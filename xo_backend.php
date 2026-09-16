<?php
/**
 * xo_backend.php
 * --------------
 * Simple backend for a 2-player online Tic-Tac-Toe game.
 * Uses one JSON file per room and appends every game-state change to
 * status/xo/{room_id}.txt.
 *
 * STATUS LOGGING SCHEMA (lines are semicolon-separated key=value pairs):
 * Every status line MUST follow this exact field order:
 *
 * state=ALIVE|DEAD;game=GAME_NAME;room=ROOM_ID;event=EVENT_NAME;...;time=YYYY-MM-DD HH:MM:SS
 *
 * See top of rps_backend.php for full schema details. Game-specific fields
 * for XO (in this exact order): status, board, turn, winner, round
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST');

$ROOMS_DIR = __DIR__ . '/rooms';

// Inactivity timeout (seconds) - configurable. Default 3 minutes (Part 3).
$INACTIVITY_TIMEOUT_SECONDS = 180;
// Rooms already DEAD are purged from storage after this many seconds.
$DEAD_ROOM_PURGE_SECONDS = 300;
// Full cleanup scans are throttled to once per this many seconds.
$CLEANUP_THROTTLE_SECONDS = 60;

if (!is_dir($ROOMS_DIR) && !mkdir($ROOMS_DIR, 0777, true) && !is_dir($ROOMS_DIR)) {
    error_log('Unable to create rooms directory: ' . $ROOMS_DIR);
}

// Ensure status file exists for this game (single fixed file per game)
$STATUS_DIR = __DIR__ . '/status';
if (!is_dir($STATUS_DIR) && !mkdir($STATUS_DIR, 0777, true) && !is_dir($STATUS_DIR)) {
    error_log('Unable to create status directory: ' . $STATUS_DIR);
}
$game_status_path = $STATUS_DIR . '/xo.txt';
if (!file_exists($game_status_path)) {
    $idle = ['state' => 'DEAD', 'game' => 'xo', 'room' => '', 'event' => 'idle', 'last_player' => '', 'last_move_row' => '', 'last_move_col' => '', 'board' => '---------', 'turn' => '', 'winner' => '', 'round' => 0];
    $parts = [];
    $parts[] = 'state=' . $idle['state'];
    $parts[] = 'game=' . $idle['game'];
    $parts[] = 'room=' . $idle['room'];
    $parts[] = 'event=' . $idle['event'];
    $parts[] = 'last_player=' . $idle['last_player'];
    $parts[] = 'last_move_row=' . $idle['last_move_row'];
    $parts[] = 'last_move_col=' . $idle['last_move_col'];
    $parts[] = 'board=' . $idle['board'];
    $parts[] = 'turn=' . $idle['turn'];
    $parts[] = 'winner=' . $idle['winner'];
    $parts[] = 'round=' . $idle['round'];
    $parts[] = 'time=' . date('Y-m-d H:i:s', time());
    if (file_put_contents($game_status_path, implode(';', $parts) . PHP_EOL, LOCK_EX) === false) {
        error_log('Unable to create game status file: ' . $game_status_path);
    }
}

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

function respond($arr) {
    echo json_encode($arr, JSON_UNESCAPED_UNICODE);
    exit;
}

function empty_board() {
    return ['', '', '', '', '', '', '', '', ''];
}

function role_symbol($role) {
    return $role === 'p1' ? 'X' : 'O';
}

function turn_role_from_symbol($symbol) {
    if ($symbol === 'X') return 'p1';
    if ($symbol === 'O') return 'p2';
    return '';
}

// Board serialization is kept compact for the append-only room log: X, O, or - per cell.
function serialize_board($board) {
    $out = '';
    for ($i = 0; $i < 9; $i++) {
        $cell = $board[$i] ?? '';
        $out .= ($cell === 'X' || $cell === 'O') ? $cell : '-';
    }
    return $out;
}

// Turn-based win detection differs from RPS: every move can end the round.
function detect_winner($board) {
    $lines = [
        [0, 1, 2], [3, 4, 5], [6, 7, 8],
        [0, 3, 6], [1, 4, 7], [2, 5, 8],
        [0, 4, 8], [2, 4, 6],
    ];

    foreach ($lines as $line) {
        [$a, $b, $c] = $line;
        if ($board[$a] !== '' && $board[$a] === $board[$b] && $board[$a] === $board[$c]) {
            return $board[$a];
        }
    }

    foreach ($board as $cell) {
        if ($cell === '') return '';
    }

    return 'draw';
}

function response_state($room, $playerId) {
    $myRole = $room['players'][$playerId]['role'] ?? null;
    $winner = $room['winner'] ?? '';
    $currentSymbol = $room['current_turn'] ?? 'X';
    $state = (!empty($room['finished']) && $room['finished']) ? 'DEAD' : 'ALIVE';

    return [
        'ok'             => true,
        'room_id'        => $room['room_id'],
        'status'         => $room['status'],
        'state'          => $state,
        'end_reason'     => $room['end_reason'] ?? '',
        'players_count'  => count($room['players']),
        'round'          => $room['round'],
        'board'          => $room['board'],
        'current_turn'   => turn_role_from_symbol($currentSymbol),
        'current_symbol' => $room['status'] === 'playing' ? $currentSymbol : '',
        'winner'         => $winner,
        'score'          => $room['score'],
        'my_role'        => $myRole,
    ];
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
    $status = $room['status'] ?? 'waiting';
    $board = serialize_board($room['board'] ?? empty_board());
    $turn = ($status === 'playing') ? ($room['current_turn'] ?? 'X') : '';
    $winner = $room['winner'] ?? '';
    $round = $room['round'] ?? 1;

    $state = (!empty($room['finished']) && $room['finished']) ? 'DEAD' : 'ALIVE';

    $fields = [
        'status' => $status,
        'board' => $board,
        'turn' => $turn,
        'winner' => $winner,
        'round' => $round,
    ];

    append_status_log('xo', $room['room_id'], $state, $event, $fields);
    // update master main status
    update_main_status('xo', $state);
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
    if (is_room_inactive($room, room_path($roomId), game_inactivity_timeout_seconds('xo'))) {
        mark_inactive_dead($roomId);
        return true;
    }
    return false;
}

// Full sweep used by the 'cleanup' action (throttled) (Part 3).
function cleanup_rooms() {
    global $INACTIVITY_TIMEOUT_SECONDS, $DEAD_ROOM_PURGE_SECONDS, $CLEANUP_THROTTLE_SECONDS, $ROOMS_DIR;

    $lock = $ROOMS_DIR . '/.cleanup_xo.lock';
    if (file_exists($lock)) {
        $mt = @filemtime($lock);
        if ($mt !== false && (time() - $mt) < $CLEANUP_THROTTLE_SECONDS) return;
    }
    @touch($lock);

    foreach (glob($ROOMS_DIR . '/*.json') as $file) {
        $roomId = basename($file, '.json');
        $room = @json_decode(@file_get_contents($file), true);
        if (!is_array($room)) continue;
        if (($room['game'] ?? '') !== 'xo') continue; // only our own rooms

        if (!empty($room['finished']) && $room['finished']) {
            // Already dead: purge from storage after a grace period so any
            // still-connected player can receive the end message first.
            $endedAt = isset($room['ended_at']) ? (int)$room['ended_at'] : 0;
            if ($endedAt > 0 && (time() - $endedAt) > $DEAD_ROOM_PURGE_SECONDS) {
                @unlink($file);
            }
            continue;
        }

        if (is_room_inactive($room, $file, game_inactivity_timeout_seconds('xo'))) {
            mark_inactive_dead($roomId);
        }
    }
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
            'room_id'      => $roomId,
            'game'         => 'xo',
            'status'       => 'waiting',
            'round'        => 1,
            'players'      => [
                $playerId => ['role' => 'p1'],
            ],
            'board'        => empty_board(),
            'current_turn' => 'X',
            'winner'       => '',
            'score'        => [
                'x'     => 0,
                'o'     => 0,
                'draws' => 0,
            ],
            'created_at'   => time(),
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
        $playerId = $_REQUEST['player'] ?? '';
        $room = load_room($roomId);
        if (!$room) {
            respond(['ok' => false, 'error' => 'room_not_found']);
        }
        if (check_inactive_and_end($room, $roomId)) {
            respond(['ok' => false, 'error' => 'match_ended', 'reason' => 'inactivity', 'state' => 'DEAD']);
        }

        respond(response_state($room, $playerId));
    }

    // ============ MOVE ============
    case 'move': {
        $roomId = $_REQUEST['room'] ?? '';
        $playerId = $_REQUEST['player'] ?? '';
        $cell = (int)($_REQUEST['cell'] ?? -1);

        $room = load_room($roomId);
        if (!$room) {
            respond(['ok' => false, 'error' => 'room_not_found']);
        }
        if (check_inactive_and_end($room, $roomId)) respond(['ok' => false, 'error' => 'match_ended', 'reason' => 'inactivity', 'state' => 'DEAD']);
        if (count($room['players']) < 2) {
            respond(['ok' => false, 'error' => 'waiting_for_opponent']);
        }
        if ($room['status'] !== 'playing') {
            respond(['ok' => false, 'error' => 'game_already_ended']);
        }

        $myRole = $room['players'][$playerId]['role'] ?? null;
        if (!$myRole) {
            respond(['ok' => false, 'error' => 'not_a_member']);
        }

        $mySymbol = role_symbol($myRole);
        if ($room['current_turn'] !== $mySymbol) {
            respond(['ok' => false, 'error' => 'not_your_turn']);
        }
        if ($cell < 0 || $cell > 8) {
            respond(['ok' => false, 'error' => 'invalid_cell']);
        }
        if (($room['board'][$cell] ?? '') !== '') {
            respond(['ok' => false, 'error' => 'cell_already_filled']);
        }

        $room['board'][$cell] = $mySymbol;
        $winner = detect_winner($room['board']);

        if ($winner !== '') {
            $room['status'] = 'result';
            $room['winner'] = $winner;
            $room['current_turn'] = '';

            if ($winner === 'X') {
                $room['score']['x'] += 1;
            } elseif ($winner === 'O') {
                $room['score']['o'] += 1;
            } else {
                $room['score']['draws'] += 1;
            }
        } else {
            $room['current_turn'] = $mySymbol === 'X' ? 'O' : 'X';
        }

        save_room($roomId, $room);
        write_status_file($room, 'move');
        if ($winner !== '') {
            write_status_file($room, 'game_result');
        }

        respond(response_state($room, $playerId));
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
        $room['status'] = count($room['players']) >= 2 ? 'playing' : 'waiting';
        // clear finished flag when starting a new round
        if (!empty($room['finished'])) unset($room['finished']);
        $room['board'] = empty_board();
        $room['current_turn'] = 'X';
        $room['winner'] = '';
        save_room($roomId, $room);
        write_status_file($room, 'play_again');

        respond(['ok' => true, 'round' => $room['round']]);
    }

    // ============ END GAME (explicitly finish match) ============
    case 'end_game': {
        $roomId = $_REQUEST['room'] ?? '';
        // Ended before a room was opened: still mark the game dead (own status
        // file + MAIN hub stats) using the shared helper.
        if ($roomId === '') {
            mark_game_dead_status('xo', 'match_ended');
            respond(['ok' => true, 'room_id' => '', 'state' => 'DEAD']);
        }
        $room = load_room($roomId);
        if (!$room) {
            respond(['ok' => false, 'error' => 'room_not_found']);
        }
        // Marks the game dead in its own status AND the main stats.
        endGameAndSync('xo', $roomId);

        respond(['ok' => true, 'room_id' => $roomId, 'state' => 'DEAD']);
    }

    // ============ CLEANUP (inactivity sweep) ============
    case 'cleanup': {
        cleanup_rooms();
        respond(['ok' => true]);
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
            respond(player_leave_room('xo', $roomId, $playerId, !empty($_REQUEST['soft'])));
        }
        respond(player_return_room($roomId, $playerId));
    }

    default:
        respond(['ok' => false, 'error' => 'unknown_action']);
}
