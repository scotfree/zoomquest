<?php

declare(strict_types=1);

namespace Bga\Games\Zoomquest\States;

use Bga\GameFramework\States\GameState;
use Bga\GameFramework\StateType;
use Bga\Games\Zoomquest\Game;

/**
 * Placeholder state to override BGA's auto-generated PlayerTurn state.
 * This game uses MoveSelection (multipleactiveplayer) instead of the
 * traditional PlayerTurn flow, but we need this to satisfy the framework.
 */
class PlayerTurn extends GameState
{
    public function __construct(protected Game $game)
    {
        parent::__construct(
            $game,
            id: 2,
            type: StateType::ACTIVE_PLAYER,
            description: clienttranslate('${actplayer} must take an action'),
            descriptionMyTurn: clienttranslate('${you} must take an action'),
        );
    }

    /**
     * Required zombie handler for active player states
     */
    function zombie(int $playerId)
    {
        // This state should never be reached in normal gameplay,
        // but if a zombie lands here, just pass
        return RoundStart::class;
    }
}

