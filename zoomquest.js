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
            console.log("Current player_id:", this.player_id, "Type:", typeof this.player_id);

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
            // Target our template div instead of the default game area
            const gameArea = document.getElementById('zq-game-area');
            console.log('buildGameArea: looking for #zq-game-area, found:', gameArea);
            if (!gameArea) {
                console.error('ZoomQuest: #zq-game-area not found in template');
                return;
            }
            
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
                const health = counts.active + counts.discard;
                const statusClass = entity.is_defeated == 1 ? 'zq-defeated' : '';
                // Get player name if available
                const playerName = entity.player_id && this.gamedatas.players[entity.player_id] 
                    ? ` (${this.gamedatas.players[entity.player_id].name || 'Player'})` 
                    : '';
                html += `
                    <div class="zq-entity-info ${statusClass}">
                        <div class="zq-entity-name">⚔️ ${entity.entity_name}${playerName}</div>
                        <div class="zq-entity-class">${entity.entity_class}</div>
                        <div class="zq-entity-location">📍 ${entity.location_name}</div>
                        <div class="zq-entity-health">❤️ Health: ${health}</div>
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
                const health = counts.active + counts.discard;
                html += `
                    <div class="zq-entity-info zq-monster">
                        <div class="zq-entity-name">🧟 ${entity.entity_name}</div>
                        <div class="zq-entity-class">${entity.entity_class}</div>
                        <div class="zq-entity-location">📍 ${entity.location_name}</div>
                        <div class="zq-entity-health">❤️ Health: ${health}</div>
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
            console.log('isCurrentPlayerActive:', this.isCurrentPlayerActive());

            switch (stateName) {
                case 'ActionSelection':
                    // For multiactive states, check if this player is in the multiactive list
                    const isMultiactive = args.multiactive && args.multiactive.includes(String(this.player_id));
                    const isActive = this.isCurrentPlayerActive() || isMultiactive;
                    console.log('ActionSelection state - isCurrentPlayerActive:', this.isCurrentPlayerActive(), 
                                'isMultiactive:', isMultiactive, 'multiactive list:', args.multiactive);
                    
                    if (isActive) {
                        // player_id might be number, but object keys are strings
                        const playerId = String(this.player_id);
                        console.log('Looking for player:', playerId, 'in playerData:', args.args?.playerData);
                        
                        let myArgs = null;
                        
                        // Look for player data in args.args.playerData[playerId]
                        if (args.args?.playerData?.[playerId]) {
                            myArgs = args.args.playerData[playerId];
                            console.log('Found args in playerData[playerId]:', myArgs);
                        }
                        
                        if (myArgs && myArgs.currentLocation) {
                            this.showActionSelectionUI(myArgs);
                        } else {
                            console.log('No valid player args found. args.args:', args.args);
                            // Show a basic UI even without full args
                            this.showBasicActionUI();
                        }
                    } else {
                        console.log('Player not active in this state');
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

            if (!panel || !buttons) {
                console.error('Action panel elements not found');
                return;
            }

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

            // Highlight current location and adjacent nodes
            this.highlightCurrentLocation(args.currentLocation.id);
            this.highlightAdjacentNodes(args.adjacentLocations);

            panel.style.display = 'block';
        },

        hideActionSelectionUI: function() {
            const panel = document.getElementById('zq-action-panel');
            if (panel) panel.style.display = 'none';

            // Remove node highlights
            document.querySelectorAll('.zq-node').forEach(node => {
                node.classList.remove('zq-node-adjacent', 'zq-node-selected', 'zq-node-current');
            });
        },

        // Fallback UI when we don't have full args from server
        showBasicActionUI: function() {
            const panel = document.getElementById('zq-action-panel');
            const buttons = document.getElementById('zq-action-buttons');

            if (!panel || !buttons) {
                console.error('Action panel elements not found');
                return;
            }

            this.selectedAction = null;
            this.selectedMoveTarget = null;

            let html = `
                <div class="zq-action-options">
                    <button id="zq-btn-move" class="zq-action-btn" disabled>🚶 Move (loading...)</button>
                    <button id="zq-btn-battle" class="zq-action-btn">⚔️ Battle</button>
                    <button id="zq-btn-rest" class="zq-action-btn">💤 Rest</button>
                </div>
                <div id="zq-confirm-action" style="display: none;">
                    <button id="zq-btn-confirm" class="zq-confirm-btn">Confirm Action</button>
                </div>
            `;
            buttons.innerHTML = html;

            // Add click handlers for battle and rest (move needs locations)
            document.getElementById('zq-btn-battle').addEventListener('click', () => this.onSelectBattle());
            document.getElementById('zq-btn-rest').addEventListener('click', () => this.onSelectRest());
            document.getElementById('zq-btn-confirm').addEventListener('click', () => this.onConfirmAction());

            panel.style.display = 'block';
        },

        highlightCurrentLocation: function(locationId) {
            document.querySelectorAll('.zq-node').forEach(node => {
                node.classList.remove('zq-node-current');
            });

            const node = document.getElementById(`zq-node-${locationId}`);
            if (node) {
                node.classList.add('zq-node-current');
            }
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
                // Show "Path Name to Destination" format
                const pathName = loc.connection_name || 'Path';
                const destName = loc.location_name || loc.location_id;
                html += `<button class="zq-move-target-btn" data-location="${loc.location_id}">
                    ${pathName} to ${destName}
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
            const battleBtn = document.getElementById('zq-btn-battle');
            if (battleBtn) battleBtn.classList.add('zq-selected');

            const moveTargets = document.getElementById('zq-move-targets');
            const confirmAction = document.getElementById('zq-confirm-action');
            if (moveTargets) moveTargets.style.display = 'none';
            if (confirmAction) confirmAction.style.display = 'block';
        },

        onSelectRest: function() {
            this.selectedAction = 'rest';
            this.selectedMoveTarget = null;

            // Update button states
            document.querySelectorAll('.zq-action-btn').forEach(btn => btn.classList.remove('zq-selected'));
            const restBtn = document.getElementById('zq-btn-rest');
            if (restBtn) restBtn.classList.add('zq-selected');

            const moveTargets = document.getElementById('zq-move-targets');
            const confirmAction = document.getElementById('zq-confirm-action');
            if (moveTargets) moveTargets.style.display = 'none';
            if (confirmAction) confirmAction.style.display = 'block';
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
                    return;
                }
            }

            // Show location popup
            this.showLocationPopup(locationId, e.currentTarget);
        },

        showLocationPopup: function(locationId, nodeElement) {
            // Remove any existing popup
            this.hideLocationPopup();

            // Find location info
            const location = this.gamedatas.map.locations.find(l => l.location_id === locationId);
            if (!location) return;

            // Determine player's current location and adjacent locations
            const myEntity = this.getMyEntity();
            const myLocationId = myEntity ? myEntity.location_id : null;
            const isCurrentLocation = locationId === myLocationId;
            const isAdjacent = this.adjacentLocations && this.adjacentLocations.some(loc => loc.location_id === locationId);

            // Build action buttons
            let actionHtml = '';
            if (isCurrentLocation && this.isCurrentPlayerActive()) {
                actionHtml = `<button class="zq-popup-action-btn" data-action="rest">💤 Rest</button>`;
            } else if (isAdjacent && this.isCurrentPlayerActive()) {
                actionHtml = `<button class="zq-popup-action-btn" data-action="move" data-location="${locationId}">🚶 Move Here</button>`;
            }

            // Create popup
            const popup = document.createElement('div');
            popup.id = 'zq-location-popup';
            popup.className = 'zq-location-popup';
            popup.innerHTML = `
                <div class="zq-popup-close" onclick="document.getElementById('zq-location-popup').remove()">×</div>
                <div class="zq-popup-name">${location.location_name}</div>
                <div class="zq-popup-description">${location.location_description || ''}</div>
                ${actionHtml ? `<div class="zq-popup-actions">${actionHtml}</div>` : ''}
            `;

            // Position popup near the node
            const rect = nodeElement.getBoundingClientRect();
            const container = document.getElementById('zq-map-container');
            const containerRect = container.getBoundingClientRect();
            
            popup.style.left = (rect.left - containerRect.left + rect.width / 2) + 'px';
            popup.style.top = (rect.top - containerRect.top + rect.height + 10) + 'px';

            container.appendChild(popup);

            // Add action button handlers
            popup.querySelectorAll('.zq-popup-action-btn').forEach(btn => {
                btn.addEventListener('click', (e) => {
                    const action = e.target.dataset.action;
                    if (action === 'rest') {
                        this.selectedAction = 'rest';
                        this.selectedMoveTarget = null;
                        this.onConfirmAction();
                    } else if (action === 'move') {
                        this.selectedAction = 'move';
                        this.selectedMoveTarget = e.target.dataset.location;
                        this.onConfirmAction();
                    }
                    this.hideLocationPopup();
                });
            });

            // Close popup when clicking outside
            setTimeout(() => {
                document.addEventListener('click', this.handlePopupOutsideClick.bind(this), { once: true });
            }, 100);
        },

        hideLocationPopup: function() {
            const popup = document.getElementById('zq-location-popup');
            if (popup) popup.remove();
        },

        handlePopupOutsideClick: function(e) {
            const popup = document.getElementById('zq-location-popup');
            if (popup && !popup.contains(e.target) && !e.target.closest('.zq-node')) {
                this.hideLocationPopup();
            }
        },

        getMyEntity: function() {
            const playerId = String(this.player_id);
            return this.gamedatas.entities.find(e => e.player_id === playerId);
        },

        //
        // ──────────────────────────────────────────────────────────────────────
        //   BATTLE UI
        // ──────────────────────────────────────────────────────────────────────
        //

        showBattlePanel: function() {
            const panel = document.getElementById('zq-battle-panel');
            if (panel) panel.style.display = 'block';
        },

        hideBattlePanel: function() {
            const panel = document.getElementById('zq-battle-panel');
            if (panel) panel.style.display = 'none';
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
            console.log('Round start notification:', args);
            const roundNum = document.getElementById('zq-round-number');
            if (roundNum) roundNum.textContent = args.round;
            
            // Re-render entities to show updated positions
            this.renderEntities();
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
                let html = '<div class="zq-cards-drawn"><strong>Cards drawn (Heal → Defend → Attack):</strong>';
                args.cards.forEach(card => {
                    const icon = this.getCardIcon(card.card_type);
                    const targetText = card.target_name ? ` → ${card.target_name}` : '';
                    html += `<div class="zq-drawn-card ${card.entity_type}">
                        ${card.entity_name}: ${icon} ${card.card_type}${targetText}
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
                let effectClass = '';

                switch (args.effect) {
                    case 'destroy':
                        const pileNote = args.from_pile === 'discard' ? ' (from discard!)' : '';
                        effectText = `💥 ${args.target_name} loses a card${pileNote}`;
                        effectClass = 'zq-effect-damage';
                        break;
                    case 'blocked':
                        effectText = `🛡️ BLOCKED! (${args.blocks_remaining} blocks remain)`;
                        effectClass = 'zq-effect-blocked';
                        break;
                    case 'block':
                        const blockText = args.block_count > 1 ? `${args.block_count} blocks` : '1 block';
                        effectText = `🛡️ ${args.target_name} gains a block (${blockText} total)`;
                        effectClass = 'zq-effect-defend';
                        break;
                    case 'heal':
                        effectText = `💚 ${args.target_name} recovers a card`;
                        effectClass = 'zq-effect-heal';
                        break;
                    case 'no_cards_to_heal':
                        effectText = `💔 No cards to recover for ${args.target_name}`;
                        effectClass = 'zq-effect-none';
                        break;
                    case 'no_cards':
                        effectText = `💀 ${args.target_name} has no cards left!`;
                        effectClass = 'zq-effect-none';
                        break;
                    case 'target_defeated':
                        effectText = `💀 Target ${args.target_name || 'unknown'} already defeated`;
                        effectClass = 'zq-effect-none';
                        break;
                    case 'no_target':
                        effectText = `❌ No valid target`;
                        effectClass = 'zq-effect-none';
                        break;
                    default:
                        effectText = `→ ${args.effect || 'No effect'}`;
                }

                log.innerHTML += `
                    <div class="zq-card-resolved ${effectClass}">
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
            // Try to get user preference, default to normal if not set
            let speed = 'normal';
            try {
                const pref = this.getGameUserPreference(100);
                const speeds = { 1: 'fast', 2: 'normal', 3: 'slow' };
                speed = speeds[pref] || 'normal';
            } catch (e) {
                // Preference not defined, use default
            }
            return this.animationDelays[speed];
        },

        wait: function(ms) {
            return new Promise(resolve => setTimeout(resolve, ms));
        }
    });
});

