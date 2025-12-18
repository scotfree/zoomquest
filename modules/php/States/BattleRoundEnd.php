<?php

declare(strict_types=1);

namespace Bga\Games\Zoomquest\States;

use Bga\GameFramework\States\GameState;
use Bga\GameFramework\StateType;
use Bga\Games\Zoomquest\Game;

require_once(dirname(__DIR__) . '/constants.inc.php');

/**
 * State: Battle Round End (game state)
 * - Check if one team is eliminated (victory/defeat)
 * - Check if everyone is out of cards (battle ends, no winner)
 * - If battle continues, go back to draw cards
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

    /**
     * Called when entering this state - checks if battle continues
     */
    function onEnteringState()
    {
        $stateHelper = $this->game->getGameStateHelper();
        $combatResolver = $this->game->getCombatResolver();

        $battleId = (int)$stateHelper->get(STATE_CURRENT_BATTLE);

        // Check if one team is eliminated (all defeated)
        $eliminatedTeam = $combatResolver->getEliminatedTeam($battleId);

        if ($eliminatedTeam) {
            if ($eliminatedTeam === 'monsters') {
                $this->notify->all('battleEnd', clienttranslate('The heroes are victorious!'), [
                    'battle_id' => $battleId,
                    'winner' => 'players',
                ]);

                // Update stats for participating players
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

        // Check if everyone is out of cards (battle ends, but no winner)
        if ($combatResolver->isEveryoneOutOfCards($battleId)) {
            $this->notify->all('battleEnd', clienttranslate('All combatants are exhausted. The battle ends in a standoff.'), [
                'battle_id' => $battleId,
                'winner' => null,
            ]);

            return BattleCleanup::class;
        }

        // Battle continues - some participants still have cards
        $combatResolver->resetBattleRound($battleId);

        $this->notify->all('battleContinues', clienttranslate('The battle continues...'), [
            'battle_id' => $battleId,
        ]);

        return BattleDrawCards::class;
    }
}
