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

        $this->game->trace("=== SEQUENCE DRAW CARDS (Round $sequenceRound) ===");
        $this->game->trace("Sequence ID: $sequenceId");

        // Clear round resolutions for new round
        $stateHelper->set(STATE_ROUND_RESOLUTIONS, json_encode([]));

        // Log participant status before drawing
        $status = $sequenceResolver->getParticipantStatus($sequenceId);
        $this->game->trace("Participants before draw: " . json_encode($status));

        // Draw cards for all participants (pass sequence round for tag expiration)
        $drawnCards = $sequenceResolver->drawCardsForSequence($sequenceId, $sequenceRound);

        $this->game->trace("Drawn cards: " . json_encode($drawnCards));

        // If no one drew cards, sequence is over
        if (empty($drawnCards)) {
            $this->game->trace("No cards drawn - going to SequenceRoundEnd");
            return SequenceRoundEnd::class;
        }

        $this->notify->all('sequenceCardsDrawn', '', [
            'round' => $sequenceRound,
            'sequence_id' => $sequenceId,
            'drawn_cards' => $drawnCards,
        ]);

        return SequenceResolve::class;
    }
}

