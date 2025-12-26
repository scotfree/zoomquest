<?php

declare(strict_types=1);

namespace Bga\Games\Zoomquest\States;

use Bga\GameFramework\States\GameState;
use Bga\GameFramework\StateType;
use Bga\Games\Zoomquest\Game;

require_once(dirname(__DIR__) . '/constants.inc.php');

/**
 * State: Sequence Setup (game state)
 * - Pick the next sequence location
 * - Create sequence record and participants
 */
class SequenceSetup extends GameState
{
    public function __construct(protected Game $game)
    {
        parent::__construct(
            $game,
            id: ST_SEQUENCE_SETUP,
            type: StateType::GAME,
        );
    }

    /**
     * Called when entering this state - sets up the next sequence
     */
    function onEnteringState()
    {
        $stateHelper = $this->game->getGameStateHelper();
        $sequenceResolver = $this->game->getActionSequenceResolver();

        // Get remaining sequence locations
        $sequencesJson = $stateHelper->get(STATE_SEQUENCES_TO_RESOLVE);
        $sequenceLocations = $sequencesJson ? json_decode($sequencesJson, true) : [];

        if (empty($sequenceLocations)) {
            return CheckVictory::class;
        }

        // Pop the next sequence location
        $locationId = array_shift($sequenceLocations);
        $stateHelper->set(STATE_SEQUENCES_TO_RESOLVE, json_encode($sequenceLocations));

        // Create the sequence
        $sequenceId = $sequenceResolver->createSequence($locationId);
        $stateHelper->set(STATE_CURRENT_SEQUENCE, (string)$sequenceId);

        // Initialize sequence round counter
        $stateHelper->set(STATE_SEQUENCE_ROUND, '0');
        $stateHelper->set(STATE_ROUND_RESOLUTIONS, json_encode([]));

        // Get location name and participants
        $locationName = $this->game->getUniqueValueFromDB(
            "SELECT location_name FROM location WHERE location_id = '" . addslashes($locationId) . "'"
        );

        $participants = $sequenceResolver->getEntitiesAtLocation($locationId);

        // Check if there are any hostile pairs - if not, skip sequence
        $hasHostiles = false;
        foreach ($participants as $p1) {
            foreach ($participants as $p2) {
                if ($p1['entity_id'] !== $p2['entity_id']) {
                    $rel = $sequenceResolver->getRelationship($p1['faction'], $p2['faction']);
                    if ($rel === RELATION_HOSTILE) {
                        $hasHostiles = true;
                        break 2;
                    }
                }
            }
        }

        if (!$hasHostiles) {
            // No hostiles here, skip this location
            return SequenceSetup::class; // Try next location
        }

        $this->notify->all('sequenceStart', clienttranslate('Action sequence begins at ${location_name}!'), [
            'sequence_id' => $sequenceId,
            'location_id' => $locationId,
            'location_name' => $locationName,
            'participants' => $participants,
        ]);

        return SequenceDrawCards::class;
    }
}

