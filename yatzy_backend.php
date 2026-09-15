<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST');

/**
 * yatzy_backend.php
 * -----------------
 * Backend for a 2-player Yatzy game.
 *
 * STATUS LOGGING SCHEMA (lines are semicolon-separated key=value pairs):
 * Every status line MUST follow this exact field order:
 *
 * state=ALIVE|DEAD;game=GAME_NAME;room=ROOM_ID;event=EVENT_NAME;...;time=YYYY-MM-DD HH:MM:SS
 *
 * Game-specific fields for Yatzy (in this exact order):
 * status, turn, rolls_left, dice, held_count, held_indices, held_values,
 * p1_score, p2_score, last_player, last_action, category_chosen, points_scored
 */

$ROOMS_DIR = __DIR__ . '/rooms';

if (!is_dir($ROOMS_DIR)) {
    mkdir($ROOMS_DIR, 0777, true);
}

// Ensure status file exists for this game (single fixed file per game)
$STATUS_DIR = __DIR__ . '/status';
if (!is_dir($STATUS_DIR)) mkdir($STATUS_DIR, 0777, true);
$game_status_path = $STATUS_DIR . '/yatzy.txt';
if (!file_exists($game_status_path)) {
    $idle = ['state' => 'DEAD', 'game' => 'yatzy', 'room' => '', 'event' => 'idle', 'status' => 'waiting', 'turn' => '', 'rolls_left' => 3, 'dice' => '', 'held_count' => 0, 'held_indices' => '', 'held_values' => '', 'p1_score' => 0, 'p2_score' => 0, 'last_player' => '', 'last_action' => '', 'category_chosen' => '', 'points_scored' => 0];
    $parts = [];
    $parts[] = 'state=' . $idle['state'];
    $parts[] = 'game=' . $idle['game'];
    $parts[] = 'room=' . $idle['room'];
    $parts[] = 'event=' . $idle['event'];
    $parts[] = 'status=' . $idle['status'];
    $parts[] = 'turn=' . $idle['turn'];
    $parts[] = 'rolls_left=' . $idle['rolls_left'];
    $parts[] = 'dice=' . $idle['dice'];
    $parts[] = 'held_count=' . $idle['held_count'];
    $parts[] = 'held_indices=' . $idle['held_indices'];
    $parts[] = 'held_values=' . $idle['held_values'];
    $parts[] = 'p1_score=' . $idle['p1_score'];
    $parts[] = 'p2_score=' . $idle['p2_score'];
    $parts[] = 'last_player=' . $idle['last_player'];
    $parts[] = 'last_action=' . $idle['last_action'];
    $parts[] = 'category_chosen=' . $idle['category_chosen'];
    $parts[] = 'points_scored=' . $idle['points_scored'];
    $parts[] = 'time=' . date('Y-m-d H:i:s', time());
    file_put_contents($game_status_path, implode(';', $parts) . PHP_EOL, LOCK_EX);
}

require_once __DIR__ . '/status_helper.php';
ensure_main_file();

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

function calculate_score($category, $dice) {
    $counts = array_count_values($dice);
    $sum = array_sum($dice);

    switch ($category) {
        case 'ones':   return ($counts[1] ?? 0) * 1;
        case 'twos':   return ($counts[2] ?? 0) * 2;
        case 'threes': return ($counts[3] ?? 0) * 3;
        case 'fours':  return ($counts[4] ?? 0) * 4;
        case 'fives':  return ($counts[5] ?? 0) * 5;
        case 'sixes':  return ($counts[6] ?? 0) * 6;
        case '3x':
            foreach ($counts as $val => $count) { if ($count >= 3) return $sum; }
            return 0;
        case '4x':
            foreach ($counts as $val => $count) { if ($count >= 4) return $sum; }
            return 0;
        case 'house':
            $has3 = false; $has2 = false;
            foreach ($counts as $val => $count) {
                if ($count === 3) $has3 = true;
                if ($count === 2) $has2 = true;
                if ($count === 5) { $has3 = true; $has2 = true; }
            }
            return ($has3 && $has2) ? 25 : 0;
        case 'small':
            $unique = array_keys($counts);
            sort($unique);
            $str = implode('', $unique);
            if (strpos($str, '1234') !== false || strpos($str, '2345') !== false || strpos($str, '3456') !== false) return 30;
            return 0;
        case 'large':
            $unique = array_keys($counts);
            sort($unique);
            $str = implode('', $unique);
            if ($str === '12345' || $str === '23456') return 40;
            return 0;
        case 'yatzy':
            foreach ($counts as $val => $count) { if ($count === 5) return 50; }
            return 0;
        case 'chance':
            return $sum;
        default:
            return 0;
    }
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

function write_status_file($room, $event, $lastActionInfo = []) {
    $turn = $room['current_turn'] ?? 'p1';
    $rollsLeft = $room['rolls_left'] ?? 3;
    $dice = $room['dice'] ?? [0,0,0,0,0];
    $diceStr = implode(',', $dice);

    $heldIndices = [];
    $heldValues = [];
    foreach ($room['held'] ?? [] as $idx => $isHeld) {
        if ($isHeld) {
            $heldIndices[] = $idx;
            $heldValues[] = $dice[$idx];
        }
    }
    
    $p1Score = $room['players_data']['p1']['total_score'] ?? 0;
    $p2Score = $room['players_data']['p2']['total_score'] ?? 0;

    $action = $lastActionInfo['action'] ?? 'none';
    $playerWhoActed = $lastActionInfo['player'] ?? $turn;
    $cat = $lastActionInfo['category'] ?? 'none';
    $pts = $lastActionInfo['points'] ?? 0;

    $state = ($room['status'] ?? '') === 'finished' ? 'DEAD' : (!empty($room['finished']) && $room['finished'] ? 'DEAD' : 'ALIVE');

    $fields = [
        'status' => $room['status'],
        'turn' => $turn,
        'rolls_left' => $rollsLeft,
        'dice' => $diceStr,
        'held_count' => count($heldIndices),
        'held_indices' => implode(',', $heldIndices),
        'held_values' => implode(',', $heldValues),
        'p1_score' => $p1Score,
        'p2_score' => $p2Score,
        'last_player' => $playerWhoActed,
        'last_action' => $action,
        'category_chosen' => $cat,
        'points_scored' => $pts,
    ];

    append_status_log('yatzy', $room['room_id'], $state, $event, $fields);
    // update master main status
    update_main_status('yatzy', $state);
}

// -------------------- Router --------------------

$action = $_REQUEST['action'] ?? '';

switch ($action) {

    case 'create': {
        $roomId = generate_room_id();
        while (file_exists(room_path($roomId))) { $roomId = generate_room_id(); }
        $playerId = generate_player_id();

        $room = [
            'room_id'      => $roomId,
            'status'       => 'waiting',
            'current_turn' => 'p1',
            'rolls_left'   => 3,
            'dice'         => [0,0,0,0,0],
            'held'         => [false, false, false, false, false],
            'players'      => [$playerId => 'p1'],
            'players_data' => [
                'p1' => ['scorecard' => [], 'total_score' => 0, 'upper_score' => 0],
                'p2' => ['scorecard' => [], 'total_score' => 0, 'upper_score' => 0],
            ],
            'created_at'   => time(),
        ];
        save_room($roomId, $room);
        write_status_file($room, 'create_room', ['action' => 'room_created']);

        respond(['ok' => true, 'room_id' => $roomId, 'player_id' => $playerId, 'role' => 'p1']);
    }

    case 'join': {
        $roomId = $_REQUEST['room'] ?? '';
        $room = load_room($roomId);
        if (!$room) respond(['ok' => false, 'error' => 'room_not_found']);

        $playerId = $_REQUEST['player'] ?? '';
        if ($playerId && isset($room['players'][$playerId])) {
            respond(['ok' => true, 'room_id' => $roomId, 'player_id' => $playerId, 'role' => $room['players'][$playerId], 'status' => $room['status']]);
        }

        if (count($room['players']) >= 2) respond(['ok' => false, 'error' => 'room_full']);

        $playerId = generate_player_id();
        $room['players'][$playerId] = 'p2';
        $room['status'] = 'playing';
        save_room($roomId, $room);
        write_status_file($room, 'player_joined', ['action' => 'player_joined']);

        respond(['ok' => true, 'room_id' => $roomId, 'player_id' => $playerId, 'role' => 'p2', 'status' => 'playing']);
    }

    case 'roll': {
        $roomId = $_REQUEST['room'] ?? '';
        $playerId = $_REQUEST['player'] ?? '';
        $room = load_room($roomId);

        if (!$room) respond(['ok' => false, 'error' => 'room_not_found']);
        $myRole = $room['players'][$playerId] ?? null;
        if ($myRole !== $room['current_turn']) respond(['ok' => false, 'error' => 'not_your_turn']);
        if ($room['rolls_left'] <= 0) respond(['ok' => false, 'error' => 'no_rolls_left']);

        for ($i = 0; $i < 5; $i++) {
            if (!$room['held'][$i]) {
                $room['dice'][$i] = random_int(1, 6);
            }
        }
        $room['rolls_left'] -= 1;
        save_room($roomId, $room);

        write_status_file($room, 'dice_roll', [
            'player' => $myRole,
            'action' => 'roll'
        ]);

        respond(['ok' => true, 'dice' => $room['dice'], 'rolls_left' => $room['rolls_left']]);
    }

    case 'toggle_hold': {
        $roomId = $_REQUEST['room'] ?? '';
        $playerId = $_REQUEST['player'] ?? '';
        $dieIndex = (int)($_REQUEST['index'] ?? -1);

        $room = load_room($roomId);
        if (!$room) respond(['ok' => false, 'error' => 'room_not_found']);
        $myRole = $room['players'][$playerId] ?? null;
        if ($myRole !== $room['current_turn']) respond(['ok' => false, 'error' => 'not_your_turn']);
        if ($room['rolls_left'] >= 3) respond(['ok' => false, 'error' => 'roll_first']);

        if ($dieIndex >= 0 && $dieIndex < 5) {
            $room['held'][$dieIndex] = !$room['held'][$dieIndex];
            save_room($roomId, $room);

            write_status_file($room, 'toggle_hold', [
                'player' => $myRole,
                'action' => 'hold'
            ]);
        }

        respond(['ok' => true, 'held' => $room['held']]);
    }

    case 'score': {
        $roomId = $_REQUEST['room'] ?? '';
        $playerId = $_REQUEST['player'] ?? '';
        $category = $_REQUEST['category'] ?? '';

        $room = load_room($roomId);
        if (!$room) respond(['ok' => false, 'error' => 'room_not_found']);
        $myRole = $room['players'][$playerId] ?? null;
        if ($myRole !== $room['current_turn']) respond(['ok' => false, 'error' => 'not_your_turn']);
        if ($room['rolls_left'] >= 3) respond(['ok' => false, 'error' => 'roll_first']);

        $pData = &$room['players_data'][$myRole];
        if (isset($pData['scorecard'][$category])) respond(['ok' => false, 'error' => 'category_already_used']);

        $pts = calculate_score($category, $room['dice']);
        $pData['scorecard'][$category] = $pts;

        if (in_array($category, ['ones', 'twos', 'threes', 'fours', 'fives', 'sixes'])) {
            $pData['upper_score'] += $pts;
            if ($pData['upper_score'] >= 63 && !isset($pData['scorecard']['bonus'])) {
                $pData['scorecard']['bonus'] = 35;
            }
        }

        $total = 0;
        foreach ($pData['scorecard'] as $v) { $total += $v; }
        $pData['total_score'] = $total;

        $p1Cards = count($room['players_data']['p1']['scorecard']);
        $p2Cards = count($room['players_data']['p2']['scorecard']);

        if ($p1Cards >= 13 && $p2Cards >= 13) {
            $room['status'] = 'finished';
        } else {
            $room['current_turn'] = ($myRole === 'p1') ? 'p2' : 'p1';
            $room['rolls_left'] = 3;
            $room['dice'] = [0,0,0,0,0];
            $room['held'] = [false, false, false, false, false];
        }

        save_room($roomId, $room);

        write_status_file($room, 'score_category', [
            'player'   => $myRole,
            'action'   => 'score_category',
            'category' => $category,
            'points'   => $pts
        ]);
        if ($room['status'] === 'finished') {
            write_status_file($room, 'game_finished', [
                'player'   => $myRole,
                'action'   => 'game_finished',
                'category' => $category,
                'points'   => $pts
            ]);
        }

        respond(['ok' => true, 'room' => $room]);
    }

    case 'status': {
        $roomId = $_REQUEST['room'] ?? '';
        $room = load_room($roomId);
        if (!$room) respond(['ok' => false, 'error' => 'room_not_found']);

        respond(['ok' => true, 'room' => $room]);
    }

    default:
        respond(['ok' => false, 'error' => 'unknown_action']);
}
