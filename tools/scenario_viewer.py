#!/usr/bin/env python3
"""
Scenario Viewer - Generate an HTML visualization of ZoomQuest game scenarios.

Usage:
    python scenario_viewer.py [scenario_file] [--open]
    
Generates an HTML file and optionally opens it in the default browser.
"""

import json
import sys
import os
import webbrowser
from pathlib import Path


HTML_TEMPLATE = '''<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ZoomQuest: {level_name}</title>
    <style>
        * {{
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }}
        
        body {{
            font-family: 'Segoe UI', system-ui, sans-serif;
            background: linear-gradient(135deg, #1a1a2e 0%, #16213e 100%);
            color: #e0e0e0;
            min-height: 100vh;
        }}
        
        .container {{
            display: flex;
            height: 100vh;
            padding: 20px;
            gap: 20px;
        }}
        
        .map-container {{
            flex: 1;
            background: #0d1117;
            background-image: url('{background_image}');
            background-size: cover;
            background-position: center;
            border-radius: 12px;
            border: 2px solid #30363d;
            position: relative;
            overflow: hidden;
        }}
        
        .map-svg {{
            width: 100%;
            height: 100%;
        }}
        
        .connection {{
            stroke: #4a6fa5;
            stroke-width: 2;
            fill: none;
        }}
        
        .connection-label {{
            fill: #6a8fc5;
            font-size: 10px;
            text-anchor: middle;
            pointer-events: none;
        }}
        
        .location {{
            cursor: pointer;
        }}
        
        .location-circle {{
            stroke-width: 2;
            transition: stroke-width 0.2s;
        }}
        
        .location:hover .location-circle {{
            stroke-width: 4;
        }}
        
        .location-settled .location-circle {{
            fill: #2d5a27;
            stroke: #4a8a42;
        }}
        
        .location-wilderness .location-circle {{
            fill: #3a3a5a;
            stroke: #5a5a8a;
        }}
        
        .location-name {{
            fill: #ffffff;
            font-size: 11px;
            font-weight: bold;
            text-anchor: middle;
            pointer-events: none;
        }}
        
        .entity {{
            cursor: pointer;
            font-size: 11px;
            transition: font-weight 0.2s;
        }}
        
        .entity:hover {{
            font-weight: bold;
        }}
        
        .entity-character {{
            fill: #55efc4;
        }}
        
        .entity-monster {{
            fill: #ff7675;
        }}
        
        .info-panel {{
            width: 320px;
            background: #161b22;
            border-radius: 12px;
            border: 2px solid #30363d;
            padding: 20px;
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }}
        
        .scenario-header {{
            margin-bottom: 15px;
        }}
        
        .scenario-title {{
            font-size: 18px;
            font-weight: bold;
            color: #58a6ff;
            margin-bottom: 8px;
        }}
        
        .scenario-victory {{
            font-size: 13px;
            color: #8b949e;
            line-height: 1.4;
        }}
        
        .scenario-stats {{
            font-size: 12px;
            color: #6e7681;
            margin-top: 10px;
            padding-top: 10px;
            border-top: 1px solid #30363d;
        }}
        
        .info-content {{
            flex: 1;
            overflow-y: auto;
            margin-top: 15px;
            padding-top: 15px;
            border-top: 1px solid #30363d;
        }}
        
        .info-title {{
            font-size: 16px;
            font-weight: bold;
            margin-bottom: 15px;
            color: #c9d1d9;
        }}
        
        .info-table {{
            width: 100%;
            font-size: 12px;
        }}
        
        .info-table tr {{
            border-bottom: 1px solid #21262d;
        }}
        
        .info-table td {{
            padding: 6px 0;
            vertical-align: top;
        }}
        
        .info-table td:first-child {{
            color: #8b949e;
            width: 90px;
        }}
        
        .info-table td:last-child {{
            color: #c9d1d9;
        }}
        
        .info-section {{
            margin-top: 15px;
            padding-top: 15px;
            border-top: 1px solid #21262d;
        }}
        
        .info-section-title {{
            font-size: 13px;
            font-weight: bold;
            color: #8b949e;
            margin-bottom: 8px;
        }}
        
        .card-list, .entity-list, .item-list {{
            list-style: none;
            font-size: 12px;
        }}
        
        .card-list li, .entity-list li, .item-list li {{
            padding: 4px 0;
            color: #c9d1d9;
        }}
        
        .card-icon {{
            margin-right: 5px;
        }}
        
        .placeholder {{
            color: #6e7681;
            font-style: italic;
            text-align: center;
            margin-top: 50px;
        }}
        
        .toolbar {{
            position: absolute;
            top: 15px;
            left: 15px;
            display: flex;
            gap: 10px;
            z-index: 100;
        }}
        
        .toolbar button {{
            padding: 8px 16px;
            border: none;
            border-radius: 6px;
            font-size: 13px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s;
        }}
        
        .btn-primary {{
            background: #238636;
            color: #fff;
        }}
        
        .btn-primary:hover {{
            background: #2ea043;
        }}
        
        .btn-primary:disabled {{
            background: #21262d;
            color: #6e7681;
            cursor: not-allowed;
        }}
        
        .btn-secondary {{
            background: #30363d;
            color: #c9d1d9;
        }}
        
        .btn-secondary:hover {{
            background: #484f58;
        }}
        
        .edit-indicator {{
            position: absolute;
            top: 15px;
            right: 15px;
            padding: 6px 12px;
            background: #f8514980;
            color: #f85149;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 500;
            opacity: 0;
            transition: opacity 0.3s;
        }}
        
        .edit-indicator.visible {{
            opacity: 1;
        }}
        
        .location.dragging .location-circle {{
            stroke-width: 4;
            filter: drop-shadow(0 0 8px rgba(88, 166, 255, 0.5));
        }}
        
        .coords-display {{
            position: absolute;
            bottom: 15px;
            left: 15px;
            padding: 6px 12px;
            background: rgba(22, 27, 34, 0.9);
            border-radius: 6px;
            font-size: 11px;
            color: #8b949e;
            font-family: monospace;
        }}
    </style>
</head>
<body>
    <div class="container">
        <div class="map-container" id="map-container">
            <div class="toolbar">
                <button class="btn-primary" id="save-btn" disabled onclick="downloadUpdatedJson()">💾 Save Coordinates</button>
                <button class="btn-secondary" onclick="resetPositions()">↩️ Reset</button>
            </div>
            <div class="edit-indicator" id="edit-indicator">⚡ Unsaved changes</div>
            <div class="coords-display" id="coords-display">Drag locations to reposition</div>
            <svg class="map-svg" id="map-svg"></svg>
        </div>
        <div class="info-panel">
            <div class="scenario-header">
                <div class="scenario-title">📜 {level_name}</div>
                <div class="scenario-victory">🎯 {victory_desc}</div>
                <div class="scenario-stats">
                    📍 {location_count} locations | 🔗 {connection_count} paths<br>
                    ⚔️ {character_count} heroes | 🧟 {monster_count} monster types
                </div>
            </div>
            <div class="info-content" id="info-content">
                <div class="placeholder">Click a location or entity to view details</div>
            </div>
        </div>
    </div>
    
    <script>
        const scenario = {scenario_json};
        const originalScenario = JSON.parse(JSON.stringify(scenario)); // Deep copy for reset
        let hasChanges = false;
        let isDragging = false;
        let dragTarget = null;
        let dragOffset = {{ x: 0, y: 0 }};
        
        const cardIcons = {{
            'attack': '⚔️',
            'defend': '🛡️',
            'heal': '💚',
            'sneak': '🥷',
            'watch': '👁️',
            'shuffle': '🔀',
            'poison': '🧪',
            'mark': '🎯',
            'backstab': '🗡️',
            'execute': '💀',
            'sell': '🏷️',
            'steal': '🤏',
            'wealth': '💰',
        }};
        
        function getCardIcon(cardType) {{
            return cardIcons[cardType] || '🃏';
        }}
        
        function initMap() {{
            const svg = document.getElementById('map-svg');
            const rect = svg.getBoundingClientRect();
            const width = rect.width;
            const height = rect.height;
            const padding = 60;
            
            const locations = scenario.map.locations;
            const connections = scenario.map.connections;
            
            // Build location lookup
            const locById = {{}};
            locations.forEach(loc => locById[loc.id] = loc);
            
            // Build entities by location
            const entitiesByLocation = {{}};
            (scenario.characters || []).forEach(char => {{
                if (!entitiesByLocation[char.location]) entitiesByLocation[char.location] = [];
                entitiesByLocation[char.location].push({{ type: 'character', data: char }});
            }});
            (scenario.monsters || []).forEach(monster => {{
                if (!entitiesByLocation[monster.location]) entitiesByLocation[monster.location] = [];
                entitiesByLocation[monster.location].push({{ type: 'monster', data: monster }});
            }});
            
            function getScreenCoords(x, y) {{
                return {{
                    x: padding + x * (width - 2 * padding),
                    y: padding + y * (height - 2 * padding)
                }};
            }}
            
            let svgContent = '';
            
            // Draw connections
            connections.forEach(conn => {{
                const from = locById[conn.from];
                const to = locById[conn.to];
                if (from && to) {{
                    const p1 = getScreenCoords(from.x || 0.5, from.y || 0.5);
                    const p2 = getScreenCoords(to.x || 0.5, to.y || 0.5);
                    const mid = {{ x: (p1.x + p2.x) / 2, y: (p1.y + p2.y) / 2 }};
                    
                    svgContent += `<line class="connection" x1="${{p1.x}}" y1="${{p1.y}}" x2="${{p2.x}}" y2="${{p2.y}}"/>`;
                    svgContent += `<text class="connection-label" x="${{mid.x}}" y="${{mid.y - 5}}">${{conn.name || ''}}</text>`;
                }}
            }});
            
            // Draw locations
            const nodeRadius = 35;
            locations.forEach(loc => {{
                const pos = getScreenCoords(loc.x || 0.5, loc.y || 0.5);
                const terrain = loc.terrain || 'wilderness';
                const entities = entitiesByLocation[loc.id] || [];
                
                // Location group (will be updated during drag)
                svgContent += `
                    <g class="location location-${{terrain}}" data-loc-id="${{loc.id}}" id="loc-group-${{loc.id}}">
                        <circle class="location-circle" cx="${{pos.x}}" cy="${{pos.y}}" r="${{nodeRadius}}"/>
                        <text class="location-name" x="${{pos.x}}" y="${{pos.y}}" dy="0.35em">${{loc.name}}</text>
                `;
                
                // Draw entities below node (inside same group so they move together)
                entities.forEach((ent, i) => {{
                    const icon = ent.type === 'character' ? '⚔️' : '🧟';
                    const y = pos.y + nodeRadius + 18 + i * 16;
                    svgContent += `
                        <text class="entity entity-${{ent.type}}" x="${{pos.x}}" y="${{y}}" 
                              text-anchor="middle" data-entity-idx="${{i}}">
                            ${{icon}} ${{ent.data.name}}
                        </text>
                    `;
                }});
                
                svgContent += `</g>`;
            }});
            
            svg.innerHTML = svgContent;
            
            // Add drag handlers to locations
            setupDragHandlers();
            
            // Store for click handlers
            window.locById = locById;
            window.entitiesByLocation = entitiesByLocation;
        }}
        
        function showLocationInfo(locId) {{
            const loc = window.locById[locId];
            const entities = window.entitiesByLocation[locId] || [];
            
            let html = `<div class="info-title">📍 ${{loc.name}}</div>`;
            html += `<table class="info-table">
                <tr><td>ID</td><td>${{loc.id}}</td></tr>
                <tr><td>Terrain</td><td>${{loc.terrain || 'unknown'}}</td></tr>
                <tr><td>Direction</td><td>${{loc.direction || 'center'}}</td></tr>
                <tr><td>Position</td><td>(${{(loc.x || 0.5).toFixed(2)}}, ${{(loc.y || 0.5).toFixed(2)}})</td></tr>
                <tr><td>Description</td><td>${{loc.description || 'No description'}}</td></tr>
            </table>`;
            
            if (entities.length > 0) {{
                html += `<div class="info-section">
                    <div class="info-section-title">Entities (${{entities.length}})</div>
                    <ul class="entity-list">`;
                entities.forEach(ent => {{
                    const icon = ent.type === 'character' ? '⚔️' : '🧟';
                    html += `<li>${{icon}} ${{ent.data.name}} (${{ent.data.class || '?'}})</li>`;
                }});
                html += `</ul></div>`;
            }}
            
            document.getElementById('info-content').innerHTML = html;
        }}
        
        function showEntityInfo(locId, index) {{
            const entities = window.entitiesByLocation[locId] || [];
            const ent = entities[index];
            if (!ent) return;
            
            const data = ent.data;
            const icon = ent.type === 'character' ? '⚔️' : '🧟';
            
            let html = `<div class="info-title">${{icon}} ${{data.name}}</div>`;
            html += `<table class="info-table">
                <tr><td>Type</td><td>${{ent.type}}</td></tr>
                <tr><td>Class</td><td>${{data.class || 'unknown'}}</td></tr>
                <tr><td>Faction</td><td>${{data.faction || 'unknown'}}</td></tr>
                <tr><td>Location</td><td>${{data.location || 'unknown'}}</td></tr>
            </table>`;
            
            // Show deck
            const active = (data.decks && data.decks.active) || [];
            if (active.length > 0) {{
                const cardCounts = {{}};
                active.forEach(card => cardCounts[card] = (cardCounts[card] || 0) + 1);
                
                html += `<div class="info-section">
                    <div class="info-section-title">Active Deck (${{active.length}} cards)</div>
                    <ul class="card-list">`;
                Object.entries(cardCounts).sort().forEach(([card, count]) => {{
                    html += `<li><span class="card-icon">${{getCardIcon(card)}}</span>${{card}} x${{count}}</li>`;
                }});
                html += `</ul></div>`;
            }}
            
            // Show items
            const items = data.items || [];
            if (items.length > 0) {{
                html += `<div class="info-section">
                    <div class="info-section-title">Items (${{items.length}})</div>
                    <ul class="item-list">`;
                items.forEach(item => {{
                    html += `<li>📦 ${{item.name}} <span style="color:#6e7681">(${{item.type}})</span></li>`;
                }});
                html += `</ul></div>`;
            }}
            
            document.getElementById('info-content').innerHTML = html;
        }}
        
        function setupDragHandlers() {{
            const svg = document.getElementById('map-svg');
            const rect = svg.getBoundingClientRect();
            
            document.querySelectorAll('.location').forEach(group => {{
                group.addEventListener('mousedown', startDrag);
                group.addEventListener('click', handleLocationClick);
            }});
            
            svg.addEventListener('mousemove', drag);
            svg.addEventListener('mouseup', endDrag);
            svg.addEventListener('mouseleave', endDrag);
        }}
        
        function startDrag(e) {{
            if (e.target.classList.contains('entity')) return; // Don't drag when clicking entity
            
            const group = e.currentTarget;
            const locId = group.dataset.locId;
            const circle = group.querySelector('.location-circle');
            
            isDragging = true;
            dragTarget = {{ group, locId }};
            group.classList.add('dragging');
            
            const svg = document.getElementById('map-svg');
            const pt = svg.createSVGPoint();
            pt.x = e.clientX;
            pt.y = e.clientY;
            const svgP = pt.matrixTransform(svg.getScreenCTM().inverse());
            
            const cx = parseFloat(circle.getAttribute('cx'));
            const cy = parseFloat(circle.getAttribute('cy'));
            dragOffset = {{ x: svgP.x - cx, y: svgP.y - cy }};
            
            e.preventDefault();
        }}
        
        function drag(e) {{
            if (!isDragging || !dragTarget) return;
            
            const svg = document.getElementById('map-svg');
            const rect = svg.getBoundingClientRect();
            const padding = 60;
            
            const pt = svg.createSVGPoint();
            pt.x = e.clientX;
            pt.y = e.clientY;
            const svgP = pt.matrixTransform(svg.getScreenCTM().inverse());
            
            const newX = svgP.x - dragOffset.x;
            const newY = svgP.y - dragOffset.y;
            
            // Update visual position
            updateLocationPosition(dragTarget.locId, newX, newY);
            
            // Update normalized coordinates in scenario
            const normX = (newX - padding) / (rect.width - 2 * padding);
            const normY = (newY - padding) / (rect.height - 2 * padding);
            
            // Clamp to 0-1 range
            const clampedX = Math.max(0, Math.min(1, normX));
            const clampedY = Math.max(0, Math.min(1, normY));
            
            // Update scenario data
            const loc = scenario.map.locations.find(l => l.id === dragTarget.locId);
            if (loc) {{
                loc.x = clampedX;
                loc.y = clampedY;
            }}
            
            // Update coords display
            document.getElementById('coords-display').textContent = 
                `${{dragTarget.locId}}: (${{clampedX.toFixed(3)}}, ${{clampedY.toFixed(3)}})`;
            
            markAsChanged();
        }}
        
        function endDrag(e) {{
            if (dragTarget) {{
                dragTarget.group.classList.remove('dragging');
                // Redraw connections after drag ends
                redrawConnections();
            }}
            isDragging = false;
            dragTarget = null;
        }}
        
        function updateLocationPosition(locId, x, y) {{
            const group = document.getElementById(`loc-group-${{locId}}`);
            if (!group) return;
            
            const circle = group.querySelector('.location-circle');
            const nameText = group.querySelector('.location-name');
            const entities = group.querySelectorAll('.entity');
            
            circle.setAttribute('cx', x);
            circle.setAttribute('cy', y);
            nameText.setAttribute('x', x);
            nameText.setAttribute('y', y);
            
            const nodeRadius = 35;
            entities.forEach((ent, i) => {{
                ent.setAttribute('x', x);
                ent.setAttribute('y', y + nodeRadius + 18 + i * 16);
            }});
        }}
        
        function redrawConnections() {{
            const svg = document.getElementById('map-svg');
            const rect = svg.getBoundingClientRect();
            const padding = 60;
            
            function getScreenCoords(x, y) {{
                return {{
                    x: padding + x * (rect.width - 2 * padding),
                    y: padding + y * (rect.height - 2 * padding)
                }};
            }}
            
            // Update each connection line
            scenario.map.connections.forEach(conn => {{
                const from = scenario.map.locations.find(l => l.id === conn.from);
                const to = scenario.map.locations.find(l => l.id === conn.to);
                if (!from || !to) return;
                
                const p1 = getScreenCoords(from.x || 0.5, from.y || 0.5);
                const p2 = getScreenCoords(to.x || 0.5, to.y || 0.5);
                
                // Find the connection line (we'll use data attributes)
                const lines = svg.querySelectorAll('.connection');
                lines.forEach(line => {{
                    // Check by comparing coordinates (rough match)
                    const lx1 = parseFloat(line.getAttribute('x1'));
                    const ly1 = parseFloat(line.getAttribute('y1'));
                    const lx2 = parseFloat(line.getAttribute('x2'));
                    const ly2 = parseFloat(line.getAttribute('y2'));
                    
                    // Find matching line by checking if either endpoint matches from or to
                    const fromGroup = document.getElementById(`loc-group-${{conn.from}}`);
                    const toGroup = document.getElementById(`loc-group-${{conn.to}}`);
                    if (!fromGroup || !toGroup) return;
                    
                    const fromCircle = fromGroup.querySelector('.location-circle');
                    const toCircle = toGroup.querySelector('.location-circle');
                    const fcx = parseFloat(fromCircle.getAttribute('cx'));
                    const fcy = parseFloat(fromCircle.getAttribute('cy'));
                    const tcx = parseFloat(toCircle.getAttribute('cx'));
                    const tcy = parseFloat(toCircle.getAttribute('cy'));
                    
                    if ((Math.abs(lx1 - fcx) < 1 && Math.abs(ly1 - fcy) < 1) ||
                        (Math.abs(lx1 - tcx) < 1 && Math.abs(ly1 - tcy) < 1)) {{
                        line.setAttribute('x1', fcx);
                        line.setAttribute('y1', fcy);
                        line.setAttribute('x2', tcx);
                        line.setAttribute('y2', tcy);
                        
                        // Update label too
                        const mid = {{ x: (fcx + tcx) / 2, y: (fcy + tcy) / 2 }};
                        const nextSibling = line.nextElementSibling;
                        if (nextSibling && nextSibling.classList.contains('connection-label')) {{
                            nextSibling.setAttribute('x', mid.x);
                            nextSibling.setAttribute('y', mid.y - 5);
                        }}
                    }}
                }});
            }});
        }}
        
        function handleLocationClick(e) {{
            if (e.target.classList.contains('entity')) {{
                // Entity click
                const locId = e.currentTarget.dataset.locId;
                const idx = parseInt(e.target.dataset.entityIdx);
                showEntityInfo(locId, idx);
            }} else if (!isDragging) {{
                // Location click (only if not dragging)
                const locId = e.currentTarget.dataset.locId;
                showLocationInfo(locId);
            }}
            e.stopPropagation();
        }}
        
        function markAsChanged() {{
            if (!hasChanges) {{
                hasChanges = true;
                document.getElementById('save-btn').disabled = false;
                document.getElementById('edit-indicator').classList.add('visible');
            }}
        }}
        
        function resetPositions() {{
            // Restore original coordinates
            scenario.map.locations.forEach((loc, i) => {{
                const orig = originalScenario.map.locations[i];
                loc.x = orig.x;
                loc.y = orig.y;
            }});
            hasChanges = false;
            document.getElementById('save-btn').disabled = true;
            document.getElementById('edit-indicator').classList.remove('visible');
            document.getElementById('coords-display').textContent = 'Drag locations to reposition';
            initMap();
        }}
        
        function downloadUpdatedJson() {{
            const jsonStr = JSON.stringify(scenario, null, 2);
            const blob = new Blob([jsonStr], {{ type: 'application/json' }});
            const url = URL.createObjectURL(blob);
            
            const a = document.createElement('a');
            a.href = url;
            a.download = '{scenario_filename}';
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            URL.revokeObjectURL(url);
            
            // Mark as saved
            hasChanges = false;
            document.getElementById('save-btn').disabled = true;
            document.getElementById('edit-indicator').classList.remove('visible');
            document.getElementById('coords-display').textContent = 'Saved! Drag locations to reposition';
        }}
        
        // Warn before leaving with unsaved changes
        window.addEventListener('beforeunload', (e) => {{
            if (hasChanges) {{
                e.preventDefault();
                e.returnValue = '';
            }}
        }});
        
        // Initialize on load
        window.addEventListener('load', initMap);
        window.addEventListener('resize', () => {{ initMap(); redrawConnections(); }});
    </script>
</body>
</html>
'''


def generate_html(scenario_path, output_path=None):
    """Generate HTML visualization for a scenario."""
    with open(scenario_path, 'r') as f:
        scenario = json.load(f)
    
    # Extract stats
    level_name = scenario.get('level_name', 'Unknown')
    victory = scenario.get('victory', {})
    victory_desc = victory.get('description', 'No victory condition')
    locations = scenario.get('map', {}).get('locations', [])
    connections = scenario.get('map', {}).get('connections', [])
    characters = scenario.get('characters', [])
    monsters = scenario.get('monsters', [])
    
    # Check for background image (same name as scenario, .png or .jpg)
    scenario_base = Path(scenario_path).stem
    scenario_dir = Path(scenario_path).parent
    background_image = ''
    for ext in ['.png', '.jpg', '.jpeg', '.webp']:
        bg_path = scenario_dir / f"{scenario_base}{ext}"
        if bg_path.exists():
            background_image = bg_path.name
            print(f"Found background image: {bg_path}")
            break
    
    # Generate HTML
    scenario_filename = Path(scenario_path).name
    html = HTML_TEMPLATE.format(
        level_name=level_name,
        victory_desc=victory_desc,
        location_count=len(locations),
        connection_count=len(connections),
        character_count=len(characters),
        monster_count=len(monsters),
        scenario_json=json.dumps(scenario, indent=2),
        background_image=background_image,
        scenario_filename=scenario_filename
    )
    
    # Determine output path
    if output_path is None:
        output_path = Path(scenario_path).with_suffix('.html')
    
    with open(output_path, 'w') as f:
        f.write(html)
    
    return output_path


def main():
    # Parse arguments
    open_browser = '--open' in sys.argv
    args = [a for a in sys.argv[1:] if not a.startswith('--')]
    
    # Determine scenario file path
    if args:
        scenario_path = args[0]
    else:
        # Default to test_0.json relative to this script
        script_dir = Path(__file__).parent.parent
        scenario_path = script_dir / 'configs' / 'test_0.json'
    
    if not os.path.exists(scenario_path):
        print(f"Error: Scenario file not found: {scenario_path}")
        print("\nUsage: python scenario_viewer.py [scenario_file] [--open]")
        print("\nAvailable scenarios:")
        configs_dir = Path(__file__).parent.parent / 'configs'
        if configs_dir.exists():
            for f in configs_dir.glob('*.json'):
                print(f"  {f}")
        sys.exit(1)
    
    print(f"Loading scenario: {scenario_path}")
    output_path = generate_html(scenario_path)
    print(f"Generated: {output_path}")
    
    if open_browser:
        webbrowser.open(f'file://{os.path.abspath(output_path)}')
        print("Opened in browser")
    else:
        print(f"\nOpen in browser: file://{os.path.abspath(output_path)}")
        print("Or run with --open to auto-open")


if __name__ == '__main__':
    main()
