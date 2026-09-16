<?php
/**
 * memory_backend.php
 * ------------------
 * Backend for a 2-player Memory matching game.
 * 8 cards = 4 shapes (Ball, Paper, Dice, Fish) x 2 copies. Two players take
 * turns flipping two cards: a match keeps them face-up (score +1, same player
 * goes again), a mismatch flips them back and the turn passes. The player with
 * the most matched pairs wins.
 *
 * Follows the same room pattern as connect4/xo: one JSON file per room in
 * rooms/, a single fixed status file status/memory.txt, and it updates the
 * master main status via status_helper.php.
 *
 * STATUS LOGGING SCHEMA (lines are semicolon-separated key=value pairs):
 * state=ALIVE|DEAD;game=GAME_NAME;room=ROOM_ID;event=EVENT_NAME;...;time=...
 * Game-specific fields for Memory (in this exact order):
 * status, turn, flip1, flip2, matched_pairs, board, winner, round
 *
 * Shared board state (see flip/status): the first card of a turn is kept
 * face-up, and a mismatched pair stays face-up for a short "reveal window"
 * (pending / pending_until) before it flips back for BOTH players at once.
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
$game_status_path = $STATUS_DIR . '/memory.txt';
if (!file_exists($game_status_path)) {
    $idle = ['state' => 'DEAD', 'game' => 'memory', 'room' => '', 'event' => 'idle', 'status' => 'waiting', 'turn' => '', 'flip1' => '', 'flip2' => '', 'matched_pairs' => 0, 'board' => '--------', 'winner' => '', 'round' => 0];
    $parts = [];
    $parts[] = 'state=' . $idle['state'];
    $parts[] = 'game=' . $idle['game'];
    $parts[] = 'room=' . $idle['room'];
    $parts[] = 'event=' . $idle['event'];
    $parts[] = 'status=' . $idle['status'];
    $parts[] = 'turn=' . $idle['turn'];
    $parts[] = 'flip1=' . $idle['flip1'];
    $parts[] = 'flip2=' . $idle['flip2'];
    $parts[] = 'matched_pairs=' . $idle['matched_pairs'];
    $parts[] = 'board=' . $idle['board'];
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

// The 4 shapes used by the game (2 copies each = 8 cards).
function card_shapes() {
    return ['ball', 'paper', 'dice', 'fish'];
}

function fresh_board() {
    $shapes = card_shapes();
    $deck = array_merge($shapes, $shapes); // 8 cards, 2 copies of each
    shuffle($deck);
    $cards = [];
    for ($i = 0; $i < 8; $i++) {
        $cards[] = [
            'shape'      => $deck[$i],
            'flipped'    => false,
            'matched'    => false,
            'matched_by' => '',
        ];
    }
    return $cards;
}

// Serialized board for the status file: '-' while hidden/unmatched, or the
// uppercase shape initial once a pair has been matched.
function serialize_board($cards) {
    $initials = ['ball' => 'B', 'paper' => 'P', 'dice' => 'D', 'fish' => 'F'];
    $out = '';
    for ($i = 0; $i < 8; $i++) {
        $c = $cards[$i];
        if (!empty($c['matched'])) {
            $out .= isset($initials[$c['shape']]) ? $initials[$c['shape']] : 'X';
        } else {
            $out .= '-';
        }
    }
    return $out;
}

// Shapes are only revealed for flipped/matched cards so players cannot inspect
// the payload to cheat; both players still see the exact same board state.
function sanitized_cards($cards) {
    $out = [];
    foreach ($cards as $c) {
        $visible = !empty($c['flipped']) || !empty($c['matched']);
        $out[] = [
            'shape'      => $visible ? $c['shape'] : 'hidden',
            'flipped'    => !empty($c['flipped']),
            'matched'    => !empty($c['matched']),
            'matched_by' => $c['matched_by'] ?? '',
        ];
    }
    return $out;
}

function append_status_log($game, $roomId, $state, $event, $fields) {
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
    $state = (!empty($room['finished']) && $room['finished']) ? 'DEAD' : 'ALIVE';
    $turn = $room['current_turn'] ?? 'p1';
    $flip1 = isset($room['flip1']) && $room['flip1'] !== null ? (string)$room['flip1'] : '';
    $flip2 = isset($room['flip2']) && $room['flip2'] !== null ? (string)$room['flip2'] : '';
    $matchedPairs = ($room['score']['p1'] ?? 0) + ($room['score']['p2'] ?? 0);
    $fields = [
        'status' => $room['status'],
        'turn' => $turn,
        'flip1' => $flip1,
        'flip2' => $flip2,
        'matched_pairs' => $matchedPairs,
        'board' => serialize_board($room['cards']),
        'winner' => $room['winner'] ?? '',
        'round' => $room['round'] ?? 1,
    ];

    append_status_log('memory', $room['room_id'], $state, $event, $fields);
    // update master main status
    update_main_status('memory', $state);
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
    if (is_room_inactive($room, room_path($roomId), game_inactivity_timeout_seconds('memory'))) {
        mark_inactive_dead($roomId);
        return true;
    }
    return false;
}

// Full sweep used by the 'cleanup' action (throttled) (Part 3).
function cleanup_rooms() {
    global $INACTIVITY_TIMEOUT_SECONDS, $DEAD_ROOM_PURGE_SECONDS, $CLEANUP_THROTTLE_SECONDS, $ROOMS_DIR;

    // Throttle full scans so we do not hammer the filesystem on every poll.
    $lock = $ROOMS_DIR . '/.cleanup_memory.lock';
    if (file_exists($lock)) {
        $mt = @filemtime($lock);
        if ($mt !== false && (time() - $mt) < $CLEANUP_THROTTLE_SECONDS) return;
    }
    @touch($lock);

    foreach (glob($ROOMS_DIR . '/*.json') as $file) {
        $roomId = basename($file, '.json');
        $room = @json_decode(@file_get_contents($file), true);
        if (!is_array($room)) continue;
        if (($room['game'] ?? '') !== 'memory') continue; // only our own rooms

        if (!empty($room['finished']) && $room['finished']) {
            // Already dead: purge from storage after a grace period so any
            // still-connected player can receive the end message first.
            $endedAt = isset($room['ended_at']) ? (int)$room['ended_at'] : 0;
            if ($endedAt > 0 && (time() - $endedAt) > $DEAD_ROOM_PURGE_SECONDS) {
                @unlink($file);
            }
            continue;
        }

        if (is_room_inactive($room, $file, game_inactivity_timeout_seconds('memory'))) {
            mark_inactive_dead($roomId);
        }
    }
}

// Mismatched pairs stay face-up for a short shared "reveal window" so BOTH
// players see the same two cards before they flip back (see memory.js).
function pending_reveal_seconds() {
    return 0.8;
}

// Clears a mismatch reveal once its window has passed (or when forced by the
// next flip) and persists the room. The window is timed server-side, so both
// clients flip the pair back at the same moment.
function resolve_pending($roomId, $room, $force = false) {
    if (empty($room['pending'])) return $room;
    $until = (float)($room['pending_until'] ?? 0);
    if (!$force && microtime(true) < $until) return $room;

    foreach ($room['pending'] as $index) {
        if (isset($room['cards'][$index]) && empty($room['cards'][$index]['matched'])) {
            $room['cards'][$index]['flipped'] = false;
        }
    }
    $room['pending'] = [];
    $room['pending_until'] = 0;
    $room['flip1'] = null;
    $room['flip2'] = null;
    save_room($roomId, $room);
    return $room;
}

function response_state($room, $playerId) {
    $myRole = $room['players'][$playerId]['role'] ?? null;
    $state = (!empty($room['finished']) && $room['finished']) ? 'DEAD' : 'ALIVE';
    return [
        'ok'            => true,
        'room_id'       => $room['room_id'],
        'status'        => $room['status'],
        'state'         => $state,
        'end_reason'    => $room['end_reason'] ?? '',
        'players_count' => count($room['players']),
        'round'         => $room['round'],
        'cards'         => sanitized_cards($room['cards']),
        'flip1'         => $room['flip1'],
        'flip2'         => $room['flip2'],
        'pending'       => $room['pending'] ?? [],
        'pending_remaining' => max(0.0, (float)($room['pending_until'] ?? 0) - microtime(true)),
        'current_turn'  => $room['current_turn'],
        'score'         => $room['score'],
        'winner'        => $room['winner'] ?? '',
        'my_role'       => $myRole,
    ];
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
            'game'         => 'memory',
            'status'       => 'waiting',
            'round'        => 1,
            'players'      => [$playerId => ['role' => 'p1']],
            'cards'        => fresh_board(),
            'flip1'        => null,
            'flip2'        => null,
            'pending'      => [],
            'pending_until'=> 0,
            'current_turn' => 'p1',
            'score'        => ['p1' => 0, 'p2' => 0],
            'winner'       => '',
            'end_reason'   => '',
            'created_at'   => time(),
            'last_activity'=> time(),
        ];
        save_room($roomId, $room);
        write_status_file($room, 'create_room');

        respond(['ok' => true, 'room_id' => $roomId, 'player_id' => $playerId, 'role' => 'p1']);
    }

    case 'join': {
        $roomId = $_REQUEST['room'] ?? '';
        $room = load_room($roomId);
        if (!$room) respond(['ok' => false, 'error' => 'room_not_found']);

        // Check for manually-ended or inactive game
        if (!empty($room['finished']) && $room['finished']) {
            respond(['ok' => false, 'error' => 'match_ended', 'reason' => $room['end_reason'] ?? 'manual', 'state' => 'DEAD']);
        }
        if (check_inactive_and_end($room, $roomId)) respond(['ok' => false, 'error' => 'match_ended', 'reason' => 'inactivity', 'state' => 'DEAD']);

        // A player who previously left (leave detection) is active again.
        if ($playerId && isset($room['players'][$playerId]) && !empty($room['players'][$playerId]['left'])) {
            $room['players'][$playerId]['left'] = 0;
            unset($room['players'][$playerId]['left_at'], $room['players'][$playerId]['left_soft']);
            save_room($roomId, $room);
        }
        if ($playerId && isset($room['players'][$playerId])) {
            $state = (!empty($room['finished']) && $room['finished']) ? 'DEAD' : 'ALIVE';
            respond(['ok' => true, 'room_id' => $roomId, 'player_id' => $playerId, 'role' => $room['players'][$playerId]['role'], 'status' => $room['status'], 'state' => $state, 'end_reason' => $room['end_reason'] ?? '']);
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
        if (check_inactive_and_end($room, $roomId)) {
            respond(['ok' => false, 'error' => 'match_ended', 'reason' => 'inactivity', 'state' => 'DEAD']);
        }
        // Flip a revealed mismatched pair back once its window has passed, so
        // every player polls the same board state.
        $room = resolve_pending($roomId, $room);
        respond(response_state($room, $playerId));
    }

    case 'flip': {
        $roomId = $_REQUEST['room'] ?? '';
        $playerId = $_REQUEST['player'] ?? '';
        $card = (int)($_REQUEST['card'] ?? -1);

        $room = load_room($roomId);
        if (!$room) respond(['ok' => false, 'error' => 'room_not_found']);
        if (check_inactive_and_end($room, $roomId)) respond(['ok' => false, 'error' => 'match_ended', 'reason' => 'inactivity', 'state' => 'DEAD']);
        if (count($room['players']) < 2) respond(['ok' => false, 'error' => 'waiting_for_opponent']);
        if ($room['status'] !== 'playing') respond(['ok' => false, 'error' => 'game_already_ended']);

        $myRole = $room['players'][$playerId]['role'] ?? null;
        if (!$myRole) respond(['ok' => false, 'error' => 'not_a_member']);
        if ($myRole !== $room['current_turn']) respond(['ok' => false, 'error' => 'not_your_turn']);
        if ($card < 0 || $card > 7) respond(['ok' => false, 'error' => 'invalid_card']);
        if (!empty($room['cards'][$card]['matched'])) respond(['ok' => false, 'error' => 'card_already_matched']);
        if (!empty($room['cards'][$card]['flipped'])) respond(['ok' => false, 'error' => 'card_already_flipped']);

        // A new flip always clears a reveal that is still on screen.
        $room = resolve_pending($roomId, $room, true);

        // ---- First flip of the turn ----
        if ($room['flip1'] === null) {
            $room['flip1'] = $card;
            $room['cards'][$card]['flipped'] = true;
            save_room($roomId, $room);
            write_status_file($room, 'card_flipped');
            respond(['ok' => true, 'flipped' => [$card], 'match' => [], 'mismatch' => [], 'pending' => [], 'current_turn' => $room['current_turn'], 'cards' => sanitized_cards($room['cards'])]);
        }

        // ---- Second flip: resolve the pair ----
        $first = $room['flip1'];
        $room['flip2'] = $card;
        $room['cards'][$card]['flipped'] = true;

        $sameShape = $room['cards'][$first]['shape'] === $room['cards'][$card]['shape'];

        if ($sameShape) {
            // Match: keep both face-up with a glow; same player goes again.
            $room['cards'][$first]['matched'] = true;
            $room['cards'][$card]['matched'] = true;
            $room['cards'][$first]['matched_by'] = $myRole;
            $room['cards'][$card]['matched_by'] = $myRole;
            $room['score'][$myRole] += 1;
            $room['flip1'] = null;
            $room['flip2'] = null;

            $matchedTotal = $room['score']['p1'] + $room['score']['p2'];
            if ($matchedTotal >= 4) {
                // All 4 pairs found -> game over, most pairs wins.
                $room['status'] = 'result';
                $room['winner'] = $room['score']['p1'] === $room['score']['p2'] ? 'draw'
                                : ($room['score']['p1'] > $room['score']['p2'] ? 'p1' : 'p2');
                $room['current_turn'] = '';
                $room['finished'] = true;
            }
            save_room($roomId, $room);
            write_status_file($room, 'card_match');
            if ($room['status'] === 'result') {
                write_status_file($room, 'game_finished');
            }
            respond(['ok' => true, 'flipped' => [$first, $card], 'match' => [$first, $card], 'mismatch' => [], 'winner' => $room['winner'] ?? '', 'pending' => [], 'current_turn' => $room['current_turn'], 'cards' => sanitized_cards($room['cards'])]);
        }

        // Mismatch: keep both cards face-up for the shared reveal window so the
        // OTHER player sees the same pair, then they flip back (resolve_pending).
        // The turn passes right away so both clients agree on whose turn it is.
        $room['cards'][$first]['flipped'] = true;
        $room['cards'][$card]['flipped'] = true;
        $room['flip1'] = $first;
        $room['flip2'] = $card;
        $room['pending'] = [$first, $card];
        $room['pending_until'] = microtime(true) + pending_reveal_seconds();
        $room['current_turn'] = $myRole === 'p1' ? 'p2' : 'p1';

        save_room($roomId, $room);
        write_status_file($room, 'card_mismatch');

        respond(['ok' => true, 'flipped' => [$first, $card], 'match' => [], 'mismatch' => [$first, $card], 'mismatch_shapes' => [$room['cards'][$first]['shape'], $room['cards'][$card]['shape']], 'pending' => $room['pending'], 'pending_remaining' => pending_reveal_seconds(), 'current_turn' => $room['current_turn'], 'cards' => sanitized_cards($room['cards'])]);
    }

    case 'next_round': {
        $roomId = $_REQUEST['room'] ?? '';
        $room = load_room($roomId);
        if (!$room) respond(['ok' => false, 'error' => 'room_not_found']);
        if (check_inactive_and_end($room, $roomId)) respond(['ok' => false, 'error' => 'match_ended', 'reason' => 'inactivity', 'state' => 'DEAD']);

        $room['round'] += 1;
        $room['status'] = count($room['players']) >= 2 ? 'playing' : 'waiting';
        if (!empty($room['finished'])) unset($room['finished']);
        $room['cards'] = fresh_board();
        $room['flip1'] = null;
        $room['flip2'] = null;
        $room['pending'] = [];
        $room['pending_until'] = 0;
        $room['current_turn'] = 'p1';
        $room['score'] = ['p1' => 0, 'p2' => 0];
        $room['winner'] = '';
        $room['end_reason'] = '';
        save_room($roomId, $room);
        write_status_file($room, 'play_again');

        respond(['ok' => true, 'round' => $room['round']]);
    }

    case 'end_game': {
        $roomId = $_REQUEST['room'] ?? '';
        // Ended before a room was opened: still mark the game dead (own status
        // file + MAIN hub stats) using the shared helper.
        if ($roomId === '') {
            mark_game_dead_status('memory', 'match_ended');
            respond(['ok' => true, 'room_id' => '', 'state' => 'DEAD']);
        }
        $room = load_room($roomId);
        if (!$room) respond(['ok' => false, 'error' => 'room_not_found']);
        endGameAndSync('memory', $roomId);
        respond(['ok' => true, 'room_id' => $roomId, 'state' => 'DEAD']);
    }

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
            respond(player_leave_room('memory', $roomId, $playerId, !empty($_REQUEST['soft'])));
        }
        respond(player_return_room($roomId, $playerId));
    }

    default:
        respond(['ok' => false, 'error' => 'unknown_action']);
}

?>

