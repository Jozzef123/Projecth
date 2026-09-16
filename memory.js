/**
 * memory.js
 * ---------
 * Front-end logic for the 2-player online Memory game.
 * Mirrors the XO room flow: create or join by URL, store player identity in
 * localStorage, then poll memory_backend.php for the current board state.
 *
 * Both players always render the SERVER state, so a flip made on one screen is
 * visible on the other one immediately (500ms polls):
 *   - a single flip is stored face-up on the server (visible to everyone),
 *   - a mismatched pair stays face-up for a shared reveal window
 *     (pending / pending_remaining) and then flips back for both players,
 *   - matched pairs stay face-up and the turn switches for both players.
 * Console logs: "P1 flipped card index X", "Syncing to server",
 * "P1/P2 received state", "Turn switched to P2".
 */

const BACKEND_URL = 'memory_backend.php';
// Fast polling (same idea as connect4's polling, but quicker) so a flip made on
// one screen shows up on the other almost instantly.
const POLL_INTERVAL_MS = 500;
// Fallback reveal time if the server does not send a pending window.
const MISMATCH_DELAY_MS = 800;

const SHAPE_ICON = { ball: '⚽', paper: '📄', dice: '🎲', fish: '🐟' };
const ROLE_LABEL = { p1: 'Player 1', p2: 'Player 2' };

const waitingScreen = document.getElementById('waitingScreen');
const waitingText = document.getElementById('waitingText');
const shareBox = document.getElementById('shareBox');
const shareLinkInput = document.getElementById('shareLink');
const copyBtn = document.getElementById('copyBtn');

const gameScreen = document.getElementById('gameScreen');
const roleTag = document.getElementById('roleTag');
const turnPrompt = document.getElementById('turnPrompt');
const p1ScoreEl = document.getElementById('p1Score');
const p2ScoreEl = document.getElementById('p2Score');
const boardEl = document.getElementById('memoryBoard');

const resultScreen = document.getElementById('resultScreen');
const resultMsg = document.getElementById('resultMsg');
const resultScore = document.getElementById('resultScore');
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
let currentTurn = null;
let resolvingMismatch = false;
let flipInFlight = false;
let flipBackTimer = null;
let pendingReveal = false;

// ------- Helpers -------

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
    return 'memory_player_' + rid;
}

// 'P1' / 'P2' label used by the debug logs.
function roleLabel(role) {
    return role === 'p2' ? 'P2' : 'P1';
}

function saveLocalPlayer(rid, pid) {
    try { localStorage.setItem(storageKey(rid), pid); } catch (e) {}
}

function getLocalPlayer(rid) {
    try { return localStorage.getItem(storageKey(rid)); } catch (e) { return null; }
}

// ------- Game initialization -------

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

        // Update the browser URL with the room id only (the share link must not
        // leak the creator's player id).
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
            } else if (data.error === 'match_ended' && data.reason === 'inactivity') {
                showError('Game ended due to inactivity.');
            } else {
                showError('An unexpected error occurred. Please try again.');
            }
            return;
        }

        roomId = data.room_id;
        playerId = data.player_id;
        myRole = data.role;
        saveLocalPlayer(roomId, playerId);

        if (data.state === 'DEAD') {
            errorText.textContent = data.end_reason === 'inactivity' ? 'Game ended due to inactivity.' : 'Match ended.';
            showOnly(errorScreen);
            return;
        }

        startPolling();
    } catch (e) {
        showError('Could not connect to the server. Make sure the server is running.');
    }
}

// ------- Polling -------

function startPolling() {
    stopPolling();
    pollTimer = setInterval(pollStatus, POLL_INTERVAL_MS);
    pollStatus(); // fire once immediately
}

function stopPolling() {
    if (flipBackTimer) {
        clearTimeout(flipBackTimer);
        flipBackTimer = null;
    }
    if (pollTimer) {
        clearInterval(pollTimer);
        pollTimer = null;
    }
}

// The server owns the mismatch reveal window: ask again right after it expires
// so the pair flips back at the same moment on BOTH screens.
function scheduleFlipBackPoll(remainingSeconds) {
    if (flipBackTimer) clearTimeout(flipBackTimer);
    const waitMs = Math.round((remainingSeconds || 0) * 1000) + 100;
    flipBackTimer = setTimeout(() => {
        flipBackTimer = null;
        pollStatus();
    }, waitMs);
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
        console.log(roleLabel(myRole) + ' received state', data);
        renderState(data);
    } catch (e) {
        // Ignore transient network failures; the next poll will retry.
    }
}

// ------- Rendering -------

function renderState(data) {
    if (data.round !== lastKnownRound) {
        console.log('Round ' + data.round + ' started');
        lastKnownRound = data.round;
    }
    if (resolvingMismatch) return; // don't overwrite the local flip-back animation

    if (data.players_count < 2) {
        showOnly(waitingScreen);
        return;
    }

    if (data.state === 'DEAD') {
        stopPolling();
        if (data.status === 'result' && data.winner) {
            showResult(data);
        } else {
            errorText.textContent = data.end_reason === 'inactivity' ? 'Game ended due to inactivity.' : 'Match ended.';
            showOnly(errorScreen);
        }
        return;
    }

    if (data.status === 'result') {
        showResult(data);
        return;
    }

    showOnly(gameScreen);
    roleTag.textContent = 'You: ' + (ROLE_LABEL[data.my_role] || '');
    renderBoard(data.cards, data.flip1, data.flip2, data.pending);
    updateScoreBar(data.score);

    // A mismatched pair is being revealed to both players right now: come back
    // exactly when the server closes the window.
    pendingReveal = !!(data.pending && data.pending.length === 2);
    if (pendingReveal) {
        scheduleFlipBackPoll(data.pending_remaining);
    }

    // Turn switching is synced from the server, so both players always agree.
    if (data.current_turn && currentTurn && data.current_turn !== currentTurn) {
        console.log('Turn switched to ' + roleLabel(data.current_turn));
    }
    currentTurn = data.current_turn;

    const myTurn = data.current_turn === data.my_role;
    if (myTurn) {
        turnPrompt.textContent = 'Your turn! Flip two matching cards.';
    } else {
        turnPrompt.textContent = 'Opponent turn — waiting for them...';
    }
}

function updateScoreBar(score) {
    p1ScoreEl.textContent = 'Player 1: ' + score.p1 + ' pair' + (score.p1 === 1 ? '' : 's');
    p2ScoreEl.textContent = 'Player 2: ' + score.p2 + ' pair' + (score.p2 === 1 ? '' : 's');
}

function renderBoard(cards, flip1, flip2, pending) {
    const flipSet = new Set();
    if (flip1 !== null && flip1 !== undefined) flipSet.add(flip1);
    if (flip2 !== null && flip2 !== undefined) flipSet.add(flip2);
    (pending || []).forEach(index => flipSet.add(index));

    // Build the 8 cards once, then only update their state: rebuilding the board
    // on every poll would kill the flip animation (and flicker at 500ms).
    if (boardEl.children.length !== cards.length) {
        boardEl.innerHTML = '';
        cards.forEach((card, index) => {
            const el = document.createElement('div');
            el.className = 'memory-card';
            el.innerHTML = '<div class="card-inner">'
                + '<div class="card-face card-back">?</div>'
                + '<div class="card-face card-front"></div>'
                + '</div>';
            el.addEventListener('click', () => handleCardClick(index, el));
            boardEl.appendChild(el);
        });
    }

    cards.forEach((card, index) => {
        const el = boardEl.children[index];
        if (!el) return;
        // flipped / matched / currently revealed pair -> face up for BOTH players
        const isFaceUp = card.matched || card.flipped || flipSet.has(index);
        el.classList.toggle('flipped', isFaceUp);
        el.classList.toggle('matched', !!card.matched);
        const front = el.querySelector('.card-front');
        if (front) {
            front.textContent = (isFaceUp && card.shape !== 'hidden') ? (SHAPE_ICON[card.shape] || '?') : '';
        }
    });
}

async function handleCardClick(index, el) {
    if (!roomId || !playerId || resolvingMismatch || flipInFlight || pendingReveal) return;
    if (myRole !== currentTurn) return;
    if (el.classList.contains('flipped') || el.classList.contains('matched')) return;

    console.log(roleLabel(myRole) + ' flipped card index ' + index);

    // Optimistic flip for instant feedback; the server response corrects it.
    flipInFlight = true;
    el.classList.add('flipped');
    console.log('Syncing to server', { action: 'flip', room: roomId, card: index });
    try {
        const data = await api('flip', { room: roomId, player: playerId, card: index });
        if (!data.ok) {
            el.classList.remove('flipped');
            flipInFlight = false;
            pollStatus();
            return;
        }

        // A mismatch is revealed to BOTH players: the server keeps the pair
        // face-up for the shared window (pending / pending_remaining) and flips
        // it back for both of them at the same moment.
        if (data.mismatch && data.mismatch.length === 2) {
            const cards = boardEl.querySelectorAll('.memory-card');
            data.mismatch.forEach((idx, i) => {
                const cardEl = cards[idx];
                if (cardEl) {
                    cardEl.classList.add('flipped');
                    const front = cardEl.querySelector('.card-front');
                    if (front && data.mismatch_shapes && data.mismatch_shapes[i]) {
                        front.textContent = SHAPE_ICON[data.mismatch_shapes[i]] || '?';
                    }
                }
            });
            resolvingMismatch = true;
            flipInFlight = false;
            const waitMs = data.pending_remaining
                ? Math.round(data.pending_remaining * 1000)
                : MISMATCH_DELAY_MS;
            console.log('No match - both players see cards ' + data.mismatch.join(' and ')
                + ' for ' + waitMs + 'ms');
            setTimeout(() => {
                resolvingMismatch = false;
                pollStatus();
            }, waitMs + 60);
        } else {
            flipInFlight = false;
            pollStatus();
        }
    } catch (e) {
        el.classList.remove('flipped');
        flipInFlight = false;
        pollStatus();
    }
}

function showResult(data) {
    showOnly(resultScreen);
    const s = data.score;
    resultScore.innerHTML = ''
        + '<span class="score-pill">Player 1: ' + s.p1 + ' pairs</span>'
        + '<span class="score-pill">Player 2: ' + s.p2 + ' pairs</span>';

    if (data.winner === 'draw') {
        resultMsg.textContent = "It's a tie! Both players matched the same number of pairs. 🤝";
    } else if (data.winner === data.my_role) {
        resultMsg.textContent = '🎉 You win! (' + ROLE_LABEL[data.winner] + ' — ' + s[data.winner] + ' pairs)';
    } else if (data.winner) {
        resultMsg.textContent = ROLE_LABEL[data.winner] + ' wins with ' + s[data.winner] + ' pairs. Better luck next time!';
    } else {
        resultMsg.textContent = 'Game over.';
    }
}

// ------- Action handlers -------

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

// ------- Kick things off -------
init();
