/**
 * parent_stats.js
 * ---------------
 * Front-end for parent_stats.html. The password is checked server-side
 * (parent_backend.php action=get) and is never stored.
 */

(function () {
    'use strict';

    var loginPanel = document.getElementById('loginPanel');
    var statsPanel = document.getElementById('statsPanel');
    var loginError = document.getElementById('loginError');
    var password = '';

    function api(action, params) {
        var url = new URL('parent_backend.php', window.location.href);
        url.searchParams.set('action', action);
        Object.keys(params || {}).forEach(function (k) {
            url.searchParams.set(k, params[k]);
        });
        return fetch(url.toString()).then(function (res) { return res.json(); });
    }

    function render(data) {
        document.getElementById('statLimitType').textContent = data.limit_type || 'none';
        document.getElementById('statLimitValue').textContent = data.limit_value || 100;
        document.getElementById('statGamesRemaining').textContent =
            (data.games_remaining === null || data.games_remaining === undefined) ? 'No game limit' : data.games_remaining;
        document.getElementById('statDaysRemaining').textContent =
            (data.days_remaining === null || data.days_remaining === undefined) ? 'No day limit' : data.days_remaining;
        document.getElementById('statSetDate').textContent = data.set_date || 'Never set (defaults apply)';

        var dead = data.dead_games || [];
        var el = document.getElementById('deadGames');
        if (dead.length === 0) {
            el.textContent = 'None';
        } else {
            var html = '<ul>';
            dead.forEach(function (g) { html += '<li>' + g + '</li>'; });
            el.innerHTML = html + '</ul>';
        }
    }

    function loadStats() {
        api('get', { password: password }).then(function (data) {
            if (!data || !data.ok) {
                loginError.textContent = (data && data.error) || 'Incorrect password';
                loginError.classList.remove('hidden');
                statsPanel.classList.add('hidden');
                loginPanel.classList.remove('hidden');
                return;
            }
            render(data);
            loginPanel.classList.add('hidden');
            statsPanel.classList.remove('hidden');
        }).catch(function () {
            loginError.textContent = 'Could not reach the server.';
            loginError.classList.remove('hidden');
        });
    }

    document.getElementById('loginBtn').addEventListener('click', function () {
        password = document.getElementById('passwordInput').value;
        loginError.classList.add('hidden');
        loadStats();
    });
    document.getElementById('passwordInput').addEventListener('keydown', function (e) {
        if (e.key === 'Enter') document.getElementById('loginBtn').click();
    });
    document.getElementById('refreshBtn').addEventListener('click', loadStats);
})();
