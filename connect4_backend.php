<?php
/**
 * connect4_backend.php
 * --------------------
 * Multiplayer Connect 4 backend following the project's room pattern.
 * Single fixed status file: status/connect4.txt (overwritten on every event).
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST');

$ROOMS_DIR = __DIR__ . '/rooms';

if (!is_dir($ROOMS_DIR)) {
    mkdir($ROOMS_DIR, 0777, true);
}

// Ensure status file exists for this game
$STATUS_DIR = __DIR__ . '/status';
if (!is_dir($STATUS_DIR)) mkdir($STATUS_DIR, 0777, true);
$game_status_path = $STATUS_DIR . '/connect4.txt';
if (!file_exists($game_status_path)) {
    $idle = ['state' => 'DEAD', 'game' => 'connect4', 'room' => '', 'event' => 'idle', 'last_player' => '', 'last_move_column' => '', 'last_move_row' => '', 'board' => str_repeat('-', 42), 'turn' => '', 'winner' => '', 'round' => 0];
    $parts = [];
    $parts[] = 'state=' . $idle['state'];
    $parts[] = 'game=' . $idle['game'];
    $parts[] = 'room=' . $idle['room'];
    $parts[] = 'event=' . $idle['event'];
    $parts[] = 'last_player=' . $idle['last_player'];
    $parts[] = 'last_move_column=' . $idle['last_move_column'];
    $parts[] = 'last_move_row=' . $idle['last_move_row'];
    $parts[] = 'board=' . $idle['board'];
    $parts[] = 'turn=' . $idle['turn'];
    $parts[] = 'winner=' . $idle['winner'];
    $parts[] = 'round=' . $idle['round'];
    $parts[] = 'time=' . date('Y-m-d H:i:s', time());
    file_put_contents($game_status_path, implode(';', $parts) . PHP_EOL, LOCK_EX);
}

require_once __DIR__ . '/status_helper.php';
ensure_main_file();

// Helpers (similar patterns used in other backends)
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

function respond($arr) {
    echo json_encode($arr, JSON_UNESCAPED_UNICODE);
    exit;
}

function serialize_board($board) {
    // board is array of 6 rows, each 7 columns, values: '' or 'R' or 'Y'
    $out = '';
    for ($r = 0; $r < 6; $r++) {
        for ($c = 0; $c < 7; $c++) {
            $cell = $board[$r][$c] ?? '';
            $out .= ($cell === 'R' || $cell === 'Y') ? $cell : '-';
        }
    }
    return $out;
}

function count_dir($board, $r, $c, $dr, $dc, $symbol) {
    $count = 0;
    $rows = 6; $cols = 7;
    $rr = $r; $cc = $c;
    while ($rr >= 0 && $rr < $rows && $cc >= 0 && $cc < $cols && ($board[$rr][$cc] ?? '') === $symbol) {
        $count++;
        $rr += $dr; $cc += $dc;
    }
    return $count;
}

function detect_winner($board, $lastRow, $lastCol, $symbol) {
    // check horizontal, vertical, diag1, diag2
    if (count_dir($board, $lastRow, $lastCol, 0, 1, $symbol) + count_dir($board, $lastRow, $lastCol, 0, -1, $symbol) - 1 >= 4) return true;
    if (count_dir($board, $lastRow, $lastCol, 1, 0, $symbol) + count_dir($board, $lastRow, $lastCol, -1, 0, $symbol) - 1 >= 4) return true;
    if (count_dir($board, $lastRow, $lastCol, 1, 1, $symbol) + count_dir($board, $lastRow, $lastCol, -1, -1, $symbol) - 1 >= 4) return true;
    if (count_dir($board, $lastRow, $lastCol, -1, 1, $symbol) + count_dir($board, $lastRow, $lastCol, 1, -1, $symbol) - 1 >= 4) return true;
    return false;
}

function board_full($board) {
    for ($c = 0; $c < 7; $c++) {
        if (($board[0][$c] ?? '') === '') return false;
    }
    return true;
}

function append_status_log($game, $roomId, $state, $event, $fields) {
    $dir = __DIR__ . '/status';
    if (!is_dir($dir)) mkdir($dir, 0777, true);
    $path = $dir . '/' . $game . '.txt';

    $parts = [];
    $parts[] = 'state=' . str_replace(["\r", "\n", ";"], '', strtoupper($state));
    $parts[] = 'game=' . str_replace(["\r", "\n", ";"], '', $game);
    $parts[] = 'room=' . preg_replace('/[^A-Za-z0-9]/', '', $roomId);
    $parts[] = 'event=' . str_replace(["\r", "\n", ";"], '', $event);

    foreach ($fields as $key => $value) {
        $clean = str_replace(["\r", "\n", ";"], '', (string)$value);
        $parts[] = $key . '=' . $clean;
    }

    $parts[] = 'time=' . date('Y-m-d H:i:s', time());
    $line = implode(';', $parts) . PHP_EOL;

    $fp = fopen($path, 'c+');
    if ($fp) {
        flock($fp, LOCK_EX);
        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, rtrim($line, PHP_EOL));
        fflush($fp);
        flock($fp, LOCK_UN);
        fclose($fp);
    }
    return true;
}

function write_status_file($room, $event, $lastMove = []) {
    $state = (!empty($room['finished']) && $room['finished']) ? 'DEAD' : 'ALIVE';
    $boardStr = serialize_board($room['board']);
    $fields = [
        'last_player' => $lastMove['player'] ?? '',
        'last_move_column' => isset($lastMove['col']) ? (string)$lastMove['col'] : '',
        'last_move_row' => isset($lastMove['row']) ? (string)$lastMove['row'] : '',
        'board' => $boardStr,
        'turn' => $room['status'] === 'playing' ? ($room['current_turn'] ?? '') : '',
        'winner' => $room['winner'] ?? '',
        'round' => $room['round'] ?? 1,
    ];

    append_status_log('connect4', $room['room_id'], $state, $event, $fields);
    // update master main status
    update_main_status('connect4', $state);
}

// Router
$action = $_REQUEST['action'] ?? '';

switch ($action) {
    case 'create': {
        $roomId = generate_room_id();
        while (file_exists(room_path($roomId))) { $roomId = generate_room_id(); }
        $playerId = generate_player_id();

        // empty 6x7 board
        $board = [];
        for ($r = 0; $r < 6; $r++) {
            $row = [];
            for ($c = 0; $c < 7; $c++) $row[] = '';
            $board[] = $row;
        }

        $room = [
            'room_id' => $roomId,
            'status' => 'waiting',
            'round' => 1,
            'players' => [$playerId => ['role' => 'p1']],
            'board' => $board,
            'current_turn' => 'p1',
            'last_move' => ['row' => '', 'col' => ''],
            'winner' => '',
            'created_at' => time(),
        ];
        save_room($roomId, $room);
        write_status_file($room, 'create_room');

        respond(['ok' => true, 'room_id' => $roomId, 'player_id' => $playerId, 'role' => 'p1']);
    }

    case 'join': {
        $roomId = $_REQUEST['room'] ?? '';
        $room = load_room($roomId);
        if (!$room) respond(['ok' => false, 'error' => 'room_not_found']);

        $playerId = $_REQUEST['player'] ?? '';
        if ($playerId && isset($room['players'][$playerId])) {
            respond(['ok' => true, 'room_id' => $roomId, 'player_id' => $playerId, 'role' => $room['players'][$playerId]['role'], 'status' => $room['status']]);
        }

        if (count($room['players']) >= 2) respond(['ok' => false, 'error' => 'room_full']);

        $playerId = generate_player_id();
        $room['players'][$playerId] = ['role' => 'p2'];
        $room['status'] = 'playing';
        save_room($roomId, $room);
        write_status_file($room, 'player_joined');

        respond(['ok' => true, 'room_id' => $roomId, 'player_id' => $playerId, 'role' => 'p2', 'status' => 'playing']);
    }

    case 'status': {
        $roomId = $_REQUEST['room'] ?? '';
        $playerId = $_REQUEST['player'] ?? '';
        $room = load_room($roomId);
        if (!$room) respond(['ok' => false, 'error' => 'room_not_found']);
        $myRole = $room['players'][$playerId]['role'] ?? null;
        respond(['ok' => true, 'room_id' => $roomId, 'status' => $room['status'], 'state' => (!empty($room['finished']) && $room['finished']) ? 'DEAD' : 'ALIVE', 'players_count' => count($room['players']), 'round' => $room['round'], 'board' => $room['board'], 'turn' => $room['current_turn'], 'winner' => $room['winner'], 'my_role' => $myRole]);
    }

    case 'move': {
        $roomId = $_REQUEST['room'] ?? '';
        $playerId = $_REQUEST['player'] ?? '';
        $col = isset($_REQUEST['column']) ? (int)$_REQUEST['column'] : -1;

        $room = load_room($roomId);
        if (!$room) respond(['ok' => false, 'error' => 'room_not_found']);
        if (count($room['players']) < 2) respond(['ok' => false, 'error' => 'waiting_for_opponent']);
        if ($room['status'] !== 'playing') respond(['ok' => false, 'error' => 'game_not_playing']);

        $myRole = $room['players'][$playerId]['role'] ?? null;
        if (!$myRole) respond(['ok' => false, 'error' => 'not_a_member']);
        if ($myRole !== $room['current_turn']) respond(['ok' => false, 'error' => 'not_your_turn']);

        if ($col < 0 || $col > 6) respond(['ok' => false, 'error' => 'invalid_column']);

        // find lowest empty row in column
        $targetRow = -1;
        for ($r = 5; $r >= 0; $r--) {
            if (($room['board'][$r][$col] ?? '') === '') { $targetRow = $r; break; }
        }
        if ($targetRow === -1) respond(['ok' => false, 'error' => 'column_full']);

        $symbol = $myRole === 'p1' ? 'R' : 'Y';
        $room['board'][$targetRow][$col] = $symbol;
        $room['last_move'] = ['row' => $targetRow, 'col' => $col];

        $won = detect_winner($room['board'], $targetRow, $col, $symbol);
        if ($won) {
            $room['status'] = 'result';
            $room['winner'] = $myRole;
            $room['current_turn'] = '';
            $room['finished'] = true;
        } else if (board_full($room['board'])) {
            $room['status'] = 'result';
            $room['winner'] = 'draw';
            $room['current_turn'] = '';
            $room['finished'] = true;
        } else {
            $room['current_turn'] = $myRole === 'p1' ? 'p2' : 'p1';
        }

        save_room($roomId, $room);
        write_status_file($room, 'move', ['player' => $myRole, 'col' => $col, 'row' => $targetRow]);
        if ($won) write_status_file($room, 'game_result', ['player' => $myRole, 'col' => $col, 'row' => $targetRow]);

        respond(['ok' => true, 'row' => $targetRow, 'col' => $col, 'winner' => $room['winner'] ?? '']);
    }

    case 'next_round': {
        $roomId = $_REQUEST['room'] ?? '';
        $room = load_room($roomId);
        if (!$room) respond(['ok' => false, 'error' => 'room_not_found']);
        $room['round'] += 1;
        $room['status'] = count($room['players']) >= 2 ? 'playing' : 'waiting';
        if (!empty($room['finished'])) unset($room['finished']);
        $board = [];
        for ($r = 0; $r < 6; $r++) { $row = []; for ($c = 0; $c < 7; $c++) $row[] = ''; $board[] = $row; }
        $room['board'] = $board;
        $room['current_turn'] = 'p1';
        $room['winner'] = '';
        save_room($roomId, $room);
        write_status_file($room, 'next_round');
        respond(['ok' => true, 'round' => $room['round']]);
    }

    case 'end_game': {
        $roomId = $_REQUEST['room'] ?? '';
        $room = load_room($roomId);
        if (!$room) respond(['ok' => false, 'error' => 'room_not_found']);
        $room['finished'] = true;
        $room['status'] = 'ended';
        save_room($roomId, $room);
        write_status_file($room, 'match_ended');
        respond(['ok' => true, 'room_id' => $roomId, 'state' => 'DEAD']);
    }

    default:
        respond(['ok' => false, 'error' => 'unknown_action']);
}

?>