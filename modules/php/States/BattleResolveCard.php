<?php

declare(strict_types=1);

namespace Bga\Games\Zoomquest\States;

use Bga\GameFramework\States\GameState;
use Bga\GameFramework\StateType;
use Bga\Games\Zoomquest\Game;

require_once(dirname(__DIR__) . '/constants.inc.php');

/**
 * State: Battle Resolve Card
 * - Resolve the next card in resolution order
 * - Loop until all cards are resolved
 * - Then transition to round end check
 */
class BattleResolveCard extends GameState
{
    public function __construct(protected Game $game)
    {
        parent::__construct(
            $game,
            id: ST_BATTLE_RESOLVE_CARD,
            type: StateType::GAME,
        );
    }

    function onEnteringState()
    {
        $stateHelper = $this->game->getGameStateHelper();
        $combatResolver = $this->game->getCombatResolver();
        $deck = $this->game->getDeck();

        $battleId = (int)$stateHelper->get(STATE_CURRENT_BATTLE);

        // Get next card to resolve
        $nextCard = $combatResolver->getNextCardToResolve($battleId);

        if (!$nextCard) {
            // All cards resolved this round
            return BattleRoundEnd::class;
        }

        // Resolve the card
        $resolution = $combatResolver->resolveCard(
            $battleId,
            (int)$nextCard['entity_id'],
            (int)$nextCard['drawn_card_id'],
            $nextCard['card_type']
        );

        // Build notification message based on card type
        $message = $this->buildResolutionMessage($resolution);

        // Get updated deck counts
        $entityDeckCounts = $deck->getPileCounts((int)$nextCard['entity_id']);
        $targetDeckCounts = null;
        if ($resolution['target_id']) {
            $targetDeckCounts = $deck->getPileCounts((int)$resolution['target_id']);
        }

        // Update player stats
        $this->updateStats($resolution);

        $this->notify->all('cardResolved', $message, [
            'battle_id' => $battleId,
            'entity_id' => $resolution['entity_id'],
            'entity_name' => $resolution['entity_name'],
            'card_type' => $resolution['card_type'],
            'target_id' => $resolution['target_id'],
            'target_name' => $resolution['target_name'] ?? null,
            'effect' => $resolution['effect'],
            'target_defeated' => $resolution['target_defeated'] ?? false,
            'entity_deck_counts' => $entityDeckCounts,
            'target_deck_counts' => $targetDeckCounts,
        ]);

        // Check if target was defeated
        if (!empty($resolution['target_defeated'])) {
            $this->notify->all('entityDefeated', clienttranslate('${entity_name} has been defeated!'), [
                'entity_id' => $resolution['target_id'],
                'entity_name' => $resolution['target_name'],
            ]);

            // Update monster defeated stat if applicable
            $target = $this->game->getObjectFromDB(
                "SELECT entity_type FROM entity WHERE entity_id = {$resolution['target_id']}"
            );
            if ($target && $target['entity_type'] === ENTITY_MONSTER) {
                $this->game->tableStats->inc('monsters_defeated', 1);
            }
        }

        // Continue resolving cards (loop back to this state)
        return BattleResolveCard::class;
    }

    private function buildResolutionMessage(array $resolution): string
    {
        switch ($resolution['card_type']) {
            case CARD_ATTACK:
                if ($resolution['effect'] === 'destroy') {
                    return clienttranslate('${entity_name} attacks ${target_name}!');
                }
                return clienttranslate('${entity_name} attacks but finds no target');

            case CARD_DEFEND:
                if ($resolution['effect'] === 'defend') {
                    return clienttranslate('${entity_name} defends ${target_name}');
                }
                return clienttranslate('${entity_name} defends but finds no one to protect');

            case CARD_HEAL:
                if ($resolution['effect'] === 'heal') {
                    return clienttranslate('${entity_name} heals ${target_name}');
                }
                return clienttranslate('${entity_name} tries to heal but there are no cards to recover');

            default:
                return clienttranslate('${entity_name} plays ${card_type}');
        }
    }

    private function updateStats(array $resolution): void
    {
        // Get entity info
        $entity = $this->game->getObjectFromDB(
            "SELECT player_id, entity_type FROM entity WHERE entity_id = {$resolution['entity_id']}"
        );

        if (!$entity || !$entity['player_id']) {
            return; // Not a player entity
        }

        $playerId = (int)$entity['player_id'];

        switch ($resolution['card_type']) {
            case CARD_ATTACK:
                if ($resolution['effect'] === 'destroy') {
                    $this->game->playerStats->inc('cards_destroyed', 1, $playerId);
                }
                break;

            case CARD_HEAL:
                if ($resolution['effect'] === 'heal') {
                    $this->game->playerStats->inc('cards_healed', 1, $playerId);
                }
                break;
        }

        // Track cards lost for target if it's a player
        if ($resolution['target_id'] && $resolution['effect'] === 'destroy') {
            $target = $this->game->getObjectFromDB(
                "SELECT player_id FROM entity WHERE entity_id = {$resolution['target_id']}"
            );
            if ($target && $target['player_id']) {
                $this->game->playerStats->inc('cards_lost', 1, (int)$target['player_id']);
            }
        }
    }
}

