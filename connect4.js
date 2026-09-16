// Connect4 front-end to match backend conventions
const ROWS = 6, COLS = 7;
let board = Array.from({length: ROWS}, () => Array(COLS).fill(''));
let roomId = null, playerId = null, role = null;
let pollInterval = null;

// Expose the current room id for the shared End Game button (end_game_helper.js).
window.getCurrentRoomId = function () { return roomId; };
// Expose the current player id for leave detection (end_game_helper.js).
window.getCurrentPlayerId = function () { return playerId; };

document.getElementById('createBtn').addEventListener('click', createRoom);
document.getElementById('joinBtn').addEventListener('click', joinRoom);
document.getElementById('nextRound').addEventListener('click', nextRound);
document.getElementById('endGame').addEventListener('click', endGame);

function createRoom() {
  fetch('connect4_backend.php?action=create')
    .then(r => r.json())
    .then(j => {
      if (j.ok) {
        roomId = j.room_id; playerId = j.player_id; role = j.role;
        localStorage.setItem('player_id', playerId);
        document.getElementById('roomInput').value = roomId;
        document.getElementById('statusText').innerText = 'Room: ' + roomId + ' (waiting)';
        startPolling();
      }
    });
}

function joinRoom() {
  const rid = document.getElementById('roomInput').value.trim();
  const stored = localStorage.getItem('player_id') || '';
  const params = new URLSearchParams({action: 'join', room: rid, player: stored});
  fetch('connect4_backend.php?' + params.toString())
    .then(r => r.json())
    .then(j => {
      if (j.ok) {
        roomId = j.room_id; playerId = j.player_id; role = j.role;
        localStorage.setItem('player_id', playerId);
        document.getElementById('statusText').innerText = 'Joined: ' + roomId + ' (' + role + ')';
        startPolling();
      } else {
        alert(j.error || 'Unable to join');
      }
    });
}

function startPolling() {
  if (pollInterval) clearInterval(pollInterval);
  poll();
  pollInterval = setInterval(poll, 1500);
}

function poll() {
  if (!roomId) return;
  const params = new URLSearchParams({action: 'status', room: roomId, player: playerId});
  fetch('connect4_backend.php?' + params.toString())
    .then(r => r.json())
    .then(j => {
      if (!j.ok) {
        if (j.error === 'match_ended' && j.reason === 'inactivity') {
          stopPolling();
          document.getElementById('statusText').innerText = 'Game ended due to inactivity.';
        } else if (j.error === 'match_ended') {
          stopPolling();
          document.getElementById('statusText').innerText = 'Match ended.';
        }
        return;
      }
      const st = j.status; role = j.my_role;
      board = j.board;
      renderBoard();
      if (j.state === 'DEAD') {
        stopPolling();
        document.getElementById('statusText').innerText = (j.end_reason === 'inactivity') ? 'Game ended due to inactivity.' : 'Match ended.';
        return;
      }
      document.getElementById('statusText').innerText = 'Room: ' + roomId + ' (' + st + ')';
    });
}

function stopPolling() {
  if (pollInterval) {
    clearInterval(pollInterval);
    pollInterval = null;
  }
}

function renderBoard() {
  const container = document.getElementById('game-board');
  container.innerHTML = '';
  for (let r = 0; r < ROWS; r++) {
    for (let c = 0; c < COLS; c++) {
      const cell = document.createElement('div');
      cell.style.width = '48px'; cell.style.height = '48px'; cell.style.borderRadius='6px';
      cell.style.background = '#1e1e2f'; cell.style.display='flex'; cell.style.alignItems='center'; cell.style.justifyContent='center';
      cell.style.cursor = 'pointer';
      cell.dataset.col = c; cell.dataset.row = r;
      const val = board[r][c];
      if (val === 'R') cell.innerHTML = '<div style="width:34px;height:34px;border-radius:50%;background:#dc3545"></div>';
      else if (val === 'Y') cell.innerHTML = '<div style="width:34px;height:34px;border-radius:50%;background:#28a745"></div>';
      cell.addEventListener('pointerdown', () => { attemptMove(parseInt(cell.dataset.col)); });
      container.appendChild(cell);
    }
  }
}

function attemptMove(col) {
  if (!roomId || !playerId) { alert('Not in a room'); return; }
  const params = new URLSearchParams({action: 'move', room: roomId, player: playerId, column: col});
  fetch('connect4_backend.php', {method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body: params.toString()})
    .then(r => r.json())
    .then(j => {
      if (!j.ok) {
        if (j.error) alert(j.error);
        return;
      }
      poll();
    });
}

function nextRound() {
  if (!roomId) return;
  const params = new URLSearchParams({action: 'next_round', room: roomId});
  fetch('connect4_backend.php?' + params.toString()).then(()=>poll());
}

function endGame() {
  if (!roomId) return;
  const params = new URLSearchParams({action: 'end_game', room: roomId});
  fetch('connect4_backend.php?' + params.toString()).then(()=>poll());
}

// simple auto-attach to existing room id in input
(function(){
  const rid = document.getElementById('roomInput').value.trim();
  const stored = localStorage.getItem('player_id');
  if (rid && stored) {
    roomId = rid; playerId = stored; startPolling();
  }
})();

// Periodically ask the backend to sweep idle rooms (inactivity timeout).
setInterval(() => {
  fetch('connect4_backend.php?action=cleanup').catch(() => {});
}, 60000);

