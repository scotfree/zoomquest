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
        );
    }

    function getArgs(int $activePlayerId): array
    {
        $stateHelper = $this->game->getGameStateHelper();

        // Get player's entity
        $entity = $stateHelper->getEntityByPlayerId($activePlayerId);
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

        // Check if there are enemies at current location (for battle option relevance)
        $entitiesHere = $this->game->getCombatResolver()->getEntitiesAtLocation($entity['location_id']);
        $hasEnemies = false;
        foreach ($entitiesHere as $e) {
            if ($e['entity_type'] === ENTITY_MONSTER) {
                $hasEnemies = true;
                break;
            }
        }

        // Get deck counts for display
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
        $actionNames = [
            ACTION_MOVE => clienttranslate('Move'),
            ACTION_BATTLE => clienttranslate('Battle'),
            ACTION_REST => clienttranslate('Rest'),
        ];

        $message = $actionType === ACTION_MOVE
            ? clienttranslate('${player_name} has chosen their action')
            : clienttranslate('${player_name} has chosen their action');

        $this->notify->all('actionSelected', $message, [
            'player_id' => $activePlayerId,
            'player_name' => $this->game->getPlayerNameById($activePlayerId),
        ]);

        // Deactivate this player (they've made their choice)
        $this->game->gamestate->setPlayerNonMultiactive($activePlayerId, '');

        // Check if all players have chosen
        if ($this->game->haveAllPlayersChosen()) {
            // Auto-submit monster actions
            $this->game->submitMonsterActions();
            return ResolveActions::class;
        }

        // Stay in this state, waiting for other players
        return null;
    }

    /**
     * Called when a player times out or becomes zombie
     */
    function zombie(int $playerId)
    {
        // Default to rest for zombie players
        $entity = $this->game->getGameStateHelper()->getEntityByPlayerId($playerId);
        if ($entity) {
            $this->game->recordActionChoice((int)$entity['entity_id'], ACTION_REST);
        }
        $this->game->gamestate->setPlayerNonMultiactive($playerId, '');

        if ($this->game->haveAllPlayersChosen()) {
            $this->game->submitMonsterActions();
            return ResolveActions::class;
        }

        return null;
    }
}

