<?php

declare(strict_types=1);

namespace Bga\Games\Zoomquest\States;

use Bga\GameFramework\States\GameState;
use Bga\GameFramework\States\PossibleAction;
use Bga\GameFramework\StateType;
use Bga\Games\Zoomquest\Game;

require_once(dirname(__DIR__) . '/constants.inc.php');

/**
 * State: Character Selection (multipleactiveplayer)
 * - All players simultaneously choose a character from the available pool
 * - Once all players have selected, game proceeds to first round
 */
class CharacterSelection extends GameState
{
    public function __construct(protected Game $game)
    {
        parent::__construct(
            $game,
            id: ST_CHARACTER_SELECTION,
            type: StateType::MULTIPLE_ACTIVE_PLAYER,
            description: clienttranslate('Waiting for other players to choose a character'),
            descriptionMyTurn: clienttranslate('${you} must choose a character to play'),
            transitions: [
                'start' => RoundStart::class,
            ],
        );
    }

    /**
     * Called when entering this state - activate all players
     */
    function onEnteringState()
    {
        $this->game->gamestate->setAllPlayersMultiactive();
        return null;
    }

    /**
     * Provide state arguments - list of available characters
     */
    function getArgs(?int $playerId): array
    {
        // Get all unassigned characters (player_id IS NULL, entity_type = 'character')
        $availableCharacters = $this->game->getObjectListFromDB(
            "SELECT entity_id, entity_name, entity_class, location_id, faction 
             FROM entity 
             WHERE entity_type = 'character' AND player_id IS NULL AND is_defeated = 0"
        );

        // Get assigned characters (to show who picked what)
        $assignedCharacters = $this->game->getObjectListFromDB(
            "SELECT e.entity_id, e.entity_name, e.entity_class, e.player_id, p.player_name, p.player_color
             FROM entity e
             JOIN player p ON e.player_id = p.player_id
             WHERE e.entity_type = 'character' AND e.player_id IS NOT NULL"
        );

        // Get deck info for each available character
        $deck = $this->game->getDeck();
        foreach ($availableCharacters as &$char) {
            $cards = $deck->getActiveCards((int)$char['entity_id']);
            $char['deck'] = array_column($cards, 'card_type');
            
            // Get location name
            $locInfo = $this->game->getObjectFromDB(
                "SELECT location_name FROM location WHERE location_id = '" . addslashes($char['location_id']) . "'"
            );
            $char['location_name'] = $locInfo['location_name'] ?? $char['location_id'];
        }

        return [
            'availableCharacters' => $availableCharacters,
            'assignedCharacters' => $assignedCharacters,
        ];
    }

    /**
     * Player action: Select a character
     */
    #[PossibleAction]
    function actSelectCharacter(int $characterId, int $activePlayerId, array $args)
    {
        $playerId = (int)$this->game->getCurrentPlayerId();
        
        // Check if this player already has a character
        $existing = $this->game->getObjectFromDB(
            "SELECT entity_id FROM entity WHERE entity_type = 'character' AND player_id = '$playerId'"
        );
        if ($existing) {
            throw new \BgaUserException(clienttranslate("You have already selected a character"));
        }

        // Check if the character is still available
        $character = $this->game->getObjectFromDB(
            "SELECT entity_id, entity_name, entity_class FROM entity 
             WHERE entity_id = $characterId AND entity_type = 'character' AND player_id IS NULL"
        );
        if (!$character) {
            throw new \BgaUserException(clienttranslate("This character is no longer available"));
        }

        // Assign the character to this player
        $this->game->DbQuery(
            "UPDATE entity SET player_id = '$playerId', entity_type = 'player' 
             WHERE entity_id = $characterId"
        );

        // Notify all players
        $this->notify->all('characterSelected', clienttranslate('${player_name} chooses ${character_name} (${character_class})'), [
            'player_id' => $playerId,
            'player_name' => $this->game->getPlayerNameById($playerId),
            'character_id' => $characterId,
            'character_name' => $character['entity_name'],
            'character_class' => $character['entity_class'],
        ]);

        // Deactivate this player
        $this->game->gamestate->setPlayerNonMultiactive($playerId, 'start');

        return null;
    }

    /**
     * Handle zombie player - assign random available character
     */
    function zombie(int $playerId)
    {
        // Get a random available character
        $character = $this->game->getObjectFromDB(
            "SELECT entity_id FROM entity 
             WHERE entity_type = 'character' AND player_id IS NULL AND is_defeated = 0
             ORDER BY RAND() LIMIT 1"
        );

        if ($character) {
            $this->game->DbQuery(
                "UPDATE entity SET player_id = '$playerId', entity_type = 'player' 
                 WHERE entity_id = " . $character['entity_id']
            );
        }

        $this->game->gamestate->setPlayerNonMultiactive($playerId, 'start');
        return null;
    }

    /**
     * Called when all players have finished selecting
     */
    function onAllPlayersNonMultiactive(): string
    {
        // Clean up any unselected characters (remove them from the game)
        $this->game->DbQuery(
            "DELETE FROM entity WHERE entity_type = 'character' AND player_id IS NULL"
        );

        return RoundStart::class;
    }
}

