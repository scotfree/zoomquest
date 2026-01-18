<?php

declare(strict_types=1);

namespace Bga\Games\Zoomquest\Helpers;

require_once(dirname(__DIR__) . '/constants.inc.php');

/**
 * Helper for managing game state values
 */
class GameStateHelper
{
    private $game;

    public function __construct($game)
    {
        $this->game = $game;
    }

    /**
     * Get a game state value
     */
    public function get(string $key): ?string
    {
        $key = addslashes($key);
        $result = $this->game->getUniqueValueFromDB(
            "SELECT state_value FROM game_state WHERE state_key = '$key'"
        );
        return $result;
    }

    /**
     * Set a game state value
     */
    public function set(string $key, $value): void
    {
        $key = addslashes($key);
        $value = addslashes((string)$value);
        $this->game->DbQuery(
            "INSERT INTO game_state (state_key, state_value) 
             VALUES ('$key', '$value')
             ON DUPLICATE KEY UPDATE state_value = '$value'"
        );
    }

    /**
     * Get current round number
     */
    public function getRound(): int
    {
        return (int)($this->get(STATE_ROUND) ?? 0);
    }

    /**
     * Increment and return new round number
     */
    public function incrementRound(): int
    {
        $round = $this->getRound() + 1;
        $this->set(STATE_ROUND, $round);
        return $round;
    }

    /**
     * Get all entities with their current state
     */
    public function getAllEntities(): array
    {
        $entities = $this->game->getObjectListFromDB(
            "SELECT e.*, l.location_name 
             FROM entity e 
             JOIN location l ON e.location_id = l.location_id
             ORDER BY e.entity_type, e.entity_id"
        );

        // Add tags to each entity
        foreach ($entities as &$entity) {
            $entityId = (int)$entity['entity_id'];
            $tags = $this->game->getObjectListFromDB(
                "SELECT tag_name, tag_value FROM entity_tag WHERE entity_id = $entityId"
            );
            $entity['tags'] = $tags;
        }

        return $entities;
    }

    /**
     * Get all players (entities controlled by actual players)
     */
    public function getPlayerEntities(): array
    {
        return $this->game->getObjectListFromDB(
            "SELECT e.*, l.location_name 
             FROM entity e 
             JOIN location l ON e.location_id = l.location_id
             WHERE e.entity_type = 'player'
             ORDER BY e.entity_id"
        );
    }

    /**
     * Get all monsters
     */
    public function getMonsterEntities(): array
    {
        return $this->game->getObjectListFromDB(
            "SELECT e.*, l.location_name 
             FROM entity e 
             JOIN location l ON e.location_id = l.location_id
             WHERE e.entity_type = 'monster' AND e.is_defeated = 0
             ORDER BY e.entity_id"
        );
    }

    /**
     * Get entity by player ID
     */
    public function getEntityByPlayerId(int $playerId): ?array
    {
        $result = $this->game->getObjectFromDB(
            "SELECT e.*, l.location_name 
             FROM entity e 
             JOIN location l ON e.location_id = l.location_id
             WHERE e.player_id = $playerId"
        );
        return $result ?: null;
    }

    /**
     * Get the map graph structure
     */
    public function getMap(): array
    {
        $locations = $this->game->getObjectListFromDB(
            "SELECT location_id, location_name, location_description, terrain, direction, x, y FROM location"
        );

        // Convert x,y to floats for JavaScript
        foreach ($locations as &$loc) {
            $loc['x'] = (float)$loc['x'];
            $loc['y'] = (float)$loc['y'];
        }

        $connections = $this->game->getObjectListFromDB(
            "SELECT connection_id, connection_name, location_from, location_to, bidirectional FROM connection"
        );

        return [
            'locations' => $locations,
            'connections' => $connections,
        ];
    }

    /**
     * Get adjacent locations for a given location
     */
    public function getAdjacentLocations(string $locationId): array
    {
        $locationId = addslashes($locationId);
        
        // Get connections going from this location (with destination name)
        $outgoing = $this->game->getObjectListFromDB(
            "SELECT c.location_to as location_id, c.connection_name, l.location_name
             FROM connection c
             JOIN location l ON c.location_to = l.location_id
             WHERE c.location_from = '$locationId'"
        );

        // Get bidirectional connections coming to this location (with destination name)
        $incoming = $this->game->getObjectListFromDB(
            "SELECT c.location_from as location_id, c.connection_name, l.location_name
             FROM connection c
             JOIN location l ON c.location_from = l.location_id
             WHERE c.location_to = '$locationId' AND c.bidirectional = 1"
        );

        // Merge and deduplicate
        $adjacent = [];
        foreach (array_merge($outgoing, $incoming) as $loc) {
            $adjacent[$loc['location_id']] = $loc;
        }

        return array_values($adjacent);
    }

    /**
     * Calculate distance between two locations using BFS
     * @param string $fromId Starting location
     * @param string $toId Target location
     * @return int Distance (0 if same location, -1 if unreachable)
     */
    public function getLocationDistance(string $fromId, string $toId): int
    {
        if ($fromId === $toId) return 0;
        
        $visited = [$fromId => true];
        $queue = [['id' => $fromId, 'distance' => 0]];
        
        while (!empty($queue)) {
            $current = array_shift($queue);
            $adjacent = $this->getAdjacentLocations($current['id']);
            
            foreach ($adjacent as $adj) {
                $adjId = $adj['location_id'];
                if ($adjId === $toId) {
                    return $current['distance'] + 1;
                }
                if (!isset($visited[$adjId])) {
                    $visited[$adjId] = true;
                    $queue[] = ['id' => $adjId, 'distance' => $current['distance'] + 1];
                }
            }
        }
        
        return -1; // Unreachable
    }

    /**
     * Get all location IDs within a certain distance from a starting location
     * @param string $fromId Starting location
     * @param int $maxDistance Maximum distance (0 = unlimited)
     * @return array Location IDs within range
     */
    public function getLocationsWithinRange(string $fromId, int $maxDistance): array
    {
        if ($maxDistance === 0) {
            // Return all locations
            $allLocs = $this->game->getObjectListFromDB("SELECT location_id FROM location");
            return array_column($allLocs, 'location_id');
        }
        
        $visited = [$fromId => 0];
        $queue = [['id' => $fromId, 'distance' => 0]];
        
        while (!empty($queue)) {
            $current = array_shift($queue);
            if ($current['distance'] >= $maxDistance) continue;
            
            $adjacent = $this->getAdjacentLocations($current['id']);
            
            foreach ($adjacent as $adj) {
                $adjId = $adj['location_id'];
                if (!isset($visited[$adjId])) {
                    $visited[$adjId] = $current['distance'] + 1;
                    $queue[] = ['id' => $adjId, 'distance' => $current['distance'] + 1];
                }
            }
        }
        
        return array_keys($visited);
    }

    /**
     * Move an entity to a new location
     */
    public function moveEntity(int $entityId, string $locationId): void
    {
        $locationId = addslashes($locationId);
        $this->game->DbQuery(
            "UPDATE entity SET location_id = '$locationId' WHERE entity_id = $entityId"
        );
    }

    /**
     * Check if all monsters are defeated
     */
    public function areAllMonstersDefeated(): bool
    {
        $alive = (int)$this->game->getUniqueValueFromDB(
            "SELECT COUNT(*) FROM entity WHERE entity_type = 'monster' AND is_defeated = 0"
        );
        return $alive === 0;
    }

    /**
     * Check if all players are defeated
     */
    public function areAllPlayersDefeated(): bool
    {
        $alive = (int)$this->game->getUniqueValueFromDB(
            "SELECT COUNT(*) FROM entity WHERE entity_type = 'player' AND is_defeated = 0"
        );
        return $alive === 0;
    }

    /**
     * Get the victory condition configuration
     */
    public function getVictoryCondition(): array
    {
        $json = $this->get(STATE_VICTORY_CONDITION);
        if (!$json) {
            // Default: defeat all monsters
            return [
                'type' => VICTORY_DEFEAT_ALL,
                'description' => 'Defeat all monsters'
            ];
        }
        return json_decode($json, true);
    }

    /**
     * Check if victory condition is met
     * Returns [bool $isVictory, string $message] or null if not yet determined
     */
    public function checkVictoryCondition(): ?array
    {
        $condition = $this->getVictoryCondition();
        $type = $condition['type'] ?? VICTORY_DEFEAT_ALL;
        $target = $condition['target'] ?? null;

        switch ($type) {
            case VICTORY_DEFEAT_ALL:
                if ($this->areAllMonstersDefeated()) {
                    return [true, 'All monsters have been defeated! Victory!'];
                }
                break;

            case VICTORY_REACH_LOCATION:
                if ($this->hasPlayerReachedLocation($target)) {
                    return [true, "A hero has reached the destination! Victory!"];
                }
                break;

            case VICTORY_DEFEAT_TARGET:
                if ($this->isTargetDefeated($target)) {
                    return [true, "$target has been slain! Victory!"];
                }
                break;

            case VICTORY_COLLECT_ITEM:
                // Items not implemented yet
                break;
        }

        return null;
    }

    /**
     * Check if any player has reached a specific location
     */
    public function hasPlayerReachedLocation(string $locationId): bool
    {
        $locationId = addslashes($locationId);
        $count = (int)$this->game->getUniqueValueFromDB(
            "SELECT COUNT(*) FROM entity 
             WHERE entity_type = 'player' AND is_defeated = 0 AND location_id = '$locationId'"
        );
        return $count > 0;
    }

    /**
     * Check if a specific target monster is defeated
     */
    public function isTargetDefeated(string $targetName): bool
    {
        $targetName = addslashes($targetName);
        // Check if monster with this name exists and is defeated
        $result = $this->game->getObjectFromDB(
            "SELECT is_defeated FROM entity 
             WHERE entity_type = 'monster' AND entity_name = '$targetName'"
        );
        return $result && $result['is_defeated'] == 1;
    }
}

