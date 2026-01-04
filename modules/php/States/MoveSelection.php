<?php

declare(strict_types=1);

namespace Bga\Games\Zoomquest\States;

use Bga\GameFramework\States\GameState;
use Bga\GameFramework\States\PossibleAction;
use Bga\GameFramework\StateType;
use Bga\Games\Zoomquest\Game;

require_once(dirname(__DIR__) . '/constants.inc.php');

/**
 * State: Move Selection (multipleactiveplayer)
 * - All players simultaneously click a location to move or stay
 * - Click adjacent = move there
 * - Click current = stay and rearrange deck
 */
class MoveSelection extends GameState
{
    public function __construct(protected Game $game)
    {
        parent::__construct(
            $game,
            id: ST_MOVE_SELECTION,
            type: StateType::MULTIPLE_ACTIVE_PLAYER,
            description: clienttranslate('Waiting for other players to choose movement'),
            descriptionMyTurn: clienttranslate('${you} must click a location to move (or click current location to stay)'),
            transitions: [
                'resolve' => ResolveMoves::class,
            ],
        );
    }

    /**
     * Provide state arguments
     */
    function getArgs(?int $playerId): array
    {
        $stateHelper = $this->game->getGameStateHelper();
        
        // Build args for all players
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

            // Check for hostiles at current location
            $sequenceResolver = $this->game->getActionSequenceResolver();
            $entitiesHere = $sequenceResolver->getEntitiesAtLocation($entity['location_id']);
            $entityFaction = $entity['faction'] ?? 'players';
            $hasHostiles = false;
            foreach ($entitiesHere as $e) {
                if ((int)$e['entity_id'] === (int)$entity['entity_id']) continue;
                $rel = $sequenceResolver->getRelationship($entityFaction, $e['faction']);
                if ($rel === RELATION_HOSTILE) {
                    $hasHostiles = true;
                    break;
                }
            }

            // Get active and inactive cards for Plan popup (shown when staying)
            $entityId = (int)$entity['entity_id'];
            $deck = $this->game->getDeck();
            $activeCards = $deck->getActiveCards($entityId);
            $inactiveCards = $deck->getInactiveCards($entityId);
            
            // Get entity level (capacity of active deck)
            $entityLevel = (int)($entity['entity_level'] ?? 5);
            
            // Check if level up is possible (inactive >= level)
            $canLevelUp = count($inactiveCards) >= $entityLevel;

            // Get current move choice if any
            $currentChoice = $this->game->getMoveChoice($pid);

            $playerData[$pid] = [
                'entity' => $entity,
                'currentLocation' => $currentLocation,
                'adjacentLocations' => $adjacentLocations,
                'hasHostilesHere' => $hasHostiles,
                'activeCards' => $activeCards,
                'inactiveCards' => $inactiveCards,
                'entityLevel' => $entityLevel,
                'canLevelUp' => $canLevelUp,
                'currentChoice' => $currentChoice,
            ];
        }

        return [
            'round' => $stateHelper->getRound(),
            'playerData' => $playerData,
        ];
    }

    /**
     * Player action: Click location to move or stay
     */
    #[PossibleAction]
    function actSelectLocation(string $locationId, ?string $cardOrder, bool $isPlan, int $activePlayerId, array $args)
    {
        $playerId = (int)$this->game->getCurrentPlayerId();
        
        // Get player's entity
        $entity = $this->game->getGameStateHelper()->getEntityByPlayerId($playerId);
        if (!$entity) {
            throw new \BgaUserException(clienttranslate("No entity found for player"));
        }

        $entityId = (int)$entity['entity_id'];
        $currentLocationId = $entity['location_id'];
        $isStaying = ($locationId === $currentLocationId);
        $deck = $this->game->getDeck();
        $entityLevel = (int)($entity['entity_level'] ?? 5);

        // If moving, validate target is adjacent
        if (!$isStaying) {
            $adjacent = $this->game->getGameStateHelper()->getAdjacentLocations($currentLocationId);
            $adjacentIds = array_column($adjacent, 'location_id');

            if (!in_array($locationId, $adjacentIds)) {
                throw new \BgaUserException(clienttranslate("Can only move to adjacent locations"));
            }
        }

        // Handle plan action (staying with deck management)
        if ($isPlan && $cardOrder) {
            $cardData = json_decode($cardOrder, true);
            
            if (is_array($cardData)) {
                // New format: { activeCards: [...], inactiveCards: [...] }
                if (isset($cardData['activeCards']) && isset($cardData['inactiveCards'])) {
                    $activeCardIds = array_map('intval', $cardData['activeCards']);
                    $inactiveCardIds = array_map('intval', $cardData['inactiveCards']);
                    
                    // Apply the plan changes (validates active deck capacity)
                    $deck->applyPlanChanges($entityId, $activeCardIds, $inactiveCardIds, $entityLevel);
                }
                // Legacy format: just an array of card IDs for active reorder
                elseif (!empty($cardData) && is_numeric($cardData[0] ?? null)) {
                    $deck->reorderActive($entityId, $cardData);
                }
            }
        }

        // Record the choice - isPlan means player won't participate in action sequences
        $targetLocation = $isStaying ? null : $locationId;
        $this->game->recordMoveChoice($playerId, $targetLocation, $cardOrder, $isPlan);

        // Notify
        $actionText = $isPlan ? 'plans their deck' : ($isStaying ? 'stays' : 'will move');
        $this->notify->all('moveSelected', clienttranslate('${player_name} ${action_text}'), [
            'player_id' => $playerId,
            'player_name' => $this->game->getPlayerNameById($playerId),
            'action_text' => $actionText,
            'target_location' => $targetLocation,
            'is_staying' => $isStaying,
            'is_plan' => $isPlan,
        ]);

        // Deactivate player
        $this->game->gamestate->setPlayerNonMultiactive($playerId, 'resolve');

        return null;
    }

    /**
     * Player action: Level up (delete cards to increase level)
     */
    #[PossibleAction]
    function actLevelUp(string $cardIdsJson, int $activePlayerId, array $args)
    {
        $playerId = (int)$this->game->getCurrentPlayerId();
        
        // Get player's entity
        $entity = $this->game->getGameStateHelper()->getEntityByPlayerId($playerId);
        if (!$entity) {
            throw new \BgaUserException(clienttranslate("No entity found for player"));
        }

        $entityId = (int)$entity['entity_id'];
        $entityLevel = (int)($entity['entity_level'] ?? 5);
        $deck = $this->game->getDeck();
        
        // Parse card IDs to delete
        $cardIds = json_decode($cardIdsJson, true);
        if (!is_array($cardIds) || count($cardIds) !== $entityLevel) {
            throw new \BgaUserException(sprintf(
                clienttranslate("Must select exactly %d cards to sacrifice for level up"),
                $entityLevel
            ));
        }

        // Validate all cards belong to this entity
        foreach ($cardIds as $cardId) {
            $card = $deck->getCard((int)$cardId, $entityId);
            if (!$card) {
                throw new \BgaUserException(clienttranslate("Invalid card selection"));
            }
        }

        // Delete the cards permanently
        $deck->permanentlyDeleteCards(array_map('intval', $cardIds));

        // Increase entity level
        $newLevel = $entityLevel + 1;
        $this->game->DbQuery(
            "UPDATE entity SET entity_level = $newLevel WHERE entity_id = $entityId"
        );

        // Notify
        $this->notify->all('entityLeveledUp', clienttranslate('${player_name} sacrifices ${count} cards to reach level ${level}!'), [
            'player_id' => $playerId,
            'player_name' => $this->game->getPlayerNameById($playerId),
            'entity_id' => $entityId,
            'entity_name' => $entity['entity_name'],
            'count' => $entityLevel,
            'level' => $newLevel,
            'old_level' => $entityLevel,
        ]);

        // Don't deactivate - player can still choose to move or stay
        return null;
    }

    /**
     * Handle zombie player - stays in place
     */
    function zombie(int $playerId)
    {
        $this->game->recordMoveChoice($playerId, null, null);
        $this->game->gamestate->setPlayerNonMultiactive($playerId, 'resolve');
        return null;
    }

    /**
     * Called when all players have finished
     */
    function onAllPlayersNonMultiactive(): string
    {
        return ResolveMoves::class;
    }
}

