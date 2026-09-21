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
        $targetPile = null;
        if ($target === 'pile') $targetPile = self::pile($database, $session['id'], (string) ($payload['pile_id'] ?? ''));
        $nextOrder = $target === 'hand' ? self::nextOrder($database, $session['id'], 'hand', (string) $member['id']) : ($target === 'pile' ? self::nextOrder($database, $session['id'], 'pile', (string) $targetPile['id']) : 0);
        $update = $database->prepare(
            "UPDATE session_cards SET location_type = :location, deck_id = NULL, pile_id = :pile, hand_participant_id = :hand,
             order_key = :order_key, x = :x, y = :y, face_state = :face_state, version = version + 1
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
        $statement = $database->prepare("SELECT id FROM session_cards WHERE session_id = :session AND location_type = 'deck' AND deck_id = :deck ORDER BY order_key FOR UPDATE");
        $statement->execute(['session' => $session['id'], 'deck' => $deckId]);
        $cards = $statement->fetchAll();
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
        $cards = $database->prepare("SELECT id FROM session_cards WHERE session_id = :session AND location_type = 'deck' AND deck_id = :deck ORDER BY order_key {$order} LIMIT {$needed} FOR UPDATE");
        $cards->execute(['session' => $session['id'], 'deck' => $deckId]); $rows = $cards->fetchAll();
        if (count($rows) < $needed) throw new RuntimeException('The deck does not contain enough cards.');
        $next = $database->prepare("SELECT coalesce(max(order_key),0) FROM session_cards WHERE session_id = :session AND location_type = 'hand' AND hand_participant_id = :participant");
        $update = $database->prepare("UPDATE session_cards SET location_type='hand', deck_id=NULL, pile_id=NULL, hand_participant_id=:participant, order_key=:order_key, x=NULL, y=NULL, face_state='private', version=version+1 WHERE id=:id");
        $counts = array_fill_keys($unique, 0); $index = 0;
        for ($round = 0; $round < $count; $round++) foreach ($unique as $participant) {
            if ($mode === 'per_participant') { /* ordering is intentionally participant-major below */ }
            if ($index >= count($rows)) break 2;
            $next->execute(['session' => $session['id'], 'participant' => $participant]);
            $update->execute(['participant' => $participant, 'order_key' => (int) $next->fetchColumn() + 1000, 'id' => $rows[$index]['id']]);
            $counts[$participant]++; $index++;
        }
        self::bumpDeck($database, $deckId);
        foreach ($counts as $participant => $number) if ($number > 0) self::bumpHand($database, $session['id'], $participant);
        return ['deck_id' => $deckId, 'participant_counts' => $counts, 'card_count' => $needed];
    }

    public static function cut(PDO $database, array $session, array $member, array $payload): array
    {
        self::assertPlayer($session, $member); $deckId = (string) ($payload['deck_id'] ?? ''); self::deck($database, $session['id'], $deckId);
        $statement = $database->prepare("SELECT id FROM session_cards WHERE session_id=:session AND location_type='deck' AND deck_id=:deck ORDER BY order_key FOR UPDATE"); $statement->execute(['session'=>$session['id'],'deck'=>$deckId]); $rows=$statement->fetchAll(); $total=count($rows);
        if ($total < 2) return ['deck_id'=>$deckId,'cut_at'=>0,'card_count'=>$total];
        $cut = isset($payload['count']) ? (int)$payload['count'] : random_int(1, $total - 1); if ($cut < 1 || $cut >= $total) throw new RuntimeException('Cut position is invalid.');
        $rows = array_merge(array_slice($rows, $cut), array_slice($rows, 0, $cut)); self::assignOrder($database, $rows); self::bumpDeck($database, $deckId);
        return ['deck_id'=>$deckId,'cut_at'=>$cut,'card_count'=>$total];
    }

    public static function insertIntoDeck(PDO $database, array $session, array $member, array $payload): array
    {
        self::assertPlayer($session, $member); $deckId=(string)($payload['deck_id']??''); self::deck($database,$session['id'],$deckId); $ids=$payload['card_ids']??[];
        if (!is_array($ids)||count($ids)<1||count($ids)>100) throw new RuntimeException('Card selection is invalid.');
        $moving=[]; foreach($ids as $id){$card=self::card($database,$session['id'],(string)$id); self::assertCanControl($card,$member); if($card['location_type']==='deck') throw new RuntimeException('A deck card cannot be inserted by identity.'); $moving[]=$card;}
        $all=$database->prepare("SELECT id FROM session_cards WHERE session_id=:session AND location_type='deck' AND deck_id=:deck ORDER BY order_key");$all->execute(['session'=>$session['id'],'deck'=>$deckId]);$remaining=$all->fetchAll();
        $position=$payload['position']??'random'; if($position==='random'){$at=random_int(0,count($remaining));}else{$at=(int)$position;if($at<0||$at>count($remaining))throw new RuntimeException('Insertion position is invalid.');}
        $ordered=array_merge(array_slice($remaining,0,$at),$moving,array_slice($remaining,$at)); $update=$database->prepare("UPDATE session_cards SET location_type='deck',deck_id=:deck,pile_id=NULL,hand_participant_id=NULL,order_key=:temp,x=NULL,y=NULL,face_state='down',version=version+1 WHERE id=:id"); foreach($ordered as $i=>$row)$update->execute(['deck'=>$deckId,'temp'=>-1000000+$i,'id'=>$row['id']]); self::assignOrder($database,$ordered); self::bumpDeck($database,$deckId);
        return ['deck_id'=>$deckId,'position'=>$at,'card_count'=>count($moving)];
    }

    public static function splitDeck(PDO $database, array $session, array $member, array $payload): array
    {
        self::assertPlayer($session,$member);$deckId=(string)($payload['deck_id']??'');self::deck($database,$session['id'],$deckId);$count=(int)($payload['count']??0);if($count<1)throw new RuntimeException('Split count is invalid.');
        $rows=$database->prepare("SELECT id FROM session_cards WHERE session_id=:session AND location_type='deck' AND deck_id=:deck ORDER BY order_key FOR UPDATE");$rows->execute(['session'=>$session['id'],'deck'=>$deckId]);$cards=$rows->fetchAll();if($count>=count($cards))throw new RuntimeException('Split must leave cards in the source deck.');
        $insert=$database->prepare('INSERT INTO session_piles(session_id,label,x,y,rotation,z_index) VALUES(:session,:label,:x,:y,:rotation,:z) RETURNING id');$insert->execute(['session'=>$session['id'],'label'=>isset($payload['label'])?(string)$payload['label']:null,'x'=>(float)($payload['x']??0),'y'=>(float)($payload['y']??0),'rotation'=>(float)($payload['rotation']??0),'z'=>(int)($payload['z_index']??0)]);$pileId=(string)$insert->fetchColumn();$moving=array_slice($cards,0,$count);$update=$database->prepare("UPDATE session_cards SET location_type='pile',deck_id=NULL,pile_id=:pile,order_key=:order,face_state='down',version=version+1 WHERE id=:id");foreach($moving as $i=>$row)$update->execute(['pile'=>$pileId,'order'=>($i+1)*1000,'id'=>$row['id']]);self::assignOrder($database,array_slice($cards,$count));self::bumpDeck($database,$deckId);return ['deck_id'=>$deckId,'pile_id'=>$pileId,'card_count'=>$count];
    }

    public static function remove(PDO $database, array $session, array $member, array $payload): array
    {
        self::assertPlayer($session, $member); $card = self::card($database, $session['id'], (string) ($payload['card_id'] ?? '')); self::assertVersion($card, $payload); self::assertCanControl($card, $member);
        if ($card['location_type'] === 'removed') throw new RuntimeException('Card is already removed.');
        $database->prepare("UPDATE session_cards SET location_type='removed', deck_id=NULL, pile_id=NULL, hand_participant_id=NULL, order_key=NULL, x=NULL, y=NULL, face_state='down', locked_by=NULL, version=version+1 WHERE id=:id")->execute(['id'=>$card['id']]);
        if ($card['location_type'] === 'hand') { self::normalizeHand($database, $session['id'], (string) $card['hand_participant_id']); self::bumpHand($database, $session['id'], (string) $card['hand_participant_id']); }
        if ($card['location_type'] === 'pile') self::bumpPile($database, (string) $card['pile_id']);
        if ($card['location_type'] === 'deck') self::bumpDeck($database, (string) $card['deck_id']);
        return ['card_id'=>(string)$card['id'],'removed'=>true];
    }

    public static function restore(PDO $database, array $session, array $member, array $payload): array
    {
        if ($member['role'] !== 'host') throw new RuntimeException('Host permission required.');
        $card = self::card($database, $session['id'], (string) ($payload['card_id'] ?? '')); self::assertVersion($card, $payload); if ($card['location_type'] !== 'removed') throw new RuntimeException('Card is not removed.');
        $deckId = (string) $card['source_deck_id']; $order = self::nextOrder($database, $session['id'], 'deck', $deckId);
        $database->prepare("UPDATE session_cards SET location_type='deck', deck_id=:deck, order_key=:order, face_state='down', version=version+1 WHERE id=:id")->execute(['deck'=>$deckId,'order'=>$order+1000,'id'=>$card['id']]); self::bumpDeck($database,$deckId);
        return ['card_id'=>(string)$card['id'],'deck_id'=>$deckId,'restored'=>true];
    }

    public static function lock(PDO $database, array $session, array $member, array $payload, bool $locked): array
    {
        self::assertPlayer($session, $member); $card = self::card($database, $session['id'], (string) ($payload['card_id'] ?? '')); self::assertVersion($card, $payload);
        if ($locked && $card['locked_by'] !== null && (string)$card['locked_by'] !== (string)$member['user_id']) throw new RuntimeException('That card is locked.');
        if (!$locked && $card['locked_by'] !== null && (string)$card['locked_by'] !== (string)$member['user_id']) throw new RuntimeException('Only the card owner can unlock it.');
        $database->prepare('UPDATE session_cards SET locked_by=:owner, version=version+1 WHERE id=:id')->execute(['owner'=>$locked?$member['user_id']:null,'id'=>$card['id']]);
        return ['card_id'=>(string)$card['id'],'locked'=>$locked];
    }

    public static function moveCards(PDO $database, array $session, array $member, array $payload): array
    {
        self::assertPlayer($session, $member); $items = $payload['cards'] ?? [];
        if (!is_array($items) || count($items) < 1 || count($items) > 100) throw new RuntimeException('Card selection is invalid.');
        $update = $database->prepare('UPDATE session_cards SET x=:x, y=:y, rotation=:rotation, z_index=:z, version=version+1 WHERE id=:id'); $moved=[];
        foreach ($items as $item) {
            if (!is_array($item)) throw new RuntimeException('Card selection is invalid.');
            $card=self::card($database,$session['id'],(string)($item['card_id']??'')); self::assertVersion($card,$item); self::assertCanControl($card,$member); if($card['location_type']!=='table') throw new RuntimeException('Only table cards can move as a group.');
            $update->execute(['x'=>(float)($item['x']??$card['x']),'y'=>(float)($item['y']??$card['y']),'rotation'=>(float)($item['rotation']??$card['rotation']),'z'=>(int)($item['z_index']??$card['z_index']),'id'=>$card['id']]); $moved[]=(string)$card['id'];
        }
        return ['card_ids'=>$moved,'count'=>count($moved)];
    }

    public static function reorderHand(PDO $database, array $session, array $member, array $payload): array
    {
        self::assertPlayer($session,$member); $ids=$payload['card_ids']??[]; if(!is_array($ids)||count($ids)>100)throw new RuntimeException('Card selection is invalid.');
        $all=$database->prepare("SELECT id FROM session_cards WHERE session_id=:session AND location_type='hand' AND hand_participant_id=:participant ORDER BY order_key,id FOR UPDATE");$all->execute(['session'=>$session['id'],'participant'=>$member['id']]);$owned=array_map(static fn(array $r):string=>(string)$r['id'],$all->fetchAll());$requested=array_values(array_unique(array_map('strval',$ids)));if($requested!==$owned)throw new RuntimeException('The hand changed; refresh and try again.');
        $update=$database->prepare('UPDATE session_cards SET order_key=:order, version=version+1 WHERE id=:id');foreach($requested as $i=>$id)$update->execute(['order'=>($i+1)*1000,'id'=>$id]);self::bumpHand($database,$session['id'],(string)$member['id']);return ['participant_id'=>(string)$member['id'],'card_count'=>count($requested)];
    }

    public static function giveCards(PDO $database, array $session, array $member, array $payload): array
    {
        self::assertPlayer($session,$member);$recipient=(string)($payload['recipient_participant_id']??'');$target=$database->prepare("SELECT id FROM session_participants WHERE session_id=:session AND id=:id AND removed_at IS NULL AND role IN ('host','player')");$target->execute(['session'=>$session['id'],'id'=>$recipient]);if(!$target->fetch())throw new RuntimeException('Recipient is invalid.');$ids=$payload['card_ids']??[];if(!is_array($ids)||count($ids)<1||count($ids)>100)throw new RuntimeException('Card selection is invalid.');$versions=is_array($payload['expected_card_versions']??null)?$payload['expected_card_versions']:[];$order=self::nextOrder($database,$session['id'],'hand',$recipient);$update=$database->prepare("UPDATE session_cards SET hand_participant_id=:participant,order_key=:order,face_state='private',version=version+1 WHERE id=:id");$moved=[];foreach($ids as $index=>$id){$card=self::card($database,$session['id'],(string)$id);if(array_key_exists((string)$id,$versions))self::assertVersion($card,['expected_card_version'=>$versions[(string)$id]]);if($card['location_type']!=='hand'||(string)$card['hand_participant_id']!==(string)$member['id'])throw new RuntimeException('Only your own hand cards can be given.');$update->execute(['participant'=>$recipient,'order'=>$order+(($index+1)*1000),'id'=>$card['id']]);$moved[]=(string)$card['id'];}self::normalizeHand($database,$session['id'],(string)$member['id']);self::bumpHand($database,$session['id'],(string)$member['id']);self::bumpHand($database,$session['id'],$recipient);return ['recipient_participant_id'=>$recipient,'card_count'=>count($moved)];
    }

    public static function peek(PDO $database, array $session, array $member, array $payload): array
    {
        self::assertPlayer($session,$member);$card=self::card($database,$session['id'],(string)($payload['card_id']??''));if(!in_array($card['location_type'],['table','pile'],true)||$card['face_state']==='up')throw new RuntimeException('Only a face-down table or pile card can be peeked.');self::assertCanControl($card,$member);Security::audit($database,(string)$member['user_id'],'card.peek','session_card',(string)$card['id']);return ['card_id'=>(string)$card['id'],'card_definition_id'=>(string)$card['card_definition_id'],'expires_in_seconds'=>30];
    }

    public static function moveCard(PDO $database, array $session, array $member, array $payload): array
    {
        self::assertPlayer($session, $member);
        $card = self::card($database, $session['id'], (string) ($payload['card_id'] ?? ''));
        self::assertVersion($card, $payload);
        self::assertCanControl($card, $member);
        if (!in_array($card['location_type'], ['table', 'hand'], true)) throw new RuntimeException('Only table or own-hand cards can change face state.');
        if ($card['location_type'] !== 'table') throw new RuntimeException('Only table cards have spatial positions.');
        $database->prepare('UPDATE session_cards SET x = :x, y = :y, rotation = :rotation, z_index = :z, version = version + 1 WHERE id = :id')
            ->execute(['x' => (float) ($payload['x'] ?? $card['x']), 'y' => (float) ($payload['y'] ?? $card['y']), 'rotation' => (float) ($payload['rotation'] ?? $card['rotation']), 'z' => (int) ($payload['z_index'] ?? $card['z_index']), 'id' => $card['id']]);
        return ['card_id' => (string) $card['id'], 'x' => (float) ($payload['x'] ?? $card['x']), 'y' => (float) ($payload['y'] ?? $card['y'])];
    }

    public static function face(PDO $database, array $session, array $member, string $operation, array $payload): array
    {
        self::assertPlayer($session, $member);
        $card = self::card($database, $session['id'], (string) ($payload['card_id'] ?? ''));
        self::assertVersion($card, $payload);
        self::assertCanControl($card, $member);
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
        if (!in_array($card['location_type'], ['table', 'pile'], true)) throw new RuntimeException('Only table or pile cards can move to a hand.');
        $order = self::nextOrder($database, $session['id'], 'hand', (string) $member['id']) + 1000;
        $database->prepare("UPDATE session_cards SET location_type = 'hand', deck_id = NULL, pile_id = NULL, hand_participant_id = :hand, order_key = :order_key, x = NULL, y = NULL, face_state = 'private', version = version + 1 WHERE id = :id")
            ->execute(['hand' => $member['id'], 'order_key' => $order, 'id' => $card['id']]);
        self::bumpHand($database, $session['id'], (string) $member['id']);
        return ['card_id' => (string) $card['id'], 'hand_participant_id' => (string) $member['id']];
    }

    public static function playFromHand(PDO $database, array $session, array $member, array $payload): array
    {
        self::assertPlayer($session, $member);
        $card = self::card($database, $session['id'], (string) ($payload['card_id'] ?? ''));
        self::assertVersion($card, $payload);
        if ($card['location_type'] !== 'hand' || (string) $card['hand_participant_id'] !== (string) $member['id']) throw new RuntimeException('Only your own hand cards can be played.');
        $face = ($payload['face_state'] ?? 'down') === 'up' ? 'up' : 'down';
        $database->prepare("UPDATE session_cards SET location_type = 'table', deck_id = NULL, pile_id = NULL, hand_participant_id = NULL, order_key = NULL, x = :x, y = :y, face_state = :face, version = version + 1 WHERE id = :id")
            ->execute(['x' => (float) ($payload['x'] ?? 0), 'y' => (float) ($payload['y'] ?? 0), 'face' => $face, 'id' => $card['id']]);
        self::normalizeHand($database, $session['id'], (string) $member['id']);
        self::bumpHand($database, $session['id'], (string) $member['id']);
        return ['card_id' => (string) $card['id'], 'face_state' => $face];
    }

    public static function returnToDeck(PDO $database, array $session, array $member, array $payload, string $position): array
    {
        self::assertPlayer($session, $member);
        $deckId = (string) ($payload['deck_id'] ?? '');
        self::deck($database, $session['id'], $deckId);
        $ids = $payload['card_ids'] ?? [];
        if (!is_array($ids) || count($ids) < 1 || count($ids) > 100) throw new RuntimeException('Card selection is invalid.');
        $moving = [];
        foreach ($ids as $id) {
            $card = self::card($database, $session['id'], (string) $id);
            self::assertCanControl($card, $member);
            $moving[] = $card;
        }
        $sourceContainers = [];
        foreach ($moving as $index => $card) {
            if ($card['location_type'] === 'deck') throw new RuntimeException('Cards already in a deck cannot be selected by identity.');
            $sourceContainers[$card['location_type'] . ':' . ($card['pile_id'] ?? $card['hand_participant_id'] ?? $card['deck_id'] ?? '')] = true;
            $database->prepare("UPDATE session_cards SET location_type = 'deck', deck_id = :deck, pile_id = NULL, hand_participant_id = NULL, order_key = :temp_order, x = NULL, y = NULL, face_state = 'down', version = version + 1 WHERE id = :id")
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
            if ($location === 'hand') self::normalizeHand($database, $session['id'], $container);
            if ($location === 'pile' && $container !== '') self::normalizePile($database, $session['id'], $container);
        }
        self::bumpDeck($database, $deckId);
        return ['deck_id' => $deckId, 'position' => $position, 'card_ids' => $movingIds];
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
        if ($card['locked_by'] !== null && (string) $card['locked_by'] !== (string) $member['user_id']) throw new RuntimeException('That card is locked.');
        if ($card['location_type'] === 'removed') throw new RuntimeException('That card is removed from play.');
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

    private static function normalizeHand(PDO $database, string $sessionId, string $participantId): void
    {
        $statement = $database->prepare("SELECT id FROM session_cards WHERE session_id = :session AND location_type = 'hand' AND hand_participant_id = :participant ORDER BY order_key, id");
        $statement->execute(['session' => $sessionId, 'participant' => $participantId]); self::assignOrder($database, $statement->fetchAll());
    }

    private static function normalizePile(PDO $database, string $sessionId, string $pileId): void
    {
        $statement = $database->prepare("SELECT id FROM session_cards WHERE session_id = :session AND location_type = 'pile' AND pile_id = :pile ORDER BY order_key, id");
        $statement->execute(['session' => $sessionId, 'pile' => $pileId]); self::assignOrder($database, $statement->fetchAll());
    }

    private static function bumpDeck(PDO $database, string $deckId): void { $database->prepare('UPDATE session_decks SET version = version + 1 WHERE id = :id')->execute(['id' => $deckId]); }
    private static function bumpHand(PDO $database, string $sessionId, string $participantId): void { $database->prepare('UPDATE session_hands SET version = version + 1 WHERE session_id = :session AND participant_id = :participant')->execute(['session' => $sessionId, 'participant' => $participantId]); }
    private static function bumpPile(PDO $database, string $pileId): void { $database->prepare('UPDATE session_piles SET version = version + 1 WHERE id = :id')->execute(['id' => $pileId]); }
}
