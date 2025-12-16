<?php

declare(strict_types=1);

namespace Bga\Games\Zoomquest\States;

use Bga\GameFramework\States\GameState;
use Bga\GameFramework\StateType;
use Bga\Games\Zoomquest\Game;

require_once(dirname(__DIR__) . '/constants.inc.php');

/**
 * State: Battle Cleanup
 * - Survivors shuffle discard back into active deck
 * - Check if more battles to resolve
 * - Transition appropriately
 */
class BattleCleanup extends GameState
{
    public function __construct(protected Game $game)
    {
        parent::__construct(
            $game,
            id: ST_BATTLE_CLEANUP,
            type: StateType::GAME,
        );
    }

    function onEnteringState()
    {
        $stateHelper = $this->game->getGameStateHelper();
        $combatResolver = $this->game->getCombatResolver();
        $deck = $this->game->getDeck();

        $battleId = (int)$stateHelper->get(STATE_CURRENT_BATTLE);

        // End the battle (shuffles discard for survivors)
        $combatResolver->endBattle($battleId);

        // Get survivor info for notification
        $survivors = $this->game->getObjectListFromDB(
            "SELECT e.entity_id, e.entity_name
             FROM battle_participant bp
             JOIN entity e ON bp.entity_id = e.entity_id
             WHERE bp.battle_id = $battleId AND e.is_defeated = 0"
        );

        $survivorData = [];
        foreach ($survivors as $s) {
            $survivorData[] = [
                'entity_id' => $s['entity_id'],
                'entity_name' => $s['entity_name'],
                'deck_counts' => $deck->getPileCounts((int)$s['entity_id']),
            ];
        }

        $this->notify->all('battleCleanup', clienttranslate('Survivors regroup'), [
            'battle_id' => $battleId,
            'survivors' => $survivorData,
        ]);

        // Check if more battles remain
        $battlesJson = $stateHelper->get(STATE_BATTLES_TO_RESOLVE);
        $battleLocations = $battlesJson ? json_decode($battlesJson, true) : [];

        if (!empty($battleLocations)) {
            return BattleSetup::class;
        }

        return CheckVictory::class;
    }
}

