<?php
/**
 * snakeladder_backend.php
 * -----------------------
 * Backend for a 2-player Snake and Ladder game.
 *
 * STATUS LOGGING SCHEMA (lines are semicolon-separated key=value pairs):
 * Every status line MUST follow this exact field order:
 *
 * state=ALIVE|DEAD;game=GAME_NAME;room=ROOM_ID;event=EVENT_NAME;...;time=YYYY-MM-DD HH:MM:SS
 *
 * Game-specific fields for SnakeAndLadder (in this exact order):
 * status, p1_pos, p2_pos, turn, last_roll, winner, round
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST');

$ROOMS_DIR = __DIR__ . '/rooms';

// Fixed board map: ladders move up, snakes move down after landing.
// Starts are unique across both maps so every feature is visually clear.
$LADDERS = [
    3 => 22,
    8 => 30,
    15 => 44,
    28 => 55,
    36 => 57,
    51 => 72,
    62 => 81,
    71 => 91,
];
$SNAKES = [
    27 => 5,
    39 => 17,
    48 => 26,
    58 => 37,
    66 => 45,
    79 => 60,
    89 => 53,
    99 => 41,
];

if (!is_dir($ROOMS_DIR)) {
    mkdir($ROOMS_DIR, 0777, true);
}

// Ensure status file exists for this game (single fixed file per game)
$STATUS_DIR = __DIR__ . '/status';
if (!is_dir($STATUS_DIR)) mkdir($STATUS_DIR, 0777, true);
$game_status_path = $STATUS_DIR . '/snakeladder.txt';
if (!file_exists($game_status_path)) {
    $idle = ['state' => 'DEAD', 'game' => 'snakeladder', 'room' => '', 'event' => 'idle', 'status' => 'waiting', 'p1_pos' => 0, 'p2_pos' => 0, 'turn' => '', 'last_roll' => '', 'winner' => '', 'round' => 0];
    $parts = [];
    $parts[] = 'state=' . $idle['state'];
    $parts[] = 'game=' . $idle['game'];
    $parts[] = 'room=' . $idle['room'];
    $parts[] = 'event=' . $idle['event'];
    $parts[] = 'status=' . $idle['status'];
    $parts[] = 'p1_pos=' . $idle['p1_pos'];
    $parts[] = 'p2_pos=' . $idle['p2_pos'];
    $parts[] = 'turn=' . $idle['turn'];
    $parts[] = 'last_roll=' . $idle['last_roll'];
    $parts[] = 'winner=' . $idle['winner'];
    $parts[] = 'round=' . $idle['round'];
    $parts[] = 'time=' . date('Y-m-d H:i:s', time());
    file_put_contents($game_status_path, implode(';', $parts) . PHP_EOL, LOCK_EX);
}

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

function apply_board_jump($position) {
    global $LADDERS, $SNAKES;
    if (isset($LADDERS[$position])) return $LADDERS[$position];
    if (isset($SNAKES[$position])) return $SNAKES[$position];
    return $position;
}

function jump_type($position) {
    global $LADDERS, $SNAKES;
    if (isset($LADDERS[$position])) return 'ladder';
    if (isset($SNAKES[$position])) return 'snake';
    return '';
}

function append_status_log($game, $roomId, $state, $event, $fields) {
    // Single fixed file per game: status/{game}.txt
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

function write_status_file($room, $event) {
    $turn = ($room['status'] ?? '') === 'playing' ? strtoupper($room['current_turn']) : '';
    $lastRoll = $room['last_roll'] ?? '';
    $winner = $room['winner'] ?? '';

    $state = (!empty($room['finished']) && $room['finished']) ? 'DEAD' : 'ALIVE';

    $fields = [
        'status' => $room['status'],
        'p1_pos' => $room['positions']['p1'],
        'p2_pos' => $room['positions']['p2'],
        'turn' => $turn,
        'last_roll' => $lastRoll,
        'winner' => $winner,
        'round' => $room['round'],
        'state' => $state,
    ];

    append_status_log('snakeladder', $room['room_id'], $state, $event, $fields);
}

function response_state($room, $playerId) {
    global $LADDERS, $SNAKES;
    $myRole = $room['players'][$playerId]['role'] ?? null;
    return [
        'ok' => true,
        'room_id' => $room['room_id'],
        'status' => $room['status'],
        'state' => (!empty($room['finished']) && $room['finished']) ? 'DEAD' : 'ALIVE',
        'players_count' => count($room['players']),
        'round' => $room['round'],
        'positions' => $room['positions'],
        'current_turn' => $room['status'] === 'playing' ? $room['current_turn'] : '',
        'last_roll' => $room['last_roll'],
        'winner' => $room['winner'],
        'score' => $room['score'],
        'maps' => ['ladders' => $LADDERS, 'snakes' => $SNAKES],
        'my_role' => $myRole,
    ];
}

$action = $_REQUEST['action'] ?? '';

switch ($action) {
    case 'create': {
        $roomId = generate_room_id();
        while (file_exists(room_path($roomId))) {
            $roomId = generate_room_id();
        }
        $playerId = generate_player_id();
        $room = [
            'room_id' => $roomId,
            'status' => 'waiting',
            'round' => 1,
            'players' => [$playerId => ['role' => 'p1']],
            'positions' => ['p1' => 0, 'p2' => 0],
            'current_turn' => 'p1',
            'last_roll' => '',
            'winner' => '',
            'score' => ['p1' => 0, 'p2' => 0],
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
        respond(response_state($room, $playerId));
    }

    case 'roll': {
        $roomId = $_REQUEST['room'] ?? '';
        $playerId = $_REQUEST['player'] ?? '';
        $room = load_room($roomId);
        if (!$room) respond(['ok' => false, 'error' => 'room_not_found']);
        if (count($room['players']) < 2) respond(['ok' => false, 'error' => 'waiting_for_opponent']);
        if ($room['status'] !== 'playing') respond(['ok' => false, 'error' => 'game_already_ended']);

        $myRole = $room['players'][$playerId]['role'] ?? null;
        if (!$myRole) respond(['ok' => false, 'error' => 'not_a_member']);
        if ($myRole !== $room['current_turn']) respond(['ok' => false, 'error' => 'not_your_turn']);

        $roll = random_int(1, 6);
        $room['last_roll'] = $roll;
        $beforePosition = $room['positions'][$myRole];
        $rawLanding = $beforePosition + $roll;
        $finalPosition = $beforePosition;
        $jumpType = '';

        // The player must land exactly on 100; overshooting means no movement.
        if ($rawLanding <= 100) {
            $jumpType = jump_type($rawLanding);
            $finalPosition = apply_board_jump($rawLanding);
            $room['positions'][$myRole] = $finalPosition;
        }

        if ($room['positions'][$myRole] === 100) {
            $room['status'] = 'result';
            $room['winner'] = $myRole;
            $room['score'][$myRole] += 1;
            $room['current_turn'] = '';
        } else {
            $room['current_turn'] = $myRole === 'p1' ? 'p2' : 'p1';
        }

        save_room($roomId, $room);
        write_status_file($room, 'dice_roll');
        if ($jumpType === 'ladder') {
            write_status_file($room, 'ladder_climb');
        } elseif ($jumpType === 'snake') {
            write_status_file($room, 'snake_slide');
        }
        if ($room['winner'] !== '') {
            write_status_file($room, 'game_won');
        }
        $response = response_state($room, $playerId);
        $response['roll_info'] = [
            'player' => $myRole,
            'roll' => $roll,
            'before_position' => $beforePosition,
            'raw_landing' => $rawLanding,
            'final_position' => $finalPosition,
            'jump_type' => $jumpType,
            'overshot' => $rawLanding > 100,
        ];
        respond($response);
    }

    case 'next_round': {
        $roomId = $_REQUEST['room'] ?? '';
        $room = load_room($roomId);
        if (!$room) respond(['ok' => false, 'error' => 'room_not_found']);

        $room['round'] += 1;
        $room['status'] = count($room['players']) >= 2 ? 'playing' : 'waiting';
        if (!empty($room['finished'])) unset($room['finished']);
        $room['positions'] = ['p1' => 0, 'p2' => 0];
        $room['current_turn'] = 'p1';
        $room['last_roll'] = '';
        $room['winner'] = '';
        save_room($roomId, $room);
        write_status_file($room, 'play_again');
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
