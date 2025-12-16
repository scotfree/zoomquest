<?php

declare(strict_types=1);

namespace Bga\Games\Zoomquest\States;

use Bga\GameFramework\States\GameState;
use Bga\GameFramework\StateType;
use Bga\Games\Zoomquest\Game;

require_once(dirname(__DIR__) . '/constants.inc.php');

/**
 * State: Battle Resolve Card (game state)
 * - Resolve the next card in resolution order
 * - Loop until all cards are resolved
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

    /**
     * Called when entering this state - resolves the next card
     */
    function onEnteringState()
    {
        $stateHelper = $this->game->getGameStateHelper();
        $combatResolver = $this->game->getCombatResolver();
        $deck = $this->game->getDeck();

        $battleId = (int)$stateHelper->get(STATE_CURRENT_BATTLE);

        // Get next card to resolve
        $nextCard = $combatResolver->getNextCardToResolve($battleId);

        if (!$nextCard) {
            return BattleRoundEnd::class;
        }

        // Resolve the card
        $resolution = $combatResolver->resolveCard(
            $battleId,
            (int)$nextCard['entity_id'],
            (int)$nextCard['drawn_card_id'],
            $nextCard['card_type']
        );

        // Build message
        $message = $this->buildResolutionMessage($resolution);

        // Get updated deck counts
        $targetDeckCounts = null;
        if ($resolution['target_id']) {
            $targetDeckCounts = $deck->getPileCounts((int)$resolution['target_id']);
        }

        $this->notify->all('cardResolved', $message, [
            'battle_id' => $battleId,
            'entity_id' => $resolution['entity_id'],
            'entity_name' => $resolution['entity_name'],
            'card_type' => $resolution['card_type'],
            'target_id' => $resolution['target_id'],
            'target_name' => $resolution['target_name'] ?? null,
            'effect' => $resolution['effect'],
            'target_defeated' => $resolution['target_defeated'] ?? false,
            'target_deck_counts' => $targetDeckCounts,
        ]);

        // Check if target was defeated
        if (!empty($resolution['target_defeated'])) {
            $this->notify->all('entityDefeated', clienttranslate('${entity_name} has been defeated!'), [
                'entity_id' => $resolution['target_id'],
                'entity_name' => $resolution['target_name'],
            ]);

            $target = $this->game->getObjectFromDB(
                "SELECT entity_type FROM entity WHERE entity_id = {$resolution['target_id']}"
            );
            if ($target && $target['entity_type'] === ENTITY_MONSTER) {
                $this->game->tableStats->inc('monsters_defeated', 1);
            }
        }

        // Continue resolving (loop back to this state)
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
}
