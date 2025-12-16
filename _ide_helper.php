<?php
/**
 * IDE Helper file for ZoomQuest
 * This file is not used by the game, it just provides type hints for IDEs
 */

namespace Bga\Games\Zoomquest;

class Game extends \Bga\GameFramework\Table {
    /** @var \Bga\GameFramework\TableStats */
    public $tableStats;
    
    /** @var \Bga\GameFramework\PlayerStats */
    public $playerStats;
    
    /** @var \Bga\GameFramework\TableOptions */
    public $tableOptions;
    
    /** @var \Bga\GameFramework\GameState */
    public $gamestate;
}

