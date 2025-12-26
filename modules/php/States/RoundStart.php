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
 * - Clear previous move choices
 * - Activate all players for move selection
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
     * Called when entering this state - increments round and sets up for move selection
     */
    function onEnteringState()
    {
        $stateHelper = $this->game->getGameStateHelper();
        $goalTracker = $this->game->getGoalTracker();
        $deck = $this->game->getDeck();

        // Increment round
        $round = $stateHelper->incrementRound();

        // Clear previous move choices
        $this->game->clearMoveChoices();

        // Track goal progress for each player (turns at location, location visits)
        $playerEntities = $stateHelper->getPlayerEntities();
        foreach ($playerEntities as $entity) {
            if ($entity['is_defeated']) continue;
            
            $playerId = (int)$entity['player_id'];
            $locationId = $entity['location_id'];

            // Track turn at this location (for terrain/direction goals)
            $goalTracker->trackTurnAtLocation($playerId, $locationId);

            // Track location visit (for explorer goal)
            $goalTracker->recordLocationVisit($playerId, $locationId);

            // Refresh deck (move discard to active)
            $deck->refreshDeck((int)$entity['entity_id']);
        }

        // Build goal progress for each player
        $goalProgressByPlayer = [];
        foreach ($playerEntities as $entity) {
            if ($entity['is_defeated']) continue;
            $playerId = (int)$entity['player_id'];
            $goalProgressByPlayer[$playerId] = [
                'progress' => $goalTracker->getGoalProgress($playerId),
                'complete' => $goalTracker->isGoalComplete($playerId),
            ];
        }

        // Notify players of new round (with private goal progress)
        foreach ($goalProgressByPlayer as $playerId => $goalData) {
            $this->notify->player($playerId, 'roundStart', clienttranslate('Round ${round} begins'), [
                'round' => $round,
                'goal_progress' => $goalData['progress'],
                'goal_complete' => $goalData['complete'],
            ]);
        }

        // Update table stats
        $this->game->tableStats->set('rounds_played', $round);

        // Activate all non-defeated players for the move selection phase
        $this->game->gamestate->setAllPlayersMultiactive();

        // Transition to move selection
        return MoveSelection::class;
    }
}
