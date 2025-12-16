/**
 *------
 * BGA framework: © Gregory Isabelli <gisabelli@boardgamearena.com> & Emmanuel Colin <ecolin@boardgamearena.com>
 * ZoomQuest implementation: © Your Name
 *
 * This code has been produced on the BGA studio platform for use on http://boardgamearena.com.
 * See http://en.boardgamearena.com/#!doc/Studio for more information.
 * -----
 *
 * zoomquest.js
 *
 * ZoomQuest user interface script
 */

define([
    "dojo", "dojo/_base/declare",
    "ebg/core/gamegui",
    "ebg/counter"
],
function (dojo, declare, gamegui, counter) {
    return declare("bgagame.zoomquest", ebg.core.gamegui, {

        constructor: function() {
            console.log('ZoomQuest constructor');
            
            // Animation speed preference
            this.animationSpeed = 'normal'; // fast, normal, slow
            this.animationDelays = {
                fast: 300,
                normal: 800,
                slow: 1500
            };

            // Selected action for action selection phase
            this.selectedAction = null;
            this.selectedMoveTarget = null;
        },

        setup: function(gamedatas) {
            console.log("Starting game setup", gamedatas);

            this.gamedatas = gamedatas;

            // Build the game area
            this.buildGameArea();

            // Render the map
            this.renderMap();

            // Render entities
            this.renderEntities();

            // Setup notifications
            this.setupNotifications();

            console.log("Game setup complete");
        },

        //
        // ──────────────────────────────────────────────────────────────────────
        //   RENDERING
        // ──────────────────────────────────────────────────────────────────────
        //

        buildGameArea: function() {
            const gameArea = this.getGameAreaElement();
            
            gameArea.insertAdjacentHTML('beforeend', `
                <div id="zq-container">
                    <div id="zq-round-display" class="zq-panel">
                        <span class="zq-label">Round:</span>
                        <span id="zq-round-number">${this.gamedatas.round}</span>
                    </div>
                    <div id="zq-map-container">
                        <svg id="zq-map-svg"></svg>
                        <div id="zq-nodes-container"></div>
                    </div>
                    <div id="zq-entity-panel" class="zq-panel">
                        <h3>Combatants</h3>
                        <div id="zq-entity-list"></div>
                    </div>
                    <div id="zq-battle-panel" class="zq-panel" style="display: none;">
                        <h3>⚔️ Battle</h3>
                        <div id="zq-battle-content"></div>
                    </div>
                    <div id="zq-action-panel" class="zq-panel" style="display: none;">
                        <h3>Choose Action</h3>
                        <div id="zq-action-buttons"></div>
                    </div>
                </div>
            `);
        },

        renderMap: function() {
            const map = this.gamedatas.map;
            const svg = document.getElementById('zq-map-svg');
            const nodesContainer = document.getElementById('zq-nodes-container');

            // Calculate node positions (simple force-directed layout placeholder)
            const positions = this.calculateNodePositions(map);

            // Draw connections first (so they appear behind nodes)
            let connectionsHtml = '';
            map.connections.forEach(conn => {
                const from = positions[conn.location_from];
                const to = positions[conn.location_to];
                if (from && to) {
                    connectionsHtml += `
                        <line class="zq-connection" 
                              x1="${from.x}" y1="${from.y}" 
                              x2="${to.x}" y2="${to.y}"
                              data-from="${conn.location_from}"
                              data-to="${conn.location_to}">
                        </line>
                    `;
                }
            });
            svg.innerHTML = connectionsHtml;

            // Draw nodes
            let nodesHtml = '';
            map.locations.forEach(loc => {
                const pos = positions[loc.location_id];
                if (pos) {
                    nodesHtml += `
                        <div class="zq-node" 
                             id="zq-node-${loc.location_id}"
                             data-location="${loc.location_id}"
                             style="left: ${pos.x - 40}px; top: ${pos.y - 40}px;"
                             title="${loc.location_description || loc.location_name}">
                            <div class="zq-node-name">${loc.location_name}</div>
                            <div class="zq-node-entities" id="zq-node-entities-${loc.location_id}"></div>
                        </div>
                    `;
                }
            });
            nodesContainer.innerHTML = nodesHtml;

            // Add click handlers for nodes
            document.querySelectorAll('.zq-node').forEach(node => {
                node.addEventListener('click', e => this.onNodeClick(e));
            });
        },

        calculateNodePositions: function(map) {
            // Simple layout algorithm - arrange in a roughly circular pattern
            const positions = {};
            const nodeCount = map.locations.length;
            const centerX = 300;
            const centerY = 250;
            const radius = 150;

            map.locations.forEach((loc, index) => {
                const angle = (2 * Math.PI * index / nodeCount) - Math.PI / 2;
                positions[loc.location_id] = {
                    x: centerX + radius * Math.cos(angle),
                    y: centerY + radius * Math.sin(angle)
                };
            });

            return positions;
        },

        renderEntities: function() {
            // Clear existing entity markers from nodes
            document.querySelectorAll('.zq-node-entities').forEach(el => el.innerHTML = '');

            // Group entities by location
            const entitiesByLocation = {};
            this.gamedatas.entities.forEach(entity => {
                if (!entitiesByLocation[entity.location_id]) {
                    entitiesByLocation[entity.location_id] = [];
                }
                entitiesByLocation[entity.location_id].push(entity);
            });

            // Render entity markers on nodes
            Object.keys(entitiesByLocation).forEach(locationId => {
                const container = document.getElementById(`zq-node-entities-${locationId}`);
                if (container) {
                    let html = '';
                    entitiesByLocation[locationId].forEach(entity => {
                        if (entity.is_defeated == 1) return;
                        
                        const icon = entity.entity_type === 'player' ? '⚔️' : '🧟';
                        const className = entity.entity_type === 'player' ? 'zq-entity-player' : 'zq-entity-monster';
                        html += `
                            <div class="zq-entity-marker ${className}" 
                                 data-entity-id="${entity.entity_id}"
                                 title="${entity.entity_name} (${entity.entity_class})">
                                ${icon}
                            </div>
                        `;
                    });
                    container.innerHTML = html;
                }
            });

            // Update entity panel
            this.updateEntityPanel();
        },

        updateEntityPanel: function() {
            const container = document.getElementById('zq-entity-list');
            let html = '';

            // Players first
            const players = this.gamedatas.entities.filter(e => e.entity_type === 'player');
            const monsters = this.gamedatas.entities.filter(e => e.entity_type === 'monster' && e.is_defeated == 0);

            html += '<div class="zq-entity-section"><h4>Heroes</h4>';
            players.forEach(entity => {
                const counts = entity.deck_counts || { active: 0, discard: 0, destroyed: 0 };
                const statusClass = entity.is_defeated == 1 ? 'zq-defeated' : '';
                html += `
                    <div class="zq-entity-info ${statusClass}">
                        <div class="zq-entity-name">⚔️ ${entity.entity_name}</div>
                        <div class="zq-entity-class">${entity.entity_class}</div>
                        <div class="zq-entity-location">📍 ${entity.location_name}</div>
                        <div class="zq-deck-status">
                            <span class="zq-pile-active" title="Active">🃏 ${counts.active}</span>
                            <span class="zq-pile-discard" title="Discard">📥 ${counts.discard}</span>
                            <span class="zq-pile-destroyed" title="Destroyed">💀 ${counts.destroyed}</span>
                        </div>
                    </div>
                `;
            });
            html += '</div>';

            html += '<div class="zq-entity-section"><h4>Monsters</h4>';
            if (monsters.length === 0) {
                html += '<div class="zq-no-monsters">All defeated!</div>';
            }
            monsters.forEach(entity => {
                const counts = entity.deck_counts || { active: 0, discard: 0, destroyed: 0 };
                html += `
                    <div class="zq-entity-info zq-monster">
                        <div class="zq-entity-name">🧟 ${entity.entity_name}</div>
                        <div class="zq-entity-class">${entity.entity_class}</div>
                        <div class="zq-entity-location">📍 ${entity.location_name}</div>
                        <div class="zq-deck-status">
                            <span class="zq-pile-active" title="Active">🃏 ${counts.active}</span>
                            <span class="zq-pile-discard" title="Discard">📥 ${counts.discard}</span>
                            <span class="zq-pile-destroyed" title="Destroyed">💀 ${counts.destroyed}</span>
                        </div>
                    </div>
                `;
            });
            html += '</div>';

            container.innerHTML = html;
        },

        //
        // ──────────────────────────────────────────────────────────────────────
        //   GAME STATES
        // ──────────────────────────────────────────────────────────────────────
        //

        onEnteringState: function(stateName, args) {
            console.log('Entering state:', stateName, args);

            switch (stateName) {
                case 'ActionSelection':
                    if (this.isCurrentPlayerActive()) {
                        this.showActionSelectionUI(args.args);
                    }
                    break;

                case 'BattleSetup':
                case 'BattleDrawCards':
                case 'BattleResolveCard':
                case 'BattleRoundEnd':
                    this.showBattlePanel();
                    break;
            }
        },

        onLeavingState: function(stateName) {
            console.log('Leaving state:', stateName);

            switch (stateName) {
                case 'ActionSelection':
                    this.hideActionSelectionUI();
                    break;

                case 'BattleCleanup':
                    this.hideBattlePanel();
                    break;
            }
        },

        onUpdateActionButtons: function(stateName, args) {
            console.log('onUpdateActionButtons:', stateName, args);

            if (!this.isCurrentPlayerActive()) return;

            switch (stateName) {
                case 'ActionSelection':
                    // Action buttons are handled in showActionSelectionUI
                    break;
            }
        },

        //
        // ──────────────────────────────────────────────────────────────────────
        //   ACTION SELECTION UI
        // ──────────────────────────────────────────────────────────────────────
        //

        showActionSelectionUI: function(args) {
            const panel = document.getElementById('zq-action-panel');
            const buttons = document.getElementById('zq-action-buttons');

            this.selectedAction = null;
            this.selectedMoveTarget = null;

            // Build action buttons
            let html = `
                <div class="zq-current-location">
                    You are at: <strong>${args.currentLocation.name}</strong>
                </div>
                <div class="zq-action-options">
                    <button id="zq-btn-move" class="zq-action-btn">🚶 Move</button>
                    <button id="zq-btn-battle" class="zq-action-btn" ${args.hasEnemiesHere ? '' : 'disabled'}>
                        ⚔️ Battle ${args.hasEnemiesHere ? '' : '(no enemies here)'}
                    </button>
                    <button id="zq-btn-rest" class="zq-action-btn">💤 Rest</button>
                </div>
                <div id="zq-move-targets" style="display: none;">
                    <div class="zq-move-label">Move to:</div>
                    <div class="zq-move-options"></div>
                </div>
                <div id="zq-confirm-action" style="display: none;">
                    <button id="zq-btn-confirm" class="zq-confirm-btn">Confirm Action</button>
                </div>
            `;
            buttons.innerHTML = html;

            // Store adjacent locations for move
            this.adjacentLocations = args.adjacentLocations;

            // Add click handlers
            document.getElementById('zq-btn-move').addEventListener('click', () => this.onSelectMove());
            document.getElementById('zq-btn-battle').addEventListener('click', () => this.onSelectBattle());
            document.getElementById('zq-btn-rest').addEventListener('click', () => this.onSelectRest());
            document.getElementById('zq-btn-confirm').addEventListener('click', () => this.onConfirmAction());

            // Highlight adjacent nodes
            this.highlightAdjacentNodes(args.adjacentLocations);

            panel.style.display = 'block';
        },

        hideActionSelectionUI: function() {
            const panel = document.getElementById('zq-action-panel');
            panel.style.display = 'none';

            // Remove node highlights
            document.querySelectorAll('.zq-node').forEach(node => {
                node.classList.remove('zq-node-adjacent', 'zq-node-selected');
            });
        },

        highlightAdjacentNodes: function(adjacentLocations) {
            document.querySelectorAll('.zq-node').forEach(node => {
                node.classList.remove('zq-node-adjacent');
            });

            adjacentLocations.forEach(loc => {
                const node = document.getElementById(`zq-node-${loc.location_id}`);
                if (node) {
                    node.classList.add('zq-node-adjacent');
                }
            });
        },

        onSelectMove: function() {
            this.selectedAction = 'move';
            this.selectedMoveTarget = null;

            // Update button states
            document.querySelectorAll('.zq-action-btn').forEach(btn => btn.classList.remove('zq-selected'));
            document.getElementById('zq-btn-move').classList.add('zq-selected');

            // Show move targets
            const targetsContainer = document.getElementById('zq-move-targets');
            const optionsContainer = targetsContainer.querySelector('.zq-move-options');

            let html = '';
            this.adjacentLocations.forEach(loc => {
                html += `<button class="zq-move-target-btn" data-location="${loc.location_id}">
                    ${loc.connection_name || loc.location_id}
                </button>`;
            });
            optionsContainer.innerHTML = html;

            // Add click handlers
            document.querySelectorAll('.zq-move-target-btn').forEach(btn => {
                btn.addEventListener('click', (e) => this.onSelectMoveTarget(e.target.dataset.location));
            });

            targetsContainer.style.display = 'block';
            document.getElementById('zq-confirm-action').style.display = 'none';
        },

        onSelectMoveTarget: function(locationId) {
            this.selectedMoveTarget = locationId;

            // Update button states
            document.querySelectorAll('.zq-move-target-btn').forEach(btn => btn.classList.remove('zq-selected'));
            document.querySelector(`.zq-move-target-btn[data-location="${locationId}"]`).classList.add('zq-selected');

            // Highlight selected node
            document.querySelectorAll('.zq-node').forEach(node => node.classList.remove('zq-node-selected'));
            document.getElementById(`zq-node-${locationId}`).classList.add('zq-node-selected');

            // Show confirm button
            document.getElementById('zq-confirm-action').style.display = 'block';
        },

        onSelectBattle: function() {
            this.selectedAction = 'battle';
            this.selectedMoveTarget = null;

            // Update button states
            document.querySelectorAll('.zq-action-btn').forEach(btn => btn.classList.remove('zq-selected'));
            document.getElementById('zq-btn-battle').classList.add('zq-selected');

            document.getElementById('zq-move-targets').style.display = 'none';
            document.getElementById('zq-confirm-action').style.display = 'block';
        },

        onSelectRest: function() {
            this.selectedAction = 'rest';
            this.selectedMoveTarget = null;

            // Update button states
            document.querySelectorAll('.zq-action-btn').forEach(btn => btn.classList.remove('zq-selected'));
            document.getElementById('zq-btn-rest').classList.add('zq-selected');

            document.getElementById('zq-move-targets').style.display = 'none';
            document.getElementById('zq-confirm-action').style.display = 'block';
        },

        onConfirmAction: function() {
            if (!this.selectedAction) {
                this.showMessage(_("Please select an action"), "error");
                return;
            }

            if (this.selectedAction === 'move' && !this.selectedMoveTarget) {
                this.showMessage(_("Please select a destination"), "error");
                return;
            }

            this.bgaPerformAction('actSelectAction', {
                actionType: this.selectedAction,
                targetLocation: this.selectedMoveTarget || ''
            });
        },

        onNodeClick: function(e) {
            const locationId = e.currentTarget.dataset.location;

            // If in move selection mode, select this as target
            if (this.selectedAction === 'move' && this.adjacentLocations) {
                const isAdjacent = this.adjacentLocations.some(loc => loc.location_id === locationId);
                if (isAdjacent) {
                    this.onSelectMoveTarget(locationId);
                }
            }
        },

        //
        // ──────────────────────────────────────────────────────────────────────
        //   BATTLE UI
        // ──────────────────────────────────────────────────────────────────────
        //

        showBattlePanel: function() {
            document.getElementById('zq-battle-panel').style.display = 'block';
        },

        hideBattlePanel: function() {
            document.getElementById('zq-battle-panel').style.display = 'none';
        },

        updateBattleDisplay: function(data) {
            const content = document.getElementById('zq-battle-content');
            content.innerHTML = data.html || '';
        },

        //
        // ──────────────────────────────────────────────────────────────────────
        //   NOTIFICATIONS
        // ──────────────────────────────────────────────────────────────────────
        //

        setupNotifications: function() {
            console.log('Setting up notifications');
            this.bgaSetupPromiseNotifications();
        },

        notif_roundStart: async function(args) {
            document.getElementById('zq-round-number').textContent = args.round;
        },

        notif_actionSelected: function(args) {
            // Player has selected their action - just log for now
            console.log('Action selected:', args);
        },

        notif_entityMoved: async function(args) {
            console.log('Entity moved:', args);

            // Update local data
            const entity = this.gamedatas.entities.find(e => e.entity_id == args.entity_id);
            if (entity) {
                entity.location_id = args.to_location;
            }

            // Re-render entities
            this.renderEntities();

            await this.wait(this.getAnimationDelay());
        },

        notif_entityRested: async function(args) {
            console.log('Entity rested:', args);

            // Update deck counts
            const entity = this.gamedatas.entities.find(e => e.entity_id == args.entity_id);
            if (entity) {
                entity.deck_counts = args.deck_counts;
            }

            this.updateEntityPanel();
            await this.wait(this.getAnimationDelay());
        },

        notif_battleStart: async function(args) {
            console.log('Battle start:', args);

            this.showBattlePanel();

            const content = document.getElementById('zq-battle-content');
            content.innerHTML = `
                <div class="zq-battle-location">📍 ${args.location_name}</div>
                <div class="zq-battle-participants">
                    ${args.participants.map(p => `
                        <div class="zq-battle-participant ${p.entity_type}">
                            ${p.entity_type === 'player' ? '⚔️' : '🧟'} ${p.entity_name}
                        </div>
                    `).join('')}
                </div>
                <div class="zq-battle-log" id="zq-battle-log"></div>
            `;

            await this.wait(this.getAnimationDelay());
        },

        notif_battleCardsDrawn: async function(args) {
            console.log('Cards drawn:', args);

            const log = document.getElementById('zq-battle-log');
            if (log) {
                let html = '<div class="zq-cards-drawn"><strong>Cards drawn:</strong>';
                args.cards.forEach(card => {
                    const icon = this.getCardIcon(card.card_type);
                    html += `<div class="zq-drawn-card ${card.entity_type}">
                        ${card.entity_name}: ${icon} ${card.card_type}
                    </div>`;
                });
                html += '</div>';
                log.innerHTML += html;
            }

            await this.wait(this.getAnimationDelay());
        },

        notif_cardResolved: async function(args) {
            console.log('Card resolved:', args);

            const log = document.getElementById('zq-battle-log');
            if (log) {
                const icon = this.getCardIcon(args.card_type);
                let effectText = '';

                switch (args.effect) {
                    case 'destroy':
                        effectText = `→ ${args.target_name} loses a card!`;
                        break;
                    case 'defend':
                        effectText = `→ Defending ${args.target_name}`;
                        break;
                    case 'heal':
                        effectText = `→ ${args.target_name} recovers a card`;
                        break;
                    default:
                        effectText = `→ No effect`;
                }

                log.innerHTML += `
                    <div class="zq-card-resolved">
                        ${icon} <strong>${args.entity_name}</strong> plays ${args.card_type} ${effectText}
                    </div>
                `;
            }

            // Update deck counts
            if (args.target_id && args.target_deck_counts) {
                const target = this.gamedatas.entities.find(e => e.entity_id == args.target_id);
                if (target) {
                    target.deck_counts = args.target_deck_counts;
                }
            }

            this.updateEntityPanel();
            await this.wait(this.getAnimationDelay());
        },

        notif_entityDefeated: async function(args) {
            console.log('Entity defeated:', args);

            const entity = this.gamedatas.entities.find(e => e.entity_id == args.entity_id);
            if (entity) {
                entity.is_defeated = 1;
            }

            const log = document.getElementById('zq-battle-log');
            if (log) {
                log.innerHTML += `<div class="zq-entity-defeated">💀 ${args.entity_name} has been defeated!</div>`;
            }

            this.renderEntities();
            await this.wait(this.getAnimationDelay());
        },

        notif_battleEnd: async function(args) {
            console.log('Battle end:', args);

            const log = document.getElementById('zq-battle-log');
            if (log) {
                const message = args.winner === 'players' ? '🎉 Victory!' : '💔 Defeat...';
                log.innerHTML += `<div class="zq-battle-result">${message}</div>`;
            }

            await this.wait(this.getAnimationDelay() * 2);
        },

        notif_battleContinues: async function(args) {
            const log = document.getElementById('zq-battle-log');
            if (log) {
                log.innerHTML += '<div class="zq-battle-continues">--- Next round ---</div>';
            }
            await this.wait(this.getAnimationDelay() / 2);
        },

        notif_battleCleanup: async function(args) {
            console.log('Battle cleanup:', args);

            // Update deck counts for survivors
            args.survivors.forEach(s => {
                const entity = this.gamedatas.entities.find(e => e.entity_id == s.entity_id);
                if (entity) {
                    entity.deck_counts = s.deck_counts;
                }
            });

            this.updateEntityPanel();
            await this.wait(this.getAnimationDelay());
        },

        notif_gameVictory: async function(args) {
            this.showMessage(_("Victory! All monsters defeated!"), "info");
        },

        notif_gameDefeat: async function(args) {
            this.showMessage(_("Defeat! All heroes have fallen."), "error");
        },

        //
        // ──────────────────────────────────────────────────────────────────────
        //   UTILITIES
        // ──────────────────────────────────────────────────────────────────────
        //

        getCardIcon: function(cardType) {
            const icons = {
                'attack': '⚔️',
                'defend': '🛡️',
                'heal': '💚'
            };
            return icons[cardType] || '🃏';
        },

        getAnimationDelay: function() {
            const pref = this.getGameUserPreference(100);
            const speeds = { 1: 'fast', 2: 'normal', 3: 'slow' };
            const speed = speeds[pref] || 'normal';
            return this.animationDelays[speed];
        },

        wait: function(ms) {
            return new Promise(resolve => setTimeout(resolve, ms));
        }
    });
});

