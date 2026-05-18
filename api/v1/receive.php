<?php
/**
 * TextFlow API - Receive Endpoint (incoming SMS from Android)
 * POST /api/v1/receive
 * Params: device_id, from, message, timestamp
 */

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

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed. Use POST.']);
    exit;
}

$deviceId = $_POST['device_id'] ?? '';
$from = $_POST['from'] ?? '';
$message = $_POST['message'] ?? '';
$timestamp = $_POST['timestamp'] ?? date('Y-m-d H:i:s');

if (empty($deviceId)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'device_id is required']);
    exit;
}
if (empty($from)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'from is required']);
    exit;
}
if (empty($message)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'message is required']);
    exit;
}

// Log incoming SMS
$activity = loadJson('activity.json');
if (!isset($activity['logs'])) $activity['logs'] = [];
array_unshift($activity['logs'], [
    'type' => 'sms_received',
    'detail' => 'SMS from ' . $from . ': ' . substr($message, 0, 100),
    'timestamp' => $timestamp,
    'meta' => [
        'from' => $from,
        'message' => substr($message, 0, 100),
        'device_id' => $deviceId
    ]
]);
$activity['logs'] = array_slice($activity['logs'], 0, 5000);
saveJson('activity.json', $activity);

// Update device last seen
$deviceTokens = loadJson('device_tokens.json');
if (isset($deviceTokens['devices'])) {
    foreach ($deviceTokens['devices'] as $idx => $device) {
        if (($device['device_id'] ?? '') === $deviceId) {
            $deviceTokens['devices'][$idx]['last_seen'] = date('Y-m-d H:i:s');
            $deviceTokens['devices'][$idx]['status'] = 'online';
            break;
        }
    }
    saveJson('device_tokens.json', $deviceTokens);
}

echo json_encode(['ok' => true]);
