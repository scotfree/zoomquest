<?php

declare(strict_types=1);

namespace Bga\Games\Zoomquest\States;

use Bga\GameFramework\States\GameState;
use Bga\GameFramework\States\PossibleAction;
use Bga\GameFramework\StateType;
use Bga\Games\Zoomquest\Game;

require_once(dirname(__DIR__) . '/constants.inc.php');

/**
 * State: Action Selection (multipleactiveplayer)
 * - All players simultaneously choose their action: Move, Battle, or Rest
 */
class ActionSelection extends GameState
{
    public function __construct(protected Game $game)
    {
        parent::__construct(
            $game,
            id: ST_ACTION_SELECTION,
            type: StateType::MULTIPLE_ACTIVE_PLAYER,
            description: clienttranslate('Other players must choose their action'),
            descriptionMyTurn: clienttranslate('${you} must choose an action: Move, Battle, or Rest'),
            transitions: [
                'resolve' => ResolveActions::class,
            ],
        );
    }

    /**
     * Provide state arguments for the current player
     * For multipleactiveplayer states, this is called with null for public args
     * and with playerId for private args
     */
    function getArgs(?int $playerId): array
    {
        // If no player ID, return public args (visible to all)
        if ($playerId === null) {
            return [
                'round' => $this->game->getGameStateHelper()->getRound(),
            ];
        }

        $stateHelper = $this->game->getGameStateHelper();

        // Get player's entity
        $entity = $stateHelper->getEntityByPlayerId($playerId);
        if (!$entity) {
            return ['error' => 'No entity found for player'];
        }

        // Get adjacent locations for move options
        $adjacentLocations = $stateHelper->getAdjacentLocations($entity['location_id']);

        // Get current location info
        $currentLocation = [
            'id' => $entity['location_id'],
            'name' => $entity['location_name'],
        ];

        // Check if there are enemies at current location
        $entitiesHere = $this->game->getCombatResolver()->getEntitiesAtLocation($entity['location_id']);
        $hasEnemies = false;
        foreach ($entitiesHere as $e) {
            if ($e['entity_type'] === ENTITY_MONSTER) {
                $hasEnemies = true;
                break;
            }
        }

        // Get deck counts
        $deckCounts = $this->game->getDeck()->getPileCounts((int)$entity['entity_id']);

        return [
            'entity' => $entity,
            'currentLocation' => $currentLocation,
            'adjacentLocations' => $adjacentLocations,
            'hasEnemiesHere' => $hasEnemies,
            'deckCounts' => $deckCounts,
            'currentChoice' => $this->game->getActionChoice((int)$entity['entity_id']),
        ];
    }

    /**
     * Player action: Select action (move/battle/rest)
     */
    #[PossibleAction]
    function actSelectAction(string $actionType, ?string $targetLocation, int $activePlayerId, array $args)
    {
        // Validate action type
        if (!in_array($actionType, [ACTION_MOVE, ACTION_BATTLE, ACTION_REST])) {
            throw new \BgaUserException(clienttranslate("Invalid action type"));
        }

        // Get player's entity
        $entity = $this->game->getGameStateHelper()->getEntityByPlayerId($activePlayerId);
        if (!$entity) {
            throw new \BgaUserException(clienttranslate("No entity found for player"));
        }

        $entityId = (int)$entity['entity_id'];

        // Validate move target if applicable
        if ($actionType === ACTION_MOVE) {
            if (!$targetLocation) {
                throw new \BgaUserException(clienttranslate("Must specify destination for move"));
            }

            // Check if target is adjacent
            $adjacent = $this->game->getGameStateHelper()->getAdjacentLocations($entity['location_id']);
            $adjacentIds = array_column($adjacent, 'location_id');

            if (!in_array($targetLocation, $adjacentIds)) {
                throw new \BgaUserException(clienttranslate("Can only move to adjacent locations"));
            }
        }

        // Record the choice
        $this->game->recordActionChoice($entityId, $actionType, $targetLocation);

        // Notify the player
        $this->notify->all('actionSelected', clienttranslate('${player_name} has chosen their action'), [
            'player_id' => $activePlayerId,
            'player_name' => $this->game->getPlayerNameById($activePlayerId),
        ]);

        // Deactivate this player - when all players deactivated, framework transitions to next state
        $this->game->gamestate->setPlayerNonMultiactive($activePlayerId, 'resolve');

        return null; // Stay in this state until all players have acted
    }

    /**
     * Handle zombie (abandoned) player - default to rest action
     */
    function zombie(int $playerId)
    {
        $entity = $this->game->getGameStateHelper()->getEntityByPlayerId($playerId);
        if ($entity) {
            $this->game->recordActionChoice((int)$entity['entity_id'], ACTION_REST);
        }
        $this->game->gamestate->setPlayerNonMultiactive($playerId, 'resolve');
        return null;
    }

    /**
     * Called when all players have finished - transition to ResolveActions
     */
    function onAllPlayersNonMultiactive(): string
    {
        return ResolveActions::class;
    }
}
