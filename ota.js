/**
 * ota.js
 * ------
 * Front-end for ota.html - the firmware (OTA) publishing admin page.
 * Talks only to ota_backend.php (actions: upload | list | activate | status).
 *
 * Flow:
 *   - Page load: only the login panel is visible. An open action=status call
 *     fills the "Currently published" panel (the same info the device already
 *     sees anonymously in status/ota.txt).
 *   - Login: action=status first (open ping - proves the backend is reachable),
 *     then action=list with the password (checked server-side against
 *     parent_password.txt). The password lives only in this JS variable, never
 *     in storage/cookies (same policy as parent_stats.js).
 *   - Upload: multipart POST (field name MUST be "firmware", see the backend).
 *     Upload never publishes - activate is the only writer of status/ota.txt.
 *   - Activate: per-row button sending action=activate&version=X&password=...
 *     A rollback (version <= live) is refused with needs_confirm:true until the
 *     admin confirms the downgrade dialog and the request is resent with
 *     confirm=1.
 *
 * Every request carries a 10 s AbortController timeout so the UI reports a
 * clear error instead of hanging. Each step is logged to the console.
 */

(function () {
    'use strict';

    var BACKEND = 'ota_backend.php';
    var TIMEOUT_MS = 10000;   // every request: 10 s, then a clear error

    // ---------------- elements ----------------
    var loginPanel   = document.getElementById('loginPanel');
    var otaPanel     = document.getElementById('otaPanel');
    var uploadPanel  = document.getElementById('uploadPanel');
    var listPanel    = document.getElementById('listPanel');

    var passwordInput = document.getElementById('passwordInput');
    var loginBtn      = document.getElementById('loginBtn');
    var loginError    = document.getElementById('loginError');

    var liveInfo = document.getElementById('liveInfo');

    var firmwareFile = document.getElementById('firmwareFile');
    var versionInput = document.getElementById('versionInput');
    var uploadBtn    = document.getElementById('uploadBtn');
    var uploadMsg    = document.getElementById('uploadMsg');

    var versionRows = document.getElementById('versionRows');
    var listMsg     = document.getElementById('listMsg');

    var password = '';   // memory only - never persisted (spec: no session/cookie)

    // ---------------- small helpers ----------------

    function escapeHtml(value) {
        return String(value === null || value === undefined ? '' : value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    function showMsg(el, text, ok) {
        el.textContent = text;
        el.classList.remove('hidden', 'ok', 'err');
        el.classList.add(ok ? 'ok' : 'err');
    }

    function hideMsg(el) {
        el.classList.add('hidden');
        el.classList.remove('ok', 'err');
        el.textContent = '';
    }

    function formatBytes(n) {
        n = parseInt(n, 10) || 0;
        if (n < 1024) return n + ' B';
        if (n < 1024 * 1024) return (n / 1024).toFixed(1) + ' KiB';
        return (n / (1024 * 1024)).toFixed(2) + ' MiB';
    }

    function backendError(err) {
        if (err && err.name === 'AbortError') {
            return 'The server did not respond within ' + (TIMEOUT_MS / 1000) +
                ' seconds. Is ota_backend.php uploaded and the site reachable?';
        }
        if (err && err.message) return err.message;
        return 'Could not reach the server (network error).';
    }

    // fetch + 10 s AbortController timeout + JSON parsing (HTML error pages and
    // PHP fatal-error output are reported as clear errors, never swallowed).
    function requestJson(url, options) {
        var controller = new AbortController();
        var timer = setTimeout(function () { controller.abort(); }, TIMEOUT_MS);
        options = options || {};
        options.signal = controller.signal;

        return fetch(url, options).then(function (res) {
            clearTimeout(timer);
            return res.text().then(function (text) {
                var data;
                try {
                    data = JSON.parse(text);
                } catch (e) {
                    throw new Error('The backend did not return JSON (HTTP ' + res.status +
                        '). Is ota_backend.php in the web root?');
                }
                return data;
            });
        }, function (err) {
            clearTimeout(timer);
            throw err;   // handled by backendError() at the call sites
        });
    }

    // All browser->backend calls are POSTs; the backend reads $_REQUEST, so a
    // urlencoded POST body behaves exactly like a query string.
    function postParams(params) {
        var body = new URLSearchParams();
        Object.keys(params).forEach(function (k) { body.set(k, params[k]); });
        return requestJson(BACKEND, { method: 'POST', body: body });
    }

    function postForm(formData) {
        return requestJson(BACKEND, { method: 'POST', body: formData });
    }

    // ---------------- status ("Currently published", open) ----------------

    function loadStatus() {
        return postParams({ action: 'status' }).then(function (data) {
            console.log('status fetched', data);
            if (!data || !data.ok) {
                throw new Error((data && data.error) || 'status failed');
            }
            liveInfo.innerHTML =
                '<div><strong>Published:</strong> ' +
                    (data.published ? '<span class="live-tag">YES</span>' : 'NO') + '</div>' +
                '<div><strong>Live version:</strong> ' +
                    (data.live_version > 0 ? String(data.live_version) : 'none') + '</div>' +
                '<div><strong>status/ota.txt:</strong> ' +
                    (data.ota_txt ? escapeHtml(data.ota_txt) : '<em>(empty - nothing published yet)</em>') + '</div>' +
                '<div><strong>url_base:</strong> ' + escapeHtml(data.url_base) + '</div>' +
                '<div><strong>Versions stored:</strong> ' + escapeHtml(data.versions) + '</div>';
            return data;
        });
    }

    // ---------------- list + version table ----------------

    function refreshList() {
        return postParams({ action: 'list', password: password }).then(function (data) {
            if (!data || !data.ok) {
                throw new Error((data && data.error) || 'list failed');
            }
            renderTable(data.records || [], data.live_version || 0);
            return data;
        });
    }

    function renderTable(records, liveVersion) {
        if (!records.length) {
            versionRows.innerHTML =
                '<tr><td colspan="6">No firmware uploaded yet. Use the Upload panel above.</td></tr>';
            return;
        }
        var html = '';
        records.forEach(function (r) {
            var isLive = parseInt(r.version, 10) === liveVersion;
            html += '<tr>' +
                '<td class="num">' + escapeHtml(r.version) +
                    (isLive ? ' <span class="live-tag">LIVE</span>' : '') + '</td>' +
                '<td class="mono">' + escapeHtml(r.file) + '</td>' +
                '<td>' + escapeHtml(r.uploaded_at || '') + '</td>' +
                '<td class="num">' + formatBytes(r.size) + '</td>' +
                '<td class="mono">' + escapeHtml(r.crc32) + '</td>' +
                '<td>' + (isLive
                    ? '<button class="btn small gray" disabled>Live</button>'
                    : '<button class="btn small" data-version="' + escapeHtml(r.version) + '">Activate</button>') +
                '</td>' +
                '</tr>';
        });
        versionRows.innerHTML = html;
    }

    function setActivateButtonsDisabled(disabled) {
        var buttons = versionRows.querySelectorAll('button[data-version]');
        for (var i = 0; i < buttons.length; i++) buttons[i].disabled = disabled;
    }

    // ---------------- activate (publish) ----------------

    function activateVersion(version, confirmed) {
        var params = { action: 'activate', version: version, password: password };
        if (confirmed) params.confirm = '1';

        setActivateButtonsDisabled(true);
        hideMsg(listMsg);

        return postParams(params).then(function (data) {
            console.log('activate response', data);

            if (data && !data.ok && data.needs_confirm) {
                var again = window.confirm(
                    'Version ' + data.requested_version +
                    ' is NOT newer than the live version ' + data.current_version + '.\n\n' +
                    'This is a ROLLBACK: the device will DOWNGRADE to the older firmware.\n' +
                    'Publish it anyway?'
                );
                if (!again) return data;                   // cancelled - nothing changed
                return activateVersion(version, true);     // re-send with confirm=1
            }

            if (!data || !data.ok) {
                throw new Error((data && data.error) || 'Activation failed.');
            }

            showMsg(listMsg, data.message + '  ota.txt: ' + data.ota_txt, true);
            return Promise.all([refreshList(), loadStatus()]).then(function () { return data; });
        }).catch(function (err) {
            showMsg(listMsg, backendError(err), false);
            return null;
        }).then(function (result) {
            setActivateButtonsDisabled(false);
            return result;
        });
    }

    function onTableClick(ev) {
        var target = ev.target;
        var button = target && target.closest ? target.closest('button[data-version]') : null;
        if (!button || button.disabled) return;
        var version = parseInt(button.getAttribute('data-version'), 10);
        if (!version) return;
        activateVersion(version, false);
    }

    // ---------------- login ----------------

    function doLogin() {
        hideMsg(loginError);
        password = passwordInput.value;

        loginBtn.disabled = true;
        loginBtn.textContent = 'Checking...';

        // Step 1 (open): proves the backend itself answers.
        loadStatus()
            // Step 2: verifies the password server-side via action=list.
            .then(function () { return refreshList(); })
            .then(function () {
                console.log('login success');
                loginPanel.classList.add('hidden');
                otaPanel.classList.remove('hidden');
                uploadPanel.classList.remove('hidden');
                listPanel.classList.remove('hidden');
            })
            .catch(function (err) {
                console.log('login failed', err);
                showMsg(loginError, backendError(err), false);
            })
            .then(function () {
                loginBtn.disabled = false;
                loginBtn.textContent = 'Enter';
            });
    }

    // ---------------- upload ----------------

    function doUpload() {
        hideMsg(uploadMsg);

        var file = firmwareFile.files && firmwareFile.files[0];
        if (!file) {
            showMsg(uploadMsg, 'Choose a .hex file first.', false);
            return;
        }

        var formData = new FormData();
        formData.append('action', 'upload');
        formData.append('password', password);
        if (versionInput.value.trim() !== '') {
            formData.append('version', versionInput.value.trim());
        }
        formData.append('firmware', file, file.name);

        uploadBtn.disabled = true;
        uploadBtn.textContent = 'Uploading...';
        console.log('upload started', file.name, file.size + ' bytes');

        postForm(formData)
            .then(function (data) {
                console.log('upload response', data);
                if (!data || !data.ok) {
                    throw new Error((data && data.error) || 'Upload failed.');
                }
                showMsg(uploadMsg,
                    data.message || ('Version ' + data.version + ' uploaded (not published yet).'),
                    true);
                firmwareFile.value = '';
                versionInput.value = '';
                return refreshList();
            })
            .catch(function (err) {
                showMsg(uploadMsg, backendError(err), false);
            })
            .then(function () {
                uploadBtn.disabled = false;
                uploadBtn.textContent = 'Upload';
            });
    }

    // ---------------- init ----------------

    function init() {
        // Only the login gate is visible until the password is verified
        // (the panels are already hidden in ota.html; enforced here too).
        loginPanel.classList.remove('hidden');
        otaPanel.classList.add('hidden');
        uploadPanel.classList.add('hidden');
        listPanel.classList.add('hidden');

        loginBtn.addEventListener('click', doLogin);
        passwordInput.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') doLogin();
        });
        uploadBtn.addEventListener('click', doUpload);
        versionRows.addEventListener('click', onTableClick);

        // Open status ping on page load: proves the backend is reachable and
        // fills the "Currently published" panel (action=status needs no password).
        loadStatus().catch(function (err) {
            liveInfo.textContent = 'Backend unreachable: ' + backendError(err);
            console.log('status fetch failed', err);
        });
    }

    init();
})();
