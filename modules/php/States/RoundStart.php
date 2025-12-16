<?php

declare(strict_types=1);

namespace Bga\Games\Zoomquest\States;

use Bga\GameFramework\States\GameState;
use Bga\GameFramework\StateType;
use Bga\Games\Zoomquest\Game;

require_once(dirname(__DIR__) . '/constants.inc.php');

/**
 * State: Round Start (game state)
 * - Increment round counter
 * - Clear previous action choices
 * - Activate all players for action selection
 */
class RoundStart extends GameState
{
    public function __construct(protected Game $game)
    {
        parent::__construct(
            $game,
            id: ST_ROUND_START,
            type: StateType::GAME,
        );
    }

    /**
     * Called when entering this state - increments round and sets up for action selection
     */
    function onEnteringState()
    {
        $stateHelper = $this->game->getGameStateHelper();

        // Increment round
        $round = $stateHelper->incrementRound();

        // Clear previous action choices
        $this->game->clearActionChoices();

        // Notify players of new round
        $this->notify->all('roundStart', clienttranslate('Round ${round} begins'), [
            'round' => $round,
        ]);

        // Update table stats
        $this->game->tableStats->set('rounds_played', $round);

        // Activate all non-defeated players for the action selection phase
        $this->game->gamestate->setAllPlayersMultiactive();

        // Transition to action selection
        return ActionSelection::class;
    }
}
