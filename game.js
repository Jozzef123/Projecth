/**
 * Connect 4 - Web Engine & Server State Writer
 * Matrix Key: 0 = Empty, 1 = RED Player, 2 = BLUE Player
 * Posts state updates to save_state.php to maintain 'connect4_state.txt'
 */

const ROWS = 6;
const COLS = 7;

// Game State Variables
let board = [];
let currentPlayer = 1; // 1 = RED, 2 = BLUE
let isAnimating = false;

/**
 * Initializes or resets the game state
 */
function initGame() {
  // Create clean 6x7 matrix filled with 0s
  board = Array(ROWS).fill(null).map(() => Array(COLS).fill(0));
  currentPlayer = 1;
  isAnimating = false;
  
  document.getElementById('status').innerText = "Turn: RED Player";
  createUIBoard();
  
  // Write initial state to connect4_state.txt
  sendStateToServer(board, 0);
}

/**
 * Generates interactive grid DOM elements
 */
function createUIBoard() {
  const uiBoard = document.getElementById('game-board');
  uiBoard.innerHTML = '';

  for (let r = 0; r < ROWS; r++) {
    for (let c = 0; c < COLS; c++) {
      const cell = document.createElement('div');
      cell.className = 'cell';
      cell.id = `cell-${r}-${c}`;
      
      // Handle touch taps and mouse clicks
      cell.addEventListener('pointerdown', (e) => {
        e.preventDefault();
        if (!isAnimating) handleMove(c);
      });

      uiBoard.appendChild(cell);
    }
  }
}

/**
 * Handles column selection, dropping animation, win evaluation, and turn switching
 */
function handleMove(col) {
  // Find lowest available row in column
  let targetRow = -1;
  for (let r = ROWS - 1; r >= 0; r--) {
    if (board[r][col] === 0) {
      targetRow = r;
      break;
    }
  }

  // Column full
  if (targetRow === -1) return;

  // Record move in matrix
  board[targetRow][col] = currentPlayer;
  isAnimating = true;

  // Animate falling coin
  animateCoinDrop(targetRow, col, currentPlayer, () => {
    // Update landed token visual style
    const targetCell = document.getElementById(`cell-${targetRow}-${col}`);
    targetCell.classList.add(currentPlayer === 1 ? 'red' : 'blue');

    // 1. Check Win Condition
    if (checkWin(targetRow, col, currentPlayer)) {
      const winnerName = currentPlayer === 1 ? 'RED' : 'BLUE';
      document.getElementById('status').innerText = `🎉 ${winnerName} Player Wins!`;
      isAnimating = true; // Lock board
      sendStateToServer(board, currentPlayer /* 1 = RED, 2 = BLUE */);
      return;
    }

    // 2. Check Tie Condition (Full Board)
    if (checkTie()) {
      document.getElementById('status').innerText = "🤝 Game Over! It's a Tie!";
      isAnimating = true; // Lock board
      sendStateToServer(board, 3 /* 3 = Tie */);
      return;
    }

    // 3. Rotate Turn
    currentPlayer = currentPlayer === 1 ? 2 : 1;
    document.getElementById('status').innerText = `Turn: ${currentPlayer === 1 ? 'RED' : 'BLUE'} Player`;

    // Send ongoing state payload to server
    sendStateToServer(board, 0 /* 0 = Ongoing */);
    isAnimating = false;
  });
}

/**
 * Animates a coin dropping smoothly from top to target landed slot
 */
function animateCoinDrop(targetRow, col, player, onComplete) {
  const uiBoard = document.getElementById('game-board');
  const sampleCell = document.querySelector('.cell');
  const cellRect = sampleCell.getBoundingClientRect();

  const cellSize = cellRect.width;
  const gap = 6;      // Grid gap in CSS
  const padding = 12;  // Board padding in CSS

  // Calculate pixel drop offsets
  const startY = -cellSize - padding; 
  const endY = padding + targetRow * (cellSize + gap);
  const startX = padding + col * (cellSize + gap);

  const coin = document.createElement('div');
  coin.className = `falling-coin ${player === 1 ? 'red' : 'blue'}`;
  
  // Assign dynamic keyframe CSS variables
  coin.style.setProperty('--cell-size', `${cellSize}px`);
  coin.style.setProperty('--start-y', `${startY}px`);
  coin.style.setProperty('--end-y', `${endY}px`);
  coin.style.left = `${startX}px`;

  uiBoard.appendChild(coin);

  coin.addEventListener('animationend', () => {
    coin.remove();
    onComplete();
  });
}

/**
 * Evaluates 4 connected tokens across horizontal, vertical, and diagonal vectors
 */
function checkWin(row, col, player) {
  if (countDirection(row, col, 0, 1, player) + countDirection(row, col, 0, -1, player) - 1 >= 4) return true;
  if (countDirection(row, col, 1, 0, player) + countDirection(row, col, -1, 0, player) - 1 >= 4) return true;
  if (countDirection(row, col, 1, 1, player) + countDirection(row, col, -1, -1, player) - 1 >= 4) return true;
  if (countDirection(row, col, -1, 1, player) + countDirection(row, col, 1, -1, player) - 1 >= 4) return true;
  return false;
}

/**
 * Traverses direction vector (deltaRow, deltaCol) to count matching tokens
 */
function countDirection(row, col, deltaRow, deltaCol, player) {
  let count = 0;
  let r = row;
  let c = col;

  while (r >= 0 && r < ROWS && c >= 0 && c < COLS && board[r][c] === player) {
    count++;
    r += deltaRow;
    c += deltaCol;
  }
  return count;
}

/**
 * Checks if top row has no empty spaces left
 */
function checkTie() {
  return board[0].every(cell => cell !== 0);
}

/**
 * Sends state payload over HTTP POST to save_state.php
 * gameStatus: 0 = Ongoing, 1 = RED Win, 2 = BLUE Win, 3 = Tie
 */
function sendStateToServer(matrix, gameStatus = 0) {
  const payload = {
    status: gameStatus,
    nextTurn: currentPlayer === 1 ? 'RED' : 'BLUE',
    board: matrix
  };

  fetch('save_state.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(payload)
  })
  .then(response => response.text())
  .then(data => console.log('Server Output:', data))
  .catch(err => console.error('Error writing to connect4_state.txt:', err));
}

/**
 * Resets board game state
 */
function resetGame() {
  initGame();
}

// Start game on page load
window.onload = initGame;