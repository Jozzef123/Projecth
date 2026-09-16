let roomId = null;
let playerId = null;
let myRole = null;
let currentRoomData = null;

// Expose the current room id for the shared End Game button (end_game_helper.js).
window.getCurrentRoomId = function () { return roomId; };
// Expose the current player id for leave detection (end_game_helper.js).
window.getCurrentPlayerId = function () { return playerId; };

const BACKEND_URL = './yatzy_backend.php';
const REQUEST_TIMEOUT_MS = 8000;

const diceIcons = ['', '⚀', '⚁', '⚂', '⚃', '⚄', '⚅'];

const shareBox = document.getElementById('shareBox');
const shareLinkInput = document.getElementById('shareLink');
const copyBtn = document.getElementById('copyBtn');
const endMatchBtn = document.getElementById('endMatchBtn');

function storageKey(rid) { return 'yatzy_player_' + rid; }
function saveLocalPlayer(rid, pid) { try { localStorage.setItem(storageKey(rid), pid); } catch (e) {} }
function getLocalPlayer(rid) { try { return localStorage.getItem(storageKey(rid)); } catch (e) { return null; } }

async function api(action, params = {}) {
    const url = new URL(BACKEND_URL, window.location.href);
    url.searchParams.set('action', action);
    Object.entries(params).forEach(([key, value]) => url.searchParams.set(key, value));
    const controller = new AbortController();
    const timeoutId = setTimeout(() => controller.abort(), REQUEST_TIMEOUT_MS);
    console.log('[Yatzy] Request start:', action, url.toString());

    try {
        const res = await fetch(url.toString(), {
            headers: { Accept: 'application/json' },
            signal: controller.signal,
        });
        const body = await res.text();
        if (!res.ok) throw new Error(`HTTP ${res.status}`);
        try {
            const data = JSON.parse(body);
            console.log('[Yatzy] Request success:', action, data);
            return data;
        } catch (e) {
            throw new Error('invalid_json');
        }
    } catch (error) {
        if (error.name === 'AbortError') {
            console.error('[Yatzy] Request timeout:', action);
            throw new Error('request_timeout');
        }
        console.error('[Yatzy] Request failure:', action, error);
        throw error;
    } finally {
        clearTimeout(timeoutId);
    }
}

function showServerError(error) {
    const message = error.message.includes('404')
        ? 'Yatzy backend returned 404. Start the PHP server from the project folder.'
        : error.message === 'request_timeout'
            ? 'Yatzy server request timed out after 8 seconds. Make sure PHP is running.'
            : 'Could not connect to the Yatzy server. Open this page through a PHP server.';
    document.getElementById('turnStatus').innerText = message;
    document.getElementById('rollBtn').disabled = true;
}

async function initGame() {
    try {
        const urlParams = new URLSearchParams(window.location.search);
        roomId = urlParams.get('room');

        if (!roomId) {
            // No room in the URL -> create a brand new room (player 1).
            let data = await api('create');
            if (!data.ok) throw new Error('create_failed');
            roomId = data.room_id;
            playerId = data.player_id;
            myRole = data.role;
            saveLocalPlayer(roomId, playerId);

            // Update the browser URL with the room id only (the share link must
            // NOT leak the creator's player id).
            window.history.replaceState({}, '', `?room=${roomId}`);

            // Show the shareable link, exactly like the other games.
            shareLinkInput.value = window.location.origin + window.location.pathname + '?room=' + roomId;
            shareBox.style.display = 'block';
        } else {
            // Room id present in the URL -> join it (as player 1 or 2
            // depending on the current room state). Use the stored player id
            // for this room so a refresh does not create a second seat.
            playerId = getLocalPlayer(roomId) || '';
            let data = await api('join', { room: roomId, player: playerId });
            if (!data.ok) {
            if (data.error === 'match_ended' && data.reason === 'inactivity') {
                document.getElementById('turnStatus').innerText = 'Game ended due to inactivity.';
                document.getElementById('rollBtn').disabled = true;
            } else if (data.error === 'match_ended') {
                document.getElementById('turnStatus').innerText = 'Match ended.';
                document.getElementById('rollBtn').disabled = true;
            } else if (data.error === 'room_full') {
                document.getElementById('turnStatus').innerText = 'This room is already full.';
            } else if (data.error === 'room_not_found') {
                document.getElementById('turnStatus').innerText = 'This link is invalid or the room no longer exists.';
            } else {
                throw new Error(data.error || 'join_failed');
            }
            return;
        }
            playerId = data.player_id;
            myRole = data.role;
            saveLocalPlayer(roomId, playerId);

            if (data.state === 'DEAD') {
                document.getElementById('turnStatus').innerText = data.end_reason === 'inactivity' ? 'Game ended due to inactivity.' : 'Match ended.';
                document.getElementById('rollBtn').disabled = true;
                return;
            }
        }

        document.getElementById('roomDisplay').innerText = `الغرفة: ${roomId}`;
        document.getElementById('roleDisplay').innerText = `Role: ${myRole === 'p1' ? 'P1 (Red)' : 'P2 (Blue)'}`;

        setInterval(pollStatus, 1000);
        await pollStatus();
    } catch (error) {
        showServerError(error);
    }
}

async function pollStatus() {
    if (!roomId) return;
    try {
        let data = await api('status', { room: roomId });
        if (data.ok) {
            if (data.state === 'DEAD') {
                const msg = data.end_reason === 'inactivity' ? 'Game ended due to inactivity.' : 'Match ended.';
                document.getElementById('turnStatus').innerText = msg;
                document.getElementById('rollBtn').disabled = true;
                if (endMatchBtn) endMatchBtn.style.display = 'none';
                return;
            }
            currentRoomData = data.room;
            renderUI();
        } else {
            if (data.error === 'match_ended' && data.reason === 'inactivity') {
                document.getElementById('turnStatus').innerText = 'Game ended due to inactivity.';
                document.getElementById('rollBtn').disabled = true;
            } else if (data.error === 'match_ended') {
                document.getElementById('turnStatus').innerText = 'Match ended.';
                document.getElementById('rollBtn').disabled = true;
            } else {
                showServerError(new Error(data.error || 'status_failed'));
            }
        }
    } catch (error) {
        showServerError(error);
    }
}

function renderUI() {
    const room = currentRoomData;
    const isMyTurn = room.current_turn === myRole && room.status === 'playing';

    // Status message
    if (room.status === 'waiting') {
        document.getElementById('turnStatus').innerText = 'waiting for opponent...';
    } else if (room.status === 'finished') {
        let p1Score = room.players_data.p1.total_score;
        let p2Score = room.players_data.p2.total_score;
        let winner = p1Score > p2Score ? 'P1 الفائز!' : (p2Score > p1Score ? 'p2 win' : 'tie');
        document.getElementById('turnStatus').innerText = `game over! ${winner}`;
    } else {
        document.getElementById('turnStatus').innerText = isMyTurn ? 'your turn!' : 'opponent\'s turn...';
    }

    // Roll button state
    const rollBtn = document.getElementById('rollBtn');
    rollBtn.innerText = `ROLL (${room.rolls_left})`;
    rollBtn.disabled = !isMyTurn || room.rolls_left <= 0;

    // Dice UI
    const diceDivs = document.querySelectorAll('.die');
    room.dice.forEach((val, idx) => {
        diceDivs[idx].innerText = val > 0 ? diceIcons[val] : '-';
        if (room.held[idx]) {
            diceDivs[idx].classList.add('held');
        } else {
            diceDivs[idx].classList.remove('held');
        }
    });

    // Scorecard UI
    ['p1', 'p2'].forEach(role => {
        const sc = room.players_data[role].scorecard;
        const categories = ['ones','twos','threes','fours','fives','sixes','3x','4x','house','small','large','yatzy','chance'];
        
        categories.forEach(cat => {
            const cell = document.querySelector(`.cell.${role}[onclick*="${cat}"]`) || document.querySelectorAll(`.cell.${role}`)[categories.indexOf(cat)];
            if (cell) {
                if (sc[cat] !== undefined) {
                    cell.innerText = sc[cat];
                    cell.style.background = role === 'p1' ? '#d63031' : '#0984e3';
                } else {
                    cell.innerText = '';
                }
            }
        });
    });
}

async function rollDice() {
    try {
        let data = await api('roll', { room: roomId, player: playerId });
        if (data.ok) pollStatus();
    } catch (error) {
        showServerError(error);
    }
}

async function toggleHold(index) {
    if (!currentRoomData || currentRoomData.current_turn !== myRole) return;
    try {
        let data = await api('toggle_hold', { room: roomId, player: playerId, index });
        if (data.ok) pollStatus();
    } catch (error) {
        showServerError(error);
    }
}

async function selectCat(category) {
    if (!currentRoomData || currentRoomData.current_turn !== myRole) return;
    if (currentRoomData.rolls_left >= 3) {
        alert('You must roll at least once before selecting a category.');
        return;
    }
    try {
        let data = await api('score', { room: roomId, player: playerId, category });
        if (data.ok) pollStatus();
    } catch (error) {
        showServerError(error);
    }
}

copyBtn.addEventListener('click', () => {
    shareLinkInput.select();
    shareLinkInput.setSelectionRange(0, 99999);
    try {
        document.execCommand('copy');
        const original = copyBtn.textContent;
        copyBtn.textContent = 'Copied!';
        setTimeout(() => { copyBtn.textContent = original; }, 1500);
    } catch (e) {
        // Fallback: user can select and copy manually.
    }
});

endMatchBtn.addEventListener('click', async () => {
    try {
        let data = await api('end_game', { room: roomId, player: playerId });
        if (data.ok) pollStatus();
    } catch (error) {
        showServerError(error);
    }
});

// Periodically ask the backend to sweep idle rooms (inactivity timeout).
setInterval(() => { api('cleanup').catch(() => {}); }, 60000);

window.onload = initGame;
