<?php

/*
 * State constants
 */
const ST_GAME_SETUP = 1;
const ST_ROUND_START = 10;
const ST_ACTION_SELECTION = 20;
const ST_RESOLVE_ACTIONS = 30;
const ST_BATTLE_SETUP = 40;
const ST_BATTLE_DRAW_CARDS = 50;
const ST_BATTLE_RESOLVE_CARD = 60;
const ST_BATTLE_ROUND_END = 70;
const ST_BATTLE_CLEANUP = 75;
const ST_CHECK_VICTORY = 80;
const ST_END_GAME = 99;

/*
 * Card types
 */
const CARD_ATTACK = 'attack';
const CARD_DEFEND = 'defend';
const CARD_HEAL = 'heal';

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
 * Action types
 */
const ACTION_MOVE = 'move';
const ACTION_BATTLE = 'battle';
const ACTION_REST = 'rest';

/*
 * Game state keys
 */
const STATE_ROUND = 'round';
const STATE_LEVEL_NAME = 'level_name';
const STATE_CURRENT_BATTLE = 'current_battle';
const STATE_BATTLES_TO_RESOLVE = 'battles_to_resolve';

?>

