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
                        <span class="zq-label">Round</span>
                        <span id="zq-round-number">${this.gamedatas.round}</span>
                        <span id="zq-round-location"></span>
                    </div>
                    <div id="zq-map-container">
                        <svg id="zq-map-svg"></svg>
                        <div id="zq-nodes-container"></div>
                    </div>
                    <div id="zq-entity-panel" class="zq-panel">
                        <h3>Combatants</h3>
                        <div id="zq-entity-list"></div>
                    </div>
                    <div id="zq-bottom-panels">
                        <div id="zq-action-panel" class="zq-panel">
                            <h3>Choose Action</h3>
                            <div id="zq-action-buttons">
                                <div class="zq-no-action">Waiting for turn...</div>
                            </div>
                        </div>
                        <div id="zq-battle-panel" class="zq-panel">
                        <h3>⚔️ Battle</h3>
                            <div id="zq-battle-content">
                                <div class="zq-no-battle">No active battle</div>
                    </div>
                        </div>
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
                // Build tags display
                const tags = entity.tags || [];
                const tagHtml = tags.map(t => this.getTagIcon(t.tag_name)).join(' ');
                html += `
                    <div class="zq-entity-info ${statusClass}" data-faction="${entity.faction || 'players'}">
                        <div class="zq-entity-name">⚔️ ${entity.entity_name}${playerName} ${tagHtml}</div>
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
                // Build tags display
                const tags = entity.tags || [];
                const tagHtml = tags.map(t => this.getTagIcon(t.tag_name)).join(' ');
                html += `
                    <div class="zq-entity-info zq-monster" data-faction="${entity.faction || 'monsters'}">
                        <div class="zq-entity-name">🧟 ${entity.entity_name} ${tagHtml}</div>
                        <div class="zq-entity-class">${entity.entity_class} <span class="zq-faction-badge">${entity.faction || 'unknown'}</span></div>
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
                case 'MoveSelection':
                    // For multiactive states, check if this player is in the multiactive list
                    const isMultiactive = args.multiactive && args.multiactive.includes(String(this.player_id));
                    const isActive = this.isCurrentPlayerActive() || isMultiactive;
                    console.log('MoveSelection state - isActive:', isActive);
                    
                    if (isActive) {
                        const playerId = String(this.player_id);
                        let myArgs = args.args?.playerData?.[playerId];
                        
                        if (myArgs && myArgs.currentLocation) {
                            this.showMoveSelectionUI(myArgs);
                            this.updateRoundLocation(myArgs.currentLocation.name);
                        } else {
                            console.log('No valid player args found');
                            this.updateRoundLocation(null);
                        }
                    }
                    break;

                case 'SequenceSetup':
                case 'SequenceDrawCards':
                case 'SequenceResolve':
                case 'SequenceRoundEnd':
                    this.showBattlePanel();
                    break;
            }
        },

        updateRoundLocation: function(locationName) {
            const locEl = document.getElementById('zq-round-location');
            if (locEl) {
                locEl.textContent = locationName ? `: ${locationName}` : '';
            }
        },

        onLeavingState: function(stateName) {
            console.log('Leaving state:', stateName);

            switch (stateName) {
                case 'MoveSelection':
                    this.hideMoveSelectionUI();
                    break;

                case 'SequenceCleanup':
                    // Don't auto-hide - wait for Continue button
                    break;
            }
        },

        onUpdateActionButtons: function(stateName, args) {
            console.log('onUpdateActionButtons:', stateName, args);
        },

        //
        // ──────────────────────────────────────────────────────────────────────
        //   MOVE SELECTION UI (Click map to move or stay)
        // ──────────────────────────────────────────────────────────────────────
        //

        showMoveSelectionUI: function(args) {
            const panel = document.getElementById('zq-action-panel');
            const buttons = document.getElementById('zq-action-buttons');

            if (!panel || !buttons) {
                console.error('Action panel elements not found');
                return;
            }

            // Store state for this turn
            this.currentLocationId = args.currentLocation.id;
            this.adjacentLocations = args.adjacentLocations;
            this.activeCards = args.activeCards || [];

            const hasHostiles = args.hasHostilesHere || false;
            
            // Build active deck display
            let deckHtml = '';
            if (this.activeCards.length > 0) {
                deckHtml = `<div class="zq-deck-display">
                    ${this.activeCards.map((card, idx) => `
                        <div class="zq-deck-card" title="${card.card_type}">
                            <span class="zq-deck-card-order">${idx + 1}</span>
                            <span class="zq-deck-card-icon">${this.getCardIcon(card.card_type)}</span>
                        </div>
                    `).join('')}
                </div>`;
            } else {
                deckHtml = '<div class="zq-deck-empty">No active cards</div>';
            }

            let html = `
                <div class="zq-current-location">
                    📍 <strong>${args.currentLocation.name}</strong>
                    ${hasHostiles ? '<span class="zq-hostiles-warning">⚠️ Hostiles!</span>' : ''}
                </div>
                <div class="zq-deck-section">
                    <div class="zq-deck-label">Active Deck:</div>
                    ${deckHtml}
                </div>
                <div class="zq-move-hint">
                    Click map to move or stay
                </div>
            `;
            buttons.innerHTML = html;

            // Highlight current location and adjacent nodes
            this.highlightCurrentLocation(args.currentLocation.id);
            this.highlightAdjacentNodes(args.adjacentLocations);

            // Enable map click handling
            this.enableMapClickHandling();

            panel.classList.add('zq-panel-active');
        },

        hideMoveSelectionUI: function() {
            const panel = document.getElementById('zq-action-panel');
            const buttons = document.getElementById('zq-action-buttons');
            
            if (panel) panel.classList.remove('zq-panel-active');
            if (buttons) {
                buttons.innerHTML = '<div class="zq-no-action">Waiting for next round...</div>';
            }

            // Remove node highlights and click handlers
            document.querySelectorAll('.zq-node').forEach(node => {
                node.classList.remove('zq-node-adjacent', 'zq-node-selected', 'zq-node-current', 'zq-node-clickable');
            });

            this.disableMapClickHandling();
        },

        enableMapClickHandling: function() {
            // Add clickable class to valid targets
            document.querySelectorAll('.zq-node-adjacent').forEach(node => {
                node.classList.add('zq-node-clickable');
            });
            
            // Current location is also clickable (to stay)
            const currentNode = document.querySelector('.zq-node-current');
            if (currentNode) {
                currentNode.classList.add('zq-node-clickable');
            }

            // Store reference to handler for removal later
            this.mapClickHandler = (e) => this.onMapNodeClick(e);
            document.getElementById('zq-nodes-container').addEventListener('click', this.mapClickHandler);
        },

        disableMapClickHandling: function() {
            if (this.mapClickHandler) {
                const container = document.getElementById('zq-nodes-container');
                if (container) {
                    container.removeEventListener('click', this.mapClickHandler);
                }
                this.mapClickHandler = null;
            }
        },

        onMapNodeClick: function(e) {
            const node = e.target.closest('.zq-node');
            if (!node || !node.classList.contains('zq-node-clickable')) {
                return;
            }

            const locationId = node.dataset.location;
            const isCurrentLocation = (locationId === this.currentLocationId);

            if (isCurrentLocation) {
                // Staying - show plan popup
                this.showPlanPopupForStay(locationId);
            } else {
                // Moving to adjacent location
                this.confirmMove(locationId);
            }
        },

        showPlanPopupForStay: function(locationId) {
            // If no cards, just submit stay immediately
            if (this.activeCards.length === 0) {
                this.submitMoveChoice(locationId, null);
                return;
            }

            // Show plan popup
            this.showPlanPopup((cardOrder) => {
                this.submitMoveChoice(locationId, cardOrder);
            }, () => {
                // Cancel - just stay without reordering
                this.submitMoveChoice(locationId, null);
            });
        },

        confirmMove: function(locationId) {
            // Find location name
            const loc = this.adjacentLocations.find(l => l.location_id === locationId);
            const locName = loc ? loc.location_name : locationId;

            // Highlight selected
            document.querySelectorAll('.zq-node').forEach(n => n.classList.remove('zq-node-selected'));
            document.getElementById(`zq-node-${locationId}`)?.classList.add('zq-node-selected');

            // Submit immediately (no confirmation needed)
            this.submitMoveChoice(locationId, null);
        },

        submitMoveChoice: function(locationId, cardOrder) {
            console.log('Submitting move choice:', locationId, cardOrder);
            
            this.bgaPerformAction('actSelectLocation', {
                locationId: locationId,
                cardOrder: cardOrder ? JSON.stringify(cardOrder) : null,
            });
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

        showPlanPopup: function(onConfirm, onCancel) {
            // Remove any existing popup
            this.hidePlanPopup();

            // Store callbacks
            this.planConfirmCallback = onConfirm;
            this.planCancelCallback = onCancel;

            const popup = document.createElement('div');
            popup.id = 'zq-plan-popup';
            popup.innerHTML = `
                <div class="zq-plan-header">
                    <h3>📋 Arrange Your Cards</h3>
                    <p>Drag cards to reorder. Top card will be played first in sequences.</p>
                </div>
                <div class="zq-plan-cards" id="zq-plan-cards-list">
                    ${this.activeCards.map((card, idx) => `
                        <div class="zq-plan-card" draggable="true" data-card-id="${card.card_id}" data-index="${idx}">
                            <span class="zq-plan-card-icon">${this.getCardIcon(card.card_type)}</span>
                            <span class="zq-plan-card-type">${card.card_type}</span>
                            <span class="zq-plan-card-order">#${idx + 1}</span>
                        </div>
                    `).join('')}
                </div>
                <div class="zq-plan-actions">
                    <button id="zq-plan-save" class="zq-plan-btn zq-plan-save">✓ Save & Stay</button>
                    <button id="zq-plan-cancel" class="zq-plan-btn zq-plan-cancel">✗ Just Stay</button>
                </div>
            `;

            document.body.appendChild(popup);

            // Setup drag and drop
            this.setupPlanDragAndDrop();

            // Button handlers
            document.getElementById('zq-plan-save').addEventListener('click', () => this.onSavePlan());
            document.getElementById('zq-plan-cancel').addEventListener('click', () => this.onCancelPlan());
        },

        hidePlanPopup: function() {
            const popup = document.getElementById('zq-plan-popup');
            if (popup) popup.remove();
        },

        setupPlanDragAndDrop: function() {
            const container = document.getElementById('zq-plan-cards-list');
            if (!container) return;

            let draggedEl = null;

            container.querySelectorAll('.zq-plan-card').forEach(card => {
                card.addEventListener('dragstart', (e) => {
                    draggedEl = card;
                    card.classList.add('zq-dragging');
                    e.dataTransfer.effectAllowed = 'move';
                });

                card.addEventListener('dragend', () => {
                    card.classList.remove('zq-dragging');
                    draggedEl = null;
                    this.updatePlanCardNumbers();
                });

                card.addEventListener('dragover', (e) => {
                    e.preventDefault();
                    e.dataTransfer.dropEffect = 'move';
                    
                    if (draggedEl && draggedEl !== card) {
                        const rect = card.getBoundingClientRect();
                        const midY = rect.top + rect.height / 2;
                        
                        if (e.clientY < midY) {
                            container.insertBefore(draggedEl, card);
                        } else {
                            container.insertBefore(draggedEl, card.nextSibling);
                        }
                    }
                });
            });
        },

        updatePlanCardNumbers: function() {
            const cards = document.querySelectorAll('#zq-plan-cards-list .zq-plan-card');
            cards.forEach((card, idx) => {
                const orderEl = card.querySelector('.zq-plan-card-order');
                if (orderEl) orderEl.textContent = `#${idx + 1}`;
            });
        },

        onSavePlan: function() {
            // Get card order from DOM
            const cards = document.querySelectorAll('#zq-plan-cards-list .zq-plan-card');
            const cardOrder = Array.from(cards).map(card => card.dataset.cardId);

            this.hidePlanPopup();

            // Call the confirm callback with the new order
            if (this.planConfirmCallback) {
                this.planConfirmCallback(cardOrder);
                this.planConfirmCallback = null;
                this.planCancelCallback = null;
            }
        },

        onCancelPlan: function() {
            this.hidePlanPopup();

            // Call the cancel callback
            if (this.planCancelCallback) {
                this.planCancelCallback();
                this.planConfirmCallback = null;
                this.planCancelCallback = null;
            }
        },

        onNodeClick: function(e) {
            // If we're in move selection mode and this is a clickable node,
            // let onMapNodeClick handle it instead of showing popup
            if (this.mapClickHandler && e.currentTarget.classList.contains('zq-node-clickable')) {
                return;
            }

            const locationId = e.currentTarget.dataset.location;

            // Show location popup
            this.showLocationPopup(locationId, e.currentTarget);
        },

        showLocationPopup: function(locationId, nodeElement) {
            // Remove any existing popup
            this.hideLocationPopup();

            // Find location info
            const location = this.gamedatas.map.locations.find(l => l.location_id === locationId);
            if (!location) return;

            // Determine entities at this location
            const entitiesHere = this.gamedatas.entities
                .filter(e => e.location_id === locationId && !e.is_defeated)
                .map(e => `${e.entity_name}`)
                .join(', ');

            // Create popup (informational only - clicking nodes handles movement)
            const popup = document.createElement('div');
            popup.id = 'zq-location-popup';
            popup.className = 'zq-location-popup';
            popup.innerHTML = `
                <div class="zq-popup-close" onclick="document.getElementById('zq-location-popup').remove()">×</div>
                <div class="zq-popup-name">${location.location_name}</div>
                <div class="zq-popup-description">${location.location_description || ''}</div>
                ${entitiesHere ? `<div class="zq-popup-entities">📍 ${entitiesHere}</div>` : '<div class="zq-popup-entities">📍 Empty</div>'}
            `;

            // Position popup near the node
            const rect = nodeElement.getBoundingClientRect();
            const container = document.getElementById('zq-map-container');
            const containerRect = container.getBoundingClientRect();
            
            popup.style.left = (rect.left - containerRect.left + rect.width / 2) + 'px';
            popup.style.top = (rect.top - containerRect.top + rect.height + 10) + 'px';

            container.appendChild(popup);

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
            // Panel is always visible now, just highlight it
            const panel = document.getElementById('zq-battle-panel');
            if (panel) panel.classList.add('zq-battle-active');
        },

        hideBattlePanel: function() {
            // Reset battle panel to idle state
            const panel = document.getElementById('zq-battle-panel');
            if (panel) panel.classList.remove('zq-battle-active');
            
            const content = document.getElementById('zq-battle-content');
            if (content) {
                content.innerHTML = '<div class="zq-no-battle">No active battle</div>';
            }
            this.battleEnded = false;
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

        notif_moveSelected: function(args) {
            // Player has selected their move - just log for now
            console.log('Move selected:', args);
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

        notif_entityPlanned: async function(args) {
            console.log('Entity planned:', args);
            // Just a visual confirmation - no data to update
            await this.wait(this.getAnimationDelay() / 2);
        },

        notif_sequenceStart: async function(args) {
            console.log('Sequence start:', args);

            this.battleEnded = false;
            this.showBattlePanel();
            this.updateRoundLocation(args.location_name);

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

        notif_sequenceCardsDrawn: async function(args) {
            console.log('Sequence cards drawn:', args);

            const log = document.getElementById('zq-battle-log');
            if (log && args.drawn_cards) {
                let html = '<div class="zq-cards-drawn"><strong>Cards drawn:</strong>';
                args.drawn_cards.forEach(card => {
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

        notif_sequenceEnd: async function(args) {
            console.log('Sequence end:', args);

            const log = document.getElementById('zq-battle-log');
            if (log) {
                let message = '';
                if (args.winner === 'players') {
                    message = '🎉 <strong>Victory!</strong> The heroes are triumphant!';
                } else if (args.winner === 'monsters') {
                    message = '💔 <strong>Defeat...</strong> The heroes have fallen.';
                } else {
                    message = '⚖️ <strong>Standoff.</strong> All combatants are exhausted.';
                }
                log.innerHTML += `<div class="zq-battle-result">${message}</div>`;
            }

            // Add Continue button
            const content = document.getElementById('zq-battle-content');
            if (content) {
                const continueBtn = document.createElement('div');
                continueBtn.className = 'zq-battle-continue';
                continueBtn.innerHTML = '<button id="zq-btn-battle-continue" class="zq-continue-btn">Continue</button>';
                content.appendChild(continueBtn);

                // Set up click handler - just hides the panel
                document.getElementById('zq-btn-battle-continue').addEventListener('click', () => {
                    this.hideBattlePanel();
                });
            }

            // Mark battle as ended but keep panel visible
            this.battleEnded = true;
        },

        notif_sequenceContinues: async function(args) {
            // Internal notification - panel already shows this via round summary
            console.log('Sequence continues:', args);
        },

        notif_sequenceRoundSummary: async function(args) {
            console.log('Sequence round summary:', args);

            const log = document.getElementById('zq-battle-log');
            if (!log) return;

            // Build round container (append, don't replace)
            let html = `<div class="zq-battle-round" data-round="${args.round}">`;
            html += `<div class="zq-round-header">⚔️ Round ${args.round}</div>`;

            // Group resolutions by phase (resolution order: watch, sneak, defend, attack, heal)
            const phases = {
                watch: { icon: '👁️', name: 'Watch', resolutions: [] },
                sneak: { icon: '🥷', name: 'Sneak', resolutions: [] },
                defend: { icon: '🛡️', name: 'Block', resolutions: [] },
                attack: { icon: '⚔️', name: 'Attack', resolutions: [] },
                heal: { icon: '💚', name: 'Heal', resolutions: [] }
            };

            args.resolutions.forEach(r => {
                if (phases[r.card_type]) {
                    phases[r.card_type].resolutions.push(r);
                }
            });

            // Display each phase
            for (const [type, phase] of Object.entries(phases)) {
                if (phase.resolutions.length > 0) {
                    html += `<div class="zq-phase-section">`;
                    html += `<div class="zq-phase-header">${phase.icon} ${phase.name}:</div>`;
                    phase.resolutions.forEach(r => {
                        const desc = this.formatResolutionLine(r);
                        html += `<div class="zq-phase-line">• ${desc}</div>`;
                    });
                    html += `</div>`;
                }
            }

            // Add status summary
            html += `<div class="zq-round-status">`;
            html += `<div class="zq-status-header">End of Round ${args.round}:</div>`;
            html += `<div class="zq-status-line">`;
            args.status.forEach((s, idx) => {
                const status = s.is_defeated ? '💀' : `${s.active}/${s.discard}/${s.destroyed}`;
                const sep = idx < args.status.length - 1 ? ' | ' : '';
                html += `<span class="zq-entity-status ${s.entity_type}">${s.entity_name}: ${status}</span>${sep}`;
            });
            html += `</div></div>`;
            html += `</div>`; // Close .zq-battle-round

            // Append to log (keep previous rounds)
            log.innerHTML += html;

            // Auto-scroll to latest round
            log.scrollTop = log.scrollHeight;

            await this.wait(this.getAnimationDelay());
        },

        formatResolutionLine: function(r) {
            const entityName = r.entity_name;
            const targetName = r.target_name || 'unknown';

            switch (r.card_type) {
                case 'watch':
                    if (r.revealed && r.revealed.length > 0) {
                        const revealedNames = r.revealed.map(e => e.entity_name).join(', ');
                        return `${entityName} watches and reveals: ${revealedNames}`;
                    }
                    return `${entityName} watches but sees nothing hidden`;

                case 'sneak':
                    if (r.effect === 'hidden') {
                        return `${entityName} sneaks into the shadows 👁️‍🗨️`;
                    } else if (r.effect === 'sneak_failed') {
                        return `${entityName} tries to sneak but is spotted!`;
                    }
                    return `${entityName}'s sneak has no effect`;

                case 'heal':
                    if (r.effect === 'heal') {
                        return `${entityName} heals ${targetName} (recovers 1 card)`;
                    } else if (r.effect === 'no_cards_to_heal') {
                        return `${entityName} tries to heal ${targetName} but no cards to recover`;
                    } else if (r.effect === 'target_defeated') {
                        return `${entityName}'s heal finds ${targetName} already defeated`;
                    }
                    return `${entityName}'s heal has no effect`;

                case 'defend':
                    if (r.effect === 'block') {
                        return `${entityName} defends ${targetName} (+1 block)`;
                    } else if (r.effect === 'target_defeated') {
                        return `${entityName} tries to defend ${targetName} but already defeated`;
                    }
                    return `${entityName}'s defense has no effect`;

                case 'attack':
                    if (r.effect === 'destroy') {
                        const pileNote = r.from_pile === 'discard' ? ' (from discard!)' : '';
                        return `${entityName} attacks ${targetName}, destroying 1 card${pileNote}`;
                    } else if (r.effect === 'blocked') {
                        return `${entityName} attacks ${targetName} but is BLOCKED`;
                    } else if (r.effect === 'target_hidden') {
                        return `${entityName} attacks but ${targetName} is hidden!`;
                    } else if (r.effect === 'no_cards') {
                        return `${entityName} attacks ${targetName} who has no cards left`;
                    } else if (r.effect === 'target_defeated') {
                        return `${entityName}'s attack finds ${targetName} already defeated`;
                    }
                    return `${entityName}'s attack has no effect`;

                case 'shuffle':
                    return `${entityName} shuffles their deck 🔀`;

                default:
                    return `${entityName} plays ${r.card_type}`;
            }
        },

        notif_sequenceCleanup: async function(args) {
            console.log('Sequence cleanup:', args);

            // Update deck counts for survivors
            if (args.survivors) {
            args.survivors.forEach(s => {
                const entity = this.gamedatas.entities.find(e => e.entity_id == s.entity_id);
                if (entity) {
                    entity.deck_counts = s.deck_counts;
                }
            });
            }

            // Mark defeated entities
            if (args.defeated) {
                args.defeated.forEach(d => {
                    const entity = this.gamedatas.entities.find(e => e.entity_id == d.entity_id);
                    if (entity) {
                        entity.is_defeated = 1;
                    }
                });
            }

            // Re-render entities to remove defeated from map
            this.renderEntities();
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
                'heal': '💚',
                'sneak': '🥷',
                'watch': '👁️',
                'shuffle': '🔀'
            };
            return icons[cardType] || '🃏';
        },

        getTagIcon: function(tagName) {
            const icons = {
                'hidden': '👻',
                'blocked': '🛡️'
            };
            return icons[tagName] || `[${tagName}]`;
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

