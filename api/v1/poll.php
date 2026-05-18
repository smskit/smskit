<?php
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
    $fp = fopen($path, 'c+');
    if ($fp && flock($fp, LOCK_EX)) {
        ftruncate($fp, 0);
        fwrite($fp, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        fflush($fp);
        flock($fp, LOCK_UN);
        fclose($fp);
    }
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

function rateLimitCheck($ip, $limit = 10) {
    $file = sys_get_temp_dir() . '/smskit_rate_' . md5($ip);
    $now = time();
    $window = 60;
    $data = @json_decode(@file_get_contents($file), true) ?: ['tokens' => $limit, 'last' => $now];
    $elapsed = $now - $data['last'];
    $data['tokens'] = min($limit, $data['tokens'] + ($elapsed * ($limit / $window)));
    $data['last'] = $now;
    if ($data['tokens'] < 1) {
        @file_put_contents($file, json_encode($data));
        return false;
    }
    $data['tokens'] -= 1;
    @file_put_contents($file, json_encode($data));
    return true;
}

$settings = loadJson('settings.json');
if (!empty($settings['timezone'])) {
    date_default_timezone_set(stripslashes($settings['timezone']));
}

$deviceId = $_GET['device_id'] ?? '';

if (empty($deviceId)) {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    if (!rateLimitCheck($ip, 10)) {
        http_response_code(429);
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'error' => 'Rate limit exceeded']);
        exit;
    }
}

$model = $_GET['model'] ?? 'Unknown';
$android = $_GET['android'] ?? 'Unknown';

if (!empty($deviceId)) {
    $tokens = loadJson('device_tokens.json');
    if (!isset($tokens['devices'])) $tokens['devices'] = [];
    $found = false;
    foreach ($tokens['devices'] as &$dev) {
        if (($dev['device_id'] ?? '') === $deviceId) {
            $dev['last_seen'] = date('Y-m-d H:i:s');
            $dev['model'] = $model;
            $dev['android'] = $android;
            $dev['status'] = 'online';
            $found = true;
            break;
        }
    }
    unset($dev);
    if (!$found) {
        $tokens['devices'][] = [
            'device_id' => $deviceId,
            'model' => $model,
            'android' => $android,
            'status' => 'online',
            'last_seen' => date('Y-m-d H:i:s'),
            'sms_sent_count' => 0
        ];
    }
    saveJson('device_tokens.json', $tokens);
}

$db = loadJson('database.json');
$scheduled = $db['scheduled_sms'] ?? [];
$now = time();
$updated = false;

foreach ($scheduled as $key => $sched) {
    if (($sched['status'] ?? '') !== 'pending') continue;
    $scheduleTime = $sched['schedule_time'] ?? '';
    if (empty($scheduleTime)) continue;
    $schedTimestamp = strtotime($scheduleTime);
    if ($schedTimestamp === false || $schedTimestamp > $now) continue;

    $numbers = $sched['numbers'] ?? [];
    $message = $sched['message'] ?? '';
    if (empty($numbers) || empty($message)) {
        $scheduled[$key]['status'] = 'failed';
        $updated = true;
        continue;
    }

    $queue = loadJson('sms_queue.json');
    if (!isset($queue['queue'])) $queue['queue'] = [];
    $msgId = 'sched_' . uniqid();
    foreach ($numbers as $num) {
        $queue['queue'][] = [
            'id' => $msgId . '_' . mt_rand(1000, 9999),
            'message_id' => $msgId,
            'to' => trim($num),
            'message' => $message,
            'status' => 'queued',
            'created_at' => date('Y-m-d H:i:s'),
            'sent_at' => null,
            'source' => 'scheduled',
            'api_key_id' => null,
            'error' => null,
            'assigned_device' => null
        ];
    }
    saveJson('sms_queue.json', $queue);

    $recurring = $sched['recurring'] ?? 'none';
    if ($recurring === 'none') {
        $scheduled[$key]['status'] = 'sent';
    } elseif ($recurring === 'daily') {
        $scheduled[$key]['schedule_time'] = date('Y-m-d\TH:i', strtotime('+1 day', $schedTimestamp));
    } elseif ($recurring === 'weekly') {
        $scheduled[$key]['schedule_time'] = date('Y-m-d\TH:i', strtotime('+1 week', $schedTimestamp));
    } elseif ($recurring === 'monthly') {
        $scheduled[$key]['schedule_time'] = date('Y-m-d\TH:i', strtotime('+1 month', $schedTimestamp));
    }
    $updated = true;
    logActivity('sms_sent', 'Poll dispatched scheduled SMS: ' . $sched['id'] . ' to ' . count($numbers) . ' number(s)');
}

if ($updated) {
    $db['scheduled_sms'] = $scheduled;
    saveJson('database.json', $db);
}

$queue = loadJson('sms_queue.json');
$devices = loadJson('device_tokens.json')['devices'] ?? [];
$onlineIds = [];
foreach ($devices as $d) {
    $lastSeen = strtotime($d['last_seen'] ?? '0');
    if ($lastSeen && (time() - $lastSeen) < 600) {
        $onlineIds[] = $d;
    }
}
usort($onlineIds, function($a, $b) {
    return ($a['sms_sent_count'] ?? 0) <=> ($b['sms_sent_count'] ?? 0);
});
$onlineDeviceIds = array_map(function($d) { return $d['device_id']; }, $onlineIds);
$assignIdx = 0;

if (!empty($onlineDeviceIds) && isset($queue['queue'])) {
    foreach ($queue['queue'] as &$item) {
        if (($item['status'] ?? '') === 'queued' && empty($item['assigned_device'])) {
            $item['assigned_device'] = $onlineDeviceIds[$assignIdx % count($onlineDeviceIds)];
            $assignIdx++;
        }
    }
    unset($item);
    saveJson('sms_queue.json', $queue);
}

$commands = [];
$remaining = [];

if (isset($queue['queue']) && !empty($deviceId)) {
    foreach ($queue['queue'] as $item) {
        $status = $item['status'] ?? '';
        $assigned = $item['assigned_device'] ?? '';
        if ($status === 'queued' && (empty($assigned) || $assigned === $deviceId)) {
            $commands[] = [
                'id' => $item['id'],
                'to' => $item['to'],
                'message' => $item['message']
            ];
            $item['status'] = 'processing';
            $item['sent_at'] = date('Y-m-d H:i:s');
            $item['assigned_device'] = $deviceId;
        }
        $remaining[] = $item;
    }
    $queue['queue'] = $remaining;
    saveJson('sms_queue.json', $queue);
}

header('Content-Type: application/json');
echo json_encode([
    'ok' => true,
    'commands' => $commands,
    'timestamp' => date('Y-m-d H:i:s')
], JSON_PRETTY_PRINT);