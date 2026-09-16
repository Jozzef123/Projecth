<?php
// status_helper.php
// Shared helper to maintain a single master status file: status/main.txt
// (schema: state=ALIVE|DEAD;game=<game-id|none>;time=YYYY-MM-DD HH:MM:SS).
//
// main.txt is a spotlight of the game the hub / device should show:
//   - a game counts as running only while its OWN status line says ALIVE and
//     is recent (within the inactivity timeout);
//   - a stale ALIVE line is never resurrected (this fixes main.txt randomly
//     switching to an old game after an End Game);
//   - when no game is running: state=DEAD;game=none;time=...
//
// Every backend calls ensure_main_file() on every request, so the shared
// inactivity sweep runs on every status read/write (throttled internally).

// ------------------------------------------------------------------
// Configurable inactivity timeout (seconds). Default: 3 minutes (180).
// Override without code changes - create status_config.php next to this file:
//   <?php
//   $game_inactivity_timeout_seconds = 180;             // all games
//   $game_inactivity_timeout_map = ['memory' => 120];   // optional, per game
// Delete the file to fall back to each backend's own constant (also 180).
// ------------------------------------------------------------------
$game_inactivity_timeout_map = [];
if (file_exists(__DIR__ . '/status_config.php')) {
    include __DIR__ . '/status_config.php';
}

function ensure_status_dir() {
    $dir = __DIR__ . '/status';
    if (is_dir($dir)) return $dir;
    if (!mkdir($dir, 0777, true) && !is_dir($dir)) {
        error_log('Unable to create status directory: ' . $dir);
        return false;
    }
    return $dir;
}

// The shared inactivity timeout for a game. Precedence: status_config.php
// (scalar or per-game map) > the backend's own $INACTIVITY_TIMEOUT_SECONDS
// constant > the built-in default of 180 seconds.
function game_inactivity_timeout_seconds($game = '') {
    global $game_inactivity_timeout_seconds;
    global $game_inactivity_timeout_map;
    global $INACTIVITY_TIMEOUT_SECONDS;

    if (isset($game_inactivity_timeout_map) && is_array($game_inactivity_timeout_map)) {
        $g = preg_replace('/[^A-Za-z0-9]/', '', (string)$game);
        if ($g !== '' && isset($game_inactivity_timeout_map[$g])) {
            $v = (int)$game_inactivity_timeout_map[$g];
            if ($v > 0) return $v;
        }
    }
    if (isset($game_inactivity_timeout_seconds)) {
        $v = (int)$game_inactivity_timeout_seconds;
        if ($v > 0) return $v;
    }
    if (isset($INACTIVITY_TIMEOUT_SECONDS)) {
        $v = (int)$INACTIVITY_TIMEOUT_SECONDS;
        if ($v > 0) return $v;
    }
    return 180;
}

// Creates main.txt when missing and repairs an EMPTY one. Never leaves
// main.txt missing or blank: the seed is the explicit "nothing running" state.
function ensure_main_path() {
    $dir = ensure_status_dir();
    if ($dir === false) return false;
    $path = $dir . '/main.txt';
    if (!file_exists($path) || trim((string)@file_get_contents($path)) === '') {
        $line = 'state=DEAD;game=none;time=' . date('Y-m-d H:i:s', time()) . PHP_EOL;
        if (file_put_contents($path, $line, LOCK_EX) === false) {
            error_log('Unable to create main status file: ' . $path);
            return false;
        }
    }
    return $path;
}

// Called by every backend on every request: guarantees main.txt exists and
// runs the shared stale-room sweep (throttled inside sweep_all_games()).
function ensure_main_file() {
    $path = ensure_main_path();
    if ($path === false) return false;
    sweep_all_games();
    return $path;
}

function parse_status_line($line) {
    $parts = explode(';', $line);
    $out = [];
    foreach ($parts as $p) {
        $kv = explode('=', $p, 2);
        if (count($kv) === 2) $out[trim($kv[0])] = trim($kv[1]);
    }
    return $out;
}

function update_main_status($game, $state) {
    // A game reporting ALIVE becomes the spotlight immediately: it is emitting
    // real events right now, so its own status file is fresh by definition.
    if (strtoupper($state) === 'ALIVE') {
        return write_main_status_line('ALIVE', $game);
    }

    // A game reporting DEAD must NEVER resurrect a stale ALIVE file. (The old
    // code scanned the other games' files and promoted any that still said
    // ALIVE, even days later - that is what made main.txt randomly switch to
    // an old game after End Game.) Recompute instead: only games whose own
    // status line is state=ALIVE AND recent (within the inactivity timeout)
    // count as running; otherwise the file explicitly says game=none.
    return recompute_main_status();
}

// The fixed game list shared by the sweep, the recompute and the helpers.
function status_known_games() {
    return ['rps', 'xo', 'connect4', 'mathquiz', 'snakeladder', 'yatzy', 'memory'];
}

// The single source of truth for "is any game running": scans every game's
// own status file and spotlights the most recently active FRESH ALIVE game.
function recompute_main_status() {
    $dir = ensure_status_dir();
    if ($dir === false) return false;

    $latestGame = '';
    $latestTime = 0;
    foreach (status_known_games() as $g) {
        $path = $dir . '/' . $g . '.txt';
        if (!file_exists($path)) continue;
        $contents = @file_get_contents($path);
        if ($contents === false || trim($contents) === '') continue;
        $data = parse_status_line(explode("\n", $contents)[0]);
        if (strtoupper($data['state'] ?? 'DEAD') !== 'ALIVE') continue;

        // Freshness: the line's own time field, falling back to file mtime.
        $ts = 0;
        $t = $data['time'] ?? '';
        if ($t !== '') {
            $parsedTime = strtotime($t);
            $ts = $parsedTime === false ? 0 : $parsedTime;
        }
        if ($ts === 0) {
            $modifiedTime = @filemtime($path);
            $ts = $modifiedTime === false ? 0 : $modifiedTime;
        }
        if ($ts <= 0) continue;
        // A stale ALIVE line (idle longer than the timeout) is NOT a running
        // game - the sweep will mark it DEAD on the next pass anyway.
        if ((time() - $ts) > game_inactivity_timeout_seconds($g)) continue;

        if ($ts > $latestTime) { $latestTime = $ts; $latestGame = $g; }
    }

    if ($latestGame !== '') {
        return write_main_status_line('ALIVE', $latestGame);
    }
    return write_main_status_line('DEAD', 'none');
}

// Atomic write of the master status line (same flock pattern as before,
// trailing newline kept - consumers split on ';' and '=' and read by index).
function write_main_status_line($state, $game) {
    $mainPath = ensure_main_path();
    if ($mainPath === false) return false;
    $line = 'state=' . $state . ';game=' . $game . ';time=' . date('Y-m-d H:i:s', time());
    $fp = fopen($mainPath, 'c+');
    if (!$fp) {
        error_log('Unable to write main status file: ' . $mainPath);
        return false;
    }
    flock($fp, LOCK_EX);
    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, $line . PHP_EOL);
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
    return true;
}

/**
 * endGameAndSync
 * --------------
 * Marks a room as ended (finished / status=ended) and updates BOTH the game's
 * own status file AND the master main status file in a single call.
 *
 * Relies on the calling backend's load_room / save_room / write_status_file
 * (resolved at call-time), so it works unchanged for every game.
 */
function endGameAndSync($gameId, $roomId) {
    $room = load_room($roomId);
    if (!$room) return false;

    $room['finished'] = true;
    $room['status'] = 'ended';
    $room['end_reason'] = 'manual';
    $room['ended_at'] = time();

    save_room($roomId, $room);
    write_status_file($room, 'match_ended');
    return true;
}

/**
 * mark_game_dead_status
 * ---------------------
 * Marks a game dead WITHOUT a room (used when the Parent Mode limit is reached
 * or when a player ends a game before a room exists). Updates BOTH the game's
 * own status file (status/<game>.txt) and the MAIN hub stats (status/main.txt).
 *
 * The game's existing status line is kept field for field and in the same order
 * (the same schema that game's backend writes); only state, event and time are
 * overwritten. If the game has no status line yet, the shared header
 * state/game/room/event/time is used.
 */
function mark_game_dead_status($game, $event = 'limit_reached') {
    $dir = ensure_status_dir();
    if ($dir === false) return false;

    $safe = preg_replace('/[^A-Za-z0-9]/', '', $game);
    if ($safe === '') return false;

    $path = $dir . '/' . $safe . '.txt';
    $fields = [];
    if (file_exists($path)) {
        $contents = @file_get_contents($path);
        if ($contents !== false && trim($contents) !== '') {
            $fields = parse_status_line(explode("\n", $contents)[0]);
        }
    }
    if (empty($fields)) {
        $fields = ['state' => '', 'game' => $safe, 'room' => '', 'event' => ''];
    }
    $fields['state'] = 'DEAD';
    $fields['event'] = str_replace(["\r", "\n", ";"], '', $event);
    $fields['time'] = date('Y-m-d H:i:s', time());

    $parts = [];
    foreach ($fields as $key => $value) {
        $parts[] = $key . '=' . $value;
    }

    $fp = fopen($path, 'c+');
    if (!$fp) {
        error_log('Unable to write game status file: ' . $path);
        return false;
    }
    flock($fp, LOCK_EX);
    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, implode(';', $parts));
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);

    // MAIN hub stats: same helper the game backends use.
    update_main_status($safe, 'DEAD');
    return true;
}

/**
 * Inactivity helpers (Part 3).
 * last_activity is bumped on every real action via save_room(); automatic
 * status polls do NOT count as activity.
 */
function get_room_activity_time($room, $roomPath) {
    if (isset($room['last_activity'])) return (int)$room['last_activity'];
    $m = @filemtime($roomPath);
    return $m ? $m : time();
}

function is_room_inactive($room, $roomPath, $timeoutSeconds) {
    if (!empty($room['finished']) && $room['finished']) return false; // already dead
    return (time() - get_room_activity_time($room, $roomPath)) > $timeoutSeconds;
}

// -------------------- Generic room JSON I/O (for the shared sweep) ----------
// Same directory / sanitisation / flock patterns as the per-game backends, so
// the shared helpers never depend on which backend is executing right now.
function sweep_room_path($roomId) {
    $safe = preg_replace('/[^A-Za-z0-9]/', '', (string)$roomId);
    if ($safe === '') return '';
    return __DIR__ . '/rooms/' . $safe . '.json';
}

function sweep_room_load($roomId) {
    $path = sweep_room_path($roomId);
    if ($path === '' || !file_exists($path)) return null;
    $fp = fopen($path, 'r');
    if (!$fp) return null;
    flock($fp, LOCK_SH);
    $data = json_decode(stream_get_contents($fp), true);
    flock($fp, LOCK_UN);
    fclose($fp);
    return is_array($data) ? $data : null;
}

function sweep_room_save($roomId, $data) {
    $path = sweep_room_path($roomId);
    if ($path === '') return false;
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

// Normalised left-state for every player, supporting both room shapes:
//   array-style  (connect4, xo, memory, rps, mathquiz, snakeladder):
//                 players[pid] = ['role' => ..., 'left' => ..., ...]
//   string-style (yatzy): players[pid] = 'p1' with the flags in the parallel
//                 room maps room['left'] / room['left_soft'] (the game reads
//                 the role string directly, so it must not be turned into an
//                 array).
function sweep_player_left_state($room) {
    $players = is_array($room['players'] ?? null) ? $room['players'] : [];
    $out = [];
    foreach ($players as $pid => $p) {
        if (is_array($p)) {
            $out[$pid] = ['left' => !empty($p['left']), 'soft' => !empty($p['left_soft'])];
        } else {
            $out[$pid] = ['left' => !empty($room['left'][$pid]), 'soft' => !empty($room['left_soft'][$pid])];
        }
    }
    return $out;
}

// Every player has hard-left (closed the tab / navigated away)? Soft leaves
// (tab merely hidden) do not count - the inactivity timeout covers them.
function sweep_all_players_hard_left($room) {
    $state = sweep_player_left_state($room);
    if (count($state) === 0) return false;
    foreach ($state as $p) {
        if (!$p['left'] || $p['soft']) return false;
    }
    return true;
}

// Marks a room finished (shared body for the sweep and the leave action).
// Writes the game's own status file WITHOUT touching game-specific fields
// (mark_game_dead_status keeps them; only state/event/time are rewritten),
// so the ARM device contract (fixed field order) stays intact.
function sweep_finish_room($roomId, $room, $game, $reason, $event) {
    $room['finished'] = true;
    $room['status'] = 'ended';
    $room['end_reason'] = $reason;
    $room['ended_at'] = time();
    sweep_room_save($roomId, $room);
    mark_game_dead_status($game, $event);
}

// -------------------- Shared inactivity sweep (all games) -------------------
/**
 * Global cleanup, run by ensure_main_file() on every status read/write and
 * therefore reachable even when no game tab is open (any backend request
 * triggers it - hub, Parent Mode, device polls, anything). Throttled to one
 * full scan per 60 s with a lock file, same pattern as the per-game
 * cleanup_rooms() sweeps.
 *
 * For every room of a known game:
 *   - all players hard-left              -> DEAD immediately (all_players_left)
 *   - no move/click/update for timeout   -> DEAD            (inactivity_timeout)
 * Rooms without a known 'game' field (created before leave detection existed)
 * are skipped - their status lines go stale and the freshness rule in
 * recompute_main_status() keeps main.txt correct regardless.
 */
function sweep_all_games() {
    $roomsDir = __DIR__ . '/rooms';
    if (!is_dir($roomsDir)) return false;

    $lock = $roomsDir . '/.cleanup_sweep.lock';
    $mt = @filemtime($lock);
    if ($mt !== false && (time() - $mt) < 60) return false;
    @touch($lock);

    $known = status_known_games();
    $files = glob($roomsDir . '/*.json');
    if (is_array($files)) {
        foreach ($files as $file) {
            $roomId = basename($file, '.json');
            $room = @json_decode(@file_get_contents($file), true);
            if (!is_array($room)) continue;
            $game = preg_replace('/[^A-Za-z0-9]/', '', (string)($room['game'] ?? ''));
            if ($game === '' || !in_array($game, $known, true)) continue;
            if (!empty($room['finished']) && $room['finished']) continue;

            if (sweep_all_players_hard_left($room)) {
                sweep_finish_room($roomId, $room, $game, 'all_players_left', 'all_players_left');
                continue;
            }

            $timeout = game_inactivity_timeout_seconds($game);
            if (is_room_inactive($room, $file, $timeout)) {
                sweep_finish_room($roomId, $room, $game, 'inactivity', 'inactivity_timeout');
            }
        }
    }

    // One final recompute so main.txt reflects reality after the sweep.
    recompute_main_status();
    return true;
}

// -------------------- Player leave / return (leave detection) ---------------
/**
 * Records that a player left the room.
 *   hard leave (soft=false): pagehide / beforeunload - tab closed or navigated
 *                            away. When EVERY player has hard-left, the room
 *                            is ended IMMEDIATELY (event all_players_left),
 *                            no inactivity wait.
 *   soft leave (soft=true):  visibilitychange - tab/app hidden. Recorded so
 *                            the other player (and the sweep) can see it, but
 *                            it does not kill the room by itself; the normal
 *                            inactivity timeout is the backstop.
 * One player left only: the room stays alive for the other player.
 */
function player_leave_room($game, $roomId, $playerId, $soft) {
    $room = sweep_room_load($roomId);
    if (!$room) return ['ok' => false, 'error' => 'room_not_found'];
    if (!empty($room['finished'])) {
        return ['ok' => true, 'room_id' => $roomId, 'state' => 'DEAD', 'end_reason' => $room['end_reason'] ?? ''];
    }
    if (!isset($room['players'][$playerId])) {
        return ['ok' => true, 'room_id' => $roomId, 'state' => 'ALIVE', 'noop' => true];
    }

    if (is_array($room['players'][$playerId])) {
        $room['players'][$playerId]['left'] = 1;
        $room['players'][$playerId]['left_at'] = time();
        $room['players'][$playerId]['left_soft'] = $soft ? 1 : 0;
    } else {
        // yatzy-style rooms store the role string directly; the leave flags
        // live in parallel maps so the game data stays untouched.
        $room['left'][$playerId] = 1;
        $room['left_at'][$playerId] = time();
        if ($soft) {
            $room['left_soft'][$playerId] = 1;
        } else {
            unset($room['left_soft'][$playerId]);
        }
    }

    if (sweep_all_players_hard_left($room)) {
        sweep_finish_room($roomId, $room, $game, 'all_players_left', 'all_players_left');
        return ['ok' => true, 'room_id' => $roomId, 'state' => 'DEAD', 'end_reason' => 'all_players_left'];
    }

    $room['last_activity'] = time();
    sweep_room_save($roomId, $room);
    return ['ok' => true, 'room_id' => $roomId, 'state' => 'ALIVE', 'left_soft' => $soft ? 1 : 0];
}

// A player who previously left is back (tab visible again / re-joined). The
// left flag is cleared so the room can keep living for both players.
function player_return_room($roomId, $playerId) {
    $room = sweep_room_load($roomId);
    if (!$room) return ['ok' => false, 'error' => 'room_not_found'];
    if (!empty($room['finished'])) {
        return ['ok' => true, 'room_id' => $roomId, 'state' => 'DEAD', 'end_reason' => $room['end_reason'] ?? ''];
    }
    if (isset($room['players'][$playerId])) {
        $cleared = false;
        if (is_array($room['players'][$playerId]) && !empty($room['players'][$playerId]['left'])) {
            $room['players'][$playerId]['left'] = 0;
            unset($room['players'][$playerId]['left_at']);
            unset($room['players'][$playerId]['left_soft']);
            $cleared = true;
        }
        if (!empty($room['left'][$playerId])) {
            unset($room['left'][$playerId], $room['left_at'][$playerId], $room['left_soft'][$playerId]);
            $cleared = true;
        }
        if ($cleared) {
            $room['last_activity'] = time();
            sweep_room_save($roomId, $room);
        }
    }
    return ['ok' => true, 'room_id' => $roomId, 'state' => 'ALIVE'];
}

?>