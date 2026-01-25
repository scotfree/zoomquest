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
            this.animationSpeed = 'normal'; // instant, fast, normal, slow
            this.animationDelays = {
                instant: 0,
                fast: 300,
                normal: 800,
                slow: 1500
            };

            // Selected action for action selection phase
            this.selectedAction = null;
            this.selectedMoveTarget = null;
            
            // Track if player is active in move selection (for popup buttons)
            this.isActiveInMoveSelection = false;
        },

        setup: function(gamedatas) {
            console.log("Starting game setup", gamedatas);

            this.gamedatas = gamedatas;
            this.levelIntroShown = false;

            // Build the game area
            this.buildGameArea();

            // Render the map
            this.renderMap();

            // Render entities
            this.renderEntities();

            // Setup notifications
            this.setupNotifications();

            // Show level intro only at game start (round 0 or 1), not on reload or game end
            // Also check we're not in gameEnd state (state 99)
            const isGameStart = (gamedatas.round <= 1) && (gamedatas.gamestate?.name !== 'gameEnd');
            if (gamedatas.level_text && !this.levelIntroShown && isGameStart) {
                this.showLevelIntro();
            }

            console.log("Game setup complete");
        },

        showLevelIntro: function() {
            this.levelIntroShown = true;

            const levelName = this.gamedatas.level_name || 'Adventure';
            const levelText = this.gamedatas.level_text || '';
            const levelGoal = this.gamedatas.victory?.description || 'Complete the adventure';
            const levelGoalIcon = this.getVictoryIcon(this.gamedatas.victory?.type);
            
            // Get character goal for current player
            const myGoal = this.gamedatas.player_goals?.[this.player_id];
            const characterGoalHtml = myGoal ? `
                <div class="zq-level-intro-goal">
                    <span class="zq-level-intro-goal-label">${myGoal.goal_icon || '🎯'} Character Goal:</span>
                    <span class="zq-level-intro-goal-text">${myGoal.goal_description}</span>
                </div>
            ` : '';

            // Parse simple markdown bullet points
            const formattedText = this.formatLevelText(levelText);

            // Create overlay
            const overlay = document.createElement('div');
            overlay.className = 'zq-level-intro-overlay';
            overlay.id = 'zq-level-intro-overlay';
            document.body.appendChild(overlay);

            // Create popup
            const popup = document.createElement('div');
            popup.className = 'zq-level-intro-popup';
            popup.id = 'zq-level-intro-popup';
            popup.innerHTML = `
                <div class="zq-level-intro-title">${levelName}</div>
                <div class="zq-level-intro-goals">
                    <div class="zq-level-intro-goal">
                        <span class="zq-level-intro-goal-label">${levelGoalIcon} Level Goal:</span>
                        <span class="zq-level-intro-goal-text">${levelGoal}</span>
                    </div>
                    ${characterGoalHtml}
                </div>
                <div class="zq-level-intro-text">${formattedText}</div>
                <button class="zq-level-intro-start" id="zq-level-intro-start">⚔️ Begin Adventure</button>
            `;
            document.body.appendChild(popup);

            // Add click handler
            document.getElementById('zq-level-intro-start').addEventListener('click', () => {
                this.hideLevelIntro();
            });
        },

        formatLevelText: function(text) {
            // Convert markdown-style bullet points to HTML
            const lines = text.split('\n');
            let inList = false;
            let html = '';

            lines.forEach(line => {
                const trimmed = line.trim();
                if (trimmed.startsWith('- ') || trimmed.startsWith('* ')) {
                    if (!inList) {
                        html += '<ul>';
                        inList = true;
                    }
                    html += `<li>${trimmed.substring(2)}</li>`;
                } else if (trimmed) {
                    if (inList) {
                        html += '</ul>';
                        inList = false;
                    }
                    html += `<p>${trimmed}</p>`;
                }
            });

            if (inList) {
                html += '</ul>';
            }

            return html;
        },

        hideLevelIntro: function() {
            const overlay = document.getElementById('zq-level-intro-overlay');
            const popup = document.getElementById('zq-level-intro-popup');
            if (overlay) overlay.remove();
            if (popup) popup.remove();
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
            
            const victoryDesc = this.gamedatas.victory?.description || 'Defeat all monsters';
            const victoryIcon = this.getVictoryIcon(this.gamedatas.victory?.type);
            
            // Get personal goal for current player
            const myGoal = this.gamedatas.player_goals?.[this.player_id];
            const goalHtml = myGoal ? `
                <div id="zq-personal-goal" class="zq-panel">
                    <span class="zq-goal-icon">${myGoal.goal_icon}</span>
                    <span class="zq-goal-text">${myGoal.goal_description}</span>
                    <span class="zq-goal-progress">${myGoal.progress}/${myGoal.threshold}</span>
                </div>
            ` : '';
            
            gameArea.insertAdjacentHTML('beforeend', `
                <div id="zq-container">
                    <div id="zq-top-bar">
                    <div id="zq-round-display" class="zq-panel">
                            <span class="zq-label">Turn</span>
                        <span id="zq-round-number">${this.gamedatas.round}</span>
                            <span id="zq-round-location"></span>
                        </div>
                        <div id="zq-objective-display" class="zq-panel">
                            <span class="zq-objective-icon">${victoryIcon}</span>
                            <span class="zq-objective-text">${victoryDesc}</span>
                        </div>
                        ${goalHtml}
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
                            <h3>Action Deck</h3>
                            <div id="zq-action-buttons">
                                <div class="zq-no-action">Waiting for turn...</div>
                    </div>
                        </div>
                        <div id="zq-battle-panel" class="zq-panel">
                            <div id="zq-battle-content">
                                <div class="zq-no-battle">No active sequence</div>
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
            const mapContainer = document.getElementById('zq-map-container');

            // Apply scenario background image if provided
            if (this.gamedatas.background_image) {
                // In BGA, use g_gamethemeurl to get correct path to game assets
                const imgUrl = g_gamethemeurl + this.gamedatas.background_image;
                mapContainer.style.backgroundImage = `url('${imgUrl}')`;
                mapContainer.style.backgroundSize = 'cover';
                mapContainer.style.backgroundPosition = 'center';
            }

            // Calculate node positions (simple force-directed layout placeholder)
            const positions = this.calculateNodePositions(map);

            // Draw connections first (so they appear behind nodes)
            let connectionsHtml = '';
            map.connections.forEach(conn => {
                const from = positions[conn.location_from];
                const to = positions[conn.location_to];
                if (from && to) {
                    // Calculate midpoint for the label
                    const midX = (from.x + to.x) / 2;
                    const midY = (from.y + to.y) / 2;
                    
                    // Calculate angle for label rotation (optional, can be removed if too complex)
                    const angle = Math.atan2(to.y - from.y, to.x - from.x) * 180 / Math.PI;
                    // Keep text readable (not upside down)
                    const labelAngle = (angle > 90 || angle < -90) ? angle + 180 : angle;
                    
                    connectionsHtml += `
                        <line class="zq-connection" 
                              x1="${from.x}" y1="${from.y}" 
                              x2="${to.x}" y2="${to.y}"
                              data-from="${conn.location_from}"
                              data-to="${conn.location_to}">
                        </line>
                    `;
                    
                    // Add route name label if name exists
                    if (conn.name) {
                        connectionsHtml += `
                            <text class="zq-connection-label"
                                  x="${midX}" y="${midY}"
                                  data-from="${conn.location_from}"
                                  data-to="${conn.location_to}"
                                  transform="rotate(${labelAngle}, ${midX}, ${midY})">
                                ${conn.name}
                            </text>
                        `;
                    }
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
            // Use x,y coordinates from config if available (normalized 0-1)
            // Falls back to circular layout if no coordinates defined
            const positions = {};
            
            // Get actual container dimensions
            const container = document.getElementById('zq-map-container');
            const rect = container.getBoundingClientRect();
            const mapWidth = rect.width || 600;
            const mapHeight = rect.height || 500;
            const padding = 50;    // Keep nodes away from edges
            
            const hasCoordinates = map.locations.some(loc => loc.x !== undefined && loc.y !== undefined);
            
            if (hasCoordinates) {
                // Use config coordinates (normalized 0-1, scaled to container)
                map.locations.forEach(loc => {
                    const x = loc.x !== undefined ? loc.x : 0.5;
                    const y = loc.y !== undefined ? loc.y : 0.5;
                    positions[loc.location_id] = {
                        x: padding + x * (mapWidth - 2 * padding),
                        y: padding + y * (mapHeight - 2 * padding)
                    };
                });
            } else {
                // Fallback: circular layout
                const nodeCount = map.locations.length;
                const centerX = mapWidth / 2;
                const centerY = mapHeight / 2;
                const radius = Math.min(mapWidth, mapHeight) / 2 - padding;

                map.locations.forEach((loc, index) => {
                    const angle = (2 * Math.PI * index / nodeCount) - Math.PI / 2;
                    positions[loc.location_id] = {
                        x: centerX + radius * Math.cos(angle),
                        y: centerY + radius * Math.sin(angle)
                    };
                });
            }

            return positions;
        },

        renderEntities: function() {
            // Clear existing entity markers from nodes
            document.querySelectorAll('.zq-node-entities').forEach(el => el.innerHTML = '');

            // Filter entities: exclude player-type entities without a player_id (unselected characters)
            const visibleEntities = this.gamedatas.entities.filter(entity => {
                // Monsters are always visible
                if (entity.entity_type === 'monster') return true;
                // Players must have a player_id assigned (means they were selected)
                if (entity.entity_type === 'player' && entity.player_id) return true;
                // Hide unselected characters
                return false;
            });

            // Group entities by location
            const entitiesByLocation = {};
            visibleEntities.forEach(entity => {
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
                        
                        const icon = entity.entity_type === 'player' ? '⚔️' : this.getMonsterIcon(entity);
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
                const counts = entity.deck_counts || { active: 0, discard: 0, inactive: 0 };
                const health = counts.active + counts.discard;
                const level = entity.entity_level || 5;
                const statusClass = entity.is_defeated == 1 ? 'zq-defeated' : '';
                // Get player name if available
                const playerName = entity.player_id && this.gamedatas.players[entity.player_id] 
                    ? ` (${this.gamedatas.players[entity.player_id].name || 'Player'})` 
                    : '';
                // Build tags display
                const tags = entity.tags || [];
                const tagHtml = tags.map(t => this.getTagIcon(t.tag_name)).join(' ');
                // Build items display
                const items = entity.items || [];
                const itemsHtml = items.length > 0 
                    ? `<div class="zq-entity-items">📦 Items: ${items.map(i => i.item_name).join(', ')}</div>`
                    : '';
                html += `
                    <div class="zq-entity-info ${statusClass}" data-faction="${entity.faction || 'players'}">
                        <div class="zq-entity-name">⚔️ ${entity.entity_name}${playerName} ${tagHtml}</div>
                        <div class="zq-entity-class">${entity.entity_class} <span class="zq-level-badge">Lv.${level}</span></div>
                        <div class="zq-entity-location">📍 ${entity.location_name}</div>
                        <div class="zq-entity-health">❤️ Health: ${health}</div>
                        <div class="zq-deck-status">
                            <span class="zq-pile-active" title="Active (max ${level})">🃏 ${counts.active}/${level}</span>
                            <span class="zq-pile-discard" title="Discard">📥 ${counts.discard}</span>
                            <span class="zq-pile-inactive" title="Inactive">📦 ${counts.inactive}</span>
                        </div>
                        ${itemsHtml}
                    </div>
                `;
            });
            html += '</div>';

            html += '<div class="zq-entity-section"><h4>Monsters</h4>';
            if (monsters.length === 0) {
                html += '<div class="zq-no-monsters">All defeated!</div>';
            }
            monsters.forEach(entity => {
                const counts = entity.deck_counts || { active: 0, discard: 0, inactive: 0 };
                const health = counts.active + counts.discard;
                // Build tags display
                const tags = entity.tags || [];
                const tagHtml = tags.map(t => this.getTagIcon(t.tag_name)).join(' ');
                // Build items display
                const items = entity.items || [];
                const itemsHtml = items.length > 0 
                    ? `<div class="zq-entity-items">📦 ${items.map(i => i.item_name).join(', ')}</div>`
                    : '';
                const monsterIcon = this.getMonsterIcon(entity);
                html += `
                    <div class="zq-entity-info zq-monster" data-faction="${entity.faction || 'monsters'}">
                        <div class="zq-entity-name">${monsterIcon} ${entity.entity_name} ${tagHtml}</div>
                        <div class="zq-entity-class">${entity.entity_class} <span class="zq-faction-badge">${entity.faction || 'unknown'}</span></div>
                        <div class="zq-entity-location">📍 ${entity.location_name}</div>
                        <div class="zq-entity-health">❤️ Health: ${health}</div>
                        <div class="zq-deck-status">
                            <span class="zq-pile-active" title="Active">🃏 ${counts.active}</span>
                            <span class="zq-pile-discard" title="Discard">📥 ${counts.discard}</span>
                            <span class="zq-pile-inactive" title="Inactive">📦 ${counts.inactive}</span>
                        </div>
                        ${itemsHtml}
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
            
            // Track current state for popup button logic
            this.currentState = stateName;

            switch (stateName) {
                case 'CharacterSelection':
                    const isMultiactiveChar = args.multiactive && args.multiactive.includes(String(this.player_id));
                    const isActiveChar = this.isCurrentPlayerActive() || isMultiactiveChar;
                    console.log('CharacterSelection state - isActive:', isActiveChar);
                    
                    this.showCharacterSelectionUI(args.args, isActiveChar);
                    break;

                case 'MoveSelection':
                    // For multiactive states, check if this player is in the multiactive list
                    const isMultiactive = args.multiactive && args.multiactive.includes(String(this.player_id));
                    const isActive = this.isCurrentPlayerActive() || isMultiactive;
                    console.log('MoveSelection state - isActive:', isActive);
                    
                    // Store active status for popup button logic
                    this.isActiveInMoveSelection = isActive;
                    
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
                case 'CharacterSelection':
                    this.hideCharacterSelectionUI();
                    break;

                case 'MoveSelection':
                    this.hideMoveSelectionUI();
                    this.isActiveInMoveSelection = false;
                    break;

                case 'SequenceCleanup':
                    // Popup stays open - user must click Close button to dismiss
                    // Just hide the battle panel in the sidebar
                    this.hideBattlePanel();
                    break;
            }
        },

        onUpdateActionButtons: function(stateName, args) {
            console.log('onUpdateActionButtons:', stateName, args);
        },

        //
        // ──────────────────────────────────────────────────────────────────────
        //   CHARACTER SELECTION UI
        // ──────────────────────────────────────────────────────────────────────
        //

        showCharacterSelectionUI: function(args, isActive) {
            const panel = document.getElementById('zq-action-panel');
            const buttons = document.getElementById('zq-action-buttons');

            if (!panel || !buttons) {
                console.error('Action panel elements not found');
                return;
            }

            panel.classList.add('zq-panel-active');

            const available = args.availableCharacters || [];
            const assigned = args.assignedCharacters || [];
            
            // Store available characters for later access in click handler
            this.availableCharacters = available;

            // Build character cards
            let cardsHtml = '';
            
            if (available.length > 0) {
                cardsHtml += '<div class="zq-char-section-title">Available Characters</div>';
                cardsHtml += '<div class="zq-char-grid">';
                available.forEach(char => {
                    const deckSummary = this.summarizeDeck(char.deck || []);
                    cardsHtml += `
                        <div class="zq-char-card ${isActive ? 'zq-char-selectable' : ''}" 
                             data-character-id="${char.entity_id}">
                            <div class="zq-char-name">${char.entity_name}</div>
                            <div class="zq-char-class">${char.entity_class}</div>
                            <div class="zq-char-location">📍 ${char.location_name}</div>
                            <div class="zq-char-deck">${deckSummary}</div>
                            ${isActive ? '<div class="zq-char-select-hint">Click to select</div>' : ''}
                        </div>
                    `;
                });
                cardsHtml += '</div>';
            }

            if (assigned.length > 0) {
                cardsHtml += '<div class="zq-char-section-title zq-char-assigned-title">Already Chosen</div>';
                cardsHtml += '<div class="zq-char-grid zq-char-assigned">';
                assigned.forEach(char => {
                    cardsHtml += `
                        <div class="zq-char-card zq-char-taken" style="border-color: #${char.player_color}">
                            <div class="zq-char-name">${char.entity_name}</div>
                            <div class="zq-char-class">${char.entity_class}</div>
                            <div class="zq-char-owner" style="color: #${char.player_color}">${char.player_name}</div>
                        </div>
                    `;
                });
                cardsHtml += '</div>';
            }

            buttons.innerHTML = `
                <div class="zq-char-selection">
                    <div class="zq-char-header">
                        <h3>⚔️ Choose Your Character</h3>
                        ${isActive ? '<p>Select a character to begin your adventure</p>' : '<p>Waiting for other players...</p>'}
                    </div>
                    ${cardsHtml}
                </div>
            `;

            // Add click handlers for selectable characters
            if (isActive) {
                document.querySelectorAll('.zq-char-selectable').forEach(card => {
                    card.addEventListener('click', e => this.onCharacterCardClick(e));
                });
            }
        },

        hideCharacterSelectionUI: function() {
            const panel = document.getElementById('zq-action-panel');
            const buttons = document.getElementById('zq-action-buttons');

            if (panel) {
                panel.classList.remove('zq-panel-active');
            }
            if (buttons) {
                buttons.innerHTML = '<div class="zq-no-action">Waiting for game to start...</div>';
            }
            
            // Clear stored characters
            this.availableCharacters = null;
        },

        summarizeDeck: function(deck) {
            if (!deck || deck.length === 0) return 'No cards';
            
            const counts = {};
            deck.forEach(card => {
                counts[card] = (counts[card] || 0) + 1;
            });
            
            return Object.entries(counts)
                .map(([card, count]) => `${this.getCardIcon(card)}×${count}`)
                .join(' ');
        },

        onCharacterCardClick: function(e) {
            const card = e.currentTarget;
            const characterId = parseInt(card.dataset.characterId);
            
            if (!characterId) return;

            // Find the character data from stored available characters
            const character = this.availableCharacters?.find(c => 
                parseInt(c.entity_id) === characterId
            );
            
            if (!character) {
                console.error('Character not found:', characterId);
                return;
            }

            // Show preview popup instead of selecting immediately
            this.showCharacterPreviewPopup(character);
        },

        showCharacterPreviewPopup: function(character) {
            // Remove any existing popup
            this.hideCharacterPreviewPopup();

            // Store character data for selection
            this.previewCharacter = character;
            this.previewActiveCards = [...(character.active_cards || [])];
            this.previewInactiveCards = [...(character.inactive_cards || [])];
            this.previewEntityLevel = parseInt(character.entity_level) || 5;

            const popup = document.createElement('div');
            popup.id = 'zq-char-preview-popup';
            popup.className = 'zq-plan-popup-enhanced zq-char-preview-popup';
            popup.innerHTML = this.renderCharacterPreviewUI(character);

            document.body.appendChild(popup);

            // Setup click handlers for cards (reuse plan logic)
            this.setupCharacterPreviewCardHandlers();

            // Button handlers
            document.getElementById('zq-char-select-btn').addEventListener('click', () => this.onConfirmCharacterSelection());
            document.getElementById('zq-char-cancel-btn').addEventListener('click', () => this.hideCharacterPreviewPopup());
        },

        renderCharacterPreviewUI: function(character) {
            const entityLevel = this.previewEntityLevel;
            
            return `
                <div class="zq-plan-header">
                    <h3>⚔️ ${character.entity_name}</h3>
                    <p class="zq-char-preview-class">${character.entity_class} • ${character.location_name}</p>
                    <p>Arrange your starting deck. Click cards to move between Active ↔ Inactive.</p>
                </div>
                <div class="zq-plan-decks">
                    <div class="zq-plan-deck-column">
                        <div class="zq-plan-deck-header">
                            <span class="zq-deck-title">🃏 Active Deck</span>
                            <span class="zq-deck-count" id="zq-preview-active-count">${this.previewActiveCards.length}/${entityLevel}</span>
                        </div>
                        <div class="zq-plan-deck-list" id="zq-preview-active-list" data-pile="active">
                            ${this.renderPreviewCardList(this.previewActiveCards, 'active')}
                        </div>
                    </div>
                    <div class="zq-plan-deck-column">
                        <div class="zq-plan-deck-header">
                            <span class="zq-deck-title">📦 Inactive</span>
                            <span class="zq-deck-count" id="zq-preview-inactive-count">${this.previewInactiveCards.length}</span>
                        </div>
                        <div class="zq-plan-deck-list" id="zq-preview-inactive-list" data-pile="inactive">
                            ${this.renderPreviewCardList(this.previewInactiveCards, 'inactive')}
                        </div>
                    </div>
                </div>
                <div class="zq-plan-actions">
                    <button id="zq-char-select-btn" class="zq-plan-btn zq-plan-save">✓ Select Character</button>
                    <button id="zq-char-cancel-btn" class="zq-plan-btn zq-plan-cancel">✗ Cancel</button>
                </div>
            `;
        },

        renderPreviewCardList: function(cards, pile) {
            if (!cards || cards.length === 0) {
                return '<div class="zq-deck-empty-slot">Empty</div>';
            }
            return cards.map((card, idx) => `
                <div class="zq-preview-card-item" data-card-id="${card.card_id}" data-pile="${pile}">
                    <span class="zq-card-icon">${this.getCardIcon(card.card_type)}</span>
                    <span class="zq-card-name">${card.card_name || card.card_type}</span>
                    <span class="zq-card-meta">${card.card_type} ${card.card_power}</span>
                </div>
            `).join('');
        },

        setupCharacterPreviewCardHandlers: function() {
            const activeList = document.getElementById('zq-preview-active-list');
            const inactiveList = document.getElementById('zq-preview-inactive-list');
            
            const handleListClick = (e) => {
                const cardEl = e.target.closest('.zq-preview-card-item');
                if (cardEl) {
                    this.onPreviewCardClick({ currentTarget: cardEl });
                }
            };
            
            if (activeList) activeList.addEventListener('click', handleListClick);
            if (inactiveList) inactiveList.addEventListener('click', handleListClick);
        },

        onPreviewCardClick: function(e) {
            const cardEl = e.currentTarget;
            const cardId = parseInt(cardEl.dataset.cardId);
            const currentPile = cardEl.dataset.pile;
            const entityLevel = this.previewEntityLevel;

            if (currentPile === 'active') {
                // Move to inactive
                const cardIndex = this.previewActiveCards.findIndex(c => parseInt(c.card_id) === cardId);
                if (cardIndex !== -1) {
                    const [card] = this.previewActiveCards.splice(cardIndex, 1);
                    this.previewInactiveCards.push(card);
                }
            } else {
                // Move to active (check capacity)
                if (this.previewActiveCards.length >= entityLevel) {
                    this.showMessage(_("Active deck is full! Move a card to inactive first."), "error");
                    return;
                }
                const cardIndex = this.previewInactiveCards.findIndex(c => parseInt(c.card_id) === cardId);
                if (cardIndex !== -1) {
                    const [card] = this.previewInactiveCards.splice(cardIndex, 1);
                    this.previewActiveCards.push(card);
                }
            }

            // Re-render the lists
            this.updateCharacterPreviewLists();
        },

        updateCharacterPreviewLists: function() {
            const entityLevel = this.previewEntityLevel;
            const activeList = document.getElementById('zq-preview-active-list');
            const inactiveList = document.getElementById('zq-preview-inactive-list');
            const activeCount = document.getElementById('zq-preview-active-count');
            const inactiveCount = document.getElementById('zq-preview-inactive-count');

            if (activeList) {
                activeList.innerHTML = this.renderPreviewCardList(this.previewActiveCards, 'active');
            }
            if (inactiveList) {
                inactiveList.innerHTML = this.renderPreviewCardList(this.previewInactiveCards, 'inactive');
            }
            if (activeCount) {
                activeCount.textContent = `${this.previewActiveCards.length}/${entityLevel}`;
            }
            if (inactiveCount) {
                inactiveCount.textContent = `${this.previewInactiveCards.length}`;
            }
        },

        onConfirmCharacterSelection: function() {
            if (!this.previewCharacter) return;

            const characterId = parseInt(this.previewCharacter.entity_id);
            
            // Build deck arrangement
            const deckArrangement = JSON.stringify({
                activeCards: this.previewActiveCards.map(c => c.card_id),
                inactiveCards: this.previewInactiveCards.map(c => c.card_id)
            });

            // Disable all character cards
            document.querySelectorAll('.zq-char-selectable').forEach(c => {
                c.classList.remove('zq-char-selectable');
                c.classList.add('zq-char-selecting');
            });

            // Hide popup
            this.hideCharacterPreviewPopup();

            // Send selection to server
            this.bgaPerformAction('actSelectCharacter', {
                characterId: characterId,
                deckArrangement: deckArrangement
            }).catch(err => {
                console.error('Character selection failed:', err);
                // Re-enable selection on error
                document.querySelectorAll('.zq-char-selecting').forEach(c => {
                    c.classList.remove('zq-char-selecting');
                    c.classList.add('zq-char-selectable');
                });
            });
        },

        hideCharacterPreviewPopup: function() {
            const popup = document.getElementById('zq-char-preview-popup');
            if (popup) popup.remove();
            this.previewCharacter = null;
            this.previewActiveCards = [];
            this.previewInactiveCards = [];
            this.previewEntityLevel = 5;
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
            this.inactiveCards = args.inactiveCards || [];
            this.entityLevel = args.entityLevel || 5;
            this.canLevelUp = args.canLevelUp || false;
            this.originalActiveIds = this.activeCards.map(c => c.card_id);
            this.originalInactiveIds = this.inactiveCards.map(c => c.card_id);
            this.deckModified = false;
            this.levelUpMode = false;
            this.selectedForLevelUp = [];

            const hasHostiles = args.hasHostilesHere || false;
            
            // Build the UI - read-only deck display
            let html = `
                <div class="zq-current-location">
                    📍 <strong>${args.currentLocation.name}</strong>
                    ${hasHostiles ? '<span class="zq-hostiles-warning">⚠️ Hostiles!</span>' : ''}
                </div>
                <div class="zq-deck-info">
                    <div class="zq-deck-info-column">
                        <div class="zq-deck-header">
                            <span class="zq-deck-title">🃏 Active</span>
                            <span class="zq-deck-count">${this.activeCards.length}/${this.entityLevel}</span>
                </div>
                        <div class="zq-deck-list-readonly">
                            ${this.renderCardListReadonly(this.activeCards)}
                        </div>
                    </div>
                    <div class="zq-deck-info-column">
                        <div class="zq-deck-header">
                            <span class="zq-deck-title">📦 Inactive</span>
                            <span class="zq-deck-count">${this.inactiveCards.length}</span>
                        </div>
                        <div class="zq-deck-list-readonly">
                            ${this.renderCardListReadonly(this.inactiveCards)}
                        </div>
                    </div>
                </div>
                <div class="zq-deck-actions" id="zq-deck-actions">
                    <button id="zq-btn-plan" class="zq-deck-btn zq-btn-plan-deck">📋 Plan</button>
                    <button id="zq-btn-action" class="zq-deck-btn zq-btn-action-deck">⚔️ Act</button>
                </div>
                <div class="zq-move-hint">
                    Use Plan to manage cards. Click map to move.
                </div>
            `;
            buttons.innerHTML = html;

            // Add button handlers (no card click handlers - read-only)
            document.getElementById('zq-btn-plan').addEventListener('click', () => this.onPlanButtonClick());
            document.getElementById('zq-btn-action').addEventListener('click', () => this.onConfirmAction());

            // Highlight current location and adjacent nodes
            this.highlightCurrentLocation(args.currentLocation.id);
            this.highlightAdjacentNodes(args.adjacentLocations);

            // Enable map click handling
            this.enableMapClickHandling();

            panel.classList.add('zq-panel-active');
        },

        renderCardListReadonly: function(cards) {
            if (!cards || cards.length === 0) {
                return '<div class="zq-deck-empty-slot">Empty</div>';
            }
            return cards.map((card, idx) => `
                <div class="zq-deck-card-readonly">
                    <span class="zq-card-icon">${this.getCardIcon(card.card_type)}</span>
                    <span class="zq-card-name">${card.card_name || card.card_type}</span>
                    <span class="zq-card-meta">${card.card_type} ${card.card_power}</span>
                </div>
            `).join('');
        },

        renderCardList: function(cards, pile) {
            if (!cards || cards.length === 0) {
                return '<div class="zq-deck-empty-slot">Empty</div>';
            }
            return cards.map((card, idx) => `
                <div class="zq-deck-card-item" data-card-id="${card.card_id}" data-pile="${pile}">
                    <span class="zq-card-icon">${this.getCardIcon(card.card_type)}</span>
                    <span class="zq-card-name">${card.card_name || card.card_type}</span>
                    <span class="zq-card-meta">${card.card_type} ${card.card_power}</span>
                </div>
            `).join('');
        },

        onPlanButtonClick: function() {
            // Show the enhanced plan popup with card management
            this.showEnhancedPlanPopup();
        },

        showEnhancedPlanPopup: function() {
            console.log('=== SHOW ENHANCED PLAN POPUP ===');
            // Remove any existing popup
            this.hidePlanPopup();

            // Create working copies of card arrays
            this.planActiveCards = [...this.activeCards];
            this.planInactiveCards = [...this.inactiveCards];
            this.levelUpMode = false;
            this.selectedForLevelUp = [];

            console.log('Initial state:');
            console.log('  this.activeCards:', this.activeCards);
            console.log('  this.inactiveCards:', this.inactiveCards);
            console.log('  planActiveCards:', this.planActiveCards);
            console.log('  planInactiveCards:', this.planInactiveCards);
            console.log('  entityLevel:', this.entityLevel);

            const popup = document.createElement('div');
            popup.id = 'zq-plan-popup';
            popup.className = 'zq-plan-popup-enhanced';
            popup.innerHTML = this.renderEnhancedPlanUI();

            document.body.appendChild(popup);
            
            console.log('Popup HTML added to DOM');
            console.log('Active list innerHTML:', document.getElementById('zq-plan-active-list')?.innerHTML);
            console.log('Inactive list innerHTML:', document.getElementById('zq-plan-inactive-list')?.innerHTML);

            // Setup click handlers for cards
            this.setupPlanCardClickHandlers();

            // Button handlers
            document.getElementById('zq-plan-save').addEventListener('click', () => this.onSaveEnhancedPlan());
            document.getElementById('zq-plan-cancel').addEventListener('click', () => this.hidePlanPopup());
            
            const levelUpBtn = document.getElementById('zq-plan-levelup');
            if (levelUpBtn) {
                levelUpBtn.addEventListener('click', () => this.startLevelUpInPopup());
            }
            console.log('=================================');
        },

        renderEnhancedPlanUI: function() {
            const canLevelUp = this.planInactiveCards.length >= this.entityLevel;
            
            return `
                <div class="zq-plan-header">
                    <h3>📋 Manage Your Decks</h3>
                    <p>Click cards to move between Active ↔ Inactive. Drag to reorder.</p>
                </div>
                <div class="zq-plan-decks">
                    <div class="zq-plan-deck-column">
                        <div class="zq-plan-deck-header">
                            <span class="zq-deck-title">🃏 Active Deck</span>
                            <span class="zq-deck-count" id="zq-plan-active-count">${this.planActiveCards.length}/${this.entityLevel}</span>
                        </div>
                        <div class="zq-plan-deck-list" id="zq-plan-active-list" data-pile="active">
                            ${this.renderPlanCardList(this.planActiveCards, 'active')}
                        </div>
                    </div>
                    <div class="zq-plan-deck-column">
                        <div class="zq-plan-deck-header">
                            <span class="zq-deck-title">📦 Inactive</span>
                            <span class="zq-deck-count" id="zq-plan-inactive-count">${this.planInactiveCards.length}</span>
                        </div>
                        <div class="zq-plan-deck-list" id="zq-plan-inactive-list" data-pile="inactive">
                            ${this.renderPlanCardList(this.planInactiveCards, 'inactive')}
                        </div>
                    </div>
                </div>
                <div class="zq-plan-level-section" id="zq-plan-level-section" style="${canLevelUp ? '' : 'display:none'}">
                    <div class="zq-level-info">Level ${this.entityLevel} • ${this.planInactiveCards.length} inactive cards</div>
                    <button id="zq-plan-levelup" class="zq-plan-btn zq-btn-levelup">⬆️ Level Up (sacrifice ${this.entityLevel} cards)</button>
                </div>
                <div class="zq-plan-actions" id="zq-plan-actions">
                    <button id="zq-plan-save" class="zq-plan-btn zq-plan-save">✓ Save & Stay</button>
                    <button id="zq-plan-cancel" class="zq-plan-btn zq-plan-cancel">✗ Cancel</button>
                </div>
            `;
        },

        renderPlanCardList: function(cards, pile) {
            console.log('renderPlanCardList called with pile:', pile, 'cards:', cards?.length);
            if (!cards || cards.length === 0) {
                return '<div class="zq-deck-empty-slot">Empty</div>';
            }
            return cards.map((card, idx) => `
                <div class="zq-plan-card-item" draggable="true" data-card-id="${card.card_id}" data-pile="${pile}">
                    <span class="zq-card-icon">${this.getCardIcon(card.card_type)}</span>
                    <span class="zq-card-name">${card.card_name || card.card_type}</span>
                    <span class="zq-card-meta">${card.card_type} ${card.card_power}</span>
                </div>
            `).join('');
        },

        setupPlanCardClickHandlers: function() {
            // Use event delegation on the container lists for more robust click handling
            // This ensures clicks work even after cards are moved between lists
            const activeList = document.getElementById('zq-plan-active-list');
            const inactiveList = document.getElementById('zq-plan-inactive-list');
            
            console.log('=== PLAN CLICK HANDLERS SETUP ===');
            console.log('activeList element:', activeList);
            console.log('inactiveList element:', inactiveList);
            console.log('Active cards in list:', activeList?.querySelectorAll('.zq-plan-card-item').length);
            console.log('Inactive cards in list:', inactiveList?.querySelectorAll('.zq-plan-card-item').length);
            
            const handleListClick = (e) => {
                console.log('=== LIST CLICK EVENT ===');
                console.log('e.target:', e.target);
                console.log('e.target.className:', e.target.className);
                const cardEl = e.target.closest('.zq-plan-card-item');
                console.log('Found card element:', cardEl);
                if (cardEl) {
                    console.log('Card data-pile:', cardEl.dataset.pile);
                    console.log('Card data-card-id:', cardEl.dataset.cardId);
                    this.onPlanCardClick({ currentTarget: cardEl });
                } else {
                    console.log('No .zq-plan-card-item found in ancestors');
                }
            };
            
            if (activeList) {
                activeList.addEventListener('click', handleListClick);
                console.log('Added click handler to activeList');
            }
            if (inactiveList) {
                inactiveList.addEventListener('click', handleListClick);
                console.log('Added click handler to inactiveList');
            }
            console.log('=================================');
            
            // Also setup drag and drop for reordering
            this.setupPlanDragAndDrop();
        },

        onPlanCardClick: function(e) {
            const cardEl = e.currentTarget;
            const cardId = cardEl.dataset.cardId;
            const currentPile = cardEl.dataset.pile;

            console.log('=== onPlanCardClick ===');
            console.log('cardEl:', cardEl);
            console.log('cardId:', cardId);
            console.log('currentPile:', currentPile, '(type:', typeof currentPile, ')');
            console.log('levelUpMode:', this.levelUpMode);

            if (this.levelUpMode) {
                console.log('In levelUpMode - toggling sacrifice selection');
                // Toggle selection for sacrifice
                cardEl.classList.toggle('zq-selected-for-sacrifice');
                if (cardEl.classList.contains('zq-selected-for-sacrifice')) {
                    this.selectedForLevelUp.push(cardId);
                        } else {
                    this.selectedForLevelUp = this.selectedForLevelUp.filter(id => id !== cardId);
                }
                this.updateLevelUpUI();
                return;
            }

            // Move card between piles
            const activeList = document.getElementById('zq-plan-active-list');
            const inactiveList = document.getElementById('zq-plan-inactive-list');
            const activeCount = activeList.querySelectorAll('.zq-plan-card-item').length;

            console.log('activeList:', activeList);
            console.log('inactiveList:', inactiveList);
            console.log('activeCount:', activeCount);
            console.log('entityLevel:', this.entityLevel);
            console.log('Comparison: currentPile === "active":', currentPile === 'active');

            if (currentPile === 'active') {
                console.log('>>> MOVING TO INACTIVE');
                // Move to inactive
                cardEl.dataset.pile = 'inactive';
                inactiveList.appendChild(cardEl);
                // Update internal arrays
                const card = this.planActiveCards.find(c => c.card_id == cardId);
                console.log('Found card in planActiveCards:', card);
                if (card) {
                    this.planActiveCards = this.planActiveCards.filter(c => c.card_id != cardId);
                    this.planInactiveCards.push(card);
                }
                console.log('After move - planActiveCards:', this.planActiveCards.length, 'planInactiveCards:', this.planInactiveCards.length);
            } else {
                console.log('>>> MOVING TO ACTIVE');
                // Move to active - check capacity
                if (activeCount >= this.entityLevel) {
                    console.log('BLOCKED: Active deck at capacity');
                    this.showMessage(_("Active deck is at capacity! Move a card out first."), "error");
                    return;
                }
                cardEl.dataset.pile = 'active';
                activeList.appendChild(cardEl);
                // Update internal arrays
                const card = this.planInactiveCards.find(c => c.card_id == cardId);
                console.log('Found card in planInactiveCards:', card);
                if (card) {
                    this.planInactiveCards = this.planInactiveCards.filter(c => c.card_id != cardId);
                    this.planActiveCards.push(card);
                }
                console.log('After move - planActiveCards:', this.planActiveCards.length, 'planInactiveCards:', this.planInactiveCards.length);
            }

            // Remove empty placeholder if present
            const activeEmpty = activeList.querySelector('.zq-deck-empty-slot');
            const inactiveEmpty = inactiveList.querySelector('.zq-deck-empty-slot');
            if (activeEmpty) activeEmpty.remove();
            if (inactiveEmpty) inactiveEmpty.remove();

            // Add empty placeholder if needed
            if (activeList.querySelectorAll('.zq-plan-card-item').length === 0) {
                activeList.innerHTML = '<div class="zq-deck-empty-slot">Empty</div>';
            }
            if (inactiveList.querySelectorAll('.zq-plan-card-item').length === 0) {
                inactiveList.innerHTML = '<div class="zq-deck-empty-slot">Empty</div>';
            }

            this.updatePlanDeckCounts();
            console.log('=== onPlanCardClick complete ===');
        },

        updatePlanDeckCounts: function() {
            const activeCount = document.querySelectorAll('#zq-plan-active-list .zq-plan-card-item').length;
            const inactiveCount = document.querySelectorAll('#zq-plan-inactive-list .zq-plan-card-item').length;
            
            const activeCountEl = document.getElementById('zq-plan-active-count');
            const inactiveCountEl = document.getElementById('zq-plan-inactive-count');
            if (activeCountEl) activeCountEl.textContent = `${activeCount}/${this.entityLevel}`;
            if (inactiveCountEl) inactiveCountEl.textContent = inactiveCount;

            // Update level up section visibility
            const levelSection = document.getElementById('zq-plan-level-section');
            if (levelSection) {
                const canLevelUp = inactiveCount >= this.entityLevel;
                levelSection.style.display = canLevelUp ? '' : 'none';
            }
        },

        startLevelUpInPopup: function() {
            this.levelUpMode = true;
            this.selectedForLevelUp = [];

            // Change UI to level up mode
            const actionsDiv = document.getElementById('zq-plan-actions');
            actionsDiv.innerHTML = `
                <div class="zq-levelup-instructions">Select ${this.entityLevel} cards to sacrifice:</div>
                <div class="zq-levelup-count" id="zq-levelup-count">Selected: 0 / ${this.entityLevel}</div>
                <button id="zq-confirm-levelup" class="zq-plan-btn zq-btn-confirm-levelup" disabled>✓ Confirm Level Up</button>
                <button id="zq-cancel-levelup" class="zq-plan-btn zq-plan-cancel">✗ Cancel</button>
            `;

            document.getElementById('zq-confirm-levelup').addEventListener('click', () => this.confirmLevelUp());
            document.getElementById('zq-cancel-levelup').addEventListener('click', () => this.cancelLevelUp());

            // Add selection styling
            document.querySelectorAll('.zq-plan-card-item').forEach(card => {
                card.classList.add('zq-selectable-for-sacrifice');
            });
        },

        updateLevelUpUI: function() {
            const countEl = document.getElementById('zq-levelup-count');
            const confirmBtn = document.getElementById('zq-confirm-levelup');
            
            if (countEl) {
                countEl.textContent = `Selected: ${this.selectedForLevelUp.length} / ${this.entityLevel}`;
            }
            if (confirmBtn) {
                confirmBtn.disabled = this.selectedForLevelUp.length !== this.entityLevel;
            }
        },

        confirmLevelUp: function() {
            if (this.selectedForLevelUp.length !== this.entityLevel) {
                this.showMessage(_("Select exactly " + this.entityLevel + " cards to sacrifice."), "error");
                return;
            }

            // Send level up action to server
            this.bgaPerformAction('actLevelUp', {
                cardIdsJson: JSON.stringify(this.selectedForLevelUp)
            }).then(() => {
                this.hidePlanPopup();
            }).catch(err => {
                console.error('Level up failed:', err);
                // Re-enable the UI on error
                this.cancelLevelUp();
            });
        },

        cancelLevelUp: function() {
            this.levelUpMode = false;
            this.selectedForLevelUp = [];
            
            // Restore normal actions
            const actionsDiv = document.getElementById('zq-plan-actions');
            actionsDiv.innerHTML = `
                <button id="zq-plan-save" class="zq-plan-btn zq-plan-save">✓ Save & Stay</button>
                <button id="zq-plan-cancel" class="zq-plan-btn zq-plan-cancel">✗ Cancel</button>
            `;
            
            document.getElementById('zq-plan-save').addEventListener('click', () => this.onSaveEnhancedPlan());
            document.getElementById('zq-plan-cancel').addEventListener('click', () => this.hidePlanPopup());

            // Remove selection styling
            document.querySelectorAll('.zq-plan-card-item').forEach(card => {
                card.classList.remove('zq-selectable-for-sacrifice', 'zq-selected-for-sacrifice');
            });
        },

        onSaveEnhancedPlan: function() {
            // Get card order from DOM
            const activeIds = Array.from(document.querySelectorAll('#zq-plan-active-list .zq-plan-card-item'))
                .map(el => el.dataset.cardId);
            const inactiveIds = Array.from(document.querySelectorAll('#zq-plan-inactive-list .zq-plan-card-item'))
                .map(el => el.dataset.cardId);

            this.hidePlanPopup();

            // Send plan action with nested card data structure
            this.bgaPerformAction('actSelectLocation', {
                locationId: this.currentLocationId,
                cardOrder: JSON.stringify({
                    activeCards: activeIds,
                    inactiveCards: inactiveIds
                }),
                isPlan: true
            });
        },

        showLevelUpUI: function() {
            this.levelUpMode = true;
            this.selectedForLevelUp = [];

            // Update UI to show level up mode
            const actionsDiv = document.getElementById('zq-deck-actions');
            actionsDiv.innerHTML = `
                <div class="zq-levelup-info">
                    ⬆️ Select <strong>${this.entityLevel}</strong> cards to sacrifice
                    <span id="zq-levelup-selected">0/${this.entityLevel}</span>
                </div>
                <button id="zq-btn-confirm-levelup" class="zq-deck-btn zq-btn-confirm" disabled>Confirm Level Up</button>
                <button id="zq-btn-cancel-levelup" class="zq-deck-btn zq-btn-cancel">Cancel</button>
            `;

            document.getElementById('zq-btn-confirm-levelup').addEventListener('click', () => this.confirmLevelUp());
            document.getElementById('zq-btn-cancel-levelup').addEventListener('click', () => this.cancelLevelUp());

            // Add visual indicator
            document.querySelectorAll('.zq-deck-card-item').forEach(card => {
                card.classList.add('zq-levelup-selectable');
            });
        },

        toggleLevelUpSelection: function(cardEl, cardId) {
            if (cardEl.classList.contains('zq-levelup-selected')) {
                // Deselect
                cardEl.classList.remove('zq-levelup-selected');
                this.selectedForLevelUp = this.selectedForLevelUp.filter(id => id !== cardId);
                        } else {
                // Select (if not at limit)
                if (this.selectedForLevelUp.length >= this.entityLevel) {
                    this.showMessage(_("Already selected enough cards"), "info");
                    return;
                }
                cardEl.classList.add('zq-levelup-selected');
                this.selectedForLevelUp.push(cardId);
            }

            // Update counter and button state
            document.getElementById('zq-levelup-selected').textContent = 
                `${this.selectedForLevelUp.length}/${this.entityLevel}`;
            
            const confirmBtn = document.getElementById('zq-btn-confirm-levelup');
            confirmBtn.disabled = this.selectedForLevelUp.length !== this.entityLevel;
        },

        // Note: confirmLevelUp is defined earlier in startLevelUpInPopup context
        // This duplicate was causing issues - removed

        // Legacy function - no longer used with read-only panel
        cancelLevelUp: function() {
            this.levelUpMode = false;
            this.selectedForLevelUp = [];
        },

        checkDeckModified: function() {
            const currentActiveIds = this.getCurrentActiveCardIds();
            const currentInactiveIds = this.getCurrentInactiveCardIds();
            
            const activeChanged = JSON.stringify(currentActiveIds) !== JSON.stringify(this.originalActiveIds);
            const inactiveChanged = JSON.stringify(currentInactiveIds) !== JSON.stringify(this.originalInactiveIds);
            
            this.deckModified = activeChanged || inactiveChanged;
            
            // Visual indicator that deck has been modified
            const activeList = document.getElementById('zq-active-list');
            const inactiveList = document.getElementById('zq-inactive-list');
            if (activeList) activeList.classList.toggle('zq-deck-modified', this.deckModified);
            if (inactiveList) inactiveList.classList.toggle('zq-deck-modified', this.deckModified);
        },

        getCurrentActiveCardIds: function() {
            const cards = document.querySelectorAll('#zq-active-list .zq-deck-card-item');
            return Array.from(cards).map(card => card.dataset.cardId);
        },

        getCurrentInactiveCardIds: function() {
            const cards = document.querySelectorAll('#zq-inactive-list .zq-deck-card-item');
            return Array.from(cards).map(card => card.dataset.cardId);
        },

        onConfirmDeckPlan: function() {
            // Get the new card arrangement
            const activeCardIds = this.getCurrentActiveCardIds();
            const inactiveCardIds = this.getCurrentInactiveCardIds();
            
            // Validate active deck not over capacity
            if (activeCardIds.length > this.entityLevel) {
                this.showMessage(_("Active deck exceeds capacity! Move cards to inactive first."), "error");
                return;
            }
            
            // Submit as a "plan" action with new format
            const cardData = {
                activeCards: activeCardIds,
                inactiveCards: inactiveCardIds
            };
            this.submitMoveChoice(this.currentLocationId, cardData, true);
        },

        onConfirmAction: function() {
            // Get the new card order (in case user reordered)
            const cardOrder = this.getCurrentDeckOrder();
            
            // Submit as an "action" - participate in action sequence with this deck order
            this.submitMoveChoice(this.currentLocationId, cardOrder, false);
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
            // This is now handled by the inline deck editor
            // Just stay without planning
            this.submitMoveChoice(locationId, null, false);
        },

        confirmMove: function(locationId) {
            // Check if deck was modified - warn before moving
            if (this.deckModified) {
                if (!confirm('You have unsaved deck changes. Moving will lose these changes. Continue?')) {
                    return;
                }
            }

            // Find location name
            const loc = this.adjacentLocations.find(l => l.location_id === locationId);
            const locName = loc ? loc.location_name : locationId;

            // Highlight selected
            document.querySelectorAll('.zq-node').forEach(n => n.classList.remove('zq-node-selected'));
            document.getElementById(`zq-node-${locationId}`)?.classList.add('zq-node-selected');

            // Submit move (no plan)
            this.submitMoveChoice(locationId, null, false);
        },

        submitMoveChoice: function(locationId, cardData, isPlan = false) {
            console.log('Submitting move choice:', locationId, cardData, 'isPlan:', isPlan);
            
            this.bgaPerformAction('actSelectLocation', {
                locationId: locationId,
                cardOrder: cardData ? JSON.stringify(cardData) : null,
                isPlan: isPlan,
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
            // Clear previous highlights
            document.querySelectorAll('.zq-node').forEach(node => {
                node.classList.remove('zq-node-adjacent');
            });
            document.querySelectorAll('.zq-connection').forEach(conn => {
                conn.classList.remove('zq-connection-adjacent');
            });
            document.querySelectorAll('.zq-connection-label').forEach(label => {
                label.classList.remove('zq-connection-label-adjacent');
            });

            // Get current location ID from highlighted node
            const currentNode = document.querySelector('.zq-node-current');
            const currentLocationId = currentNode ? currentNode.dataset.location : null;

            adjacentLocations.forEach(loc => {
                // Highlight the adjacent node
                const node = document.getElementById(`zq-node-${loc.location_id}`);
                if (node) {
                    node.classList.add('zq-node-adjacent');
                }

                // Highlight the connection line between current and adjacent
                if (currentLocationId) {
                    document.querySelectorAll('.zq-connection').forEach(conn => {
                        const from = conn.dataset.from;
                        const to = conn.dataset.to;
                        if ((from === currentLocationId && to === loc.location_id) ||
                            (to === currentLocationId && from === loc.location_id)) {
                            conn.classList.add('zq-connection-adjacent');
                        }
                    });
                    
                    // Highlight the route label
                    document.querySelectorAll('.zq-connection-label').forEach(label => {
                        const from = label.dataset.from;
                        const to = label.dataset.to;
                        if ((from === currentLocationId && to === loc.location_id) ||
                            (to === currentLocationId && from === loc.location_id)) {
                            label.classList.add('zq-connection-label-adjacent');
                        }
                    });
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
                            <span class="zq-plan-card-name">${card.card_name || card.card_type}</span>
                            <span class="zq-card-meta">${card.card_type} ${card.card_power}</span>
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
            // Setup drag and drop for both active and inactive lists
            const activeList = document.getElementById('zq-plan-active-list');
            const inactiveList = document.getElementById('zq-plan-inactive-list');
            
            // Also support old single-list popup
            const oldContainer = document.getElementById('zq-plan-cards-list');
            
            if (!activeList && !oldContainer) return;

            let draggedEl = null;

            const setupDragForCard = (card) => {
                card.addEventListener('dragstart', (e) => {
                    draggedEl = card;
                    card.classList.add('zq-dragging');
                    e.dataTransfer.effectAllowed = 'move';
                });

                card.addEventListener('dragend', () => {
                    card.classList.remove('zq-dragging');
                    draggedEl = null;
                });

                card.addEventListener('dragover', (e) => {
                    e.preventDefault();
                    e.dataTransfer.dropEffect = 'move';
                    
                    if (draggedEl && draggedEl !== card) {
                        const rect = card.getBoundingClientRect();
                        const midY = rect.top + rect.height / 2;
                        const container = card.parentElement;
                        
                        if (e.clientY < midY) {
                            container.insertBefore(draggedEl, card);
                        } else {
                            container.insertBefore(draggedEl, card.nextSibling);
                        }
                    }
                });
            };

            // Setup for enhanced popup with two lists
            if (activeList) {
                activeList.querySelectorAll('.zq-plan-card-item').forEach(setupDragForCard);
                inactiveList?.querySelectorAll('.zq-plan-card-item').forEach(setupDragForCard);
            }
            
            // Setup for old popup with single list
            if (oldContainer) {
                oldContainer.querySelectorAll('.zq-plan-card').forEach(setupDragForCard);
            }
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
            e.stopPropagation();
            const locationId = e.currentTarget.dataset.location;
            this.showLocationPopup(locationId, e.currentTarget);
        },

        showLocationPopup: function(locationId, nodeElement) {
            // Remove any existing popup
            this.hideLocationPopup();

            // Find location info
            const location = this.gamedatas.map.locations.find(l => l.location_id === locationId);
            if (!location) return;

            const myEntity = this.getMyEntity();
            const isMyLocation = myEntity && myEntity.location_id === locationId;
            const isInMoveSelection = this.currentState === 'MoveSelection' && this.isActiveInMoveSelection;
            
            // Debug logging
            console.log('Popup debug:', {
                locationId,
                myEntity,
                myEntityLocation: myEntity?.location_id,
                isMyLocation,
                currentState: this.currentState,
                isActiveInMoveSelection: this.isActiveInMoveSelection,
                isInMoveSelection
            });
            
            // Check if this is an adjacent location (can move to)
            const isAdjacent = this.adjacentLocations && 
                this.adjacentLocations.some(adj => adj.location_id === locationId);

            // Determine entities at this location (filter out unselected player characters)
            const entitiesHere = this.gamedatas.entities
                .filter(e => e.location_id === locationId && !e.is_defeated)
                .filter(e => e.entity_type === 'monster' || (e.entity_type === 'player' && e.player_id));
            const entityList = entitiesHere.map(e => {
                const icon = e.entity_type === 'player' ? '⚔️' : this.getMonsterIcon(e);
                return `${icon} ${e.entity_name}`;
            }).join('<br>');

            // Build action buttons
            let buttonsHtml = '<div class="zq-popup-buttons">';
            
            // Log button - always available
            buttonsHtml += `<button class="zq-popup-btn zq-btn-log" onclick="gameui.showLocationLog('${locationId}')">📜 Log</button>`;
            
            if (isInMoveSelection) {
                if (isMyLocation) {
                    // Current location - Plan or Action
                    buttonsHtml += `<button class="zq-popup-btn zq-btn-plan" onclick="gameui.onPlanClick()">📋 Plan</button>`;
                    buttonsHtml += `<button class="zq-popup-btn zq-btn-action" onclick="gameui.onActionClick()">⚔️ Act</button>`;
                } else if (isAdjacent) {
                    // Adjacent location - Move
                    buttonsHtml += `<button class="zq-popup-btn zq-btn-move" onclick="gameui.onMoveClick('${locationId}')">🚶 Move</button>`;
                }
            }
            
            buttonsHtml += '</div>';

            // Create popup
            const popup = document.createElement('div');
            popup.id = 'zq-location-popup';
            popup.className = 'zq-location-popup';
            popup.innerHTML = `
                <div class="zq-popup-close" onclick="gameui.hideLocationPopup()">×</div>
                <div class="zq-popup-name">${location.location_name}</div>
                <div class="zq-popup-description">${location.location_description || ''}</div>
                <div class="zq-popup-entities">${entityList || '📍 Empty'}</div>
                ${buttonsHtml}
            `;

            // Position popup near the node
            const rect = nodeElement.getBoundingClientRect();
            const container = document.getElementById('zq-map-container');
            const containerRect = container.getBoundingClientRect();
            
            // Add popup to DOM first to measure its height
            container.appendChild(popup);
            const popupRect = popup.getBoundingClientRect();
            
            // Check if location is in bottom third of map
            const nodeRelativeY = rect.top - containerRect.top + rect.height / 2;
            const isInBottomThird = nodeRelativeY > (containerRect.height * 0.67);
            
            popup.style.left = (rect.left - containerRect.left + rect.width / 2) + 'px';
            
            if (isInBottomThird) {
                // Position above the node
                popup.style.top = (rect.top - containerRect.top - popupRect.height - 10) + 'px';
                popup.classList.add('zq-popup-above');
            } else {
                // Position below the node (default)
            popup.style.top = (rect.top - containerRect.top + rect.height + 10) + 'px';
            }

            // Store reference for button handlers
            this.currentPopupLocation = locationId;
        },

        hideLocationPopup: function() {
            const popup = document.getElementById('zq-location-popup');
            if (popup) popup.remove();
            this.currentPopupLocation = null;
        },

        // Called when Plan button is clicked (from location popup)
        onPlanClick: function() {
            this.hideLocationPopup();
            // Show the enhanced plan popup with deck management
            this.showEnhancedPlanPopup();
        },

        // Called when Action button is clicked (stay and participate in sequence)
        onActionClick: function() {
            this.hideLocationPopup();
            
            // Send action - staying at current location, not planning
            this.bgaPerformAction('actSelectLocation', {
                locationId: this.currentLocationId,
                cardOrder: null,
                isPlan: false
            });
        },

        // Called when Move button is clicked
        onMoveClick: function(locationId) {
            this.hideLocationPopup();
            
            // Send move action
            this.bgaPerformAction('actSelectLocation', {
                locationId: locationId,
                cardOrder: null,
                isPlan: false
            });
        },

        // Show the log for a specific location (all action sequences at this location)
        showLocationLog: function(locationId) {
            this.hideLocationPopup();
            
            const logs = this.gamedatas.location_logs?.[locationId] || [];
            const location = this.gamedatas.map.locations.find(l => l.location_id === locationId);
            const locationName = location ? location.location_name : locationId;
            
            // Create log popup
            const popup = document.createElement('div');
            popup.id = 'zq-log-popup';
            popup.className = 'zq-log-popup';
            
            let logsHtml = '';
            if (logs.length === 0) {
                logsHtml = '<div class="zq-log-empty">No events recorded at this location.</div>';
            } else {
                // Sort logs by round (oldest first for chronological reading)
                const sortedLogs = [...logs].sort((a, b) => a.round - b.round);
                
                sortedLogs.forEach(log => {
                    const data = log.log_data;
                    
                    // Game round header
                    let entryHtml = `<div class="zq-log-entry">`;
                    entryHtml += `<div class="zq-log-round-header">Turn ${log.round}</div>`;
                    
                    // Detailed round-by-round actions (if available)
                    if (data.rounds && data.rounds.length > 0) {
                        data.rounds.forEach(round => {
                            entryHtml += `<div class="zq-log-action"><span class="zq-log-round-num">Round ${round.round}:</span> ${round.log}</div>`;
                        });
                    }
                    
                    // Outcome summary
                    let outcome = '';
                    if (data.defeated && data.defeated.length > 0) {
                        const defeatedNames = data.defeated.map(d => `<span class="zq-log-defeated">${d.entity_name}</span>`).join(', ');
                        outcome += `${defeatedNames} defeated. `;
                    }
                    if (data.survivors && data.survivors.length > 0) {
                        const survivorNames = data.survivors.map(s => `<span class="zq-log-survivor">${s.entity_name}</span>`).join(', ');
                        outcome += `Survivors: ${survivorNames}`;
                    }
                    if (outcome) {
                        entryHtml += `<div class="zq-log-outcome">${outcome}</div>`;
                    }
                    
                    entryHtml += `</div>`;
                    logsHtml += entryHtml;
                });
            }
            
            popup.innerHTML = `
                <div class="zq-log-header">
                    <span>📜 ${locationName} History</span>
                    <div class="zq-popup-close" onclick="document.getElementById('zq-log-popup').remove()">×</div>
                </div>
                <div class="zq-log-content">${logsHtml}</div>
            `;
            
            document.getElementById('zq-map-container').appendChild(popup);
        },

        getMyEntity: function() {
            const playerId = String(this.player_id);
            // Compare as strings to handle type mismatches (number vs string)
            const entity = this.gamedatas.entities.find(e => e.player_id && String(e.player_id) === playerId);
            if (!entity) {
                console.log('getMyEntity failed. Looking for player_id:', playerId);
                console.log('Available entities:', this.gamedatas.entities.map(e => ({
                    entity_id: e.entity_id,
                    entity_name: e.entity_name,
                    player_id: e.player_id,
                    entity_type: e.entity_type
                })));
            }
            return entity;
        },

        highlightCard: function(cardId) {
            // Clear any existing highlights
            this.clearCardHighlight();
            
            // Find and highlight the card in the deck panel
            const cardEl = document.querySelector(`#zq-deck-list [data-card-id="${cardId}"]`);
            if (cardEl) {
                cardEl.classList.add('zq-card-playing');
            }
        },

        clearCardHighlight: function() {
            document.querySelectorAll('.zq-card-playing').forEach(el => {
                el.classList.remove('zq-card-playing');
            });
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

        // ──────────────────────────────────────────────────────────────────────
        //   SEQUENCE POPUP MODAL
        // ──────────────────────────────────────────────────────────────────────

        showSequencePopup: function(locationId, locationName, participants) {
            // Remove any existing popup
            this.closeSequencePopup();

            // Get the node's position to place the popup near it
            const node = document.getElementById(`zq-node-${locationId}`);
            const mapContainer = document.getElementById('zq-map-container');
            if (!node || !mapContainer) return;

            const nodeRect = node.getBoundingClientRect();
            const mapRect = mapContainer.getBoundingClientRect();

            // Calculate node position relative to map container
            const nodeRelativeX = nodeRect.left - mapRect.left + nodeRect.width / 2;
            const nodeRelativeY = nodeRect.top - mapRect.top + nodeRect.height / 2;

            // Create the popup
            const popup = document.createElement('div');
            popup.id = 'zq-sequence-popup';
            popup.className = 'zq-sequence-popup';
            
            popup.innerHTML = `
                <div class="zq-sequence-popup-header">
                    <span class="zq-sequence-popup-title">⚔️ ${locationName}</span>
                    <span class="zq-sequence-popup-close" onclick="gameui.closeSequencePopup()">×</span>
                </div>
                <div class="zq-sequence-popup-participants">
                    ${participants.map(p => {
                        const icon = p.entity_type === 'player' ? '⚔️' : this.getMonsterIcon(p);
                        return `<span class="zq-popup-participant ${p.entity_type}">${icon} ${p.entity_name}</span>`;
                    }).join('')}
                </div>
                <div class="zq-sequence-popup-log" id="zq-sequence-popup-log"></div>
                <div class="zq-sequence-popup-footer" id="zq-sequence-popup-footer" style="display: none;">
                    <button class="zq-sequence-close-btn" onclick="gameui.closeSequencePopup()">Close</button>
                </div>
            `;

            // Add popup to DOM first to measure its actual size
            mapContainer.appendChild(popup);
            const popupRect = popup.getBoundingClientRect();
            const popupWidth = popupRect.width;
            const popupHeight = popupRect.height;
            const margin = 10;

            // Check if node is in bottom half of map - if so, position popup above
            const isInBottomHalf = nodeRelativeY > (mapRect.height / 2);
            
            // Position horizontally - prefer right of node, then left, then centered
            let popupX = nodeRelativeX + 60;  // Right of node
            if (popupX + popupWidth > mapRect.width - margin) {
                popupX = nodeRelativeX - popupWidth - 60; // Left of node instead
            }
            if (popupX < margin) {
                popupX = margin; // Clamp to left edge
            }
            
            // Position vertically based on node position
            let popupY;
            if (isInBottomHalf) {
                // Node is in bottom half - position popup above the node
                popupY = nodeRelativeY - nodeRect.height / 2 - popupHeight - 20;
                if (popupY < margin) {
                    popupY = margin;
                }
            } else {
                // Node is in top half - position popup below the node
                popupY = nodeRelativeY + nodeRect.height / 2 + 20;
                if (popupY + popupHeight > mapRect.height - margin) {
                    popupY = mapRect.height - popupHeight - margin;
                }
            }

            popup.style.left = `${popupX}px`;
            popup.style.top = `${popupY}px`;
        },

        closeSequencePopup: function() {
            const popup = document.getElementById('zq-sequence-popup');
            if (popup) {
                popup.remove();
            }
            // Also clean up location highlight
            if (this.activeSequenceLocationId) {
                const node = document.getElementById(`zq-node-${this.activeSequenceLocationId}`);
                if (node) {
                    node.classList.remove('zq-node-active-sequence');
                }
                this.activeSequenceLocationId = null;
            }
        },

        showSequencePopupCloseButton: function() {
            const footer = document.getElementById('zq-sequence-popup-footer');
            if (footer) {
                footer.style.display = 'block';
            }
        },

        getSequencePopupLog: function() {
            return document.getElementById('zq-sequence-popup-log');
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
            
            // Log preference configuration
            console.log('=== ANIMATION PREFERENCE DEBUG ===');
            console.log('animationDelays config:', this.animationDelays);
            try {
                const pref = this.getGameUserPreference(100);
                console.log('Preference 100 (Animation Speed) raw value:', pref, typeof pref);
                const speeds = { 0: 'instant', 1: 'fast', 2: 'normal', 3: 'slow' };
                console.log('Mapped speed:', speeds[pref] || 'normal (fallback)');
                console.log('Resulting delay (ms):', this.getAnimationDelay());
            } catch (e) {
                console.log('Could not read preference:', e);
            }
            console.log('=================================');
            
            this.bgaSetupPromiseNotifications();
        },

        notif_locationLogAdded: async function(args) {
            // Update local location logs
            if (!this.gamedatas.location_logs) {
                this.gamedatas.location_logs = {};
            }
            if (!this.gamedatas.location_logs[args.location_id]) {
                this.gamedatas.location_logs[args.location_id] = [];
            }
            
            // Check if this log already exists (prevent duplicates from replayed notifications)
            const existingLog = this.gamedatas.location_logs[args.location_id].find(
                log => log.log_id === args.log_id
            );
            
            if (!existingLog) {
                // Add to front of array (newest first)
                this.gamedatas.location_logs[args.location_id].unshift({
                    log_id: args.log_id,
                    round: args.round,
                    log_type: args.log_type,
                    log_data: args.log_data,
                });
            }
        },

        notif_characterSelected: async function(args) {
            console.log('Character selected:', args);
            
            // Update the UI to show this character as taken
            const card = document.querySelector(`.zq-char-card[data-character-id="${args.character_id}"]`);
            if (card) {
                card.classList.remove('zq-char-selectable', 'zq-char-selecting');
                card.classList.add('zq-char-taken');
                card.innerHTML = `
                    <div class="zq-char-name">${args.character_name}</div>
                    <div class="zq-char-class">${args.character_class}</div>
                    <div class="zq-char-owner">${args.player_name}</div>
                `;
            }
            
            // Update gamedatas to reflect the character is now a player entity
            const entity = this.gamedatas.entities.find(e => e.entity_id == args.character_id);
            if (entity) {
                entity.entity_type = 'player';
                entity.player_id = args.player_id;
            }
            
            await this.wait(300);
        },

        notif_roundStart: async function(args) {
            console.log('Round start notification:', args);
            const roundNum = document.getElementById('zq-round-number');
            if (roundNum) roundNum.textContent = args.round;
            
            // Update goal progress if provided
            if (args.goal_progress !== undefined) {
                this.updateGoalProgress(args.goal_progress, args.goal_complete);
            }
            
            // Re-render entities to show updated positions
            this.renderEntities();
        },

        updateGoalProgress: function(progress, complete) {
            const progressEl = document.querySelector('.zq-goal-progress');
            const goalEl = document.getElementById('zq-personal-goal');
            
            if (progressEl && this.gamedatas.player_goals?.[this.player_id]) {
                const goal = this.gamedatas.player_goals[this.player_id];
                goal.progress = progress;
                goal.complete = complete;
                progressEl.textContent = `${progress}/${goal.threshold}`;
                
                if (complete && goalEl) {
                    goalEl.classList.add('zq-goal-complete');
                }
            }
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

        notif_entityLeveledUp: async function(args) {
            console.log('Entity leveled up:', args);
            
            // Update local entity data
            const entity = this.gamedatas.entities.find(e => e.entity_id == args.entity_id);
            if (entity) {
                entity.entity_level = args.level;
            }
            
            // Show a message
            this.showMessage(`${args.entity_name} reached level ${args.level}!`, 'info');
            
            // Update entity panel
            this.updateEntityPanel();
            
            await this.wait(this.getAnimationDelay());
        },

        notif_sequenceStart: async function(args) {
            console.log('=== SEQUENCE START ===');
            console.log('Full sequence data:', JSON.stringify(args, null, 2));
            console.log('Timestamp:', new Date().toISOString());

            this.battleEnded = false;
            this.activeSequenceLocationId = args.location_id;

            // Highlight the location with active combat
            if (args.location_id) {
                const node = document.getElementById(`zq-node-${args.location_id}`);
                if (node) {
                    node.classList.add('zq-node-active-sequence');
                }
            }

            // Show the popup modal over the location
            this.showSequencePopup(args.location_id, args.location_name, args.participants);

            const delay = this.getAnimationDelay();
            console.log('sequenceStart applying delay:', delay, 'ms');
            await this.wait(delay);
            console.log('sequenceStart delay complete');
        },

        notif_sequenceCardsDrawn: async function(args) {
            console.log('=== SEQUENCE CARDS DRAWN (Round ' + args.round + ') ===');
            console.log('Cards drawn data:', args.drawn_cards);
            console.log('Timestamp:', new Date().toISOString());

            const log = this.getSequencePopupLog();
            if (log && args.drawn_cards) {
                let html = '<div class="zq-cards-drawn"><strong>Cards drawn:</strong>';
                args.drawn_cards.forEach(card => {
                    const icon = this.getCardIcon(card.card_type);
                    const targetText = card.target_name ? ` → ${card.target_name}` : '';
                    const cardName = card.card_name || card.card_type;
                    const power = card.card_power > 1 ? ` (×${card.card_power})` : '';
                    html += `<div class="zq-drawn-card ${card.entity_type}">
                        ${card.entity_name}: ${icon} ${cardName}${power}${targetText}
                    </div>`;
                });
                html += '</div>';
                log.innerHTML += html;
            }

            // Highlight the current player's drawn card in the deck panel
            const myEntity = this.getMyEntity();
            if (myEntity && args.drawn_cards) {
                const myCard = args.drawn_cards.find(c => c.entity_id == myEntity.entity_id);
                if (myCard) {
                    this.highlightCard(myCard.card_id);
                }
            }

            const delay = this.getAnimationDelay();
            console.log('sequenceCardsDrawn applying delay:', delay, 'ms');
            await this.wait(delay);
            console.log('sequenceCardsDrawn delay complete');
        },

        notif_cardResolved: async function(args) {
            console.log('=== CARD RESOLVED ===');
            console.log('Card:', args.card_type, 'Entity:', args.entity_name, 'Effect:', args.effect);
            console.log('Timestamp:', new Date().toISOString());

            const log = document.getElementById('zq-battle-log');
            if (log) {
                const icon = this.getCardIcon(args.card_type);
                const cardName = args.card_name || args.card_type || 'card';
                let effectText = '';
                let effectClass = '';

                switch (args.effect) {
                    // New marker-based effects
                    case 'watch':
                        effectText = `👁️ +${args.markers_placed || 1} watch`;
                        effectClass = 'zq-effect-neutral';
                        break;
                    case 'watch_cancels_sneak':
                        effectText = `👁️ Cancels ${args.cancelled || 1} sneak!`;
                        effectClass = 'zq-effect-blocked';
                        break;
                    case 'sneak':
                        effectText = `🥷 +${args.markers_placed || 1} sneak (hidden)`;
                        effectClass = 'zq-effect-success';
                        break;
                    case 'mark':
                        effectText = `🎯 ${args.target_name} marked (+${args.markers_placed || 1} bonus)`;
                        effectClass = 'zq-effect-danger';
                        break;
                    case 'attack':
                        const sneakBonus = args.sneak_bonus ? ` +${args.sneak_bonus}🥷` : '';
                        const markBonus = args.mark_bonus ? ` +${args.mark_bonus}🎯` : '';
                        effectText = `⚔️ ${args.total_attack || args.card_power} damage → ${args.target_name}${sneakBonus}${markBonus}`;
                        effectClass = 'zq-effect-damage';
                        break;
                    case 'defend':
                        const dSneakBonus = args.sneak_bonus ? ` +${args.sneak_bonus}🥷` : '';
                        effectText = `🛡️ +${args.total_defend || args.card_power} block → ${args.target_name}${dSneakBonus}`;
                        effectClass = 'zq-effect-defend';
                        break;
                    case 'combat_resolution':
                        let parts = [];
                        if (args.cancelled > 0) parts.push(`${args.cancelled} blocked`);
                        if (args.damage > 0) parts.push(`${args.damage} dmg taken`);
                        if (args.defend_remaining > 0) parts.push(`${args.defend_remaining} block left`);
                        if (args.defeated) parts.push('💀 DEFEATED!');
                        effectText = parts.join(', ');
                        effectClass = args.defeated ? 'zq-effect-damage' : 'zq-effect-neutral';
                        break;
                    case 'poison':
                        effectText = `🧪 ${args.target_name} poisoned (+${args.markers_placed || 1})`;
                        effectClass = 'zq-effect-danger';
                        break;
                    case 'heal':
                        let healParts = [];
                        if (args.poison_removed > 0) healParts.push(`-${args.poison_removed} poison`);
                        const cardsRestored = args.cards_restored?.length || 0;
                        if (cardsRestored > 0) healParts.push(`+${cardsRestored} card(s)`);
                        effectText = healParts.length ? `💚 ${healParts.join(', ')}` : '💔 Nothing to heal';
                        effectClass = healParts.length ? 'zq-effect-heal' : 'zq-effect-none';
                        break;
                    case 'shuffle':
                        const shuffled = args.cards_shuffled?.length || 0;
                        effectText = `🔀 Shuffled ${shuffled} card(s) from discard`;
                        effectClass = 'zq-effect-neutral';
                        break;
                    case 'selling':
                        effectText = `🏷️ Selling (min ${args.minimum_price} wealth)`;
                        effectClass = 'zq-effect-neutral';
                        break;
                    case 'purchased':
                        const boughtItem = args.item?.item_name || 'an item';
                        effectText = `💰 Bought ${boughtItem} from ${args.target_name}`;
                        effectClass = 'zq-effect-success';
                        break;
                    case 'stolen':
                        const count = args.items?.length || 1;
                        effectText = `🤏 Stole ${count} item(s) from ${args.target_name}`;
                        effectClass = 'zq-effect-success';
                        break;
                    case 'caught':
                        effectText = `😠 CAUGHT by watchers!`;
                        effectClass = 'zq-effect-danger';
                        break;
                    case 'target_defeated':
                        effectText = `💀 Target ${args.target_name || 'unknown'} already defeated`;
                        effectClass = 'zq-effect-none';
                        break;
                    case 'target_hidden':
                        effectText = `🥷 Target ${args.target_name || 'unknown'} is hidden!`;
                        effectClass = 'zq-effect-blocked';
                        break;
                    case 'no_target':
                        effectText = `❌ No valid target`;
                        effectClass = 'zq-effect-none';
                        break;
                    default:
                        effectText = args.message || `→ ${args.effect || 'No effect'}`;
                }

                log.innerHTML += `
                    <div class="zq-card-resolved ${effectClass}">
                        ${icon} <strong>${args.entity_name || ''}</strong> ${cardName} ${effectText}
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
            const delay = this.getAnimationDelay();
            console.log('cardResolved applying delay:', delay, 'ms');
            await this.wait(delay);
            console.log('cardResolved delay complete');
        },

        notif_entityDefeated: async function(args) {
            console.log('Entity defeated:', args);

            const entity = this.gamedatas.entities.find(e => e.entity_id == args.entity_id);
            if (entity) {
                entity.is_defeated = 1;
            }

            const log = this.getSequencePopupLog();
            if (log) {
                let lootText = '';
                if (args.items_looted && args.items_looted.length > 0) {
                    const itemNames = args.items_looted.map(i => i.item_name).join(', ');
                    lootText = ` ${args.killer_name || 'You'} looted: ${itemNames}`;
                }
                log.innerHTML += `<div class="zq-entity-defeated">💀 ${args.entity_name} has been defeated!${lootText}</div>`;
            }

            this.renderEntities();
            await this.wait(this.getAnimationDelay());
        },

        notif_sequenceEnd: async function(args) {
            console.log('=== SEQUENCE END ===');
            console.log('Result:', args);
            console.log('Timestamp:', new Date().toISOString());

            // Handle "no action" sequences with simplified display and auto-close
            if (args.no_action) {
                const popup = document.getElementById('zq-sequence-popup');
                if (popup) {
                    // Replace popup content with simple "No Actions" message
                    const log = this.getSequencePopupLog();
                    if (log) {
                        log.innerHTML = '<div class="zq-no-action-message">No Actions</div>';
                    }
                    // Hide participants section for cleaner look
                    const participants = popup.querySelector('.zq-sequence-popup-participants');
                    if (participants) {
                        participants.style.display = 'none';
                    }
                }
                
                // Remove red pulse from location
                if (this.activeSequenceLocationId) {
                    const node = document.getElementById(`zq-node-${this.activeSequenceLocationId}`);
                    if (node) {
                        node.classList.remove('zq-node-active-sequence');
                    }
                }
                
                // Auto-close after 2 seconds (but still allow manual close)
                this.showSequencePopupCloseButton();
                setTimeout(() => {
                    this.closeSequencePopup();
                }, 2000);
                
                this.battleEnded = true;
                return;
            }

            const log = this.getSequencePopupLog();
            if (log) {
                let message = '';
                if (args.eliminated_faction) {
                    message = `💀 <strong>${args.eliminated_faction}</strong> faction eliminated!`;
                } else {
                    message = '⚖️ <strong>Standoff.</strong> All combatants are exhausted.';
                }
                log.innerHTML += `<div class="zq-battle-result">${message}</div>`;
                log.scrollTop = log.scrollHeight;
            }

            // Clear card highlights
            this.clearCardHighlight();

            // Remove red pulse from location (but keep popup open)
            if (this.activeSequenceLocationId) {
                const node = document.getElementById(`zq-node-${this.activeSequenceLocationId}`);
                if (node) {
                    node.classList.remove('zq-node-active-sequence');
                }
            }

            // Show the close button on the popup
            this.showSequencePopupCloseButton();

            this.battleEnded = true;
            console.log('Sequence complete, no delay applied (end notification)');
        },

        notif_sequenceContinues: async function(args) {
            // Internal notification - panel already shows this via round summary
            console.log('Sequence continues:', args);
        },

        notif_sequenceRoundSummary: async function(args) {
            console.log('=== SEQUENCE ROUND SUMMARY (Round ' + args.round + ') ===');
            console.log('Full round data:', JSON.stringify(args, null, 2));
            console.log('Timestamp:', new Date().toISOString());

            const log = this.getSequencePopupLog();
            if (!log) return;

            // Build round container using the pre-formatted round_log from PHP
            // This matches exactly what appears in the BGA sidebar
            let html = `<div class="zq-battle-round" data-round="${args.round}">`;
            html += `<div class="zq-round-header">⚔️ Round ${args.round}</div>`;
            
            // Use the pre-formatted round_log from PHP (same as BGA sidebar)
            if (args.round_log) {
                html += `<div class="zq-round-log">${args.round_log}</div>`;
            }

            // Check for loot drops from defeats
            const lootEvents = (args.resolutions || []).filter(r => r.items_transferred && r.items_transferred.length > 0);
            if (lootEvents.length > 0) {
                html += `<div class="zq-loot-section">`;
                html += `<div class="zq-loot-header">🎁 Loot Acquired:</div>`;
                lootEvents.forEach(loot => {
                    // Find killer name
                    const killer = this.gamedatas.entities.find(e => e.entity_id == loot.killer_id);
                    const killerName = killer ? killer.entity_name : 'Unknown';
                    
                    loot.items_transferred.forEach(item => {
                        const itemData = typeof item.item_data === 'string' 
                            ? JSON.parse(item.item_data) 
                            : (item.item_data || {});
                        const cardName = itemData.name || item.item_name || 'Card';
                        html += `<div class="zq-loot-line">📦 ${killerName} received: <strong>${cardName}</strong></div>`;
                    });
                    
                    // Update killer's deck_counts locally
                    if (killer) {
                        killer.deck_counts = killer.deck_counts || { active: 0, discard: 0, inactive: 0 };
                        killer.deck_counts.inactive = (killer.deck_counts.inactive || 0) + loot.items_transferred.length;
                    }
                });
                html += `</div>`;
            }

            // Add status summary
            html += `<div class="zq-round-status">`;
            html += `<div class="zq-status-header">End of Round ${args.round}:</div>`;
            html += `<div class="zq-status-line">`;
            (args.status || []).forEach((s, idx) => {
                const status = s.is_defeated ? '💀' : `${s.active}/${s.discard}/${s.inactive}`;
                const sep = idx < (args.status || []).length - 1 ? ' | ' : '';
                html += `<span class="zq-entity-status ${s.entity_type}">${s.entity_name}: ${status}</span>${sep}`;
            });
            html += `</div></div>`;
            html += `</div>`; // Close .zq-battle-round

            // Append to log (keep previous rounds)
            log.innerHTML += html;

            // Auto-scroll to latest round
            log.scrollTop = log.scrollHeight;

            const delay = this.getAnimationDelay();
            console.log('sequenceRoundSummary (Round ' + args.round + ') applying delay:', delay, 'ms');
            await this.wait(delay);
            console.log('sequenceRoundSummary (Round ' + args.round + ') delay complete, timestamp:', new Date().toISOString());
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

                case 'poison':
                    if (r.effect === 'poison') {
                        const duration = r.duration || 3;
                        return `${entityName} poisons ${targetName} 🧪 (${duration} rounds)`;
                    } else if (r.effect === 'target_hidden') {
                        return `${entityName}'s poison misses - ${targetName} is hidden! 🥷`;
                    } else if (r.effect === 'target_defeated') {
                        return `${entityName}'s poison finds ${targetName} already defeated`;
                    }
                    return `${entityName}'s poison has no effect`;

                case 'mark':
                    if (r.effect === 'mark') {
                        const duration = r.duration || 2;
                        return `${entityName} marks ${targetName} 🎯 (${duration} rounds, +1 damage)`;
                    } else if (r.effect === 'target_hidden') {
                        return `${entityName}'s mark misses - ${targetName} is hidden! 🥷`;
                    } else if (r.effect === 'target_defeated') {
                        return `${entityName}'s mark finds ${targetName} already defeated`;
                    }
                    return `${entityName}'s mark has no effect`;

                case 'sell':
                    return `${entityName} offers items for sale 🏷️`;

                case 'wealth':
                case 'buy':
                    if (r.effect === 'purchased') {
                        const itemName = r.item?.item_name || 'an item';
                        return `${entityName} buys ${itemName} from ${targetName} 💰`;
                    } else if (r.effect === 'not_selling') {
                        return `${entityName} tries to buy but ${targetName} isn't selling`;
                    } else if (r.effect === 'no_items') {
                        return `${entityName} tries to buy but ${targetName} has no items`;
                    }
                    return `${entityName}'s purchase fails`;

                case 'steal':
                    if (r.effect === 'stolen') {
                        const itemName = r.item?.item_name || 'an item';
                        return `${entityName} steals ${itemName} from ${targetName} 🤏`;
                    } else if (r.effect === 'caught') {
                        return `${entityName} is CAUGHT stealing! ${r.faction_now_hostile} is now hostile! 😠`;
                    } else if (r.effect === 'no_items') {
                        return `${entityName} tries to steal but ${targetName} has nothing`;
                    }
                    return `${entityName}'s theft fails`;

                default:
                    // Handle poison tick (effect-based, not card-based)
                    if (r.effect === 'poison_tick') {
                        const rounds = r.rounds_remaining || 0;
                        if (r.defeated) {
                            return `${entityName} takes poison damage and is defeated! ☠️`;
                        }
                        return `${entityName} takes poison damage 🧪 (${rounds} rounds left)`;
                    }
                    return `${entityName} plays ${r.card_type}`;
            }
        },

        notif_sequenceEndEffects: async function(args) {
            console.log('Sequence end effects:', args);
            
            const log = document.getElementById('zq-battle-log');
            if (log && args.effects) {
                args.effects.forEach(effect => {
                    let html = '';
                    if (effect.damage) {
                        const defeatedText = effect.defeated ? ' 💀 DEFEATED!' : '';
                        html = `<div class="zq-card-resolved zq-effect-damage">
                            🧪 <strong>${effect.entity_name}</strong> takes poison damage (${effect.poison_remaining} poison remaining)${defeatedText}
                        </div>`;
                    }
                    if (html) log.innerHTML += html;
                    
                    // Update entity state
                    if (effect.defeated) {
                        const entity = this.gamedatas.entities.find(e => e.entity_id == effect.entity_id);
                        if (entity) entity.is_defeated = 1;
                    }
                });
            }
            
            await this.wait(this.getAnimationDelay());
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
            this.showMessage(args.message || _("Victory!"), "info");
            this.showGoalSummary(args.goal_status, true);
        },

        notif_gameDefeat: async function(args) {
            this.showMessage(_("Defeat! All heroes have fallen."), "error");
            this.showGoalSummary(args.goal_status, false);
        },

        showGoalSummary: function(goalStatus, isVictory) {
            if (!goalStatus) return;
            
            // Create a summary popup showing all player goals
            const popup = document.createElement('div');
            popup.className = 'zq-goal-summary-popup';
            popup.id = 'zq-goal-summary';
            
            let html = `<div class="zq-goal-summary-content">
                <h3>${isVictory ? '🏆 Victory!' : '💀 Defeat!'}</h3>
                <h4>Individual Goals:</h4>
                <div class="zq-goal-list">`;
            
            for (const playerId in goalStatus) {
                const g = goalStatus[playerId];
                const statusIcon = g.complete ? '✅' : '❌';
                const pointsText = g.complete ? `+${g.points} pts` : '';
                
                html += `
                    <div class="zq-goal-result ${g.complete ? 'complete' : 'incomplete'}">
                        <span class="zq-goal-player">${g.player_name}</span>
                        <span class="zq-goal-info">${g.goal_icon} ${g.goal_name}</span>
                        <span class="zq-goal-status">${g.progress}/${g.threshold} ${statusIcon}</span>
                        <span class="zq-goal-points">${pointsText}</span>
                    </div>`;
            }
            
            html += `</div>
                <button id="zq-goal-summary-close" class="zq-summary-close-btn">Close</button>
            </div>`;
            
            popup.innerHTML = html;
            document.body.appendChild(popup);
            
            document.getElementById('zq-goal-summary-close').addEventListener('click', () => {
                popup.remove();
            });
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
                'shuffle': '🔀',
                'poison': '🧪',
                'mark': '🎯',
                'sell': '🏷️',
                'steal': '🤏',
                'wealth': '💰',
                'buy': '🪙'
            };
            return icons[cardType] || '🃏';
        },

        getTagIcon: function(tagName) {
            const icons = {
                'hidden': '👻',
                'blocked': '🛡️',
                'poisoned': '🧪',
                'marked': '🎯'
            };
            return icons[tagName] || `[${tagName}]`;
        },

        getVictoryIcon: function(victoryType) {
            const icons = {
                'defeat_all': '⚔️',
                'reach_location': '🏁',
                'defeat_target': '💀',
                'collect_item': '🎁'
            };
            return icons[victoryType] || '🏆';
        },

        getMonsterIcon: function(entity) {
            // Different icons based on faction or class for variety and atmosphere
            const factionIcons = {
                'goblins': '👺',
                'undead': '💀',
                'criminals': '🗡️',
                'beasts': '🐺',
                'demons': '👿',
                'dragons': '🐉',
                'elementals': '🔥',
            };
            
            const classIcons = {
                'goblin': '👺',
                'skeleton': '💀',
                'zombie': '🧟',
                'ghost': '👻',
                'bandit': '🗡️',
                'thief': '🥷',
                'wolf': '🐺',
                'bear': '🐻',
                'spider': '🕷️',
                'demon': '👿',
                'dragon': '🐉',
                'orc': '👹',
                'troll': '🧌',
                'vampire': '🧛',
                'witch': '🧙',
                'merchant': '🧑‍💼',
            };

            // Try class first (more specific), then faction, then default
            const entityClass = (entity.entity_class || '').toLowerCase();
            const entityFaction = (entity.faction || '').toLowerCase();
            
            if (classIcons[entityClass]) return classIcons[entityClass];
            if (factionIcons[entityFaction]) return factionIcons[entityFaction];
            
            // Default menacing icon for unknown monsters
            return '☠️';
        },

        getAnimationDelay: function() {
            // Try to get user preference, default to instant for dev (normal for prod)
            let speed = 'instant'; // Default for when preference unavailable (e.g. BGA Studio)
            try {
            const pref = this.getGameUserPreference(100);
                const speeds = { 0: 'instant', 1: 'fast', 2: 'normal', 3: 'slow' };
                speed = speeds[pref] || 'instant';
            } catch (e) {
                // Preference not defined, use instant for faster dev iteration
            }
            return this.animationDelays[speed];
        },

        wait: function(ms) {
            return new Promise(resolve => setTimeout(resolve, ms));
        }
    });
});

