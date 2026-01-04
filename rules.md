# ZoomQuest - Rules

## Overview

ZoomQuest is a cooperative medieval fantasy game for 1-5 players. Players control characters navigating a network of locations, battling enemies to achieve victory before being eliminated.

---

## Core Mechanics

### The Basics

**Map**: The game takes place on a network of locations connected by paths. Each location can contain players and enemies.

**Cards = Health**: Each entity (player or monster) has a deck of cards. These cards represent both their abilities AND their health. When you take damage, cards move to your inactive pile. When your active and discard piles are both empty, you are defeated.

**Three Piles**:
- **Active Deck**: Cards available to draw (limited by character level)
- **Discard Pile**: Cards that have been played (shuffled back at end of sequence)
- **Inactive Pile**: Stored cards - damaged cards go here, new acquisitions go here

**Character Level**: Each character has a level (starting at 5) which determines the maximum size of their active deck. The inactive pile has no limit.

### Card Types (Basic)

**Attack** ⚔️
- Deals damage to an enemy
- Each point of power destroys one card from the target
- Automatically targets the enemy with the lowest health

**Defend** 🛡️
- Protects a friendly entity from incoming attacks
- Each point of power blocks one point of attack damage
- Targets the friendly entity with the lowest health

### Round Structure

Each round has two phases:

**Phase 1: Move or Plan**
- Choose to move to an adjacent location, or stay put
- If staying, you can choose **Action** (participate in combat) or **Plan** (skip combat, manage deck)
- All players reveal moves simultaneously

**Phase 2: Action Sequence**
- At each location with players present, an action sequence occurs
- Players who chose "Plan" do not participate
- All entities draw cards simultaneously and resolve them

### The Plan Action

When you choose to **Plan** instead of acting:
- You skip the action sequence at your location (you don't fight)
- You can move cards between your Active and Inactive piles
- Active deck is limited to your character level
- You can reorder your active deck (top card is drawn first)

This is useful for:
- Preparing specific cards for an upcoming battle
- Storing powerful cards for later
- Setting up for a level up

### Action Sequence Resolution

1. All entities draw their top card
2. Attack markers are placed on targets
3. Defend markers are placed on targets
4. Combat resolves: Defend cancels Attack (1:1), remaining attack = damage
5. Damaged entities have cards moved to their inactive pile
6. All played cards go to discard piles
7. Repeat until one side is eliminated or combat ends

### Victory & Defeat

- **Win**: Achieve the scenario's victory condition (usually: defeat all enemies)
- **Lose**: All players are defeated
- **Defeated**: An entity is defeated when their active deck AND discard pile are both empty

---

## Example Playthrough: The Test Scenario

### Setup

The **test_0** scenario has 4 locations connected in a simple network:

```
         [Stables] -------- Gut Lane -------- [Farm]
              |                                  |
         South Trail                        Farm Track
              |                                  |
          [South Road] ----- Tricky Track ----- [Illplaced]
```

**Starting positions:**
- All players start at the Farm
- A Bandit is at South Road (has 2 cards: Cutlass, Parry)
- A Goblin is at Illplaced Farm (has 5 cards)

### Round 1: Movement

The players discuss strategy. Bob the Warrior (5 attack cards) decides to go after the Bandit.

**Move choice**: Bob moves from Farm → South Road via Farm Track

**Result**: Bob arrives at South Road where the Bandit waits.

### Round 2: Battle Begins

Bob and the Bandit are now at the same location. An action sequence begins.

**Draw Phase:**
- Bob draws: **Greatsword** (Attack, Power 2)
- Bandit draws: **Cutlass** (Attack, Power 1)

**Marker Placement:**
- Bob's Greatsword places 2 attack markers on the Bandit
- Bandit's Cutlass places 1 attack marker on Bob

**Combat Resolution:**
- Bandit has 2 attack markers, 0 defend → takes 2 damage → 2 cards to inactive!
- Bob has 1 attack marker, 0 defend → takes 1 damage → 1 card to inactive

**Result:**
- Bandit only had 2 cards total - both gone to inactive! **Bandit is defeated!**
- Bob has 4 cards remaining

**Items**: The Bandit drops a Rusty Sword, which Bob picks up.

### Round 3: Continue the Hunt

Bob now moves toward Illplaced Farm to help deal with the Goblin...

---

## Advanced Rules

### Leveling Up

When your **Inactive pile** has cards equal to or greater than your current level, you can **Level Up** during a Plan action:

1. Choose exactly LEVEL cards to sacrifice (from either Active or Inactive)
2. Those cards are permanently removed from the game
3. Your level increases by 1
4. Your active deck capacity increases by 1

**Example**: At level 5 with 6 inactive cards, you can sacrifice 5 cards to become level 6. Your active deck can now hold 6 cards.

**Strategy Tips**:
- Sacrifice weaker cards to make room for better ones
- Higher levels mean more cards in play during combat
- Balance offense and defense as your deck grows

### Additional Card Types

**Heal** ❤️
- Restores cards from the inactive pile
- First removes Poison markers, then restores cards
- Targets the friendly entity with the lowest health

**Poison** 🧪
- Places poison markers on an enemy
- At the end of the action sequence, each poison marker deals 1 damage
- Poison persists until healed!

**Sneak** 👤
- Places sneak markers on yourself (you become hidden)
- Hidden entities cannot be targeted by attacks
- When you attack from hidden: consume 1 sneak marker, remaining markers add bonus damage
- Sneak markers persist between rounds

**Watch** 👁️
- Places watch markers on yourself
- At the start of each round, Watch cancels Sneak (1:1, both consumed)
- Watch also prevents Steal cards

**Mark** 🎯
- Places mark markers on an enemy
- All attacks against marked targets deal bonus damage (+1 per mark)
- Marks persist until the sequence ends

**Shuffle** 🔄
- Moves cards from your discard pile back into your active deck
- Power determines how many cards

### Commerce Cards

**Sell** 💰
- Places sell markers on yourself (indicates you're selling)
- The number of markers = minimum price

**Wealth** 💎
- Can purchase items from entities that are selling
- Power must meet or exceed the seller's price

**Steal** 🗝️
- Steals items from neutral entities
- Cancelled by Watch markers (1:1)

---

## Advanced Examples

### Example: Sneak Attack

Erik the Rogue has been building up sneak markers:
- Round 1: Plays **Cloak** (Sneak, Power 2) → gains 2 sneak markers, becomes hidden
- Round 2: Plays **Shadow Step** (Sneak, Power 1) → gains 1 more sneak marker (3 total)
- Round 3: Plays **Backstab Dagger** (Attack, Power 1) at the Goblin

When attacking from hidden:
- Consumes 1 sneak marker to allow the attack
- Remaining 2 sneak markers add +2 bonus damage
- Base attack (1) + Sneak bonus (2) = **3 total damage!**

The Goblin is defeated without ever seeing Erik coming.

### Example: Coordinated Defense

Alice the Cleric and Bob the Warrior are fighting a powerful enemy together.

**Draw Phase:**
- Enemy draws: **Crushing Blow** (Attack, Power 3) → targets Bob (lowest health)
- Alice draws: **Blessed Shield** (Defend, Power 2) → targets Bob
- Bob draws: **Greatsword** (Attack, Power 2) → targets Enemy

**Resolution:**
- Enemy places 3 attack markers on Bob
- Alice places 2 defend markers on Bob
- Bob attacks enemy for 2 damage

**Combat:**
- Bob has 3 attack markers, 2 defend → 2 cancelled → 1 damage taken
- Enemy has 2 attack markers → 2 damage taken

Alice's shield saved Bob from 2 points of damage!

### Example: Poison and Heal

The Goblin has poisoned Charlie with **Poison Spit** (Power 2), giving Charlie 2 poison markers.

If left untreated:
- End of sequence: Each poison marker deals 1 damage
- Charlie takes 2 damage from poison alone!

Diana the Paladin plays **Lay on Hands** (Heal, Power 2) targeting Charlie:
1. First, removes 2 poison markers (all removed!)
2. No heal power remaining, but Charlie is cured

If Diana had Power 3, she could cure the poison AND restore 1 card from inactive.

### Example: Commerce

A Merchant is at the Town Square with items for sale.

**Round 1:**
- Merchant plays **Sell** (Power 2) → places 2 sell markers (minimum price: 2)
- Erik plays **Wealth** (Power 3) → has enough to buy!

**Resolution:**
- Erik pays (3 ≥ 2 required) and receives the Merchant's item
- The item might be a new weapon card added to Erik's deck!

### Example: Watch vs Steal

Erik tries to steal from a watchful Merchant:
- Merchant has 2 watch markers
- Erik plays **Pickpocket** (Steal, Power 1)

**Resolution:**
- Watch cancels Steal 1:1
- 1 watch marker consumed, 1 steal cancelled
- Erik is caught! Steal fails.
- Merchant still has 1 watch marker remaining

---

## Factions

Each entity belongs to a faction. Faction relationships determine targeting:

| Relationship | Attack | Defend/Heal | Sneak Visibility |
|-------------|--------|-------------|------------------|
| Hostile | Can target | Cannot target | Attacks reveal |
| Friendly | Cannot target | Can target | Shared benefit |
| Neutral | Cannot attack | Cannot defend | Allows commerce |

Default factions in test scenario:
- **Players**: Hostile to goblins and criminals
- **Goblins**: Hostile to players and merchants
- **Criminals**: Hostile to players
- **Merchants**: Neutral to players, hostile to goblins

---

## Targeting Rules

When a card is drawn, targets are determined automatically:

**Attack/Poison/Mark** → Targets the visible hostile entity with the lowest health

**Defend/Heal** → Targets the friendly entity with the lowest health

**Self-targeting cards** (Sneak, Watch, Shuffle, Sell) → Always target self

**Health** = Active deck + Discard pile (not inactive cards)

Ties are broken randomly.

---

## Game Flow Summary

```
┌─────────────────────────────────────────────────────────────┐
│                    ROUND START                               │
└─────────────────────────────────────────────────────────────┘
                           ↓
┌─────────────────────────────────────────────────────────────┐
│              PHASE 1: MOVE/PLAN SELECTION                    │
│  All players secretly choose:                                │
│   • Move to adjacent location                                │
│   • Stay + Action (participate in combat)                    │
│   • Stay + Plan (manage deck, skip combat)                   │
└─────────────────────────────────────────────────────────────┘
                           ↓
┌─────────────────────────────────────────────────────────────┐
│              PHASE 2: RESOLVE MOVES                          │
│  All moves revealed and executed simultaneously              │
│  Players who chose Plan: rearrange active/inactive decks     │
└─────────────────────────────────────────────────────────────┘
                           ↓
┌─────────────────────────────────────────────────────────────┐
│        PHASE 3: ACTION SEQUENCES (per location)              │
│  Players who chose Plan do NOT participate                   │
│  ┌─────────────────────────────────────────────────────┐    │
│  │ 1. Watch resolves (cancels existing Sneak)          │    │
│  │ 2. Sneak markers placed                             │    │
│  │ 3. Mark markers placed                              │    │
│  │ 4. Attack markers placed (+ sneak/mark bonuses)     │    │
│  │ 5. Defend markers placed (+ sneak bonus)            │    │
│  │ 6. Combat: Attack vs Defend, damage → inactive      │    │
│  │ 7. Poison markers placed                            │    │
│  │ 8. Heal resolves (cures poison, restores cards)     │    │
│  │ 9. Shuffle resolves                                 │    │
│  │ 10. Commerce (Sell/Wealth/Steal)                    │    │
│  │ 11. Cards go to discard                             │    │
│  │ 12. Repeat until sequence ends                      │    │
│  └─────────────────────────────────────────────────────┘    │
└─────────────────────────────────────────────────────────────┘
                           ↓
┌─────────────────────────────────────────────────────────────┐
│              END OF SEQUENCE                                 │
│  • Apply poison damage (cards → inactive)                    │
│  • Clear temporary markers (keep sneak, watch, poison)       │
└─────────────────────────────────────────────────────────────┘
                           ↓
┌─────────────────────────────────────────────────────────────┐
│              CHECK VICTORY                                   │
│  Win? Lose? Or continue to next round...                     │
└─────────────────────────────────────────────────────────────┘
```

---

## Individual Goals

Each player may have secret individual goals for bonus points:

- 🗺️ **Explorer**: Visit 4+ unique locations
- 💀 **Slayer**: Deal 3+ killing blows
- 🛡️ **Protector**: Block 4+ attacks for allies
- 🥷 **Shadow**: Play Sneak 3+ times
- 🕊️ **Pacifist**: End with 0 killing blows
- And more...

Goals are revealed at game end for bonus scoring.
