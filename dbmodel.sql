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
  `location_id` varchar(32) NOT NULL,
  `is_defeated` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`entity_id`),
  KEY (`player_id`),
  KEY (`location_id`),
  KEY (`entity_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 AUTO_INCREMENT=1;

-- Cards (belong to entity decks)
CREATE TABLE IF NOT EXISTS `card` (
  `card_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `entity_id` int(10) unsigned NOT NULL,
  `card_type` enum('attack','defend','heal') NOT NULL,
  `card_pile` enum('active','discard','destroyed') NOT NULL DEFAULT 'active',
  `card_order` int(10) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`card_id`),
  KEY (`entity_id`),
  KEY (`card_pile`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 AUTO_INCREMENT=1;

-- Action choices for the current round (cleared each round)
CREATE TABLE IF NOT EXISTS `action_choice` (
  `entity_id` int(10) unsigned NOT NULL,
  `action_type` enum('move','battle','rest') NOT NULL,
  `target_location` varchar(32) DEFAULT NULL,
  PRIMARY KEY (`entity_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- Battle state (tracks ongoing battles)
CREATE TABLE IF NOT EXISTS `battle` (
  `battle_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `location_id` varchar(32) NOT NULL,
  `is_resolved` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`battle_id`),
  KEY (`location_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 AUTO_INCREMENT=1;

-- Battle participants and their drawn cards
CREATE TABLE IF NOT EXISTS `battle_participant` (
  `battle_id` int(10) unsigned NOT NULL,
  `entity_id` int(10) unsigned NOT NULL,
  `drawn_card_id` int(10) unsigned DEFAULT NULL,
  `target_entity_id` int(10) unsigned DEFAULT NULL,
  `resolution_order` int(10) unsigned DEFAULT NULL,
  `block_count` int(10) unsigned NOT NULL DEFAULT 0,
  `is_resolved` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`battle_id`, `entity_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- Game state tracking
CREATE TABLE IF NOT EXISTS `game_state` (
  `state_key` varchar(32) NOT NULL,
  `state_value` text,
  PRIMARY KEY (`state_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

