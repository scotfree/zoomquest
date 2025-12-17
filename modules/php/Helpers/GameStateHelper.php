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
        return $this->game->getObjectListFromDB(
            "SELECT e.*, l.location_name 
             FROM entity e 
             JOIN location l ON e.location_id = l.location_id
             ORDER BY e.entity_type, e.entity_id"
        );
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
            "SELECT location_id, location_name, location_description FROM location"
        );

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
}

