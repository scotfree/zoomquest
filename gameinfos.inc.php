<?php
/**
 *------
 * BGA framework: © Gregory Isabelli <gisabelli@boardgamearena.com> & Emmanuel Colin <ecolin@boardgamearena.com>
 * ZoomQuest implementation : © Your Name
 *
 * This code has been produced on the BGA studio platform for use on http://boardgamearena.com.
 * See http://en.boardgamearena.com/#!doc/Studio for more information.
 * -----
 */

$gameinfos = [
    'game_name' => "ZoomQuest",
    'designer' => '',
    'artist' => '',
    'year' => 2024,
    'publisher' => '',
    'publisher_website' => '',
    'publisher_bgg_id' => 0,
    'bgg_id' => 0,

    'players' => [1, 2, 3, 4, 5],
    'suggest_player_number' => 2,
    'not_recommend_player_number' => null,

    'estimated_duration' => 30,
    'fast_additional_time' => 30,
    'medium_additional_time' => 40,
    'slow_additional_time' => 50,

    'tie_breaker_description' => "",

    'losers_not_ranked' => false,

    'is_solo' => 1,
    'is_coop' => 1,

    'complexity' => 2,
    'luck' => 2,
    'strategy' => 3,
    'diplomacy' => 1,

    'player_colors' => ["ff0000", "008000", "0000ff", "ffa500", "800080"],

    'favorite_colors_support' => true,

    'disable_hierarchical_behavior' => false,

    'db_undo_support' => true,

    'is_beta' => 1,

    'tags' => [2, 11, 200],
    // 2 = Cooperative
    // 11 = Fantasy
    // 200 = Deck Building (closest match for card-based combat)
];

