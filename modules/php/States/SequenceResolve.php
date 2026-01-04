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
                'entity_id' => $r['entity_id'] ?? null,
                'entity_name' => $r['entity_name'] ?? null,
                'entity_type' => $r['entity_type'] ?? null,
                'card_type' => $r['card_type'] ?? null,
                'card_name' => $r['card_name'] ?? null,
                'card_power' => $r['card_power'] ?? 1,
                'target_id' => $r['target_id'] ?? null,
                'target_name' => $r['target_name'] ?? null,
                'effect' => $r['effect'] ?? 'none',
                'markers_placed' => $r['markers_placed'] ?? 0,
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
        $name = $r['entity_name'] ?? 'Unknown';
        $target = $r['target_name'] ?? 'unknown';
        $cardName = $r['card_name'] ?? 'card';
        $effect = $r['effect'] ?? 'none';
        
        switch ($effect) {
            case 'watch':
                $markers = $r['markers_placed'] ?? 1;
                return "{$name} plays {$cardName} (+{$markers} watch)";

            case 'watch_cancels_sneak':
                $cancelled = $r['cancelled'] ?? 1;
                return "Watch cancels {$cancelled} sneak marker(s)";

            case 'sneak':
                $markers = $r['markers_placed'] ?? 1;
                return "{$name} plays {$cardName} and hides (+{$markers} sneak)";

            case 'mark':
                $markers = $r['markers_placed'] ?? 1;
                return "{$name} marks {$target} (+{$markers} attack bonus)";

            case 'attack':
                $total = $r['total_attack'] ?? $r['card_power'] ?? 1;
                $sneakBonus = $r['sneak_bonus'] ?? 0;
                $markBonus = $r['mark_bonus'] ?? 0;
                $parts = ["{$name} attacks {$target} with {$cardName} ({$total} damage)"];
                if ($sneakBonus > 0) $parts[] = "+{$sneakBonus} from sneak";
                if ($markBonus > 0) $parts[] = "+{$markBonus} from mark";
                return implode(' ', $parts);

            case 'defend':
                $total = $r['total_defend'] ?? $r['card_power'] ?? 1;
                return "{$name} defends {$target} with {$cardName} (+{$total} block)";

            case 'combat_resolution':
                $damage = $r['damage'] ?? 0;
                $cancelled = $r['cancelled'] ?? 0;
                $parts = [];
                if ($cancelled > 0) $parts[] = "{$cancelled} blocked";
                if ($damage > 0) $parts[] = "{$damage} damage taken";
                if (isset($r['defeated'])) $parts[] = "DEFEATED!";
                return "{$name}: " . implode(', ', $parts);

            case 'poison':
                $markers = $r['markers_placed'] ?? 1;
                return "{$name} poisons {$target} (+{$markers} poison)";

            case 'heal':
                $poisonRemoved = $r['poison_removed'] ?? 0;
                $cardsRestored = count($r['cards_restored'] ?? []);
                $parts = [];
                if ($poisonRemoved > 0) $parts[] = "removed {$poisonRemoved} poison";
                if ($cardsRestored > 0) $parts[] = "restored {$cardsRestored} card(s)";
                if (empty($parts)) return "{$name} plays {$cardName} but nothing to heal";
                return "{$name} plays {$cardName}: " . implode(', ', $parts);

            case 'shuffle':
                $count = count($r['cards_shuffled'] ?? []);
                return "{$name} plays {$cardName}, shuffling {$count} card(s)";

            case 'selling':
                $price = $r['minimum_price'] ?? 1;
                return "{$name} is selling (minimum {$price} wealth)";

            case 'purchased':
                $itemName = $r['item']['item_name'] ?? 'item';
                return "{$name} purchases {$itemName} from {$target}";

            case 'stolen':
                $count = count($r['items'] ?? []);
                return "{$name} steals {$count} item(s) from {$target}";

            case 'caught':
                return "{$name} tried to steal but was caught by watchers!";

            case 'no_target':
            case 'target_defeated':
                return "{$name} plays {$cardName} but has no valid target";

            default:
                if (isset($r['card_name'])) {
                    return "{$name} plays {$cardName}";
                }
                return "{$name} takes action";
        }
    }
}

