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
            description: clienttranslate('Waiting for other players to choose their action'),
            descriptionMyTurn: clienttranslate('${you} must choose an action: Move, Battle, or Rest'),
            transitions: [
                'resolve' => ResolveActions::class,
            ],
        );
    }

    /**
     * Provide state arguments
     * Include all player data keyed by player ID so client can extract their own
     */
    function getArgs(?int $playerId): array
    {
        $stateHelper = $this->game->getGameStateHelper();
        
        // Build args for all players (each player will extract their own on client)
        $playerData = [];
        $players = $this->game->loadPlayersBasicInfos();
        
        foreach ($players as $pid => $player) {
            $entity = $stateHelper->getEntityByPlayerId($pid);
            if (!$entity) {
                continue;
            }

            // Get adjacent locations for move options
            $adjacentLocations = $stateHelper->getAdjacentLocations($entity['location_id']);

            // Get current location info
            $currentLocation = [
                'id' => $entity['location_id'],
                'name' => $entity['location_name'] ?? $entity['location_id'],
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

            $playerData[$pid] = [
                'entity' => $entity,
                'currentLocation' => $currentLocation,
                'adjacentLocations' => $adjacentLocations,
                'hasEnemiesHere' => $hasEnemies,
                'deckCounts' => $deckCounts,
                'currentChoice' => $this->game->getActionChoice((int)$entity['entity_id']),
            ];
        }

        return [
            'round' => $stateHelper->getRound(),
            'playerData' => $playerData,
        ];
    }

    /**
     * Player action: Select action (move/battle/rest)
     */
    #[PossibleAction]
    function actSelectAction(string $actionType, ?string $targetLocation, int $activePlayerId, array $args)
    {
        // For multiactive states, get the actual calling player (not the framework's activePlayerId)
        $playerId = (int)$this->game->getCurrentPlayerId();
        
        // Validate action type
        if (!in_array($actionType, [ACTION_MOVE, ACTION_BATTLE, ACTION_REST])) {
            throw new \BgaUserException(clienttranslate("Invalid action type"));
        }

        // Get player's entity
        $entity = $this->game->getGameStateHelper()->getEntityByPlayerId($playerId);
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
            'player_id' => $playerId,
            'player_name' => $this->game->getPlayerNameById($playerId),
        ]);

        // Deactivate this player - when all players deactivated, framework transitions to next state
        $this->game->gamestate->setPlayerNonMultiactive($playerId, 'resolve');

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
