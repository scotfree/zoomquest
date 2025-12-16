<?php

declare(strict_types=1);

namespace Bga\Games\Zoomquest\Helpers;

require_once(dirname(__DIR__) . '/constants.inc.php');

/**
 * Handles combat resolution logic
 */
class CombatResolver
{
    private $game;
    private Deck $deck;

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
     * (any entity chose 'battle' action)
     */
    public function shouldBattleOccur(string $locationId): bool
    {
        $entities = $this->getEntitiesAtLocation($locationId);
        if (count($entities) < 2) {
            return false;
        }

        // Check if any entity chose battle
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
        // Get all locations with 2+ entities where at least one chose battle
        $locations = $this->game->getObjectListFromDB("SELECT DISTINCT location_id FROM entity WHERE is_defeated = 0");
        
        $battleLocations = [];
        foreach ($locations as $loc) {
            if ($this->shouldBattleOccur($loc['location_id'])) {
                $battleLocations[] = $loc['location_id'];
            }
        }

        // Shuffle for random resolution order
        shuffle($battleLocations);
        return $battleLocations;
    }

    /**
     * Create a battle record and initialize participants
     * @return int The battle ID
     */
    public function createBattle(string $locationId): int
    {
        $this->game->DbQuery(
            "INSERT INTO battle (location_id, is_resolved) VALUES ('$locationId', 0)"
        );
        $battleId = (int)$this->game->DbGetLastId();

        // Add all entities at this location as participants
        $entities = $this->getEntitiesAtLocation($locationId);
        foreach ($entities as $entity) {
            $entityId = $entity['entity_id'];
            $this->game->DbQuery(
                "INSERT INTO battle_participant (battle_id, entity_id, drawn_card_id, resolution_order, is_resolved) 
                 VALUES ($battleId, $entityId, NULL, NULL, 0)"
            );
        }

        return $battleId;
    }

    /**
     * Have all participants draw their top card and determine resolution order
     * @return array Drawn cards with resolution order
     */
    public function drawCardsForBattle(int $battleId): array
    {
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
                $this->game->DbQuery(
                    "UPDATE battle_participant SET drawn_card_id = $cardId, is_resolved = 0 
                     WHERE battle_id = $battleId AND entity_id = $entityId"
                );

                $drawnCards[] = [
                    'entity_id' => $entityId,
                    'entity_type' => $p['entity_type'],
                    'entity_name' => $p['entity_name'],
                    'card_id' => $cardId,
                    'card_type' => $card['card_type'],
                ];
            }
        }

        // Assign random resolution order
        shuffle($drawnCards);
        foreach ($drawnCards as $order => $card) {
            $entityId = $card['entity_id'];
            $this->game->DbQuery(
                "UPDATE battle_participant SET resolution_order = $order 
                 WHERE battle_id = $battleId AND entity_id = $entityId"
            );
            $drawnCards[$order]['resolution_order'] = $order;
        }

        return $drawnCards;
    }

    /**
     * Get the next card to resolve in the current battle round
     * @return array|null The next card to resolve or null if all resolved
     */
    public function getNextCardToResolve(int $battleId): ?array
    {
        $result = $this->game->getObjectFromDB(
            "SELECT bp.entity_id, bp.drawn_card_id, bp.resolution_order,
                    e.entity_type, e.entity_name, c.card_type
             FROM battle_participant bp
             JOIN entity e ON bp.entity_id = e.entity_id
             JOIN card c ON bp.drawn_card_id = c.card_id
             WHERE bp.battle_id = $battleId 
               AND bp.is_resolved = 0 
               AND bp.drawn_card_id IS NOT NULL
               AND e.is_defeated = 0
             ORDER BY bp.resolution_order ASC
             LIMIT 1"
        );

        return $result ?: null;
    }

    /**
     * Resolve a single card's effect
     * @return array Resolution details for notification
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

        switch ($cardType) {
            case CARD_ATTACK:
                $result = $this->resolveAttack($battleId, $entityId, $result);
                break;
            case CARD_DEFEND:
                $result = $this->resolveDefend($battleId, $entityId, $result);
                break;
            case CARD_HEAL:
                $result = $this->resolveHeal($battleId, $entityId, $result);
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
     * Resolve attack: destroy one card from target's active deck
     */
    private function resolveAttack(int $battleId, int $attackerId, array $result): array
    {
        // Get attacker type to determine target team
        $attacker = $this->game->getObjectFromDB(
            "SELECT entity_type FROM entity WHERE entity_id = $attackerId"
        );

        // Target the enemy with smallest active deck
        $targetType = ($attacker['entity_type'] === ENTITY_PLAYER) ? ENTITY_MONSTER : ENTITY_PLAYER;
        $target = $this->getSmallestDeckTarget($battleId, $targetType);

        if ($target) {
            $targetId = (int)$target['entity_id'];
            $destroyedCard = $this->deck->destroyRandomActive($targetId);

            $result['target_id'] = $targetId;
            $result['target_name'] = $target['entity_name'];
            $result['effect'] = 'destroy';
            $result['destroyed_card'] = $destroyedCard;

            // Check if target is now defeated
            if ($this->deck->isDefeated($targetId)) {
                $this->game->DbQuery("UPDATE entity SET is_defeated = 1 WHERE entity_id = $targetId");
                $result['target_defeated'] = true;
            }
        } else {
            $result['effect'] = 'no_target';
        }

        return $result;
    }

    /**
     * Resolve defend: mark this entity as defended
     * (For now, defend doesn't have an active effect - it just goes to discard)
     * Future: could block the next incoming attack
     */
    private function resolveDefend(int $battleId, int $defenderId, array $result): array
    {
        // Get defender type to determine who to defend
        $defender = $this->game->getObjectFromDB(
            "SELECT entity_type FROM entity WHERE entity_id = $defenderId"
        );

        // Defend targets ally with smallest deck
        $targetType = $defender['entity_type'];
        $target = $this->getSmallestDeckTarget($battleId, $targetType);

        if ($target) {
            $result['target_id'] = (int)$target['entity_id'];
            $result['target_name'] = $target['entity_name'];
            $result['effect'] = 'defend';
            // TODO: Implement actual defend blocking logic
            // For now, defend just goes to discard without effect
        } else {
            $result['effect'] = 'no_target';
        }

        return $result;
    }

    /**
     * Resolve heal: recover one card from target's destroyed pile
     */
    private function resolveHeal(int $battleId, int $healerId, array $result): array
    {
        // Get healer type to determine who to heal
        $healer = $this->game->getObjectFromDB(
            "SELECT entity_type FROM entity WHERE entity_id = $healerId"
        );

        // Heal targets ally with smallest deck
        $targetType = $healer['entity_type'];
        $target = $this->getSmallestDeckTarget($battleId, $targetType);

        if ($target) {
            $targetId = (int)$target['entity_id'];
            $healedCard = $this->deck->healOne($targetId);

            $result['target_id'] = $targetId;
            $result['target_name'] = $target['entity_name'];
            $result['effect'] = $healedCard ? 'heal' : 'no_cards_to_heal';
            $result['healed_card'] = $healedCard;
        } else {
            $result['effect'] = 'no_target';
        }

        return $result;
    }

    /**
     * Get the target with smallest active deck of a given type
     */
    private function getSmallestDeckTarget(int $battleId, string $entityType): ?array
    {
        // Get all non-defeated entities of this type in the battle
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

        // Find the one with smallest active deck
        $smallestCount = PHP_INT_MAX;
        $target = null;

        foreach ($entities as $entity) {
            $counts = $this->deck->getPileCounts((int)$entity['entity_id']);
            if ($counts['active'] < $smallestCount) {
                $smallestCount = $counts['active'];
                $target = $entity;
            }
        }

        return $target;
    }

    /**
     * Check if a battle round is complete (all cards resolved)
     */
    public function isBattleRoundComplete(int $battleId): bool
    {
        $unresolved = (int)$this->game->getUniqueValueFromDB(
            "SELECT COUNT(*) FROM battle_participant bp
             JOIN entity e ON bp.entity_id = e.entity_id
             WHERE bp.battle_id = $battleId 
               AND bp.is_resolved = 0 
               AND bp.drawn_card_id IS NOT NULL
               AND e.is_defeated = 0"
        );

        return $unresolved === 0;
    }

    /**
     * Check if one team is eliminated
     * @return string|null 'players', 'monsters', or null if battle continues
     */
    public function getEliminatedTeam(int $battleId): ?string
    {
        // Get participants by type
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
     * Clean up after a battle ends
     */
    public function endBattle(int $battleId): void
    {
        // Get surviving participants
        $survivors = $this->game->getObjectListFromDB(
            "SELECT bp.entity_id
             FROM battle_participant bp
             JOIN entity e ON bp.entity_id = e.entity_id
             WHERE bp.battle_id = $battleId AND e.is_defeated = 0"
        );

        // Shuffle discard into active for survivors
        foreach ($survivors as $survivor) {
            $this->deck->shuffleDiscardIntoActive((int)$survivor['entity_id']);
        }

        // Mark battle as resolved
        $this->game->DbQuery("UPDATE battle SET is_resolved = 1 WHERE battle_id = $battleId");

        // Clear drawn cards for next battle round (if any)
        $this->game->DbQuery(
            "UPDATE battle_participant SET drawn_card_id = NULL, resolution_order = NULL, is_resolved = 0 
             WHERE battle_id = $battleId"
        );
    }

    /**
     * Reset battle round for next iteration
     */
    public function resetBattleRound(int $battleId): void
    {
        $this->game->DbQuery(
            "UPDATE battle_participant SET drawn_card_id = NULL, resolution_order = NULL, is_resolved = 0 
             WHERE battle_id = $battleId"
        );
    }
}

