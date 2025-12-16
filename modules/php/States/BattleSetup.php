<?php

declare(strict_types=1);

namespace Bga\Games\Zoomquest\States;

use Bga\GameFramework\States\GameState;
use Bga\GameFramework\StateType;
use Bga\Games\Zoomquest\Game;

require_once(dirname(__DIR__) . '/constants.inc.php');

/**
 * State: Battle Setup
 * - Pick the next battle location
 * - Create battle record and participants
 * - Transition to card drawing
 */
class BattleSetup extends GameState
{
    public function __construct(protected Game $game)
    {
        parent::__construct(
            $game,
            id: ST_BATTLE_SETUP,
            type: StateType::GAME,
        );
    }

    function onEnteringState()
    {
        $stateHelper = $this->game->getGameStateHelper();
        $combatResolver = $this->game->getCombatResolver();

        // Get remaining battle locations
        $battlesJson = $stateHelper->get(STATE_BATTLES_TO_RESOLVE);
        $battleLocations = $battlesJson ? json_decode($battlesJson, true) : [];

        if (empty($battleLocations)) {
            // No more battles
            return CheckVictory::class;
        }

        // Pop the next battle location
        $locationId = array_shift($battleLocations);
        $stateHelper->set(STATE_BATTLES_TO_RESOLVE, json_encode($battleLocations));

        // Create the battle
        $battleId = $combatResolver->createBattle($locationId);
        $stateHelper->set(STATE_CURRENT_BATTLE, (string)$battleId);

        // Get location name and participants for notification
        $locationName = $this->game->getUniqueValueFromDB(
            "SELECT location_name FROM location WHERE location_id = '" . addslashes($locationId) . "'"
        );

        $participants = $combatResolver->getEntitiesAtLocation($locationId);
        $participantNames = array_column($participants, 'entity_name');

        $this->notify->all('battleStart', clienttranslate('Battle begins at ${location_name}!'), [
            'battle_id' => $battleId,
            'location_id' => $locationId,
            'location_name' => $locationName,
            'participants' => $participants,
            'participant_names' => implode(', ', $participantNames),
        ]);

        return BattleDrawCards::class;
    }
}

