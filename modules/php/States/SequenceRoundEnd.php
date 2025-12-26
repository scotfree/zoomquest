<?php

declare(strict_types=1);

namespace Bga\Games\Zoomquest\States;

use Bga\GameFramework\States\GameState;
use Bga\GameFramework\StateType;
use Bga\Games\Zoomquest\Game;

require_once(dirname(__DIR__) . '/constants.inc.php');

/**
 * State: Sequence Round End (game state)
 * - Check if sequence should end
 * - Send round summary
 */
class SequenceRoundEnd extends GameState
{
    public function __construct(protected Game $game)
    {
        parent::__construct(
            $game,
            id: ST_SEQUENCE_ROUND_END,
            type: StateType::GAME,
        );
    }

    /**
     * Called when entering this state - checks end conditions
     */
    function onEnteringState()
    {
        $stateHelper = $this->game->getGameStateHelper();
        $sequenceResolver = $this->game->getActionSequenceResolver();

        $sequenceId = (int)$stateHelper->get(STATE_CURRENT_SEQUENCE);
        $sequenceRound = (int)$stateHelper->get(STATE_SEQUENCE_ROUND);

        // Get resolutions from this round
        $resolutionsJson = $stateHelper->get(STATE_ROUND_RESOLUTIONS);
        $resolutions = $resolutionsJson ? json_decode($resolutionsJson, true) : [];

        // Apply poison damage at end of round
        $poisonResults = $sequenceResolver->applyPoisonTicks($sequenceId);
        foreach ($poisonResults as $pr) {
            $resolutions[] = $pr;
        }

        // Get participant status (after poison damage)
        $status = $sequenceResolver->getParticipantStatus($sequenceId);

        // Build readable log message
        $logParts = [];
        foreach ($resolutions as $r) {
            $logParts[] = $this->formatResolution($r);
        }
        $roundLog = implode(' ', $logParts);

        // Send round summary notification
        $this->notify->all('sequenceRoundSummary', clienttranslate('Round ${round}: ${round_log}'), [
            'round' => $sequenceRound,
            'round_log' => $roundLog,
            'sequence_id' => $sequenceId,
            'resolutions' => $resolutions,
            'status' => $status,
        ]);

        // Check if one faction is eliminated
        $eliminatedFaction = $sequenceResolver->getEliminatedFaction($sequenceId);
        $gameRound = $stateHelper->getRound();

        if ($eliminatedFaction !== null) {
            // Build status summary
            $statusLog = $this->formatStatusSummary($status);
            
            // Sequence ends - one side won
            $this->notify->all('sequenceEnd', clienttranslate('Turn ${game_round}: ${status_log} (${faction} eliminated)'), [
                'sequence_id' => $sequenceId,
                'game_round' => $gameRound,
                'faction' => $eliminatedFaction,
                'eliminated_faction' => $eliminatedFaction,
                'status' => $status,
                'status_log' => $statusLog,
            ]);
            return SequenceCleanup::class;
        }

        // Check if everyone is out of cards (standoff)
        if ($sequenceResolver->isEveryoneOutOfCards($sequenceId)) {
            // Build status summary
            $statusLog = $this->formatStatusSummary($status);
            
            $this->notify->all('sequenceEnd', clienttranslate('Turn ${game_round}: ${status_log} (standoff)'), [
                'sequence_id' => $sequenceId,
                'game_round' => $gameRound,
                'eliminated_faction' => null,
                'status' => $status,
                'status_log' => $statusLog,
            ]);
            return SequenceCleanup::class;
        }

        // Sequence continues
        $this->notify->all('sequenceContinues', '', [
            'sequence_id' => $sequenceId,
        ]);

        // Reset for next round
        $sequenceResolver->resetSequenceRound($sequenceId);

        return SequenceDrawCards::class;
    }

    /**
     * Format status summary for log (e.g., "Bob 2/3/0 Goblin 💀")
     */
    private function formatStatusSummary(array $status): string
    {
        $parts = [];
        foreach ($status as $s) {
            $name = $s['entity_name'];
            if (isset($s['is_defeated']) && $s['is_defeated']) {
                $parts[] = "$name 💀";
            } else {
                $a = $s['active'] ?? 0;
                $d = $s['discard'] ?? 0;
                $x = $s['destroyed'] ?? 0;
                $parts[] = "$name $a/$d/$x";
            }
        }
        return implode(' ', $parts);
    }

    /**
     * Format a resolution into a readable log string
     */
    private function formatResolution(array $r): string
    {
        $entity = $r['entity_name'] ?? 'Unknown';
        $target = $r['target_name'] ?? null;
        $effect = $r['effect'] ?? 'none';
        $cardType = $r['card_type'] ?? 'unknown';

        switch ($cardType) {
            case 'attack':
                if ($effect === 'destroy') {
                    return "$entity attacked $target, destroying a card.";
                } elseif ($effect === 'blocked') {
                    return "$entity attacked $target but was blocked.";
                } elseif ($effect === 'target_hidden') {
                    return "$entity attacked but $target was hidden.";
                }
                return "$entity's attack had no effect.";

            case 'defend':
                if ($effect === 'block') {
                    return "$entity defended $target (+1 block).";
                }
                return "$entity's defense had no effect.";

            case 'heal':
                if ($effect === 'heal') {
                    return "$entity healed $target, recovering a card.";
                }
                return "$entity tried to heal but couldn't.";

            case 'sneak':
                if ($effect === 'hidden') {
                    return "$entity snuck into the shadows.";
                }
                return "$entity tried to sneak but was spotted.";

            case 'watch':
                return "$entity watched for enemies.";

            case 'shuffle':
                return "$entity shuffled their deck.";

            case 'poison':
                if ($effect === 'poison') {
                    $duration = $r['duration'] ?? 3;
                    return "$entity poisoned $target ($duration rounds).";
                }
                return "$entity's poison had no effect.";

            case 'mark':
                if ($effect === 'mark') {
                    $duration = $r['duration'] ?? 2;
                    return "$entity marked $target ($duration rounds, +1 damage).";
                }
                return "$entity's mark had no effect.";

            case 'backstab':
                if ($effect === 'backstab') {
                    $damage = $r['damage'] ?? 3;
                    $bonus = isset($r['marked_bonus']) ? ' (+1 marked)' : '';
                    return "$entity backstabbed $target for $damage damage$bonus!";
                } elseif ($effect === 'not_hidden') {
                    return "$entity tried to backstab but wasn't hidden.";
                } elseif ($effect === 'blocked') {
                    return "$entity's backstab was blocked.";
                }
                return "$entity's backstab missed.";

            case 'execute':
                if ($effect === 'execute') {
                    $damage = $r['damage'] ?? 3;
                    $bonus = isset($r['marked_bonus']) ? ' (+1 marked)' : '';
                    return "$entity executed $target for $damage damage$bonus!";
                } elseif ($effect === 'not_poisoned') {
                    return "$entity tried to execute but $target wasn't poisoned.";
                } elseif ($effect === 'blocked') {
                    return "$entity's execute was blocked.";
                }
                return "$entity's execute missed.";

            default:
                // Handle poison tick (effect-based, not card-based)
                if ($effect === 'poison_tick') {
                    $rounds = $r['rounds_remaining'] ?? 0;
                    if (isset($r['defeated'])) {
                        return "$entity took poison damage and was defeated!";
                    }
                    return "$entity took poison damage ($rounds rounds left).";
                }
                return "$entity played $cardType.";
        }
    }
}

