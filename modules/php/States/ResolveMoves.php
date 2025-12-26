<?php

declare(strict_types=1);

namespace Bga\Games\Zoomquest\States;

use Bga\GameFramework\States\GameState;
use Bga\GameFramework\StateType;
use Bga\Games\Zoomquest\Game;

require_once(dirname(__DIR__) . '/constants.inc.php');

/**
 * State: Resolve Moves (game state)
 * - Apply all movement
 * - Refresh decks (discard → bottom of active)
 * - Find sequence locations
 */
class ResolveMoves extends GameState
{
    public function __construct(protected Game $game)
    {
        parent::__construct(
            $game,
            id: ST_RESOLVE_MOVES,
            type: StateType::GAME,
        );
    }

    /**
     * Called when entering this state
     */
    function onEnteringState()
    {
        $stateHelper = $this->game->getGameStateHelper();
        $deck = $this->game->getDeck();

        // Get all move choices
        $choices = $this->game->getCollectionFromDb(
            "SELECT mc.player_id, mc.target_location, e.entity_id, e.entity_name, e.location_id
             FROM move_choice mc
             JOIN entity e ON e.player_id = mc.player_id"
        );

        // Process movements
        foreach ($choices as $choice) {
            if ($choice['target_location']) {
                $entityId = (int)$choice['entity_id'];
                $targetLocation = $choice['target_location'];

                // Move the entity
                $stateHelper->moveEntity($entityId, $targetLocation);

                // Get location name for notification
                $locationName = $this->game->getUniqueValueFromDB(
                    "SELECT location_name FROM location WHERE location_id = '" . addslashes($targetLocation) . "'"
                );

                $this->notify->all('entityMoved', clienttranslate('${entity_name} moves to ${location_name}'), [
                    'entity_id' => $entityId,
                    'entity_name' => $choice['entity_name'],
                    'from_location' => $choice['location_id'],
                    'to_location' => $targetLocation,
                    'location_name' => $locationName,
                ]);
            }
        }

        // Refresh all entity decks (discard → bottom of active, maintaining order)
        $allEntities = $stateHelper->getAllEntities();
        foreach ($allEntities as $entity) {
            if ($entity['is_defeated'] == 0) {
                $deck->refreshDeck((int)$entity['entity_id']);
            }
        }

        // Clear move choices
        $this->game->clearMoveChoices();

        // Find sequence locations (where players are with hostiles)
        $sequenceResolver = $this->game->getActionSequenceResolver();
        $sequenceLocations = $sequenceResolver->getSequenceLocations();

        if (!empty($sequenceLocations)) {
            $stateHelper->set(
                STATE_SEQUENCES_TO_RESOLVE,
                json_encode($sequenceLocations)
            );
            return SequenceSetup::class;
        } else {
            return CheckVictory::class;
        }
    }
}

