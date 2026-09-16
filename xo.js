/**
 * xo.js
 * -----
 * Front-end logic for the 2-player online Tic-Tac-Toe game.
 * Mirrors the RPS room flow: create or join by URL, store player identity in
 * localStorage, then poll xo_backend.php for the current board state.
 */

const BACKEND_URL = 'xo_backend.php';
const POLL_INTERVAL_MS = 1500;

const ROLE_SYMBOL = { p1: 'X', p2: 'O' };
const ROLE_LABEL = { p1: 'Player 1 (X)', p2: 'Player 2 (O)' };

const waitingScreen = document.getElementById('waitingScreen');
const waitingText = document.getElementById('waitingText');
const shareBox = document.getElementById('shareBox');
const shareLinkInput = document.getElementById('shareLink');
const copyBtn = document.getElementById('copyBtn');

const gameScreen = document.getElementById('gameScreen');
const roleTag = document.getElementById('roleTag');
const turnPrompt = document.getElementById('turnPrompt');
const turnNote = document.getElementById('turnNote');
const cellButtons = document.querySelectorAll('.cell-btn[data-index]');
const xScore = document.getElementById('xScore');
const oScore = document.getElementById('oScore');
const drawScore = document.getElementById('drawScore');

const resultScreen = document.getElementById('resultScreen');
const resultRoleTag = document.getElementById('resultRoleTag');
const resultMsg = document.getElementById('resultMsg');
const resultCells = document.querySelectorAll('.cell-btn[data-result-index]');
const resultXScore = document.getElementById('resultXScore');
const resultOScore = document.getElementById('resultOScore');
const resultDrawScore = document.getElementById('resultDrawScore');
const nextRoundBtn = document.getElementById('nextRoundBtn');
const endMatchBtn = document.getElementById('endMatchBtn');

const errorScreen = document.getElementById('errorScreen');
const errorText = document.getElementById('errorText');

let roomId = null;
let playerId = null;
let myRole = null;
let pollTimer = null;

// Expose the current room id for the shared End Game button (end_game_helper.js).
window.getCurrentRoomId = function () { return roomId; };
// Expose the current player id for leave detection (end_game_helper.js).
window.getCurrentPlayerId = function () { return playerId; };
let lastKnownRound = 1;

function showOnly(el) {
    [waitingScreen, gameScreen, resultScreen, errorScreen].forEach(s => s.classList.add('hidden'));
    el.classList.remove('hidden');
}

function showError(message) {
    stopPolling();
    errorText.textContent = message;
    showOnly(errorScreen);
}

async function api(action, params = {}) {
    const url = new URL(BACKEND_URL, window.location.href);
    url.searchParams.set('action', action);
    Object.entries(params).forEach(([key, value]) => url.searchParams.set(key, value));
    const res = await fetch(url.toString());
    if (!res.ok) throw new Error('network_error');
    return res.json();
}

function storageKey(rid) {
    return 'xo_player_' + rid;
}

function saveLocalPlayer(rid, pid) {
    try { localStorage.setItem(storageKey(rid), pid); } catch (e) {}
}

function getLocalPlayer(rid) {
    try { return localStorage.getItem(storageKey(rid)); } catch (e) { return null; }
}

async function init() {
    const params = new URLSearchParams(window.location.search);
    const roomParam = params.get('room');

    if (!roomParam) {
        await createRoom();
    } else {
        roomId = roomParam;
        await joinRoom(roomId, getLocalPlayer(roomId));
    }
}

async function createRoom() {
    showOnly(waitingScreen);
    waitingText.textContent = 'Setting up the room...';
    try {
        const data = await api('create');
        if (!data.ok) {
            showError('Something went wrong creating the room. Please try again.');
            return;
        }

        roomId = data.room_id;
        playerId = data.player_id;
        myRole = data.role;
        saveLocalPlayer(roomId, playerId);

        const newUrl = window.location.pathname + '?room=' + roomId;
        window.history.replaceState({}, '', newUrl);

        shareLinkInput.value = window.location.origin + window.location.pathname + '?room=' + roomId;
        shareBox.classList.remove('hidden');
        waitingText.textContent = 'Room is ready!';

        startPolling();
    } catch (e) {
        showError('Could not connect to the server. Make sure the server is running.');
    }
}

async function joinRoom(rid, existingPlayerId) {
    showOnly(waitingScreen);
    shareBox.classList.add('hidden');
    waitingText.textContent = 'Joining the room...';
    try {
        const data = await api('join', {
            room: rid,
            player: existingPlayerId || '',
        });

        if (!data.ok) {
            if (data.error === 'room_full') {
                showError('This room is already full (2 players). Create a new room from the main menu instead.');
            } else if (data.error === 'room_not_found') {
                showError('This link is invalid or the room no longer exists.');
            } else {
                showError('An unexpected error occurred. Please try again.');
            }
            return;
        }

        roomId = data.room_id;
        playerId = data.player_id;
        myRole = data.role;
        saveLocalPlayer(roomId, playerId);

        startPolling();
    } catch (e) {
        showError('Could not connect to the server. Make sure the server is running.');
    }
}

function startPolling() {
    stopPolling();
    pollTimer = setInterval(pollStatus, POLL_INTERVAL_MS);
    pollStatus();
}

function stopPolling() {
    if (pollTimer) {
        clearInterval(pollTimer);
        pollTimer = null;
    }
}

async function pollStatus() {
    if (!roomId || !playerId) return;
    try {
        const data = await api('status', { room: roomId, player: playerId });
        if (!data.ok) {
            if (data.error === 'match_ended' && data.reason === 'inactivity') {
                showError('Game ended due to inactivity.');
            } else {
                showError('This room no longer exists. It may have been cleared.');
            }
            return;
        }
        renderState(data);
    } catch (e) {
        // Ignore transient network failures; the next poll will retry.
    }
}

function renderState(data) {
    if (data.round !== lastKnownRound) {
        lastKnownRound = data.round;
    }

    if (data.players_count < 2) {
        showOnly(waitingScreen);
        return;
    }

    updateScores(data.score);

    if (data.status === 'result') {
        showResult(data);
        return;
    }

    if (data.state === 'DEAD') {
        stopPolling();
        showOnly(errorScreen);
        errorText.textContent = data.end_reason === 'inactivity' ? 'Game ended due to inactivity.' : 'Match ended.';
        return;
    }

    showOnly(gameScreen);
    roleTag.textContent = 'You: ' + (ROLE_LABEL[data.my_role] || '');
    renderBoard(cellButtons, data.board, data.current_turn === data.my_role);

    const mySymbol = ROLE_SYMBOL[data.my_role] || '';
    if (data.current_turn === data.my_role) {
        turnPrompt.textContent = 'Your turn. Place ' + mySymbol + '.';
        turnNote.textContent = 'Choose any empty square.';
    } else {
        turnPrompt.textContent = 'Opponent turn. Waiting for ' + data.current_symbol + '...';
        turnNote.textContent = 'The board updates automatically.';
    }
}

function updateScores(score) {
    const labels = [
        [xScore, resultXScore, 'X wins: ' + score.x],
        [oScore, resultOScore, 'O wins: ' + score.o],
        [drawScore, resultDrawScore, 'Draws: ' + score.draws],
    ];
    labels.forEach(([gameEl, resultEl, text]) => {
        gameEl.textContent = text;
        resultEl.textContent = text;
    });
}

function renderBoard(cells, board, allowMove) {
    cells.forEach((cell, index) => {
        const value = board[index] || '';
        cell.textContent = value;
        cell.classList.toggle('x', value === 'X');
        cell.classList.toggle('o', value === 'O');
        cell.disabled = !allowMove || value !== '';
    });
}

function showResult(data) {
    showOnly(resultScreen);
    resultRoleTag.textContent = 'You: ' + (ROLE_LABEL[data.my_role] || '');
    renderBoard(resultCells, data.board, false);

    resultMsg.classList.remove('win', 'lose', 'draw');
    if (data.winner === 'draw') {
        resultMsg.textContent = "It's a draw!";
        resultMsg.classList.add('draw');
    } else if (data.winner === ROLE_SYMBOL[data.my_role]) {
        resultMsg.textContent = 'You win!';
        resultMsg.classList.add('win');
    } else {
        resultMsg.textContent = 'You lost this round.';
        resultMsg.classList.add('lose');
    }
}

cellButtons.forEach(btn => {
    btn.addEventListener('click', async () => {
        const index = btn.dataset.index;
        btn.disabled = true;
        try {
            const data = await api('move', { room: roomId, player: playerId, cell: index });
            if (!data.ok) {
                pollStatus();
                return;
            }
            pollStatus();
        } catch (e) {
            btn.disabled = false;
        }
    });
});

nextRoundBtn.addEventListener('click', async () => {
    try {
        await api('next_round', { room: roomId });
        showOnly(gameScreen);
        pollStatus();
    } catch (e) {
        // Will self-correct on the next poll.
    }
});

endMatchBtn.addEventListener('click', async () => {
    try {
        await api('end_game', { room: roomId });
        await pollStatus();
    } catch (e) {}
});

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

// Periodically ask the backend to sweep idle rooms (inactivity timeout).
setInterval(() => { api('cleanup').catch(() => {}); }, 60000);

init();


