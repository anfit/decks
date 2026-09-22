<?php

declare(strict_types=1);

namespace Decks;

use PDO;
use RuntimeException;

final class CardService
{
    public static function draw(PDO $database, array $session, array $member, string $direction, array $payload): array
    {
        self::assertPlayer($session, $member);
        $deckId = (string) ($payload['deck_id'] ?? '');
        $count = (int) ($payload['count'] ?? 1);
        if ($count < 1 || $count > 100) throw new RuntimeException('Draw count is invalid.');
        $target = (string) ($payload['target'] ?? 'table');
        if (!in_array($target, ['table', 'hand', 'pile'], true)) throw new RuntimeException('Draw target is invalid.');
        $deck = self::deck($database, $session['id'], $deckId);
        $order = $direction === 'bottom' ? 'DESC' : 'ASC';
        $cards = $database->prepare("SELECT * FROM session_cards WHERE session_id = :session AND location_type = 'deck' AND deck_id = :deck ORDER BY order_key {$order} LIMIT {$count} FOR UPDATE");
        $cards->execute(['session' => $session['id'], 'deck' => $deckId]);
        $selected = $cards->fetchAll();
        if (count($selected) < $count) throw new RuntimeException('The deck does not contain enough cards.');
        foreach ($selected as $card) self::assertCanControl($card, $member);
        $targetPile = null;
        if ($target === 'pile') {
            $targetPile = self::pile($database, $session['id'], (string) ($payload['pile_id'] ?? ''));
            if ($targetPile['locked_by'] !== null && (string) $targetPile['locked_by'] !== (string) $member['user_id']) throw new RuntimeException('Pile is locked by another participant.');
            if (isset($payload['expected_pile_version']) && (int) $payload['expected_pile_version'] !== (int) $targetPile['version']) throw new RuntimeException('Pile changed; refresh and try again.');
        }
        $nextOrder = $target === 'hand' ? self::nextOrder($database, $session['id'], 'hand', (string) $member['id']) : ($target === 'pile' ? self::nextOrder($database, $session['id'], 'pile', (string) $targetPile['id']) : 0);
        $update = $database->prepare(
            "UPDATE session_cards SET location_type = :location, deck_id = NULL, pile_id = :pile, hand_participant_id = :hand,
             order_key = :order_key, x = :x, y = :y, face_state = :face_state, owner_user_id = NULL, version = version + 1
             WHERE id = :id",
        );
        $drawn = [];
        foreach ($selected as $index => $card) {
            $location = $target === 'table' ? 'table' : $target;
            $face = $target === 'hand' ? 'private' : (($payload['face_state'] ?? 'down') === 'up' ? 'up' : 'down');
            $update->execute([
                'location' => $location, 'pile' => $target === 'pile' ? $targetPile['id'] : null, 'hand' => $target === 'hand' ? $member['id'] : null,
                'order_key' => $target === 'table' ? null : $nextOrder + ($index + 1) * 1000,
                'x' => $target === 'table' ? (float) ($payload['x'] ?? 0) + $index * 24 : null,
                'y' => $target === 'table' ? (float) ($payload['y'] ?? 0) + $index * 24 : null,
                'face_state' => $face, 'id' => $card['id'],
            ]);
            if ($location === 'table') self::applyZoneEffect($database, $session['id'], (string) $card['id'], (float) ($payload['x'] ?? 0) + $index * 24, (float) ($payload['y'] ?? 0) + $index * 24, (string) $member['user_id']);
            $drawn[] = ['id' => (string) $card['id'], 'location_type' => $location, 'face_state' => $face];
        }
        self::bumpDeck($database, $deckId);
        if ($target === 'hand') self::bumpHand($database, $session['id'], (string) $member['id']);
        if ($target === 'pile') self::bumpPile($database, $targetPile['id']);
        return ['deck_id' => $deckId, 'direction' => $direction, 'cards' => $drawn];
    }

    public static function shuffle(PDO $database, array $session, array $member, array $payload): array
    {
        self::assertPlayer($session, $member);
        $deckId = (string) ($payload['deck_id'] ?? '');
        self::deck($database, $session['id'], $deckId);
        $statement = $database->prepare("SELECT * FROM session_cards WHERE session_id = :session AND location_type = 'deck' AND deck_id = :deck ORDER BY order_key FOR UPDATE");
        $statement->execute(['session' => $session['id'], 'deck' => $deckId]);
        $cards = $statement->fetchAll();
        foreach ($cards as $card) self::assertCanControl($card, $member);
        for ($index = count($cards) - 1; $index > 0; $index--) {
            $swap = random_int(0, $index);
            [$cards[$index], $cards[$swap]] = [$cards[$swap], $cards[$index]];
        }
        self::assignOrder($database, $cards);
        self::bumpDeck($database, $deckId);
        return ['deck_id' => $deckId, 'card_count' => count($cards), 'randomized' => true];
    }

    public static function deal(PDO $database, array $session, array $member, array $payload): array
    {
        self::assertPlayer($session, $member);
        $deckId = (string) ($payload['deck_id'] ?? ''); self::deck($database, $session['id'], $deckId);
        $participants = $payload['participant_ids'] ?? [];
        if (!is_array($participants) || count($participants) < 1 || count($participants) > 100) throw new RuntimeException('Participants are invalid.');
        $unique = array_values(array_unique(array_map('strval', $participants)));
        $placeholders = implode(',', array_map(static fn (int $index): string => ':participant' . $index, array_keys($unique)));
        $valid = $database->prepare("SELECT id FROM session_participants WHERE session_id = :session AND id IN ({$placeholders}) AND removed_at IS NULL AND role IN ('host','player')");
        $validParams = ['session' => $session['id']]; foreach ($unique as $index => $participant) $validParams['participant' . $index] = $participant;
        $valid->execute($validParams);
        $allowed = array_map(static fn (array $row): string => (string) $row['id'], $valid->fetchAll());
        if (count($allowed) !== count($unique)) throw new RuntimeException('A deal recipient is invalid.');
        $count = (int) ($payload['count'] ?? 1); if ($count < 1 || $count > 10000) throw new RuntimeException('Deal count is invalid.');
        $mode = ($payload['mode'] ?? 'round_robin') === 'per_participant' ? 'per_participant' : 'round_robin';
        $needed = $count * ($mode === 'per_participant' ? count($unique) : 1);
        $order = ($payload['direction'] ?? 'top') === 'bottom' ? 'DESC' : 'ASC';
        $cards = $database->prepare("SELECT * FROM session_cards WHERE session_id = :session AND location_type = 'deck' AND deck_id = :deck ORDER BY order_key {$order} LIMIT {$needed} FOR UPDATE");
        $cards->execute(['session' => $session['id'], 'deck' => $deckId]); $rows = $cards->fetchAll();
        if (count($rows) < $needed) throw new RuntimeException('The deck does not contain enough cards.');
        foreach ($rows as $card) self::assertCanControl($card, $member);
        $next = $database->prepare("SELECT coalesce(max(order_key),0) FROM session_cards WHERE session_id = :session AND location_type = 'hand' AND hand_participant_id = :participant");
        $update = $database->prepare("UPDATE session_cards SET location_type='hand', deck_id=NULL, pile_id=NULL, hand_participant_id=:participant, order_key=:order_key, x=NULL, y=NULL, face_state='private', version=version+1 WHERE id=:id");
        $counts = array_fill_keys($unique, 0); $index = 0;
        $deliver = static function (string $participant) use ($next, $update, $session, $rows, &$index, &$counts): void {
            if ($index >= count($rows)) throw new RuntimeException('The deck does not contain enough cards.');
            $next->execute(['session' => $session['id'], 'participant' => $participant]);
            $update->execute(['participant' => $participant, 'order_key' => (int) $next->fetchColumn() + 1000, 'id' => $rows[$index]['id']]);
            $counts[$participant]++; $index++;
        };
        if ($mode === 'per_participant') {
            foreach ($unique as $participant) for ($round = 0; $round < $count; $round++) $deliver($participant);
        } else {
            for ($round = 0; $round < $count; $round++) foreach ($unique as $participant) $deliver($participant);
        }
        self::bumpDeck($database, $deckId);
        foreach ($counts as $participant => $number) if ($number > 0) self::bumpHand($database, $session['id'], $participant);
        return ['deck_id' => $deckId, 'participant_counts' => $counts, 'card_count' => $needed];
    }

    public static function cut(PDO $database, array $session, array $member, array $payload): array
    {
        self::assertPlayer($session, $member); $deckId = (string) ($payload['deck_id'] ?? ''); self::deck($database, $session['id'], $deckId);
        $statement = $database->prepare("SELECT * FROM session_cards WHERE session_id=:session AND location_type='deck' AND deck_id=:deck ORDER BY order_key FOR UPDATE"); $statement->execute(['session'=>$session['id'],'deck'=>$deckId]); $rows=$statement->fetchAll(); $total=count($rows);
        if ($total < 2) return ['deck_id'=>$deckId,'cut_at'=>0,'card_count'=>$total];
        foreach ($rows as $card) self::assertCanControl($card, $member);
        $cut = isset($payload['count']) ? (int)$payload['count'] : random_int(1, $total - 1); if ($cut < 1 || $cut >= $total) throw new RuntimeException('Cut position is invalid.');
        $rows = array_merge(array_slice($rows, $cut), array_slice($rows, 0, $cut)); self::assignOrder($database, $rows); self::bumpDeck($database, $deckId);
        return ['deck_id'=>$deckId,'cut_at'=>$cut,'card_count'=>$total];
    }

    public static function insertIntoDeck(PDO $database, array $session, array $member, array $payload): array
    {
        self::assertPlayer($session, $member); $deckId=(string)($payload['deck_id']??''); self::deck($database,$session['id'],$deckId); $ids=$payload['card_ids']??[];
        if (!is_array($ids)||count($ids)<1||count($ids)>100||count(array_unique(array_map('strval',$ids)))!==count($ids)) throw new RuntimeException('Card selection is invalid.');
        $moving=[]; foreach($ids as $id){$card=self::card($database,$session['id'],(string)$id); self::assertCanControl($card,$member); self::assertSourcePileUnlocked($database,(string)$session['id'],$card,$member); if($card['location_type']==='deck') throw new RuntimeException('A deck card cannot be inserted by identity.'); $moving[]=$card;}
        $all=$database->prepare("SELECT * FROM session_cards WHERE session_id=:session AND location_type='deck' AND deck_id=:deck ORDER BY order_key FOR UPDATE");$all->execute(['session'=>$session['id'],'deck'=>$deckId]);$remaining=$all->fetchAll();
        foreach ($remaining as $card) self::assertCanControl($card, $member);
        $position=$payload['position']??'random'; if($position==='random'){$at=random_int(0,count($remaining));}else{$at=(int)$position;if($at<0||$at>count($remaining))throw new RuntimeException('Insertion position is invalid.');}
        $sources=[];foreach($moving as $card)$sources[$card['location_type'].':'.($card['pile_id']??$card['hand_participant_id']??'')]=true;
        $ordered=array_merge(array_slice($remaining,0,$at),$moving,array_slice($remaining,$at)); $update=$database->prepare("UPDATE session_cards SET location_type='deck',deck_id=:deck,pile_id=NULL,hand_participant_id=NULL,order_key=:temp,x=NULL,y=NULL,face_state='down',version=version+1 WHERE id=:id"); foreach($ordered as $i=>$row)$update->execute(['deck'=>$deckId,'temp'=>-1000000+$i,'id'=>$row['id']]); self::assignOrder($database,$ordered); self::bumpDeck($database,$deckId);foreach($sources as $key=>$_){[$location,$container]=explode(':',$key,2);if($location==='hand')self::bumpHand($database,$session['id'],$container);if($location==='pile'&&$container!=='')self::bumpPile($database,$container);}
        return ['deck_id'=>$deckId,'position'=>$at,'card_count'=>count($moving)];
    }

    public static function splitDeck(PDO $database, array $session, array $member, array $payload): array
    {
        self::assertPlayer($session,$member);$deckId=(string)($payload['deck_id']??'');self::deck($database,$session['id'],$deckId);$count=(int)($payload['count']??0);if($count<1)throw new RuntimeException('Split count is invalid.');
        $rows=$database->prepare("SELECT * FROM session_cards WHERE session_id=:session AND location_type='deck' AND deck_id=:deck ORDER BY order_key FOR UPDATE");$rows->execute(['session'=>$session['id'],'deck'=>$deckId]);$cards=$rows->fetchAll();if($count>=count($cards))throw new RuntimeException('Split must leave cards in the source deck.');
        foreach ($cards as $card) self::assertCanControl($card, $member);
        $insert=$database->prepare('INSERT INTO session_piles(session_id,label,x,y,rotation,z_index) VALUES(:session,:label,:x,:y,:rotation,:z) RETURNING id');$insert->execute(['session'=>$session['id'],'label'=>isset($payload['label'])?(string)$payload['label']:null,'x'=>(float)($payload['x']??0),'y'=>(float)($payload['y']??0),'rotation'=>(float)($payload['rotation']??0),'z'=>(int)($payload['z_index']??0)]);$pileId=(string)$insert->fetchColumn();$moving=array_slice($cards,0,$count);$update=$database->prepare("UPDATE session_cards SET location_type='pile',deck_id=NULL,pile_id=:pile,order_key=:order,face_state='down',version=version+1 WHERE id=:id");foreach($moving as $i=>$row)$update->execute(['pile'=>$pileId,'order'=>($i+1)*1000,'id'=>$row['id']]);self::assignOrder($database,array_slice($cards,$count));self::bumpDeck($database,$deckId);return ['deck_id'=>$deckId,'pile_id'=>$pileId,'card_count'=>$count];
    }

    public static function remove(PDO $database, array $session, array $member, array $payload): array
    {
        self::assertPlayer($session, $member); $card = self::card($database, $session['id'], (string) ($payload['card_id'] ?? '')); self::assertVersion($card, $payload); self::assertCanControl($card, $member);
        self::assertSourcePileUnlocked($database, (string) $session['id'], $card, $member);
        if ($card['location_type'] === 'removed') throw new RuntimeException('Card is already removed.');
        $database->prepare("UPDATE session_cards SET location_type='removed', deck_id=NULL, pile_id=NULL, hand_participant_id=NULL, order_key=NULL, x=NULL, y=NULL, face_state='down', locked_by=NULL, version=version+1 WHERE id=:id")->execute(['id'=>$card['id']]);
        if ($card['location_type'] === 'hand') self::bumpHand($database, $session['id'], (string) $card['hand_participant_id']);
        if ($card['location_type'] === 'pile') self::bumpPile($database, (string) $card['pile_id']);
        if ($card['location_type'] === 'deck') self::bumpDeck($database, (string) $card['deck_id']);
        return ['card_id'=>(string)$card['id'],'removed'=>true];
    }

    public static function restore(PDO $database, array $session, array $member, array $payload): array
    {
        if ($member['role'] !== 'host') throw new RuntimeException('Host permission required.');
        $card = self::card($database, $session['id'], (string) ($payload['card_id'] ?? '')); self::assertVersion($card, $payload); if ($card['location_type'] !== 'removed') throw new RuntimeException('Card is not removed.');
        $deckId = (string) $card['source_deck_id']; self::deck($database, $session['id'], $deckId);
        $position = ($payload['position'] ?? 'bottom'); if (!in_array($position, ['top', 'bottom', 'shuffle'], true)) throw new RuntimeException('Restore position is invalid.');
        $existing = $database->prepare("SELECT * FROM session_cards WHERE session_id=:session AND location_type='deck' AND deck_id=:deck ORDER BY order_key FOR UPDATE");
        $existing->execute(['session'=>$session['id'],'deck'=>$deckId]); $rows = $existing->fetchAll();
        foreach ($rows as $existingCard) self::assertCanControl($existingCard, $member);
        $restored = ['id' => (string) $card['id']];
        $ordered = $position === 'top' ? array_merge([$restored], $rows) : array_merge($rows, [$restored]);
        if ($position === 'shuffle') {
            for ($i = count($ordered) - 1; $i > 0; $i--) { $j = random_int(0, $i); [$ordered[$i], $ordered[$j]] = [$ordered[$j], $ordered[$i]]; }
        }
        $database->prepare("UPDATE session_cards SET location_type='deck', deck_id=:deck, pile_id=NULL, hand_participant_id=NULL, order_key=:temporary, x=NULL, y=NULL, face_state='down', owner_user_id=NULL, locked_by=NULL, version=version+1 WHERE session_id=:session AND id=:id")
            ->execute(['deck'=>$deckId,'session'=>$session['id'],'id'=>$card['id'],'temporary'=>-1000000]);
        self::assignOrder($database, $ordered); self::bumpDeck($database,$deckId);
        return ['card_id'=>(string)$card['id'],'deck_id'=>$deckId,'restored'=>true,'position'=>$position,'randomized'=>$position==='shuffle'];
    }

    public static function lock(PDO $database, array $session, array $member, array $payload, bool $locked): array
    {
        self::assertPlayer($session, $member); $card = self::card($database, $session['id'], (string) ($payload['card_id'] ?? '')); self::assertVersion($card, $payload);
        self::assertCanControl($card, $member);
        self::assertSourcePileUnlocked($database, (string) $session['id'], $card, $member);
        if ($locked && $card['locked_by'] !== null && (string)$card['locked_by'] !== (string)$member['user_id']) throw new RuntimeException('That card is locked.');
        if (!$locked && $card['locked_by'] !== null && (string)$card['locked_by'] !== (string)$member['user_id']) throw new RuntimeException('Only the card owner can unlock it.');
        $database->prepare('UPDATE session_cards SET locked_by=:owner, version=version+1 WHERE id=:id')->execute(['owner'=>$locked?$member['user_id']:null,'id'=>$card['id']]);
        return ['card_id'=>(string)$card['id'],'locked'=>$locked];
    }

    public static function moveCards(PDO $database, array $session, array $member, array $payload): array
    {
        self::assertPlayer($session, $member); $items = $payload['cards'] ?? [];
        if (!is_array($items) || count($items) < 1 || count($items) > 100) throw new RuntimeException('Card selection is invalid.');
        $selectedIds = [];
        $update = $database->prepare('UPDATE session_cards SET x=:x, y=:y, rotation=:rotation, z_index=:z, version=version+1 WHERE id=:id'); $moved=[];
        foreach ($items as $item) {
            if (!is_array($item)) throw new RuntimeException('Card selection is invalid.');
            $itemId = (string) ($item['card_id'] ?? ''); if (isset($selectedIds[$itemId])) throw new RuntimeException('Card selection contains duplicates.'); $selectedIds[$itemId] = true;
            $card=self::card($database,$session['id'],(string)($item['card_id']??'')); self::assertVersion($card,$item); self::assertCanControl($card,$member); if($card['location_type']!=='table') throw new RuntimeException('Only table cards can move as a group.');
            $nextX = (float) ($item['x'] ?? $card['x']); $nextY = (float) ($item['y'] ?? $card['y']);
            $update->execute(['x'=>$nextX,'y'=>$nextY,'rotation'=>(float)($item['rotation']??$card['rotation']),'z'=>(int)($item['z_index']??$card['z_index']),'id'=>$card['id']]); self::applyZoneEffect($database, $session['id'], (string) $card['id'], $nextX, $nextY, (string) $member['user_id']); $moved[]=(string)$card['id'];
        }
        return ['card_ids'=>$moved,'count'=>count($moved)];
    }

    public static function rotateCards(PDO $database, array $session, array $member, array $payload): array
    {
        self::assertPlayer($session, $member);
        $cards = self::tableSelection($database, $session, $member, $payload['card_ids'] ?? null, $payload['expected_card_versions'] ?? null);
        $delta = filter_var($payload['rotation_delta'] ?? null, FILTER_VALIDATE_FLOAT);
        if ($delta === false || !is_finite((float) $delta) || $delta == 0.0 || abs((float) $delta) > 360.0) throw new RuntimeException('Rotation change is invalid.');
        $update = $database->prepare('UPDATE session_cards SET rotation = :rotation, version = version + 1 WHERE session_id = :session AND id = :id');
        foreach ($cards as $card) {
            $rotation = (float) $card['rotation'] + (float) $delta;
            if (!is_finite($rotation) || $rotation < -3600 || $rotation > 3600) throw new RuntimeException('Card rotation is outside the supported range.');
            $update->execute(['rotation' => $rotation, 'session' => $session['id'], 'id' => $card['id']]);
        }
        return ['count' => count($cards), 'rotation_delta' => (float) $delta];
    }

    public static function setCardsFace(PDO $database, array $session, array $member, array $payload): array
    {
        self::assertPlayer($session, $member);
        $cards = self::tableSelection($database, $session, $member, $payload['card_ids'] ?? null, $payload['expected_card_versions'] ?? null);
        $face = (string) ($payload['face_state'] ?? '');
        if (!in_array($face, ['up', 'down'], true)) throw new RuntimeException('Card face state is invalid.');
        $update = $database->prepare('UPDATE session_cards SET face_state = :face, owner_user_id = NULL, version = version + 1 WHERE session_id = :session AND id = :id');
        foreach ($cards as $card) $update->execute(['face' => $face, 'session' => $session['id'], 'id' => $card['id']]);
        return ['count' => count($cards), 'face_state' => $face];
    }

    public static function reorderCards(PDO $database, array $session, array $member, array $payload): array
    {
        self::assertPlayer($session, $member);
        $cards = self::tableSelection($database, $session, $member, $payload['card_ids'] ?? null, $payload['expected_card_versions'] ?? null);
        $direction = (string) ($payload['direction'] ?? '');
        if (!in_array($direction, ['front', 'back'], true)) throw new RuntimeException('Card layer direction is invalid.');
        $selectedIds = array_fill_keys(array_map(static fn (array $card): string => (string) $card['id'], $cards), true);
        $all = $database->prepare("SELECT id, z_index, locked_by FROM session_cards WHERE session_id = :session AND location_type = 'table' ORDER BY z_index, id FOR UPDATE");
        $all->execute(['session' => $session['id']]);
        $ordered = $all->fetchAll();
        $selected = []; $remaining = [];
        foreach ($ordered as $card) {
            if (isset($selectedIds[(string) $card['id']])) $selected[] = $card;
            else $remaining[] = $card;
        }
        usort($selected, static fn (array $left, array $right): int => (int) $left['z_index'] <=> (int) $right['z_index'] ?: strcmp((string) $left['id'], (string) $right['id']));
        $edge = $direction === 'front' ? max(array_map(static fn (array $card): int => (int) $card['z_index'], $ordered)) : min(array_map(static fn (array $card): int => (int) $card['z_index'], $ordered));
        $selectedStart = $direction === 'front' ? $edge + 1 : $edge - count($selected);
        $selectedEnd = $selectedStart + count($selected) - 1;
        $requiresCompaction = $selectedStart < -1000000 || $selectedEnd > 1000000;
        $update = $database->prepare('UPDATE session_cards SET z_index = :next_z, version = version + 1 WHERE session_id = :session AND id = :id AND z_index <> :current_z');
        if (!$requiresCompaction) {
            foreach ($selected as $index => $card) {
                $nextZ = $selectedStart + $index;
                $update->execute(['next_z' => $nextZ, 'session' => $session['id'], 'id' => $card['id'], 'current_z' => (int) $card['z_index']]);
            }
        } else {
            foreach ($ordered as $card) {
                if ($card['locked_by'] !== null && (string) $card['locked_by'] !== (string) $member['user_id']) throw new RuntimeException('A table card is locked by another participant.');
            }
            $ordered = $direction === 'front' ? array_merge($remaining, $selected) : array_merge($selected, $remaining);
            $gap = intdiv(2000000, count($ordered) + 1);
            if ($gap < 1) throw new RuntimeException('The table has too many cards to reorder safely.');
            $start = -1000000 + $gap;
            foreach ($ordered as $index => $card) {
                $nextZ = $start + ($index * $gap);
                $update->execute(['next_z' => $nextZ, 'session' => $session['id'], 'id' => $card['id'], 'current_z' => (int) $card['z_index']]);
            }
        }
        return ['count' => count($selected), 'direction' => $direction];
    }

    /** @return list<array<string,mixed>> */
    private static function tableSelection(PDO $database, array $session, array $member, mixed $ids, mixed $versions): array
    {
        if (!is_array($ids) || count($ids) < 1 || count($ids) > 100 || !is_array($versions)) throw new RuntimeException('Card selection is invalid.');
        $ids = array_map('strval', $ids);
        if (count(array_unique($ids)) !== count($ids)) throw new RuntimeException('Card selection contains duplicates.');
        $cards = [];
        foreach ($ids as $id) {
            if (!array_key_exists($id, $versions) || filter_var($versions[$id], FILTER_VALIDATE_INT) === false) throw new RuntimeException('Expected card versions are required.');
            $card = self::card($database, $session['id'], $id);
            self::assertVersion($card, ['expected_card_version' => (int) $versions[$id]]);
            self::assertCanControl($card, $member);
            if ($card['location_type'] !== 'table') throw new RuntimeException('Only table cards may be selected for this action.');
            $cards[] = $card;
        }
        return $cards;
    }

    public static function reorderHand(PDO $database, array $session, array $member, array $payload): array
    {
        self::assertPlayer($session,$member); $ids=$payload['card_ids']??[]; if(!is_array($ids)||count($ids)>100)throw new RuntimeException('Card selection is invalid.');
        $all=$database->prepare("SELECT * FROM session_cards WHERE session_id=:session AND location_type='hand' AND hand_participant_id=:participant ORDER BY order_key,id FOR UPDATE");$all->execute(['session'=>$session['id'],'participant'=>$member['id']]);$handRows=$all->fetchAll();foreach($handRows as $card)self::assertCanControl($card,$member);$owned=array_map(static fn(array $r):string=>(string)$r['id'],$handRows);$requested=array_values(array_unique(array_map('strval',$ids)));if($requested!==$owned)throw new RuntimeException('The hand changed; refresh and try again.');
        $update=$database->prepare('UPDATE session_cards SET order_key=:order, version=version+1 WHERE id=:id');foreach($requested as $i=>$id)$update->execute(['order'=>($i+1)*1000,'id'=>$id]);self::bumpHand($database,$session['id'],(string)$member['id']);return ['participant_id'=>(string)$member['id'],'card_count'=>count($requested)];
    }

    public static function giveCards(PDO $database, array $session, array $member, array $payload): array
    {
        self::assertPlayer($session,$member);$recipient=(string)($payload['recipient_participant_id']??'');$target=$database->prepare("SELECT id FROM session_participants WHERE session_id=:session AND id=:id AND removed_at IS NULL AND role IN ('host','player')");$target->execute(['session'=>$session['id'],'id'=>$recipient]);if(!$target->fetch())throw new RuntimeException('Recipient is invalid.');$ids=$payload['card_ids']??[];if(!is_array($ids)||count($ids)<1||count($ids)>100||count(array_unique(array_map('strval',$ids)))!==count($ids))throw new RuntimeException('Card selection is invalid.');$versions=is_array($payload['expected_card_versions']??null)?$payload['expected_card_versions']:[];$order=self::nextOrder($database,$session['id'],'hand',$recipient);$update=$database->prepare("UPDATE session_cards SET hand_participant_id=:participant,order_key=:order,face_state='private',owner_user_id=NULL,version=version+1 WHERE id=:id");$moved=[];foreach($ids as $index=>$id){$card=self::card($database,$session['id'],(string)$id);if(array_key_exists((string)$id,$versions))self::assertVersion($card,['expected_card_version'=>$versions[(string)$id]]);if($card['location_type']!=='hand'||(string)$card['hand_participant_id']!==(string)$member['id'])throw new RuntimeException('Only your own hand cards can be given.');self::assertCanControl($card,$member);$update->execute(['participant'=>$recipient,'order'=>$order+(($index+1)*1000),'id'=>$card['id']]);$moved[]=(string)$card['id'];}self::bumpHand($database,$session['id'],(string)$member['id']);self::bumpHand($database,$session['id'],$recipient);return ['recipient_participant_id'=>$recipient,'card_count'=>count($moved)];
    }

    public static function peek(PDO $database, array $session, array $member, array $payload): array
    {
        self::assertPlayer($session, $member); $card = self::card($database, $session['id'], (string) ($payload['card_id'] ?? ''));
        if (!in_array($card['location_type'], ['table', 'pile'], true) || $card['face_state'] === 'up') throw new RuntimeException('Only a face-down table or pile card can be peeked.');
        self::assertCanControl($card, $member); self::assertSourcePileUnlocked($database, (string) $session['id'], $card, $member);
        Security::audit($database, (string) $member['user_id'], 'card.peek', 'session_card', (string) $card['id']);
        return ['card_id' => (string) $card['id'], 'card_definition_id' => (string) $card['card_definition_id'], 'expires_in_seconds' => 30];
    }

    private static function applyZoneEffect(PDO $database, string $sessionId, string $cardId, float $x, float $y, ?string $ownerUserId = null): void
    {
        $zones = $database->prepare('SELECT id, geometry, priority, behavior FROM session_zones WHERE session_id = :session ORDER BY priority DESC, id');
        $zones->execute(['session' => $sessionId]); $matches = [];
        foreach ($zones as $zone) {
            $geometry = json_decode((string) $zone['geometry'], true, 512, JSON_THROW_ON_ERROR);
            $left = (float) ($geometry['x'] ?? 0); $top = (float) ($geometry['y'] ?? 0); $width = (float) ($geometry['width'] ?? 0); $height = (float) ($geometry['height'] ?? 0);
            if ($x < $left || $y < $top || $x > $left + $width || $y > $top + $height) continue;
            $behavior = json_decode((string) $zone['behavior'], true, 512, JSON_THROW_ON_ERROR); $matches[] = ['priority' => (int) $zone['priority'], 'area' => $width * $height, 'zone_id' => (string) $zone['id'], 'effect' => (string) ($behavior['effect'] ?? 'none'), 'geometry' => $geometry, 'behavior' => $behavior];
        }
        if ($matches === []) return;
        usort($matches, static fn (array $left, array $right): int => $left['priority'] !== $right['priority'] ? $right['priority'] <=> $left['priority'] : ($left['area'] <=> $right['area'] ?: strcmp($left['zone_id'], $right['zone_id'])));
        $priority = $matches[0]['priority']; $area = $matches[0]['area']; $selected = array_values(array_filter($matches, static fn (array $match): bool => $match['priority'] === $priority && abs($match['area'] - $area) < 0.000001));
        $effects = array_values(array_unique(array_map(static fn (array $match): string => $match['effect'], $selected)));
        if (count($effects) > 1) throw new RuntimeException('Overlapping zones have conflicting effects.');
        $effect = $effects[0];
        if ($effect === 'face_up') $database->prepare("UPDATE session_cards SET face_state='up', version=version+1 WHERE id=:id")->execute(['id'=>$cardId]);
        if ($effect === 'face_down') $database->prepare("UPDATE session_cards SET face_state='down', version=version+1 WHERE id=:id")->execute(['id'=>$cardId]);
        if ($effect === 'stack') $database->prepare('UPDATE session_cards SET z_index = z_index + 1, version = version + 1 WHERE id = :id')->execute(['id' => $cardId]);
        if ($effect === 'align') { $geometry = $selected[0]['geometry']; $centerX = (float) $geometry['x'] + (float) $geometry['width'] / 2; $centerY = (float) $geometry['y'] + (float) $geometry['height'] / 2; $database->prepare('UPDATE session_cards SET x=:x, y=:y, version=version+1 WHERE id=:id')->execute(['x'=>$centerX,'y'=>$centerY,'id'=>$cardId]); }
        if ($effect === 'fan') { $geometry = $selected[0]['geometry']; $degrees = max(1.0, min(180.0, (float) ($selected[0]['behavior']['degrees'] ?? 30))); $center = (float) $geometry['x'] + (float) $geometry['width'] / 2; $ratio = (float) $geometry['width'] > 0 ? max(-1.0, min(1.0, ($x - $center) / ((float) $geometry['width'] / 2))) : 0.0; $database->prepare('UPDATE session_cards SET rotation=:rotation, version=version+1 WHERE id=:id')->execute(['rotation'=>$ratio*$degrees,'id'=>$cardId]); }
        if ($effect === 'owner_private' && $ownerUserId !== null) $database->prepare("UPDATE session_cards SET face_state='private', owner_user_id=:owner, version=version+1 WHERE id=:id")->execute(['owner'=>$ownerUserId,'id'=>$cardId]);
    }

    public static function moveCard(PDO $database, array $session, array $member, array $payload): array
    {
        self::assertPlayer($session, $member);
        $card = self::card($database, $session['id'], (string) ($payload['card_id'] ?? ''));
        self::assertVersion($card, $payload);
        self::assertCanControl($card, $member);
        if (!in_array($card['location_type'], ['table', 'hand'], true)) throw new RuntimeException('Only table or own-hand cards can change face state.');
        if ($card['location_type'] !== 'table') throw new RuntimeException('Only table cards have spatial positions.');
        $previous = ['x' => (float) $card['x'], 'y' => (float) $card['y'], 'rotation' => (float) $card['rotation'], 'z_index' => (int) $card['z_index'], 'face_state' => (string) $card['face_state'], 'owner_user_id' => $card['owner_user_id'] !== null ? (string) $card['owner_user_id'] : null];
        $nextX = (float) ($payload['x'] ?? $card['x']); $nextY = (float) ($payload['y'] ?? $card['y']);
        $database->prepare('UPDATE session_cards SET x = :x, y = :y, rotation = :rotation, z_index = :z, version = version + 1 WHERE id = :id')
            ->execute(['x' => $nextX, 'y' => $nextY, 'rotation' => (float) ($payload['rotation'] ?? $card['rotation']), 'z' => (int) ($payload['z_index'] ?? $card['z_index']), 'id' => $card['id']]);
        self::applyZoneEffect($database, $session['id'], (string) $card['id'], $nextX, $nextY, (string) $member['user_id']);
        $current = $database->prepare('SELECT version FROM session_cards WHERE id = :id'); $current->execute(['id' => $card['id']]);
        return ['card_id' => (string) $card['id'], 'x' => $nextX, 'y' => $nextY, 'undo' => ['card_id' => (string) $card['id'], 'previous' => $previous, 'expected_version' => (int) $current->fetchColumn()]];
    }

    public static function rotateCard(PDO $database, array $session, array $member, array $payload): array
    {
        self::assertPlayer($session, $member);
        $card = self::card($database, $session['id'], (string) ($payload['card_id'] ?? ''));
        self::assertVersion($card, $payload);
        self::assertCanControl($card, $member);
        if ($card['location_type'] !== 'table') throw new RuntimeException('Only table cards can rotate.');
        $previous = ['x' => (float) $card['x'], 'y' => (float) $card['y'], 'rotation' => (float) $card['rotation'], 'z_index' => (int) $card['z_index'], 'face_state' => (string) $card['face_state'], 'owner_user_id' => $card['owner_user_id'] !== null ? (string) $card['owner_user_id'] : null];
        $rotation = (float) ($payload['rotation'] ?? $card['rotation']);
        if (!is_finite($rotation) || $rotation < -3600 || $rotation > 3600) throw new RuntimeException('Card rotation is invalid.');
        $database->prepare('UPDATE session_cards SET rotation = :rotation, version = version + 1 WHERE id = :id')->execute(['rotation' => $rotation, 'id' => $card['id']]);
        return ['card_id' => (string) $card['id'], 'rotation' => $rotation, 'undo' => ['card_id' => (string) $card['id'], 'previous' => $previous, 'expected_version' => (int) $card['version'] + 1]];
    }

    public static function face(PDO $database, array $session, array $member, string $operation, array $payload): array
    {
        self::assertPlayer($session, $member);
        $card = self::card($database, $session['id'], (string) ($payload['card_id'] ?? ''));
        self::assertVersion($card, $payload);
        self::assertCanControl($card, $member);
        self::assertSourcePileUnlocked($database, (string) $session['id'], $card, $member);
        $face = match ($operation) {
            'flip_card' => $card['face_state'] === 'up' ? 'down' : 'up',
            'turn_face_up' => 'up',
            'turn_face_down' => 'down',
            default => throw new RuntimeException('Invalid face operation.'),
        };
        $database->prepare('UPDATE session_cards SET face_state = :face, version = version + 1 WHERE id = :id')->execute(['face' => $face, 'id' => $card['id']]);
        return ['card_id' => (string) $card['id'], 'face_state' => $face];
    }

    public static function moveToHand(PDO $database, array $session, array $member, array $payload): array
    {
        self::assertPlayer($session, $member);
        $card = self::card($database, $session['id'], (string) ($payload['card_id'] ?? ''));
        self::assertVersion($card, $payload);
        self::assertCanControl($card, $member);
        self::assertSourcePileUnlocked($database, (string) $session['id'], $card, $member);
        if (!in_array($card['location_type'], ['table', 'pile'], true)) throw new RuntimeException('Only table or pile cards can move to a hand.');
        $order = self::nextOrder($database, $session['id'], 'hand', (string) $member['id']) + 1000;
        $database->prepare("UPDATE session_cards SET location_type = 'hand', deck_id = NULL, pile_id = NULL, hand_participant_id = :hand, order_key = :order_key, x = NULL, y = NULL, face_state = 'private', owner_user_id = NULL, version = version + 1 WHERE id = :id")
            ->execute(['hand' => $member['id'], 'order_key' => $order, 'id' => $card['id']]);
        if ($card['location_type'] === 'pile') self::bumpPile($database, (string) $card['pile_id']);
        self::bumpHand($database, $session['id'], (string) $member['id']);
        return ['card_id' => (string) $card['id'], 'hand_participant_id' => (string) $member['id']];
    }

    public static function playFromHand(PDO $database, array $session, array $member, array $payload): array
    {
        self::assertPlayer($session, $member);
        $card = self::card($database, $session['id'], (string) ($payload['card_id'] ?? ''));
        self::assertVersion($card, $payload);
        if ($card['location_type'] !== 'hand' || (string) $card['hand_participant_id'] !== (string) $member['id']) throw new RuntimeException('Only your own hand cards can be played.');
        self::assertCanControl($card, $member);
        $face = ($payload['face_state'] ?? 'down') === 'up' ? 'up' : 'down';
        $database->prepare("UPDATE session_cards SET location_type = 'table', deck_id = NULL, pile_id = NULL, hand_participant_id = NULL, order_key = NULL, x = :x, y = :y, face_state = :face, owner_user_id = NULL, version = version + 1 WHERE id = :id")
            ->execute(['x' => (float) ($payload['x'] ?? 0), 'y' => (float) ($payload['y'] ?? 0), 'face' => $face, 'id' => $card['id']]);
        self::applyZoneEffect($database, $session['id'], (string) $card['id'], (float) ($payload['x'] ?? 0), (float) ($payload['y'] ?? 0), (string) $member['user_id']);
        self::bumpHand($database, $session['id'], (string) $member['id']);
        return ['card_id' => (string) $card['id'], 'face_state' => $face];
    }

    public static function returnToDeck(PDO $database, array $session, array $member, array $payload, string $position): array
    {
        self::assertPlayer($session, $member);
        $deckId = (string) ($payload['deck_id'] ?? '');
        self::deck($database, $session['id'], $deckId);
        $ids = $payload['card_ids'] ?? [];
        if (!is_array($ids) || count($ids) < 1 || count($ids) > 100 || count(array_unique(array_map('strval', $ids))) !== count($ids)) throw new RuntimeException('Card selection is invalid.');
        $versions = $payload['expected_card_versions'] ?? null;
        if (!is_array($versions) || count($versions) !== count($ids)) throw new RuntimeException('Expected card versions are required.');
        $moving = [];
        foreach ($ids as $id) {
            if (!array_key_exists((string) $id, $versions) || filter_var($versions[(string) $id], FILTER_VALIDATE_INT) === false) throw new RuntimeException('Expected card versions are required.');
            $card = self::card($database, $session['id'], (string) $id);
            self::assertVersion($card, ['expected_card_version' => $versions[(string) $id]]);
            self::assertCanControl($card, $member);
            self::assertSourcePileUnlocked($database, (string) $session['id'], $card, $member);
            $moving[] = $card;
        }
        $targetCards = $database->prepare("SELECT * FROM session_cards WHERE session_id = :session AND location_type = 'deck' AND deck_id = :deck FOR UPDATE");
        $targetCards->execute(['session' => $session['id'], 'deck' => $deckId]);
        foreach ($targetCards->fetchAll() as $existingCard) self::assertCanControl($existingCard, $member);
        $sourceContainers = [];
        foreach ($moving as $index => $card) {
            if ($card['location_type'] === 'deck') throw new RuntimeException('Cards already in a deck cannot be selected by identity.');
            $sourceContainers[$card['location_type'] . ':' . ($card['pile_id'] ?? $card['hand_participant_id'] ?? $card['deck_id'] ?? '')] = true;
                $database->prepare("UPDATE session_cards SET location_type = 'deck', deck_id = :deck, pile_id = NULL, hand_participant_id = NULL, order_key = :temp_order, x = NULL, y = NULL, face_state = 'down', owner_user_id = NULL, version = version + 1 WHERE id = :id")
                ->execute(['deck' => $deckId, 'temp_order' => -1000000 + $index, 'id' => $card['id']]);
        }
        $all = $database->prepare("SELECT id FROM session_cards WHERE session_id = :session AND location_type = 'deck' AND deck_id = :deck ORDER BY order_key");
        $all->execute(['session' => $session['id'], 'deck' => $deckId]);
        $ordered = $all->fetchAll();
        $movingIds = array_map(static fn (array $card): string => (string) $card['id'], $moving);
        $movingRows = []; $remainingRows = [];
        foreach ($ordered as $row) {
            if (in_array((string) $row['id'], $movingIds, true)) $movingRows[] = $row;
            else $remainingRows[] = $row;
        }
        $ordered = $position === 'top' ? array_merge($movingRows, $remainingRows) : array_merge($remainingRows, $movingRows);
        self::assignOrder($database, $ordered);
        foreach ($sourceContainers as $key => $_) {
            [$location, $container] = explode(':', $key, 2);
            if ($location === 'hand') self::bumpHand($database, $session['id'], $container);
            if ($location === 'pile' && $container !== '') self::bumpPile($database, $container);
        }
        self::bumpDeck($database, $deckId);
        return ['deck_id' => $deckId, 'position' => $position, 'card_ids' => $movingIds];
    }

    public static function returnToSourceDecks(PDO $database, array $session, array $member, array $payload): array
    {
        self::assertPlayer($session, $member);
        $position = (string) ($payload['position'] ?? '');
        if (!in_array($position, ['top', 'bottom'], true)) throw new RuntimeException('Deck position is invalid.');
        $ids = $payload['card_ids'] ?? [];
        $versions = $payload['expected_card_versions'] ?? null;
        if (!is_array($ids) || count($ids) < 1 || count($ids) > 100 || count(array_unique(array_map('strval', $ids))) !== count($ids)) throw new RuntimeException('Card selection is invalid.');
        if (!is_array($versions) || count($versions) !== count($ids)) throw new RuntimeException('Expected card versions are required.');

        $groups = [];
        foreach ($ids as $rawId) {
            $cardId = (string) $rawId;
            if (!array_key_exists($cardId, $versions) || filter_var($versions[$cardId], FILTER_VALIDATE_INT) === false) throw new RuntimeException('Expected card versions are required.');
            $card = self::card($database, (string) $session['id'], $cardId);
            self::assertVersion($card, ['expected_card_version' => $versions[$cardId]]);
            self::assertCanControl($card, $member);
            if ($card['location_type'] !== 'table') throw new RuntimeException('Only table cards may be returned to their source decks.');
            $sourceDeckId = (string) $card['source_deck_id'];
            if ($sourceDeckId === '') throw new RuntimeException('Card source deck is unavailable.');
            $groups[$sourceDeckId]['card_ids'][] = $cardId;
            $groups[$sourceDeckId]['versions'][$cardId] = $versions[$cardId];
        }

        ksort($groups, SORT_STRING);
        foreach ($groups as $deckId => $group) {
            self::returnToDeck($database, $session, $member, [
                'deck_id' => $deckId,
                'card_ids' => $group['card_ids'],
                'expected_card_versions' => $group['versions'],
            ], $position);
        }
        return ['card_count' => count($ids), 'position' => $position];
    }

    private static function assertPlayer(array $session, array $member): void
    {
        if (!in_array($member['role'], ['host', 'player'], true)) throw new RuntimeException('Player permission required.');
        if ($session['status'] === 'ended') throw new RuntimeException('Session has ended.');
    }

    private static function assertVersion(array $card, array $payload): void
    {
        if (isset($payload['expected_card_version']) && (int) $payload['expected_card_version'] !== (int) $card['version']) throw new RuntimeException('Card changed; refresh and try again.');
    }

    private static function assertCanControl(array $card, array $member): void
    {
        if ($card['location_type'] === 'hand' && (string) $card['hand_participant_id'] !== (string) $member['id']) throw new RuntimeException('That hand is private.');
        if ($card['location_type'] === 'pile' && $card['face_state'] === 'private') throw new RuntimeException('That pile card is private.');
        if ($card['location_type'] === 'table' && $card['face_state'] === 'private' && (string) $card['owner_user_id'] !== (string) $member['user_id']) throw new RuntimeException('That table card is private.');
        if ($card['locked_by'] !== null && (string) $card['locked_by'] !== (string) $member['user_id']) throw new RuntimeException('That card is locked.');
        if ($card['location_type'] === 'removed') throw new RuntimeException('That card is removed from play.');
    }

    private static function assertSourcePileUnlocked(PDO $database, string $sessionId, array $card, array $member): void
    {
        if (($card['location_type'] ?? '') !== 'pile') return;
        $statement = $database->prepare('SELECT locked_by FROM session_piles WHERE session_id = :session AND id = :pile FOR UPDATE');
        $statement->execute(['session' => $sessionId, 'pile' => $card['pile_id']]);
        $pile = $statement->fetch();
        if (!is_array($pile)) throw new RuntimeException('Pile not found.');
        if ($pile['locked_by'] !== null && (string) $pile['locked_by'] !== (string) $member['user_id']) throw new RuntimeException('Pile is locked by another participant.');
    }

    private static function card(PDO $database, string $sessionId, string $cardId): array
    {
        $statement = $database->prepare('SELECT * FROM session_cards WHERE session_id = :session AND id = :id FOR UPDATE');
        $statement->execute(['session' => $sessionId, 'id' => $cardId]);
        $card = $statement->fetch();
        if (!is_array($card)) throw new RuntimeException('Card not found.');
        return $card;
    }

    private static function deck(PDO $database, string $sessionId, string $deckId): array
    {
        $statement = $database->prepare('SELECT * FROM session_decks WHERE session_id = :session AND id = :id FOR UPDATE');
        $statement->execute(['session' => $sessionId, 'id' => $deckId]);
        $deck = $statement->fetch();
        if (!is_array($deck)) throw new RuntimeException('Deck not found.');
        return $deck;
    }

    private static function pile(PDO $database, string $sessionId, string $pileId): array
    {
        $statement = $database->prepare('SELECT * FROM session_piles WHERE session_id = :session AND id = :id FOR UPDATE');
        $statement->execute(['session' => $sessionId, 'id' => $pileId]);
        $pile = $statement->fetch();
        if (!is_array($pile)) throw new RuntimeException('Pile not found.');
        return $pile;
    }

    private static function nextOrder(PDO $database, string $sessionId, string $location, string $containerId): int
    {
        $field = match ($location) { 'hand' => 'hand_participant_id', 'pile' => 'pile_id', default => throw new RuntimeException('Invalid order container.') };
        $statement = $database->prepare("SELECT coalesce(max(order_key), 0) FROM session_cards WHERE session_id = :session AND location_type = :location AND {$field} = :container");
        $statement->execute(['session' => $sessionId, 'location' => $location, 'container' => $containerId]);
        return (int) $statement->fetchColumn();
    }

    private static function assignOrder(PDO $database, array $cards): void
    {
        $update = $database->prepare('UPDATE session_cards SET order_key = :temporary WHERE id = :id');
        foreach ($cards as $index => $card) $update->execute(['temporary' => -2000000000 + $index, 'id' => $card['id']]);
        foreach ($cards as $index => $card) $update->execute(['temporary' => ($index + 1) * 1000, 'id' => $card['id']]);
    }

    private static function bumpDeck(PDO $database, string $deckId): void { $database->prepare('UPDATE session_decks SET version = version + 1 WHERE id = :id')->execute(['id' => $deckId]); }
    private static function bumpHand(PDO $database, string $sessionId, string $participantId): void { $database->prepare('UPDATE session_hands SET version = version + 1 WHERE session_id = :session AND participant_id = :participant')->execute(['session' => $sessionId, 'participant' => $participantId]); }
    private static function bumpPile(PDO $database, string $pileId): void { $database->prepare('UPDATE session_piles SET version = version + 1 WHERE id = :id')->execute(['id' => $pileId]); }
}
