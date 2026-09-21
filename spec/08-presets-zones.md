# Decks mats, presets and zones

Status: contract placeholder, 2026-09-21. The MVP stores generic session piles and table positions; mats, presets and spatial drop zones remain the next implementation step.

Presets pin immutable template/mat versions and generic configuration. They never contain old session cards, random results or private hand contents. A zone has a name, geometry and explicit priority. If a card center is inside multiple zones, the highest priority wins; an equal-priority conflicting effect is rejected rather than guessed. Spatial zones never replace logical card containment. All zone effects are applied in the same durable action transaction and projected through the viewer's privacy rules.
