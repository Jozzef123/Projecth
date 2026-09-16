<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
/**
 * rps_backend.php
 * -----------------
 * Simple backend for a 2-player online Rock Paper Scissors game.
 * Uses plain JSON files instead of a database, and appends every
 * game-state change to status/rps/{room_id}.txt.
 *
 * STATUS LOGGING SCHEMA (lines are semicolon-separated key=value pairs):
 * Every status line MUST follow this exact field order:
 *
 * state=ALIVE|DEAD;game=GAME_NAME;room=ROOM_ID;event=EVENT_NAME;...;time=YYYY-MM-DD HH:MM:SS
 *
 * - `state`: ALIVE when the room/match exists and may continue, DEAD when the
 *            match has ended and will not continue.
 * - `game`:  short game identifier (e.g. rps, xo, yatzy)
 * - `room`:  room id string
 * - `event`: semantic event name (create_room, player_joined, player_choice,
 *            round_result, next_round, match_ended, game_finished, etc.)
 *
 * Each game may append game-specific fields after the required first four
 * fields. Those fields must always appear in the same order for the game and
 * must never be omitted (use an empty value when appropriate). The final
 * field in every line MUST be `time=YYYY-MM-DD HH:MM:SS`.
 *
 * This stable, fixed-order format is designed to be parsed by an external
 * device (ARM M3) that splits on `;` then `=` and reads fields by index.
 */

header('Content-Type: application/json; charset=utf-8');

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST');

$ROOMS_DIR = __DIR__ . '/rooms';

if (!is_dir($ROOMS_DIR) && !mkdir($ROOMS_DIR, 0777, true) && !is_dir($ROOMS_DIR)) {
    error_log('Unable to create rooms directory: ' . $ROOMS_DIR);
}

// Ensure status file exists for this game (single fixed file per game)
$STATUS_DIR = __DIR__ . '/status';
if (!is_dir($STATUS_DIR) && !mkdir($STATUS_DIR, 0777, true) && !is_dir($STATUS_DIR)) {
    error_log('Unable to create status directory: ' . $STATUS_DIR);
}
$game_status_path = $STATUS_DIR . '/rps.txt';
if (!file_exists($game_status_path)) {
    $idle = ['state' => 'DEAD', 'game' => 'rps', 'room' => '', 'event' => 'idle', 'status' => 'waiting', 'p1_choice' => '', 'p2_choice' => '', 'winner' => '', 'round' => 0];
    $parts = [];
    $parts[] = 'state=' . $idle['state'];
    $parts[] = 'game=' . $idle['game'];
    $parts[] = 'room=' . $idle['room'];
    $parts[] = 'event=' . $idle['event'];
    $parts[] = 'status=' . $idle['status'];
    $parts[] = 'p1_choice=' . $idle['p1_choice'];
    $parts[] = 'p2_choice=' . $idle['p2_choice'];
    $parts[] = 'winner=' . $idle['winner'];
    $parts[] = 'round=' . $idle['round'];
    $parts[] = 'time=' . date('Y-m-d H:i:s', time());
    if (file_put_contents($game_status_path, implode(';', $parts) . PHP_EOL, LOCK_EX) === false) {
        error_log('Unable to create game status file: ' . $game_status_path);
    }
}

// shared helper to update status/main.txt
require_once __DIR__ . '/status_helper.php';
ensure_main_file();

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
    // Any real write counts as activity for the inactivity timeout (Part 3).
    $data['last_activity'] = time();
    $path = room_path($roomId);
    $fp = fopen($path, 'c+');
    if (!$fp) {
        error_log('Unable to open room file for writing: ' . $path);
        return false;
    }
    flock($fp, LOCK_EX);
    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
    return true;
}

function append_status_log($game, $roomId, $state, $event, $fields) {
    // Single fixed file per game: status/{game}.txt
    $dir = __DIR__ . '/status';
    if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
        error_log('Unable to create status directory: ' . $dir);
        return false;
    }
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
    } else {
        error_log('Unable to write status file: ' . $path);
        return false;
    }
    return true;
}

function write_status_file($room, $event) {
    $round = $room['round'] ?? 1;
    $p1 = $room['moves'][$round]['p1'] ?? '';
    $p2 = $room['moves'][$round]['p2'] ?? '';
    $winner = ($p1 !== '' && $p2 !== '') ? decide_winner($p1, $p2) : '';

    $state = (!empty($room['finished']) && $room['finished']) ? 'DEAD' : 'ALIVE';

    // Game-specific fields MUST be in the same order every time for ARM parsing
    $fields = [
        'status' => $room['status'] ?? 'waiting',
        'p1_choice' => $p1,
        'p2_choice' => $p2,
        'winner' => $winner,
        'round' => $round,
    ];

    append_status_log('rps', $room['room_id'], $state, $event, $fields);
    update_main_status('rps', $state);
}

function respond($arr) {
    echo json_encode($arr, JSON_UNESCAPED_UNICODE);
    exit;
}

// Inactivity (Part 3): mark an idle room dead in BOTH statuses.
function mark_inactive_dead($roomId) {
    $room = load_room($roomId);
    if (!$room) return false;
    if (!empty($room['finished']) && $room['finished']) return true;
    $room['finished'] = true;
    $room['status'] = 'ended';
    $room['end_reason'] = 'inactivity';
    $room['ended_at'] = time();
    save_room($roomId, $room);
    write_status_file($room, 'inactivity_timeout');
    return true;
}

// Returns true (and marks the room dead) if it has been idle too long.
// The timeout is configurable via status_config.php (see status_helper.php).
function check_inactive_and_end($room, $roomId) {
    if (is_room_inactive($room, room_path($roomId), game_inactivity_timeout_seconds('rps'))) {
        mark_inactive_dead($roomId);
        return true;
    }
    return false;
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
            'game'       => 'rps',
            'status'     => 'waiting',
            'round'      => 1,
            'players'    => [
                $playerId => ['role' => 'p1'],
            ],
            'moves'      => [],
            'created_at' => time(),
            'last_activity' => time(),
        ];
        save_room($roomId, $room);
        write_status_file($room, 'create_room');

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
        if (check_inactive_and_end($room, $roomId)) respond(['ok' => false, 'error' => 'match_ended', 'reason' => 'inactivity', 'state' => 'DEAD']);

        // Check for manually-ended game
        if (!empty($room['finished']) && $room['finished']) {
            respond(['ok' => false, 'error' => 'match_ended', 'reason' => $room['end_reason'] ?? 'manual', 'state' => 'DEAD']);
        }

        $playerId = $_REQUEST['player'] ?? '';

        // A player who previously left (leave detection) is active again.
        if ($playerId && isset($room['players'][$playerId]) && !empty($room['players'][$playerId]['left'])) {
            $room['players'][$playerId]['left'] = 0;
            unset($room['players'][$playerId]['left_at'], $room['players'][$playerId]['left_soft']);
            save_room($roomId, $room);
        }

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
        write_status_file($room, 'player_joined');

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
        if (check_inactive_and_end($room, $roomId)) {
            respond(['ok' => false, 'error' => 'match_ended', 'reason' => 'inactivity', 'state' => 'DEAD']);
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

        $state = (!empty($room['finished']) && $room['finished']) ? 'DEAD' : 'ALIVE';

        respond([
            'ok'            => true,
            'room_id'       => $roomId,
            'status'        => $room['status'],
            'state'         => $state,
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
        if (check_inactive_and_end($room, $roomId)) {
            respond(['ok' => false, 'error' => 'match_ended', 'reason' => 'inactivity', 'state' => 'DEAD']);
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
        write_status_file($room, 'player_choice');
        if ($bothMoved) {
            write_status_file($room, 'round_result');
        }

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
        if (check_inactive_and_end($room, $roomId)) respond(['ok' => false, 'error' => 'match_ended', 'reason' => 'inactivity', 'state' => 'DEAD']);
        $room['round'] += 1;
        $room['status'] = 'playing';
        save_room($roomId, $room);
        write_status_file($room, 'next_round');

        respond(['ok' => true, 'round' => $room['round']]);
    }

    case 'end_game': {
        $roomId = $_REQUEST['room'] ?? '';
        // Ended before a room was opened: still mark the game dead (own status
        // file + MAIN hub stats) using the shared helper.
        if ($roomId === '') {
            mark_game_dead_status('rps', 'match_ended');
            respond(['ok' => true, 'room_id' => '', 'state' => 'DEAD']);
        }
        $room = load_room($roomId);
        if (!$room) {
            respond(['ok' => false, 'error' => 'room_not_found']);
        }
        // Marks the game dead in its own status AND the main stats.
        endGameAndSync('rps', $roomId);

        respond(['ok' => true, 'room_id' => $roomId, 'state' => 'DEAD']);
    }

    case 'leave':
    case 'back': {
        // Leave detection (fired by end_game_helper.js on pagehide /
        // beforeunload / visibilitychange): see player_leave_room in
        // status_helper.php. Both players hard-left -> room ends immediately.
        $roomId = $_REQUEST['room'] ?? '';
        $playerId = $_REQUEST['player'] ?? '';
        if ($roomId === '') respond(['ok' => true, 'room_id' => '', 'state' => 'DEAD']);
        if ($action === 'leave') {
            respond(player_leave_room('rps', $roomId, $playerId, !empty($_REQUEST['soft'])));
        }
        respond(player_return_room($roomId, $playerId));
    }

    default:
        respond(['ok' => false, 'error' => 'unknown_action']);
}


