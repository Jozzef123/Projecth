/**
 * rps.js
 * ------
 * Front-end logic for the 2-player online Rock Paper Scissors game.
 * Talks to rps_backend.php via fetch, and polls periodically to find
 * out the current room state (waiting for opponent / playing / result).
 */

const BACKEND_URL = 'rps_backend.php';
const POLL_INTERVAL_MS = 1500;

const EMOJI = { rock: '\u270A', paper: '\u270B', scissors: '\u270C\uFE0F' };
const ROLE_LABEL = { p1: 'Player 1', p2: 'Player 2' };

// ------- Page elements -------
const waitingScreen  = document.getElementById('waitingScreen');
const waitingText    = document.getElementById('waitingText');
const shareBox        = document.getElementById('shareBox');
const shareLinkInput  = document.getElementById('shareLink');
const copyBtn         = document.getElementById('copyBtn');

const gameScreen     = document.getElementById('gameScreen');
const roleTag        = document.getElementById('roleTag');
const movedNote       = document.getElementById('movedNote');
const pickPrompt      = document.getElementById('pickPrompt');
const choiceButtons   = document.querySelectorAll('.choice-btn');

const resultScreen   = document.getElementById('resultScreen');
const myEmojiEl        = document.getElementById('myEmoji');
const oppEmojiEl        = document.getElementById('oppEmoji');
const resultMsgEl     = document.getElementById('resultMsg');
const nextRoundBtn    = document.getElementById('nextRoundBtn');

const errorScreen    = document.getElementById('errorScreen');
const errorText       = document.getElementById('errorText');

let roomId = null;
let playerId = null;
let myRole = null;
let pollTimer = null;
let lastKnownRound = 1;
let hasMovedThisRound = false;

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
    Object.entries(params).forEach(([k, v]) => url.searchParams.set(k, v));
    const res = await fetch(url.toString());
    if (!res.ok) throw new Error('network_error');
    return res.json();
}

function storageKey(rid) {
    return 'rps_player_' + rid;
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
        // No room in the URL -> create a brand new room (player 1).
        await createRoom();
    } else {
        // Room id present in the URL -> join it (as player 1 or 2
        // depending on the current room state).
        roomId = roomParam;
        const existingPlayerId = getLocalPlayer(roomId);
        await joinRoom(roomId, existingPlayerId);
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

        // Update the browser URL without reloading the page.
        const newUrl = window.location.pathname + '?room=' + roomId;
        window.history.replaceState({}, '', newUrl);

        // Show the shareable link.
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

// ------- Polling -------

function startPolling() {
    stopPolling();
    pollTimer = setInterval(pollStatus, POLL_INTERVAL_MS);
    pollStatus(); // fire once immediately
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
            showError('This room no longer exists. It may have been cleared.');
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
        hasMovedThisRound = false;
        resetChoiceButtons();
    }

    if (data.players_count < 2) {
        // Still waiting for a second player to join.
        showOnly(waitingScreen);
        return;
    }

    if (data.both_moved && data.result) {
        showResult(data.result);
        return;
    }

    // Gameplay screen.
    showOnly(gameScreen);
    roleTag.textContent = 'You: ' + (ROLE_LABEL[data.my_role] || '');

    if (data.i_have_moved) {
        hasMovedThisRound = true;
        pickPrompt.classList.add('hidden');
        movedNote.classList.remove('hidden');
        disableChoiceButtons(true);
    } else {
        pickPrompt.classList.remove('hidden');
        movedNote.classList.add('hidden');
        disableChoiceButtons(false);
    }
}          


function showResult(result) {
    showOnly(resultScreen);

    const myMove  = myRole === 'p1' ? result.p1_move : result.p2_move;
    const oppMove = myRole === 'p1' ? result.p2_move : result.p1_move;

    myEmojiEl.textContent = EMOJI[myMove] || '?';
    oppEmojiEl.textContent = EMOJI[oppMove] || '?';

    resultMsgEl.classList.remove('win', 'lose', 'draw');
    if (result.winner === 'draw') {
        resultMsgEl.textContent = "It's a draw! \uD83E\uDD1D";
        resultMsgEl.classList.add('draw');
    } else if (result.winner === myRole) {
        resultMsgEl.textContent = 'You win! \uD83C\uDF89';
        resultMsgEl.classList.add('win');
    } else {
        resultMsgEl.textContent = 'You lost this round \uD83D\uDE05';
        resultMsgEl.classList.add('lose');
    }
}

// ------- Choice selection -------

function resetChoiceButtons() {
    choiceButtons.forEach(btn => btn.classList.remove('selected'));
    disableChoiceButtons(false);
    pickPrompt.classList.remove('hidden');
    movedNote.classList.add('hidden');
}

function disableChoiceButtons(disabled) {
    choiceButtons.forEach(btn => btn.disabled = disabled);
}

choiceButtons.forEach(btn => {
    btn.addEventListener('click', async () => {
        if (hasMovedThisRound) return;
        const choice = btn.dataset.choice;
        disableChoiceButtons(true);
        btn.classList.add('selected');
        try {
            await api('move', { room: roomId, player: playerId, choice });
            hasMovedThisRound = true;
            pickPrompt.classList.add('hidden');
            movedNote.classList.remove('hidden');
            pollStatus();
        } catch (e) {
            disableChoiceButtons(false);
            btn.classList.remove('selected');
        }
    });
});

nextRoundBtn.addEventListener('click', async () => {
    try {
        await api('next_round', { room: roomId });
        hasMovedThisRound = false;
        resetChoiceButtons();
        showOnly(gameScreen);
        pollStatus();
    } catch (e) {
        // Will self-correct on the next poll.
    }
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

// ------- Kick things off -------
init();
