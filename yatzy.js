let roomId = null;
let playerId = null;
let myRole = null;
let currentRoomData = null;

const diceIcons = ['', '⚀', '⚁', '⚂', '⚃', '⚄', '⚅'];

async function initGame() {
    const urlParams = new URLSearchParams(window.location.search);
    roomId = urlParams.get('room');
    playerId = urlParams.get('player');

    if (!roomId) {
        let res = await fetch('yatzy_backend.php?action=create');
        let data = await res.json();
        roomId = data.room_id;
        playerId = data.player_id;
        myRole = data.role;
        window.history.pushState({}, '', `?room=${roomId}&player=${playerId}`);
    } else {
        let res = await fetch(`yatzy_backend.php?action=join&room=${roomId}&player=${playerId}`);
        let data = await res.json();
        myRole = data.role;
    }

    document.getElementById('roomDisplay').innerText = `room_id: ${roomId}`;
    document.getElementById('roleDisplay').innerText = `role: ${myRole === 'p1' ? 'P1 (أحمر)' : 'P2 (أزرق)'}`;

    setInterval(pollStatus, 1000);
}

async function pollStatus() {
    if (!roomId) return;
    let res = await fetch(`yatzy_backend.php?action=status&room=${roomId}`);
    let data = await res.json();
    if (data.ok) {
        currentRoomData = data.room;
        renderUI();
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
    let res = await fetch(`yatzy_backend.php?action=roll&room=${roomId}&player=${playerId}`);
    let data = await res.json();
    if (data.ok) pollStatus();
}

async function toggleHold(index) {
    if (!currentRoomData || currentRoomData.current_turn !== myRole) return;
    let res = await fetch(`yatzy_backend.php?action=toggle_hold&room=${roomId}&player=${playerId}&index=${index}`);
    let data = await res.json();
    if (data.ok) pollStatus();
}

async function selectCat(category) {
    if (!currentRoomData || currentRoomData.current_turn !== myRole) return;
    if (currentRoomData.rolls_left >= 3) {
        alert('You must roll at least once before selecting a category.');
        return;
    }
    let res = await fetch(`yatzy_backend.php?action=score&room=${roomId}&player=${playerId}&category=${category}`);
    let data = await res.json();
    if (data.ok) pollStatus();
}

window.onload = initGame;