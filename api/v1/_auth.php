<?php


header('Content-Type: application/json');

$configDir = __DIR__ . '/../../config/';

function loadJson($file) {
    global $configDir;
    $path = $configDir . $file;
    if (!file_exists($path)) return [];
    $content = file_get_contents($path);
    return json_decode($content, true) ?: [];
}

function saveJson($file, $data) {
    global $configDir;
    $path = $configDir . $file;
    file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT));
}

function logActivity($type, $detail) {
    $activity = loadJson('activity.json');
    if (!isset($activity['logs'])) $activity['logs'] = [];
    array_unshift($activity['logs'], [
        'type' => $type,
        'detail' => $detail,
        'timestamp' => date('Y-m-d H:i:s')
    ]);
    $activity['logs'] = array_slice($activity['logs'], 0, 5000);
    saveJson('activity.json', $activity);
}

function sendError($code, $message) {
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $message]);
    exit;
}

// Extract token from Authorization header or query parameter
$token = null;

// Check Authorization header
$authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
if (empty($authHeader)) {
    $authHeader = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
}

if (!empty($authHeader)) {
    if (preg_match('/Bearer\s+(\S+)/i', $authHeader, $matches)) {
        $token = $matches[1];
    }
}

// Fallback to query parameter
if (empty($token)) {
    $token = $_GET['api_key'] ?? null;
}

if (empty($token)) {
    sendError(401, 'Authentication required. Provide API key via Authorization: Bearer header or ?api_key= parameter.');
}

// Look up key
$db = loadJson('database.json');
$apiKeys = $db['api_keys'] ?? [];
$tokenHash = hash('sha256', $token);

$keyData = null;
foreach ($apiKeys as $key) {
    if (($key['hash'] ?? '') === $tokenHash) {
        $keyData = $key;
        break;
    }
}

if ($keyData === null) {
    sendError(401, 'Invalid API key.');
}

// Rate limit check
$rateLimit = $keyData['rate_limit'] ?? 100;
$activity = loadJson('activity.json');
$logs = $activity['logs'] ?? [];
$windowStart = time() - 3600;
$callCount = 0;

foreach ($logs as $log) {
    if (($log['type'] ?? '') === 'api_call' && ($log['api_key_id'] ?? '') === ($keyData['id'] ?? '')) {
        $ts = strtotime($log['timestamp'] ?? '0');
        if ($ts && $ts > $windowStart) {
            $callCount++;
        }
    }
}

if ($callCount >= $rateLimit) {
    sendError(429, 'Rate limit exceeded. Maximum ' . $rateLimit . ' requests per hour.');
}

// Update usage
foreach ($apiKeys as $idx => $key) {
    if (($key['id'] ?? '') === ($keyData['id'] ?? '')) {
        $apiKeys[$idx]['last_used'] = date('Y-m-d H:i:s');
        $apiKeys[$idx]['usage_count'] = ($apiKeys[$idx]['usage_count'] ?? 0) + 1;
        break;
    }
}
$db['api_keys'] = $apiKeys;
saveJson('database.json', $db);

// Log API call
$activity['logs'] = $logs;
if (!isset($activity['logs'])) $activity['logs'] = [];
array_unshift($activity['logs'], [
    'type' => 'api_call',
    'api_key_id' => $keyData['id'] ?? '',
    'detail' => $_SERVER['REQUEST_METHOD'] . ' ' . ($_SERVER['REQUEST_URI'] ?? ''),
    'timestamp' => date('Y-m-d H:i:s')
]);
$activity['logs'] = array_slice($activity['logs'], 0, 5000);
saveJson('activity.json', $activity);

// Make key data available to the including endpoint
$__keyData = $keyData;
