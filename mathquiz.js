/**
 * mathquiz.js
 * -----------
 * Front-end logic for the 2-player Math Quiz duel.
 * The backend owns question generation and answer validation; the browser
 * only shows the shared question, submits one answer, and polls for results.
 */

const BACKEND_URL = 'mathquiz_backend.php';
const POLL_INTERVAL_MS = 1500;
const QUESTION_SECONDS = 15;
const ROLE_LABEL = { p1: 'Player 1', p2: 'Player 2' };

const waitingScreen = document.getElementById('waitingScreen');
const waitingText = document.getElementById('waitingText');
const shareBox = document.getElementById('shareBox');
const shareLinkInput = document.getElementById('shareLink');
const copyBtn = document.getElementById('copyBtn');

const gameScreen = document.getElementById('gameScreen');
const roleTag = document.getElementById('roleTag');
const timerEl = document.getElementById('timer');
const questionText = document.getElementById('questionText');
const choicesEl = document.getElementById('choices');
const answerNote = document.getElementById('answerNote');
const p1Score = document.getElementById('p1Score');
const p2Score = document.getElementById('p2Score');

const resultScreen = document.getElementById('resultScreen');
const resultRoleTag = document.getElementById('resultRoleTag');
const resultQuestion = document.getElementById('resultQuestion');
const resultMsg = document.getElementById('resultMsg');
const resultNote = document.getElementById('resultNote');
const resultP1Score = document.getElementById('resultP1Score');
const resultP2Score = document.getElementById('resultP2Score');
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
let countdownTimer = null;
let lastKnownRound = 1;
let lastSelectedAnswer = null;

function showOnly(el) {
    [waitingScreen, gameScreen, resultScreen, errorScreen].forEach(s => s.classList.add('hidden'));
    el.classList.remove('hidden');
}

function showError(message) {
    stopPolling();
    stopCountdown();
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
    return 'mathquiz_player_' + rid;
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

        window.history.replaceState({}, '', window.location.pathname + '?room=' + roomId);
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
        const data = await api('join', { room: rid, player: existingPlayerId || '' });
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
            showError('This room no longer exists. It may have been cleared.');
            return;
        }
        renderState(data);
    } catch (e) {
        // Ignore short network misses; the next poll will retry.
    }
}

function renderState(data) {
    if (data.round !== lastKnownRound) {
        lastKnownRound = data.round;
        lastSelectedAnswer = null;
    }

    if (data.players_count < 2) {
        stopCountdown();
        showOnly(waitingScreen);
        return;
    }

    updateScores(data.score);

    if (data.status === 'result') {
        stopCountdown();
        showResult(data);
        return;
    }

    if (data.state === 'DEAD') {
        stopPolling();
        stopCountdown();
        showOnly(errorScreen);
        errorText.textContent = 'Match ended.';
        return;
    }

    showOnly(gameScreen);
    roleTag.textContent = 'You: ' + (ROLE_LABEL[data.my_role] || '');
    questionText.textContent = data.question.display;
    renderChoices(data);
    startCountdown(data.question_started_at);
}

function updateScores(score) {
    p1Score.textContent = 'Player 1: ' + score.p1;
    p2Score.textContent = 'Player 2: ' + score.p2;
    resultP1Score.textContent = 'Player 1: ' + score.p1;
    resultP2Score.textContent = 'Player 2: ' + score.p2;
}

function renderChoices(data) {
    choicesEl.innerHTML = '';
    const myAnswer = data.answers[data.my_role] || null;
    const canAnswer = !myAnswer && data.status === 'playing';

    data.question.options.forEach(option => {
        const btn = document.createElement('button');
        btn.className = 'choice-btn';
        btn.textContent = option;
        btn.disabled = !canAnswer;
        if (lastSelectedAnswer !== null && Number(option) === Number(lastSelectedAnswer)) {
            btn.classList.add('selected');
        }
        btn.addEventListener('click', () => submitAnswer(option, btn));
        choicesEl.appendChild(btn);
    });

    if (myAnswer) {
        answerNote.textContent = 'Answer submitted. Waiting for the round result...';
    } else {
        answerNote.textContent = 'Choose one answer. You only get one try.';
    }
}

async function submitAnswer(answer, button) {
    lastSelectedAnswer = answer;
    Array.from(choicesEl.children).forEach(btn => btn.disabled = true);
    button.classList.add('selected');
    answerNote.textContent = 'Submitting answer...';
    try {
        const data = await api('answer', { room: roomId, player: playerId, answer });
        if (!data.ok) {
            answerNote.textContent = data.error === 'already_answered'
                ? 'You already answered this question.'
                : 'That answer was not accepted.';
        }
        pollStatus();
    } catch (e) {
        answerNote.textContent = 'Could not submit. The page will retry on the next poll.';
    }
}

function startCountdown(startedAt) {
    stopCountdown();
    const update = () => {
        const elapsed = Math.floor(Date.now() / 1000) - Number(startedAt || 0);
        const remaining = Math.max(0, QUESTION_SECONDS - elapsed);
        timerEl.textContent = remaining;
        if (remaining <= 0) {
            Array.from(choicesEl.children).forEach(btn => btn.disabled = true);
            answerNote.textContent = 'Time is up. Waiting for the server result...';
        }
    };
    update();
    countdownTimer = setInterval(update, 250);
}

function stopCountdown() {
    if (countdownTimer) {
        clearInterval(countdownTimer);
        countdownTimer = null;
    }
}

function showResult(data) {
    showOnly(resultScreen);
    resultRoleTag.textContent = 'You: ' + (ROLE_LABEL[data.my_role] || '');
    resultQuestion.textContent = data.question.display;
    resultNote.textContent = 'Correct answer: ' + data.question.correct_answer;

    resultMsg.classList.remove('win', 'lose', 'draw');
    if (data.winner === 'draw') {
        resultMsg.textContent = 'No score this round.';
        resultMsg.classList.add('draw');
    } else if (data.winner === data.my_role) {
        resultMsg.textContent = 'You answered correctly first!';
        resultMsg.classList.add('win');
    } else {
        resultMsg.textContent = 'Your opponent won this round.';
        resultMsg.classList.add('lose');
    }
}

nextRoundBtn.addEventListener('click', async () => {
    try {
        await api('next_round', { room: roomId });
        lastSelectedAnswer = null;
        showOnly(gameScreen);
        pollStatus();
    } catch (e) {
        // The next poll will recover the current room state.
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

init();
