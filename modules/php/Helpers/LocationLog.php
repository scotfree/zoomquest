<?php

declare(strict_types=1);

namespace Bga\Games\Zoomquest\Helpers;

/**
 * Helper class for managing per-location event logs
 */
class LocationLog
{
    private $game;

    public function __construct($game)
    {
        $this->game = $game;
    }

    /**
     * Add a log entry for a location
     */
    public function addEntry(string $locationId, int $round, string $logType, array $data): int
    {
        $locationId = addslashes($locationId);
        $logType = addslashes($logType);
        $logData = addslashes(json_encode($data));

        $this->game->DbQuery(
            "INSERT INTO location_log (location_id, round, log_type, log_data) 
             VALUES ('$locationId', $round, '$logType', '$logData')"
        );

        return (int)$this->game->DbGetLastId();
    }

    /**
     * Add an action sequence summary to the log
     */
    public function addSequenceSummary(string $locationId, int $round, array $summary): int
    {
        return $this->addEntry($locationId, $round, 'sequence', $summary);
    }

    /**
     * Get all log entries for a location
     */
    public function getLocationLogs(string $locationId, ?int $limit = null): array
    {
        $locationId = addslashes($locationId);
        $limitClause = $limit ? "LIMIT $limit" : "";

        $logs = $this->game->getObjectListFromDB(
            "SELECT log_id, round, log_type, log_data, created_at 
             FROM location_log 
             WHERE location_id = '$locationId' 
             ORDER BY log_id DESC $limitClause"
        );

        // Parse JSON data
        foreach ($logs as &$log) {
            $log['log_data'] = json_decode($log['log_data'], true) ?? [];
        }

        return $logs;
    }

    /**
     * Get recent logs for a location (for popup display)
     */
    public function getRecentLogs(string $locationId, int $count = 10): array
    {
        return $this->getLocationLogs($locationId, $count);
    }

    /**
     * Get logs for all locations (for client-side caching)
     */
    public function getAllLogs(): array
    {
        $logs = $this->game->getObjectListFromDB(
            "SELECT log_id, location_id, round, log_type, log_data, created_at 
             FROM location_log 
             ORDER BY log_id DESC"
        );

        // Group by location and parse JSON
        $byLocation = [];
        foreach ($logs as $log) {
            $locationId = $log['location_id'];
            if (!isset($byLocation[$locationId])) {
                $byLocation[$locationId] = [];
            }
            $log['log_data'] = json_decode($log['log_data'], true) ?? [];
            $byLocation[$locationId][] = $log;
        }

        return $byLocation;
    }

    /**
     * Clear all logs (for new game)
     */
    public function clearAll(): void
    {
        $this->game->DbQuery("DELETE FROM location_log");
    }
}

