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
use Bga\Games\Zoomquest\Helpers\CombatResolver;
use Bga\Games\Zoomquest\Helpers\GameStateHelper;
use Bga\Games\Zoomquest\States\RoundStart;

require_once("constants.inc.php");

class Game extends \Bga\GameFramework\Table
{
    // Helper instances
    private ?ConfigLoader $configLoader = null;
    private ?Deck $deck = null;
    private ?CombatResolver $combatResolver = null;
    private ?GameStateHelper $gameStateHelper = null;

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
     * Get CombatResolver helper (lazy initialization)
     */
    public function getCombatResolver(): CombatResolver
    {
        if ($this->combatResolver === null) {
            $this->combatResolver = new CombatResolver($this, $this->getDeck());
        }
        return $this->combatResolver;
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
     * Setup a new game from configuration
     */
    protected function setupNewGame($players, $options = [])
    {
        // Load scenario configuration
        $scenarioOption = (int)($this->tableOptions->get(100) ?? 1);
        $configLoader = $this->getConfigLoader();
        $filename = $configLoader->getScenarioFilename($scenarioOption);
        $config = $configLoader->loadScenario($filename);

        // Store level name
        $this->getGameStateHelper()->set(STATE_LEVEL_NAME, $config['level_name']);
        $this->getGameStateHelper()->set(STATE_ROUND, '0');

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

        // Setup map locations
        foreach ($config['map']['locations'] as $loc) {
            $id = addslashes($loc['id']);
            $name = addslashes($loc['name']);
            $desc = addslashes($loc['description'] ?? '');
            $this->DbQuery(
                "INSERT INTO location (location_id, location_name, location_description) 
                 VALUES ('$id', '$name', '$desc')"
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
            $location = addslashes($characterConfig['location']);

            $this->DbQuery(
                "INSERT INTO entity (entity_type, player_id, entity_name, entity_class, location_id, is_defeated) 
                 VALUES ('player', '$bgaPlayerId', '$name', '$class', '$location', 0)"
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
            $location = addslashes($monsterConfig['location']);

            // Create one monster copy per player
            for ($i = 0; $i < $playerCount; $i++) {
                // Add number suffix if multiple copies
                $displayName = $playerCount > 1 ? $name . ' ' . ($i + 1) : $name;
                $displayName = addslashes($displayName);

                $this->DbQuery(
                    "INSERT INTO entity (entity_type, player_id, entity_name, entity_class, location_id, is_defeated) 
                     VALUES ('monster', NULL, '$displayName', '$class', '$location', 0)"
                );
                $entityId = (int)$this->DbGetLastId();

                // Create deck from config (copy the deck for each monster)
                $deck->createDeck($entityId, $monsterConfig['decks']['active']);
                $deck->shuffleActive($entityId);
            }
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

        // All entities with their deck counts
        $entities = $this->getGameStateHelper()->getAllEntities();
        $deck = $this->getDeck();

        foreach ($entities as &$entity) {
            $entity['deck_counts'] = $deck->getPileCounts((int)$entity['entity_id']);
        }
        $result['entities'] = $entities;

        // Current action choices (if in action selection phase)
        $result['action_choices'] = $this->getCollectionFromDb(
            "SELECT entity_id, action_type, target_location FROM action_choice"
        );

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
     * Record an action choice for an entity
     */
    public function recordActionChoice(int $entityId, string $actionType, ?string $targetLocation = null): void
    {
        $targetSql = $targetLocation ? "'" . addslashes($targetLocation) . "'" : 'NULL';
        $this->DbQuery(
            "INSERT INTO action_choice (entity_id, action_type, target_location) 
             VALUES ($entityId, '$actionType', $targetSql)
             ON DUPLICATE KEY UPDATE action_type = '$actionType', target_location = $targetSql"
        );
    }

    /**
     * Clear all action choices (at start of new round)
     */
    public function clearActionChoices(): void
    {
        $this->DbQuery("DELETE FROM action_choice");
    }

    /**
     * Get action choice for an entity
     */
    public function getActionChoice(int $entityId): ?array
    {
        $result = $this->getObjectFromDB(
            "SELECT action_type, target_location FROM action_choice WHERE entity_id = $entityId"
        );
        return $result ?: null;
    }

    /**
     * Check if all players have submitted their action
     */
    public function haveAllPlayersChosen(): bool
    {
        $playerCount = (int)$this->getUniqueValueFromDB(
            "SELECT COUNT(*) FROM entity WHERE entity_type = 'player' AND is_defeated = 0"
        );
        $choiceCount = (int)$this->getUniqueValueFromDB(
            "SELECT COUNT(*) FROM action_choice ac
             JOIN entity e ON ac.entity_id = e.entity_id
             WHERE e.entity_type = 'player' AND e.is_defeated = 0"
        );

        return $choiceCount >= $playerCount;
    }

    /**
     * Auto-submit monster action choices (they always choose battle)
     */
    public function submitMonsterActions(): void
    {
        $monsters = $this->getGameStateHelper()->getMonsterEntities();
        foreach ($monsters as $monster) {
            $this->recordActionChoice((int)$monster['entity_id'], ACTION_BATTLE);
        }
    }
}
