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
     * @return array|null The drawn card or null if deck is empty
     */
    public function drawTop(int $entityId): ?array
    {
        // First check if we need to reshuffle
        $this->checkReshuffle($entityId);

        $card = $this->game->getObjectFromDB(
            "SELECT card_id, card_type FROM card 
             WHERE entity_id = $entityId AND card_pile = 'active' 
             ORDER BY card_order ASC LIMIT 1"
        );

        return $card ?: null;
    }

    /**
     * Check if active deck is empty and reshuffle discard if needed
     */
    private function checkReshuffle(int $entityId): void
    {
        $activeCount = (int)$this->game->getUniqueValueFromDB(
            "SELECT COUNT(*) FROM card WHERE entity_id = $entityId AND card_pile = 'active'"
        );

        if ($activeCount === 0) {
            // Move all discard to active
            $this->game->DbQuery(
                "UPDATE card SET card_pile = 'active' WHERE entity_id = $entityId AND card_pile = 'discard'"
            );
            // Shuffle the new active deck
            $this->shuffleActive($entityId);
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
     * Destroy a random card from active deck (attack effect)
     * @return array|null The destroyed card or null if no active cards
     */
    public function destroyRandomActive(int $entityId): ?array
    {
        $card = $this->game->getObjectFromDB(
            "SELECT card_id, card_type FROM card 
             WHERE entity_id = $entityId AND card_pile = 'active' 
             ORDER BY RAND() LIMIT 1"
        );

        if ($card) {
            $this->destroy((int)$card['card_id']);
        }

        return $card;
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
     * Shuffle discard back into active deck (end of battle for survivors)
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

