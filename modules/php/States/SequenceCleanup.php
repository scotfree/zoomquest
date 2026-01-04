<?php

declare(strict_types=1);

namespace Bga\Games\Zoomquest\States;

use Bga\GameFramework\States\GameState;
use Bga\GameFramework\StateType;
use Bga\Games\Zoomquest\Game;

require_once(dirname(__DIR__) . '/constants.inc.php');

/**
 * State: Sequence Cleanup (game state)
 * - Mark sequence as resolved
 * - Check for more sequences or proceed to victory check
 */
class SequenceCleanup extends GameState
{
    public function __construct(protected Game $game)
    {
        parent::__construct(
            $game,
            id: ST_SEQUENCE_CLEANUP,
            type: StateType::GAME,
        );
    }

    /**
     * Called when entering this state - cleans up the sequence
     */
    function onEnteringState()
    {
        $stateHelper = $this->game->getGameStateHelper();
        $sequenceResolver = $this->game->getActionSequenceResolver();
        $deck = $this->game->getDeck();

        $sequenceId = (int)$stateHelper->get(STATE_CURRENT_SEQUENCE);

        // Get all participants to send cleanup info
        $participants = $this->game->getObjectListFromDB(
            "SELECT sp.entity_id, e.entity_name, e.entity_type, e.is_defeated
             FROM sequence_participant sp
             JOIN entity e ON sp.entity_id = e.entity_id
             WHERE sp.sequence_id = $sequenceId"
        );

        $survivors = [];
        $defeated = [];
        foreach ($participants as $p) {
            $entityId = (int)$p['entity_id'];
            if ($p['is_defeated'] == 1) {
                $defeated[] = [
                    'entity_id' => $entityId,
                    'entity_name' => $p['entity_name'],
                    'entity_type' => $p['entity_type'],
                ];
            } else {
                $survivors[] = [
                    'entity_id' => $entityId,
                    'entity_name' => $p['entity_name'],
                    'entity_type' => $p['entity_type'],
                    'deck_counts' => $deck->getPileCounts($entityId),
                ];
            }
        }

        // Send cleanup notification
        $this->notify->all('sequenceCleanup', '', [
            'sequence_id' => $sequenceId,
            'survivors' => $survivors,
            'defeated' => $defeated,
        ]);

        // Log the sequence to location history
        $sequence = $this->game->getObjectFromDB(
            "SELECT location_id FROM action_sequence WHERE sequence_id = $sequenceId"
        );
        if ($sequence) {
            $gameRound = $stateHelper->getRound();
            
            // Get the accumulated sequence log (detailed round-by-round actions)
            $sequenceLogJson = $stateHelper->get(STATE_SEQUENCE_LOG);
            $sequenceLog = $sequenceLogJson ? json_decode($sequenceLogJson, true) : [];
            
            $logData = [
                'participants' => $participants,
                'survivors' => $survivors,
                'defeated' => $defeated,
                'rounds' => $sequenceLog, // Detailed round-by-round actions
            ];
            $logId = $this->game->getLocationLog()->addSequenceSummary($sequence['location_id'], $gameRound, $logData);
            
            // Notify clients to update their location logs
            $this->notify->all('locationLogAdded', '', [
                'location_id' => $sequence['location_id'],
                'round' => $gameRound,
                'log_type' => 'sequence',
                'log_data' => $logData,
                'log_id' => $logId,
            ]);
        }

        // End the sequence (applies poison damage, clears non-persistent markers)
        $endResults = $sequenceResolver->endSequence($sequenceId);
        
        // Notify about poison damage
        if (!empty($endResults)) {
            $this->notify->all('sequenceEndEffects', '', [
                'sequence_id' => $sequenceId,
                'effects' => $endResults,
            ]);
        }

        // Check for more sequences
        $sequencesJson = $stateHelper->get(STATE_SEQUENCES_TO_RESOLVE);
        $sequenceLocations = $sequencesJson ? json_decode($sequencesJson, true) : [];

        if (!empty($sequenceLocations)) {
            return SequenceSetup::class;
        }

        return CheckVictory::class;
    }
}

