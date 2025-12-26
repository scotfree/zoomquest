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
 * Game.php
 *
 * Main game logic for ZoomQuest - a cooperative medieval fantasy game.
 */

declare(strict_types=1);

namespace Bga\Games\Zoomquest;

use Bga\Games\Zoomquest\Helpers\ConfigLoader;
use Bga\Games\Zoomquest\Helpers\Deck;
use Bga\Games\Zoomquest\Helpers\ActionSequenceResolver;
use Bga\Games\Zoomquest\Helpers\GameStateHelper;
use Bga\Games\Zoomquest\Helpers\GoalTracker;
use Bga\Games\Zoomquest\States\RoundStart;

require_once("constants.inc.php");

class Game extends \Bga\GameFramework\Table
{
    // Helper instances
    private ?ConfigLoader $configLoader = null;
    private ?Deck $deck = null;
    private ?ActionSequenceResolver $actionSequenceResolver = null;
    private ?GameStateHelper $gameStateHelper = null;
    private ?GoalTracker $goalTracker = null;

    function __construct()
    {
        parent::__construct();
        $this->initGameStateLabels([]);
    }

    /**
     * Get ConfigLoader helper (lazy initialization)
     */
    public function getConfigLoader(): ConfigLoader
    {
        if ($this->configLoader === null) {
            $this->configLoader = new ConfigLoader($this);
        }
        return $this->configLoader;
    }

    /**
     * Get Deck helper (lazy initialization)
     */
    public function getDeck(): Deck
    {
        if ($this->deck === null) {
            $this->deck = new Deck($this);
        }
        return $this->deck;
    }

    /**
     * Get ActionSequenceResolver helper (lazy initialization)
     */
    public function getActionSequenceResolver(): ActionSequenceResolver
    {
        if ($this->actionSequenceResolver === null) {
            $this->actionSequenceResolver = new ActionSequenceResolver($this, $this->getDeck());
        }
        return $this->actionSequenceResolver;
    }

    /**
     * Get GameStateHelper (lazy initialization)
     */
    public function getGameStateHelper(): GameStateHelper
    {
        if ($this->gameStateHelper === null) {
            $this->gameStateHelper = new GameStateHelper($this);
        }
        return $this->gameStateHelper;
    }

    /**
     * Get GoalTracker (lazy initialization)
     */
    public function getGoalTracker(): GoalTracker
    {
        if ($this->goalTracker === null) {
            $this->goalTracker = new GoalTracker($this);
        }
        return $this->goalTracker;
    }

    /**
     * Setup a new game from configuration
     */
    protected function setupNewGame($players, $options = [])
    {
        // Load scenario configuration
        $scenarioOption = (int)($this->tableOptions->get(100) ?? 1);
        $configLoader = $this->getConfigLoader();
        $filename = $configLoader->getScenarioFilename($scenarioOption);
        $config = $configLoader->loadScenario($filename);

        // Store level name and faction matrix
        $this->getGameStateHelper()->set(STATE_LEVEL_NAME, $config['level_name']);
        $this->getGameStateHelper()->set(STATE_ROUND, '0');
        
        // Store faction matrix from config
        if (isset($config['factions']['matrix'])) {
            $this->getGameStateHelper()->set(STATE_FACTION_MATRIX, json_encode($config['factions']['matrix']));
        }

        // Store victory condition
        if (isset($config['victory'])) {
            $this->getGameStateHelper()->set(STATE_VICTORY_CONDITION, json_encode($config['victory']));
        }

        // Setup BGA players
        $sql = "INSERT INTO player (player_id, player_color, player_canal, player_name, player_avatar) VALUES ";
        $values = [];
        $playerColors = ['ff0000', '008000', '0000ff', 'ffa500', '800080'];
        $playerIndex = 0;

        foreach ($players as $playerId => $player) {
            $color = $playerColors[$playerIndex % count($playerColors)];
            $values[] = "('" . $playerId . "','" . $color . "','" . $player['player_canal'] . "','" 
                . addslashes($player['player_name']) . "','" . addslashes($player['player_avatar']) . "')";
            $playerIndex++;
        }

        $sql .= implode(',', $values);
        $this->DbQuery($sql);
        $this->reloadPlayersBasicInfos();

        // Setup map locations (with terrain, direction, and coordinates)
        foreach ($config['map']['locations'] as $loc) {
            $id = addslashes($loc['id']);
            $name = addslashes($loc['name']);
            $desc = addslashes($loc['description'] ?? '');
            $terrain = addslashes($loc['terrain'] ?? 'wilderness');
            $direction = addslashes($loc['direction'] ?? 'center');
            $x = (float)($loc['x'] ?? 0.5);
            $y = (float)($loc['y'] ?? 0.5);
            $this->DbQuery(
                "INSERT INTO location (location_id, location_name, location_description, terrain, direction, x, y) 
                 VALUES ('$id', '$name', '$desc', '$terrain', '$direction', $x, $y)"
            );
        }

        // Setup map connections
        foreach ($config['map']['connections'] as $conn) {
            $name = addslashes($conn['name'] ?? '');
            $from = addslashes($conn['from']);
            $to = addslashes($conn['to']);
            $bidir = ($conn['bidirectional'] ?? true) ? 1 : 0;
            $this->DbQuery(
                "INSERT INTO connection (connection_name, location_from, location_to, bidirectional) 
                 VALUES ('$name', '$from', '$to', $bidir)"
            );
        }

        // Setup player entities - randomly select characters for each player
        $playerIds = array_keys($players);
        $playerCount = count($playerIds);
        $deck = $this->getDeck();

        // Shuffle characters and select one for each player
        $characters = $config['characters'];
        shuffle($characters);
        
        foreach ($playerIds as $index => $bgaPlayerId) {
            // Get character for this player (cycling if more players than characters)
            $characterConfig = $characters[$index % count($characters)];
            
            $name = addslashes($characterConfig['name']);
            $class = addslashes($characterConfig['class']);
            $faction = addslashes($characterConfig['faction'] ?? 'players');
            $location = addslashes($characterConfig['location']);

            $this->DbQuery(
                "INSERT INTO entity (entity_type, player_id, entity_name, entity_class, faction, location_id, is_defeated) 
                 VALUES ('player', '$bgaPlayerId', '$name', '$class', '$faction', '$location', 0)"
            );
            $entityId = (int)$this->DbGetLastId();

            // Create deck from config
            $deck->createDeck($entityId, $characterConfig['decks']['active']);
            $deck->shuffleActive($entityId);
        }

        // Setup monster entities - create copies equal to player count
        foreach ($config['monsters'] as $monsterConfig) {
            $name = addslashes($monsterConfig['name']);
            $class = addslashes($monsterConfig['class']);
            $faction = addslashes($monsterConfig['faction'] ?? 'monsters');
            $location = addslashes($monsterConfig['location']);

            // Create one monster copy per player
            for ($i = 0; $i < $playerCount; $i++) {
                // Add number suffix if multiple copies
                $displayName = $playerCount > 1 ? $name . ' ' . ($i + 1) : $name;
                $displayName = addslashes($displayName);

                $this->DbQuery(
                    "INSERT INTO entity (entity_type, player_id, entity_name, entity_class, faction, location_id, is_defeated) 
                     VALUES ('monster', NULL, '$displayName', '$class', '$faction', '$location', 0)"
                );
                $entityId = (int)$this->DbGetLastId();

                // Create deck from config (copy the deck for each monster)
                $deck->createDeck($entityId, $monsterConfig['decks']['active']);
                $deck->shuffleActive($entityId);

                // Create items from config (copy the items for each monster)
                if (isset($monsterConfig['items'])) {
                    foreach ($monsterConfig['items'] as $itemConfig) {
                        $itemName = addslashes($itemConfig['name']);
                        $itemType = addslashes($itemConfig['type']);
                        $itemData = addslashes(json_encode($itemConfig['data'] ?? []));
                        $this->DbQuery(
                            "INSERT INTO item (entity_id, item_name, item_type, item_data) 
                             VALUES ($entityId, '$itemName', '$itemType', '$itemData')"
                        );
                    }
                }
            }
        }

        // Assign individual goals to players
        if (isset($config['individual_goals']) && !empty($config['individual_goals'])) {
            $this->getGoalTracker()->assignGoals($players, $config['individual_goals']);
        }

        // Initialize stats
        $this->tableStats->init(['rounds_played', 'monsters_defeated'], 0);
        $this->playerStats->init(['battles_won', 'cards_destroyed', 'cards_lost', 'cards_healed'], 0);

        // Activate first player
        $this->activeNextPlayer();

        // Return initial state class for modern BGA framework
        return RoundStart::class;
    }

    /**
     * Get all game data for client
     */
    protected function getAllDatas(): array
    {
        $result = [];

        // Basic player info (including name for display)
        $result['players'] = $this->getCollectionFromDb(
            "SELECT player_id id, player_score score, player_color color, player_name name FROM player"
        );

        // Current round
        $result['round'] = $this->getGameStateHelper()->getRound();

        // Map data
        $result['map'] = $this->getGameStateHelper()->getMap();

        // All entities with their deck counts and items
        $entities = $this->getGameStateHelper()->getAllEntities();
        $deck = $this->getDeck();

        foreach ($entities as &$entity) {
            $entityId = (int)$entity['entity_id'];
            $entity['deck_counts'] = $deck->getPileCounts($entityId);
            
            // Get items for this entity
            $entity['items'] = $this->getObjectListFromDB(
                "SELECT item_id, item_name, item_type, item_data FROM item WHERE entity_id = $entityId"
            );
            // Parse item_data JSON
            foreach ($entity['items'] as &$item) {
                $item['item_data'] = json_decode($item['item_data'], true) ?? [];
            }
        }
        $result['entities'] = $entities;

        // Current move choices (if in move selection phase)
        $result['move_choices'] = $this->getCollectionFromDb(
            "SELECT player_id, target_location FROM move_choice"
        );

        // Victory condition
        $result['victory'] = $this->getGameStateHelper()->getVictoryCondition();

        // Individual goals (each player only sees their own)
        $result['player_goals'] = [];
        $goalTracker = $this->getGoalTracker();
        foreach ($result['players'] as $playerId => $player) {
            $goal = $goalTracker->getPlayerGoal((int)$playerId);
            if ($goal) {
                $progress = $goalTracker->getGoalProgress((int)$playerId);
                $result['player_goals'][$playerId] = [
                    'goal_id' => $goal['goal_id'],
                    'goal_name' => $goal['goal_name'],
                    'goal_description' => $goal['goal_description'],
                    'goal_icon' => $goal['goal_icon'],
                    'threshold' => (int)$goal['threshold'],
                    'progress' => $progress,
                    'complete' => $goalTracker->isGoalComplete((int)$playerId),
                ];
            }
        }

        return $result;
    }

    /**
     * Get game progression percentage
     */
    function getGameProgression()
    {
        // Based on monsters defeated
        $totalMonsters = (int)$this->getUniqueValueFromDB(
            "SELECT COUNT(*) FROM entity WHERE entity_type = 'monster'"
        );
        $defeatedMonsters = (int)$this->getUniqueValueFromDB(
            "SELECT COUNT(*) FROM entity WHERE entity_type = 'monster' AND is_defeated = 1"
        );

        if ($totalMonsters === 0) {
            return 100;
        }

        return (int)round(($defeatedMonsters / $totalMonsters) * 100);
    }

    /**
     * Record a move choice for a player
     */
    public function recordMoveChoice(int $playerId, ?string $targetLocation = null, ?string $cardOrder = null): void
    {
        $targetSql = $targetLocation ? "'" . addslashes($targetLocation) . "'" : 'NULL';
        $cardOrderSql = $cardOrder ? "'" . addslashes($cardOrder) . "'" : 'NULL';
        $this->DbQuery(
            "INSERT INTO move_choice (player_id, target_location, card_order) 
             VALUES ($playerId, $targetSql, $cardOrderSql)
             ON DUPLICATE KEY UPDATE target_location = $targetSql, card_order = $cardOrderSql"
        );
    }

    /**
     * Clear all move choices (at start of new round)
     */
    public function clearMoveChoices(): void
    {
        $this->DbQuery("DELETE FROM move_choice");
    }

    /**
     * Get move choice for a player
     */
    public function getMoveChoice(int $playerId): ?array
    {
        $result = $this->getObjectFromDB(
            "SELECT target_location, card_order FROM move_choice WHERE player_id = $playerId"
        );
        return $result ?: null;
    }
}

