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
use Bga\Games\Zoomquest\Helpers\MarkerHelper;
use Bga\Games\Zoomquest\States\CharacterSelection;
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
    private ?Helpers\LocationLog $locationLog = null;
    private ?MarkerHelper $markerHelper = null;

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
     * Get MarkerHelper (lazy initialization)
     */
    public function getMarkerHelper(): MarkerHelper
    {
        if ($this->markerHelper === null) {
            $this->markerHelper = new MarkerHelper($this);
        }
        return $this->markerHelper;
    }

    /**
     * Get ActionSequenceResolver helper (lazy initialization)
     */
    public function getActionSequenceResolver(): ActionSequenceResolver
    {
        if ($this->actionSequenceResolver === null) {
            $this->actionSequenceResolver = new ActionSequenceResolver(
                $this, 
                $this->getDeck(), 
                $this->getMarkerHelper()
            );
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
     * Get LocationLog helper (lazy initialization)
     */
    public function getLocationLog(): Helpers\LocationLog
    {
        if ($this->locationLog === null) {
            $this->locationLog = new Helpers\LocationLog($this);
        }
        return $this->locationLog;
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

        // Store background image URL if provided
        if (isset($config['background_image'])) {
            $this->getGameStateHelper()->set(STATE_BACKGROUND_IMAGE, $config['background_image']);
        }

        // Store visibility settings (fog of war)
        $entityVisibility = $config['entity_visibility'] ?? DEFAULT_ENTITY_VISIBILITY;
        $locationVisibility = $config['location_visibility'] ?? DEFAULT_LOCATION_VISIBILITY;
        $this->getGameStateHelper()->set(STATE_ENTITY_VISIBILITY, (string)$entityVisibility);
        $this->getGameStateHelper()->set(STATE_LOCATION_VISIBILITY, (string)$locationVisibility);

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

        // Setup character entities - create all characters as unassigned
        // Players will select their characters in the CharacterSelection state
        $playerIds = array_keys($players);
        $playerCount = count($playerIds);
        $deck = $this->getDeck();

        $characters = $config['characters'];
        
        foreach ($characters as $characterConfig) {
            $name = addslashes($characterConfig['name']);
            $class = addslashes($characterConfig['class']);
            $faction = addslashes($characterConfig['faction'] ?? 'players');
            $location = addslashes($characterConfig['location']);
            $level = (int)($characterConfig['level'] ?? 3); // Characters default to level 3

            // Create as 'character' type with no player_id - will be updated when selected
            $this->DbQuery(
                "INSERT INTO entity (entity_type, player_id, entity_name, entity_class, faction, location_id, entity_level, is_defeated) 
                 VALUES ('character', NULL, '$name', '$class', '$faction', '$location', $level, 0)"
            );
            $entityId = (int)$this->DbGetLastId();

            // Create deck from config
            $deck->createDeck($entityId, $characterConfig['decks']['active'], 'active');
            if (isset($characterConfig['decks']['inactive']) && !empty($characterConfig['decks']['inactive'])) {
                $deck->createDeck($entityId, $characterConfig['decks']['inactive'], 'inactive');
            }
            $deck->shuffleActive($entityId);

            // Create items from config (if any)
            if (isset($characterConfig['items']) && !empty($characterConfig['items'])) {
                foreach ($characterConfig['items'] as $itemConfig) {
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

        // Setup monster entities - create copies equal to player count
        foreach ($config['monsters'] as $monsterConfig) {
            $name = addslashes($monsterConfig['name']);
            $class = addslashes($monsterConfig['class']);
            $faction = addslashes($monsterConfig['faction'] ?? 'monsters');
            $location = addslashes($monsterConfig['location']);

            // Create one monster copy per player
            $level = (int)($monsterConfig['level'] ?? 2); // Monsters default to level 2
            
            for ($i = 0; $i < $playerCount; $i++) {
                // Add number suffix if multiple copies
                $displayName = $playerCount > 1 ? $name . ' ' . ($i + 1) : $name;
                $displayName = addslashes($displayName);

                $this->DbQuery(
                    "INSERT INTO entity (entity_type, player_id, entity_name, entity_class, faction, location_id, entity_level, is_defeated) 
                     VALUES ('monster', NULL, '$displayName', '$class', '$faction', '$location', $level, 0)"
                );
                $entityId = (int)$this->DbGetLastId();

                // Create deck from config (copy the deck for each monster)
                $deck->createDeck($entityId, $monsterConfig['decks']['active'], 'active');
                if (isset($monsterConfig['decks']['inactive']) && !empty($monsterConfig['decks']['inactive'])) {
                    $deck->createDeck($entityId, $monsterConfig['decks']['inactive'], 'inactive');
                }
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
        $this->playerStats->init(['battles_won', 'cards_damaged', 'cards_lost', 'cards_healed'], 0);

        // Activate first player
        $this->activeNextPlayer();

        // Return initial state class - start with character selection
        return CharacterSelection::class;
    }

    /**
     * Get all game data for client
     */
    protected function getAllDatas(): array
    {
        $result = [];
        $stateHelper = $this->getGameStateHelper();

        // Basic player info (including name for display)
        $result['players'] = $this->getCollectionFromDb(
            "SELECT player_id id, player_score score, player_color color, player_name name FROM player"
        );

        // Current round
        $result['round'] = $stateHelper->getRound();

        // Get visibility settings
        $entityVisibilityRaw = $stateHelper->get(STATE_ENTITY_VISIBILITY);
        $locationVisibilityRaw = $stateHelper->get(STATE_LOCATION_VISIBILITY);
        $entityVisibility = $entityVisibilityRaw !== null ? (int)$entityVisibilityRaw : DEFAULT_ENTITY_VISIBILITY;
        $locationVisibility = $locationVisibilityRaw !== null ? (int)$locationVisibilityRaw : DEFAULT_LOCATION_VISIBILITY;
        
        // Debug visibility settings
        $this->trace("Visibility settings - entity: $entityVisibility (raw: " . var_export($entityVisibilityRaw, true) . "), location: $locationVisibility (raw: " . var_export($locationVisibilityRaw, true) . ")");
        
        // Get current player's character location (for fog of war)
        $currentPlayerId = $this->getCurrentPlayerId();
        $myCharacter = $this->getObjectFromDB(
            "SELECT location_id FROM entity WHERE player_id = '$currentPlayerId' AND entity_type = 'player'"
        );
        $myLocationId = $myCharacter ? $myCharacter['location_id'] : null;
        
        $this->trace("Fog of war - player: $currentPlayerId, location: " . ($myLocationId ?? 'null'));
        
        // Calculate visible locations
        $visibleEntityLocations = [];
        $visibleMapLocations = [];
        
        if ($myLocationId) {
            $visibleEntityLocations = $stateHelper->getLocationsWithinRange($myLocationId, $entityVisibility);
            $visibleMapLocations = $stateHelper->getLocationsWithinRange($myLocationId, $locationVisibility);
            $this->trace("Visible entity locations: " . json_encode($visibleEntityLocations));
            $this->trace("Visible map locations: " . json_encode($visibleMapLocations));
        }

        // Map data - filter by location visibility
        $fullMap = $stateHelper->getMap();
        if ($locationVisibility === 0 || !$myLocationId) {
            // No fog for locations - show all
            $result['map'] = $fullMap;
        } else {
            // Filter locations to only visible ones
            $result['map'] = [
                'locations' => array_values(array_filter($fullMap['locations'], function($loc) use ($visibleMapLocations) {
                    return in_array($loc['location_id'], $visibleMapLocations);
                })),
                'connections' => array_values(array_filter($fullMap['connections'], function($conn) use ($visibleMapLocations) {
                    // Show connection if either endpoint is visible
                    return in_array($conn['location_from'], $visibleMapLocations) || 
                           in_array($conn['location_to'], $visibleMapLocations);
                })),
            ];
        }

        // All entities with their deck counts and items - filter by entity visibility
        $entities = $stateHelper->getAllEntities();
        $deck = $this->getDeck();

        $filteredEntities = [];
        foreach ($entities as &$entity) {
            $entityId = (int)$entity['entity_id'];
            
            // Check visibility (0 means no fog, or entity is in visible range)
            $isVisible = $entityVisibility === 0 || 
                         !$myLocationId || 
                         in_array($entity['location_id'], $visibleEntityLocations);
            
            if (!$isVisible) continue;
            
            $entity['deck_counts'] = $deck->getPileCounts($entityId);
            
            // Get items for this entity
            $entity['items'] = $this->getObjectListFromDB(
                "SELECT item_id, item_name, item_type, item_data FROM item WHERE entity_id = $entityId"
            );
            // Parse item_data JSON
            foreach ($entity['items'] as &$item) {
                $item['item_data'] = json_decode($item['item_data'], true) ?? [];
            }
            
            $filteredEntities[] = $entity;
        }
        $result['entities'] = $filteredEntities;

        // Current move choices (if in move selection phase)
        $result['move_choices'] = $this->getCollectionFromDb(
            "SELECT player_id, target_location FROM move_choice"
        );

        // Victory condition
        $result['victory'] = $stateHelper->getVictoryCondition();

        // Background image for the map (if any)
        $result['background_image'] = $stateHelper->get(STATE_BACKGROUND_IMAGE);

        // Visibility settings (for client display)
        $result['entity_visibility'] = $entityVisibility;
        $result['location_visibility'] = $locationVisibility;

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

        // Location logs (action sequence history per location)
        $result['location_logs'] = $this->getLocationLog()->getAllLogs();

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
     * @param bool $isPlanning If true, player chose "Plan" and won't participate in action sequences
     */
    public function recordMoveChoice(int $playerId, ?string $targetLocation = null, ?string $cardOrder = null, bool $isPlanning = false): void
    {
        $targetSql = $targetLocation ? "'" . addslashes($targetLocation) . "'" : 'NULL';
        $cardOrderSql = $cardOrder ? "'" . addslashes($cardOrder) . "'" : 'NULL';
        $isPlanningInt = $isPlanning ? 1 : 0;
        $this->DbQuery(
            "INSERT INTO move_choice (player_id, target_location, card_order, is_planning) 
             VALUES ($playerId, $targetSql, $cardOrderSql, $isPlanningInt)
             ON DUPLICATE KEY UPDATE target_location = $targetSql, card_order = $cardOrderSql, is_planning = $isPlanningInt"
        );
    }

    /**
     * Check if a player chose to plan (skip action sequence)
     */
    public function isPlayerPlanning(int $playerId): bool
    {
        $result = $this->getUniqueValueFromDB(
            "SELECT is_planning FROM move_choice WHERE player_id = $playerId"
        );
        return $result == 1;
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

