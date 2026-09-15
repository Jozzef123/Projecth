<?php
/**
 * mathquiz_backend.php
 * --------------------
 * Backend for a 2-player fast-answer Math Quiz duel.
 *
 * STATUS LOGGING SCHEMA (lines are semicolon-separated key=value pairs):
 * Every status line MUST follow this exact field order:
 *
 * state=ALIVE|DEAD;game=GAME_NAME;room=ROOM_ID;event=EVENT_NAME;...;time=YYYY-MM-DD HH:MM:SS
 *
 * Game-specific fields for MathQuiz (in this exact order):
 * status, question, p1_answer, p2_answer, winner, round
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST');

$ROOMS_DIR = __DIR__ . '/rooms';
$QUESTION_SECONDS = 15;

if (!is_dir($ROOMS_DIR)) {
    mkdir($ROOMS_DIR, 0777, true);
}

// Ensure status file exists for this game (single fixed file per game)
$STATUS_DIR = __DIR__ . '/status';
if (!is_dir($STATUS_DIR)) mkdir($STATUS_DIR, 0777, true);
$game_status_path = $STATUS_DIR . '/mathquiz.txt';
if (!file_exists($game_status_path)) {
    $idle = ['state' => 'DEAD', 'game' => 'mathquiz', 'room' => '', 'event' => 'idle', 'status' => 'waiting', 'question' => '', 'p1_answer' => '', 'p2_answer' => '', 'winner' => '', 'round' => 0];
    $parts = [];
    $parts[] = 'state=' . $idle['state'];
    $parts[] = 'game=' . $idle['game'];
    $parts[] = 'room=' . $idle['room'];
    $parts[] = 'event=' . $idle['event'];
    $parts[] = 'status=' . $idle['status'];
    $parts[] = 'question=' . $idle['question'];
    $parts[] = 'p1_answer=' . $idle['p1_answer'];
    $parts[] = 'p2_answer=' . $idle['p2_answer'];
    $parts[] = 'winner=' . $idle['winner'];
    $parts[] = 'round=' . $idle['round'];
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

function shuffle_values($values) {
    for ($i = count($values) - 1; $i > 0; $i--) {
        $j = random_int(0, $i);
        $tmp = $values[$i];
        $values[$i] = $values[$j];
        $values[$j] = $tmp;
    }
    return $values;
}

function make_wrong_options($correct) {
    $wrong = [];
    $deltas = shuffle_values([-5, -4, -3, -2, -1, 1, 2, 3, 4, 5]);
    foreach ($deltas as $delta) {
        $candidate = $correct + $delta;
        if ($candidate >= 0 && $candidate !== $correct && !in_array($candidate, $wrong, true)) {
            $wrong[] = $candidate;
        }
        if (count($wrong) === 2) break;
    }
    return $wrong;
}

// The question is generated only in PHP so both players see identical options.
function generate_question() {
    $op = ['+', '-', '*', '/'][random_int(0, 3)];

    if ($op === '+') {
        $num1 = random_int(1, 20);
        $num2 = random_int(1, 20);
        $answer = $num1 + $num2;
    } elseif ($op === '-') {
        $num1 = random_int(1, 20);
        $num2 = random_int(1, 20);
        if ($num2 > $num1) {
            $tmp = $num1;
            $num1 = $num2;
            $num2 = $tmp;
        }
        $answer = $num1 - $num2;
    } elseif ($op === '*') {
        $num1 = random_int(1, 12);
        $num2 = random_int(1, 12);
        $answer = $num1 * $num2;
    } else {
        $answer = random_int(1, 12);
        $num2 = random_int(1, 12);
        $num1 = $answer * $num2;
    }

    $options = shuffle_values(array_merge([$answer], make_wrong_options($answer)));

    return [
        'num1' => $num1,
        'num2' => $num2,
        'operator' => $op,
        'display' => $num1 . ' ' . $op . ' ' . $num2,
        'status_text' => $num1 . $op . $num2,
        'correct_answer' => $answer,
        'options' => $options,
    ];
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
    $question = $room['question']['status_text'] ?? '';
    $p1Answer = isset($room['answers']['p1']) ? $room['answers']['p1']['value'] : '';
    $p2Answer = isset($room['answers']['p2']) ? $room['answers']['p2']['value'] : '';
    $winner = $room['winner'] ?? '';
    $state = (!empty($room['finished']) && $room['finished']) ? 'DEAD' : 'ALIVE';

    $fields = [
        'status' => $room['status'],
        'question' => $question,
        'p1_answer' => $p1Answer,
        'p2_answer' => $p2Answer,
        'winner' => $winner,
        'round' => $room['round'],
        'state' => $state,
    ];

    append_status_log('mathquiz', $room['room_id'], $state, $event, $fields);
    // update master main status
    update_main_status('mathquiz', $state);
}

function expire_if_needed($room) {
    global $QUESTION_SECONDS;
    if (($room['status'] ?? '') !== 'playing') return $room;
    if (time() - ($room['question_started_at'] ?? time()) < $QUESTION_SECONDS) return $room;

    $room['status'] = 'result';
    $room['winner'] = 'draw';
    return $room;
}

function response_state($room, $playerId) {
    $myRole = $room['players'][$playerId]['role'] ?? null;
    return [
        'ok' => true,
        'room_id' => $room['room_id'],
        'status' => $room['status'],
        'state' => (!empty($room['finished']) && $room['finished']) ? 'DEAD' : 'ALIVE',
        'players_count' => count($room['players']),
        'round' => $room['round'],
        'question' => $room['question'],
        'question_started_at' => $room['question_started_at'],
        'answers' => [
            'p1' => $room['answers']['p1']['value'] ?? '',
            'p2' => $room['answers']['p2']['value'] ?? '',
        ],
        'winner' => $room['winner'],
        'score' => $room['score'],
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
            'question' => generate_question(),
            'question_started_at' => time(),
            'answers' => [],
            'winner' => '',
            'score' => ['p1' => 0, 'p2' => 0],
            'created_at' => time(),
        ];
        save_room($roomId, $room);
        write_status_file($room, 'create_room');
        write_status_file($room, 'question_generated');
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
        $room['question_started_at'] = time();
        save_room($roomId, $room);
        write_status_file($room, 'player_joined');
        respond(['ok' => true, 'room_id' => $roomId, 'player_id' => $playerId, 'role' => 'p2', 'status' => 'playing']);
    }

    case 'status': {
        $roomId = $_REQUEST['room'] ?? '';
        $playerId = $_REQUEST['player'] ?? '';
        $room = load_room($roomId);
        if (!$room) respond(['ok' => false, 'error' => 'room_not_found']);
        $before = $room['status'];
        $room = expire_if_needed($room);
        if ($before !== $room['status']) {
            save_room($roomId, $room);
            write_status_file($room, 'round_result');
        }
        respond(response_state($room, $playerId));
    }

    case 'answer': {
        $roomId = $_REQUEST['room'] ?? '';
        $playerId = $_REQUEST['player'] ?? '';
        $answer = (int)($_REQUEST['answer'] ?? 0);
        $room = load_room($roomId);
        if (!$room) respond(['ok' => false, 'error' => 'room_not_found']);
        if (count($room['players']) < 2) respond(['ok' => false, 'error' => 'waiting_for_opponent']);

        $room = expire_if_needed($room);
        if ($room['status'] !== 'playing') {
            save_room($roomId, $room);
            write_status_file($room, 'round_result');
            respond(['ok' => false, 'error' => 'round_expired']);
        }

        $myRole = $room['players'][$playerId]['role'] ?? null;
        if (!$myRole) respond(['ok' => false, 'error' => 'not_a_member']);
        if (isset($room['answers'][$myRole])) respond(['ok' => false, 'error' => 'already_answered']);

        $isCorrect = $answer === (int)$room['question']['correct_answer'];
        $room['answers'][$myRole] = ['value' => $answer, 'correct' => $isCorrect, 'answered_at' => microtime(true)];

        if ($isCorrect) {
            $room['status'] = 'result';
            $room['winner'] = $myRole;
            $room['score'][$myRole] += 1;
        } elseif (isset($room['answers']['p1']) && isset($room['answers']['p2'])) {
            $room['status'] = 'result';
            $room['winner'] = 'draw';
        }

        save_room($roomId, $room);
        write_status_file($room, 'player_answered');
        if ($room['status'] === 'result') {
            write_status_file($room, 'round_result');
        }
        respond(response_state($room, $playerId));
    }

    case 'next_round': {
        $roomId = $_REQUEST['room'] ?? '';
        $room = load_room($roomId);
        if (!$room) respond(['ok' => false, 'error' => 'room_not_found']);
        $room['round'] += 1;
        $room['status'] = count($room['players']) >= 2 ? 'playing' : 'waiting';
        if (!empty($room['finished'])) unset($room['finished']);
        $room['question'] = generate_question();
        $room['question_started_at'] = time();
        $room['answers'] = [];
        $room['winner'] = '';
        save_room($roomId, $room);
        write_status_file($room, 'next_question');
        write_status_file($room, 'question_generated');
        respond(['ok' => true, 'round' => $room['round']]);
    }

    case 'end_game': {
        $roomId = $_REQUEST['room'] ?? '';
        $room = load_room($roomId);
        if (!$room) respond(['ok' => false, 'error' => 'room_not_found']);
        $room['finished'] = true;
        save_room($roomId, $room);
        write_status_file($room, 'match_ended');
        respond(['ok' => true, 'room_id' => $roomId, 'state' => 'DEAD']);
    }

    default:
        respond(['ok' => false, 'error' => 'unknown_action']);
}
