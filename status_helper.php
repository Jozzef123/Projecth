<?php
// status_helper.php
// Shared helper to maintain a single master status file: status/main.txt

function ensure_status_dir() {
    $dir = __DIR__ . '/status';
    if (!is_dir($dir)) mkdir($dir, 0777, true);
    return $dir;
}

function ensure_main_file() {
    $dir = ensure_status_dir();
    $path = $dir . '/main.txt';
    if (!file_exists($path)) {
        $line = 'state=DEAD;game=;time=' . date('Y-m-d H:i:s', time()) . PHP_EOL;
        file_put_contents($path, $line, LOCK_EX);
    }
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
    $dir = ensure_status_dir();
    $mainPath = ensure_main_file();

    // If the caller reports ALIVE for its game, that becomes the spotlight immediately.
    if (strtoupper($state) === 'ALIVE') {
        $line = 'state=ALIVE;game=' . $game . ';time=' . date('Y-m-d H:i:s', time());
        $fp = fopen($mainPath, 'w');
        if ($fp) {
            flock($fp, LOCK_EX);
            fwrite($fp, $line . PHP_EOL);
            fflush($fp);
            flock($fp, LOCK_UN);
            fclose($fp);
        }
        return true;
    }

    // Caller reports DEAD for its game: we must determine if any other game is ALIVE.
    $games = ['rps','xo','connect4','mathquiz','snakeladder','yatzy'];
    $latestGame = '';
    $latestTime = 0;

    foreach ($games as $g) {
        // skip the caller's own per-game file when checking others
        if ($g === $game) continue;
        $path = $dir . '/' . $g . '.txt';
        if (!file_exists($path)) continue;
        $contents = file_get_contents($path);
        if (!$contents) continue;
        $line = explode("\n", $contents)[0];
        $data = parse_status_line($line);
        $s = strtoupper($data['state'] ?? 'DEAD');
        $t = $data['time'] ?? '';
        $ts = 0;
        if ($t) {
            $ts = strtotime($t);
        } else {
            $ts = filemtime($path);
        }
        if ($s === 'ALIVE') {
            if ($ts > $latestTime) { $latestTime = $ts; $latestGame = $g; }
        }
    }

    $outState = 'DEAD';
    $outGame = '';
    if ($latestGame !== '') {
        $outState = 'ALIVE';
        $outGame = $latestGame;
    }

    $line = 'state=' . $outState . ';game=' . $outGame . ';time=' . date('Y-m-d H:i:s', time());
    $fp = fopen($mainPath, 'c+');
    if ($fp) {
        flock($fp, LOCK_EX);
        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, $line . PHP_EOL);
        fflush($fp);
        flock($fp, LOCK_UN);
        fclose($fp);
    }
    return true;
}

?>