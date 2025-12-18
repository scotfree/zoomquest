<?php

declare(strict_types=1);

namespace Bga\Games\Zoomquest\Helpers;

require_once(dirname(__DIR__) . '/constants.inc.php');

/**
 * Handles combat resolution logic
 * 
 * Combat is simultaneous with ordered resolution:
 * 1. All cards are drawn and targets are assigned (snapshot)
 * 2. Resolution order: Heal → Defend → Attack
 * 3. Defend grants blocks that absorb attacks
 * 4. Blocks expire at end of round
 */
class CombatResolver
{
    private $game;
    private Deck $deck;

    // Resolution order by card type (lower = resolves first)
    private const RESOLUTION_ORDER = [
        CARD_HEAL => 0,
        CARD_DEFEND => 1,
        CARD_ATTACK => 2,
    ];

    public function __construct($game, Deck $deck)
    {
        $this->game = $game;
        $this->deck = $deck;
    }

    /**
     * Get all entities at a location
     */
    public function getEntitiesAtLocation(string $locationId): array
    {
        return $this->game->getObjectListFromDB(
            "SELECT entity_id, entity_type, entity_name, entity_class 
             FROM entity 
             WHERE location_id = '$locationId' AND is_defeated = 0"
        );
    }

    /**
     * Check if a battle should occur at a location
     */
    public function shouldBattleOccur(string $locationId): bool
    {
        $entities = $this->getEntitiesAtLocation($locationId);
        if (count($entities) < 2) {
            return false;
        }

        $entityIds = array_column($entities, 'entity_id');
        $entityIdList = implode(',', $entityIds);

        $battleCount = (int)$this->game->getUniqueValueFromDB(
            "SELECT COUNT(*) FROM action_choice 
             WHERE entity_id IN ($entityIdList) AND action_type = 'battle'"
        );

        return $battleCount > 0;
    }

    /**
     * Get locations where battles should occur
     */
    public function getBattleLocations(): array
    {
        $locations = $this->game->getObjectListFromDB("SELECT DISTINCT location_id FROM entity WHERE is_defeated = 0");
        
        $battleLocations = [];
        foreach ($locations as $loc) {
            if ($this->shouldBattleOccur($loc['location_id'])) {
                $battleLocations[] = $loc['location_id'];
            }
        }

        shuffle($battleLocations);
        return $battleLocations;
    }

    /**
     * Create a battle record and initialize participants
     */
    public function createBattle(string $locationId): int
    {
        $this->game->DbQuery(
            "INSERT INTO battle (location_id, is_resolved) VALUES ('$locationId', 0)"
        );
        $battleId = (int)$this->game->DbGetLastId();

        $entities = $this->getEntitiesAtLocation($locationId);
        foreach ($entities as $entity) {
            $entityId = $entity['entity_id'];
            $this->game->DbQuery(
                "INSERT INTO battle_participant (battle_id, entity_id, drawn_card_id, target_entity_id, resolution_order, block_count, is_resolved) 
                 VALUES ($battleId, $entityId, NULL, NULL, NULL, 0, 0)"
            );
        }

        return $battleId;
    }

    /**
     * Calculate health for an entity (active + discard pile count)
     */
    private function getEntityHealth(int $entityId): int
    {
        $counts = $this->deck->getPileCounts($entityId);
        return $counts['active'] + $counts['discard'];
    }

    /**
     * Get lowest health target of a given type, random on ties
     * @param int $battleId The battle
     * @param string $entityType 'player' or 'monster'
     * @param bool $includeSelf If true, include the requesting entity
     * @param int|null $selfEntityId The entity requesting (for self-inclusion check)
     * @return array|null The target entity or null
     */
    private function getLowestHealthTarget(int $battleId, string $entityType, bool $includeSelf = true, ?int $selfEntityId = null): ?array
    {
        $entities = $this->game->getObjectListFromDB(
            "SELECT e.entity_id, e.entity_name
             FROM battle_participant bp
             JOIN entity e ON bp.entity_id = e.entity_id
             WHERE bp.battle_id = $battleId 
               AND e.entity_type = '$entityType'
               AND e.is_defeated = 0"
        );

        if (empty($entities)) {
            return null;
        }

        // Calculate health for each and find minimum
        $lowestHealth = PHP_INT_MAX;
        $candidates = [];

        foreach ($entities as $entity) {
            $health = $this->getEntityHealth((int)$entity['entity_id']);
            if ($health < $lowestHealth) {
                $lowestHealth = $health;
                $candidates = [$entity];
            } elseif ($health === $lowestHealth) {
                $candidates[] = $entity;
            }
        }

        // Random selection among ties
        if (count($candidates) > 1) {
            shuffle($candidates);
        }

        return $candidates[0] ?? null;
    }

    /**
     * Draw cards for all participants and assign targets (snapshot)
     * Resolution order is by card type: Heal (0) → Defend (1) → Attack (2)
     */
    public function drawCardsForBattle(int $battleId): array
    {
        // Reset block counts at start of round
        $this->game->DbQuery(
            "UPDATE battle_participant SET block_count = 0 WHERE battle_id = $battleId"
        );

        // Get non-defeated participants
        $participants = $this->game->getObjectListFromDB(
            "SELECT bp.entity_id, e.entity_type, e.entity_name 
             FROM battle_participant bp
             JOIN entity e ON bp.entity_id = e.entity_id
             WHERE bp.battle_id = $battleId AND e.is_defeated = 0"
        );

        $drawnCards = [];
        foreach ($participants as $p) {
            $entityId = (int)$p['entity_id'];
            $card = $this->deck->drawTop($entityId);

            if ($card) {
                $cardId = (int)$card['card_id'];
                $cardType = $card['card_type'];

                // Determine target based on card type (snapshot at draw time)
                $targetId = $this->determineTarget($battleId, $entityId, $p['entity_type'], $cardType);

                // Get resolution order based on card type
                $resolutionOrder = self::RESOLUTION_ORDER[$cardType] ?? 99;

                $this->game->DbQuery(
                    "UPDATE battle_participant 
                     SET drawn_card_id = $cardId, 
                         target_entity_id = " . ($targetId !== null ? $targetId : "NULL") . ",
                         resolution_order = $resolutionOrder,
                         is_resolved = 0 
                     WHERE battle_id = $battleId AND entity_id = $entityId"
                );

                // Get target name for notification
                $targetName = null;
                if ($targetId !== null) {
                    $target = $this->game->getObjectFromDB(
                        "SELECT entity_name FROM entity WHERE entity_id = $targetId"
                    );
                    $targetName = $target ? $target['entity_name'] : null;
                }

                $drawnCards[] = [
                    'entity_id' => $entityId,
                    'entity_type' => $p['entity_type'],
                    'entity_name' => $p['entity_name'],
                    'card_id' => $cardId,
                    'card_type' => $cardType,
                    'target_id' => $targetId,
                    'target_name' => $targetName,
                    'resolution_order' => $resolutionOrder,
                ];
            }
        }

        // Sort by resolution order for display
        usort($drawnCards, fn($a, $b) => $a['resolution_order'] <=> $b['resolution_order']);

        return $drawnCards;
    }

    /**
     * Determine target for a card based on type
     * - Heal/Defend: Lowest health ally (including self)
     * - Attack: Lowest health enemy
     */
    private function determineTarget(int $battleId, int $entityId, string $entityType, string $cardType): ?int
    {
        switch ($cardType) {
            case CARD_HEAL:
            case CARD_DEFEND:
                // Target lowest health ally (same type as self)
                $target = $this->getLowestHealthTarget($battleId, $entityType, true, $entityId);
                return $target ? (int)$target['entity_id'] : null;

            case CARD_ATTACK:
                // Target lowest health enemy (opposite type)
                $targetType = ($entityType === ENTITY_PLAYER) ? ENTITY_MONSTER : ENTITY_PLAYER;
                $target = $this->getLowestHealthTarget($battleId, $targetType, false, null);
                return $target ? (int)$target['entity_id'] : null;

            default:
                return null;
        }
    }

    /**
     * Get the next card to resolve (ordered by card type)
     */
    public function getNextCardToResolve(int $battleId): ?array
    {
        $result = $this->game->getObjectFromDB(
            "SELECT bp.entity_id, bp.drawn_card_id, bp.target_entity_id, bp.resolution_order,
                    e.entity_type, e.entity_name, c.card_type
             FROM battle_participant bp
             JOIN entity e ON bp.entity_id = e.entity_id
             JOIN card c ON bp.drawn_card_id = c.card_id
             WHERE bp.battle_id = $battleId 
               AND bp.is_resolved = 0 
               AND bp.drawn_card_id IS NOT NULL
             ORDER BY bp.resolution_order ASC, bp.entity_id ASC
             LIMIT 1"
        );

        return $result ?: null;
    }

    /**
     * Resolve a single card's effect
     */
    public function resolveCard(int $battleId, int $entityId, int $cardId, string $cardType): array
    {
        $result = [
            'entity_id' => $entityId,
            'card_id' => $cardId,
            'card_type' => $cardType,
            'target_id' => null,
            'effect' => null,
        ];

        // Get entity info
        $entity = $this->game->getObjectFromDB(
            "SELECT entity_type, entity_name FROM entity WHERE entity_id = $entityId"
        );
        $result['entity_name'] = $entity['entity_name'];
        $result['entity_type'] = $entity['entity_type'];

        // Get the pre-assigned target
        $participant = $this->game->getObjectFromDB(
            "SELECT target_entity_id FROM battle_participant 
             WHERE battle_id = $battleId AND entity_id = $entityId"
        );
        $targetId = $participant['target_entity_id'] ? (int)$participant['target_entity_id'] : null;

        switch ($cardType) {
            case CARD_HEAL:
                $result = $this->resolveHeal($battleId, $entityId, $targetId, $result);
                break;
            case CARD_DEFEND:
                $result = $this->resolveDefend($battleId, $entityId, $targetId, $result);
                break;
            case CARD_ATTACK:
                $result = $this->resolveAttack($battleId, $entityId, $targetId, $result);
                break;
        }

        // Move card to discard
        $this->deck->discard($cardId);

        // Mark as resolved
        $this->game->DbQuery(
            "UPDATE battle_participant SET is_resolved = 1 
             WHERE battle_id = $battleId AND entity_id = $entityId"
        );

        return $result;
    }

    /**
     * Resolve heal: recover one card from target's destroyed pile
     */
    private function resolveHeal(int $battleId, int $healerId, ?int $targetId, array $result): array
    {
        if ($targetId === null) {
            $result['effect'] = 'no_target';
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

            $result['target_id'] = $targetId;
            $result['target_name'] = $target['entity_name'];
        $result['effect'] = $healedCard ? 'heal' : 'no_cards_to_heal';
        $result['healed_card'] = $healedCard;

        return $result;
    }

    /**
     * Resolve defend: give target +1 block
     */
    private function resolveDefend(int $battleId, int $defenderId, ?int $targetId, array $result): array
    {
        if ($targetId === null) {
            $result['effect'] = 'no_target';
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

        // Add block to target
        $this->game->DbQuery(
            "UPDATE battle_participant SET block_count = block_count + 1 
             WHERE battle_id = $battleId AND entity_id = $targetId"
        );

            $result['target_id'] = $targetId;
            $result['target_name'] = $target['entity_name'];
        $result['effect'] = 'block';

        // Get new block count for notification
        $blockCount = (int)$this->game->getUniqueValueFromDB(
            "SELECT block_count FROM battle_participant 
             WHERE battle_id = $battleId AND entity_id = $targetId"
        );
        $result['block_count'] = $blockCount;

        return $result;
    }

    /**
     * Resolve attack: check for blocks, then destroy a card
     */
    private function resolveAttack(int $battleId, int $attackerId, ?int $targetId, array $result): array
    {
        if ($targetId === null) {
            $result['effect'] = 'no_target';
            return $result;
        }

        // Check target is still alive
        $target = $this->game->getObjectFromDB(
            "SELECT entity_name, is_defeated FROM entity WHERE entity_id = $targetId"
        );
        
        if (!$target || $target['is_defeated'] == 1) {
            $result['effect'] = 'target_defeated';
            $result['target_id'] = $targetId;
            $result['target_name'] = $target ? $target['entity_name'] : 'Unknown';
            return $result;
        }

        $result['target_id'] = $targetId;
        $result['target_name'] = $target['entity_name'];

        // Check for blocks
        $blockCount = (int)$this->game->getUniqueValueFromDB(
            "SELECT block_count FROM battle_participant 
             WHERE battle_id = $battleId AND entity_id = $targetId"
        );

        if ($blockCount > 0) {
            // Attack is blocked!
            $this->game->DbQuery(
                "UPDATE battle_participant SET block_count = block_count - 1 
                 WHERE battle_id = $battleId AND entity_id = $targetId"
            );
            $result['effect'] = 'blocked';
            $result['blocks_remaining'] = $blockCount - 1;
            return $result;
        }

        // No block - destroy a card (tries active first, then discard)
        $destroyedCard = $this->deck->destroyOneCard($targetId);

        if ($destroyedCard) {
            $result['effect'] = 'destroy';
            $result['destroyed_card'] = $destroyedCard;
            $result['from_pile'] = $destroyedCard['from_pile'] ?? 'active';

            // Check if target is now defeated (no cards in active or discard)
            if ($this->deck->isDefeated($targetId)) {
                $this->game->DbQuery("UPDATE entity SET is_defeated = 1 WHERE entity_id = $targetId");
                $result['target_defeated'] = true;
            }
        } else {
            // No cards to destroy - target already defeated
            $result['effect'] = 'no_cards';
        }

        return $result;
    }

    /**
     * Check if a battle round is complete
     */
    public function isBattleRoundComplete(int $battleId): bool
    {
        $unresolved = (int)$this->game->getUniqueValueFromDB(
            "SELECT COUNT(*) FROM battle_participant bp
             JOIN entity e ON bp.entity_id = e.entity_id
             WHERE bp.battle_id = $battleId 
               AND bp.is_resolved = 0 
               AND bp.drawn_card_id IS NOT NULL"
        );

        return $unresolved === 0;
    }

    /**
     * Check if one team is eliminated
     */
    public function getEliminatedTeam(int $battleId): ?string
    {
        $participants = $this->game->getObjectListFromDB(
            "SELECT e.entity_type, e.is_defeated
             FROM battle_participant bp
             JOIN entity e ON bp.entity_id = e.entity_id
             WHERE bp.battle_id = $battleId"
        );

        $playersAlive = 0;
        $monstersAlive = 0;

        foreach ($participants as $p) {
            if ($p['is_defeated'] == 0) {
                if ($p['entity_type'] === ENTITY_PLAYER) {
                    $playersAlive++;
                } else {
                    $monstersAlive++;
                }
            }
        }

        if ($playersAlive === 0) {
            return 'players';
        }
        if ($monstersAlive === 0) {
            return 'monsters';
        }

        return null;
    }

    /**
     * Check if all non-defeated participants have empty active piles
     * Battle ends when no one can draw cards
     */
    public function isEveryoneOutOfCards(int $battleId): bool
    {
        $participants = $this->game->getObjectListFromDB(
            "SELECT bp.entity_id
             FROM battle_participant bp
             JOIN entity e ON bp.entity_id = e.entity_id
             WHERE bp.battle_id = $battleId AND e.is_defeated = 0"
        );

        foreach ($participants as $p) {
            if ($this->deck->hasActiveCards((int)$p['entity_id'])) {
                return false;
            }
        }

        return true;
    }

    /**
     * Clean up after a battle ends
     * Note: No automatic shuffle - cards stay in discard until Rest action
     */
    public function endBattle(int $battleId): void
    {
        $this->game->DbQuery("UPDATE battle SET is_resolved = 1 WHERE battle_id = $battleId");
        $this->game->DbQuery(
            "UPDATE battle_participant 
             SET drawn_card_id = NULL, target_entity_id = NULL, resolution_order = NULL, block_count = 0, is_resolved = 0 
             WHERE battle_id = $battleId"
        );
    }

    /**
     * Reset battle round for next iteration
     */
    public function resetBattleRound(int $battleId): void
    {
        $this->game->DbQuery(
            "UPDATE battle_participant 
             SET drawn_card_id = NULL, target_entity_id = NULL, resolution_order = NULL, block_count = 0, is_resolved = 0 
             WHERE battle_id = $battleId"
        );
    }
}
