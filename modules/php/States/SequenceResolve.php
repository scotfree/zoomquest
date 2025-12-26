<?php

declare(strict_types=1);

namespace Bga\Games\Zoomquest\States;

use Bga\GameFramework\States\GameState;
use Bga\GameFramework\StateType;
use Bga\Games\Zoomquest\Game;

require_once(dirname(__DIR__) . '/constants.inc.php');

/**
 * State: Sequence Resolve (game state)
 * - Resolves all cards simultaneously
 * - Order: Watch → Sneak → Block → Attack → Heal
 */
class SequenceResolve extends GameState
{
    public function __construct(protected Game $game)
    {
        parent::__construct(
            $game,
            id: ST_SEQUENCE_RESOLVE,
            type: StateType::GAME,
        );
    }

    /**
     * Called when entering this state - resolves all cards
     */
    function onEnteringState()
    {
        $stateHelper = $this->game->getGameStateHelper();
        $sequenceResolver = $this->game->getActionSequenceResolver();

        $sequenceId = (int)$stateHelper->get(STATE_CURRENT_SEQUENCE);
        $sequenceRound = (int)$stateHelper->get(STATE_SEQUENCE_ROUND);

        // Resolve all cards simultaneously (pass sequence round for tag handling)
        $resolutions = $sequenceResolver->resolveRound($sequenceId, $sequenceRound);

        // Store resolutions for summary
        $stateHelper->set(STATE_ROUND_RESOLUTIONS, json_encode($resolutions));

        // Send individual resolution notifications for animation
        foreach ($resolutions as $r) {
            $message = $this->buildResolutionMessage($r);
            
            $this->notify->all('cardResolved', '', [
                'entity_id' => $r['entity_id'],
                'entity_name' => $r['entity_name'],
                'entity_type' => $r['entity_type'],
                'card_type' => $r['card_type'],
                'target_id' => $r['target_id'] ?? null,
                'target_name' => $r['target_name'] ?? null,
                'effect' => $r['effect'],
                'message' => $message,
            ]);
        }

        return SequenceRoundEnd::class;
    }

    /**
     * Build human-readable message for a resolution
     */
    private function buildResolutionMessage(array $r): string
    {
        $name = $r['entity_name'];
        $target = $r['target_name'] ?? 'unknown';
        
        switch ($r['card_type']) {
            case CARD_WATCH:
                $revealed = $r['revealed'] ?? [];
                if (empty($revealed)) {
                    return "{$name} watches but sees nothing hidden";
                }
                $names = array_column($revealed, 'entity_name');
                return "{$name} watches and reveals: " . implode(', ', $names);

            case CARD_SNEAK:
                if ($r['effect'] === 'hidden') {
                    return "{$name} sneaks into the shadows";
                } else {
                    return "{$name} tries to sneak but is spotted!";
                }

            case CARD_DEFEND:
                if ($r['effect'] === 'block') {
                    $count = $r['block_count'] ?? 1;
                    return "{$name} defends {$target} (blocks: {$count})";
                }
                return "{$name}'s defense finds no target";

            case CARD_ATTACK:
                switch ($r['effect']) {
                    case 'destroy':
                        $defeated = isset($r['target_defeated']) ? ' DEFEATED!' : '';
                        return "{$name} attacks {$target}, destroying a card{$defeated}";
                    case 'blocked':
                        return "{$name}'s attack on {$target} is blocked!";
                    case 'target_hidden':
                        return "{$name} attacks but {$target} is hidden!";
                    case 'target_defeated':
                        return "{$name}'s attack misses - {$target} already defeated";
                    case 'no_target':
                        return "{$name} attacks but has no valid target";
                    default:
                        return "{$name} attacks {$target}";
                }

            case CARD_HEAL:
                if ($r['effect'] === 'heal') {
                    return "{$name} heals {$target}, restoring a card";
                } elseif ($r['effect'] === 'no_cards_to_heal') {
                    return "{$name} tries to heal {$target} but no cards to restore";
                }
                return "{$name}'s heal finds no target";

            case CARD_SHUFFLE:
                return "{$name} shuffles their deck";

            default:
                return "{$name} uses {$r['card_type']}";
        }
    }
}

