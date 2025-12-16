# ZoomQuest - Rules Summary

## Overview

ZoomQuest is a cooperative medieval fantasy game for 1-5 players. Players control characters navigating a network graph, battling monsters to eliminate them before being eliminated themselves.

## Setup

- **Map**: A network graph of nodes (locations) connected by edges (routes), defined in a JSON configuration file (5-25 nodes, 1-4 edges each)
- **Players**: Each player controls one character with a deck of cards, starting at a specified location
- **Monsters**: Placed at specific nodes as defined in the JSON config; each monster has its own deck

### Character Classes
- **Warrior**: Starts with 5 Attack cards
- **Guardian**: Starts with 5 Defend cards
- **Healer**: Starts with 5 Heal cards

## Victory & Defeat

- **Win**: All monsters are defeated
- **Lose**: All players are defeated
- **Defeated**: An entity is defeated when all their cards are in the destroyed pile (active deck and discard pile are both empty)

## Round Structure

Each round has two phases, resolved simultaneously for all players:

### Phase 1: Move
Each player secretly chooses to either:
- Move along one edge to an adjacent node, OR
- Stay at their current node

Monsters do not move.

### Phase 2: Action
Each entity chooses one action:
- **Battle**: Initiate combat with all entities in the node
- **Rest**: Recover 1 card from your destroyed pile to your discard pile

Monsters always choose Battle.

If ANY entity in a node chooses Battle, all entities in that node must fight.

When battles occur in multiple nodes simultaneously, they are resolved one at a time in random order.

## Combat

Combat occurs when Battle is triggered in a node. It continues until one team (players or monsters) is eliminated.

### Card Piles (per entity)
- **Active Deck**: Cards available to draw
- **Discard Pile**: Cards that have been played this battle
- **Destroyed Pile**: Cards lost to attacks (permanent until healed/rested)

When an entity's active deck is empty, shuffle their discard pile to form a new active deck.

### Combat Flow
1. All entities reveal their top card from their active deck
2. Cards resolve in random order with immediate effect (see Card Types)
3. Resolved cards go to that entity's discard pile
4. An entity is defeated when their active deck AND discard pile are both empty (all cards destroyed)
5. Battle ends when all monsters OR all players in the fight are defeated
6. Surviving entities shuffle their discard pile back into their active deck

### Card Types
- **Attack**: Destroy 1 random card from target's active deck (moves to their destroyed pile)
- **Defend**: Prevent one incoming attack against target (must be played before the attack)
- **Heal**: Recover 1 random card from target's destroyed pile to the top of their discard pile

### Default Targeting
Cards target the entity with the smallest active deck on the appropriate team:
- Attack → targets a monster (smallest deck)
- Defend/Heal → targets a player (smallest deck)

If multiple valid targets have the same deck size, choose randomly among them.

## Game State

The complete game state is stored in a JSON file, saved at the end of each round as `{game_name}_{round_number}.json`. This enables asynchronous play and state recovery.

### JSON Structure
```json
{
  "level_name": "string",
  "round": 0,
  "map": {
    "locations": [
      { "id": "string", "name": "string", "description": "string" }
    ],
    "connections": [
      { "name": "string", "from": "id", "to": "id", "bidirectional": true }
    ]
  },
  "players": [
    {
      "name": "string",
      "class": "warrior|guardian|healer",
      "location": "location_id",
      "decks": {
        "active": ["card", "card", ...],
        "discarded": [],
        "destroyed": []
      }
    }
  ],
  "monsters": [
    {
      "name": "string",
      "class": "string",
      "location": "location_id",
      "decks": {
        "active": ["card", "card", ...],
        "discarded": [],
        "destroyed": []
      }
    }
  ]
}
```

## Project Structure

```
zoomquest/
├── configs/
│   └── test_0.json              # Test scenario config
├── modules/
│   └── php/
│       ├── constants.inc.php    # State IDs and constants
│       ├── Game.php             # Main game class
│       ├── Helpers/
│       │   ├── ConfigLoader.php
│       │   ├── Deck.php
│       │   ├── CombatResolver.php
│       │   └── GameStateHelper.php
│       └── States/
│           ├── RoundStart.php
│           ├── ActionSelection.php
│           ├── ResolveActions.php
│           ├── BattleSetup.php
│           ├── BattleDrawCards.php
│           ├── BattleResolveCard.php
│           ├── BattleRoundEnd.php
│           ├── BattleCleanup.php
│           └── CheckVictory.php
├── dbmodel.sql                  # Database schema
├── gameinfos.inc.php            # BGA game info
├── gameoptions.json             # Game options
├── gamepreferences.json         # Player preferences
├── stats.json                   # Statistics config
├── zoomquest.js                 # Client JavaScript
├── zoomquest.css                # Stylesheet
└── rules.md                     # This file
```
