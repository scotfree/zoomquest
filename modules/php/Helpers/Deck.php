<?php

declare(strict_types=1);

namespace Bga\Games\Zoomquest\Helpers;

require_once(dirname(__DIR__) . '/constants.inc.php');

/**
 * Helper class for deck/card operations
 */
class Deck
{
    private $game;

    public function __construct($game)
    {
        $this->game = $game;
    }

    /**
     * Create cards for an entity from an array of card types
     */
    public function createDeck(int $entityId, array $cardTypes): void
    {
        $order = 0;
        foreach ($cardTypes as $cardType) {
            $this->game->DbQuery(
                "INSERT INTO card (entity_id, card_type, card_pile, card_order) 
                 VALUES ($entityId, '$cardType', 'active', $order)"
            );
            $order++;
        }
    }

    /**
     * Shuffle the active deck for an entity
     */
    public function shuffleActive(int $entityId): void
    {
        // Get all active cards
        $cards = $this->game->getObjectListFromDB(
            "SELECT card_id FROM card WHERE entity_id = $entityId AND card_pile = 'active'"
        );

        if (empty($cards)) {
            return;
        }

        // Assign random order
        $cardIds = array_column($cards, 'card_id');
        shuffle($cardIds);

        foreach ($cardIds as $order => $cardId) {
            $this->game->DbQuery(
                "UPDATE card SET card_order = $order WHERE card_id = $cardId"
            );
        }
    }

    /**
     * Draw the top card from active deck
     * Returns null if active deck is empty (no auto-reshuffle)
     * @return array|null The drawn card or null if deck is empty
     */
    public function drawTop(int $entityId): ?array
    {
        $card = $this->game->getObjectFromDB(
            "SELECT card_id, card_type FROM card 
             WHERE entity_id = $entityId AND card_pile = 'active' 
             ORDER BY card_order ASC LIMIT 1"
        );

        return $card ?: null;
    }

    /**
     * Check if entity has cards in active pile
     */
    public function hasActiveCards(int $entityId): bool
    {
        $count = (int)$this->game->getUniqueValueFromDB(
            "SELECT COUNT(*) FROM card WHERE entity_id = $entityId AND card_pile = 'active'"
        );
        return $count > 0;
    }

    /**
     * Get all active cards for an entity, ordered by card_order
     * @return array List of cards with id and type
     */
    public function getActiveCards(int $entityId): array
    {
        return $this->game->getObjectListFromDB(
            "SELECT card_id, card_type FROM card 
             WHERE entity_id = $entityId AND card_pile = 'active' 
             ORDER BY card_order ASC"
        );
    }

    /**
     * Reorder active cards based on provided card IDs
     * @param int $entityId The entity
     * @param array $cardIds Array of card IDs in desired order
     */
    public function reorderActive(int $entityId, array $cardIds): void
    {
        foreach ($cardIds as $order => $cardId) {
            $cardId = (int)$cardId;
            // Verify this card belongs to this entity and is in active pile
            $this->game->DbQuery(
                "UPDATE card SET card_order = $order 
                 WHERE card_id = $cardId AND entity_id = $entityId AND card_pile = 'active'"
            );
        }
    }

    /**
     * Move a card to discard pile
     */
    public function discard(int $cardId): void
    {
        $this->game->DbQuery(
            "UPDATE card SET card_pile = 'discard' WHERE card_id = $cardId"
        );
    }

    /**
     * Move a card to destroyed pile
     */
    public function destroy(int $cardId): void
    {
        $this->game->DbQuery(
            "UPDATE card SET card_pile = 'destroyed' WHERE card_id = $cardId"
        );
    }

    /**
     * Move a random card from destroyed to discard (heal/rest effect)
     * @return array|null The healed card or null if no destroyed cards
     */
    public function healOne(int $entityId): ?array
    {
        $card = $this->game->getObjectFromDB(
            "SELECT card_id, card_type FROM card 
             WHERE entity_id = $entityId AND card_pile = 'destroyed' 
             ORDER BY RAND() LIMIT 1"
        );

        if ($card) {
            // Move to top of discard (highest order)
            $maxOrder = (int)$this->game->getUniqueValueFromDB(
                "SELECT COALESCE(MAX(card_order), -1) FROM card 
                 WHERE entity_id = $entityId AND card_pile = 'discard'"
            );
            $this->game->DbQuery(
                "UPDATE card SET card_pile = 'discard', card_order = " . ($maxOrder + 1) . " 
                 WHERE card_id = {$card['card_id']}"
            );
        }

        return $card;
    }

    /**
     * Destroy a random card - tries active pile first, then discard pile
     * Excludes cards that are currently drawn (in a sequence)
     * @param int $entityId The entity to destroy a card from
     * @param array $excludeCardIds Card IDs to exclude (e.g., currently drawn cards)
     * @return array|null The destroyed card with 'from_pile' key, or null if no cards
     */
    public function destroyOneCard(int $entityId, array $excludeCardIds = []): ?array
    {
        $excludeClause = '';
        if (!empty($excludeCardIds)) {
            $ids = implode(',', array_map('intval', $excludeCardIds));
            $excludeClause = " AND card_id NOT IN ($ids)";
        }

        // Try active pile first
        $card = $this->game->getObjectFromDB(
            "SELECT card_id, card_type FROM card 
             WHERE entity_id = $entityId AND card_pile = 'active' $excludeClause
             ORDER BY RAND() LIMIT 1"
        );

        if ($card) {
            $this->destroy((int)$card['card_id']);
            $card['from_pile'] = 'active';
            return $card;
        }

        // Try discard pile
        $card = $this->game->getObjectFromDB(
            "SELECT card_id, card_type FROM card 
             WHERE entity_id = $entityId AND card_pile = 'discard' $excludeClause
             ORDER BY RAND() LIMIT 1"
        );

        if ($card) {
            $this->destroy((int)$card['card_id']);
            $card['from_pile'] = 'discard';
            return $card;
        }

        return null;
    }

    /**
     * Get pile counts for an entity
     * @return array ['active' => int, 'discard' => int, 'destroyed' => int]
     */
    public function getPileCounts(int $entityId): array
    {
        $result = $this->game->getObjectListFromDB(
            "SELECT card_pile, COUNT(*) as count FROM card 
             WHERE entity_id = $entityId GROUP BY card_pile"
        );

        $counts = ['active' => 0, 'discard' => 0, 'destroyed' => 0];
        foreach ($result as $row) {
            $counts[$row['card_pile']] = (int)$row['count'];
        }

        return $counts;
    }

    /**
     * Check if entity is defeated (all cards destroyed)
     */
    public function isDefeated(int $entityId): bool
    {
        $counts = $this->getPileCounts($entityId);
        return ($counts['active'] + $counts['discard']) === 0;
    }

    /**
     * Refresh deck: move discard to bottom of active (maintaining order)
     * Called at start of each round
     */
    public function refreshDeck(int $entityId): void
    {
        // Get current max order in active pile
        $maxActiveOrder = (int)$this->game->getUniqueValueFromDB(
            "SELECT COALESCE(MAX(card_order), -1) FROM card 
             WHERE entity_id = $entityId AND card_pile = 'active'"
        );

        // Get discard cards in order
        $discardCards = $this->game->getObjectListFromDB(
            "SELECT card_id FROM card 
             WHERE entity_id = $entityId AND card_pile = 'discard' 
             ORDER BY card_order ASC"
        );

        // Move each discard card to bottom of active, maintaining order
        foreach ($discardCards as $index => $card) {
            $newOrder = $maxActiveOrder + 1 + $index;
            $this->game->DbQuery(
                "UPDATE card SET card_pile = 'active', card_order = $newOrder 
                 WHERE card_id = {$card['card_id']}"
            );
        }
    }

    /**
     * Shuffle discard back into active deck (legacy - use refreshDeck for ordered refresh)
     */
    public function shuffleDiscardIntoActive(int $entityId): void
    {
        // Move discard to active
        $this->game->DbQuery(
            "UPDATE card SET card_pile = 'active' WHERE entity_id = $entityId AND card_pile = 'discard'"
        );
        // Shuffle
        $this->shuffleActive($entityId);
    }

    /**
     * Get all cards for an entity organized by pile
     */
    public function getAllCards(int $entityId): array
    {
        $cards = $this->game->getObjectListFromDB(
            "SELECT card_id, card_type, card_pile, card_order FROM card 
             WHERE entity_id = $entityId ORDER BY card_pile, card_order"
        );

        $result = ['active' => [], 'discard' => [], 'destroyed' => []];
        foreach ($cards as $card) {
            $result[$card['card_pile']][] = $card;
        }

        return $result;
    }
}

