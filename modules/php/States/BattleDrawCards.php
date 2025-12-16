<?php

declare(strict_types=1);

namespace Bga\Games\Zoomquest\States;

use Bga\GameFramework\States\GameState;
use Bga\GameFramework\StateType;
use Bga\Games\Zoomquest\Game;

require_once(dirname(__DIR__) . '/constants.inc.php');

/**
 * State: Battle Draw Cards
 * - Each participant draws their top card
 * - Determine resolution order (random)
 * - Transition to card resolution
 */
class BattleDrawCards extends GameState
{
    public function __construct(protected Game $game)
    {
        parent::__construct(
            $game,
            id: ST_BATTLE_DRAW_CARDS,
            type: StateType::GAME,
        );
    }

    function onEnteringState()
    {
        $stateHelper = $this->game->getGameStateHelper();
        $combatResolver = $this->game->getCombatResolver();

        $battleId = (int)$stateHelper->get(STATE_CURRENT_BATTLE);

        // Have all participants draw cards and get resolution order
        $drawnCards = $combatResolver->drawCardsForBattle($battleId);

        if (empty($drawnCards)) {
            // No cards drawn - battle should end (shouldn't normally happen)
            return BattleRoundEnd::class;
        }

        // Build notification data
        $cardData = [];
        foreach ($drawnCards as $card) {
            $cardData[] = [
                'entity_id' => $card['entity_id'],
                'entity_name' => $card['entity_name'],
                'entity_type' => $card['entity_type'],
                'card_type' => $card['card_type'],
                'resolution_order' => $card['resolution_order'],
            ];
        }

        // Sort by resolution order for display
        usort($cardData, fn($a, $b) => $a['resolution_order'] <=> $b['resolution_order']);

        $this->notify->all('battleCardsDrawn', clienttranslate('All combatants draw their cards'), [
            'battle_id' => $battleId,
            'cards' => $cardData,
        ]);

        return BattleResolveCard::class;
    }
}

