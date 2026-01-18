<?php

/*
 * State constants
 */
const ST_GAME_SETUP = 1;
const ST_CHARACTER_SELECTION = 5;
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
const CARD_POISON = 'poison';
const CARD_MARK = 'mark';
const CARD_SELL = 'sell';
const CARD_STEAL = 'steal';
const CARD_WEALTH = 'wealth';
const CARD_BUY = 'buy';

/*
 * Marker types (for the new marker-based combat system)
 */
const MARKER_ATTACK = 'attack';
const MARKER_DEFEND = 'defend';
const MARKER_SNEAK = 'sneak';
const MARKER_WATCH = 'watch';
const MARKER_POISON = 'poison';
const MARKER_HEAL = 'heal';
const MARKER_MARK = 'mark';
const MARKER_SELL = 'sell';
const MARKER_WEALTH = 'wealth';
const MARKER_STEAL = 'steal';

/*
 * Card piles
 * - active: cards available to draw in action sequences (limited by entity_level)
 * - discard: cards played this sequence (shuffled back to active at end)
 * - inactive: stored cards (damaged cards go here, new acquisitions go here)
 */
const PILE_ACTIVE = 'active';
const PILE_DISCARD = 'discard';
const PILE_INACTIVE = 'inactive';

/*
 * Item types
 */
const ITEM_NEW_ACTION = 'new_action';
const ITEM_INFORMATION = 'information';
const ITEM_FACTION = 'faction';

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
 * Entity tags (legacy - being replaced by marker system)
 * Keeping for backward compatibility during transition
 */
const TAG_HIDDEN = 'hidden';
const TAG_BLOCKED = 'blocked';
const TAG_POISONED = 'poisoned';
const TAG_MARKED = 'marked';

/*
 * Game state keys
 */
const STATE_ROUND = 'round';
const STATE_LEVEL_NAME = 'level_name';
const STATE_CURRENT_SEQUENCE = 'current_sequence';
const STATE_SEQUENCES_TO_RESOLVE = 'sequences_to_resolve';
const STATE_SEQUENCE_ROUND = 'sequence_round';
const STATE_ROUND_RESOLUTIONS = 'round_resolutions';
const STATE_SEQUENCE_LOG = 'sequence_log'; // Accumulated log entries for the entire sequence
const STATE_FACTION_MATRIX = 'faction_matrix';
const STATE_VICTORY_CONDITION = 'victory_condition';

/*
 * Victory condition types
 */
const VICTORY_DEFEAT_ALL = 'defeat_all';
const VICTORY_REACH_LOCATION = 'reach_location';
const VICTORY_DEFEAT_TARGET = 'defeat_target';
const VICTORY_COLLECT_ITEM = 'collect_item';

/*
 * Individual goal tracking types
 */
const TRACK_LOCATIONS_VISITED = 'locations_visited';
const TRACK_TURNS_IN_TERRAIN = 'turns_in_terrain';
const TRACK_TURNS_IN_DIRECTION = 'turns_in_direction';
const TRACK_KILLING_BLOWS = 'killing_blows';
const TRACK_KILLING_BLOWS_FACTION = 'killing_blows_faction';
const TRACK_BLOCKS_FOR_ALLIES = 'blocks_for_allies';
const TRACK_CARD_PLAYS = 'card_plays';

/*
 * Goal state key
 */
const STATE_INDIVIDUAL_GOALS = 'individual_goals';
const STATE_BACKGROUND_IMAGE = 'background_image';

/*
 * Fog of war / visibility settings
 * entity_visibility: how far entities are visible (0=all, 1=same location, 2=+neighbors, etc)
 * location_visibility: how far locations are visible (0=all, 1=same location, 2=+neighbors, etc)
 */
const STATE_ENTITY_VISIBILITY = 'entity_visibility';
const STATE_LOCATION_VISIBILITY = 'location_visibility';
const DEFAULT_ENTITY_VISIBILITY = 2;
const DEFAULT_LOCATION_VISIBILITY = 2;

?>
