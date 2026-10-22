<?php
// ─── VanLog Public API ────────────────────────────────────────────────────────
// Liefert die Reise-Daten für die öffentliche Blog-Seite.
// Kein Token nötig. Filtert sensible Daten (Ausgaben, Tankkosten, interne Notizen) raus.
// Veröffentlicht werden nur Log-Einträge mit GPS-Koordinaten.

declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '0');

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=60');
header('Access-Control-Allow-Origin: *');

$configPath = __DIR__ . '/config.php';
if (!file_exists($configPath)) {
    http_response_code(500);
    echo json_encode(['error' => 'Config fehlt']);
    exit;
}
$config = require $configPath;
date_default_timezone_set($config['timezone'] ?? 'Europe/Berlin');

$dataFile = $config['data_file'] ?? '';
if (!$dataFile || !file_exists($dataFile)) {
    echo json_encode(['settings' => ['startKm' => 0, 'startDate' => null, 'tireFactor' => 0], 'logs' => []]);
    exit;
}

$raw = @file_get_contents($dataFile);
$data = json_decode($raw, true);
if (!is_array($data)) {
    echo json_encode(['settings' => ['startKm' => 0, 'startDate' => null, 'tireFactor' => 0], 'logs' => []]);
    exit;
}

// Nur Logs mit GPS — und auf öffentliche Felder reduzieren
$publicLogs = [];
foreach ($data['logs'] ?? [] as $l) {
    if (empty($l['gps']) || !is_array($l['gps'])) continue;
    $publicLogs[] = [
        'id' => (string)($l['id'] ?? ''),
        'date' => (string)($l['date'] ?? ''),
        'km' => (int)($l['km'] ?? 0),
        'liter' => (float)($l['liter'] ?? 0),
        'oil' => (isset($l['oil']) && $l['oil'] !== null) ? (int)$l['oil'] : null,
        'ortsname' => (string)($l['ortsname'] ?? ''),
        'reisenotiz' => (string)($l['reisenotiz'] ?? ''),
        'gps' => [
            'lat' => (float)$l['gps']['lat'],
            'lon' => (float)$l['gps']['lon'],
        ],
        'country' => (string)($l['country'] ?? ''),
        'photos' => array_values(array_filter(
            (array)($l['photos'] ?? []),
            fn($p) => is_string($p) && preg_match('/^[a-zA-Z0-9_.-]+\.(jpg|jpeg|png|webp|mp4|mov|webm|m4v)$/', $p)
        )),
    ];
}

usort($publicLogs, fn($a, $b) => strcmp($a['date'], $b['date']));

echo json_encode([
    'settings' => [
        'startKm' => (int)($data['settings']['startKm'] ?? 0),
        'startDate' => $data['settings']['startDate'] ?? null,
        'tireFactor' => (float)($data['settings']['tireFactor'] ?? 0),
    ],
    'logs' => $publicLogs,
    'generatedAt' => date('c'),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
