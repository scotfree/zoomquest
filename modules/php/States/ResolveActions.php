<?php

declare(strict_types=1);

namespace Bga\Games\Zoomquest\States;

use Bga\GameFramework\States\GameState;
use Bga\GameFramework\StateType;
use Bga\Games\Zoomquest\Game;

require_once(dirname(__DIR__) . '/constants.inc.php');

/**
 * State: Resolve Actions
 * - Apply all movement actions
 * - Apply all rest actions
 * - Identify battle locations
 * - Transition to battle or victory check
 */
class ResolveActions extends GameState
{
    public function __construct(protected Game $game)
    {
        parent::__construct(
            $game,
            id: ST_RESOLVE_ACTIONS,
            type: StateType::GAME,
        );
    }

    function onEnteringState()
    {
        $stateHelper = $this->game->getGameStateHelper();
        $deck = $this->game->getDeck();

        // Get all action choices
        $choices = $this->game->getCollectionFromDb(
            "SELECT ac.entity_id, ac.action_type, ac.target_location, e.entity_name, e.location_id
             FROM action_choice ac
             JOIN entity e ON ac.entity_id = e.entity_id"
        );

        // Process MOVE actions first
        foreach ($choices as $choice) {
            if ($choice['action_type'] === ACTION_MOVE && $choice['target_location']) {
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

        // Process REST actions
        foreach ($choices as $choice) {
            if ($choice['action_type'] === ACTION_REST) {
                $entityId = (int)$choice['entity_id'];

                // Heal one card from destroyed pile
                $healedCard = $deck->healOne($entityId);

                if ($healedCard) {
                    $this->notify->all('entityRested', clienttranslate('${entity_name} rests and recovers a ${card_type} card'), [
                        'entity_id' => $entityId,
                        'entity_name' => $choice['entity_name'],
                        'card_type' => $healedCard['card_type'],
                        'deck_counts' => $deck->getPileCounts($entityId),
                    ]);

                    // Update player stats if this is a player
                    $entity = $this->game->getObjectFromDB(
                        "SELECT player_id FROM entity WHERE entity_id = $entityId"
                    );
                    if ($entity && $entity['player_id']) {
                        $this->game->playerStats->inc('cards_healed', 1, (int)$entity['player_id']);
                    }
                } else {
                    $this->notify->all('entityRested', clienttranslate('${entity_name} rests but has no cards to recover'), [
                        'entity_id' => $entityId,
                        'entity_name' => $choice['entity_name'],
                        'card_type' => null,
                        'deck_counts' => $deck->getPileCounts($entityId),
                    ]);
                }
            }
        }

        // Find battle locations
        $combatResolver = $this->game->getCombatResolver();
        $battleLocations = $combatResolver->getBattleLocations();

        if (!empty($battleLocations)) {
            // Store battle locations for processing
            $this->game->getGameStateHelper()->set(
                STATE_BATTLES_TO_RESOLVE,
                json_encode($battleLocations)
            );

            return BattleSetup::class;
        }

        // No battles - go to victory check
        return CheckVictory::class;
    }
}

