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

        $this->game->trace("=== SEQUENCE ROUND END (Round $sequenceRound) ===");

        // Get resolutions from this round
        $resolutionsJson = $stateHelper->get(STATE_ROUND_RESOLUTIONS);
        $resolutions = $resolutionsJson ? json_decode($resolutionsJson, true) : [];

        $this->game->trace("Resolutions this round: " . count($resolutions));

        // Note: Poison damage now happens at end of action sequence, not each round

        // Get participant status
        $status = $sequenceResolver->getParticipantStatus($sequenceId);
        $this->game->trace("Participant status: " . json_encode($status));

        // Build readable log message
        $logParts = [];
        foreach ($resolutions as $r) {
            $logParts[] = $this->formatResolution($r);
        }
        $roundLog = implode(' ', $logParts);

        // Append this round's log to the accumulated sequence log
        $sequenceLogJson = $stateHelper->get(STATE_SEQUENCE_LOG);
        $sequenceLog = $sequenceLogJson ? json_decode($sequenceLogJson, true) : [];
        $sequenceLog[] = [
            'round' => $sequenceRound,
            'log' => $roundLog,
            'resolutions' => $resolutions,
        ];
        $stateHelper->set(STATE_SEQUENCE_LOG, json_encode($sequenceLog));

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

        $this->game->trace("Eliminated faction check: " . ($eliminatedFaction ?? 'none'));

        if ($eliminatedFaction !== null) {
            $this->game->trace(">>> ENDING: Faction eliminated - $eliminatedFaction");
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
        $everyoneOut = $sequenceResolver->isEveryoneOutOfCards($sequenceId);
        $this->game->trace("Everyone out of cards check: " . ($everyoneOut ? 'true' : 'false'));
        
        if ($everyoneOut) {
            $this->game->trace(">>> ENDING: Everyone out of cards (standoff)");
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

        // Check if no cards were drawn this round (all players planning/moving, no active NPCs)
        // This prevents infinite loops when no one can act
        $anyCardsDrawn = $sequenceResolver->anyCardsDrawnThisRound($sequenceId);
        $this->game->trace("Any cards drawn check: " . ($anyCardsDrawn ? 'true' : 'false'));
        
        if (!$anyCardsDrawn) {
            $this->game->trace(">>> ENDING: No cards drawn (no active participants)");
            // Build status summary
            $statusLog = $this->formatStatusSummary($status);
            
            $this->notify->all('sequenceEnd', clienttranslate('Turn ${game_round}: No actions'), [
                'sequence_id' => $sequenceId,
                'game_round' => $gameRound,
                'eliminated_faction' => null,
                'status' => $status,
                'status_log' => $statusLog,
                'no_action' => true,
            ]);
            return SequenceCleanup::class;
        }

        // Sequence continues
        $this->game->trace(">>> CONTINUING to next round");

        // Decrement all markers at this location before next round
        $sequence = $this->game->getObjectFromDB(
            "SELECT location_id FROM action_sequence WHERE sequence_id = $sequenceId"
        );
        if ($sequence) {
            $markers = $this->game->getMarkerHelper();
            $expiredMarkers = $markers->decrementAllMarkersAtLocation($sequence['location_id']);
            if (!empty($expiredMarkers)) {
                $this->game->trace("Expired markers: " . json_encode($expiredMarkers));
            }
        }
        
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
                $x = $s['inactive'] ?? 0;
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
        $cardName = $r['card_name'] ?? ucfirst($cardType);
        $power = $r['card_power'] ?? 1;

        switch ($effect) {
            case 'watch':
                $markers = $r['markers_placed'] ?? 1;
                return "$entity played $cardName (+$markers watch).";

            case 'watch_cancels_sneak':
                $cancelled = $r['cancelled'] ?? 1;
                return "Watch cancelled $cancelled sneak marker(s).";

            case 'sneak':
                $markers = $r['markers_placed'] ?? 1;
                return "$entity played $cardName (+$markers sneak, hidden).";

            case 'mark':
                $markers = $r['markers_placed'] ?? 1;
                return "$entity played $cardName, marking $target (+$markers attack bonus).";

            case 'attack':
                $total = $r['total_attack'] ?? $power;
                $sneakBonus = $r['sneak_bonus'] ?? 0;
                $markBonus = $r['mark_bonus'] ?? 0;
                $bonusStr = '';
                if ($sneakBonus > 0) $bonusStr .= " +$sneakBonus sneak";
                if ($markBonus > 0) $bonusStr .= " +$markBonus mark";
                return "$entity attacked $target with $cardName ($total damage$bonusStr).";

            case 'defend':
                $total = $r['total_defend'] ?? $power;
                $sneakBonus = $r['sneak_bonus'] ?? 0;
                $bonusStr = $sneakBonus > 0 ? " +$sneakBonus sneak" : '';
                return "$entity defended $target with $cardName (+$total block$bonusStr).";

            case 'combat_resolution':
                $damage = $r['damage'] ?? 0;
                $cancelled = $r['cancelled'] ?? 0;
                $defendRemaining = $r['defend_remaining'] ?? 0;
                $parts = [];
                if ($cancelled > 0) $parts[] = "$cancelled blocked";
                if ($damage > 0) $parts[] = "$damage damage";
                if ($defendRemaining > 0) $parts[] = "$defendRemaining block remaining";
                if (isset($r['defeated'])) $parts[] = "defeated!";
                return "$entity: " . implode(', ', $parts) . ".";

            case 'poison':
                $markers = $r['markers_placed'] ?? 1;
                return "$entity poisoned $target with $cardName (+$markers poison).";

            case 'heal':
                $poisonRemoved = $r['poison_removed'] ?? 0;
                $cardsRestored = count($r['cards_restored'] ?? []);
                $parts = [];
                if ($poisonRemoved > 0) $parts[] = "removed $poisonRemoved poison";
                if ($cardsRestored > 0) $parts[] = "restored $cardsRestored card(s)";
                if (empty($parts)) return "$entity played $cardName but nothing to heal.";
                return "$entity played $cardName: " . implode(', ', $parts) . ".";

            case 'shuffle':
                $count = count($r['cards_shuffled'] ?? []);
                return "$entity played $cardName, shuffling $count card(s) from discard.";

            case 'selling':
                $price = $r['minimum_price'] ?? 1;
                return "$entity is selling (minimum $price wealth).";

            case 'purchased':
                $itemName = $r['item']['item_name'] ?? 'item';
                return "$entity purchased $itemName from $target.";

            case 'stolen':
                $itemCount = count($r['items'] ?? []);
                return "$entity stole $itemCount item(s) from $target.";

            case 'caught':
                return "$entity tried to steal but was caught!";

            case 'no_target':
            case 'target_defeated':
                return "$entity played $cardName but had no valid target.";

            case 'target_hidden':
                return "$entity played $cardName but $target is hidden!";

            default:
                return "$entity played $cardName.";
        }
    }
}

