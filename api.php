<?php
// ─── VanLog API ───────────────────────────────────────────────────────────────
// Einziger Endpunkt: alle Aktionen über ?action=...
// Authentifizierung: Token muss im Header X-API-Token mitgeschickt werden.

declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '0');

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

// CORS — falls die App von einer anderen Domain läuft.
// Bei Hosting auf derselben Domain kann das weg.
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-API-Token');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// ─── Config laden ─────────────────────────────────────────────────────────────
$configPath = __DIR__ . '/config.php';
if (!file_exists($configPath)) {
    respond(500, ['error' => 'Config fehlt. Bitte config.example.php zu config.php kopieren.']);
}
$config = require $configPath;
date_default_timezone_set($config['timezone'] ?? 'Europe/Berlin');

// ─── Datendatei vorbereiten (vor Token-Check, damit Debug-Endpunkt sie kennt) ──
$dataFile = $config['data_file'] ?? '';
$dataDir = $dataFile ? dirname($dataFile) : '';
if ($dataDir && !is_dir($dataDir)) {
    @mkdir($dataDir, 0755, true);
}

// ─── Token prüfen ─────────────────────────────────────────────────────────────
// Token kann via Header, URL-Parameter oder POST-Body kommen.
// Custom-Header werden bei manchen Hostings (Hetzner, 1&1, ...) gefiltert,
// deshalb akzeptieren wir mehrere Quellen.
$body = json_decode(file_get_contents('php://input'), true) ?? [];

$incoming = '';
if (!empty($_SERVER['HTTP_X_API_TOKEN'])) $incoming = $_SERVER['HTTP_X_API_TOKEN'];
elseif (!empty($_SERVER['REDIRECT_HTTP_X_API_TOKEN'])) $incoming = $_SERVER['REDIRECT_HTTP_X_API_TOKEN'];
elseif (function_exists('getallheaders')) {
    foreach (getallheaders() as $k => $v) {
        if (strcasecmp($k, 'X-API-Token') === 0) { $incoming = $v; break; }
    }
}
if (!$incoming && !empty($_GET['token'])) $incoming = $_GET['token'];
if (!$incoming && !empty($body['_token'])) $incoming = $body['_token'];

// Debug-Endpunkt — zeigt was angekommen ist, ohne Daten herauszugeben.
// Nützlich für die Erst-Einrichtung wenn der Token nicht angenommen wird.
if (($_GET['action'] ?? '') === 'debug') {
    respond(200, [
        'php_version' => PHP_VERSION,
        'tokenReceived' => $incoming !== '',
        'tokenLength' => strlen($incoming),
        'tokenSources' => [
            'header_HTTP_X_API_TOKEN' => isset($_SERVER['HTTP_X_API_TOKEN']),
            'header_REDIRECT_HTTP_X_API_TOKEN' => isset($_SERVER['REDIRECT_HTTP_X_API_TOKEN']),
            'getallheaders_available' => function_exists('getallheaders'),
            'url_param_token' => isset($_GET['token']),
            'body_token' => isset($body['_token']),
        ],
        'expectedTokenLength' => strlen((string)$config['token']),
        'tokenMatches' => $incoming !== '' && hash_equals((string)$config['token'], (string)$incoming),
        'dataFileConfigured' => $dataFile !== '',
        'dataFileExists' => $dataFile && file_exists($dataFile),
        'dataDirWritable' => $dataDir && is_writable($dataDir),
    ]);
}

if (!hash_equals((string)$config['token'], (string)$incoming)) {
    respond(401, [
        'error' => 'Ungültiger Token',
        'hint' => 'Falls das wiederholt fehlschlägt: ' . rtrim(($_SERVER['REQUEST_SCHEME'] ?? 'https') . '://' . ($_SERVER['HTTP_HOST'] ?? '') . ($_SERVER['REQUEST_URI'] ?? ''), '/') . ' mit ?action=debug aufrufen für Diagnose.'
    ]);
}

// ─── Datendatei initialisieren falls noch leer ────────────────────────────────
if (!file_exists($dataFile)) {
    file_put_contents($dataFile, json_encode(defaultData(), JSON_PRETTY_PRINT));
}

// ─── Aktion ausführen ─────────────────────────────────────────────────────────
$action = $_GET['action'] ?? '';

switch ($action) {
    case 'load':
        respond(200, loadData($dataFile));
        break;

    case 'saveSettings':
        $data = loadData($dataFile);
        $data['settings']['startKm'] = (int)($body['startKm'] ?? $data['settings']['startKm'] ?? 0);
        $data['settings']['startDate'] = $body['startDate'] ?? $data['settings']['startDate'] ?? date('c');
        if (isset($body['tireFactor'])) {
            $data['settings']['tireFactor'] = round((float)$body['tireFactor'], 3);
        } elseif (!isset($data['settings']['tireFactor'])) {
            $data['settings']['tireFactor'] = 0;
        }
        saveData($dataFile, $data);
        respond(200, $data);
        break;

    case 'saveLog':
        $data = loadData($dataFile);
        $entry = sanitizeLog($body);
        if ($entry['id']) {
            // Update — vorhandenes Datum beibehalten falls nicht explizit geändert
            $found = false;
            foreach ($data['logs'] as &$l) {
                if ($l['id'] === $entry['id']) {
                    if (!$entry['date']) $entry['date'] = $l['date'];
                    $l = $entry;
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                if (!$entry['date']) $entry['date'] = date('c');
                $data['logs'][] = $entry;
            }
        } else {
            $entry['id'] = uniqid('log_', true);
            if (!$entry['date']) $entry['date'] = date('c');
            $data['logs'][] = $entry;
        }
        // Sortieren: neueste zuerst
        usort($data['logs'], fn($a, $b) => strcmp($b['date'], $a['date']));
        saveData($dataFile, $data);
        respond(200, $data);
        break;

    case 'deleteLog':
        $data = loadData($dataFile);
        $id = $body['id'] ?? '';
        // Fotos der zu löschenden Einträge ebenfalls löschen
        foreach ($data['logs'] as $l) {
            if ($l['id'] === $id && !empty($l['photos'])) {
                foreach ($l['photos'] as $fname) {
                    if (preg_match('/^[a-zA-Z0-9_.-]+\.(jpg|jpeg|png|webp|mp4|mov|webm|m4v)$/', $fname)) {
                        @unlink(__DIR__ . '/photos/' . $fname);
                    }
                }
            }
        }
        $data['logs'] = array_values(array_filter($data['logs'], fn($l) => $l['id'] !== $id));
        // Verlinkte Auto-Expense entfernen (falls vorhanden)
        $data['expenses'] = array_values(array_filter($data['expenses'], fn($e) => ($e['linkedLogId'] ?? null) !== $id));
        saveData($dataFile, $data);
        respond(200, $data);
        break;

    case 'saveExpense':
        $data = loadData($dataFile);
        $entry = sanitizeExpense($body);
        if ($entry['id']) {
            $found = false;
            foreach ($data['expenses'] as &$e) {
                if ($e['id'] === $entry['id']) {
                    if (!$entry['date']) $entry['date'] = $e['date'];
                    $e = $entry;
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                if (!$entry['date']) $entry['date'] = date('c');
                $data['expenses'][] = $entry;
            }
        } else {
            $entry['id'] = uniqid('exp_', true);
            if (!$entry['date']) $entry['date'] = date('c');
            $data['expenses'][] = $entry;
        }
        usort($data['expenses'], fn($a, $b) => strcmp($b['date'], $a['date']));
        saveData($dataFile, $data);
        respond(200, $data);
        break;

    case 'deleteExpense':
        $data = loadData($dataFile);
        $id = $body['id'] ?? '';
        $data['expenses'] = array_values(array_filter($data['expenses'], fn($e) => $e['id'] !== $id));
        saveData($dataFile, $data);
        respond(200, $data);
        break;

    case 'ping':
        respond(200, ['ok' => true, 'time' => date('c')]);
        break;

    case 'uploadPhoto':
        // Erwartet { image: "data:image/jpeg;base64,..." } und gibt Dateiname zurück
        $img = $body['image'] ?? '';
        if (!preg_match('#^data:image/(jpeg|jpg|png|webp);base64,(.+)$#', $img, $m)) {
            respond(400, ['error' => 'Ungültiges Bildformat']);
        }
        $ext = $m[1] === 'jpg' ? 'jpeg' : $m[1];
        $bin = base64_decode($m[2], true);
        if ($bin === false || strlen($bin) > 8 * 1024 * 1024) {
            respond(400, ['error' => 'Bild zu groß oder defekt']);
        }
        // Photos-Verzeichnis liegt neben api.php
        $photosDir = __DIR__ . '/photos';
        if (!is_dir($photosDir)) @mkdir($photosDir, 0755, true);
        if (!is_writable($photosDir)) respond(500, ['error' => 'Photos-Verzeichnis nicht beschreibbar']);
        $fname = 'p_' . date('Ymd_His') . '_' . substr(bin2hex(random_bytes(4)), 0, 8) . '.' . $ext;
        if (!file_put_contents($photosDir . '/' . $fname, $bin)) {
            respond(500, ['error' => 'Bild konnte nicht gespeichert werden']);
        }
        respond(200, ['filename' => $fname]);
        break;

    case 'uploadVideo':
        // Video-Upload via FormData (multipart)
        if (empty($_FILES['video'])) {
            respond(400, ['error' => 'Kein Video empfangen']);
        }
        $file = $_FILES['video'];
        if ($file['error'] !== UPLOAD_ERR_OK) {
            respond(400, ['error' => 'Upload-Fehler: ' . $file['error']]);
        }
        if ($file['size'] > 50 * 1024 * 1024) {
            respond(400, ['error' => 'Video zu groß (max 50 MB)']);
        }
        $allowed = ['video/mp4', 'video/quicktime', 'video/webm', 'video/x-m4v'];
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);
        if (!in_array($mime, $allowed)) {
            respond(400, ['error' => 'Nicht unterstütztes Format: ' . $mime]);
        }
        $extMap = ['video/mp4'=>'mp4', 'video/quicktime'=>'mov', 'video/webm'=>'webm', 'video/x-m4v'=>'m4v'];
        $ext = $extMap[$mime] ?? 'mp4';
        $photosDir = __DIR__ . '/photos';
        if (!is_dir($photosDir)) @mkdir($photosDir, 0755, true);
        $fname = 'v_' . date('Ymd_His') . '_' . substr(bin2hex(random_bytes(4)), 0, 8) . '.' . $ext;
        if (!move_uploaded_file($file['tmp_name'], $photosDir . '/' . $fname)) {
            respond(500, ['error' => 'Video konnte nicht gespeichert werden']);
        }
        respond(200, ['filename' => $fname]);
        break;

    case 'deletePhoto':
        $fname = $body['filename'] ?? '';
        if (!preg_match('/^[a-zA-Z0-9_.-]+\.(jpg|jpeg|png|webp|mp4|mov|webm|m4v)$/', $fname)) {
            respond(400, ['error' => 'Ungültiger Dateiname']);
        }
        $path = __DIR__ . '/photos/' . $fname;
        if (file_exists($path)) @unlink($path);
        respond(200, ['ok' => true]);
        break;

    default:
        respond(400, ['error' => 'Unbekannte Aktion: ' . $action]);
}

// ─── HELPER ───────────────────────────────────────────────────────────────────
function defaultData(): array {
    return [
        'settings' => ['startKm' => 0, 'startDate' => null, 'tireFactor' => 0],
        'logs' => [],
        'expenses' => [],
    ];
}

function loadData(string $file): array {
    $fp = fopen($file, 'r');
    if (!$fp) respond(500, ['error' => 'Datendatei nicht lesbar']);
    flock($fp, LOCK_SH);
    $raw = stream_get_contents($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
    $data = json_decode($raw, true);
    if (!is_array($data)) $data = defaultData();
    $data['settings'] = array_merge(['startKm' => 0, 'startDate' => null, 'tireFactor' => 0], $data['settings'] ?? []);
    $data['logs'] = $data['logs'] ?? [];
    $data['expenses'] = $data['expenses'] ?? [];
    return $data;
}

function saveData(string $file, array $data): void {
    $fp = fopen($file, 'c+');
    if (!$fp) respond(500, ['error' => 'Datendatei nicht schreibbar']);
    flock($fp, LOCK_EX);
    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
}

function sanitizeLog(array $b): array {
    $photos = [];
    if (isset($b['photos']) && is_array($b['photos'])) {
        foreach ($b['photos'] as $p) {
            if (is_string($p) && preg_match('/^[a-zA-Z0-9_.-]+\.(jpg|jpeg|png|webp|mp4|mov|webm|m4v)$/', $p)) {
                $photos[] = $p;
            }
        }
    }
    return [
        'id' => isset($b['id']) ? (string)$b['id'] : '',
        'date' => isset($b['date']) ? (string)$b['date'] : '',
        'km' => isset($b['km']) ? (int)$b['km'] : 0,
        'liter' => isset($b['liter']) ? (float)$b['liter'] : 0.0,
        'fuelCost' => isset($b['fuelCost']) ? (float)$b['fuelCost'] : 0.0,
        'fuelPayer' => isset($b['fuelPayer']) ? (string)$b['fuelPayer'] : 'dirk',
        'oil' => (isset($b['oil']) && $b['oil'] !== null && $b['oil'] !== '') ? (int)$b['oil'] : null,
        'ortsname' => isset($b['ortsname']) ? trim((string)$b['ortsname']) : '',
        'notiz' => isset($b['notiz']) ? trim((string)$b['notiz']) : '',
        'reisenotiz' => isset($b['reisenotiz']) ? trim((string)$b['reisenotiz']) : '',
        'gps' => isset($b['gps']) && is_array($b['gps']) ? [
            'lat' => (float)$b['gps']['lat'],
            'lon' => (float)$b['gps']['lon'],
        ] : null,
        'country' => isset($b['country']) ? (string)$b['country'] : '',
        'photos' => $photos,
    ];
}

function sanitizeExpense(array $b): array {
    return [
        'id' => isset($b['id']) ? (string)$b['id'] : '',
        'date' => isset($b['date']) ? (string)$b['date'] : '',
        'amount' => isset($b['amount']) ? round((float)$b['amount'], 2) : 0.0,
        'desc' => isset($b['desc']) ? trim((string)$b['desc']) : '',
        'payer' => isset($b['payer']) ? (string)$b['payer'] : 'dirk',
        'cat' => isset($b['cat']) ? (string)$b['cat'] : 'sonstiges',
        'linkedLogId' => isset($b['linkedLogId']) ? (string)$b['linkedLogId'] : null,
    ];
}

function respond(int $code, $payload): void {
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
