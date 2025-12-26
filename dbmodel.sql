--------
-- BGA framework: © Gregory Isabelli <gisabelli@boardgamearena.com> & Emmanuel Colin <ecolin@boardgamearena.com>
-- ZoomQuest implementation: © Your Name
-- 
-- This code has been produced on the BGA studio platform for use on http://boardgamearena.com.
-- See http://en.boardgamearena.com/#!doc/Studio for more information.
-------

-- Locations (nodes on the map graph)
CREATE TABLE IF NOT EXISTS `location` (
  `location_id` varchar(32) NOT NULL,
  `location_name` varchar(64) NOT NULL,
  `location_description` varchar(255) DEFAULT '',
  `terrain` varchar(32) DEFAULT 'wilderness',
  `direction` varchar(32) DEFAULT 'center',
  `x` float DEFAULT 0.5,
  `y` float DEFAULT 0.5,
  PRIMARY KEY (`location_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- Connections (edges between locations)
CREATE TABLE IF NOT EXISTS `connection` (
  `connection_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `connection_name` varchar(64) NOT NULL DEFAULT '',
  `location_from` varchar(32) NOT NULL,
  `location_to` varchar(32) NOT NULL,
  `bidirectional` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`connection_id`),
  KEY (`location_from`),
  KEY (`location_to`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 AUTO_INCREMENT=1;

-- Entities (players and monsters)
CREATE TABLE IF NOT EXISTS `entity` (
  `entity_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `entity_type` enum('player','monster') NOT NULL,
  `player_id` int(10) unsigned DEFAULT NULL,
  `entity_name` varchar(64) NOT NULL,
  `entity_class` varchar(32) NOT NULL,
  `faction` varchar(32) NOT NULL DEFAULT 'neutral',
  `location_id` varchar(32) NOT NULL,
  `is_defeated` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`entity_id`),
  KEY (`player_id`),
  KEY (`location_id`),
  KEY (`entity_type`),
  KEY (`faction`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 AUTO_INCREMENT=1;

-- Entity tags (temporary status effects)
CREATE TABLE IF NOT EXISTS `entity_tag` (
  `entity_id` int(10) unsigned NOT NULL,
  `tag_name` varchar(32) NOT NULL,
  `tag_value` int(10) NOT NULL DEFAULT 1,
  `round_applied` int(10) unsigned NOT NULL,
  PRIMARY KEY (`entity_id`, `tag_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- Cards (belong to entity decks)
CREATE TABLE IF NOT EXISTS `card` (
  `card_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `entity_id` int(10) unsigned NOT NULL,
  `card_type` enum('attack','defend','heal','sneak','watch','shuffle','poison','mark','backstab','execute','sell','steal','wealth') NOT NULL,
  `card_pile` enum('active','discard','destroyed','inactive') NOT NULL DEFAULT 'active',
  `card_order` int(10) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`card_id`),
  KEY (`entity_id`),
  KEY (`card_pile`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 AUTO_INCREMENT=1;

-- Items (belong to entities, consumed on acquisition)
CREATE TABLE IF NOT EXISTS `item` (
  `item_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `entity_id` int(10) unsigned NOT NULL,
  `item_name` varchar(64) NOT NULL,
  `item_type` enum('new_action','information','faction') NOT NULL,
  `item_data` text NOT NULL,
  PRIMARY KEY (`item_id`),
  KEY (`entity_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 AUTO_INCREMENT=1;

-- Movement choices for the current round (cleared each round)
CREATE TABLE IF NOT EXISTS `move_choice` (
  `player_id` int(10) unsigned NOT NULL,
  `target_location` varchar(32) DEFAULT NULL,
  `card_order` text DEFAULT NULL,
  PRIMARY KEY (`player_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- Action sequence state (tracks ongoing action sequences at locations)
CREATE TABLE IF NOT EXISTS `action_sequence` (
  `sequence_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `location_id` varchar(32) NOT NULL,
  `is_resolved` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`sequence_id`),
  KEY (`location_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 AUTO_INCREMENT=1;

-- Action sequence participants and their drawn cards
CREATE TABLE IF NOT EXISTS `sequence_participant` (
  `sequence_id` int(10) unsigned NOT NULL,
  `entity_id` int(10) unsigned NOT NULL,
  `drawn_card_id` int(10) unsigned DEFAULT NULL,
  `target_entity_id` int(10) unsigned DEFAULT NULL,
  `block_count` int(10) unsigned NOT NULL DEFAULT 0,
  `is_resolved` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`sequence_id`, `entity_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- Game state tracking
CREATE TABLE IF NOT EXISTS `game_state` (
  `state_key` varchar(32) NOT NULL,
  `state_value` text,
  PRIMARY KEY (`state_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- Player individual goals
CREATE TABLE IF NOT EXISTS `player_goal` (
  `player_id` int(10) unsigned NOT NULL,
  `goal_id` varchar(32) NOT NULL,
  `goal_name` varchar(64) NOT NULL,
  `goal_description` varchar(255) NOT NULL,
  `goal_icon` varchar(32) DEFAULT NULL,
  `threshold` int(10) unsigned NOT NULL DEFAULT 1,
  `compare` varchar(16) DEFAULT 'gte',
  `points` int(10) unsigned NOT NULL DEFAULT 1,
  PRIMARY KEY (`player_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Goal progress tracking (generic counters)
CREATE TABLE IF NOT EXISTS `goal_progress` (
  `player_id` int(10) unsigned NOT NULL,
  `track_type` varchar(32) NOT NULL,
  `track_filter` varchar(32) NOT NULL DEFAULT '',
  `progress` int(10) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`player_id`, `track_type`, `track_filter`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- Locations visited (for explorer goal)
CREATE TABLE IF NOT EXISTS `player_visited` (
  `player_id` int(10) unsigned NOT NULL,
  `location_id` varchar(32) NOT NULL,
  PRIMARY KEY (`player_id`, `location_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

