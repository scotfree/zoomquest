<?php

/*
 * State constants
 */
const ST_GAME_SETUP = 1;
const ST_ROUND_START = 10;
const ST_MOVE_SELECTION = 20;
const ST_RESOLVE_MOVES = 30;
const ST_SEQUENCE_SETUP = 40;
const ST_SEQUENCE_DRAW_CARDS = 50;
const ST_SEQUENCE_RESOLVE = 60;
const ST_SEQUENCE_ROUND_END = 70;
const ST_SEQUENCE_CLEANUP = 75;
const ST_CHECK_VICTORY = 80;
const ST_END_GAME = 99;

/*
 * Card types
 */
const CARD_ATTACK = 'attack';
const CARD_DEFEND = 'defend';
const CARD_HEAL = 'heal';
const CARD_SNEAK = 'sneak';
const CARD_WATCH = 'watch';
const CARD_SHUFFLE = 'shuffle';

/*
 * Card piles
 */
const PILE_ACTIVE = 'active';
const PILE_DISCARD = 'discard';
const PILE_DESTROYED = 'destroyed';

/*
 * Entity types
 */
const ENTITY_PLAYER = 'player';
const ENTITY_MONSTER = 'monster';

/*
 * Faction relationships
 */
const RELATION_HOSTILE = 'hostile';
const RELATION_NEUTRAL = 'neutral';
const RELATION_FRIENDLY = 'friendly';

/*
 * Entity tags (temporary status effects)
 */
const TAG_HIDDEN = 'hidden';
const TAG_BLOCKED = 'blocked';

/*
 * Game state keys
 */
const STATE_ROUND = 'round';
const STATE_LEVEL_NAME = 'level_name';
const STATE_CURRENT_SEQUENCE = 'current_sequence';
const STATE_SEQUENCES_TO_RESOLVE = 'sequences_to_resolve';
const STATE_SEQUENCE_ROUND = 'sequence_round';
const STATE_ROUND_RESOLUTIONS = 'round_resolutions';
const STATE_FACTION_MATRIX = 'faction_matrix';

?>
