<?php

declare(strict_types=1);

namespace Bga\Games\Zoomquest\Helpers;

require_once(dirname(__DIR__) . '/constants.inc.php');

/**
 * Tracks individual player goals and progress
 */
class GoalTracker
{
    private $game;

    public function __construct($game)
    {
        $this->game = $game;
    }

    /**
     * Assign random goals to all players at game start
     */
    public function assignGoals(array $players, array $availableGoals): void
    {
        if (empty($availableGoals)) {
            return;
        }

        // Shuffle goals
        shuffle($availableGoals);
        $goalIndex = 0;

        foreach ($players as $playerId => $player) {
            // Cycle through goals if more players than goals
            $goal = $availableGoals[$goalIndex % count($availableGoals)];
            $goalIndex++;

            $goalId = addslashes($goal['id']);
            $goalName = addslashes($goal['name']);
            $goalDesc = addslashes($goal['description']);
            // Store icon as plain text (emoji) - use a simple fallback if not set
            $goalIcon = $goal['icon'] ?? 'target';
            $threshold = (int)($goal['threshold'] ?? 1);
            $compare = addslashes($goal['compare'] ?? 'gte');
            $points = (int)($goal['points'] ?? 1);

            $this->game->DbQuery(
                "INSERT INTO player_goal (player_id, goal_id, goal_name, goal_description, goal_icon, threshold, compare, points)
                 VALUES ($playerId, '$goalId', '$goalName', '$goalDesc', '$goalIcon', $threshold, '$compare', $points)"
            );
        }

        // Store available goals in game state for reference
        $this->game->getGameStateHelper()->set(STATE_INDIVIDUAL_GOALS, json_encode($availableGoals));
    }

    /**
     * Get a player's assigned goal
     */
    public function getPlayerGoal(int $playerId): ?array
    {
        return $this->game->getObjectFromDB(
            "SELECT * FROM player_goal WHERE player_id = $playerId"
        );
    }

    /**
     * Get all player goals
     */
    public function getAllPlayerGoals(): array
    {
        return $this->game->getCollectionFromDb(
            "SELECT player_id, goal_id, goal_name, goal_description, goal_icon, threshold, compare, points 
             FROM player_goal"
        );
    }

    /**
     * Get progress for a specific tracking type
     */
    public function getProgress(int $playerId, string $trackType, ?string $filter = null): int
    {
        $trackType = addslashes($trackType);
        $filterValue = $filter !== null ? addslashes($filter) : '';
        
        $result = $this->game->getUniqueValueFromDB(
            "SELECT progress FROM goal_progress 
             WHERE player_id = $playerId AND track_type = '$trackType' AND track_filter = '$filterValue'"
        );
        return $result !== null ? (int)$result : 0;
    }

    /**
     * Increment progress for a tracking type
     */
    public function incrementProgress(int $playerId, string $trackType, ?string $filter = null, int $amount = 1): void
    {
        $trackType = addslashes($trackType);
        $filterValue = $filter !== null ? addslashes($filter) : '';
        
        $this->game->DbQuery(
            "INSERT INTO goal_progress (player_id, track_type, track_filter, progress)
             VALUES ($playerId, '$trackType', '$filterValue', $amount)
             ON DUPLICATE KEY UPDATE progress = progress + $amount"
        );
    }

    /**
     * Record a location visit (for explorer goal)
     */
    public function recordLocationVisit(int $playerId, string $locationId): bool
    {
        $locationId = addslashes($locationId);
        
        // Check if already visited
        $exists = $this->game->getUniqueValueFromDB(
            "SELECT COUNT(*) FROM player_visited 
             WHERE player_id = $playerId AND location_id = '$locationId'"
        );
        
        if ((int)$exists === 0) {
            $this->game->DbQuery(
                "INSERT INTO player_visited (player_id, location_id) VALUES ($playerId, '$locationId')"
            );
            return true; // New location
        }
        return false; // Already visited
    }

    /**
     * Get count of unique locations visited
     */
    public function getLocationsVisitedCount(int $playerId): int
    {
        return (int)$this->game->getUniqueValueFromDB(
            "SELECT COUNT(*) FROM player_visited WHERE player_id = $playerId"
        );
    }

    /**
     * Track turn spent at location (for terrain/direction goals)
     */
    public function trackTurnAtLocation(int $playerId, string $locationId): void
    {
        // Get location attributes
        $location = $this->game->getObjectFromDB(
            "SELECT terrain, direction FROM location WHERE location_id = '" . addslashes($locationId) . "'"
        );

        if ($location) {
            // Track terrain
            if (!empty($location['terrain'])) {
                $this->incrementProgress($playerId, TRACK_TURNS_IN_TERRAIN, $location['terrain']);
            }
            // Track direction
            if (!empty($location['direction'])) {
                $this->incrementProgress($playerId, TRACK_TURNS_IN_DIRECTION, $location['direction']);
            }
        }
    }

    /**
     * Track a killing blow
     */
    public function trackKillingBlow(int $playerId, string $targetFaction): void
    {
        // Track total killing blows
        $this->incrementProgress($playerId, TRACK_KILLING_BLOWS);
        
        // Track by faction
        $this->incrementProgress($playerId, TRACK_KILLING_BLOWS_FACTION, $targetFaction);
    }

    /**
     * Track a block for an ally
     */
    public function trackBlockForAlly(int $playerId): void
    {
        $this->incrementProgress($playerId, TRACK_BLOCKS_FOR_ALLIES);
    }

    /**
     * Track a card play
     */
    public function trackCardPlay(int $playerId, string $cardType): void
    {
        $this->incrementProgress($playerId, TRACK_CARD_PLAYS, $cardType);
    }

    /**
     * Check if a player's goal is complete
     */
    public function isGoalComplete(int $playerId): bool
    {
        $goal = $this->getPlayerGoal($playerId);
        if (!$goal) {
            return false;
        }

        $progress = $this->getGoalProgress($playerId);
        $threshold = (int)$goal['threshold'];
        $compare = $goal['compare'] ?? 'gte';

        if ($compare === 'equal') {
            return $progress === $threshold;
        } else {
            return $progress >= $threshold;
        }
    }

    /**
     * Get current progress toward player's goal
     */
    public function getGoalProgress(int $playerId): int
    {
        $goal = $this->getPlayerGoal($playerId);
        if (!$goal) {
            return 0;
        }

        // Load the full goal definition to get track type and filter
        $goalsJson = $this->game->getGameStateHelper()->get(STATE_INDIVIDUAL_GOALS);
        $goals = $goalsJson ? json_decode($goalsJson, true) : [];
        
        $goalDef = null;
        foreach ($goals as $g) {
            if ($g['id'] === $goal['goal_id']) {
                $goalDef = $g;
                break;
            }
        }

        if (!$goalDef) {
            return 0;
        }

        $trackType = $goalDef['track'] ?? '';
        $filter = $goalDef['filter'] ?? null;

        // Special case for locations_visited
        if ($trackType === TRACK_LOCATIONS_VISITED) {
            return $this->getLocationsVisitedCount($playerId);
        }

        return $this->getProgress($playerId, $trackType, $filter);
    }

    /**
     * Get goal status for all players (for end game display)
     */
    public function getAllGoalStatus(): array
    {
        $players = $this->game->loadPlayersBasicInfos();
        $status = [];

        foreach ($players as $playerId => $player) {
            $goal = $this->getPlayerGoal((int)$playerId);
            if ($goal) {
                $progress = $this->getGoalProgress((int)$playerId);
                $complete = $this->isGoalComplete((int)$playerId);
                
                $status[$playerId] = [
                    'player_id' => $playerId,
                    'player_name' => $player['player_name'],
                    'goal_id' => $goal['goal_id'],
                    'goal_name' => $goal['goal_name'],
                    'goal_description' => $goal['goal_description'],
                    'goal_icon' => $goal['goal_icon'],
                    'threshold' => (int)$goal['threshold'],
                    'progress' => $progress,
                    'complete' => $complete,
                    'points' => $complete ? (int)$goal['points'] : 0,
                ];
            }
        }

        return $status;
    }
}

