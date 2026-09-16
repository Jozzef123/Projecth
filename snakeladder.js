/**
 * snakeladder.js
 * --------------
 * Front-end logic for Snake and Ladder.
 * The PHP backend owns dice rolls, movement rules, snake/ladder jumps, and
 * wins. This file renders the board and animates the server-approved path.
 */

const BACKEND_URL = 'snakeladder_backend.php';
const POLL_INTERVAL_MS = 1500;
const STEP_MS = 240;
const JUMP_MS = 560;
const ROLE_LABEL = { p1: 'Player 1', p2: 'Player 2' };

const waitingScreen = document.getElementById('waitingScreen');
const waitingText = document.getElementById('waitingText');
const shareBox = document.getElementById('shareBox');
const shareLinkInput = document.getElementById('shareLink');
const copyBtn = document.getElementById('copyBtn');

const gameScreen = document.getElementById('gameScreen');
const roleTag = document.getElementById('roleTag');
const turnNote = document.getElementById('turnNote');
const toast = document.getElementById('toast');
const diceValue = document.getElementById('diceValue');
const rollBtn = document.getElementById('rollBtn');
const boardEl = document.getElementById('board');
const featureLayer = document.getElementById('featureLayer');
const tokenP1 = document.getElementById('tokenP1');
const tokenP2 = document.getElementById('tokenP2');
const p1Card = document.getElementById('p1Card');
const p2Card = document.getElementById('p2Card');
const p1Badge = document.getElementById('p1Badge');
const p2Badge = document.getElementById('p2Badge');
const p1Score = document.getElementById('p1Score');
const p2Score = document.getElementById('p2Score');

const resultScreen = document.getElementById('resultScreen');
const resultMsg = document.getElementById('resultMsg');
const resultP1Card = document.getElementById('resultP1Card');
const resultP2Card = document.getElementById('resultP2Card');
const resultP1Badge = document.getElementById('resultP1Badge');
const resultP2Badge = document.getElementById('resultP2Badge');
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
let boardMaps = { ladders: {}, snakes: {} };
let boardDrawn = false;
let isAnimating = false;
let queuedState = null;
let latestState = null;
let displayedPositions = { p1: 0, p2: 0 };

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
    return 'snakeladder_player_' + rid;
}

function saveLocalPlayer(rid, pid) {
    try { localStorage.setItem(storageKey(rid), pid); } catch (e) {}
}

function getLocalPlayer(rid) {
    try { return localStorage.getItem(storageKey(rid)); } catch (e) { return null; }
}

async function init() {
    renderDice('');
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
        if (isAnimating) {
            queuedState = data;
            return;
        }
        renderState(data);
    } catch (e) {
        // Ignore transient network failures; polling will retry.
    }
}

function renderState(data) {
    latestState = data;

    if (data.players_count < 2) {
        showOnly(waitingScreen);
        return;
    }

    boardMaps = data.maps;
    if (!boardDrawn) {
        renderBoardSquares();
        drawFeatureLayer();
        boardDrawn = true;
    }

    displayedPositions = { ...data.positions };
    updateTokens(displayedPositions);
    updatePlayers(data, data.current_turn);
    renderDice(data.last_roll);
    toast.textContent = '';

    if (data.status === 'result') {
        showResult(data);
        return;
    }

    if (data.state === 'DEAD') {
        stopPolling();
        showOnly(errorScreen);
        errorText.textContent = 'Match ended.';
        return;
    }

    showOnly(gameScreen);
    roleTag.textContent = 'You: ' + (ROLE_LABEL[data.my_role] || '');
    rollBtn.disabled = data.current_turn !== data.my_role;
    turnNote.textContent = data.current_turn === data.my_role
        ? 'Your turn. Roll the dice.'
        : 'Opponent turn. Waiting for the next roll...';
}

function updatePlayers(data, activeRole) {
    const p1Active = activeRole === 'p1' && data.status === 'playing';
    const p2Active = activeRole === 'p2' && data.status === 'playing';
    p1Card.classList.toggle('active', p1Active);
    p2Card.classList.toggle('active', p2Active);
    resultP1Card.classList.toggle('active', data.winner === 'p1');
    resultP2Card.classList.toggle('active', data.winner === 'p2');

    p1Badge.textContent = p1Active ? (myRole === 'p1' ? 'Your turn' : 'Opponent turn') : 'Waiting';
    p2Badge.textContent = p2Active ? (myRole === 'p2' ? 'Your turn' : 'Opponent turn') : 'Waiting';
    resultP1Badge.textContent = data.winner === 'p1' ? 'Winner' : 'Finished';
    resultP2Badge.textContent = data.winner === 'p2' ? 'Winner' : 'Finished';

    p1Score.textContent = 'Wins: ' + data.score.p1 + ' | Position: ' + data.positions.p1;
    p2Score.textContent = 'Wins: ' + data.score.p2 + ' | Position: ' + data.positions.p2;
    resultP1Score.textContent = 'Wins: ' + data.score.p1 + ' | Position: ' + data.positions.p1;
    resultP2Score.textContent = 'Wins: ' + data.score.p2 + ' | Position: ' + data.positions.p2;
}

function renderBoardSquares() {
    boardEl.innerHTML = '';
    for (let row = 9; row >= 0; row--) {
        const leftToRight = row % 2 === 0;
        for (let col = 0; col < 10; col++) {
            const offset = leftToRight ? col : 9 - col;
            const squareNumber = row * 10 + offset + 1;
            const square = document.createElement('div');
            square.className = 'square' + ((row + col) % 2 ? ' alt' : '');
            square.textContent = squareNumber;
            if (boardMaps.ladders[squareNumber] || boardMaps.snakes[squareNumber]) {
                square.classList.add('start-feature');
            }
            boardEl.appendChild(square);
        }
    }
}

function drawFeatureLayer() {
    featureLayer.innerHTML = '';
    Object.entries(boardMaps.ladders).forEach(([start, end]) => drawLadder(Number(start), Number(end)));
    Object.entries(boardMaps.snakes).forEach(([start, end]) => drawSnake(Number(start), Number(end)));
}

function drawLadder(start, end) {
    const a = squareCenter(start);
    const b = squareCenter(end);
    const dx = b.x - a.x;
    const dy = b.y - a.y;
    const length = Math.hypot(dx, dy) || 1;
    const nx = (-dy / length) * 1.2;
    const ny = (dx / length) * 1.2;

    addSvgLine(a.x + nx, a.y + ny, b.x + nx, b.y + ny, '#b77a38', 0.9);
    addSvgLine(a.x - nx, a.y - ny, b.x - nx, b.y - ny, '#b77a38', 0.9);

    for (let i = 1; i < 7; i++) {
        const t = i / 7;
        const x = a.x + dx * t;
        const y = a.y + dy * t;
        addSvgLine(x - nx * 1.35, y - ny * 1.35, x + nx * 1.35, y + ny * 1.35, '#e7c08b', 0.65);
    }
}

function drawSnake(start, end) {
    const a = squareCenter(start);
    const b = squareCenter(end);
    const midX = (a.x + b.x) / 2;
    const midY = (a.y + b.y) / 2;
    const bend = start % 2 === 0 ? 10 : -10;
    const path = document.createElementNS('http://www.w3.org/2000/svg', 'path');
    path.setAttribute('d', 'M ' + a.x + ' ' + a.y + ' Q ' + (midX + bend) + ' ' + (midY - bend / 2) + ' ' + b.x + ' ' + b.y);
    path.setAttribute('fill', 'none');
    path.setAttribute('stroke', '#6fb04f');
    path.setAttribute('stroke-width', '1.25');
    path.setAttribute('stroke-linecap', 'round');
    featureLayer.appendChild(path);

    const head = document.createElementNS('http://www.w3.org/2000/svg', 'circle');
    head.setAttribute('cx', a.x);
    head.setAttribute('cy', a.y);
    head.setAttribute('r', '1.7');
    head.setAttribute('fill', '#9be06e');
    head.setAttribute('stroke', '#173b18');
    head.setAttribute('stroke-width', '0.35');
    featureLayer.appendChild(head);
}

function addSvgLine(x1, y1, x2, y2, color, width) {
    const line = document.createElementNS('http://www.w3.org/2000/svg', 'line');
    line.setAttribute('x1', x1);
    line.setAttribute('y1', y1);
    line.setAttribute('x2', x2);
    line.setAttribute('y2', y2);
    line.setAttribute('stroke', color);
    line.setAttribute('stroke-width', width);
    line.setAttribute('stroke-linecap', 'round');
    featureLayer.appendChild(line);
}

function squareCenter(square) {
    if (square <= 0) return { x: 5, y: 104 };
    const rowFromBottom = Math.floor((square - 1) / 10);
    const indexInRow = (square - 1) % 10;
    const col = rowFromBottom % 2 === 0 ? indexInRow : 9 - indexInRow;
    const rowFromTop = 9 - rowFromBottom;
    return { x: (col + 0.5) * 10, y: (rowFromTop + 0.5) * 10 };
}

function updateTokens(positions) {
    placeToken(tokenP1, positions.p1, -1.5);
    placeToken(tokenP2, positions.p2, 1.5);
}

function placeToken(token, position, offset) {
    const point = squareCenter(position);
    token.style.left = (point.x + offset) + '%';
    token.style.top = point.y + '%';
}

function renderDice(value) {
    diceValue.innerHTML = '';
    const active = dicePips(Number(value));
    for (let i = 1; i <= 9; i++) {
        const pip = document.createElement('span');
        pip.className = 'pip' + (active.includes(i) ? ' on' : '');
        diceValue.appendChild(pip);
    }
}

function dicePips(value) {
    const map = {
        1: [5],
        2: [1, 9],
        3: [1, 5, 9],
        4: [1, 3, 7, 9],
        5: [1, 3, 5, 7, 9],
        6: [1, 3, 4, 6, 7, 9],
    };
    return map[value] || [];
}

function wait(ms) {
    return new Promise(resolve => setTimeout(resolve, ms));
}

function showResult(data) {
    showOnly(resultScreen);
    resultMsg.classList.remove('win', 'lose');
    resultMsg.textContent = data.winner === data.my_role ? 'You reached 100 first!' : 'Your opponent reached 100 first.';
    resultMsg.classList.add(data.winner === data.my_role ? 'win' : 'lose');
    updatePlayers(data, '');
}

async function animateRoll(data) {
    const info = data.roll_info;
    if (!info) return;

    isAnimating = true;
    queuedState = null;
    rollBtn.disabled = true;
    showOnly(gameScreen);
    diceValue.classList.add('shake');
    toast.textContent = 'Rolling...';
    renderDice('');
    await wait(460);
    diceValue.classList.remove('shake');
    renderDice(info.roll);

    displayedPositions[info.player] = info.before_position;
    updateTokens(displayedPositions);
    await wait(80);

    if (info.overshot) {
        toast.textContent = 'Too high. You need the exact roll to reach 100.';
        await wait(650);
    } else {
        for (let pos = info.before_position + 1; pos <= info.raw_landing; pos++) {
            displayedPositions[info.player] = pos;
            updateTokens(displayedPositions);
            await wait(STEP_MS);
        }

        if (info.jump_type) {
            toast.textContent = info.jump_type === 'ladder' ? 'Climbing the ladder!' : 'Oh no, sliding down!';
            const token = info.player === 'p1' ? tokenP1 : tokenP2;
            token.classList.add('jump');
            displayedPositions[info.player] = info.final_position;
            updateTokens(displayedPositions);
            await wait(JUMP_MS);
            token.classList.remove('jump');
        }
    }

    toast.textContent = '';
    isAnimating = false;
    const state = queuedState || data;
    queuedState = null;
    renderState(state);
}

rollBtn.addEventListener('click', async () => {
    if (!latestState || latestState.current_turn !== myRole || isAnimating) return;
    rollBtn.disabled = true;
    try {
        const data = await api('roll', { room: roomId, player: playerId });
        if (data.ok) {
            boardMaps = data.maps;
            await animateRoll(data);
        } else {
            pollStatus();
        }
    } catch (e) {
        pollStatus();
    }
});

nextRoundBtn.addEventListener('click', async () => {
    try {
        await api('next_round', { room: roomId });
        displayedPositions = { p1: 0, p2: 0 };
        showOnly(gameScreen);
        pollStatus();
    } catch (e) {
        // The next poll will recover the room state.
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
