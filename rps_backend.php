<?php
/**
 * rps_backend.php
 * -----------------
 * Simple backend for a 2-player online Rock Paper Scissors game.
 * Uses plain JSON files instead of a database, and writes every
 * game-state change to status.txt so the ARM M3 board can read it.
 *
 * Actions (via ?action=):
 *   create        -> Creates a new room, returns room_id + player_id (player 1)
 *   join          -> Joins an existing room as player 2 (or returns current
 *                    state if this player already belongs to the room)
 *   status        -> Returns the current room state (used for polling)
 *   move          -> Records a player's choice (rock/paper/scissors) for the
 *                    current round
 *   next_round    -> Starts a new round in the same room
 *
 * All responses are JSON.
 */

header('Content-Type: application/json; charset=utf-8');

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST');

$ROOMS_DIR   = __DIR__ . '/rooms';
$STATUS_FILE = __DIR__ . '/status.txt';

if (!is_dir($ROOMS_DIR)) {
    mkdir($ROOMS_DIR, 0777, true);
}

// -------------------- Helpers --------------------

function room_path($roomId) {
    global $ROOMS_DIR;
    $safe = preg_replace('/[^A-Za-z0-9]/', '', $roomId);
    return $ROOMS_DIR . '/' . $safe . '.json';
}

function generate_room_id() {
    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $id = '';
    for ($i = 0; $i < 6; $i++) {
        $id .= $chars[random_int(0, strlen($chars) - 1)];
    }
    return $id;
}

function generate_player_id() {
    return bin2hex(random_bytes(8));
}

function load_room($roomId) {
    $path = room_path($roomId);
    if (!file_exists($path)) return null;
    $fp = fopen($path, 'r');
    if (!$fp) return null;
    flock($fp, LOCK_SH);
    $data = json_decode(stream_get_contents($fp), true);
    flock($fp, LOCK_UN);
    fclose($fp);
    return $data;
}

function save_room($roomId, $data) {
    $path = room_path($roomId);
    $fp = fopen($path, 'c+');
    if (!$fp) return false;
    flock($fp, LOCK_EX);
    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
    return true;
}

function write_status_file($room) {
    global $STATUS_FILE;
    
    $currentRound = $room['round'] ?? 1;
    $status = $room['status'] ?? 'waiting';
    
    $lines = [];
    $lines[] = "status={$status};current_round={$currentRound}";
    
    for ($r = 1; $r <= $currentRound; $r++) {
        $p1 = $room['moves'][$r]['p1'] ?? '';
        $p2 = $room['moves'][$r]['p2'] ?? '';
        $winner = ($p1 !== '' && $p2 !== '') ? decide_winner($p1, $p2) : '';
        
        $lines[] = "round={$r};p1={$p1};p2={$p2};winner={$winner}";
    }
    
    file_put_contents($STATUS_FILE, implode(PHP_EOL, $lines), LOCK_EX);
}

function respond($arr) {
    echo json_encode($arr, JSON_UNESCAPED_UNICODE);
    exit;
}

function decide_winner($moveA, $moveB) {
    if ($moveA === $moveB) return 'draw';
    $beats = [
        'rock'     => 'scissors',
        'scissors' => 'paper',
        'paper'    => 'rock',
    ];
    return ($beats[$moveA] === $moveB) ? 'p1' : 'p2';
}

// -------------------- Router --------------------

$action = $_REQUEST['action'] ?? '';

switch ($action) {

    // ============ CREATE ============
    case 'create': {
        $roomId = generate_room_id();
        while (file_exists(room_path($roomId))) {
            $roomId = generate_room_id();
        }
        $playerId = generate_player_id();

        $room = [
            'room_id'    => $roomId,
            'status'     => 'waiting',
            'round'      => 1,
            'players'    => [
                $playerId => ['role' => 'p1'],
            ],
            'moves'      => [],
            'created_at' => time(),
        ];
        save_room($roomId, $room);
        write_status_file($room);

        respond([
            'ok'        => true,
            'room_id'   => $roomId,
            'player_id' => $playerId,
            'role'      => 'p1',
        ]);
    }

    // ============ JOIN ============
    case 'join': {
        $roomId = $_REQUEST['room'] ?? '';
        $room = load_room($roomId);
        if (!$room) {
            respond(['ok' => false, 'error' => 'room_not_found']);
        }

        $playerId = $_REQUEST['player'] ?? '';

        if ($playerId && isset($room['players'][$playerId])) {
            respond([
                'ok'        => true,
                'room_id'   => $roomId,
                'player_id' => $playerId,
                'role'      => $room['players'][$playerId]['role'],
                'status'    => $room['status'],
            ]);
        }

        if (count($room['players']) >= 2) {
            respond(['ok' => false, 'error' => 'room_full']);
        }

        $playerId = generate_player_id();
        $room['players'][$playerId] = ['role' => 'p2'];
        $room['status'] = 'playing';
        save_room($roomId, $room);
        write_status_file($room);

        respond([
            'ok'        => true,
            'room_id'   => $roomId,
            'player_id' => $playerId,
            'role'      => 'p2',
            'status'    => 'playing',
        ]);
    }

    // ============ STATUS (polling) ============
    case 'status': {
        $roomId = $_REQUEST['room'] ?? '';
        $room = load_room($roomId);
        if (!$room) {
            respond(['ok' => false, 'error' => 'room_not_found']);
        }

        $playerId = $_REQUEST['player'] ?? '';
        $round = $room['round'];
        $roundMoves = $room['moves'][$round] ?? [];

        $myRole = $room['players'][$playerId]['role'] ?? null;
        $iHaveMoved = $myRole && isset($roundMoves[$myRole]);

        $bothMoved = isset($roundMoves['p1']) && isset($roundMoves['p2']);

        $result = null;
        if ($bothMoved) {
            $winner = decide_winner($roundMoves['p1'], $roundMoves['p2']);
            $result = [
                'p1_move' => $roundMoves['p1'],
                'p2_move' => $roundMoves['p2'],
                'winner'  => $winner,
            ];
        }

        respond([
            'ok'            => true,
            'room_id'       => $roomId,
            'status'        => $room['status'],
            'players_count' => count($room['players']),
            'round'         => $round,
            'my_role'       => $myRole,
            'i_have_moved'  => $iHaveMoved,
            'both_moved'    => $bothMoved,
            'result'        => $result,
        ]);
    }

    // ============ MOVE ============
    case 'move': {
        $roomId = $_REQUEST['room'] ?? '';
        $playerId = $_REQUEST['player'] ?? '';
        $choice = $_REQUEST['choice'] ?? '';

        if (!in_array($choice, ['rock', 'paper', 'scissors'], true)) {
            respond(['ok' => false, 'error' => 'invalid_choice']);
        }

        $room = load_room($roomId);
        if (!$room) {
            respond(['ok' => false, 'error' => 'room_not_found']);
        }
        if (count($room['players']) < 2) {
            respond(['ok' => false, 'error' => 'waiting_for_opponent']);
        }
        $myRole = $room['players'][$playerId]['role'] ?? null;
        if (!$myRole) {
            respond(['ok' => false, 'error' => 'not_a_member']);
        }

        $round = $room['round'];
        if (!isset($room['moves'][$round])) {
            $room['moves'][$round] = [];
        }
        $room['moves'][$round][$myRole] = $choice;

        $p1Choice = $room['moves'][$round]['p1'] ?? '';
        $p2Choice = $room['moves'][$round]['p2'] ?? '';

        $bothMoved = ($p1Choice !== '') && ($p2Choice !== '');
        $winner = '';
        if ($bothMoved) {
            $winner = decide_winner($p1Choice, $p2Choice);
            $room['status'] = 'result';
        }

        save_room($roomId, $room);
        write_status_file($room);

        respond([
            'ok'         => true,
            'both_moved' => $bothMoved,
            'winner'     => $winner,
        ]);
    }

    // ============ NEXT ROUND ============
    case 'next_round': {
        $roomId = $_REQUEST['room'] ?? '';
        $room = load_room($roomId);
        if (!$room) {
            respond(['ok' => false, 'error' => 'room_not_found']);
        }
        $room['round'] += 1;
        $room['status'] = 'playing';
        save_room($roomId, $room);
        write_status_file($room);

        respond(['ok' => true, 'round' => $room['round']]);
    }

    default:
        respond(['ok' => false, 'error' => 'unknown_action']);
}



