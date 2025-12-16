<?php

declare(strict_types=1);

namespace Bga\Games\Zoomquest\States;

use Bga\GameFramework\States\GameState;
use Bga\GameFramework\StateType;
use Bga\Games\Zoomquest\Game;

require_once(dirname(__DIR__) . '/constants.inc.php');

/**
 * State: Check Victory (game state)
 * - Check if all monsters are defeated (WIN)
 * - Check if all players are defeated (LOSE)
 * - Otherwise continue to next round
 */
class CheckVictory extends GameState
{
    public function __construct(protected Game $game)
    {
        parent::__construct(
            $game,
            id: ST_CHECK_VICTORY,
            type: StateType::GAME,
            updateGameProgression: true,
        );
    }

    /**
     * Called when entering this state - checks victory/defeat conditions
     */
    function onEnteringState()
    {
        $stateHelper = $this->game->getGameStateHelper();

        if ($stateHelper->areAllMonstersDefeated()) {
            $this->notify->all('gameVictory', clienttranslate('All monsters have been defeated! Victory!'), []);

            $players = $this->game->loadPlayersBasicInfos();
            foreach ($players as $playerId => $player) {
                $this->game->DbQuery("UPDATE player SET player_score = 1 WHERE player_id = $playerId");
            }

            return ST_END_GAME;
        }

        if ($stateHelper->areAllPlayersDefeated()) {
            $this->notify->all('gameDefeat', clienttranslate('All heroes have fallen. The quest has failed.'), []);

            $players = $this->game->loadPlayersBasicInfos();
            foreach ($players as $playerId => $player) {
                $this->game->DbQuery("UPDATE player SET player_score = 0 WHERE player_id = $playerId");
            }

            return ST_END_GAME;
        }

        // Game continues - go to next round
        return RoundStart::class;
    }
}
