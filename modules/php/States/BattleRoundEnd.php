<?php

declare(strict_types=1);

namespace Bga\Games\Zoomquest\States;

use Bga\GameFramework\States\GameState;
use Bga\GameFramework\StateType;
use Bga\Games\Zoomquest\Game;

require_once(dirname(__DIR__) . '/constants.inc.php');

/**
 * State: Battle Round End
 * - Check if one team is eliminated
 * - If battle continues, go back to draw cards
 * - If battle ends, go to cleanup
 */
class BattleRoundEnd extends GameState
{
    public function __construct(protected Game $game)
    {
        parent::__construct(
            $game,
            id: ST_BATTLE_ROUND_END,
            type: StateType::GAME,
        );
    }

    function onEnteringState()
    {
        $stateHelper = $this->game->getGameStateHelper();
        $combatResolver = $this->game->getCombatResolver();

        $battleId = (int)$stateHelper->get(STATE_CURRENT_BATTLE);

        // Check if one team is eliminated
        $eliminatedTeam = $combatResolver->getEliminatedTeam($battleId);

        if ($eliminatedTeam) {
            // Battle is over
            if ($eliminatedTeam === 'monsters') {
                $this->notify->all('battleEnd', clienttranslate('The heroes are victorious!'), [
                    'battle_id' => $battleId,
                    'winner' => 'players',
                ]);

                // Update battle won stats for participating players
                $participants = $this->game->getObjectListFromDB(
                    "SELECT e.player_id FROM battle_participant bp
                     JOIN entity e ON bp.entity_id = e.entity_id
                     WHERE bp.battle_id = $battleId AND e.player_id IS NOT NULL AND e.is_defeated = 0"
                );
                foreach ($participants as $p) {
                    $this->game->playerStats->inc('battles_won', 1, (int)$p['player_id']);
                }
            } else {
                $this->notify->all('battleEnd', clienttranslate('The heroes have fallen...'), [
                    'battle_id' => $battleId,
                    'winner' => 'monsters',
                ]);
            }

            return BattleCleanup::class;
        }

        // Battle continues - reset for next round
        $combatResolver->resetBattleRound($battleId);

        $this->notify->all('battleContinues', clienttranslate('The battle continues...'), [
            'battle_id' => $battleId,
        ]);

        return BattleDrawCards::class;
    }
}

