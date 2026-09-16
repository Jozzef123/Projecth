/**
 * end_game_helper.js
 * ------------------
 * Shared "End Game" button + endGameAndGoHome() helper, used by every game
 * page (Connect4, XO, Yatzy, Memory, RPS, Math Quiz, Snake and Ladder).
 *
 * - Injects a visible "End Game" button in the top-right corner of the game
 *   screen (styled like the hub buttons).
 * - endGameAndGoHome(gameId, roomId):
 *     1. Confirm with the user ("Are you sure you want to end this game?").
 *     2. Yes -> mark the game dead via its own backend (the backend updates
 *        BOTH the game's own status file and the MAIN hub stats with the same
 *        call it uses when a game naturally ends), then go to index.html.
 *     3. No  -> close the confirmation and stay in the game.
 * - The button is NOT shown in Parent Mode (see parent_mode.js): a parent
 *   should not end games by accident.
 */

(function () {
    'use strict';

    var GAME_ID = document.body.getAttribute('data-game') || '';

    // Parent Mode session flag, shared by parent_mode.js. Falls back to the
    // session flag itself if that helper is not loaded on this page.
    function parentModeActive() {
        if (typeof window.parentModeSessionActive === 'function') {
            return window.parentModeSessionActive();
        }
        try { return sessionStorage.getItem('parent_mode_active') === '1'; } catch (e) { return false; }
    }

    window.endGameAndGoHome = function (gameId, roomId) {
        // 1. Confirm
        if (!window.confirm('Are you sure you want to end this game?')) {
            return; // No: stay in the game
        }
        // 2. Mark dead (own status + main stats are synced by the backend).
        //    Without a room the backend still marks the game itself dead.
        var gid = gameId || GAME_ID;
        var goHome = function () { window.location.href = 'index.html'; };
        var params = new URLSearchParams({ action: 'end_game' });
        if (roomId) params.set('room', roomId);
        fetch(gid + '_backend.php?' + params.toString())
            .catch(function () { /* still go home even if the network fails */ })
            .then(goHome, goHome);
    };

    // Visible "End Game" button - top-right corner of every game screen.
    if (GAME_ID && document.body && !parentModeActive()) {
        var btn = document.createElement('button');
        btn.id = 'endGameHomeBtn';
        btn.type = 'button';
        btn.textContent = 'End Game';
        btn.style.cssText = 'position:fixed;top:12px;right:12px;z-index:500;padding:8px 16px;' +
            'border:none;border-radius:6px;background:#dc3545;color:#fff;font-weight:bold;' +
            'cursor:pointer;font-family:inherit;box-shadow:0 4px 10px rgba(0,0,0,0.3);';
        btn.addEventListener('click', function () {
            // Each game exposes its current room id via window.getCurrentRoomId().
            var rid = (typeof window.getCurrentRoomId === 'function') ? window.getCurrentRoomId() : null;
            window.endGameAndGoHome(GAME_ID, rid);
        });
        document.body.appendChild(btn);
    }

    // ------------------------------------------------------------------
    // Leave detection: tell the backend when a player closes the tab,
    // navigates away, or hides the page.
    //   pagehide + beforeunload  -> hard leave (action=leave)
    //   visibilitychange hidden  -> soft leave (action=leave&soft=1)
    //   visibilitychange visible -> player is back (action=back)
    // Hard leaves from BOTH players end the room immediately (all_players_left);
    // soft leaves are recorded and the normal inactivity timeout is the
    // backstop. navigator.sendBeacon survives page unload; fetch(keepalive)
    // is the fallback. See player_leave_room() in status_helper.php.
    // ------------------------------------------------------------------
    function leaveBeaconUrl(action, soft) {
        var rid = (typeof window.getCurrentRoomId === 'function') ? window.getCurrentRoomId() : null;
        var pid = (typeof window.getCurrentPlayerId === 'function') ? window.getCurrentPlayerId() : null;
        if (!GAME_ID || !rid || !pid) return '';
        return GAME_ID + '_backend.php?action=' + action +
            '&room=' + encodeURIComponent(rid) +
            '&player=' + encodeURIComponent(pid) +
            (soft ? '&soft=1' : '');
    }

    function sendLeaveBeacon(url) {
        if (!url) return;
        try {
            if (navigator.sendBeacon) {
                navigator.sendBeacon(url);
            } else {
                fetch(url, { keepalive: true }).catch(function () {});
            }
        } catch (e) { /* never block unloading */ }
    }

    window.addEventListener('pagehide', function () {
        sendLeaveBeacon(leaveBeaconUrl('leave', false));
    });
    window.addEventListener('beforeunload', function () {
        sendLeaveBeacon(leaveBeaconUrl('leave', false));
    });
    document.addEventListener('visibilitychange', function () {
        if (document.visibilityState === 'hidden') {
            sendLeaveBeacon(leaveBeaconUrl('leave', true));
        } else {
            sendLeaveBeacon(leaveBeaconUrl('back', false));
        }
    });
})();
