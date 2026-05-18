<?php

require_once __DIR__ . '/_auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendError(405, 'Method not allowed. Use GET.');
}

$activity = loadJson('activity.json');
$logs = $activity['logs'] ?? [];
$now = time();

$totalSent = 0;
$todaySent = 0;
$weekSent = 0;
$monthSent = 0;

foreach ($logs as $log) {
    if (($log['type'] ?? '') === 'sms_sent') {
        $totalSent++;
        $ts = strtotime($log['timestamp'] ?? '0');
        if ($ts === false) continue;
        $daysDiff = floor(($now - $ts) / 86400);
        if ($daysDiff < 1) $todaySent++;
        if ($daysDiff < 7) $weekSent++;
        if ($daysDiff < 30) $monthSent++;
    }
}

$db = loadJson('database.json');
$apiKeysCount = count($db['api_keys'] ?? []);

$deviceTokens = loadJson('device_tokens.json');
$devices = $deviceTokens['devices'] ?? [];
$onlineCount = 0;
foreach ($devices as $device) {
    $seen = strtotime($device['last_seen'] ?? '0');
    if ($seen && (time() - $seen) < 300) {
        $onlineCount++;
    }
}

echo json_encode([
    'ok' => true,
    'total_sent' => $totalSent,
    'today_sent' => $todaySent,
    'week_sent' => $weekSent,
    'month_sent' => $monthSent,
    'api_keys_count' => $apiKeysCount,
    'devices_online_count' => $onlineCount
]);
