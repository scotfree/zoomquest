<?php

declare(strict_types=1);

namespace Bga\Games\Zoomquest\Helpers;

require_once(dirname(__DIR__) . '/constants.inc.php');

/**
 * Handles action sequence resolution (generalized combat)
 * 
 * Each round:
 * 1. All entities draw cards simultaneously
 * 2. Resolve simultaneously:
 *    - Watch: Reveals hidden enemies, counters Sneak
 *    - Sneak: Grants hidden if not Watched
 *    - Block: Grants blocked to target (stacks)
 *    - Attack: Destroys card if target visible and not blocked
 *    - Heal: Restores card from destroyed to discard
 * 3. End of round: hidden expires
 */
class ActionSequenceResolver
{
    private $game;
    private Deck $deck;
    private array $factionMatrix = [];

    public function __construct($game, Deck $deck)
    {
        $this->game = $game;
        $this->deck = $deck;
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
            // Get victim's faction for faction-specific tracking
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
            "SELECT entity_id, entity_type, entity_name, entity_class, faction 
             FROM entity 
             WHERE location_id = '$locationId' AND is_defeated = 0"
        );
    }

    /**
     * Check if an action sequence should occur at a location
     * Sequences happen wherever there are players
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
            $entityId = $entity['entity_id'];
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
     * Check if entity has a specific tag
     */
    public function hasTag(int $entityId, string $tagName): bool
    {
        $count = (int)$this->game->getUniqueValueFromDB(
            "SELECT COUNT(*) FROM entity_tag 
             WHERE entity_id = $entityId AND tag_name = '$tagName'"
        );
        return $count > 0;
    }

    /**
     * Get tag value (e.g., block count)
     */
    public function getTagValue(int $entityId, string $tagName): int
    {
        $value = $this->game->getUniqueValueFromDB(
            "SELECT tag_value FROM entity_tag 
             WHERE entity_id = $entityId AND tag_name = '$tagName'"
        );
        return $value !== null ? (int)$value : 0;
    }

    /**
     * Set a tag on an entity
     */
    public function setTag(int $entityId, string $tagName, int $value = 1, int $round = 0): void
    {
        $this->game->DbQuery(
            "INSERT INTO entity_tag (entity_id, tag_name, tag_value, round_applied) 
             VALUES ($entityId, '$tagName', $value, $round)
             ON DUPLICATE KEY UPDATE tag_value = $value, round_applied = $round"
        );
    }

    /**
     * Add to a tag value (for stacking like blocked)
     */
    public function addToTag(int $entityId, string $tagName, int $amount = 1, int $round = 0): void
    {
        $current = $this->getTagValue($entityId, $tagName);
        $this->setTag($entityId, $tagName, $current + $amount, $round);
    }

    /**
     * Remove a tag
     */
    public function removeTag(int $entityId, string $tagName): void
    {
        $this->game->DbQuery(
            "DELETE FROM entity_tag WHERE entity_id = $entityId AND tag_name = '$tagName'"
        );
    }

    /**
     * Clear expired tags (called at end of round)
     */
    public function clearExpiredTags(int $currentRound): void
    {
        // Hidden expires after 1 round
        $this->game->DbQuery(
            "DELETE FROM entity_tag WHERE tag_name = '" . TAG_HIDDEN . "' AND round_applied < $currentRound"
        );
        
        // Poisoned expires after 3 rounds
        $this->game->DbQuery(
            "DELETE FROM entity_tag WHERE tag_name = '" . TAG_POISONED . "' AND round_applied < ($currentRound - 2)"
        );
        
        // Marked expires after 2 rounds
        $this->game->DbQuery(
            "DELETE FROM entity_tag WHERE tag_name = '" . TAG_MARKED . "' AND round_applied < ($currentRound - 1)"
        );
    }

    /**
     * Get all tags for an entity
     */
    public function getTags(int $entityId): array
    {
        return $this->game->getObjectListFromDB(
            "SELECT tag_name, tag_value FROM entity_tag WHERE entity_id = $entityId"
        );
    }

    /**
     * Get lowest health target with specific relationship to actor
     */
    private function getLowestHealthTarget(int $sequenceId, int $actorEntityId, string $relationship, bool $includeHidden = true): ?array
    {
        // Get actor's faction
        $actor = $this->game->getObjectFromDB(
            "SELECT faction FROM entity WHERE entity_id = $actorEntityId"
        );
        $actorFaction = $actor['faction'];

        // Get all non-defeated participants
        $participants = $this->game->getObjectListFromDB(
            "SELECT e.entity_id, e.entity_name, e.faction
             FROM sequence_participant sp
             JOIN entity e ON sp.entity_id = e.entity_id
             WHERE sp.sequence_id = $sequenceId AND e.is_defeated = 0"
        );

        $candidates = [];
        foreach ($participants as $p) {
            // Check relationship
            $rel = $this->getRelationship($actorFaction, $p['faction']);
            if ($rel !== $relationship) {
                continue;
            }

            // Skip hidden entities if not including them
            if (!$includeHidden && $this->hasTag((int)$p['entity_id'], TAG_HIDDEN)) {
                continue;
            }

            $health = $this->getEntityHealth((int)$p['entity_id']);
            $p['health'] = $health;
            $candidates[] = $p;
        }

        if (empty($candidates)) {
            return null;
        }

        // Find lowest health
        usort($candidates, fn($a, $b) => $a['health'] <=> $b['health']);
        $lowestHealth = $candidates[0]['health'];
        
        // Get all with lowest health for random selection
        $ties = array_filter($candidates, fn($c) => $c['health'] === $lowestHealth);
        shuffle($ties);

        return $ties[0] ?? null;
    }

    /**
     * Draw cards for all participants and assign targets
     */
    public function drawCardsForSequence(int $sequenceId, int $currentRound): array
    {
        // Clear hidden from previous round
        $this->clearExpiredTags($currentRound);

        // Reset block counts at start of round
        $this->game->DbQuery(
            "UPDATE sequence_participant SET block_count = 0 WHERE sequence_id = $sequenceId"
        );

        // Get non-defeated participants
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

                // Determine target based on card type and faction
                $targetId = $this->determineTarget($sequenceId, $entityId, $cardType);

                $this->game->DbQuery(
                    "UPDATE sequence_participant 
                     SET drawn_card_id = $cardId, 
                         target_entity_id = " . ($targetId !== null ? $targetId : "NULL") . ",
                         is_resolved = 0 
                     WHERE sequence_id = $sequenceId AND entity_id = $entityId"
                );

                // Get target name
                $targetName = null;
                if ($targetId !== null) {
                    $target = $this->game->getObjectFromDB(
                        "SELECT entity_name FROM entity WHERE entity_id = $targetId"
                    );
                    $targetName = $target ? $target['entity_name'] : null;
                }

                // Track card play for goals (sneak, poison, etc.)
                $this->trackGoalForEntity($entityId, TRACK_CARD_PLAYS, $cardType);

                $drawnCards[] = [
                    'entity_id' => $entityId,
                    'entity_type' => $p['entity_type'],
                    'entity_name' => $p['entity_name'],
                    'faction' => $p['faction'],
                    'card_id' => $cardId,
                    'card_type' => $cardType,
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
                // Target lowest health friendly (including self)
                $target = $this->getLowestHealthTarget($sequenceId, $entityId, RELATION_FRIENDLY, true);
                return $target ? (int)$target['entity_id'] : null;

            case CARD_ATTACK:
            case CARD_BACKSTAB:
            case CARD_EXECUTE:
                // Target lowest health hostile (not hidden)
                $target = $this->getLowestHealthTarget($sequenceId, $entityId, RELATION_HOSTILE, false);
                return $target ? (int)$target['entity_id'] : null;

            case CARD_POISON:
            case CARD_MARK:
                // Target lowest health hostile (can target hidden - poison/mark are area effects)
                $target = $this->getLowestHealthTarget($sequenceId, $entityId, RELATION_HOSTILE, true);
                return $target ? (int)$target['entity_id'] : null;

            case CARD_STEAL:
            case CARD_WEALTH:
                // Target a neutral entity with items
                $target = $this->getNeutralWithItems($sequenceId, $entityId);
                return $target ? (int)$target['entity_id'] : null;

            case CARD_SELL:
                // Sell targets self (just indicates willingness to sell)
                return $entityId;

            case CARD_SNEAK:
            case CARD_WATCH:
            case CARD_SHUFFLE:
                // Self-targeting
                return $entityId;

            default:
                return null;
        }
    }

    /**
     * Find a neutral entity with items at the same location
     */
    private function getNeutralWithItems(int $sequenceId, int $actorEntityId): ?array
    {
        // Get actor's faction
        $actor = $this->game->getObjectFromDB(
            "SELECT faction FROM entity WHERE entity_id = $actorEntityId"
        );
        $actorFaction = $actor['faction'];

        // Get all non-defeated participants with items
        $participants = $this->game->getObjectListFromDB(
            "SELECT e.entity_id, e.entity_name, e.faction, 
                    (SELECT COUNT(*) FROM item i WHERE i.entity_id = e.entity_id) as item_count
             FROM sequence_participant sp
             JOIN entity e ON sp.entity_id = e.entity_id
             WHERE sp.sequence_id = $sequenceId AND e.is_defeated = 0"
        );

        $candidates = [];
        foreach ($participants as $p) {
            // Check relationship is neutral
            $rel = $this->getRelationship($actorFaction, $p['faction']);
            if ($rel !== RELATION_NEUTRAL) {
                continue;
            }

            // Check they have items
            if ((int)$p['item_count'] === 0) {
                continue;
            }

            $candidates[] = $p;
        }

        if (empty($candidates)) {
            return null;
        }

        // Return random from candidates
        shuffle($candidates);
        return $candidates[0];
    }

    /**
     * Resolve all cards for this round simultaneously
     * Returns array of resolution results
     */
    public function resolveRound(int $sequenceId, int $currentRound): array
    {
        $results = [];

        // Get all drawn cards
        $cards = $this->game->getObjectListFromDB(
            "SELECT sp.entity_id, sp.drawn_card_id, sp.target_entity_id,
                    e.entity_type, e.entity_name, e.faction, c.card_type
             FROM sequence_participant sp
             JOIN entity e ON sp.entity_id = e.entity_id
             JOIN card c ON sp.drawn_card_id = c.card_id
             WHERE sp.sequence_id = $sequenceId AND sp.drawn_card_id IS NOT NULL"
        );

        // Group by card type for phased resolution
        $byType = [];
        foreach ($cards as $card) {
            $type = $card['card_type'];
            if (!isset($byType[$type])) {
                $byType[$type] = [];
            }
            $byType[$type][] = $card;
        }

        // Track who was Watched this round (to counter Sneak)
        $watchedLocations = [];

        // Phase 1: Watch - reveals hidden, marks locations as watched
        if (isset($byType[CARD_WATCH])) {
            foreach ($byType[CARD_WATCH] as $card) {
                $result = $this->resolveWatch($sequenceId, $card, $currentRound);
                $results[] = $result;
                
                // Mark this entity's location as watched
                $location = $this->game->getUniqueValueFromDB(
                    "SELECT location_id FROM entity WHERE entity_id = " . $card['entity_id']
                );
                $watchedLocations[$location] = true;
            }
        }

        // Phase 2: Sneak - grants hidden if not at watched location
        if (isset($byType[CARD_SNEAK])) {
            foreach ($byType[CARD_SNEAK] as $card) {
                $result = $this->resolveSneak($sequenceId, $card, $watchedLocations, $currentRound);
                $results[] = $result;
            }
        }

        // Phase 3: Poison - applies poisoned debuff (3 rounds)
        if (isset($byType[CARD_POISON])) {
            foreach ($byType[CARD_POISON] as $card) {
                $result = $this->resolvePoison($sequenceId, $card, $currentRound);
                $results[] = $result;
            }
        }

        // Phase 4: Mark - applies marked debuff (2 rounds, +1 damage taken)
        if (isset($byType[CARD_MARK])) {
            foreach ($byType[CARD_MARK] as $card) {
                $result = $this->resolveMark($sequenceId, $card, $currentRound);
                $results[] = $result;
            }
        }

        // Phase 5: Defend/Block - grants blocked to target
        if (isset($byType[CARD_DEFEND])) {
            foreach ($byType[CARD_DEFEND] as $card) {
                $result = $this->resolveDefend($sequenceId, $card);
                $results[] = $result;
            }
        }

        // Phase 6: Backstab - deals 3 damage but only if attacker is hidden
        if (isset($byType[CARD_BACKSTAB])) {
            shuffle($byType[CARD_BACKSTAB]);
            foreach ($byType[CARD_BACKSTAB] as $card) {
                $result = $this->resolveBackstab($sequenceId, $card);
                $results[] = $result;
            }
        }

        // Phase 7: Execute - deals 3 damage but only if target is poisoned
        if (isset($byType[CARD_EXECUTE])) {
            shuffle($byType[CARD_EXECUTE]);
            foreach ($byType[CARD_EXECUTE] as $card) {
                $result = $this->resolveExecute($sequenceId, $card);
                $results[] = $result;
            }
        }

        // Phase 8: Attack - destroys cards if target visible and not blocked
        if (isset($byType[CARD_ATTACK])) {
            shuffle($byType[CARD_ATTACK]); // Random order for ties
            foreach ($byType[CARD_ATTACK] as $card) {
                $result = $this->resolveAttack($sequenceId, $card);
                $results[] = $result;
            }
        }

        // Phase 9: Heal - restores cards
        if (isset($byType[CARD_HEAL])) {
            foreach ($byType[CARD_HEAL] as $card) {
                $result = $this->resolveHeal($sequenceId, $card);
                $results[] = $result;
            }
        }

        // Phase 10: Shuffle - shuffles own active deck
        if (isset($byType[CARD_SHUFFLE])) {
            foreach ($byType[CARD_SHUFFLE] as $card) {
                $result = $this->resolveShuffle($sequenceId, $card);
                $results[] = $result;
            }
        }

        // Phase 11: Sell - marks entity as selling this round (done first to enable wealth)
        // (Sell is just a marker, the actual sale happens when Wealth resolves)
        if (isset($byType[CARD_SELL])) {
            foreach ($byType[CARD_SELL] as $card) {
                $result = $this->resolveSell($sequenceId, $card);
                $results[] = $result;
            }
        }

        // Phase 12: Wealth - buys an item from a selling neutral entity
        if (isset($byType[CARD_WEALTH])) {
            $sellersThisRound = isset($byType[CARD_SELL]) ? 
                array_map(fn($c) => (int)$c['entity_id'], $byType[CARD_SELL]) : [];
            
            foreach ($byType[CARD_WEALTH] as $card) {
                $result = $this->resolveWealth($sequenceId, $card, $sellersThisRound);
                $results[] = $result;
            }
        }

        // Phase 13: Steal - steals an item from a neutral entity (countered by watch)
        if (isset($byType[CARD_STEAL])) {
            foreach ($byType[CARD_STEAL] as $card) {
                $result = $this->resolveSteal($sequenceId, $card, $watchedLocations);
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
     * Resolve Watch card
     */
    private function resolveWatch(int $sequenceId, array $card, int $currentRound): array
    {
        $entityId = (int)$card['entity_id'];
        $entityFaction = $card['faction'];

        $result = [
            'entity_id' => $entityId,
            'entity_name' => $card['entity_name'],
            'entity_type' => $card['entity_type'],
            'card_id' => (int)$card['drawn_card_id'],
            'card_type' => CARD_WATCH,
            'effect' => 'watch',
            'revealed' => [],
        ];

        // Get all hidden hostile entities at this location
        $location = $this->game->getUniqueValueFromDB(
            "SELECT location_id FROM entity WHERE entity_id = $entityId"
        );

        $entities = $this->getEntitiesAtLocation($location);
        foreach ($entities as $e) {
            $eId = (int)$e['entity_id'];
            if ($eId === $entityId) continue;

            // Check if hostile
            $rel = $this->getRelationship($entityFaction, $e['faction']);
            if ($rel !== RELATION_HOSTILE) continue;

            // Check if hidden
            if ($this->hasTag($eId, TAG_HIDDEN)) {
                $this->removeTag($eId, TAG_HIDDEN);
                $result['revealed'][] = [
                    'entity_id' => $eId,
                    'entity_name' => $e['entity_name'],
                ];
            }
        }

        return $result;
    }

    /**
     * Resolve Sneak card
     */
    private function resolveSneak(int $sequenceId, array $card, array $watchedLocations, int $currentRound): array
    {
        $entityId = (int)$card['entity_id'];

        $result = [
            'entity_id' => $entityId,
            'entity_name' => $card['entity_name'],
            'entity_type' => $card['entity_type'],
            'card_id' => (int)$card['drawn_card_id'],
            'card_type' => CARD_SNEAK,
            'effect' => 'sneak',
        ];

        // Check if at a watched location
        $location = $this->game->getUniqueValueFromDB(
            "SELECT location_id FROM entity WHERE entity_id = $entityId"
        );

        if (isset($watchedLocations[$location])) {
            // Sneak fails - someone is watching!
            $result['effect'] = 'sneak_failed';
            $result['reason'] = 'watched';
        } else {
            // Sneak succeeds - become hidden
            $this->setTag($entityId, TAG_HIDDEN, 1, $currentRound);
            $result['effect'] = 'hidden';
        }

        return $result;
    }

    /**
     * Resolve Poison card - applies poisoned debuff for 3 rounds
     * Poisoned entities take 1 damage at end of each round
     */
    private function resolvePoison(int $sequenceId, array $card, int $currentRound): array
    {
        $entityId = (int)$card['entity_id'];
        $targetId = $card['target_entity_id'] ? (int)$card['target_entity_id'] : null;

        $result = [
            'entity_id' => $entityId,
            'entity_name' => $card['entity_name'],
            'entity_type' => $card['entity_type'],
            'card_id' => (int)$card['drawn_card_id'],
            'card_type' => CARD_POISON,
            'target_id' => $targetId,
            'effect' => 'no_target',
        ];

        if ($targetId === null) {
            return $result;
        }

        // Check target is still alive
        $target = $this->game->getObjectFromDB(
            "SELECT entity_name, is_defeated FROM entity WHERE entity_id = $targetId"
        );

        if (!$target || $target['is_defeated'] == 1) {
            $result['effect'] = 'target_defeated';
            return $result;
        }

        // Apply poisoned tag for 3 rounds
        $this->setTag($targetId, TAG_POISONED, 3, $currentRound);
        
        $result['target_name'] = $target['entity_name'];
        $result['effect'] = 'poison';
        $result['duration'] = 3;

        return $result;
    }

    /**
     * Resolve Mark card - applies marked debuff for 2 rounds
     * Marked entities take +1 damage from attacks
     */
    private function resolveMark(int $sequenceId, array $card, int $currentRound): array
    {
        $entityId = (int)$card['entity_id'];
        $targetId = $card['target_entity_id'] ? (int)$card['target_entity_id'] : null;

        $result = [
            'entity_id' => $entityId,
            'entity_name' => $card['entity_name'],
            'entity_type' => $card['entity_type'],
            'card_id' => (int)$card['drawn_card_id'],
            'card_type' => CARD_MARK,
            'target_id' => $targetId,
            'effect' => 'no_target',
        ];

        if ($targetId === null) {
            return $result;
        }

        // Check target is still alive
        $target = $this->game->getObjectFromDB(
            "SELECT entity_name, is_defeated FROM entity WHERE entity_id = $targetId"
        );

        if (!$target || $target['is_defeated'] == 1) {
            $result['effect'] = 'target_defeated';
            return $result;
        }

        // Apply marked tag for 2 rounds
        $this->setTag($targetId, TAG_MARKED, 2, $currentRound);
        
        $result['target_name'] = $target['entity_name'];
        $result['effect'] = 'mark';
        $result['duration'] = 2;

        return $result;
    }

    /**
     * Resolve Defend card
     */
    private function resolveDefend(int $sequenceId, array $card): array
    {
        $entityId = (int)$card['entity_id'];
        $targetId = $card['target_entity_id'] ? (int)$card['target_entity_id'] : null;

        $result = [
            'entity_id' => $entityId,
            'entity_name' => $card['entity_name'],
            'entity_type' => $card['entity_type'],
            'card_id' => (int)$card['drawn_card_id'],
            'card_type' => CARD_DEFEND,
            'target_id' => $targetId,
            'effect' => 'no_target',
        ];

        if ($targetId === null) {
            return $result;
        }

        // Check target is still alive
        $target = $this->game->getObjectFromDB(
            "SELECT entity_name, is_defeated FROM entity WHERE entity_id = $targetId"
        );

        if (!$target || $target['is_defeated'] == 1) {
            $result['effect'] = 'target_defeated';
            return $result;
        }

        // Add block using participant table for this sequence
        $this->game->DbQuery(
            "UPDATE sequence_participant SET block_count = block_count + 1 
             WHERE sequence_id = $sequenceId AND entity_id = $targetId"
        );

        $result['target_name'] = $target['entity_name'];
        $result['effect'] = 'block';

        // Track blocks for allies (when target is not self)
        if ($targetId !== $entityId) {
            $playerId = $this->getPlayerIdForEntity($entityId);
            if ($playerId !== null) {
                $this->game->getGoalTracker()->trackBlockForAlly($playerId);
            }
        }

        // Get new block count
        $blockCount = (int)$this->game->getUniqueValueFromDB(
            "SELECT block_count FROM sequence_participant 
             WHERE sequence_id = $sequenceId AND entity_id = $targetId"
        );
        $result['block_count'] = $blockCount;

        return $result;
    }

    /**
     * Resolve Backstab card - deals 3 damage but only if attacker is hidden
     */
    private function resolveBackstab(int $sequenceId, array $card): array
    {
        $entityId = (int)$card['entity_id'];
        $targetId = $card['target_entity_id'] ? (int)$card['target_entity_id'] : null;

        $result = [
            'entity_id' => $entityId,
            'entity_name' => $card['entity_name'],
            'entity_type' => $card['entity_type'],
            'card_id' => (int)$card['drawn_card_id'],
            'card_type' => CARD_BACKSTAB,
            'target_id' => $targetId,
            'effect' => 'no_target',
        ];

        // Backstab requires being hidden
        if (!$this->hasTag($entityId, TAG_HIDDEN)) {
            $result['effect'] = 'not_hidden';
            $result['reason'] = 'must be hidden to backstab';
            return $result;
        }

        if ($targetId === null) {
            return $result;
        }

        // Check target is still alive
        $target = $this->game->getObjectFromDB(
            "SELECT entity_name, is_defeated FROM entity WHERE entity_id = $targetId"
        );

        if (!$target || $target['is_defeated'] == 1) {
            $result['effect'] = 'target_defeated';
            $result['target_name'] = $target ? $target['entity_name'] : 'Unknown';
            return $result;
        }

        $result['target_name'] = $target['entity_name'];

        // Check for blocks (backstab can be blocked)
        $blockCount = (int)$this->game->getUniqueValueFromDB(
            "SELECT block_count FROM sequence_participant 
             WHERE sequence_id = $sequenceId AND entity_id = $targetId"
        );

        // Calculate damage (3 base, +1 if marked)
        $damage = 3;
        if ($this->hasTag($targetId, TAG_MARKED)) {
            $damage++;
            $result['marked_bonus'] = true;
        }

        // Apply blocks
        $blocksUsed = min($blockCount, $damage);
        $damageDealt = $damage - $blocksUsed;

        if ($blocksUsed > 0) {
            $this->game->DbQuery(
                "UPDATE sequence_participant SET block_count = block_count - $blocksUsed 
                 WHERE sequence_id = $sequenceId AND entity_id = $targetId"
            );
            $result['blocks_used'] = $blocksUsed;
            $result['blocks_remaining'] = $blockCount - $blocksUsed;
        }

        if ($damageDealt === 0) {
            $result['effect'] = 'blocked';
            return $result;
        }

        // Deal damage - destroy multiple cards
        $result['effect'] = 'backstab';
        $result['damage'] = $damageDealt;
        $result['destroyed_cards'] = [];

        $targetDrawnCardId = $this->game->getUniqueValueFromDB(
            "SELECT drawn_card_id FROM sequence_participant 
             WHERE sequence_id = $sequenceId AND entity_id = $targetId"
        );
        $excludeCards = $targetDrawnCardId ? [(int)$targetDrawnCardId] : [];

        for ($i = 0; $i < $damageDealt; $i++) {
            $destroyedCard = $this->deck->destroyOneCard($targetId, $excludeCards);
            if ($destroyedCard) {
                $result['destroyed_cards'][] = $destroyedCard;
                $excludeCards[] = (int)$destroyedCard['card_id'];
            } else {
                break;
            }
        }

        // Check if target is now defeated
        if ($this->deck->isDefeated($targetId)) {
            $this->game->DbQuery("UPDATE entity SET is_defeated = 1 WHERE entity_id = $targetId");
            $result['target_defeated'] = true;
            
            // Track killing blow for goals
            $this->trackKillingBlow($entityId, $targetId);
            
            // Transfer items from killed entity
            $itemsLooted = $this->transferItemsOnKill($entityId, $targetId);
            if (!empty($itemsLooted)) {
                $result['items_looted'] = $itemsLooted;
            }
        }

        return $result;
    }

    /**
     * Resolve Execute card - deals 3 damage but only if target is poisoned
     */
    private function resolveExecute(int $sequenceId, array $card): array
    {
        $entityId = (int)$card['entity_id'];
        $targetId = $card['target_entity_id'] ? (int)$card['target_entity_id'] : null;

        $result = [
            'entity_id' => $entityId,
            'entity_name' => $card['entity_name'],
            'entity_type' => $card['entity_type'],
            'card_id' => (int)$card['drawn_card_id'],
            'card_type' => CARD_EXECUTE,
            'target_id' => $targetId,
            'effect' => 'no_target',
        ];

        if ($targetId === null) {
            return $result;
        }

        // Check target is still alive
        $target = $this->game->getObjectFromDB(
            "SELECT entity_name, is_defeated FROM entity WHERE entity_id = $targetId"
        );

        if (!$target || $target['is_defeated'] == 1) {
            $result['effect'] = 'target_defeated';
            $result['target_name'] = $target ? $target['entity_name'] : 'Unknown';
            return $result;
        }

        $result['target_name'] = $target['entity_name'];

        // Execute requires target to be poisoned
        if (!$this->hasTag($targetId, TAG_POISONED)) {
            $result['effect'] = 'not_poisoned';
            $result['reason'] = 'target must be poisoned';
            return $result;
        }

        // Check for blocks
        $blockCount = (int)$this->game->getUniqueValueFromDB(
            "SELECT block_count FROM sequence_participant 
             WHERE sequence_id = $sequenceId AND entity_id = $targetId"
        );

        // Calculate damage (3 base, +1 if marked)
        $damage = 3;
        if ($this->hasTag($targetId, TAG_MARKED)) {
            $damage++;
            $result['marked_bonus'] = true;
        }

        // Apply blocks
        $blocksUsed = min($blockCount, $damage);
        $damageDealt = $damage - $blocksUsed;

        if ($blocksUsed > 0) {
            $this->game->DbQuery(
                "UPDATE sequence_participant SET block_count = block_count - $blocksUsed 
                 WHERE sequence_id = $sequenceId AND entity_id = $targetId"
            );
            $result['blocks_used'] = $blocksUsed;
            $result['blocks_remaining'] = $blockCount - $blocksUsed;
        }

        if ($damageDealt === 0) {
            $result['effect'] = 'blocked';
            return $result;
        }

        // Deal damage - destroy multiple cards
        $result['effect'] = 'execute';
        $result['damage'] = $damageDealt;
        $result['destroyed_cards'] = [];

        $targetDrawnCardId = $this->game->getUniqueValueFromDB(
            "SELECT drawn_card_id FROM sequence_participant 
             WHERE sequence_id = $sequenceId AND entity_id = $targetId"
        );
        $excludeCards = $targetDrawnCardId ? [(int)$targetDrawnCardId] : [];

        for ($i = 0; $i < $damageDealt; $i++) {
            $destroyedCard = $this->deck->destroyOneCard($targetId, $excludeCards);
            if ($destroyedCard) {
                $result['destroyed_cards'][] = $destroyedCard;
                $excludeCards[] = (int)$destroyedCard['card_id'];
            } else {
                break;
            }
        }

        // Check if target is now defeated
        if ($this->deck->isDefeated($targetId)) {
            $this->game->DbQuery("UPDATE entity SET is_defeated = 1 WHERE entity_id = $targetId");
            $result['target_defeated'] = true;
            
            // Track killing blow for goals
            $this->trackKillingBlow($entityId, $targetId);
            
            // Transfer items from killed entity
            $itemsLooted = $this->transferItemsOnKill($entityId, $targetId);
            if (!empty($itemsLooted)) {
                $result['items_looted'] = $itemsLooted;
            }
        }

        return $result;
    }

    /**
     * Resolve Attack card
     */
    private function resolveAttack(int $sequenceId, array $card): array
    {
        $entityId = (int)$card['entity_id'];
        $targetId = $card['target_entity_id'] ? (int)$card['target_entity_id'] : null;

        $result = [
            'entity_id' => $entityId,
            'entity_name' => $card['entity_name'],
            'entity_type' => $card['entity_type'],
            'card_id' => (int)$card['drawn_card_id'],
            'card_type' => CARD_ATTACK,
            'target_id' => $targetId,
            'effect' => 'no_target',
        ];

        if ($targetId === null) {
            return $result;
        }

        // Check target is still alive
        $target = $this->game->getObjectFromDB(
            "SELECT entity_name, is_defeated FROM entity WHERE entity_id = $targetId"
        );

        if (!$target || $target['is_defeated'] == 1) {
            $result['effect'] = 'target_defeated';
            $result['target_name'] = $target ? $target['entity_name'] : 'Unknown';
            return $result;
        }

        $result['target_name'] = $target['entity_name'];

        // Check if target is hidden
        if ($this->hasTag($targetId, TAG_HIDDEN)) {
            $result['effect'] = 'target_hidden';
            return $result;
        }

        // Check for blocks
        $blockCount = (int)$this->game->getUniqueValueFromDB(
            "SELECT block_count FROM sequence_participant 
             WHERE sequence_id = $sequenceId AND entity_id = $targetId"
        );

        // Calculate damage (1 base, +1 if marked)
        $damage = 1;
        if ($this->hasTag($targetId, TAG_MARKED)) {
            $damage++;
            $result['marked_bonus'] = true;
        }

        // Apply blocks
        $blocksUsed = min($blockCount, $damage);
        $damageDealt = $damage - $blocksUsed;

        if ($blocksUsed > 0) {
            $this->game->DbQuery(
                "UPDATE sequence_participant SET block_count = block_count - $blocksUsed 
                 WHERE sequence_id = $sequenceId AND entity_id = $targetId"
            );
            $result['blocks_used'] = $blocksUsed;
            $result['blocks_remaining'] = $blockCount - $blocksUsed;
        }

        if ($damageDealt === 0) {
            $result['effect'] = 'blocked';
            return $result;
        }

        // Deal damage - destroy cards
        $targetDrawnCardId = $this->game->getUniqueValueFromDB(
            "SELECT drawn_card_id FROM sequence_participant 
             WHERE sequence_id = $sequenceId AND entity_id = $targetId"
        );
        $excludeCards = $targetDrawnCardId ? [(int)$targetDrawnCardId] : [];
        
        $result['effect'] = 'destroy';
        $result['damage'] = $damageDealt;
        $result['destroyed_cards'] = [];

        for ($i = 0; $i < $damageDealt; $i++) {
            $destroyedCard = $this->deck->destroyOneCard($targetId, $excludeCards);
            if ($destroyedCard) {
                $result['destroyed_cards'][] = $destroyedCard;
                $excludeCards[] = (int)$destroyedCard['card_id'];
            } else {
                break;
            }
        }

        // For backwards compatibility, keep single card info
        if (!empty($result['destroyed_cards'])) {
            $result['destroyed_card'] = $result['destroyed_cards'][0];
            $result['from_pile'] = $result['destroyed_cards'][0]['from_pile'] ?? 'active';
        }

        // Check if target is now defeated
        if ($this->deck->isDefeated($targetId)) {
            $this->game->DbQuery("UPDATE entity SET is_defeated = 1 WHERE entity_id = $targetId");
            $result['target_defeated'] = true;
            
            // Track killing blow for goals
            $this->trackKillingBlow($entityId, $targetId);
            
            // Transfer items from killed entity
            $itemsLooted = $this->transferItemsOnKill($entityId, $targetId);
            if (!empty($itemsLooted)) {
                $result['items_looted'] = $itemsLooted;
            }
        }

        return $result;
    }

    /**
     * Resolve Heal card
     */
    private function resolveHeal(int $sequenceId, array $card): array
    {
        $entityId = (int)$card['entity_id'];
        $targetId = $card['target_entity_id'] ? (int)$card['target_entity_id'] : null;

        $result = [
            'entity_id' => $entityId,
            'entity_name' => $card['entity_name'],
            'entity_type' => $card['entity_type'],
            'card_id' => (int)$card['drawn_card_id'],
            'card_type' => CARD_HEAL,
            'target_id' => $targetId,
            'effect' => 'no_target',
        ];

        if ($targetId === null) {
            return $result;
        }

        // Check target is still alive
        $target = $this->game->getObjectFromDB(
            "SELECT entity_name, is_defeated FROM entity WHERE entity_id = $targetId"
        );

        if (!$target || $target['is_defeated'] == 1) {
            $result['effect'] = 'target_defeated';
            return $result;
        }

        $healedCard = $this->deck->healOne($targetId);

        $result['target_name'] = $target['entity_name'];
        $result['effect'] = $healedCard ? 'heal' : 'no_cards_to_heal';
        $result['healed_card'] = $healedCard;

        return $result;
    }

    /**
     * Resolve Shuffle card - shuffles own active deck
     */
    private function resolveShuffle(int $sequenceId, array $card): array
    {
        $entityId = (int)$card['entity_id'];

        $result = [
            'entity_id' => $entityId,
            'entity_name' => $card['entity_name'],
            'entity_type' => $card['entity_type'],
            'card_id' => (int)$card['drawn_card_id'],
            'card_type' => CARD_SHUFFLE,
            'effect' => 'shuffle',
        ];

        // Shuffle the entity's active deck
        $this->deck->shuffleActive($entityId);

        return $result;
    }

    /**
     * Resolve Sell card - marks entity as willing to sell this round
     */
    private function resolveSell(int $sequenceId, array $card): array
    {
        $entityId = (int)$card['entity_id'];

        return [
            'entity_id' => $entityId,
            'entity_name' => $card['entity_name'],
            'entity_type' => $card['entity_type'],
            'card_id' => (int)$card['drawn_card_id'],
            'card_type' => CARD_SELL,
            'effect' => 'selling',
        ];
    }

    /**
     * Resolve Wealth card - buys an item from a selling neutral entity
     * Consumes both the Wealth card and the item
     */
    private function resolveWealth(int $sequenceId, array $card, array $sellersThisRound): array
    {
        $entityId = (int)$card['entity_id'];
        $targetId = $card['target_entity_id'] ? (int)$card['target_entity_id'] : null;

        $result = [
            'entity_id' => $entityId,
            'entity_name' => $card['entity_name'],
            'entity_type' => $card['entity_type'],
            'card_id' => (int)$card['drawn_card_id'],
            'card_type' => CARD_WEALTH,
            'target_id' => $targetId,
            'effect' => 'no_target',
        ];

        if ($targetId === null) {
            return $result;
        }

        // Check target exists and has items
        $target = $this->game->getObjectFromDB(
            "SELECT entity_name FROM entity WHERE entity_id = $targetId"
        );

        if (!$target) {
            return $result;
        }

        $result['target_name'] = $target['entity_name'];

        // Check target is selling this round
        if (!in_array($targetId, $sellersThisRound)) {
            $result['effect'] = 'not_selling';
            $result['reason'] = 'target is not selling';
            return $result;
        }

        // Get an item from the target
        $item = $this->game->getObjectFromDB(
            "SELECT item_id, item_name, item_type, item_data FROM item 
             WHERE entity_id = $targetId LIMIT 1"
        );

        if (!$item) {
            $result['effect'] = 'no_items';
            return $result;
        }

        // Transfer the item by consuming it
        $this->consumeItem($entityId, $item);

        // Delete the item from the seller
        $this->game->DbQuery("DELETE FROM item WHERE item_id = " . (int)$item['item_id']);

        // Mark the wealth card as destroyed (it's consumed)
        $this->deck->destroy((int)$card['drawn_card_id']);

        $result['effect'] = 'purchased';
        $result['item'] = $item;

        return $result;
    }

    /**
     * Resolve Steal card - steals an item from a neutral entity
     * Countered by Watch - if caught, faction becomes hostile
     */
    private function resolveSteal(int $sequenceId, array $card, array $watchedLocations): array
    {
        $entityId = (int)$card['entity_id'];
        $targetId = $card['target_entity_id'] ? (int)$card['target_entity_id'] : null;

        $result = [
            'entity_id' => $entityId,
            'entity_name' => $card['entity_name'],
            'entity_type' => $card['entity_type'],
            'card_id' => (int)$card['drawn_card_id'],
            'card_type' => CARD_STEAL,
            'target_id' => $targetId,
            'effect' => 'no_target',
        ];

        if ($targetId === null) {
            return $result;
        }

        // Get target info
        $target = $this->game->getObjectFromDB(
            "SELECT entity_name, faction FROM entity WHERE entity_id = $targetId"
        );

        if (!$target) {
            return $result;
        }

        $result['target_name'] = $target['entity_name'];

        // Check if location is watched - stealing is caught!
        $location = $this->game->getUniqueValueFromDB(
            "SELECT location_id FROM entity WHERE entity_id = $entityId"
        );

        if (isset($watchedLocations[$location])) {
            // Caught stealing! Faction becomes hostile
            $this->setFactionRelationship($card['faction'], $target['faction'], RELATION_HOSTILE);
            
            $result['effect'] = 'caught';
            $result['reason'] = 'watched';
            $result['faction_now_hostile'] = $target['faction'];
            return $result;
        }

        // Get an item from the target
        $item = $this->game->getObjectFromDB(
            "SELECT item_id, item_name, item_type, item_data FROM item 
             WHERE entity_id = $targetId LIMIT 1"
        );

        if (!$item) {
            $result['effect'] = 'no_items';
            return $result;
        }

        // Transfer the item by consuming it
        $this->consumeItem($entityId, $item);

        // Delete the item from the victim
        $this->game->DbQuery("DELETE FROM item WHERE item_id = " . (int)$item['item_id']);

        $result['effect'] = 'stolen';
        $result['item'] = $item;

        return $result;
    }

    /**
     * Consume an item - apply its effect to the receiving entity
     * Items are consumed upon acquisition (not stored)
     */
    private function consumeItem(int $entityId, array $item): void
    {
        $itemType = $item['item_type'];
        $itemData = is_string($item['item_data']) ? json_decode($item['item_data'], true) : $item['item_data'];

        switch ($itemType) {
            case ITEM_NEW_ACTION:
                // Add a new card to the entity's inactive deck
                $cardType = $itemData['card_type'] ?? CARD_ATTACK;
                $this->deck->addCardToInactive($entityId, $cardType);
                break;

            case ITEM_INFORMATION:
                // TODO: Reveal map information
                // For now, this is a no-op
                break;

            case ITEM_FACTION:
                // TODO: Alter faction relationships
                // For now, this is a no-op
                break;
        }
    }

    /**
     * Set a faction relationship (for when stealing is caught)
     */
    private function setFactionRelationship(string $faction1, string $faction2, string $relationship): void
    {
        // Update the in-memory matrix
        if (isset($this->factionMatrix[$faction1])) {
            $this->factionMatrix[$faction1][$faction2] = $relationship;
        }
        if (isset($this->factionMatrix[$faction2])) {
            $this->factionMatrix[$faction2][$faction1] = $relationship;
        }

        // Persist to game state
        $this->game->DbQuery(
            "UPDATE game_state SET state_value = '" . 
            addslashes(json_encode($this->factionMatrix)) . 
            "' WHERE state_key = '" . STATE_FACTION_MATRIX . "'"
        );
    }

    /**
     * Transfer and consume all items from a killed entity to the killer
     * Returns array of items transferred
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

        // Group by faction and count alive
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

        // Check if any faction is eliminated
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
            $counts = $this->deck->getPileCounts((int)$p['entity_id']);
            $tags = $this->getTags((int)$p['entity_id']);
            
            $status[] = [
                'entity_id' => (int)$p['entity_id'],
                'entity_name' => $p['entity_name'],
                'entity_type' => $p['entity_type'],
                'faction' => $p['faction'],
                'is_defeated' => (bool)$p['is_defeated'],
                'active' => $counts['active'],
                'discard' => $counts['discard'],
                'destroyed' => $counts['destroyed'],
                'tags' => $tags,
            ];
        }

        return $status;
    }

    /**
     * Clean up after a sequence ends
     */
    public function endSequence(int $sequenceId): void
    {
        $this->game->DbQuery("UPDATE action_sequence SET is_resolved = 1 WHERE sequence_id = $sequenceId");
        $this->game->DbQuery(
            "UPDATE sequence_participant 
             SET drawn_card_id = NULL, target_entity_id = NULL, block_count = 0, is_resolved = 0 
             WHERE sequence_id = $sequenceId"
        );
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
     * Apply poison damage to all poisoned entities at end of round
     * Returns array of damage results
     */
    public function applyPoisonTicks(int $sequenceId): array
    {
        $results = [];

        // Get all poisoned participants
        $poisonedEntities = $this->game->getObjectListFromDB(
            "SELECT et.entity_id, e.entity_name, e.entity_type, et.tag_value as rounds_remaining
             FROM entity_tag et
             JOIN entity e ON et.entity_id = e.entity_id
             JOIN sequence_participant sp ON e.entity_id = sp.entity_id
             WHERE sp.sequence_id = $sequenceId 
               AND et.tag_name = '" . TAG_POISONED . "'
               AND e.is_defeated = 0"
        );

        foreach ($poisonedEntities as $pe) {
            $entityId = (int)$pe['entity_id'];
            
            $result = [
                'entity_id' => $entityId,
                'entity_name' => $pe['entity_name'],
                'entity_type' => $pe['entity_type'],
                'effect' => 'poison_tick',
                'rounds_remaining' => (int)$pe['rounds_remaining'],
            ];

            // Get their drawn card to exclude it
            $drawnCardId = $this->game->getUniqueValueFromDB(
                "SELECT drawn_card_id FROM sequence_participant 
                 WHERE sequence_id = $sequenceId AND entity_id = $entityId"
            );
            $excludeCards = $drawnCardId ? [(int)$drawnCardId] : [];

            // Destroy one card from poison
            $destroyedCard = $this->deck->destroyOneCard($entityId, $excludeCards);

            if ($destroyedCard) {
                $result['destroyed_card'] = $destroyedCard;
                $result['from_pile'] = $destroyedCard['from_pile'] ?? 'active';

                // Check if now defeated
                if ($this->deck->isDefeated($entityId)) {
                    $this->game->DbQuery("UPDATE entity SET is_defeated = 1 WHERE entity_id = $entityId");
                    $result['defeated'] = true;
                }
            } else {
                $result['no_cards'] = true;
            }

            $results[] = $result;
        }

        return $results;
    }
}

