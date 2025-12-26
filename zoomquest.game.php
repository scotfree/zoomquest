<?php
/**
 *------
 * BGA framework: © Gregory Isabelli <gisabelli@boardgamearena.com> & Emmanuel Colin <ecolin@boardgamearena.com>
 * ZoomQuest implementation: © Your Name
 *
 * This code has been produced on the BGA studio platform for use on http://boardgamearena.com.
 * See http://en.boardgamearena.com/#!doc/Studio for more information.
 * -----
 *
 * zoomquest.game.php
 *
 * Main game entry point - bootstraps the Game class
 */

declare(strict_types=1);

namespace Bga\Games\Zoomquest;

require_once(APP_GAMEMODULE_PATH . 'module/table/table.game.php');

// Load game modules
require_once(__DIR__ . '/modules/php/Game.php');
require_once(__DIR__ . '/modules/php/Helpers/ConfigLoader.php');
require_once(__DIR__ . '/modules/php/Helpers/Deck.php');
require_once(__DIR__ . '/modules/php/Helpers/ActionSequenceResolver.php');
require_once(__DIR__ . '/modules/php/Helpers/GameStateHelper.php');
require_once(__DIR__ . '/modules/php/Helpers/GoalTracker.php');

