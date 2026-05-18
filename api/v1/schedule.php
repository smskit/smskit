<?php
header('Content-Type: application/json');

$configDir = __DIR__ . '/../../config/';

function loadJson($file) {
    global $configDir;
    $path = $configDir . $file;
    if (!file_exists($path)) return [];
    return json_decode(file_get_contents($path), true) ?: [];
}

function saveJson($file, $data) {
    global $configDir;
    $path = $configDir . $file;
    file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
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

$token = null;
$authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
if (!empty($authHeader) && preg_match('/Bearer\s+(\S+)/i', $authHeader, $matches)) {
    $token = $matches[1];
}
if (empty($token)) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Authentication required.']);
    exit;
}

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
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Invalid API key.']);
    exit;
}

$permissions = $keyData['permissions'] ?? [];
if (!in_array('schedule', $permissions) && !in_array('send', $permissions)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'API key lacks schedule permission.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed. Use POST.']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    $input = $_POST;
}

$numbers = $input['numbers'] ?? [];
$message = trim($input['message'] ?? '');
$scheduleTime = $input['schedule_time'] ?? '';
$recurring = $input['recurring'] ?? 'none';

if (empty($numbers) || !is_array($numbers)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'numbers is required.']);
    exit;
}
if (empty($message)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'message is required.']);
    exit;
}
if (empty($scheduleTime)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'schedule_time is required.']);
    exit;
}

$schedId = 'sched_' . uniqid();
$db['scheduled_sms'][] = [
    'id' => $schedId,
    'numbers' => $numbers,
    'message' => $message,
    'schedule_time' => $scheduleTime,
    'recurring' => $recurring,
    'status' => 'pending',
    'created_at' => date('Y-m-d H:i:s')
];
saveJson('database.json', $db);

echo json_encode([
    'ok' => true,
    'sched_id' => $schedId,
    'numbers_count' => count($numbers),
    'schedule_time' => $scheduleTime
]);