/**
 * hub_status.js
 * -------------
 * Reads status/main.txt (the exact same line the IoT monitor parses) and
 * shows it on the hub - nothing more. No auto-selection, no redirects, no
 * game picking: the hub displays whatever main.txt says.
 *
 *   state=ALIVE;game=memory;time=...  ->  "Game running: Memory"
 *   state=DEAD;game=none;time=...     ->  "No game running"
 * The raw line is always available as the tooltip of the status element.
 */

(function () {
    'use strict';

    var REFRESH_MS = 30000;
    var NAMES = {
        rps: 'Rock Paper Scissors',
        xo: 'X and O',
        connect4: 'Connect 4',
        mathquiz: 'Math Quiz',
        snakeladder: 'Snake and Ladder',
        yatzy: 'Yatzy',
        memory: 'Memory'
    };

    var el = document.getElementById('hubGameStatus');
    if (!el) return;

    function render(text) {
        var line = (text || '').trim();
        if (line === '') {
            el.textContent = 'No game running';
            el.title = 'status/main.txt is missing or empty';
            return;
        }
        var parts = {};
        line.split(';').forEach(function (kv) {
            var i = kv.indexOf('=');
            if (i > 0) parts[kv.slice(0, i).trim()] = kv.slice(i + 1).trim();
        });
        var state = (parts.state || '').toUpperCase();
        var game = parts.game || '';
        if (state === 'ALIVE' && game !== '' && game !== 'none') {
            el.textContent = 'Game running: ' + (NAMES[game] || game);
        } else {
            el.textContent = 'No game running';
        }
        el.title = 'status/main.txt: ' + line;
    }

    function refresh() {
        fetch('status/main.txt?t=' + Date.now())
            .then(function (res) { return res.ok ? res.text() : ''; })
            .then(render)
            .catch(function () { el.textContent = 'No game running'; });
    }

    refresh();
    setInterval(refresh, REFRESH_MS);
})();