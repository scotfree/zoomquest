<?php

declare(strict_types=1);

namespace Bga\Games\Zoomquest\Helpers;

/**
 * Loads and validates game configuration from JSON files
 */
class ConfigLoader
{
    private $game;

    public function __construct($game)
    {
        $this->game = $game;
    }

    /**
     * Load a scenario configuration from JSON file
     * @param string $filename The JSON file name (without path)
     * @return array The parsed configuration
     */
    public function loadScenario(string $filename): array
    {
        // __DIR__ is modules/php/Helpers, so we need to go up 3 levels to reach game root
        $filepath = dirname(__DIR__, 3) . '/configs/' . $filename;
        
        if (!file_exists($filepath)) {
            throw new \BgaUserException("Scenario file not found: $filename");
        }

        $json = file_get_contents($filepath);
        $config = json_decode($json, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \BgaUserException("Invalid JSON in scenario file: " . json_last_error_msg());
        }

        $this->validateConfig($config);
        return $config;
    }

    /**
     * Validate the configuration structure
     */
    private function validateConfig(array $config): void
    {
        // Required top-level keys (characters instead of players)
        $required = ['level_name', 'map', 'characters', 'monsters', 'victory'];
        foreach ($required as $key) {
            if (!isset($config[$key])) {
                throw new \BgaUserException("Missing required config key: $key");
            }
        }

        // Validate victory condition
        $this->validateVictoryCondition($config['victory'], $config);

        // Validate map structure
        if (!isset($config['map']['locations']) || !is_array($config['map']['locations'])) {
            throw new \BgaUserException("Map must have 'locations' array");
        }
        if (!isset($config['map']['connections']) || !is_array($config['map']['connections'])) {
            throw new \BgaUserException("Map must have 'connections' array");
        }

        // Validate each location
        foreach ($config['map']['locations'] as $loc) {
            if (!isset($loc['id']) || !isset($loc['name'])) {
                throw new \BgaUserException("Each location must have 'id' and 'name'");
            }
        }

        // Validate each connection
        $locationIds = array_column($config['map']['locations'], 'id');
        foreach ($config['map']['connections'] as $conn) {
            if (!isset($conn['from']) || !isset($conn['to'])) {
                throw new \BgaUserException("Each connection must have 'from' and 'to'");
            }
            if (!in_array($conn['from'], $locationIds)) {
                throw new \BgaUserException("Connection 'from' references unknown location: {$conn['from']}");
            }
            if (!in_array($conn['to'], $locationIds)) {
                throw new \BgaUserException("Connection 'to' references unknown location: {$conn['to']}");
            }
        }

        // Validate characters (templates for player assignment)
        foreach ($config['characters'] as $character) {
            $this->validateEntity($character, $locationIds, 'character');
        }

        // Validate monsters
        foreach ($config['monsters'] as $monster) {
            $this->validateEntity($monster, $locationIds, 'monster');
        }
    }

    /**
     * Validate an entity (player or monster) configuration
     */
    private function validateEntity(array $entity, array $locationIds, string $type): void
    {
        $required = ['name', 'class', 'location', 'decks'];
        foreach ($required as $key) {
            if (!isset($entity[$key])) {
                throw new \BgaUserException("$type must have '$key'");
            }
        }

        if (!in_array($entity['location'], $locationIds)) {
            throw new \BgaUserException("$type references unknown location: {$entity['location']}");
        }

        if (!isset($entity['decks']['active']) || !is_array($entity['decks']['active'])) {
            throw new \BgaUserException("$type must have 'decks.active' array");
        }

        // Validate card types
        $validCards = ['attack', 'defend', 'heal', 'sneak', 'watch', 'shuffle', 'poison', 'mark', 'backstab', 'execute', 'sell', 'steal', 'wealth'];
        foreach ($entity['decks']['active'] as $card) {
            if (!in_array($card, $validCards)) {
                throw new \BgaUserException("Invalid card type: $card");
            }
        }

        // Validate items if present
        if (isset($entity['items'])) {
            foreach ($entity['items'] as $item) {
                if (!isset($item['name']) || !isset($item['type'])) {
                    throw new \BgaUserException("$type items must have 'name' and 'type'");
                }
                $validItemTypes = ['new_action', 'information', 'faction'];
                if (!in_array($item['type'], $validItemTypes)) {
                    throw new \BgaUserException("Invalid item type: {$item['type']}");
                }
            }
        }
    }

    /**
     * Validate victory condition structure
     */
    private function validateVictoryCondition(array $victory, array $config): void
    {
        if (!isset($victory['type'])) {
            throw new \BgaUserException("Victory condition must have 'type'");
        }

        $validTypes = ['defeat_all', 'reach_location', 'defeat_target', 'collect_item'];
        if (!in_array($victory['type'], $validTypes)) {
            throw new \BgaUserException("Invalid victory type: {$victory['type']}");
        }

        // Validate target based on type
        switch ($victory['type']) {
            case 'reach_location':
                if (!isset($victory['target'])) {
                    throw new \BgaUserException("Victory type 'reach_location' requires 'target' location");
                }
                $locationIds = array_column($config['map']['locations'], 'id');
                if (!in_array($victory['target'], $locationIds)) {
                    throw new \BgaUserException("Victory target location not found: {$victory['target']}");
                }
                break;

            case 'defeat_target':
                if (!isset($victory['target'])) {
                    throw new \BgaUserException("Victory type 'defeat_target' requires 'target' monster name");
                }
                $monsterNames = array_column($config['monsters'], 'name');
                if (!in_array($victory['target'], $monsterNames)) {
                    throw new \BgaUserException("Victory target monster not found: {$victory['target']}");
                }
                break;

            case 'collect_item':
                if (!isset($victory['target'])) {
                    throw new \BgaUserException("Victory type 'collect_item' requires 'target' item name");
                }
                // Items system not implemented yet, just validate target exists
                break;

            case 'defeat_all':
                // No additional validation needed
                break;
        }

        if (!isset($victory['description'])) {
            throw new \BgaUserException("Victory condition must have 'description'");
        }
    }

    /**
     * Get the default scenario filename based on game option
     */
    public function getScenarioFilename(int $optionValue): string
    {
        $scenarios = [
            1 => 'test_0.json',
            2 => 'tempe_junction.json',
            3 => 'outland_valley.json',
        ];

        return $scenarios[$optionValue] ?? 'test_0.json';
    }
}

