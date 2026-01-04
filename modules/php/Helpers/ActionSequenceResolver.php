<?php

declare(strict_types=1);

namespace Bga\Games\Zoomquest\Helpers;

require_once(dirname(__DIR__) . '/constants.inc.php');

/**
 * Handles action sequence resolution using the marker-based combat system
 * 
 * Each round:
 * 1. All entities draw cards simultaneously
 * 2. Place markers based on card type and power:
 *    - Watch markers on self (persist, cancel sneak)
 *    - Sneak markers on self (persist, hidden + bonus to attack/defend)
 *    - Mark markers on target (bonus to attacks)
 *    - Attack markers on target (damage at end of round)
 *    - Defend markers on target (cancel attack markers)
 *    - Poison markers on target (damage at end of sequence)
 *    - Heal markers on self (remove poison, restore cards)
 *    - Shuffle markers on self (move cards from discard)
 *    - Sell/Wealth/Steal for commerce
 * 3. Resolve:
 *    - Watch cancels Sneak (1:1, both consumed)
 *    - Attack vs Defend (1:1, defend carries over)
 *    - Remaining attack = damage
 *    - Heal removes poison first, then restores cards
 *    - Shuffle moves cards from discard to active
 *    - Sell/Wealth/Steal for item transfers
 * 4. End of round: clear attack markers
 * 5. End of sequence: apply poison damage, clear non-persistent markers
 */
class ActionSequenceResolver
{
    private $game;
    private Deck $deck;
    private MarkerHelper $markers;
    private array $factionMatrix = [];

    public function __construct($game, Deck $deck, MarkerHelper $markers)
    {
        $this->game = $game;
        $this->deck = $deck;
        $this->markers = $markers;
        $this->loadFactionMatrix();
    }

    /**
     * Get player_id for an entity (returns null if not a player entity)
     */
    private function getPlayerIdForEntity(int $entityId): ?int
    {
        $result = $this->game->getUniqueValueFromDB(
            "SELECT player_id FROM entity WHERE entity_id = $entityId AND entity_type = 'player'"
        );
        return $result !== null ? (int)$result : null;
    }

    /**
     * Track goal progress for a player entity
     */
    private function trackGoalForEntity(int $entityId, string $trackType, ?string $filter = null): void
    {
        $playerId = $this->getPlayerIdForEntity($entityId);
        if ($playerId !== null) {
            $this->game->getGoalTracker()->incrementProgress($playerId, $trackType, $filter);
        }
    }

    /**
     * Track killing blow for a player entity
     */
    private function trackKillingBlow(int $killerEntityId, int $victimEntityId): void
    {
        $playerId = $this->getPlayerIdForEntity($killerEntityId);
        if ($playerId !== null) {
            $victim = $this->game->getObjectFromDB(
                "SELECT faction FROM entity WHERE entity_id = $victimEntityId"
            );
            $victimFaction = $victim ? $victim['faction'] : 'unknown';
            $this->game->getGoalTracker()->trackKillingBlow($playerId, $victimFaction);
        }
    }

    /**
     * Load faction matrix from game state
     */
    private function loadFactionMatrix(): void
    {
        $matrixJson = $this->game->getUniqueValueFromDB(
            "SELECT state_value FROM game_state WHERE state_key = '" . STATE_FACTION_MATRIX . "'"
        );
        $this->factionMatrix = $matrixJson ? json_decode($matrixJson, true) : [];
    }

    /**
     * Get relationship between two factions
     */
    public function getRelationship(string $faction1, string $faction2): string
    {
        return $this->factionMatrix[$faction1][$faction2] ?? RELATION_NEUTRAL;
    }

    /**
     * Get all entities at a location with faction info
     */
    public function getEntitiesAtLocation(string $locationId): array
    {
        return $this->game->getObjectListFromDB(
            "SELECT entity_id, entity_type, entity_name, entity_class, faction, player_id 
             FROM entity 
             WHERE location_id = '$locationId' AND is_defeated = 0"
        );
    }

    /**
     * Check if an action sequence should occur at a location
     */
    public function shouldSequenceOccur(string $locationId): bool
    {
        $playerCount = (int)$this->game->getUniqueValueFromDB(
            "SELECT COUNT(*) FROM entity 
             WHERE location_id = '$locationId' AND entity_type = 'player' AND is_defeated = 0"
        );
        return $playerCount > 0;
    }

    /**
     * Get locations where sequences should occur
     */
    public function getSequenceLocations(): array
    {
        $locations = $this->game->getObjectListFromDB(
            "SELECT DISTINCT location_id FROM entity 
             WHERE entity_type = 'player' AND is_defeated = 0"
        );
        return array_column($locations, 'location_id');
    }

    /**
     * Create a sequence record and initialize participants
     */
    public function createSequence(string $locationId): int
    {
        $this->game->DbQuery(
            "INSERT INTO action_sequence (location_id, is_resolved) VALUES ('$locationId', 0)"
        );
        $sequenceId = (int)$this->game->DbGetLastId();

        $entities = $this->getEntitiesAtLocation($locationId);
        foreach ($entities as $entity) {
            $entityId = (int)$entity['entity_id'];
            
            // Skip players who chose "Plan"
            if ($entity['entity_type'] === 'player' && $entity['player_id']) {
                $playerId = (int)$entity['player_id'];
                if ($this->game->isPlayerPlanning($playerId)) {
                    continue;
                }
            }
            
            $this->game->DbQuery(
                "INSERT INTO sequence_participant (sequence_id, entity_id, drawn_card_id, target_entity_id, block_count, is_resolved) 
                 VALUES ($sequenceId, $entityId, NULL, NULL, 0, 0)"
            );
        }

        return $sequenceId;
    }

    /**
     * Get entity health (active + discard pile count)
     */
    private function getEntityHealth(int $entityId): int
    {
        $counts = $this->deck->getPileCounts($entityId);
        return $counts['active'] + $counts['discard'];
    }

    /**
     * Check if entity is hidden (has sneak markers)
     */
    public function isHidden(int $entityId): bool
    {
        return $this->markers->getMarkerCount($entityId, MARKER_SNEAK) > 0;
    }

    /**
     * Get lowest health target with specific relationship to actor
     */
    private function getLowestHealthTarget(int $sequenceId, int $actorEntityId, string $relationship, bool $includeHidden = true): ?array
    {
        $actor = $this->game->getObjectFromDB(
            "SELECT faction FROM entity WHERE entity_id = $actorEntityId"
        );
        $actorFaction = $actor['faction'];

        $participants = $this->game->getObjectListFromDB(
            "SELECT e.entity_id, e.entity_name, e.faction
             FROM sequence_participant sp
             JOIN entity e ON sp.entity_id = e.entity_id
             WHERE sp.sequence_id = $sequenceId AND e.is_defeated = 0"
        );

        $candidates = [];
        foreach ($participants as $p) {
            $rel = $this->getRelationship($actorFaction, $p['faction']);
            if ($rel !== $relationship) continue;

            if (!$includeHidden && $this->isHidden((int)$p['entity_id'])) continue;

            $health = $this->getEntityHealth((int)$p['entity_id']);
            $p['health'] = $health;
            $candidates[] = $p;
        }

        if (empty($candidates)) return null;

        usort($candidates, fn($a, $b) => $a['health'] <=> $b['health']);
        $lowestHealth = $candidates[0]['health'];
        $ties = array_filter($candidates, fn($c) => $c['health'] === $lowestHealth);
        shuffle($ties);

        return $ties[0] ?? null;
    }

    /**
     * Draw cards for all participants and determine targets
     */
    public function drawCardsForSequence(int $sequenceId, int $currentRound): array
    {
        $participants = $this->game->getObjectListFromDB(
            "SELECT sp.entity_id, e.entity_type, e.entity_name, e.faction 
             FROM sequence_participant sp
             JOIN entity e ON sp.entity_id = e.entity_id
             WHERE sp.sequence_id = $sequenceId AND e.is_defeated = 0"
        );

        $drawnCards = [];
        foreach ($participants as $p) {
            $entityId = (int)$p['entity_id'];
            $card = $this->deck->drawTop($entityId);

            if ($card) {
                $cardId = (int)$card['card_id'];
                $cardType = $card['card_type'];
                $cardPower = (int)($card['card_power'] ?? 1);
                $cardName = $card['card_name'] ?? ucfirst($cardType);

                $targetId = $this->determineTarget($sequenceId, $entityId, $cardType);

                $this->game->DbQuery(
                    "UPDATE sequence_participant 
                     SET drawn_card_id = $cardId, 
                         target_entity_id = " . ($targetId !== null ? $targetId : "NULL") . ",
                         is_resolved = 0 
                     WHERE sequence_id = $sequenceId AND entity_id = $entityId"
                );

                $targetName = null;
                if ($targetId !== null) {
                    $target = $this->game->getObjectFromDB(
                        "SELECT entity_name FROM entity WHERE entity_id = $targetId"
                    );
                    $targetName = $target ? $target['entity_name'] : null;
                }

                $this->trackGoalForEntity($entityId, TRACK_CARD_PLAYS, $cardType);

                $drawnCards[] = [
                    'entity_id' => $entityId,
                    'entity_type' => $p['entity_type'],
                    'entity_name' => $p['entity_name'],
                    'faction' => $p['faction'],
                    'card_id' => $cardId,
                    'card_name' => $cardName,
                    'card_type' => $cardType,
                    'card_power' => $cardPower,
                    'target_id' => $targetId,
                    'target_name' => $targetName,
                ];
            }
        }

        return $drawnCards;
    }

    /**
     * Determine target for a card based on type and faction
     */
    private function determineTarget(int $sequenceId, int $entityId, string $cardType): ?int
    {
        switch ($cardType) {
            case CARD_HEAL:
            case CARD_DEFEND:
                $target = $this->getLowestHealthTarget($sequenceId, $entityId, RELATION_FRIENDLY, true);
                return $target ? (int)$target['entity_id'] : $entityId; // Default to self

            case CARD_ATTACK:
            case CARD_POISON:
            case CARD_MARK:
                $target = $this->getLowestHealthTarget($sequenceId, $entityId, RELATION_HOSTILE, false);
                return $target ? (int)$target['entity_id'] : null;

            case CARD_STEAL:
                $target = $this->getNeutralWithItems($sequenceId, $entityId);
                return $target ? (int)$target['entity_id'] : null;

            case CARD_WEALTH:
                $target = $this->getSellingEntity($sequenceId, $entityId);
                return $target ? (int)$target['entity_id'] : null;

            case CARD_SNEAK:
            case CARD_WATCH:
            case CARD_SHUFFLE:
            case CARD_SELL:
                return $entityId; // Self-targeting

            default:
                return null;
        }
    }

    /**
     * Find a neutral entity with items at the same location
     */
    private function getNeutralWithItems(int $sequenceId, int $actorEntityId): ?array
    {
        $actor = $this->game->getObjectFromDB(
            "SELECT faction FROM entity WHERE entity_id = $actorEntityId"
        );
        $actorFaction = $actor['faction'];

        $participants = $this->game->getObjectListFromDB(
            "SELECT e.entity_id, e.entity_name, e.faction, 
                    (SELECT COUNT(*) FROM item i WHERE i.entity_id = e.entity_id) as item_count
             FROM sequence_participant sp
             JOIN entity e ON sp.entity_id = e.entity_id
             WHERE sp.sequence_id = $sequenceId AND e.is_defeated = 0"
        );

        $candidates = [];
        foreach ($participants as $p) {
            $rel = $this->getRelationship($actorFaction, $p['faction']);
            if ($rel !== RELATION_NEUTRAL) continue;
            if ((int)$p['item_count'] === 0) continue;
            $candidates[] = $p;
        }

        if (empty($candidates)) return null;
        shuffle($candidates);
        return $candidates[0];
    }

    /**
     * Find an entity that is selling (has sell markers)
     */
    private function getSellingEntity(int $sequenceId, int $actorEntityId): ?array
    {
        $participants = $this->game->getObjectListFromDB(
            "SELECT e.entity_id, e.entity_name, e.faction
             FROM sequence_participant sp
             JOIN entity e ON sp.entity_id = e.entity_id
             WHERE sp.sequence_id = $sequenceId AND e.is_defeated = 0"
        );

        foreach ($participants as $p) {
            if ((int)$p['entity_id'] === $actorEntityId) continue;
            if ($this->markers->getMarkerCount((int)$p['entity_id'], MARKER_SELL) > 0) {
                return $p;
            }
        }

        return null;
    }

    /**
     * Resolve all cards for this round using the marker system
     * Returns array of resolution results
     */
    public function resolveRound(int $sequenceId, int $currentRound): array
    {
        $results = [];

        // Get all drawn cards
        $cards = $this->game->getObjectListFromDB(
            "SELECT sp.entity_id, sp.drawn_card_id, sp.target_entity_id,
                    e.entity_type, e.entity_name, e.faction, 
                    c.card_name, c.card_type, c.card_power
             FROM sequence_participant sp
             JOIN entity e ON sp.entity_id = e.entity_id
             JOIN card c ON sp.drawn_card_id = c.card_id
             WHERE sp.sequence_id = $sequenceId AND sp.drawn_card_id IS NOT NULL"
        );

        // Get location for this sequence
        $sequence = $this->game->getObjectFromDB(
            "SELECT location_id FROM action_sequence WHERE sequence_id = $sequenceId"
        );
        $locationId = $sequence ? $sequence['location_id'] : '';

        // Phase 1: Place Watch markers
        foreach ($cards as $card) {
            if ($card['card_type'] === CARD_WATCH) {
                $result = $this->placeWatchMarkers($card, $currentRound);
                $results[] = $result;
            }
        }

        // Phase 2: Watch cancels Sneak (1:1, both consumed)
        $watchVsSneakResults = $this->markers->resolveWatchVsSneak($locationId);
        foreach ($watchVsSneakResults as $wvs) {
            $results[] = [
                'effect' => 'watch_cancels_sneak',
                'sneaker_id' => $wvs['sneaker_id'],
                'watcher_id' => $wvs['watcher_id'],
                'cancelled' => $wvs['cancelled'],
            ];
        }

        // Phase 3: Place Sneak markers (for those playing sneak this round)
        foreach ($cards as $card) {
            if ($card['card_type'] === CARD_SNEAK) {
                $result = $this->placeSneakMarkers($card, $currentRound);
                $results[] = $result;
            }
        }

        // Phase 4: Place Mark markers on targets
        foreach ($cards as $card) {
            if ($card['card_type'] === CARD_MARK) {
                $result = $this->placeMarkMarkers($card);
                $results[] = $result;
            }
        }

        // Phase 5: Place Attack markers (including sneak bonus)
        foreach ($cards as $card) {
            if ($card['card_type'] === CARD_ATTACK) {
                $result = $this->placeAttackMarkers($card);
                $results[] = $result;
            }
        }

        // Phase 6: Place Defend markers
        foreach ($cards as $card) {
            if ($card['card_type'] === CARD_DEFEND) {
                $result = $this->placeDefendMarkers($card);
                $results[] = $result;
            }
        }

        // Phase 7: Resolve Attack vs Defend, deal damage
        $combatResults = $this->resolveCombat($sequenceId);
        foreach ($combatResults as $cr) {
            $results[] = $cr;
        }

        // Phase 8: Place Poison markers
        foreach ($cards as $card) {
            if ($card['card_type'] === CARD_POISON) {
                $result = $this->placePoisonMarkers($card);
                $results[] = $result;
            }
        }

        // Phase 9: Place and resolve Heal markers
        foreach ($cards as $card) {
            if ($card['card_type'] === CARD_HEAL) {
                $result = $this->resolveHeal($card);
                $results[] = $result;
            }
        }

        // Phase 10: Resolve Shuffle
        foreach ($cards as $card) {
            if ($card['card_type'] === CARD_SHUFFLE) {
                $result = $this->resolveShuffle($card);
                $results[] = $result;
            }
        }

        // Phase 11: Place Sell markers
        foreach ($cards as $card) {
            if ($card['card_type'] === CARD_SELL) {
                $result = $this->placeSellMarkers($card);
                $results[] = $result;
            }
        }

        // Phase 12: Resolve Wealth (purchase)
        foreach ($cards as $card) {
            if ($card['card_type'] === CARD_WEALTH) {
                $result = $this->resolveWealth($sequenceId, $card);
                $results[] = $result;
            }
        }

        // Phase 13: Resolve Steal (cancelled by watch)
        foreach ($cards as $card) {
            if ($card['card_type'] === CARD_STEAL) {
                $result = $this->resolveSteal($card);
                $results[] = $result;
            }
        }

        // Move all drawn cards to discard
        foreach ($cards as $card) {
            $this->deck->discard((int)$card['drawn_card_id']);
        }

        // Mark all as resolved
        $this->game->DbQuery(
            "UPDATE sequence_participant SET is_resolved = 1 WHERE sequence_id = $sequenceId"
        );

        return $results;
    }

    /**
     * Place watch markers on self
     */
    private function placeWatchMarkers(array $card, int $round): array
    {
        $entityId = (int)$card['entity_id'];
        $power = (int)($card['card_power'] ?? 1);

        $this->markers->addMarkers($entityId, MARKER_WATCH, $power);

        return [
            'entity_id' => $entityId,
            'entity_name' => $card['entity_name'],
            'card_name' => $card['card_name'],
            'card_type' => CARD_WATCH,
            'card_power' => $power,
            'effect' => 'watch',
            'markers_placed' => $power,
        ];
    }

    /**
     * Place sneak markers on self
     */
    private function placeSneakMarkers(array $card, int $round): array
    {
        $entityId = (int)$card['entity_id'];
        $power = (int)($card['card_power'] ?? 1);

        $this->markers->addMarkers($entityId, MARKER_SNEAK, $power);

        return [
            'entity_id' => $entityId,
            'entity_name' => $card['entity_name'],
            'card_name' => $card['card_name'],
            'card_type' => CARD_SNEAK,
            'card_power' => $power,
            'effect' => 'sneak',
            'markers_placed' => $power,
        ];
    }

    /**
     * Place mark markers on target
     */
    private function placeMarkMarkers(array $card): array
    {
        $entityId = (int)$card['entity_id'];
        $targetId = $card['target_entity_id'] ? (int)$card['target_entity_id'] : null;
        $power = (int)($card['card_power'] ?? 1);

        $result = [
            'entity_id' => $entityId,
            'entity_name' => $card['entity_name'],
            'card_name' => $card['card_name'],
            'card_type' => CARD_MARK,
            'card_power' => $power,
            'target_id' => $targetId,
            'effect' => 'no_target',
        ];

        if ($targetId === null) return $result;

        $target = $this->game->getObjectFromDB(
            "SELECT entity_name, is_defeated FROM entity WHERE entity_id = $targetId"
        );
        if (!$target || $target['is_defeated'] == 1) {
            $result['effect'] = 'target_defeated';
            return $result;
        }

        $this->markers->addMarkers($targetId, MARKER_MARK, $power);

        $result['target_name'] = $target['entity_name'];
        $result['effect'] = 'mark';
        $result['markers_placed'] = $power;

        return $result;
    }

    /**
     * Place attack markers on target (includes sneak bonus)
     */
    private function placeAttackMarkers(array $card): array
    {
        $entityId = (int)$card['entity_id'];
        $targetId = $card['target_entity_id'] ? (int)$card['target_entity_id'] : null;
        $power = (int)($card['card_power'] ?? 1);

        $result = [
            'entity_id' => $entityId,
            'entity_name' => $card['entity_name'],
            'card_name' => $card['card_name'],
            'card_type' => CARD_ATTACK,
            'card_power' => $power,
            'target_id' => $targetId,
            'effect' => 'no_target',
        ];

        if ($targetId === null) return $result;

        // Check if hidden - consume 1 sneak marker, add remaining as bonus
        $sneakMarkers = $this->markers->getMarkerCount($entityId, MARKER_SNEAK);
        $sneakBonus = 0;
        if ($sneakMarkers > 0) {
            // Consume 1 for the action
            $this->markers->removeMarkers($entityId, MARKER_SNEAK, 1);
            // Remaining become bonus attack
            $sneakBonus = $sneakMarkers - 1;
            if ($sneakBonus > 0) {
                $this->markers->removeMarkers($entityId, MARKER_SNEAK, $sneakBonus);
            }
            $result['sneak_consumed'] = 1;
            $result['sneak_bonus'] = $sneakBonus;
        }

        // Check target still valid
        $target = $this->game->getObjectFromDB(
            "SELECT entity_name, is_defeated FROM entity WHERE entity_id = $targetId"
        );
        if (!$target || $target['is_defeated'] == 1) {
            $result['effect'] = 'target_defeated';
            return $result;
        }

        // Add mark bonus from target
        $markBonus = $this->markers->getMarkerCount($targetId, MARKER_MARK);

        // Total attack markers
        $totalAttack = $power + $sneakBonus + $markBonus;

        $this->markers->addMarkers($targetId, MARKER_ATTACK, $totalAttack, $entityId);

        $result['target_name'] = $target['entity_name'];
        $result['effect'] = 'attack';
        $result['mark_bonus'] = $markBonus;
        $result['total_attack'] = $totalAttack;
        $result['markers_placed'] = $totalAttack;

        return $result;
    }

    /**
     * Place defend markers on target (includes sneak bonus)
     */
    private function placeDefendMarkers(array $card): array
    {
        $entityId = (int)$card['entity_id'];
        $targetId = $card['target_entity_id'] ? (int)$card['target_entity_id'] : $entityId;
        $power = (int)($card['card_power'] ?? 1);

        $result = [
            'entity_id' => $entityId,
            'entity_name' => $card['entity_name'],
            'card_name' => $card['card_name'],
            'card_type' => CARD_DEFEND,
            'card_power' => $power,
            'target_id' => $targetId,
            'effect' => 'defend',
        ];

        // Check if hidden - consume 1 sneak marker, add remaining as bonus
        $sneakMarkers = $this->markers->getMarkerCount($entityId, MARKER_SNEAK);
        $sneakBonus = 0;
        if ($sneakMarkers > 0) {
            $this->markers->removeMarkers($entityId, MARKER_SNEAK, 1);
            $sneakBonus = $sneakMarkers - 1;
            if ($sneakBonus > 0) {
                $this->markers->removeMarkers($entityId, MARKER_SNEAK, $sneakBonus);
            }
            $result['sneak_consumed'] = 1;
            $result['sneak_bonus'] = $sneakBonus;
        }

        $target = $this->game->getObjectFromDB(
            "SELECT entity_name, is_defeated FROM entity WHERE entity_id = $targetId"
        );
        if (!$target || $target['is_defeated'] == 1) {
            $result['effect'] = 'target_defeated';
            return $result;
        }

        $totalDefend = $power + $sneakBonus;
        $this->markers->addMarkers($targetId, MARKER_DEFEND, $totalDefend);

        $result['target_name'] = $target['entity_name'];
        $result['total_defend'] = $totalDefend;
        $result['markers_placed'] = $totalDefend;

        return $result;
    }

    /**
     * Resolve combat: attack vs defend, deal damage
     */
    private function resolveCombat(int $sequenceId): array
    {
        $results = [];

        $participants = $this->game->getObjectListFromDB(
            "SELECT sp.entity_id, e.entity_name
             FROM sequence_participant sp
             JOIN entity e ON sp.entity_id = e.entity_id
             WHERE sp.sequence_id = $sequenceId AND e.is_defeated = 0"
        );

        foreach ($participants as $p) {
            $entityId = (int)$p['entity_id'];
            $attackMarkers = $this->markers->getMarkerCount($entityId, MARKER_ATTACK);
            $defendMarkers = $this->markers->getMarkerCount($entityId, MARKER_DEFEND);

            if ($attackMarkers === 0 && $defendMarkers === 0) continue;

            // Get the attacker's source BEFORE clearing markers
            $attackerId = $this->markers->getMarkerSource($entityId, MARKER_ATTACK);

            // Defend cancels attack
            $cancelled = min($attackMarkers, $defendMarkers);
            $damage = $attackMarkers - $cancelled;
            $remainingDefend = $defendMarkers - $cancelled;

            // Clear attack markers
            $this->markers->setMarkers($entityId, MARKER_ATTACK, 0);
            // Set remaining defend (carries over)
            $this->markers->setMarkers($entityId, MARKER_DEFEND, $remainingDefend);

            $result = [
                'entity_id' => $entityId,
                'entity_name' => $p['entity_name'],
                'effect' => 'combat_resolution',
                'attack_markers' => $attackMarkers,
                'defend_markers' => $defendMarkers,
                'cancelled' => $cancelled,
                'damage' => $damage,
                'defend_remaining' => $remainingDefend,
                'destroyed_cards' => [],
            ];

            // Deal damage
            if ($damage > 0) {
                $drawnCardId = $this->game->getUniqueValueFromDB(
                    "SELECT drawn_card_id FROM sequence_participant 
                     WHERE sequence_id = $sequenceId AND entity_id = $entityId"
                );
                $excludeCards = $drawnCardId ? [(int)$drawnCardId] : [];

                for ($i = 0; $i < $damage; $i++) {
                    $destroyed = $this->deck->destroyOneCard($entityId, $excludeCards);
                    if ($destroyed) {
                        $result['destroyed_cards'][] = $destroyed;
                        $excludeCards[] = (int)$destroyed['card_id'];
                    }
                }

                // Check if defeated
                if ($this->deck->isDefeated($entityId)) {
                    $this->game->DbQuery("UPDATE entity SET is_defeated = 1 WHERE entity_id = $entityId");
                    $result['defeated'] = true;

                    // Use the attacker ID we saved before clearing markers
                    if ($attackerId !== null) {
                        // Track killing blow for goals
                        $this->trackKillingBlow($attackerId, $entityId);
                        
                        // Transfer items from defeated entity to killer
                        $transferredItems = $this->transferItemsOnKill($attackerId, $entityId);
                        if (!empty($transferredItems)) {
                            $result['items_transferred'] = $transferredItems;
                            $result['killer_id'] = $attackerId;
                        }
                    }
                }
            }

            $results[] = $result;
        }

        return $results;
    }

    /**
     * Place poison markers on target
     */
    private function placePoisonMarkers(array $card): array
    {
        $entityId = (int)$card['entity_id'];
        $targetId = $card['target_entity_id'] ? (int)$card['target_entity_id'] : null;
        $power = (int)($card['card_power'] ?? 1);

        $result = [
            'entity_id' => $entityId,
            'entity_name' => $card['entity_name'],
            'card_name' => $card['card_name'],
            'card_type' => CARD_POISON,
            'card_power' => $power,
            'target_id' => $targetId,
            'effect' => 'no_target',
        ];

        if ($targetId === null) return $result;

        $target = $this->game->getObjectFromDB(
            "SELECT entity_name, is_defeated FROM entity WHERE entity_id = $targetId"
        );
        if (!$target || $target['is_defeated'] == 1) {
            $result['effect'] = 'target_defeated';
            return $result;
        }

        $this->markers->addMarkers($targetId, MARKER_POISON, $power);

        $result['target_name'] = $target['entity_name'];
        $result['effect'] = 'poison';
        $result['markers_placed'] = $power;

        return $result;
    }

    /**
     * Resolve heal card
     */
    private function resolveHeal(array $card): array
    {
        $entityId = (int)$card['entity_id'];
        $targetId = $card['target_entity_id'] ? (int)$card['target_entity_id'] : $entityId;
        $power = (int)($card['card_power'] ?? 1);

        $result = [
            'entity_id' => $entityId,
            'entity_name' => $card['entity_name'],
            'card_name' => $card['card_name'],
            'card_type' => CARD_HEAL,
            'card_power' => $power,
            'target_id' => $targetId,
            'effect' => 'heal',
            'poison_removed' => 0,
            'cards_restored' => [],
        ];

        $target = $this->game->getObjectFromDB(
            "SELECT entity_name, is_defeated FROM entity WHERE entity_id = $targetId"
        );
        if (!$target || $target['is_defeated'] == 1) {
            $result['effect'] = 'target_defeated';
            return $result;
        }

        $result['target_name'] = $target['entity_name'];
        $remainingHeal = $power;

        // First remove poison markers
        $poisonCount = $this->markers->getMarkerCount($targetId, MARKER_POISON);
        if ($poisonCount > 0 && $remainingHeal > 0) {
            $toRemove = min($poisonCount, $remainingHeal);
            $this->markers->removeMarkers($targetId, MARKER_POISON, $toRemove);
            $result['poison_removed'] = $toRemove;
            $remainingHeal -= $toRemove;
        }

        // Then restore cards
        while ($remainingHeal > 0) {
            $restored = $this->deck->healOne($targetId);
            if (!$restored) break;
            $result['cards_restored'][] = $restored;
            $remainingHeal--;
        }

        return $result;
    }

    /**
     * Resolve shuffle card
     */
    private function resolveShuffle(array $card): array
    {
        $entityId = (int)$card['entity_id'];
        $power = (int)($card['card_power'] ?? 1);

        $shuffled = $this->deck->shuffleFromDiscard($entityId, $power);

        return [
            'entity_id' => $entityId,
            'entity_name' => $card['entity_name'],
            'card_name' => $card['card_name'],
            'card_type' => CARD_SHUFFLE,
            'card_power' => $power,
            'effect' => 'shuffle',
            'cards_shuffled' => $shuffled,
        ];
    }

    /**
     * Place sell markers on self
     */
    private function placeSellMarkers(array $card): array
    {
        $entityId = (int)$card['entity_id'];
        $power = (int)($card['card_power'] ?? 1);

        $this->markers->addMarkers($entityId, MARKER_SELL, $power);

        return [
            'entity_id' => $entityId,
            'entity_name' => $card['entity_name'],
            'card_name' => $card['card_name'],
            'card_type' => CARD_SELL,
            'card_power' => $power,
            'effect' => 'selling',
            'minimum_price' => $power,
        ];
    }

    /**
     * Resolve wealth card (purchase from seller)
     */
    private function resolveWealth(int $sequenceId, array $card): array
    {
        $entityId = (int)$card['entity_id'];
        $targetId = $card['target_entity_id'] ? (int)$card['target_entity_id'] : null;
        $power = (int)($card['card_power'] ?? 1);

        $result = [
            'entity_id' => $entityId,
            'entity_name' => $card['entity_name'],
            'card_name' => $card['card_name'],
            'card_type' => CARD_WEALTH,
            'card_power' => $power,
            'target_id' => $targetId,
            'effect' => 'no_seller',
        ];

        if ($targetId === null) return $result;

        $sellMarkers = $this->markers->getMarkerCount($targetId, MARKER_SELL);
        if ($sellMarkers <= 0) {
            $result['effect'] = 'not_selling';
            return $result;
        }

        $target = $this->game->getObjectFromDB(
            "SELECT entity_name FROM entity WHERE entity_id = $targetId"
        );
        $result['target_name'] = $target ? $target['entity_name'] : 'Unknown';

        if ($power < $sellMarkers) {
            $result['effect'] = 'insufficient_wealth';
            $result['required'] = $sellMarkers;
            $result['offered'] = $power;
            return $result;
        }

        // Get an item from the seller
        $item = $this->game->getObjectFromDB(
            "SELECT item_id, item_name, item_type, item_data FROM item 
             WHERE entity_id = $targetId LIMIT 1"
        );

        if (!$item) {
            $result['effect'] = 'no_items';
            return $result;
        }

        // Transfer item
        $this->consumeItem($entityId, $item);
        $this->game->DbQuery("DELETE FROM item WHERE item_id = " . (int)$item['item_id']);

        // Clear sell markers
        $this->markers->setMarkers($targetId, MARKER_SELL, 0);

        $result['effect'] = 'purchased';
        $result['item'] = $item;

        return $result;
    }

    /**
     * Resolve steal card (cancelled by watch)
     */
    private function resolveSteal(array $card): array
    {
        $entityId = (int)$card['entity_id'];
        $targetId = $card['target_entity_id'] ? (int)$card['target_entity_id'] : null;
        $power = (int)($card['card_power'] ?? 1);

        $result = [
            'entity_id' => $entityId,
            'entity_name' => $card['entity_name'],
            'card_name' => $card['card_name'],
            'card_type' => CARD_STEAL,
            'card_power' => $power,
            'target_id' => $targetId,
            'effect' => 'no_target',
        ];

        if ($targetId === null) return $result;

        $target = $this->game->getObjectFromDB(
            "SELECT entity_name, faction FROM entity WHERE entity_id = $targetId"
        );
        if (!$target) return $result;

        $result['target_name'] = $target['entity_name'];

        // Check if target has watch markers (cancels steal 1:1)
        $watchMarkers = $this->markers->getMarkerCount($targetId, MARKER_WATCH);
        $cancelled = min($power, $watchMarkers);
        $effectiveSteal = $power - $cancelled;

        if ($cancelled > 0) {
            $this->markers->removeMarkers($targetId, MARKER_WATCH, $cancelled);
            $result['cancelled_by_watch'] = $cancelled;
        }

        if ($effectiveSteal <= 0) {
            $result['effect'] = 'caught';
            return $result;
        }

        // Steal items (1 per remaining steal marker)
        $stolenItems = [];
        for ($i = 0; $i < $effectiveSteal; $i++) {
            $item = $this->game->getObjectFromDB(
                "SELECT item_id, item_name, item_type, item_data FROM item 
                 WHERE entity_id = $targetId LIMIT 1"
            );
            if (!$item) break;

            $this->consumeItem($entityId, $item);
            $this->game->DbQuery("DELETE FROM item WHERE item_id = " . (int)$item['item_id']);
            $stolenItems[] = $item;
        }

        if (empty($stolenItems)) {
            $result['effect'] = 'no_items';
            return $result;
        }

        $result['effect'] = 'stolen';
        $result['items'] = $stolenItems;

        return $result;
    }

    /**
     * Consume an item - apply its effect
     */
    private function consumeItem(int $entityId, array $item): void
    {
        $itemType = $item['item_type'];
        $itemData = is_string($item['item_data']) ? json_decode($item['item_data'], true) : $item['item_data'];

        switch ($itemType) {
            case ITEM_NEW_ACTION:
                // Item data contains card fields directly: name, type, power
                // or could have a nested 'card' key for legacy support
                $cardData = isset($itemData['card']) ? $itemData['card'] : $itemData;
                $this->deck->addCardToInactive($entityId, $cardData);
                break;
        }
    }

    /**
     * Check if one faction has been eliminated
     */
    public function getEliminatedFaction(int $sequenceId): ?string
    {
        $participants = $this->game->getObjectListFromDB(
            "SELECT e.faction, e.is_defeated
             FROM sequence_participant sp
             JOIN entity e ON sp.entity_id = e.entity_id
             WHERE sp.sequence_id = $sequenceId"
        );

        $factionAlive = [];
        foreach ($participants as $p) {
            $faction = $p['faction'];
            if (!isset($factionAlive[$faction])) {
                $factionAlive[$faction] = 0;
            }
            if ($p['is_defeated'] == 0) {
                $factionAlive[$faction]++;
            }
        }

        foreach ($factionAlive as $faction => $alive) {
            if ($alive === 0) {
                return $faction;
            }
        }

        return null;
    }

    /**
     * Check if all participants are out of cards
     */
    public function isEveryoneOutOfCards(int $sequenceId): bool
    {
        $participants = $this->game->getObjectListFromDB(
            "SELECT sp.entity_id
             FROM sequence_participant sp
             JOIN entity e ON sp.entity_id = e.entity_id
             WHERE sp.sequence_id = $sequenceId AND e.is_defeated = 0"
        );

        foreach ($participants as $p) {
            if ($this->deck->hasActiveCards((int)$p['entity_id'])) {
                return false;
            }
        }

        return true;
    }

    /**
     * Get current participant status
     */
    public function getParticipantStatus(int $sequenceId): array
    {
        $participants = $this->game->getObjectListFromDB(
            "SELECT e.entity_id, e.entity_name, e.entity_type, e.faction, e.is_defeated
             FROM sequence_participant sp
             JOIN entity e ON sp.entity_id = e.entity_id
             WHERE sp.sequence_id = $sequenceId"
        );

        $status = [];
        foreach ($participants as $p) {
            $entityId = (int)$p['entity_id'];
            $counts = $this->deck->getPileCounts($entityId);
            $allMarkers = $this->markers->getAllMarkers($entityId);
            
            $status[] = [
                'entity_id' => $entityId,
                'entity_name' => $p['entity_name'],
                'entity_type' => $p['entity_type'],
                'faction' => $p['faction'],
                'is_defeated' => (bool)$p['is_defeated'],
                'active' => $counts['active'],
                'discard' => $counts['discard'],
                'inactive' => $counts['inactive'],
                'markers' => $allMarkers,
            ];
        }

        return $status;
    }

    /**
     * End of action sequence: apply poison damage, clear non-persistent markers
     */
    public function endSequence(int $sequenceId): array
    {
        $results = [];

        // Get location
        $sequence = $this->game->getObjectFromDB(
            "SELECT location_id FROM action_sequence WHERE sequence_id = $sequenceId"
        );
        $locationId = $sequence ? $sequence['location_id'] : '';

        // Apply poison damage (1 per poisoned entity)
        $poisonResults = $this->markers->applyPoisonDamage();
        foreach ($poisonResults as $pr) {
            $entityId = (int)$pr['entity_id'];
            
            // Deal damage
            $destroyed = $this->deck->destroyOneCard($entityId);
            if ($destroyed) {
                $pr['destroyed_card'] = $destroyed;
            }

            // Check if defeated
            if ($this->deck->isDefeated($entityId)) {
                $this->game->DbQuery("UPDATE entity SET is_defeated = 1 WHERE entity_id = $entityId");
                $pr['defeated'] = true;

                // Get the poisoner from poison marker source
                $killerId = $this->markers->getMarkerSource($entityId, MARKER_POISON);
                if ($killerId !== null) {
                    // Track killing blow for goals
                    $this->trackKillingBlow($killerId, $entityId);
                    
                    // Transfer items from defeated entity to killer
                    $transferredItems = $this->transferItemsOnKill($killerId, $entityId);
                    if (!empty($transferredItems)) {
                        $pr['items_transferred'] = $transferredItems;
                        $pr['killer_id'] = $killerId;
                    }
                }
            }

            $results[] = $pr;
        }

        // Clear non-persistent markers (keeps sneak, watch, poison)
        $this->markers->clearNonPersistentMarkersAtLocation($locationId);

        // Mark sequence as resolved
        $this->game->DbQuery("UPDATE action_sequence SET is_resolved = 1 WHERE sequence_id = $sequenceId");
        $this->game->DbQuery(
            "UPDATE sequence_participant 
             SET drawn_card_id = NULL, target_entity_id = NULL, block_count = 0, is_resolved = 0 
             WHERE sequence_id = $sequenceId"
        );

        return $results;
    }

    /**
     * Reset sequence round for next iteration
     */
    public function resetSequenceRound(int $sequenceId): void
    {
        $this->game->DbQuery(
            "UPDATE sequence_participant 
             SET drawn_card_id = NULL, target_entity_id = NULL, block_count = 0, is_resolved = 0 
             WHERE sequence_id = $sequenceId"
        );
    }

    /**
     * Transfer items from killed entity to killer (legacy support)
     */
    public function transferItemsOnKill(int $killerId, int $victimId): array
    {
        $items = $this->game->getObjectListFromDB(
            "SELECT item_id, item_name, item_type, item_data FROM item 
             WHERE entity_id = $victimId"
        );

        $transferred = [];
        foreach ($items as $item) {
            $this->consumeItem($killerId, $item);
            $this->game->DbQuery("DELETE FROM item WHERE item_id = " . (int)$item['item_id']);
            $transferred[] = $item;
        }

        return $transferred;
    }
}
