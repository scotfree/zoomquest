<?php

declare(strict_types=1);

namespace Bga\Games\Zoomquest\Helpers;

require_once(dirname(__DIR__) . '/constants.inc.php');

/**
 * Helper class for managing markers in the new combat system
 * 
 * Markers are temporary effects that accumulate during action sequences.
 * Each marker type has different resolution rules:
 * - attack: damage at end of round (cancelled by defend)
 * - defend: cancels attack markers, remainder carries over
 * - sneak: hidden for actions, adds to attack/defend when used
 * - watch: cancels sneak markers, consumed when doing so
 * - poison: damage at end of action sequence (persists)
 * - heal: restores poison markers first, then cards
 * - mark: bonus to attacks against target
 * - sell: minimum price for purchase
 * - wealth: payment for purchase
 * - steal: items stolen (cancelled by watch)
 */
class MarkerHelper
{
    private $game;

    public function __construct($game)
    {
        $this->game = $game;
    }

    /**
     * Add markers to an entity
     */
    public function addMarkers(int $entityId, string $markerType, int $count, ?int $sourceEntityId = null): void
    {
        if ($count <= 0) return;

        $markerType = addslashes($markerType);
        $sourceClause = $sourceEntityId !== null ? $sourceEntityId : 'NULL';

        $this->game->DbQuery(
            "INSERT INTO entity_marker (entity_id, marker_type, marker_count, source_entity_id)
             VALUES ($entityId, '$markerType', $count, $sourceClause)
             ON DUPLICATE KEY UPDATE marker_count = marker_count + $count"
        );
    }

    /**
     * Remove markers from an entity
     * @return int Number of markers actually removed
     */
    public function removeMarkers(int $entityId, string $markerType, int $count): int
    {
        $current = $this->getMarkerCount($entityId, $markerType);
        $toRemove = min($current, $count);

        if ($toRemove <= 0) return 0;

        $remaining = $current - $toRemove;
        $markerType = addslashes($markerType);

        if ($remaining <= 0) {
            $this->game->DbQuery(
                "DELETE FROM entity_marker 
                 WHERE entity_id = $entityId AND marker_type = '$markerType'"
            );
        } else {
            $this->game->DbQuery(
                "UPDATE entity_marker SET marker_count = $remaining 
                 WHERE entity_id = $entityId AND marker_type = '$markerType'"
            );
        }

        return $toRemove;
    }

    /**
     * Set marker count to a specific value
     */
    public function setMarkers(int $entityId, string $markerType, int $count): void
    {
        $markerType = addslashes($markerType);

        if ($count <= 0) {
            $this->game->DbQuery(
                "DELETE FROM entity_marker 
                 WHERE entity_id = $entityId AND marker_type = '$markerType'"
            );
        } else {
            $this->game->DbQuery(
                "INSERT INTO entity_marker (entity_id, marker_type, marker_count)
                 VALUES ($entityId, '$markerType', $count)
                 ON DUPLICATE KEY UPDATE marker_count = $count"
            );
        }
    }

    /**
     * Get marker count for an entity
     */
    public function getMarkerCount(int $entityId, string $markerType): int
    {
        $markerType = addslashes($markerType);
        $result = $this->game->getUniqueValueFromDB(
            "SELECT marker_count FROM entity_marker 
             WHERE entity_id = $entityId AND marker_type = '$markerType'"
        );
        return $result !== null ? (int)$result : 0;
    }

    /**
     * Get the source entity id for a marker (who placed it)
     */
    public function getMarkerSource(int $entityId, string $markerType): ?int
    {
        $markerType = addslashes($markerType);
        $result = $this->game->getUniqueValueFromDB(
            "SELECT source_entity_id FROM entity_marker 
             WHERE entity_id = $entityId AND marker_type = '$markerType'"
        );
        return $result !== null ? (int)$result : null;
    }

    /**
     * Get all markers for an entity
     */
    public function getAllMarkers(int $entityId): array
    {
        return $this->game->getObjectListFromDB(
            "SELECT marker_type, marker_count, source_entity_id 
             FROM entity_marker WHERE entity_id = $entityId"
        );
    }

    /**
     * Get all markers for all entities at a location
     */
    public function getMarkersAtLocation(string $locationId): array
    {
        $locationId = addslashes($locationId);
        return $this->game->getObjectListFromDB(
            "SELECT em.entity_id, em.marker_type, em.marker_count, em.source_entity_id,
                    e.entity_name, e.faction
             FROM entity_marker em
             JOIN entity e ON em.entity_id = e.entity_id
             WHERE e.location_id = '$locationId' AND e.is_defeated = 0"
        );
    }

    /**
     * Clear all markers for an entity (except persistent ones like sneak, watch, poison)
     */
    public function clearNonPersistentMarkers(int $entityId): void
    {
        $this->game->DbQuery(
            "DELETE FROM entity_marker 
             WHERE entity_id = $entityId 
             AND marker_type NOT IN ('" . MARKER_SNEAK . "', '" . MARKER_WATCH . "', '" . MARKER_POISON . "')"
        );
    }

    /**
     * Clear all markers of a specific type for all entities at a location
     */
    public function clearMarkersAtLocation(string $locationId, string $markerType): void
    {
        $locationId = addslashes($locationId);
        $markerType = addslashes($markerType);
        
        $this->game->DbQuery(
            "DELETE em FROM entity_marker em
             JOIN entity e ON em.entity_id = e.entity_id
             WHERE e.location_id = '$locationId' AND em.marker_type = '$markerType'"
        );
    }

    /**
     * Clear ALL markers for all entities at a location (except persistent ones)
     */
    public function clearNonPersistentMarkersAtLocation(string $locationId): void
    {
        $locationId = addslashes($locationId);
        
        $this->game->DbQuery(
            "DELETE em FROM entity_marker em
             JOIN entity e ON em.entity_id = e.entity_id
             WHERE e.location_id = '$locationId' 
             AND em.marker_type NOT IN ('" . MARKER_SNEAK . "', '" . MARKER_WATCH . "', '" . MARKER_POISON . "')"
        );
    }

    /**
     * Decrement all markers for all entities at a location by 1
     * Markers reaching 0 are removed
     * @param string $locationId Location to process
     * @return array Array of expired marker info
     */
    public function decrementAllMarkersAtLocation(string $locationId): array
    {
        $locationId = addslashes($locationId);
        $expired = [];
        
        // Get all markers at this location
        $markers = $this->game->getObjectListFromDB(
            "SELECT em.entity_id, em.marker_type, em.marker_count, e.entity_name
             FROM entity_marker em
             JOIN entity e ON em.entity_id = e.entity_id
             WHERE e.location_id = '$locationId' AND e.is_defeated = 0"
        );
        
        foreach ($markers as $m) {
            $entityId = (int)$m['entity_id'];
            $markerType = $m['marker_type'];
            $count = (int)$m['marker_count'];
            
            if ($count <= 1) {
                // Remove marker entirely
                $this->game->DbQuery(
                    "DELETE FROM entity_marker 
                     WHERE entity_id = $entityId AND marker_type = '" . addslashes($markerType) . "'"
                );
                $expired[] = [
                    'entity_id' => $entityId,
                    'entity_name' => $m['entity_name'],
                    'marker_type' => $markerType,
                    'expired' => true,
                ];
            } else {
                // Decrement by 1
                $newCount = $count - 1;
                $this->game->DbQuery(
                    "UPDATE entity_marker SET marker_count = $newCount 
                     WHERE entity_id = $entityId AND marker_type = '" . addslashes($markerType) . "'"
                );
            }
        }
        
        return $expired;
    }

    /**
     * Process watch vs sneak at a location
     * Watch markers cancel sneak markers 1:1, both are consumed
     * @return array Results of the cancellation
     */
    public function resolveWatchVsSneak(string $locationId): array
    {
        $locationId = addslashes($locationId);
        $results = [];

        // Get all entities at location with their markers
        $entities = $this->game->getObjectListFromDB(
            "SELECT entity_id, faction FROM entity 
             WHERE location_id = '$locationId' AND is_defeated = 0"
        );

        // Build faction groups
        $factionMatrix = $this->loadFactionMatrix();
        
        foreach ($entities as $sneaker) {
            $sneakerId = (int)$sneaker['entity_id'];
            $sneakCount = $this->getMarkerCount($sneakerId, MARKER_SNEAK);
            
            if ($sneakCount <= 0) continue;

            // Find hostile watchers
            foreach ($entities as $watcher) {
                if ($watcher['entity_id'] === $sneaker['entity_id']) continue;
                
                $relationship = $factionMatrix[$watcher['faction']][$sneaker['faction']] ?? RELATION_NEUTRAL;
                if ($relationship !== RELATION_HOSTILE) continue;

                $watcherId = (int)$watcher['entity_id'];
                $watchCount = $this->getMarkerCount($watcherId, MARKER_WATCH);
                
                if ($watchCount <= 0) continue;

                // Cancel sneak with watch 1:1
                $cancelled = min($sneakCount, $watchCount);
                $this->removeMarkers($sneakerId, MARKER_SNEAK, $cancelled);
                $this->removeMarkers($watcherId, MARKER_WATCH, $cancelled);

                $results[] = [
                    'sneaker_id' => $sneakerId,
                    'watcher_id' => $watcherId,
                    'cancelled' => $cancelled,
                ];

                $sneakCount -= $cancelled;
                if ($sneakCount <= 0) break;
            }
        }

        return $results;
    }

    /**
     * Load faction matrix from game state
     */
    private function loadFactionMatrix(): array
    {
        $matrixJson = $this->game->getUniqueValueFromDB(
            "SELECT state_value FROM game_state WHERE state_key = '" . STATE_FACTION_MATRIX . "'"
        );
        return $matrixJson ? json_decode($matrixJson, true) : [];
    }

    /**
     * Resolve attack vs defend markers at end of round
     * @return array Results with damage dealt per entity
     */
    public function resolveAttackDefend(int $sequenceId): array
    {
        $results = [];

        // Get all participants
        $participants = $this->game->getObjectListFromDB(
            "SELECT sp.entity_id, e.entity_name 
             FROM sequence_participant sp
             JOIN entity e ON sp.entity_id = e.entity_id
             WHERE sp.sequence_id = $sequenceId AND e.is_defeated = 0"
        );

        foreach ($participants as $p) {
            $entityId = (int)$p['entity_id'];
            $attackMarkers = $this->getMarkerCount($entityId, MARKER_ATTACK);
            $defendMarkers = $this->getMarkerCount($entityId, MARKER_DEFEND);

            // Defend cancels attack
            $cancelled = min($attackMarkers, $defendMarkers);
            $damage = $attackMarkers - $cancelled;
            $remainingDefend = $defendMarkers - $cancelled;

            // Clear attack markers (always consumed)
            $this->setMarkers($entityId, MARKER_ATTACK, 0);
            
            // Set remaining defend (carries over)
            $this->setMarkers($entityId, MARKER_DEFEND, $remainingDefend);

            $results[] = [
                'entity_id' => $entityId,
                'entity_name' => $p['entity_name'],
                'attack_markers' => $attackMarkers,
                'defend_markers' => $defendMarkers,
                'cancelled' => $cancelled,
                'damage' => $damage,
                'defend_remaining' => $remainingDefend,
            ];
        }

        return $results;
    }

    /**
     * Apply poison damage at end of action sequence
     * Consumes 1 poison marker per entity, deals 1 damage
     * @return array Results
     */
    public function applyPoisonDamage(): array
    {
        $results = [];

        // Get all entities with poison markers
        $poisoned = $this->game->getObjectListFromDB(
            "SELECT em.entity_id, em.marker_count, e.entity_name
             FROM entity_marker em
             JOIN entity e ON em.entity_id = e.entity_id
             WHERE em.marker_type = '" . MARKER_POISON . "' AND em.marker_count > 0 AND e.is_defeated = 0"
        );

        foreach ($poisoned as $p) {
            $entityId = (int)$p['entity_id'];
            
            // Remove 1 poison marker
            $this->removeMarkers($entityId, MARKER_POISON, 1);

            $results[] = [
                'entity_id' => $entityId,
                'entity_name' => $p['entity_name'],
                'damage' => 1,
                'poison_remaining' => (int)$p['marker_count'] - 1,
            ];
        }

        return $results;
    }

    /**
     * Resolve heal markers
     * First removes poison markers, then restores inactive cards
     * @param int $entityId The entity with heal markers
     * @param Deck $deck The deck helper
     * @return array Results
     */
    public function resolveHeal(int $entityId, Deck $deck): array
    {
        $healMarkers = $this->getMarkerCount($entityId, MARKER_HEAL);
        if ($healMarkers <= 0) return [];

        $results = [
            'poison_removed' => 0,
            'cards_restored' => [],
        ];

        // First, remove poison markers from self
        $poisonCount = $this->getMarkerCount($entityId, MARKER_POISON);
        if ($poisonCount > 0 && $healMarkers > 0) {
            $toRemove = min($poisonCount, $healMarkers);
            $this->removeMarkers($entityId, MARKER_POISON, $toRemove);
            $results['poison_removed'] = $toRemove;
            $healMarkers -= $toRemove;
        }

        // Then restore cards from inactive pile
        while ($healMarkers > 0) {
            $card = $deck->healOne($entityId);
            if (!$card) break;
            $results['cards_restored'][] = $card;
            $healMarkers--;
        }

        // Clear remaining heal markers
        $this->setMarkers($entityId, MARKER_HEAL, 0);

        return $results;
    }
}

