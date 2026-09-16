# OTA Website Notes

Website-side OTA publishing for the **IoT Game Monitor** (STM32F401CC).
This file records what the website produces, the decisions taken while building it,
and the assumptions that must stay in sync with the firmware.

Firmware / bootloader / flashing logic is **out of scope** here and is documented in
the firmware repository. Nothing in this repository under `src/`, `include/`,
`ldscripts/`, `system/` or `Debug/` was created, edited or deleted by the website work
(those folders do not exist in this checkout).

---

## 1. Where the website actually lives

The task description refers to `website/Projecth/`. In this checkout there is **no**
`website/` folder and there are **no** firmware folders - the site files sit directly
in the repository root:

```
Projecth/
├── index.html                 hub (games + "Admin / Ops" section)
├── ota.html / ota.js          OTA admin page (this work)
├── ota_backend.php            OTA action router (this work)
├── firmware/                  uploaded .hex files + firmware_index.json (runtime)
├── status/                    device-facing status files (main.txt, rps.txt, ..., ota.txt)
├── rooms/                     per-room game state JSON
├── *_backend.php, *.html, *.js, styles.css
└── OTA_WEBSITE_NOTES.md / README.md
```

That root **is** the web root (`php -S 0.0.0.0:80` per the deployment notes, and
`htdocs/` on AwardSpace), so every path below is relative to it. Do not move these
files into a subfolder - the device fetches them by path.

There is a second, existing "device contract" convention in this repository worth
knowing: `rps_backend.php` documents that its status lines are parsed by an
**external device (ARM M3)** that splits on `;`, then on `=`, and reads fields
**by index**. So for the OTA line the field **order is part of the contract**.

---

## 2. The published file: `status/ota.txt`

Written by `ota_backend.php` on **activate** only. Exactly one line, exactly four
fields, in this order, no trailing newline:

```
version=<int>;url=<path-to-hex-file>;crc32=<hex>;size=<int>
```

Real example:

```
version=4;url=/firmware/fw_v4.hex;crc32=3610a686;size=24190
```

| Field | Type / format | Source |
|---|---|---|
| `version` | decimal int, no padding, no sign | the firmware record's version (auto-increment or admin-specified at upload) |
| `url` | path to the `.hex` file, `OTA_URL_BASE` + stored file name | `OTA_URL_BASE . 'fw_v<N>.hex'` |
| `crc32` | exactly 8 lowercase hex digits, zero-padded | `sprintf('%08x', crc32($bytes))` computed **at upload time from the stored file** |
| `size` | decimal int, bytes of the `.hex` file | `strlen()` of the uploaded bytes, verified against the stored file at activation |

Notes:

* **Deliberately not** the game status schema (`state=...;game=...;room=...;event=...`).
  The firmware contract for `ota.txt` is the 4 fields above; adding or reordering
  fields would break an index-based parser. It is written in the same *style*
  (one flat `key=value;` line, atomic `flock` write, same helper conventions).
* No trailing newline is written, matching `append_status_log()` in `rps_backend.php`
  which `rtrim()`s the line before writing.
* Only `activate` writes it. `upload` never publishes - publishing is an explicit
  second step.

### `url` assumption (verify against your firmware)

`url` is written as a **site-root-relative path with a leading slash**
(`/firmware/fw_v4.hex`) because it is typically used directly as the HTTP request
path (`GET /firmware/fw_v4.hex HTTP/1.1`), which is what an index-based ARM HTTP
client normally does. Change the `$OTA_URL_BASE` constant in `ota_backend.php` if your
device expects something else:

| Firmware expectation | Set `$OTA_URL_BASE` to |
|---|---|
| request path / absolute path on the host (default) | `'/firmware/'` |
| `host + "/" + url` concatenation without a leading slash | `'firmware/'` |
| different folder | e.g. `'/fw/'` (create it, or change `$FIRMWARE_DIR`) |

### `status/ota.txt` does not exist until the first activation

A missing file means "no update has been published", which a polling device should
treat as no-update. Nothing pre-seeds `version=0`. If your bootloader requires the
file to always exist, say so and a `version=0;url=;crc32=00000000;size=0` line can be
written once at deploy time.

---

## 3. Storage layout

```
firmware/
├── fw_v1.hex                 one stored file per version, name = fw_v<version>.hex
├── fw_v2.hex
└── firmware_index.json       metadata records (flat JSON, like rooms/<id>.json)
```

`firmware_index.json` is an array of records:

```json
[
    {
        "version": 2,
        "file": "fw_v2.hex",
        "original_name": "GameMonitor.hex",
        "uploaded_at": "2026-09-16 21:04:11",
        "crc32": "3610a686",
        "size": 24190,
        "active": true,
        "activated_at": "2026-09-16 21:05:02"
    }
]
```

* `firmware/` is created on demand by `ota_backend.php` (0777, same as `rooms/`).
* It is kept **separate** from `status/` and `rooms/`, so game cleanup logic and the
  inactivity sweeper never touch OTA assets.
* `crc32` and `size` are computed **server-side from the uploaded bytes** and are
  never accepted from the request. `activate` re-reads the stored file and refuses to
  publish if it no longer matches the stored metadata (hand-edited file on disk),
  and always writes the **upload-time** values into `ota.txt` - never a freshly
  computed value.

### Version numbering

Auto-increment by default: `version = max(existing version) + 1` (starts at 1).
The admin may instead type an explicit version in the upload form; it must be a
positive whole number and must not already exist. Duplicate versions are rejected.

---

## 4. Access control

Only `upload`, `activate` and `list` require the password; `status` is intentionally
open because it returns nothing that is not already publicly fetchable
(`status/ota.txt` is polled anonymously by the device).

* The password is the existing Parent Mode password file `parent_password.txt`,
  verified **server-side** in `ota_backend.php` with the same `trim()` +
  `hash_equals()` check used by `parent_backend.php`. No new secret file.
* No session/cookie is used: the page re-asks after a reload (same behaviour as
  `parent_stats.js`), so OTA auth can never interact with Parent Mode's game-lock
  bypass.
* `.htaccess` (repository root) denies direct HTTP access to the secret/metadata
  files. PHP reads them from disk, so the site keeps working:

| URL | Result |
|---|---|
| `/parent_password.txt`, `/parent_settings.json`, `/firmware/firmware_index.json` | 403 |
| `/status/ota.txt`, `/firmware/fw_v<N>.hex` | 200 (the device must reach these) |

If Apache ever returns 500 after uploading `.htaccess`, delete it via FTP - it is the
only file that can affect the server config.

---

## 5. Upload limits

| Setting | Local XAMPP (PHP 8.0.30) | Notes |
|---|---|---|
| `upload_max_filesize` | 40M | AwardSpace free tier is much lower (typically 2-8M) - check the host panel |
| `post_max_size` | 40M | a multi-part body larger than this arrives as an empty `$_FILES` |
| `OTA_MAX_HEX_BYTES` | 4 MiB (constant in `ota_backend.php`) | a `.hex` for a 256 KB F401CC is roughly 0.7 MiB, so this is generous |

Intel HEX acceptance is a **structural sanity check only** (per-line `:` prefix, hex
characters, byte-count/checksum agreement, at least one data record, terminating
`00000001FF`). Deep parsing, flash layout and the actual bootloader verification stay
the firmware's job.

---

## 6. Files

| File | Role |
|---|---|
| `ota_backend.php` | actions `upload`, `list`, `activate`, `status` |
| `ota.html` / `ota.js` | admin page: upload form, version table, per-row activate |
| `run_ota_test.ps1` | end-to-end test runner (same style as `run_rps_test*.ps1`) |
| `index.html` | "Admin / Ops" card linking to `ota.html` (visually separate from the games) |
| `.htaccess` | blocks direct HTTP access to the secret / metadata files |

---

## 7. Testing performed

`run_ota_test.ps1` reproduces the whole flow. What was verified:

1. `php -l` on every backend, `node --check` on every script.
2. Upload of a generated, structurally valid Intel HEX file via multipart `POST`;
   CRC32 and size verified three ways (PHP `crc32()`, PHP `hash_file('crc32b')`,
   Python `zlib.crc32`), and the stored file compared byte-for-byte with the source.
3. `status/ota.txt` parsed **by field index**, exactly like the ARM device does.
4. The published `url` fetched over HTTP from the machine's LAN address (not
   `localhost`) and byte-compared with the stored firmware.
5. Rollback: activating a version `<=` the live one is refused with a warning until
   `confirm=1` is sent, then succeeds (deliberate downgrade).
6. Rejections: wrong extension, non-Intel-HEX content, corrupted checksum, truncated
   file without the EOF record, duplicate version, oversized file.
7. Regression: the game backends were exercised again to prove nothing changed.
8. All test firmware files and `status/ota.txt` were removed afterwards.
