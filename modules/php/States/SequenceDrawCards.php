<?php

declare(strict_types=1);

namespace Bga\Games\Zoomquest\States;

use Bga\GameFramework\States\GameState;
use Bga\GameFramework\StateType;
use Bga\Games\Zoomquest\Game;

require_once(dirname(__DIR__) . '/constants.inc.php');

/**
 * State: Sequence Draw Cards (game state)
 * - Each participant draws a card
 * - Targets are assigned (snapshot)
 */
class SequenceDrawCards extends GameState
{
    public function __construct(protected Game $game)
    {
        parent::__construct(
            $game,
            id: ST_SEQUENCE_DRAW_CARDS,
            type: StateType::GAME,
        );
    }

    /**
     * Called when entering this state - draws cards for all participants
     */
    function onEnteringState()
    {
        $stateHelper = $this->game->getGameStateHelper();
        $sequenceResolver = $this->game->getActionSequenceResolver();

        $sequenceId = (int)$stateHelper->get(STATE_CURRENT_SEQUENCE);
        
        // Increment sequence round
        $sequenceRound = (int)$stateHelper->get(STATE_SEQUENCE_ROUND) + 1;
        $stateHelper->set(STATE_SEQUENCE_ROUND, (string)$sequenceRound);

        // Clear round resolutions for new round
        $stateHelper->set(STATE_ROUND_RESOLUTIONS, json_encode([]));

        // Draw cards for all participants (pass sequence round for tag expiration)
        $drawnCards = $sequenceResolver->drawCardsForSequence($sequenceId, $sequenceRound);

        // If no one drew cards, sequence is over
        if (empty($drawnCards)) {
            return SequenceRoundEnd::class;
        }

        $this->notify->all('sequenceCardsDrawn', clienttranslate('Round ${round}: All entities draw cards'), [
            'round' => $sequenceRound,
            'sequence_id' => $sequenceId,
            'drawn_cards' => $drawnCards,
        ]);

        return SequenceResolve::class;
    }
}

