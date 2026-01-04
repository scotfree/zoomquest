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
     * Create cards for an entity from an array of card definitions
     * Each card can be:
     *   - A string (card type) for backward compatibility
     *   - An array with 'name', 'type', and optional 'power' (default 1)
     */
    public function createDeck(int $entityId, array $cards): void
    {
        $order = 0;
        foreach ($cards as $card) {
            if (is_string($card)) {
                // Backward compatibility: just a card type string
                $cardType = addslashes($card);
                $cardName = ucfirst($card); // Default name from type
                $cardPower = 1;
            } else {
                // New format: array with name, type, power
                $cardType = addslashes($card['type'] ?? 'attack');
                $cardName = addslashes($card['name'] ?? ucfirst($cardType));
                $cardPower = (int)($card['power'] ?? 1);
            }
            
            $this->game->DbQuery(
                "INSERT INTO card (entity_id, card_name, card_type, card_power, card_pile, card_order) 
                 VALUES ($entityId, '$cardName', '$cardType', $cardPower, 'active', $order)"
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
            "SELECT card_id, card_name, card_type, card_power FROM card 
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
     * @return array List of cards with id, name, type, power
     */
    public function getActiveCards(int $entityId): array
    {
        return $this->game->getObjectListFromDB(
            "SELECT card_id, card_name, card_type, card_power FROM card 
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
     * Move all active cards to discard (penalty for planning)
     * Maintains the current order
     */
    public function moveActiveToDiscard(int $entityId): void
    {
        // Get current max order in discard pile
        $maxDiscardOrder = (int)$this->game->getUniqueValueFromDB(
            "SELECT COALESCE(MAX(card_order), -1) FROM card 
             WHERE entity_id = $entityId AND card_pile = 'discard'"
        );

        // Get active cards in order
        $activeCards = $this->game->getObjectListFromDB(
            "SELECT card_id FROM card 
             WHERE entity_id = $entityId AND card_pile = 'active' 
             ORDER BY card_order ASC"
        );

        // Move each to discard, maintaining order
        foreach ($activeCards as $index => $card) {
            $newOrder = $maxDiscardOrder + 1 + $index;
            $this->game->DbQuery(
                "UPDATE card SET card_pile = 'discard', card_order = $newOrder 
                 WHERE card_id = {$card['card_id']}"
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
     * Move a card to inactive pile (damage)
     */
    public function destroy(int $cardId): void
    {
        $this->game->DbQuery(
            "UPDATE card SET card_pile = 'inactive' WHERE card_id = $cardId"
        );
    }

    /**
     * Move a random card from inactive to discard (heal/rest effect)
     * @return array|null The healed card or null if no inactive cards
     */
    public function healOne(int $entityId): ?array
    {
        $card = $this->game->getObjectFromDB(
            "SELECT card_id, card_name, card_type, card_power FROM card 
             WHERE entity_id = $entityId AND card_pile = 'inactive' 
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
     * Damage a card - moves it to inactive pile
     * Tries active pile first, then discard pile
     * Excludes cards that are currently drawn (in a sequence)
     * @param int $entityId The entity to damage a card from
     * @param array $excludeCardIds Card IDs to exclude (e.g., currently drawn cards)
     * @return array|null The damaged card with 'from_pile' key, or null if no cards
     */
    public function destroyOneCard(int $entityId, array $excludeCardIds = []): ?array
    {
        $excludeClause = '';
        if (!empty($excludeCardIds)) {
            $ids = implode(',', array_map('intval', $excludeCardIds));
            $excludeClause = " AND card_id NOT IN ($ids)";
        }

        // Try active pile first (excluding drawn card)
        $card = $this->game->getObjectFromDB(
            "SELECT card_id, card_name, card_type, card_power FROM card 
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
            "SELECT card_id, card_name, card_type, card_power FROM card 
             WHERE entity_id = $entityId AND card_pile = 'discard' $excludeClause
             ORDER BY RAND() LIMIT 1"
        );

        if ($card) {
            $this->destroy((int)$card['card_id']);
            $card['from_pile'] = 'discard';
            return $card;
        }

        // Last resort: destroy the drawn card itself if it's the only option
        // This prevents infinite combat when both sides only have their drawn card
        if (!empty($excludeCardIds)) {
            $card = $this->game->getObjectFromDB(
                "SELECT card_id, card_name, card_type, card_power FROM card 
                 WHERE entity_id = $entityId AND card_pile = 'active'
                 ORDER BY RAND() LIMIT 1"
            );

            if ($card) {
                $this->destroy((int)$card['card_id']);
                $card['from_pile'] = 'active';
                $card['was_drawn'] = true; // Flag that this was the drawn card
                return $card;
            }
        }

        return null;
    }

    /**
     * Get pile counts for an entity
     * @return array ['active' => int, 'discard' => int, 'inactive' => int]
     */
    public function getPileCounts(int $entityId): array
    {
        $result = $this->game->getObjectListFromDB(
            "SELECT card_pile, COUNT(*) as count FROM card 
             WHERE entity_id = $entityId GROUP BY card_pile"
        );

        $counts = ['active' => 0, 'discard' => 0, 'inactive' => 0];
        foreach ($result as $row) {
            $counts[$row['card_pile']] = (int)$row['count'];
        }

        return $counts;
    }

    /**
     * Check if entity is defeated (active + discard = 0)
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
            "SELECT card_id, card_name, card_type, card_power, card_pile, card_order FROM card 
             WHERE entity_id = $entityId ORDER BY card_pile, card_order"
        );

        $result = ['active' => [], 'discard' => [], 'inactive' => []];
        foreach ($cards as $card) {
            $result[$card['card_pile']][] = $card;
        }

        return $result;
    }

    /**
     * Get inactive cards for an entity, ordered by card_order
     * @return array List of cards with id, name, type, power
     */
    public function getInactiveCards(int $entityId): array
    {
        return $this->game->getObjectListFromDB(
            "SELECT card_id, card_name, card_type, card_power FROM card 
             WHERE entity_id = $entityId AND card_pile = 'inactive' 
             ORDER BY card_order ASC"
        );
    }

    /**
     * Move a card from active to inactive pile
     */
    public function moveToInactive(int $cardId): void
    {
        // Get the entity_id from the card
        $card = $this->game->getObjectFromDB(
            "SELECT entity_id FROM card WHERE card_id = $cardId"
        );
        if (!$card) {
            return;
        }
        $entityId = (int)$card['entity_id'];
        
        // Get max order in inactive pile
        $maxOrder = (int)$this->game->getUniqueValueFromDB(
            "SELECT COALESCE(MAX(card_order), -1) FROM card 
             WHERE entity_id = $entityId AND card_pile = 'inactive'"
        );
        
        $this->game->DbQuery(
            "UPDATE card SET card_pile = 'inactive', card_order = " . ($maxOrder + 1) . " 
             WHERE card_id = $cardId"
        );
    }

    /**
     * Move a card from inactive to active pile
     */
    public function moveToActive(int $cardId): void
    {
        // Get the entity_id from the card
        $card = $this->game->getObjectFromDB(
            "SELECT entity_id FROM card WHERE card_id = $cardId"
        );
        if (!$card) {
            return;
        }
        $entityId = (int)$card['entity_id'];
        
        // Get max order in active pile
        $maxOrder = (int)$this->game->getUniqueValueFromDB(
            "SELECT COALESCE(MAX(card_order), -1) FROM card 
             WHERE entity_id = $entityId AND card_pile = 'active'"
        );
        
        $this->game->DbQuery(
            "UPDATE card SET card_pile = 'active', card_order = " . ($maxOrder + 1) . " 
             WHERE card_id = $cardId"
        );
    }

    /**
     * Add a new card to entity's inactive pile (for item consumption)
     * @param int $entityId The entity to receive the card
     * @param string|array $card Card type string or array with name, type, power
     * @return int The new card's ID
     */
    public function addCardToInactive(int $entityId, $card): int
    {
        if (is_string($card)) {
            $cardType = addslashes($card);
            $cardName = ucfirst($card);
            $cardPower = 1;
        } else {
            $cardType = addslashes($card['type'] ?? 'attack');
            $cardName = addslashes($card['name'] ?? ucfirst($cardType));
            $cardPower = (int)($card['power'] ?? 1);
        }
        
        // Get max order in inactive pile
        $maxOrder = (int)$this->game->getUniqueValueFromDB(
            "SELECT COALESCE(MAX(card_order), -1) FROM card 
             WHERE entity_id = $entityId AND card_pile = 'inactive'"
        );
        
        $this->game->DbQuery(
            "INSERT INTO card (entity_id, card_name, card_type, card_power, card_pile, card_order) 
             VALUES ($entityId, '$cardName', '$cardType', $cardPower, 'inactive', " . ($maxOrder + 1) . ")"
        );
        
        return (int)$this->game->getUniqueValueFromDB("SELECT LAST_INSERT_ID()");
    }

    /**
     * Shuffle N cards from discard to active (for shuffle card effect)
     * @param int $entityId The entity
     * @param int $count Number of cards to shuffle
     * @return array Cards that were shuffled
     */
    public function shuffleFromDiscard(int $entityId, int $count): array
    {
        $cards = $this->game->getObjectListFromDB(
            "SELECT card_id, card_name, card_type, card_power FROM card 
             WHERE entity_id = $entityId AND card_pile = 'discard' 
             ORDER BY RAND() LIMIT $count"
        );

        if (empty($cards)) {
            return [];
        }

        // Get current max order in active pile
        $maxActiveOrder = (int)$this->game->getUniqueValueFromDB(
            "SELECT COALESCE(MAX(card_order), -1) FROM card 
             WHERE entity_id = $entityId AND card_pile = 'active'"
        );

        // Move cards to active and shuffle their positions
        $cardIds = array_column($cards, 'card_id');
        foreach ($cardIds as $index => $cardId) {
            $newOrder = $maxActiveOrder + 1 + $index;
            $this->game->DbQuery(
                "UPDATE card SET card_pile = 'active', card_order = $newOrder 
                 WHERE card_id = $cardId"
            );
        }

        // Shuffle the active deck
        $this->shuffleActive($entityId);

        return $cards;
    }

    /**
     * Permanently delete a card (for level up cost)
     * @param int $cardId The card to permanently remove
     */
    public function permanentlyDelete(int $cardId): void
    {
        $this->game->DbQuery("DELETE FROM card WHERE card_id = $cardId");
    }

    /**
     * Permanently delete multiple cards (for level up cost)
     * @param array $cardIds Array of card IDs to permanently remove
     * @return int Number of cards deleted
     */
    public function permanentlyDeleteCards(array $cardIds): int
    {
        if (empty($cardIds)) {
            return 0;
        }
        
        $ids = implode(',', array_map('intval', $cardIds));
        $this->game->DbQuery("DELETE FROM card WHERE card_id IN ($ids)");
        
        return count($cardIds);
    }

    /**
     * Apply deck changes from Plan action
     * Moves cards between active and inactive based on provided arrays
     * @param int $entityId The entity
     * @param array $activeCardIds Cards that should be in active pile (in order)
     * @param array $inactiveCardIds Cards that should be in inactive pile
     * @param int $maxActive Maximum cards allowed in active (entity level)
     */
    public function applyPlanChanges(int $entityId, array $activeCardIds, array $inactiveCardIds, int $maxActive): void
    {
        // Validate we don't exceed max active
        if (count($activeCardIds) > $maxActive) {
            throw new \BgaUserException(sprintf(
                clienttranslate("Active deck can only hold %d cards (your level)"),
                $maxActive
            ));
        }

        // Move all specified cards to their new piles
        foreach ($activeCardIds as $order => $cardId) {
            $cardId = (int)$cardId;
            $this->game->DbQuery(
                "UPDATE card SET card_pile = 'active', card_order = $order 
                 WHERE card_id = $cardId AND entity_id = $entityId"
            );
        }

        foreach ($inactiveCardIds as $order => $cardId) {
            $cardId = (int)$cardId;
            $this->game->DbQuery(
                "UPDATE card SET card_pile = 'inactive', card_order = $order 
                 WHERE card_id = $cardId AND entity_id = $entityId"
            );
        }
    }

    /**
     * Get card info by ID (with entity validation)
     */
    public function getCard(int $cardId, int $entityId): ?array
    {
        return $this->game->getObjectFromDB(
            "SELECT card_id, card_name, card_type, card_power, card_pile FROM card 
             WHERE card_id = $cardId AND entity_id = $entityId"
        );
    }
}

