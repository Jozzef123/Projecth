/**
 * parent_mode.js
 * --------------
 * Parent Mode front-end, shared by the hub (index.html) and every game page.
 *
 * Hub mode  (body has no data-game attribute):
 *   - Adds a "Parent Mode" button next to the hub title.
 *   - Password is checked server-side (parent_backend.php action=check);
 *     it is never stored or hardcoded in this file.
 *   - On success shows the settings screen (limit by games / days, Save and
 *     No Limit / Reset) and starts a Parent Mode session for this browser tab.
 *
 * Game mode (body has data-game="memory" etc.):
 *   - Runs BEFORE the game scripts (see the guard in the game .html files) and
 *     registers the game open / asks parent_backend.php whether the limit is
 *     reached (action=game_opened, synchronous).
 *   - If locked: window.PARENT_MODE_LOCKED = true, so the game scripts are not
 *     loaded at all - the board is never rendered, no room is created and no
 *     status file is written. Only the lock message and a "Back to Hub" button
 *     are shown. The lock lives in parent_settings.json, so it also survives
 *     page reloads.
 *   - While a Parent Mode session is active the lock is skipped: the limit
 *     applies in normal (non-parent) mode only.
 */

(function () {
    'use strict';

    var BACKEND = 'parent_backend.php';
    var GAME_ID = document.body.getAttribute('data-game') || '';

    function api(action, params) {
        var url = new URL(BACKEND, window.location.href);
        url.searchParams.set('action', action);
        Object.keys(params || {}).forEach(function (k) {
            url.searchParams.set(k, params[k]);
        });
        return fetch(url.toString()).then(function (res) { return res.json(); });
    }

    // Parent Mode session (set while a parent is logged in). The limit applies in
    // normal mode only and the End Game button is hidden while it is active.
    var SESSION_KEY = 'parent_mode_active';

    function isParentSession() {
        try { return sessionStorage.getItem(SESSION_KEY) === '1'; } catch (e) { return false; }
    }

    function startParentSession() {
        try { sessionStorage.setItem(SESSION_KEY, '1'); } catch (e) {}
    }

    function endParentSession() {
        try { sessionStorage.removeItem(SESSION_KEY); } catch (e) {}
    }

    // Shared with end_game_helper.js (the End Game button is hidden in Parent Mode).
    window.parentModeSessionActive = isParentSession;

    function updateParentButton() {
        var btn = document.getElementById('parentModeBtn');
        if (!btn) return;
        var active = isParentSession();
        btn.textContent = active ? 'Parent Mode: ON' : 'Parent Mode';
        btn.style.background = active ? '#28a745' : '#444466';
    }

    // ------------------------------------------------------------------
    // Game mode: lock guard. Runs synchronously, BEFORE the game scripts are
    // loaded (see the guard in the game .html files), so a locked game never
    // renders its board, never creates a room and never writes to the status
    // files.
    // ------------------------------------------------------------------
    function fetchLockStatus() {
        var xhr = new XMLHttpRequest();
        // game_opened counts this game open (which is what can trigger the limit)
        // and answers whether the hub is locked right now.
        xhr.open('GET', BACKEND + '?action=game_opened&game=' + encodeURIComponent(GAME_ID), false);
        xhr.send(null);
        if (!xhr.responseText) return null;
        try { return JSON.parse(xhr.responseText); } catch (e) { return null; }
    }

    function showLock(info) {
        // Do NOT render the game board - drop the game markup and show only the
        // lock message with a "Back to Hub" button.
        while (document.body.firstChild) document.body.removeChild(document.body.firstChild);

        var overlay = document.createElement('div');
        overlay.id = 'parentLockScreen';
        overlay.style.cssText = 'position:fixed;inset:0;background:#1e1e2f;z-index:9999;' +
            'display:flex;flex-direction:column;align-items:center;justify-content:center;gap:18px;text-align:center;padding:20px;';
        overlay.innerHTML =
            '<div style="font-size:64px;">&#128274;</div>' +
            '<h1 style="margin:0;">Limit reached. Ask a parent to unlock.</h1>' +
            '<p style="color:#b3b3cc;margin:0;">Limit type: ' + (info.limit_type || 'games') +
            ' (limit: ' + (info.limit_value || 100) + ')</p>' +
            '<a href="index.html" style="padding:12px 25px;background:#28a745;color:#fff;' +
            'text-decoration:none;border-radius:6px;font-weight:bold;">Back to Hub</a>';
        document.body.appendChild(overlay);
        document.title = 'Limit reached - Gaming Hub';
    }

    if (GAME_ID) {
        // The limit applies in normal (non-parent) mode only.
        if (!isParentSession()) {
            var lockInfo = null;
            try { lockInfo = fetchLockStatus(); } catch (e) { lockInfo = null; }
            if (lockInfo && lockInfo.ok && lockInfo.locked) {
                window.PARENT_MODE_LOCKED = true;
                showLock(lockInfo);
            }
        }
        return; // game pages get nothing else from this file
    }

    // ------------------------------------------------------------------
    // Hub mode: Parent Mode button + password modal + settings screen
    // ------------------------------------------------------------------
    var sessionPassword = null; // kept only in memory for this page view

    function buildUi() {
        var title = document.querySelector('h1');
        if (!title) return;

        var btn = document.createElement('button');
        btn.id = 'parentModeBtn';
        btn.textContent = 'Parent Mode';
        btn.style.cssText = 'margin-left:14px;padding:6px 14px;font-size:13px;border:none;' +
            'border-radius:20px;background:#444466;color:#fff;cursor:pointer;vertical-align:middle;';
        btn.addEventListener('click', openModal);
        title.appendChild(btn);
        updateParentButton();

        var modal = document.createElement('div');
        modal.id = 'parentModal';
        modal.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,0.7);z-index:10000;' +
            'display:none;align-items:center;justify-content:center;';
        modal.innerHTML =
            '<div style="background:#2f2f4a;border-radius:16px;padding:30px;max-width:340px;width:90%;text-align:center;">' +
            '<h2 style="margin:0 0 14px 0;">Parent Mode</h2>' +
            '<input type="password" id="parentPassInput" placeholder="Password" style="width:100%;padding:10px 12px;' +
            'border-radius:6px;border:none;background:#1e1e2f;color:#fff;margin-bottom:12px;box-sizing:border-box;">' +
            '<div id="parentPassError" style="color:#dc3545;font-size:13px;min-height:18px;margin-bottom:6px;"></div>' +
            '<button id="parentPassSubmit" style="padding:10px 24px;border:none;border-radius:6px;background:#28a745;' +
            'color:#fff;font-weight:bold;cursor:pointer;">Enter</button> ' +
            '<button id="parentPassCancel" style="padding:10px 24px;border:none;border-radius:6px;background:#6c757d;' +
            'color:#fff;cursor:pointer;">Cancel</button>' +
            '</div>';
        document.body.appendChild(modal);

        document.getElementById('parentPassSubmit').addEventListener('click', submitPassword);
        document.getElementById('parentPassInput').addEventListener('keydown', function (e) {
            if (e.key === 'Enter') submitPassword();
        });
        document.getElementById('parentPassCancel').addEventListener('click', closeModal);
    }

    function openModal() {
        document.getElementById('parentPassError').textContent = '';
        document.getElementById('parentPassInput').value = '';
        document.getElementById('parentModal').style.display = 'flex';
        document.getElementById('parentPassInput').focus();
    }

    function closeModal() {
        document.getElementById('parentModal').style.display = 'none';
    }

    function submitPassword() {
        var input = document.getElementById('parentPassInput');
        var err = document.getElementById('parentPassError');
        api('check', { password: input.value }).then(function (data) {
            if (data && data.ok && data.correct) {
                sessionPassword = input.value;
                startParentSession();   // parent browsing is not limited
                updateParentButton();
                document.getElementById('parentModal').style.display = 'none';
                openSettings();
            } else {
                err.textContent = (data && data.message) || 'Incorrect password';
            }
        }).catch(function () {
            err.textContent = 'Could not reach the server.';
        });
    }

    function buildSettingsUi() {
        var settings = document.createElement('div');
        settings.id = 'parentSettings';
        settings.style.cssText = 'position:fixed;inset:0;background:#1e1e2f;z-index:10001;' +
            'display:none;flex-direction:column;align-items:center;justify-content:center;gap:14px;padding:20px;text-align:center;';
        settings.innerHTML =
            '<h1 style="margin:0;">Parent Mode Settings</h1>' +
            '<div style="background:#2f2f4a;border-radius:16px;padding:26px;max-width:380px;width:100%;">' +
            '<label style="display:block;text-align:left;color:#b3b3cc;font-size:13px;margin-bottom:6px;">Limit by number of games</label>' +
            '<input type="number" id="limitGamesInput" min="1" placeholder="e.g. 5" style="width:100%;padding:10px;border-radius:6px;' +
            'border:none;background:#1e1e2f;color:#fff;margin-bottom:14px;box-sizing:border-box;">' +
            '<label style="display:block;text-align:left;color:#b3b3cc;font-size:13px;margin-bottom:6px;">Limit by number of days</label>' +
            '<input type="number" id="limitDaysInput" min="1" placeholder="e.g. 7" style="width:100%;padding:10px;border-radius:6px;' +
            'border:none;background:#1e1e2f;color:#fff;margin-bottom:14px;box-sizing:border-box;">' +
            '<div id="parentSettingsMsg" style="font-size:13px;min-height:18px;margin-bottom:8px;color:#28a745;"></div>' +
            '<button id="parentSaveBtn" style="padding:10px 24px;border:none;border-radius:6px;background:#28a745;color:#fff;' +
            'font-weight:bold;cursor:pointer;margin:4px;">Save</button>' +
            '<button id="parentResetBtn" style="padding:10px 24px;border:none;border-radius:6px;background:#6c757d;color:#fff;' +
            'cursor:pointer;margin:4px;">No Limit / Reset</button> ' +
            '<button id="parentStatsBtn" style="padding:10px 24px;border:none;border-radius:6px;background:#444466;color:#fff;' +
            'cursor:pointer;margin:4px;">Stats</button>' +
            '<button id="parentCloseBtn" style="padding:10px 24px;border:none;border-radius:6px;background:#dc3545;color:#fff;' +
            'cursor:pointer;margin:4px;">Close</button>' +
            '</div>';
        document.body.appendChild(settings);

        document.getElementById('parentSaveBtn').addEventListener('click', saveLimits);
        document.getElementById('parentResetBtn').addEventListener('click', resetLimits);
        document.getElementById('parentStatsBtn').addEventListener('click', function () {
            // parent_stats.html re-asks for the password (server-side check).
            window.location.href = 'parent_stats.html';
        });
        document.getElementById('parentCloseBtn').addEventListener('click', function () {
            sessionPassword = null;
            endParentSession();         // back to normal (limited) mode
            updateParentButton();
            settings.style.display = 'none';
        });
    }

    function openSettings() {
        buildSettingsUi();
        var settings = document.getElementById('parentSettings');
        settings.style.display = 'flex';
        api('get', { password: sessionPassword }).then(function (data) {
            if (!data || !data.ok) return;
            document.getElementById('limitGamesInput').value =
                (data.limit_type === 'games' && data.limit_value !== 100) ? data.limit_value : '';
            document.getElementById('limitDaysInput').value =
                (data.limit_type === 'days') ? data.limit_value : '';
        }).catch(function () {});
    }

    function saveLimits() {
        var msg = document.getElementById('parentSettingsMsg');
        api('save', {
            password: sessionPassword,
            limit_games: document.getElementById('limitGamesInput').value || 0,
            limit_days: document.getElementById('limitDaysInput').value || 0
        }).then(function (data) {
            msg.style.color = (data && data.ok) ? '#28a745' : '#dc3545';
            msg.textContent = (data && data.ok) ? 'Limit saved.' : ((data && data.error) || 'Error saving.');
        }).catch(function () {
            msg.style.color = '#dc3545';
            msg.textContent = 'Could not reach the server.';
        });
    }

    function resetLimits() {
        var msg = document.getElementById('parentSettingsMsg');
        api('reset', { password: sessionPassword }).then(function (data) {
            msg.style.color = (data && data.ok) ? '#28a745' : '#dc3545';
            msg.textContent = (data && data.ok) ? 'Limits removed (default 100 games).' : ((data && data.error) || 'Error.');
            if (data && data.ok) {
                document.getElementById('limitGamesInput').value = '';
                document.getElementById('limitDaysInput').value = '';
            }
        }).catch(function () {});
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', buildUi);
    } else {
        buildUi();
    }
})();
