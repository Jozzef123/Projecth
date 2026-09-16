<?php
/**
 * parent_backend.php
 * ------------------
 * Backend for Parent Mode (limits, stats, password check).
 * Uses the same file conventions as connect4_backend.php:
 *   - JSON settings file: parent_settings.json
 *   - Password file:      parent_password.txt (read server-side, never sent)
 *   - status/ folder and status_helper.php for marking games dead
 *
 * Actions:
 *   action=check        { password }                     -> { ok, correct }
 *   action=get          { password }                     -> settings + stats
 *   action=save         { password, limit_games, limit_days }
 *   action=reset        { password }                     -> no limit
 *   action=lock_status  { game }                         -> is the hub locked?
 *   action=game_opened  { game }                         -> count + auto-lock
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST');

$SETTINGS_PATH = __DIR__ . '/parent_settings.json';
$PASSWORD_PATH = __DIR__ . '/parent_password.txt';
$STATUS_DIR    = __DIR__ . '/status';

if (!is_dir($STATUS_DIR) && !mkdir($STATUS_DIR, 0777, true) && !is_dir($STATUS_DIR)) {
    error_log('Unable to create status directory: ' . $STATUS_DIR);
}

require_once __DIR__ . '/status_helper.php';
ensure_main_file();

// -------------------- Settings helpers --------------------

function default_settings() {
    return [
        'limit_games'  => 100,   // default limit when nothing has ever been set
        'limit_days'   => 0,     // 0 = no day limit
        'set_date'     => '',    // Y-m-d H:i:s when limits were last saved
        'games_played' => 0,     // games opened since the limit was set
        'notified'     => [],    // games already marked dead due to the limit
    ];
}

function load_settings($path) {
    if (!file_exists($path)) return default_settings();
    $data = json_decode((string)file_get_contents($path), true);
    if (!is_array($data)) return default_settings();
    return array_merge(default_settings(), $data);
}

function save_settings($path, $data) {
    $fp = fopen($path, 'c+');
    if (!$fp) {
        error_log('Unable to open settings file for writing: ' . $path);
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

function check_password($passwordPath, $given) {
    if (!file_exists($passwordPath)) return false;
    $real = trim((string)file_get_contents($passwordPath));
    return $real !== '' && hash_equals($real, (string)$given);
}

// Full days elapsed since the limit was set.
function days_since_set($setDate) {
    if (!$setDate) return 0;
    $ts = strtotime($setDate);
    if ($ts === false) return 0;
    return (int)floor((time() - $ts) / 86400);
}

// Which limit (if any) has been reached. Whichever is hit first triggers it.
function evaluate_limit($s) {
    $limitGames = (int)$s['limit_games'];
    $limitDays  = (int)$s['limit_days'];
    if ($limitGames <= 0 && $limitDays <= 0) {
        return ['locked' => false, 'limit_type' => 'none', 'limit_value' => 100,
                'games_remaining' => null, 'days_remaining' => null];
    }
    $played = (int)$s['games_played'];
    $days   = days_since_set($s['set_date']);

    $gamesHit = ($limitGames > 0) && ($played >= $limitGames);
    $daysHit  = ($limitDays  > 0) && ($days   >= $limitDays);

    if ($gamesHit || $daysHit) {
        // Whichever limit is hit first triggers the lock.
        $type  = $gamesHit ? 'games' : 'days';
        $value = $gamesHit ? $limitGames : $limitDays;
        return ['locked' => true, 'limit_type' => $type, 'limit_value' => $value,
                'games_remaining' => max(0, $limitGames - $played),
                'days_remaining'  => max(0, $limitDays - $days)];
    }

    return ['locked' => false,
            'limit_type' => ($limitGames > 0 ? 'games' : 'days'),
            'limit_value' => ($limitGames > 0 ? $limitGames : $limitDays),
            'games_remaining' => $limitGames > 0 ? max(0, $limitGames - $played) : null,
            'days_remaining'  => $limitDays  > 0 ? max(0, $limitDays - $days)   : null];
}

// Mark a game dead in its own status file AND the main hub stats, using the same
// helper/schema as the game backends (see status_helper.php). No new schema.
function mark_game_dead($game) {
    return mark_game_dead_status($game);
}

function respond($arr) {
    echo json_encode($arr, JSON_UNESCAPED_UNICODE);
    exit;
}

// -------------------- Router --------------------
$action = $_REQUEST['action'] ?? '';

switch ($action) {

    case 'check': {
        $password = $_REQUEST['password'] ?? '';
        if (!check_password($PASSWORD_PATH, $password)) {
            respond(['ok' => true, 'correct' => false, 'message' => 'Incorrect password']);
        }
        respond(['ok' => true, 'correct' => true]);
    }

    case 'get': {
        $password = $_REQUEST['password'] ?? '';
        if (!check_password($PASSWORD_PATH, $password)) {
            respond(['ok' => false, 'error' => 'Incorrect password']);
        }
        $s = load_settings($SETTINGS_PATH);
        $e = evaluate_limit($s);
        respond(['ok' => true,
                 'limit_type'       => $e['limit_type'],
                 'limit_value'      => $e['limit_value'],
                 'locked'           => $e['locked'],
                 'games_remaining'  => $e['games_remaining'],
                 'days_remaining'   => $e['days_remaining'],
                 'set_date'         => $s['set_date'],
                 'games_played'     => (int)$s['games_played'],
                 'dead_games'       => array_values($s['notified'])]);
    }

    case 'save': {
        $password = $_REQUEST['password'] ?? '';
        if (!check_password($PASSWORD_PATH, $password)) {
            respond(['ok' => false, 'error' => 'Incorrect password']);
        }
        $limitGames = max(0, (int)($_REQUEST['limit_games'] ?? 0));
        $limitDays  = max(0, (int)($_REQUEST['limit_days'] ?? 0));
        if ($limitGames === 0 && $limitDays === 0) {
            respond(['ok' => false, 'error' => 'Set at least one limit (games or days).']);
        }

        $s = load_settings($SETTINGS_PATH);
        $s['limit_games']  = $limitGames;
        $s['limit_days']   = $limitDays;
        $s['set_date']     = date('Y-m-d H:i:s', time());
        $s['games_played'] = 0;      // restart the counters for the new limit
        $s['notified']     = [];
        save_settings($SETTINGS_PATH, $s);
        respond(['ok' => true]);
    }

    case 'reset': {
        $password = $_REQUEST['password'] ?? '';
        if (!check_password($PASSWORD_PATH, $password)) {
            respond(['ok' => false, 'error' => 'Incorrect password']);
        }
        save_settings($SETTINGS_PATH, default_settings()); // 100-game default
        respond(['ok' => true]);
    }

    case 'lock_status': {
        // Called by every game page on open (no password needed - read only).
        $s = load_settings($SETTINGS_PATH);
        $e = evaluate_limit($s);
        respond(['ok' => true,
                 'locked'          => $e['locked'],
                 'limit_type'      => $e['limit_type'],
                 'limit_value'     => $e['limit_value'],
                 'games_remaining' => $e['games_remaining'],
                 'days_remaining'  => $e['days_remaining'],
                 'games_played'    => (int)$s['games_played']]);
    }

    case 'game_opened': {
        // Called once per game open. Increments the counter; if the limit is
        // reached, all games lock and every game is marked dead.
        $game = preg_replace('/[^A-Za-z0-9]/', '', $_REQUEST['game'] ?? '');
        $s = load_settings($SETTINGS_PATH);

        $eBefore = evaluate_limit($s);
        if ($eBefore['locked']) {
            respond(['ok' => true, 'locked' => true, 'limit_type' => $eBefore['limit_type'],
                     'limit_value' => $eBefore['limit_value'],
                     'games_remaining' => $eBefore['games_remaining'],
                     'days_remaining' => $eBefore['days_remaining']]);
        }

        $s['games_played'] = (int)$s['games_played'] + 1;
        $e = evaluate_limit($s);

        if ($e['locked']) {
            // Limit hit: mark ALL games dead (own status + main stats).
            $all = ['connect4', 'xo', 'yatzy', 'memory', 'rps', 'mathquiz', 'snakeladder'];
            $s['notified'] = $all;
            save_settings($SETTINGS_PATH, $s);
            foreach ($all as $g) { mark_game_dead($g); }
        } else {
            save_settings($SETTINGS_PATH, $s);
        }

        respond(['ok' => true, 'locked' => $e['locked'], 'limit_type' => $e['limit_type'],
                 'limit_value' => $e['limit_value'],
                 'games_remaining' => $e['games_remaining'],
                 'days_remaining' => $e['days_remaining']]);
    }

    default:
        respond(['ok' => false, 'error' => 'unknown_action']);
}
