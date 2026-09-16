<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
/**
 * ota_backend.php
 * ---------------
 * Firmware (OTA) publishing backend for the IoT Game Monitor's updater.
 *
 * The device polls status/ota.txt at boot and between games and expects exactly
 * one line in this fixed field order (parsed by index, like the game status
 * lines documented in rps_backend.php):
 *
 *   version=<int>;url=<path-to-hex-file>;crc32=<hex>;size=<int>
 *
 * Example:  version=4;url=/firmware/fw_v4.hex;crc32=3610a686;size=24190
 *
 * Uploading and publishing are deliberately two separate steps:
 *   upload   -> stores firmware/fw_v<N>.hex + a metadata record in firmware_index.json
 *   activate -> writes status/ota.txt (the only writer of that file)
 *
 * CRC32 and size are ALWAYS computed server-side from the uploaded bytes and are
 * never accepted from the request; activate publishes the upload-time values.
 *
 * Actions: upload | list | activate | status
 *   - upload / list / activate require the Parent Mode password
 *     (parent_password.txt, checked server-side - same check as parent_backend.php)
 *   - status is open: it only reports what status/ota.txt already publishes
 *     anonymously to the device.
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST');

$FIRMWARE_DIR    = __DIR__ . '/firmware';
$STATUS_DIR      = __DIR__ . '/status';
$INDEX_PATH      = $FIRMWARE_DIR . '/firmware_index.json';
$OTA_STATUS_PATH = $STATUS_DIR . '/ota.txt';
$PASSWORD_PATH   = __DIR__ . '/parent_password.txt';

// Where the published url points. The device fetches this with a plain HTTP GET,
// so it is a path on this site (leading slash = path from the web root).
// See OTA_WEBSITE_NOTES.md if your firmware expects a different shape.
$OTA_URL_BASE = '/firmware/';

// Structural upload cap (a .hex for a 256 KB F401CC is ~0.7 MiB).
$OTA_MAX_HEX_BYTES = 4 * 1024 * 1024;

if (!is_dir($FIRMWARE_DIR) && !mkdir($FIRMWARE_DIR, 0777, true) && !is_dir($FIRMWARE_DIR)) {
    error_log('Unable to create firmware directory: ' . $FIRMWARE_DIR);
}

// Ensure the status directory exists (same as the game backends do)
if (!is_dir($STATUS_DIR) && !mkdir($STATUS_DIR, 0777, true) && !is_dir($STATUS_DIR)) {
    error_log('Unable to create status directory: ' . $STATUS_DIR);
}

// -------------------- Helpers --------------------

function respond($arr) {
    echo json_encode($arr, JSON_UNESCAPED_UNICODE);
    exit;
}

// Same check as parent_backend.php: the password lives in a file, never in JS.
function check_ota_password($passwordPath, $given) {
    if (!file_exists($passwordPath)) return false;
    $real = trim((string)file_get_contents($passwordPath));
    return $real !== '' && hash_equals($real, (string)$given);
}

function ota_index_load($path) {
    if (!file_exists($path)) return [];
    $fp = fopen($path, 'r');
    if (!$fp) return [];
    flock($fp, LOCK_SH);
    $data = json_decode((string)stream_get_contents($fp), true);
    flock($fp, LOCK_UN);
    fclose($fp);
    return is_array($data) ? $data : [];
}

function ota_index_save($path, $records) {
    $fp = fopen($path, 'c+');
    if (!$fp) {
        error_log('Unable to open firmware index for writing: ' . $path);
        return false;
    }
    flock($fp, LOCK_EX);
    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, json_encode($records, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
    return true;
}

function ota_safe_name($name) {
    return preg_replace('/[^A-Za-z0-9._-]/', '', basename((string)$name));
}

// The version currently published, or 0 when nothing has been activated.
function ota_live_version($records) {
    foreach ($records as $r) {
        if (!empty($r['active'])) return (int)$r['version'];
    }
    return 0;
}

// -------------------- Router --------------------
/**
 * Intel HEX structural sanity check.
 * Catches obviously wrong uploads (.bin, .elf, images, truncated files) - full
 * parsing and flash layout stay the firmware's job. Returns null when the file
 * looks like Intel HEX, otherwise a human-readable reason.
 */
function ota_hex_problem($raw) {
    $lines = preg_split('/\r\n|\n|\r/', (string)$raw);
    $dataRecords = 0;
    $eofRecords = 0;

    foreach ($lines as $i => $line) {
        $line = trim($line);
        if ($line === '') continue;              // trailing blank lines are fine
        $n = $i + 1;

        if ($line[0] !== ':') {
            return 'line ' . $n . ' does not start with ":"';
        }
        $hex = substr($line, 1);
        if ($hex === '' || strlen($hex) % 2 !== 0) {
            return 'line ' . $n . ' has an odd number of hex digits';
        }
        if (!ctype_xdigit($hex)) {
            return 'line ' . $n . ' contains non-hex characters';
        }
        if (strlen($hex) < 10) {
            return 'line ' . $n . ' is too short to be a HEX record';
        }

        $bytes = str_split($hex, 2);
        $count = hexdec($bytes[0]);
        if (count($bytes) !== $count + 5) {
            return 'line ' . $n . ' byte count (' . $count . ') does not match its length';
        }
        $sum = 0;
        foreach ($bytes as $b) { $sum += hexdec($b); }
        if ($sum % 256 !== 0) {
            return 'line ' . $n . ' has an invalid checksum';
        }

        $type = hexdec($bytes[3]);
        if ($type === 0) $dataRecords++;
        if ($type === 1) $eofRecords++;
    }

    if ($dataRecords === 0) {
        return 'no data records (type 00) found - this does not look like firmware';
    }
    if ($eofRecords === 0) {
        return 'missing the end-of-file record (:00000001FF) - the file looks truncated';
    }
    return null;
}

/**
 * Writes status/ota.txt - the single line the device polls for:
 *
 *   version=<int>;url=<path>;crc32=<hex>;size=<int>
 *
 * Exactly four fields, in that order, no trailing newline, written atomically
 * with the same lock pattern the game backends use for their status files.
 * This is the ONLY function that writes status/ota.txt.
 */
function write_ota_status($path, $version, $url, $crc32, $size) {
    $parts = [];
    $parts[] = 'version=' . (int)$version;
    $parts[] = 'url=' . str_replace(["\r", "\n", ";"], '', (string)$url);
    $parts[] = 'crc32=' . str_replace(["\r", "\n", ";"], '', (string)$crc32);
    $parts[] = 'size=' . (int)$size;
    $line = implode(';', $parts);

    $fp = fopen($path, 'c+');
    if (!$fp) {
        error_log('Unable to open OTA status file for writing: ' . $path);
        return false;
    }
    flock($fp, LOCK_EX);
    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, $line);
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
    return true;
}

$action = $_REQUEST['action'] ?? '';

switch ($action) {

    // ============ UPLOAD (store + metadata only, never publishes) ============
    case 'upload': {
        $password = $_REQUEST['password'] ?? '';
        if (!check_ota_password($PASSWORD_PATH, $password)) {
            respond(['ok' => false, 'error' => 'Incorrect password']);
        }

        if (!isset($_FILES['firmware'])) {
            respond(['ok' => false, 'error' => 'No file received. The form field must be named "firmware".']);
        }
        $file = $_FILES['firmware'];
        $err = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($err !== UPLOAD_ERR_OK) {
            $messages = [
                UPLOAD_ERR_INI_SIZE   => 'the file is larger than the server allows (upload_max_filesize)',
                UPLOAD_ERR_FORM_SIZE  => 'the file is larger than the form allows',
                UPLOAD_ERR_PARTIAL    => 'the upload was interrupted',
                UPLOAD_ERR_NO_FILE    => 'no file was selected',
                UPLOAD_ERR_NO_TMP_DIR => 'the server has no upload temp directory',
                UPLOAD_ERR_CANT_WRITE => 'the server could not write the uploaded file',
            ];
            respond(['ok' => false, 'error' => 'Upload failed: ' . ($messages[$err] ?? ('PHP error code ' . $err))]);
        }

        $original = ota_safe_name($file['name'] ?? 'firmware.hex');
        if (strtolower(pathinfo($original, PATHINFO_EXTENSION)) !== 'hex') {
            respond(['ok' => false, 'error' => 'Only .hex files are accepted (received "' . $original . '").']);
        }

        $size = (int)($file['size'] ?? 0);
        if ($size <= 0) {
            respond(['ok' => false, 'error' => 'The uploaded file is empty.']);
        }
        if ($size > $OTA_MAX_HEX_BYTES) {
            respond(['ok' => false, 'error' => 'File too large (' . $size . ' bytes); the limit is ' . $OTA_MAX_HEX_BYTES . ' bytes.']);
        }
        if (!is_uploaded_file($file['tmp_name'])) {
            respond(['ok' => false, 'error' => 'Invalid upload.']);
        }

        $raw = file_get_contents($file['tmp_name']);
        if ($raw === false) {
            respond(['ok' => false, 'error' => 'Could not read the uploaded file.']);
        }

        // Intel HEX sanity check (structure only - the firmware does the real parsing)
        $problem = ota_hex_problem($raw);
        if ($problem !== null) {
            respond(['ok' => false, 'error' => 'Not a valid Intel HEX file: ' . $problem . '.']);
        }

        // Metadata is computed HERE, server-side, from the uploaded bytes.
        $crc32 = sprintf('%08x', crc32($raw));
        $bytes = strlen($raw);

        $records = ota_index_load($INDEX_PATH);

        // Version: explicit from the admin, otherwise the next free number.
        $requested = trim((string)($_REQUEST['version'] ?? ''));
        if ($requested !== '') {
            if (!ctype_digit($requested) || (int)$requested <= 0) {
                respond(['ok' => false, 'error' => 'Version must be a positive whole number.']);
            }
            $version = (int)$requested;
            foreach ($records as $r) {
                if ((int)($r['version'] ?? 0) === $version) {
                    respond(['ok' => false, 'error' => 'Version ' . $version . ' already exists. Pick another version or leave the field empty for the next free number.']);
                }
            }
        } else {
            $version = 1;
            foreach ($records as $r) {
                if ((int)($r['version'] ?? 0) >= $version) $version = (int)$r['version'] + 1;
            }
        }

        $storedName = 'fw_v' . $version . '.hex';
        $dest = $FIRMWARE_DIR . '/' . $storedName;

        if (!move_uploaded_file($file['tmp_name'], $dest)) {
            // Some hosts restrict move_uploaded_file; fall back to a plain copy.
            if (@copy($file['tmp_name'], $dest) === false) {
                respond(['ok' => false, 'error' => 'Could not store the firmware file on the server.']);
            }
            @unlink($file['tmp_name']);
        }

        // Verify what actually landed on disk before recording it.
        $storedRaw = @file_get_contents($dest);
        if ($storedRaw === false || sprintf('%08x', crc32($storedRaw)) !== $crc32) {
            @unlink($dest);
            respond(['ok' => false, 'error' => 'The stored file does not match the upload (CRC32 mismatch). Nothing was saved.']);
        }

        $records[] = [
            'version'       => $version,
            'file'          => $storedName,
            'original_name' => $original,
            'uploaded_at'   => date('Y-m-d H:i:s', time()),
            'crc32'         => $crc32,
            'size'          => $bytes,
            'active'        => false,
            'activated_at'  => '',
        ];
        if (!ota_index_save($INDEX_PATH, $records)) {
            respond(['ok' => false, 'error' => 'The file was stored but the metadata index could not be written.']);
        }

        respond(['ok' => true, 'version' => $version, 'file' => $storedName,
                 'original_name' => $original, 'crc32' => $crc32, 'size' => $bytes,
                 'url' => $OTA_URL_BASE . $storedName,
                 'message' => 'Version ' . $version . ' uploaded. It is NOT live yet - press Activate to publish it.']);
    }
    // ============ LIST (upload history for the admin UI) ============
    case 'list': {
        $password = $_REQUEST['password'] ?? '';
        if (!check_ota_password($PASSWORD_PATH, $password)) {
            respond(['ok' => false, 'error' => 'Incorrect password']);
        }

        $records = ota_index_load($INDEX_PATH);
        usort($records, function ($a, $b) {
            return (int)($b['version'] ?? 0) - (int)($a['version'] ?? 0);   // newest first
        });

        respond(['ok' => true, 'live_version' => ota_live_version($records), 'records' => $records]);
    }

    // ============ ACTIVATE (publish: writes status/ota.txt) ============
    case 'activate': {
        $password = $_REQUEST['password'] ?? '';
        if (!check_ota_password($PASSWORD_PATH, $password)) {
            respond(['ok' => false, 'error' => 'Incorrect password']);
        }

        $version = (int)($_REQUEST['version'] ?? 0);
        if ($version <= 0) {
            respond(['ok' => false, 'error' => 'Missing or invalid version.']);
        }

        $records = ota_index_load($INDEX_PATH);
        $target = -1;
        foreach ($records as $i => $r) {
            if ((int)($r['version'] ?? 0) === $version) $target = $i;
        }
        if ($target < 0) {
            respond(['ok' => false, 'error' => 'Unknown firmware version ' . $version . '. Upload it first.']);
        }

        // Safety gate: publishing an older/equal version is a deliberate rollback
        // and must be confirmed explicitly by the admin.
        $live = ota_live_version($records);
        $isRollback = ($live > 0 && $version <= $live);
        $confirmed = (string)($_REQUEST['confirm'] ?? '') === '1';
        if ($isRollback && !$confirmed) {
            respond(['ok' => false,
                     'error' => 'Version ' . $version . ' is not newer than the live version ' . $live . '.',
                     'needs_confirm' => true,
                     'current_version' => $live,
                     'requested_version' => $version]);
        }

        // The published file must still be exactly what was uploaded.
        $fileName = ota_safe_name($records[$target]['file'] ?? '');
        $path = $FIRMWARE_DIR . '/' . $fileName;
        if ($fileName === '' || !file_exists($path)) {
            respond(['ok' => false, 'error' => 'The stored .hex file for version ' . $version . ' is missing; upload it again.']);
        }

        // Always publish the crc32/size computed at upload time - never a value
        // from this request and never a lazily recomputed one.
        $crc32 = (string)$records[$target]['crc32'];
        $size = (int)$records[$target]['size'];
        $storedRaw = @file_get_contents($path);
        if ($storedRaw === false
            || sprintf('%08x', crc32($storedRaw)) !== $crc32
            || strlen($storedRaw) !== $size) {
            respond(['ok' => false, 'error' => 'The stored file no longer matches its upload metadata (crc32 ' . $crc32 . '). Refusing to publish - upload it again.']);
        }

        $url = $OTA_URL_BASE . $fileName;
        if (!write_ota_status($OTA_STATUS_PATH, $version, $url, $crc32, $size)) {
            respond(['ok' => false, 'error' => 'Could not write status/ota.txt on the server.']);
        }

        // Exactly one record stays active.
        foreach ($records as $i => $r) {
            $records[$i]['active'] = ($i === $target);
        }
        $records[$target]['activated_at'] = date('Y-m-d H:i:s', time());
        ota_index_save($INDEX_PATH, $records);

        respond(['ok' => true,
                 'version' => $version,
                 'url' => $url,
                 'crc32' => $crc32,
                 'size' => $size,
                 'rolled_back' => $isRollback,
                 'ota_txt' => 'version=' . $version . ';url=' . $url . ';crc32=' . $crc32 . ';size=' . $size,
                 'message' => 'Version ' . $version . ' is now live.']);
    }
// ============ STATUS (open: reports what the device already sees) ============
    case 'status': {
        $records = ota_index_load($INDEX_PATH);
        $raw = file_exists($OTA_STATUS_PATH) ? (string)file_get_contents($OTA_STATUS_PATH) : '';
        $line = trim($raw);

        respond(['ok' => true,
                 'published' => ($line !== ''),
                 'live_version' => ota_live_version($records),
                 'ota_txt' => $line,
                 'url_base' => $OTA_URL_BASE,
                 'versions' => count($records)]);
    }

    default:
        respond(['ok' => false, 'error' => 'unknown_action']);
}
