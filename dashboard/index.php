<?php
session_start();
if (isset($_COOKIE[session_name()])) {
    setcookie(session_name(), $_COOKIE[session_name()], [
        'expires' => 0, 'path' => '/', 'secure' => true,
        'httponly' => true, 'samesite' => 'Strict'
    ]);
}

$configDir = __DIR__ . '/../config/';

if (!file_exists($configDir . 'admin.json')) {
    header('Location: ../setup.php');
    exit;
}

function loadJson($file) {
    global $configDir;
    $path = $configDir . $file;
    if (!file_exists($path)) return [];
    $content = file_get_contents($path);
    return $content ? json_decode($content, true) : [];
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
        'type' => $type, 'detail' => $detail,
        'timestamp' => date('Y-m-d H:i:s')
    ]);
    $activity['logs'] = array_slice($activity['logs'], 0, 5000);
    saveJson('activity.json', $activity);
}

$settings = loadJson('settings.json');
if (!empty($settings['timezone'])) {
    $tz = stripslashes($settings['timezone']);
    date_default_timezone_set($tz);
}

if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: ./');
    exit;
}

if (isset($_GET['export']) && !empty($_SESSION['tf_auth'])) {
    $type = $_GET['type'] ?? 'sent';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="smskit_'.$type.'_'.date('Ymd_His').'.csv"');
    $out = fopen('php://output', 'w');
    if ($type === 'sent') {
        fputcsv($out, ['Time','To','Message','Status','Device']);
        $queue = loadJson('sms_queue.json')['queue'] ?? [];
        foreach ($queue as $q) {
            fputcsv($out, [$q['created_at']??'',$q['to']??'',$q['message']??'',$q['status']??'',$q['source']??'']);
        }
    } elseif ($type === 'received') {
        fputcsv($out, ['Time','From','Message','Device']);
        $logs = loadJson('activity.json')['logs'] ?? [];
        foreach ($logs as $log) {
            if (($log['type']??'') === 'sms_received') {
                $meta = $log['meta'] ?? [];
                fputcsv($out, [$log['timestamp']??'',$meta['from']??'',$meta['message']??'',$meta['device_id']??'']);
            }
        }
    } elseif ($type === 'scheduled') {
        fputcsv($out, ['ID','Numbers','Message','Schedule','Recurring','Status']);
        $sched = loadJson('database.json')['scheduled_sms'] ?? [];
        foreach ($sched as $s) {
            fputcsv($out, [$s['id']??'',implode(';',$s['numbers']??[]),$s['message']??'',$s['schedule_time']??'',$s['recurring']??'',$s['status']??'']);
        }
    } elseif ($type === 'queue') {
        fputcsv($out, ['ID','To','Message','Status','Created','Device']);
        $queue = loadJson('sms_queue.json')['queue'] ?? [];
        foreach ($queue as $q) {
            fputcsv($out, [$q['id']??'',$q['to']??'',$q['message']??'',$q['status']??'',$q['created_at']??'',$q['assigned_device']??'']);
        }
    }
    fclose($out);
    exit;
}

if (isset($_GET['login']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';
    $admin = loadJson('admin.json');
    if (($admin['username'] ?? '') === $username && password_verify($password, $admin['password'] ?? '')) {
        $_SESSION['tf_auth'] = true;
        session_regenerate_id(true);
        $admin['last_login'] = date('Y-m-d H:i:s');
        saveJson('admin.json', $admin);
        logActivity('login', 'Admin logged in: ' . $username);
        echo json_encode(['ok' => true]);
    } else {
        echo json_encode(['ok' => false, 'error' => 'Invalid credentials']);
    }
    exit;
}

$isAuthed = !empty($_SESSION['tf_auth']);

if ($isAuthed && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['action'])) {
    header('Content-Type: application/json');
    $action = $_GET['action'];

    if ($action === 'send_sms') {
        $numbers    = $_POST['numbers'] ?? '';
        $message    = $_POST['message'] ?? '';
        $device     = $_POST['device'] ?? 'auto';
        $numberList = array_filter(array_map('trim', explode(',', $numbers)));
        if (empty($numberList)) { echo json_encode(['ok' => false, 'error' => 'No valid numbers']); exit; }
        if (empty($message))    { echo json_encode(['ok' => false, 'error' => 'Message is required']); exit; }

        $devices = loadJson('device_tokens.json')['devices'] ?? [];
        $online = array_filter($devices, fn($d) => (time() - strtotime($d['last_seen']??'0')) < 600);
        if (empty($online)) { echo json_encode(['ok' => false, 'error' => 'No online devices']); exit; }

        if ($device === 'auto') {
            usort($online, fn($a,$b) => ($a['sms_sent_count']??0) <=> ($b['sms_sent_count']??0));
            $assigned = $online[0]['device_id'];
        } else {
            $found = false;
            foreach ($online as $d) { if ($d['device_id'] === $device) { $found = true; break; } }
            if (!$found) { echo json_encode(['ok' => false, 'error' => 'Selected device not online']); exit; }
            $assigned = $device;
        }

        $msgId = 'msg_' . uniqid();
        $queue = loadJson('sms_queue.json');
        if (!isset($queue['queue'])) $queue['queue'] = [];
        foreach ($numberList as $num) {
            $queue['queue'][] = [
                'id' => $msgId . '_' . mt_rand(1000,9999), 'message_id' => $msgId,
                'to' => $num, 'message' => $message, 'status' => 'queued',
                'created_at' => date('Y-m-d H:i:s'), 'sent_at' => null,
                'source' => 'dashboard', 'api_key_id' => null, 'error' => null,
                'assigned_device' => $assigned
            ];
        }
        saveJson('sms_queue.json', $queue);
        logActivity('sms_sent', 'Queued SMS to ' . count($numberList) . ' number(s): ' . implode(', ', $numberList));
        echo json_encode(['ok' => true, 'msg_id' => $msgId, 'count' => count($numberList), 'device' => $assigned]);
        exit;
    }

    if ($action === 'schedule_sms') {
        $numbers      = $_POST['numbers'] ?? '';
        $message      = $_POST['message'] ?? '';
        $scheduleTime = $_POST['schedule_time'] ?? '';
        $recurring    = $_POST['recurring'] ?? 'none';
        $numberList   = array_filter(array_map('trim', explode(',', $numbers)));
        if (empty($numberList) || empty($message) || empty($scheduleTime)) {
            echo json_encode(['ok' => false, 'error' => 'All fields are required']); exit;
        }
        $db = loadJson('database.json');
        if (!isset($db['scheduled_sms'])) $db['scheduled_sms'] = [];
        $db['scheduled_sms'][] = [
            'id' => 'sched_' . uniqid(), 'numbers' => $numberList, 'message' => $message,
            'schedule_time' => $scheduleTime, 'recurring' => $recurring,
            'status' => 'pending', 'created_at' => date('Y-m-d H:i:s')
        ];
        saveJson('database.json', $db);
        logActivity('schedule', 'Scheduled SMS for ' . $scheduleTime);
        echo json_encode(['ok' => true]);
        exit;
    }

    if ($action === 'del_schedule') {
        $schedId = $_POST['sched_id'] ?? '';
        $db = loadJson('database.json');
        if (isset($db['scheduled_sms'])) {
            $db['scheduled_sms'] = array_values(array_filter($db['scheduled_sms'],
                fn($s) => ($s['id'] ?? '') !== $schedId));
        }
        saveJson('database.json', $db);
        logActivity('schedule', 'Deleted scheduled SMS: ' . $schedId);
        echo json_encode(['ok' => true]);
        exit;
    }

    if ($action === 'retry_sms') {
        $msgId = $_POST['msg_id'] ?? '';
        $queue = loadJson('sms_queue.json');
        $found = false;
        if (isset($queue['queue'])) {
            foreach ($queue['queue'] as &$item) {
                if (($item['id'] ?? '') === $msgId || ($item['message_id'] ?? '') === $msgId) {
                    $item['status'] = 'queued';
                    $item['error'] = null;
                    $item['sent_at'] = null;
                    $found = true;
                }
            }
            unset($item);
        }
        if ($found) {
            saveJson('sms_queue.json', $queue);
            logActivity('sms_sent', 'Retried SMS: ' . $msgId);
            echo json_encode(['ok' => true]);
        } else {
            echo json_encode(['ok' => false, 'error' => 'Message not found']);
        }
        exit;
    }

    if ($action === 'gen_api_key') {
        $keyName    = $_POST['key_name'] ?? '';
        $rateLimit  = (int)($_POST['rate_limit'] ?? 100);
        $permissions = $_POST['permissions'] ?? ['send','status'];
        if (empty($keyName)) { echo json_encode(['ok' => false, 'error' => 'Key name required']); exit; }
        $raw     = 'sk_live_' . bin2hex(random_bytes(20));
        $hash    = hash('sha256', $raw);
        $preview = substr($raw, 0, 12) . '...' . substr($raw, -4);
        $db = loadJson('database.json');
        if (!isset($db['api_keys'])) $db['api_keys'] = [];
        $db['api_keys'][] = [
            'id' => 'key_' . uniqid(), 'name' => $keyName, 'hash' => $hash,
            'preview' => $preview, 'permissions' => $permissions,
            'rate_limit' => $rateLimit, 'usage_count' => 0,
            'last_used' => null, 'created_at' => date('Y-m-d H:i:s')
        ];
        saveJson('database.json', $db);
        logActivity('api_key', 'Generated API key: ' . $keyName);
        echo json_encode(['ok' => true, 'key' => $raw]);
        exit;
    }

    if ($action === 'del_api_key') {
        $keyId = $_POST['key_id'] ?? '';
        $db = loadJson('database.json');
        if (isset($db['api_keys'])) {
            $db['api_keys'] = array_values(array_filter($db['api_keys'],
                fn($k) => ($k['id'] ?? '') !== $keyId));
        }
        saveJson('database.json', $db);
        logActivity('api_key', 'Revoked API key: ' . $keyId);
        echo json_encode(['ok' => true]);
        exit;
    }

    // Template actions
    if ($action === 'save_template') {
        $id   = $_POST['id'] ?? '';
        $name = trim($_POST['name'] ?? '');
        $message = $_POST['message'] ?? '';
        $variables = json_decode($_POST['variables'] ?? '[]', true);
        if (empty($name) || empty($message)) { echo json_encode(['ok'=>false,'error'=>'Name and message required']); exit; }
        $templates = loadJson('templates.json');
        if (empty($id)) {
            $id = 'tpl_' . uniqid();
            $templates[] = ['id'=>$id,'name'=>$name,'message'=>$message,'variables'=>$variables];
        } else {
            foreach ($templates as &$t) {
                if ($t['id'] === $id) { $t['name']=$name; $t['message']=$message; $t['variables']=$variables; break; }
            }
        }
        saveJson('templates.json', $templates);
        logActivity('system', 'Saved template: ' . $name);
        echo json_encode(['ok'=>true,'id'=>$id]);
        exit;
    }
    if ($action === 'del_template') {
        $tid = $_POST['id'] ?? '';
        $templates = loadJson('templates.json');
        $templates = array_values(array_filter($templates, fn($t)=>($t['id']??'')!==$tid));
        saveJson('templates.json', $templates);
        logActivity('system', 'Deleted template: ' . $tid);
        echo json_encode(['ok'=>true]);
        exit;
    }

    echo json_encode(['ok' => false, 'error' => 'Unknown action']);
    exit;
}

// Auto-create templates if missing
if (!file_exists($configDir . 'templates.json')) {
    $defaultTemplates = [
        ['id'=>'tpl_otp','name'=>'OTP','message'=>'Your verification code is {code}. Do not share it with anyone.','variables'=>['code']],
        ['id'=>'tpl_order','name'=>'Order Confirmed','message'=>'Hi {name}, your order #{order} has been confirmed. Estimated delivery: {date}.','variables'=>['name','order','date']],
        ['id'=>'tpl_reminder','name'=>'Reminder','message'=>'Hi {name}, this is a friendly reminder about {task} scheduled for {time}.','variables'=>['name','task','time']],
        ['id'=>'tpl_welcome','name'=>'Welcome','message'=>'Welcome to {company}, {name}! We are excited to have you on board.','variables'=>['name','company']],
        ['id'=>'tpl_alert','name'=>'Alert','message'=>'Alert: {title}. Details: {details}. Please take necessary action.','variables'=>['title','details']],
        ['id'=>'tpl_verify','name'=>'Verification','message'=>'{name}, please verify your account using this link: {link}','variables'=>['name','link']],
        ['id'=>'tpl_payment','name'=>'Payment Received','message'=>'Payment of {amount} received on {date}. Thank you, {name}!','variables'=>['amount','date','name']],
        ['id'=>'tpl_appt','name'=>'Appointment','message'=>'Your appointment with {doctor} is on {date} at {time}. Please arrive 10 min early.','variables'=>['doctor','date','time']]
    ];
    saveJson('templates.json', $defaultTemplates);
}

$activity = loadJson('activity.json');
$logs     = $activity['logs'] ?? [];
$now      = time();
$totalSent = $todaySent = $weekSent = $monthSent = 0;
$dayLabels = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'];
$dayCounts = array_fill(0, 7, 0);

foreach ($logs as $log) {
    $logType = $log['type'] ?? '';
    $logDetail = $log['detail'] ?? '';
    if ($logType !== 'sms_sent') continue;
    if (strpos($logDetail, 'SMS delivered:') === false) continue;
    $totalSent++;
    $ts = strtotime($log['timestamp'] ?? '0');
    if ($ts === false) continue;
    $diff = floor(($now - $ts) / 86400);
    if ($diff < 1)  $todaySent++;
    if ($diff < 7)  $weekSent++;
    if ($diff < 30) $monthSent++;
    if ($diff < 7)  $dayCounts[(int)date('w', $ts)]++;
}

$queueItems = loadJson('sms_queue.json');
$queuedCount = 0;
$failedItems = [];
if (isset($queueItems['queue'])) {
    foreach ($queueItems['queue'] as $qi) {
        if (($qi['status'] ?? '') === 'queued') $queuedCount++;
        if (($qi['status'] ?? '') === 'failed') $failedItems[] = $qi;
    }
}

$orderedDays = $orderedCounts = [];
$todayIdx = (int)date('w', $now);
for ($i = 0; $i < 7; $i++) {
    $idx = ($todayIdx + 1 + $i) % 7;
    $orderedDays[]   = $dayLabels[$idx];
    $orderedCounts[] = $dayCounts[$idx];
}
$maxCount = max($orderedCounts) ?: 1;

$deviceTokens = loadJson('device_tokens.json');
$devices      = $deviceTokens['devices'] ?? [];
$onlineDevices = [];
$tzName = !empty($settings['timezone']) ? stripslashes($settings['timezone']) : 'UTC';
try {
    $deviceTz = new DateTimeZone($tzName);
    $nowDhaka = new DateTime('now', $deviceTz);
    foreach ($devices as $d) {
        $lastSeenStr = $d['last_seen'] ?? '';
        if (empty($lastSeenStr)) continue;
        try {
            $deviceTime = new DateTime($lastSeenStr, $deviceTz);
            $diff = $nowDhaka->getTimestamp() - $deviceTime->getTimestamp();
            if ($diff < 600) $onlineDevices[] = $d;
        } catch (Exception $e) { continue; }
    }
} catch (Exception $e) { $onlineDevices = []; }
$allDevicesOffline = (!empty($devices) && empty($onlineDevices));

$db          = loadJson('database.json');
$apiKeys     = $db['api_keys'] ?? [];
$allScheduled = $db['scheduled_sms'] ?? [];
$scheduledSms = array_filter($allScheduled, fn($s) => ($s['status'] ?? '') === 'pending');

$templates   = loadJson('templates.json');

$recentLogs  = array_slice($logs, 0, 8);
$recentSends = [];
foreach ($logs as $log) {
    if (($log['type'] ?? '') === 'sms_sent') {
        $recentSends[] = $log;
        if (count($recentSends) >= 10) break;
    }
}

$baseUrl = rtrim('https://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . dirname(dirname($_SERVER['SCRIPT_NAME'])), '/');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
<meta name="theme-color" content="#F7F7F5">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="default">
<title>SMSKIT — Dashboard</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&family=Fira+Code:wght@300;400;500&display=swap" rel="stylesheet">
<style>
:root {
  --bg:#F7F7F5;--bg-2:#F0F0ED;--bg-3:#E8E8E4;--white:#FFFFFF;--card:#FFFFFF;
  --border:#E4E4DF;--border2:#D0D0CA;--ink:#0C0C0A;--ink-2:#4A4A46;--ink-3:#9A9A94;--ink-4:#C8C8C2;
  --blue:#0057FF;--blue-bg:#EEF3FF;--blue-mid:rgba(0,87,255,0.08);--green:#00875A;--green-bg:#E6F5EF;
  --red:#D92D20;--red-bg:#FEF3F2;--amber:#B54708;--amber-bg:#FFFAEB;
  --f-ui:'Plus Jakarta Sans',sans-serif;--f-code:'Fira Code',monospace;
  --ease:cubic-bezier(0.16,1,0.3,1);--r:12px;--nav-h:56px;--sub-h:44px;
  --safe-top:env(safe-area-inset-top,0px);--safe-bottom:env(safe-area-inset-bottom,0px);
}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
html,body{height:100%;overflow:hidden}
body{font-family:var(--f-ui);background:var(--bg);color:var(--ink);font-size:13px;-webkit-font-smoothing:antialiased;display:flex;flex-direction:column;position:relative;padding-top:var(--safe-top)}
body::before{content:'';position:fixed;inset:0;background-image:radial-gradient(circle,var(--ink-4) 1px,transparent 1px);background-size:28px 28px;opacity:0.18;pointer-events:none;z-index:0}
::-webkit-scrollbar{width:3px;height:3px}::-webkit-scrollbar-track{background:transparent}::-webkit-scrollbar-thumb{background:var(--ink-4);border-radius:2px}
button,input,textarea,select{font-family:var(--f-ui)}button{cursor:pointer;border:none;background:none;-webkit-tap-highlight-color:transparent}a{color:inherit;text-decoration:none}
.nav{height:var(--nav-h);background:rgba(255,255,255,0.92);backdrop-filter:blur(12px);-webkit-backdrop-filter:blur(12px);border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;padding:0 0.75rem;gap:0.5rem;flex-shrink:0;z-index:50;position:relative}
.nav-brand{display:flex;align-items:center;gap:0.4rem;flex-shrink:0;cursor:pointer}
.nav-logo{width:28px;height:28px;background:var(--ink);border-radius:7px;display:flex;align-items:center;justify-content:center;flex-shrink:0}
.nav-wordmark{font-weight:700;font-size:15px;letter-spacing:-0.03em;line-height:1;color:var(--ink)}.nav-wordmark span{color:var(--blue)}
.nav-ver{font-family:var(--f-code);font-size:8px;padding:2px 5px;border-radius:4px;background:var(--blue-bg);color:var(--blue);letter-spacing:0.04em}
.nav-actions{display:flex;align-items:center;gap:0.4rem;flex-shrink:0}
.nav-btn{height:32px;padding:0 0.7rem;border-radius:8px;border:1.5px solid var(--border);background:transparent;color:var(--ink-2);font-size:11px;font-weight:600;display:flex;align-items:center;gap:0.3rem;transition:all 0.15s}
.nav-btn:active{background:var(--bg-2)}.nav-btn.icon-only{width:32px;padding:0;justify-content:center}.nav-btn.danger:active{border-color:rgba(217,45,32,0.3);color:var(--red)}
.device-pill{display:flex;align-items:center;gap:0.3rem;padding:0.25rem 0.6rem;background:var(--bg-2);border:1.5px solid var(--border);border-radius:20px;font-size:10px;color:var(--ink-2);flex-shrink:0;font-family:var(--f-code);font-weight:500}
.device-dot{width:5px;height:5px;border-radius:50%;background:var(--green);box-shadow:0 0 4px rgba(0,135,90,0.4);flex-shrink:0}
.device-pill.offline .device-dot{background:var(--red);box-shadow:0 0 4px rgba(217,45,32,0.4)}
.alert-banner{display:flex;align-items:center;gap:0.5rem;padding:0.5rem 0.75rem;background:var(--amber-bg);border-bottom:1px solid var(--amber);color:var(--amber);font-size:11px;font-weight:500;flex-shrink:0;z-index:45}
.subbar{height:var(--sub-h);background:rgba(255,255,255,0.85);border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;padding:0 0.75rem;gap:0.5rem;flex-shrink:0;z-index:40;overflow-x:auto;-webkit-overflow-scrolling:touch;scrollbar-width:none}
.subbar::-webkit-scrollbar{display:none}.subbar-left{display:flex;align-items:center;gap:0.25rem}
.tab-nav{display:flex;align-items:center;gap:1px}
.tab-item{display:flex;align-items:center;gap:0.3rem;padding:0.3rem 0.55rem;border-radius:7px;cursor:pointer;color:var(--ink-3);font-size:11px;font-weight:500;transition:all 0.15s;white-space:nowrap;flex-shrink:0;border:none;background:none;-webkit-tap-highlight-color:transparent}
.tab-item:active{background:var(--blue-mid)}.tab-item.active{background:var(--blue-bg);color:var(--blue);font-weight:600}.tab-item svg{flex-shrink:0;width:13px;height:13px}
.workspace{flex:1;overflow:hidden;display:flex;position:relative;z-index:1}
.content-pane{flex:1;overflow-y:auto;padding:0.875rem;-webkit-overflow-scrolling:touch;padding-bottom:calc(0.875rem + var(--safe-bottom))}
.section{display:none;animation:fadeUp 0.2s var(--ease)}.section.active{display:block}
@keyframes fadeUp{from{opacity:0;transform:translateY(6px)}to{opacity:1;transform:none}}
.stat-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:8px;margin-bottom:12px}
.stat-card{background:var(--card);border:1.5px solid var(--border);border-radius:10px;padding:14px}.stat-card:active{border-color:var(--border2)}
.stat-label{font-family:var(--f-code);font-size:8.5px;color:var(--ink-3);text-transform:uppercase;letter-spacing:0.06em;font-weight:500;margin-bottom:4px}
.stat-value{font-weight:700;font-size:22px;color:var(--ink);line-height:1;letter-spacing:-0.02em}.stat-value.accent{color:var(--blue)}
.chart-card{background:var(--card);border:1.5px solid var(--border);border-radius:10px;padding:14px;margin-bottom:12px}
.chart-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;flex-wrap:wrap;gap:4px}
.chart-title{font-weight:700;font-size:13px;color:var(--ink)}.chart-sub{font-family:var(--f-code);font-size:9px;color:var(--ink-3);font-weight:500}
.chart-bars{display:flex;align-items:flex-end;gap:6px;height:100px;padding-bottom:22px;position:relative}
.chart-bar-wrap{flex:1;display:flex;flex-direction:column;align-items:center;justify-content:flex-end;height:100%;position:relative}
.chart-bar{width:100%;border-radius:4px 4px 0 0;min-height:3px}.chart-bar.past{background:var(--bg-3)}.chart-bar.today{background:var(--blue)}
.chart-label{position:absolute;bottom:-20px;font-family:var(--f-code);font-size:8.5px;color:var(--ink-3);font-weight:500}
.chart-val{font-family:var(--f-code);font-size:9px;color:var(--ink-4);margin-bottom:3px;font-weight:500}.chart-bar-wrap:last-child .chart-val{color:var(--blue)}
.panel{background:var(--card);border:1.5px solid var(--border);border-radius:10px;padding:14px;margin-bottom:12px}
.panel-title{font-weight:700;font-size:12px;margin-bottom:10px;color:var(--ink)}
.activity-list{display:flex;flex-direction:column}
.activity-item{display:flex;align-items:center;gap:6px;padding:8px 0;border-bottom:1px solid var(--border);flex-wrap:wrap}.activity-item:last-child{border-bottom:none}
.activity-badge{padding:2px 5px;border-radius:3px;font-family:var(--f-code);font-size:8px;font-weight:500;text-transform:uppercase;letter-spacing:0.04em;flex-shrink:0}
.badge-sms{background:var(--green-bg);color:var(--green)}.badge-api{background:#E8F4FD;color:#0077CC}.badge-system{background:var(--amber-bg);color:var(--amber)}.badge-schedule{background:var(--blue-bg);color:var(--blue)}.badge-login{background:#F0EFFF;color:#5B4FCF}.badge-default{background:var(--bg-2);color:var(--ink-3)}
.badge-failed{background:var(--red-bg);color:var(--red)}
.activity-detail{flex:1;font-size:11px;color:var(--ink-2);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;min-width:0}
.activity-time{font-family:var(--f-code);font-size:9px;color:var(--ink-4);flex-shrink:0}
.card{background:var(--card);border:1.5px solid var(--border);border-radius:10px;padding:16px;margin-bottom:12px}
.card-title{font-weight:700;font-size:14px;margin-bottom:2px;color:var(--ink)}.card-sub{font-size:11px;color:var(--ink-3);margin-bottom:14px}
.form-group{margin-bottom:12px}
.form-label{display:block;font-family:var(--f-code);font-size:8.5px;font-weight:500;color:var(--ink-3);text-transform:uppercase;letter-spacing:0.06em;margin-bottom:5px}
.form-input,.form-textarea,.form-select{width:100%;padding:10px 12px;background:var(--bg-2);border:1.5px solid var(--border);border-radius:8px;color:var(--ink);font-size:13px;outline:none;transition:border-color 0.15s,background 0.15s;appearance:none;-webkit-appearance:none;font-family:var(--f-ui)}
.form-input:focus,.form-textarea:focus,.form-select:focus{border-color:var(--blue);background:var(--white)}
.form-input::placeholder,.form-textarea::placeholder{color:var(--ink-4);font-size:12px}.form-textarea{resize:vertical;min-height:80px}
.form-select{cursor:pointer;background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%239A9A94' stroke-width='2'%3E%3Cpolyline points='6 9 12 15 18 9'/%3E%3C/svg%3E");background-repeat:no-repeat;background-position:right 10px center;padding-right:32px}
.form-grid{display:flex;flex-direction:column;gap:12px}
.char-counter{font-size:10px;color:var(--ink-3);margin-top:4px;font-family:var(--f-code)}.char-counter .sms-cnt{color:var(--blue);font-weight:500}
.checkbox-group{display:flex;flex-wrap:wrap;gap:10px;margin-top:4px}
.checkbox-item{display:flex;align-items:center;gap:5px;font-size:11px;color:var(--ink-2);cursor:pointer;font-weight:500}
.checkbox-item input[type="checkbox"]{width:14px;height:14px;accent-color:var(--blue);cursor:pointer}
.btn{display:inline-flex;align-items:center;justify-content:center;gap:6px;font-weight:600;font-size:12px;letter-spacing:0.01em;padding:9px 16px;border-radius:8px;border:1.5px solid transparent;cursor:pointer;transition:all 0.15s;-webkit-tap-highlight-color:transparent}
.btn-primary{background:var(--ink);color:var(--white);border-color:var(--ink)}.btn-primary:active{background:#222220;transform:scale(0.97)}
.btn-ghost{background:transparent;border-color:var(--border);color:var(--ink-2)}.btn-ghost:active{background:var(--bg-2)}
.btn-danger{background:var(--red-bg);color:var(--red);border-color:rgba(217,45,32,0.2);font-size:10px;padding:4px 10px;height:28px;font-weight:600}.btn-danger:active{background:rgba(217,45,32,0.1)}
.btn-copy{background:var(--bg-2);border-color:var(--border);color:var(--ink-2);font-size:10px;padding:4px 10px;height:28px;font-weight:600}.btn-copy:active{border-color:var(--blue);color:var(--blue);background:var(--blue-bg)}
.btn-retry{background:var(--amber-bg);color:var(--amber);border-color:rgba(181,71,8,0.2);font-size:10px;padding:4px 10px;height:28px;font-weight:600}
.btn-retry:hover{background:rgba(181,71,8,0.15)}
.status-msg{font-size:11px;margin-left:8px;color:var(--ink-3);transition:color 0.2s;font-weight:500}.status-msg.ok{color:var(--green)}.status-msg.err{color:var(--red)}
.data-table{width:100%;border-collapse:collapse;font-size:11px}
.data-table th{text-align:left;padding:7px 8px;font-family:var(--f-code);font-size:8.5px;font-weight:500;color:var(--ink-3);text-transform:uppercase;letter-spacing:0.05em;border-bottom:1.5px solid var(--border);white-space:nowrap}
.data-table td{padding:9px 8px;font-size:11px;border-bottom:1px solid var(--border);color:var(--ink-2)}.data-table tr:last-child td{border-bottom:none}.data-table tbody tr:active td{background:var(--bg-2)}
.mono{font-family:var(--f-code);font-size:10px}.muted-text{color:var(--ink-3)}.table-wrap{overflow-x:auto;-webkit-overflow-scrolling:touch;margin:-8px;padding:8px}
.key-reveal{background:var(--green-bg);border:1.5px solid rgba(0,135,90,0.2);border-radius:8px;padding:12px;margin-bottom:14px}
.key-reveal .key-text{font-family:var(--f-code);font-size:11px;color:var(--green);word-break:break-all;margin-bottom:4px}.key-reveal .key-warning{font-size:10px;color:var(--amber);font-weight:500}
.endpoint-card{background:var(--card);border:1.5px solid var(--border);border-radius:8px;margin-bottom:8px;overflow:hidden}
.endpoint-header{display:flex;align-items:center;gap:8px;padding:12px 14px;cursor:pointer;user-select:none;-webkit-tap-highlight-color:transparent;flex-wrap:wrap}.endpoint-header:active{background:var(--bg-2)}
.method-badge{padding:2px 6px;border-radius:3px;font-family:var(--f-code);font-size:9px;font-weight:500;flex-shrink:0}.method-post{background:var(--green-bg);color:var(--green)}.method-get{background:#E8F4FD;color:#0077CC}
.endpoint-path{font-family:var(--f-code);font-size:11px;color:var(--ink);flex:1;min-width:0;word-break:break-all}.endpoint-desc{font-size:10px;color:var(--ink-3);width:100%}
.endpoint-arrow{width:12px;height:12px;color:var(--ink-4);transition:transform 0.2s;flex-shrink:0}.endpoint-card.open .endpoint-arrow{transform:rotate(180deg)}
.endpoint-body{display:none;padding:0 14px 14px;border-top:1px solid var(--border);padding-top:12px}.endpoint-card.open .endpoint-body{display:block}
.code-label{font-family:var(--f-code);font-size:8px;color:var(--ink-3);font-weight:500;margin-bottom:3px;text-transform:uppercase;letter-spacing:0.05em}
.code-block{background:var(--bg-2);border:1.5px solid var(--border);border-radius:6px;padding:10px;font-family:var(--f-code);font-size:10px;line-height:1.5;overflow-x:auto;color:var(--ink-2);margin-top:3px;white-space:pre;-webkit-overflow-scrolling:touch}
.empty-state{text-align:center;padding:20px 12px;color:var(--ink-4);font-size:11px}
.login-wrap{min-height:100vh;min-height:100dvh;display:flex;align-items:center;justify-content:center;padding:20px;position:relative;z-index:1;padding-top:calc(20px + var(--safe-top));padding-bottom:calc(20px + var(--safe-bottom))}
.login-box{width:100%;max-width:360px;background:var(--card);border:1.5px solid var(--border);border-radius:14px;overflow:hidden;animation:fadeUp 0.4s var(--ease);box-shadow:0 16px 48px rgba(12,12,10,0.09)}
.login-head{padding:28px 24px 0;text-align:center}
.login-logo-ring{width:44px;height:44px;background:var(--bg-2);border:1.5px solid var(--border);border-radius:10px;display:flex;align-items:center;justify-content:center;margin:0 auto 14px}
.login-body{padding:20px 24px 24px}
.login-error{background:var(--red-bg);border:1px solid rgba(217,45,32,0.2);color:var(--red);padding:8px 10px;border-radius:7px;font-size:11px;margin-bottom:12px;display:none;font-weight:500}.login-error.show{display:block}
.login-foot{padding:0.65rem 1.5rem;border-top:1px solid var(--border);background:var(--bg-2);text-align:center;font-family:var(--f-code);font-size:8.5px;color:var(--ink-4);letter-spacing:0.04em;font-weight:500}.login-foot a{color:var(--blue);font-weight:500}
.toast-wrap{position:fixed;top:calc(var(--nav-h) + var(--sub-h) + 0.5rem);right:0.5rem;left:0.5rem;z-index:400;display:flex;flex-direction:column;gap:0.3rem;pointer-events:none;align-items:flex-end}
.toast{display:flex;align-items:center;gap:0.4rem;padding:0.5rem 0.75rem;background:rgba(255,255,255,0.97);border:1.5px solid var(--border2);border-radius:8px;font-size:11px;font-weight:500;box-shadow:0 4px 16px rgba(12,12,10,0.07);pointer-events:all;animation:t-in 0.3s var(--ease) both;max-width:280px;color:var(--ink)}
@keyframes t-in{from{opacity:0;transform:translateX(8px)}to{opacity:1;transform:none}}.toast.exiting{animation:t-out 0.25s var(--ease) both}@keyframes t-out{to{opacity:0;transform:translateX(8px)}}
.modal-backdrop{position:fixed;inset:0;background:rgba(12,12,10,0.35);z-index:200;display:flex;align-items:center;justify-content:center;padding:1rem;backdrop-filter:blur(2px);-webkit-backdrop-filter:blur(2px)}.modal-backdrop.hidden{display:none}
.modal{background:var(--card);border:1.5px solid var(--border);border-radius:12px;box-shadow:0 16px 48px rgba(12,12,10,0.09);width:100%;max-width:340px;overflow:hidden;animation:fadeUp 0.25s var(--ease)}
.modal-head{padding:0.75rem 1rem;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between}
.modal-title{font-weight:700;font-size:13px;color:var(--ink)}
.modal-close{width:24px;height:24px;border-radius:6px;display:flex;align-items:center;justify-content:center;color:var(--ink-3);cursor:pointer}.modal-close:active{background:var(--bg-2);color:var(--ink)}
.modal-body{padding:1rem}.modal-foot{padding:0.75rem 1rem;border-top:1px solid var(--border);display:flex;justify-content:flex-end;gap:0.4rem}
@media(min-width:600px){.nav{padding:0 1.25rem}.subbar{padding:0 1.25rem}.content-pane{padding:1.5rem}.stat-grid{grid-template-columns:repeat(4,1fr);gap:12px}.form-grid{flex-direction:row;gap:14px}.form-grid>*{flex:1}.tab-label{display:inline}.tab-item{padding:0.35rem 0.75rem;font-size:12px}.chart-bars{height:140px}.nav-ver{display:inline}.data-table th,.data-table td{padding:9px 10px}}
</style>
</head>
<body>

<?php if (!$isAuthed): ?>
<!-- login unchanged – same as previous -->
<div class="login-wrap">
  <div class="login-box">
    <div class="login-head">
      <div class="login-logo-ring">
        <svg width="20" height="20" viewBox="0 0 28 28" fill="none">
          <rect x="2" y="4" width="20" height="20" rx="5" fill="#F0F0ED" stroke="#D0D0CA" stroke-width="1.5"/>
          <line x1="7" y1="10" x2="17" y2="10" stroke="#9A9A94" stroke-width="1.5" stroke-linecap="round"/>
          <line x1="7" y1="14" x2="14" y2="14" stroke="#9A9A94" stroke-width="1.5" stroke-linecap="round"/>
          <line x1="7" y1="18" x2="12" y2="18" stroke="#9A9A94" stroke-width="1.5" stroke-linecap="round"/>
          <path d="M18 16 L23 12 L23 20 Z" fill="#0057FF"/>
        </svg>
      </div>
      <div style="font-weight:700;font-size:22px;margin-bottom:3px;color:#0C0C0A;">SMS<span style="color:#0057FF;">KIT</span></div>
      <div style="font-size:10px;color:#9A9A94;margin-bottom:0;padding-bottom:20px;font-family:'Fira Code',monospace;letter-spacing:0.06em;text-transform:uppercase;font-weight:500;">SMS Gateway</div>
    </div>
    <div class="login-body">
      <div class="login-error" id="loginError"></div>
      <form id="loginForm">
        <div class="form-group"><label class="form-label">Username</label><input type="text" name="username" class="form-input" placeholder="Enter username" required autofocus autocomplete="username"></div>
        <div class="form-group" style="margin-bottom:16px"><label class="form-label">Password</label><input type="password" name="password" class="form-input" placeholder="••••••••••" required autocomplete="current-password"></div>
        <button type="submit" class="btn btn-primary" style="width:100%"><svg width="12" height="12" viewBox="0 0 14 14" fill="none"><path d="M2 7H12M8 3L12 7L8 11" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>Sign In</button>
      </form>
    </div>
    <div class="login-foot">SMSKIT v<?php echo htmlspecialchars($settings['version'] ?? '1.0.0'); ?> · <a href="https://t.me/envrc">@envrc</a></div>
  </div>
</div>
<script>(function(){document.getElementById('loginForm').addEventListener('submit',function(e){e.preventDefault();var err=document.getElementById('loginError');var data=new FormData(this);fetch('?login=1',{method:'POST',body:data}).then(r=>r.json()).then(d=>{if(d.ok){location.replace(location.pathname)}else{err.textContent=d.error||'Invalid credentials';err.classList.add('show')}}).catch(()=>{err.textContent='Connection error';err.classList.add('show')})})})();</script>
<?php else: ?>
<?php if ($allDevicesOffline): ?>
<div class="alert-banner">
  <svg width="12" height="12" viewBox="0 0 12 12" fill="none"><path d="M6 1L11 10H1L6 1Z" stroke="var(--amber)" stroke-width="1.25" stroke-linejoin="round"/><circle cx="6" cy="8.5" r=".5" fill="var(--amber)"/></svg>
  No devices online — messages will queue until a device connects.
</div>
<?php endif; ?>

<nav class="nav">
  <div class="nav-brand" onclick="showSection('status')">
    <div class="nav-logo"><svg width="14" height="14" viewBox="0 0 28 28" fill="none"><rect x="2" y="4" width="20" height="20" rx="5" fill="#0C0C0A" stroke="#4A4A46" stroke-width="1.5"/><line x1="7" y1="10" x2="17" y2="10" stroke="#C8C8C2" stroke-width="1.5" stroke-linecap="round"/><line x1="7" y1="14" x2="14" y2="14" stroke="#C8C8C2" stroke-width="1.5" stroke-linecap="round"/><line x1="7" y1="18" x2="12" y2="18" stroke="#C8C8C2" stroke-width="1.5" stroke-linecap="round"/><path d="M18 16 L23 12 L23 20 Z" fill="#FFFFFF"/></svg></div>
    <div class="nav-wordmark">SMS<span>KIT</span></div>
    <span class="nav-ver">v<?php echo htmlspecialchars($settings['version'] ?? '1.0'); ?></span>
  </div>
  <div class="nav-actions">
    <div class="device-pill <?php echo $allDevicesOffline ? 'offline' : ''; ?>">
      <div class="device-dot"></div>
      <span><?php echo count($onlineDevices); ?> on</span>
    </div>
    <a href="?logout=1" class="nav-btn icon-only danger" title="Sign Out"><svg width="13" height="13" viewBox="0 0 14 14" fill="none"><path d="M9 2H11.5C12 2 12.5 2.5 12.5 3V11C12.5 11.5 12 12 11.5 12H9" stroke="currentColor" stroke-width="1.3" stroke-linecap="round"/><path d="M6 9.5L9 7L6 4.5M9 7H1.5" stroke="currentColor" stroke-width="1.3" stroke-linecap="round" stroke-linejoin="round"/></svg></a>
  </div>
</nav>

<div class="subbar">
  <div class="subbar-left">
    <div class="tab-nav" id="tabNav">
      <button class="tab-item active" data-section="status" onclick="showSection('status')"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 3v18h18"/><path d="M18 17V9"/><path d="M13 17V5"/><path d="M8 17v-3"/></svg><span class="tab-label">Status</span></button>
      <button class="tab-item" data-section="playground" onclick="showSection('playground')"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg><span class="tab-label">Send</span></button>
      <button class="tab-item" data-section="schedule" onclick="showSection('schedule')"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg><span class="tab-label">Schedule</span></button>
      <button class="tab-item" data-section="templates" onclick="showSection('templates')"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2"/><line x1="3" y1="9" x2="21" y2="9"/><line x1="9" y1="21" x2="9" y2="9"/></svg><span class="tab-label">Templates</span></button>
      <button class="tab-item" data-section="devices" onclick="showSection('devices')"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="5" y="2" width="14" height="20" rx="2"/><line x1="12" y1="18" x2="12.01" y2="18"/></svg><span class="tab-label">Devices</span></button>
      <button class="tab-item" data-section="apikeys" onclick="showSection('apikeys')"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 2l-2 2m-7.61 7.61a5.5 5.5 0 1 1-7.778 7.778 5.5 5.5 0 0 1 7.777-7.777zm0 0L15.5 7.5m0 0l3 3L22 7l-3-3m-3.5 3.5L19 4"/></svg><span class="tab-label">Keys</span></button>
      <button class="tab-item" data-section="docs" onclick="showSection('docs')"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg><span class="tab-label">API</span></button>
      <button class="tab-item" data-section="export" onclick="showSection('export')"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg><span class="tab-label">Export</span></button>
    </div>
  </div>
</div>

<div class="workspace"><div class="content-pane" id="contentPane">

<div class="section active" id="section-status">
  <div class="stat-grid">
    <div class="stat-card"><div class="stat-label">Total Sent</div><div class="stat-value"><?php echo number_format($totalSent); ?></div></div>
    <div class="stat-card"><div class="stat-label">This Month</div><div class="stat-value accent"><?php echo number_format($monthSent); ?></div></div>
    <div class="stat-card"><div class="stat-label">This Week</div><div class="stat-value"><?php echo number_format($weekSent); ?></div></div>
    <div class="stat-card"><div class="stat-label">Queued</div><div class="stat-value accent"><?php echo number_format($queuedCount); ?></div></div>
  </div>
  <?php if (!empty($failedItems)): ?>
  <div class="panel" style="border-color:rgba(217,45,32,0.3);background:var(--red-bg)">
    <div class="panel-title" style="color:var(--red)">Failed Messages (<?php echo count($failedItems); ?>)</div>
    <div class="table-wrap">
      <table class="data-table">
        <thead><tr><th>To</th><th>Message</th><th>Error</th><th></th></tr></thead>
        <tbody>
          <?php foreach ($failedItems as $fi): ?>
          <tr>
            <td class="mono"><?php echo htmlspecialchars($fi['to'] ?? ''); ?></td>
            <td><?php echo htmlspecialchars(substr($fi['message'] ?? '', 0, 30)); ?></td>
            <td style="color:var(--red);font-size:10px"><?php echo htmlspecialchars($fi['error'] ?? 'Unknown'); ?></td>
            <td><button class="btn btn-retry" onclick="retrySms('<?php echo htmlspecialchars($fi['id'] ?? ''); ?>', this)">Retry</button></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php endif; ?>
  <div class="chart-card">
    <div class="chart-header"><div class="chart-title">Last 7 Days</div><div class="chart-sub"><?php echo $weekSent; ?> SMS</div></div>
    <div class="chart-bars">
      <?php foreach ($orderedDays as $i => $day): ?>
      <div class="chart-bar-wrap"><div class="chart-val"><?php echo $orderedCounts[$i]; ?></div><div class="chart-bar <?php echo $i === 6 ? 'today' : 'past'; ?>" style="height:<?php echo round(($orderedCounts[$i] / $maxCount) * 100); ?>%"></div><div class="chart-label"><?php echo $day; ?></div></div>
      <?php endforeach; ?>
    </div>
  </div>
  <div class="panel">
    <div class="panel-title">Recent Activity</div>
    <?php if (empty($recentLogs)): ?><div class="empty-state">No activity yet</div>
    <?php else: ?><div class="activity-list">
      <?php foreach ($recentLogs as $log): $type=$log['type']??'unknown';$bc=match(true){str_starts_with($type,'sms_')=>'badge-sms',str_starts_with($type,'api_')=>'badge-api',$type==='system'=>'badge-system',$type==='schedule'=>'badge-schedule',$type==='login'=>'badge-login',default=>'badge-default'};?>
      <div class="activity-item"><span class="activity-badge <?php echo $bc; ?>"><?php echo htmlspecialchars($type); ?></span><span class="activity-detail"><?php echo htmlspecialchars($log['detail']??''); ?></span><span class="activity-time"><?php echo date('H:i',strtotime($log['timestamp']??'0')); ?></span></div>
      <?php endforeach; ?>
    </div><?php endif; ?>
  </div>
</div>

<div class="section" id="section-playground">
  <div class="card">
    <div class="card-title">Send SMS</div><div class="card-sub">Select template, enter numbers and message.</div>
    <div class="form-group">
      <label class="form-label">Template <span style="font-style:italic;text-transform:none;color:var(--ink-4);">(optional)</span></label>
      <select id="sendTemplate" class="form-select" onchange="applyTemplate()">
        <option value="">-- Choose a template --</option>
        <?php foreach ($templates as $tpl): ?>
        <option value="<?php echo htmlspecialchars($tpl['id']); ?>" data-message="<?php echo htmlspecialchars($tpl['message']); ?>"><?php echo htmlspecialchars($tpl['name']); ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="form-group"><label class="form-label">Phone Numbers <span style="font-style:italic;text-transform:none;color:var(--ink-4);font-weight:400;">(comma-separated)</span></label><input type="text" id="sendNumbers" class="form-input" placeholder="+8801712345678, +8801712345679"></div>
    <div class="form-group"><label class="form-label">Message</label><textarea id="sendMessage" class="form-textarea" placeholder="Your message…" maxlength="1600"></textarea><div class="char-counter"><span id="charCount">0</span>/160 · <span class="sms-cnt" id="smsCount">1</span> SMS</div></div>
    <div class="form-group">
      <label class="form-label">Send via Device</label>
      <select id="sendDevice" class="form-select">
        <option value="auto">Auto (best available)</option>
        <?php foreach ($onlineDevices as $dev): ?>
        <option value="<?php echo htmlspecialchars($dev['device_id']); ?>"><?php echo htmlspecialchars(substr($dev['device_id'],0,8).'… ('.$dev['model'].')'); ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div style="display:flex;align-items:center;flex-wrap:wrap;gap:8px;"><button class="btn btn-primary" onclick="doSend()"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>Send</button><span class="status-msg" id="sendStatus"></span></div>
  </div>
  <div class="card">
    <div class="card-title">Recent Sends</div><div class="card-sub">Last 10 SMS dispatched.</div>
    <?php if (empty($recentSends)): ?><div class="empty-state">No sends yet</div>
    <?php else: ?><div class="table-wrap"><table class="data-table"><thead><tr><th>Time</th><th>Details</th></tr></thead><tbody>
      <?php foreach ($recentSends as $s): ?><tr><td class="mono muted-text"><?php echo date('M d H:i',strtotime($s['timestamp']??'0')); ?></td><td><?php echo htmlspecialchars($s['detail']??''); ?></td></tr><?php endforeach; ?>
    </tbody></table></div><?php endif; ?>
  </div>
</div>

<div class="section" id="section-schedule">
  <div class="card">
    <div class="card-title">Schedule SMS</div><div class="card-sub">Set a time and your device will send automatically.</div>
    <div class="form-group"><label class="form-label">Phone Numbers</label><input type="text" id="schedNumbers" class="form-input" placeholder="+8801712345678"></div>
    <div class="form-group"><label class="form-label">Message</label><textarea id="schedMessage" class="form-textarea" placeholder="Scheduled message…" maxlength="1600"></textarea><div class="char-counter"><span id="schedCharCount">0</span>/160 · <span class="sms-cnt" id="schedSmsCount">1</span> SMS</div></div>
    <div class="form-grid">
      <div class="form-group" style="margin-bottom:0"><label class="form-label">Schedule Time <span style="font-style:italic;text-transform:none;color:var(--ink-4);font-weight:400;">(24‑hour)</span></label><input type="datetime-local" id="schedTime" class="form-input"></div>
      <div class="form-group" style="margin-bottom:0"><label class="form-label">Recurring</label><select id="schedRecurring" class="form-select"><option value="none">None</option><option value="daily">Daily</option><option value="weekly">Weekly</option><option value="monthly">Monthly</option></select></div>
    </div>
    <div style="display:flex;align-items:center;flex-wrap:wrap;gap:8px;margin-top:12px;"><button class="btn btn-primary" onclick="doSchedule()"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>Schedule</button><span class="status-msg" id="schedStatus"></span></div>
  </div>
  <div class="card">
    <div class="card-title">Pending Schedules</div><div class="card-sub">Only shows messages waiting to be sent.</div>
    <?php if (empty($scheduledSms)): ?><div class="empty-state" id="schedEmpty">No pending schedules</div>
    <?php else: ?><div class="table-wrap"><table class="data-table" id="schedTable"><thead><tr><th>Numbers</th><th>Message</th><th>Time</th><th>Recur</th><th></th></tr></thead><tbody>
      <?php foreach ($scheduledSms as $sc): ?><tr data-id="<?php echo htmlspecialchars($sc['id']??''); ?>"><td class="mono"><?php echo htmlspecialchars(implode(', ',array_slice($sc['numbers']??[],0,2))); ?></td><td><?php echo htmlspecialchars(substr($sc['message']??'',0,30).(strlen($sc['message']??'')>30?'…':'')); ?></td><td class="mono muted-text"><?php echo htmlspecialchars($sc['schedule_time']??''); ?></td><td><span class="activity-badge badge-default"><?php echo htmlspecialchars($sc['recurring']??'none'); ?></span></td><td><button class="btn btn-danger" onclick="deleteSchedule('<?php echo htmlspecialchars($sc['id']??''); ?>',this)">Del</button></td></tr><?php endforeach; ?>
    </tbody></table></div><?php endif; ?>
  </div>
</div>

<div class="section" id="section-templates">
  <div class="card">
    <div class="card-title">Message Templates</div><div class="card-sub">Use variables like {name}, {code}, {otp} in your messages.</div>
    <div class="table-wrap">
      <table class="data-table">
        <thead><tr><th>Name</th><th>Message</th><th>Variables</th><th></th></tr></thead>
        <tbody id="templatesTable">
          <?php foreach ($templates as $t): ?>
          <tr data-id="<?php echo htmlspecialchars($t['id']); ?>">
            <td><?php echo htmlspecialchars($t['name']); ?></td>
            <td><?php echo htmlspecialchars(substr($t['message'],0,40).(strlen($t['message'])>40?'…':'')); ?></td>
            <td class="mono muted-text"><?php echo htmlspecialchars(implode(', ',$t['variables']??[])); ?></td>
            <td>
              <button class="btn btn-ghost" style="padding:2px 8px;font-size:10px" onclick="editTemplate('<?php echo htmlspecialchars($t['id']); ?>')">Edit</button>
              <button class="btn btn-danger" style="padding:2px 8px;font-size:10px" onclick="deleteTemplate('<?php echo htmlspecialchars($t['id']); ?>')">Del</button>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <button class="btn btn-primary" onclick="showTemplateForm()" style="margin-top:12px">+ New Template</button>
  </div>
  <div class="card hidden" id="templateFormCard">
    <div class="card-title" id="templateFormTitle">New Template</div>
    <input type="hidden" id="tplId">
    <div class="form-group"><label class="form-label">Template Name</label><input type="text" id="tplName" class="form-input" placeholder="e.g. OTP"></div>
    <div class="form-group"><label class="form-label">Message (use {var})</label><textarea id="tplMessage" class="form-textarea" placeholder="Your code is {code}"></textarea></div>
    <div class="form-group"><label class="form-label">Variables (comma separated)</label><input type="text" id="tplVars" class="form-input" placeholder="code,name,otp"></div>
    <div style="display:flex;gap:8px">
      <button class="btn btn-primary" onclick="saveTemplate()">Save</button>
      <button class="btn btn-ghost" onclick="hideTemplateForm()">Cancel</button>
    </div>
  </div>
</div>

<div class="section" id="section-devices">
  <div class="card">
    <div class="card-title">Registered Devices</div>
    <?php if (empty($devices)): ?><div class="empty-state">No devices registered yet</div>
    <?php else: ?>
    <div class="table-wrap">
      <table class="data-table">
        <thead><tr><th>Device ID</th><th>Model</th><th>Android</th><th>Status</th><th>Last Seen</th><th>SMS Sent</th></tr></thead>
        <tbody>
          <?php foreach ($devices as $d): 
            $online = (time() - strtotime($d['last_seen']??'0')) < 600;
          ?>
          <tr>
            <td class="mono"><?php echo htmlspecialchars(substr($d['device_id']??'',0,12)); ?></td>
            <td><?php echo htmlspecialchars($d['model']??'?'); ?></td>
            <td><?php echo htmlspecialchars($d['android']??'?'); ?></td>
            <td><span class="activity-badge <?php echo $online?'badge-sms':'badge-failed'; ?>"><?php echo $online?'Online':'Offline'; ?></span></td>
            <td class="muted-text mono"><?php echo htmlspecialchars($d['last_seen']??''); ?></td>
            <td><?php echo number_format($d['sms_sent_count']??0); ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>
</div>

<div class="section" id="section-apikeys">
  <div class="card">
    <div class="card-title">Generate API Key</div><div class="card-sub">Create a new key for API access.</div>
    <div class="key-reveal" id="keyReveal" style="display:none;"><div class="key-text" id="keyText"></div><div class="key-warning">⚠ Save this key — it won't be shown again.</div><button class="btn btn-copy" onclick="copyKey()" style="margin-top:6px;">Copy Key</button></div>
    <div class="form-group"><label class="form-label">Key Name</label><input type="text" id="keyName" class="form-input" placeholder="My App Key"></div>
    <div class="form-group"><label class="form-label">Rate Limit (req/hour)</label><input type="number" id="keyRate" class="form-input" value="100" min="1" max="10000"></div>
    <div class="form-group"><label class="form-label">Permissions</label><div class="checkbox-group"><label class="checkbox-item"><input type="checkbox" id="perm-send" value="send" checked> Send</label><label class="checkbox-item"><input type="checkbox" id="perm-status" value="status" checked> Status</label><label class="checkbox-item"><input type="checkbox" id="perm-schedule" value="schedule"> Schedule</label><label class="checkbox-item"><input type="checkbox" id="perm-stats" value="stats"> Stats</label></div></div>
    <div style="display:flex;align-items:center;flex-wrap:wrap;gap:8px;"><button class="btn btn-primary" onclick="genApiKey()">Generate</button><span class="status-msg" id="keyStatus"></span></div>
  </div>
  <div class="card">
    <div class="card-title">Active API Keys</div><div class="card-sub">Manage existing keys.</div>
    <?php if (empty($apiKeys)): ?><div class="empty-state">No API keys yet</div>
    <?php else: ?><div class="table-wrap"><table class="data-table"><thead><tr><th>Name</th><th>Preview</th><th>Perms</th><th>Rate</th><th></th></tr></thead><tbody>
      <?php foreach ($apiKeys as $key): ?><tr data-id="<?php echo htmlspecialchars($key['id']??''); ?>"><td><?php echo htmlspecialchars($key['name']??''); ?></td><td class="mono"><?php echo htmlspecialchars($key['preview']??''); ?></td><td><?php echo htmlspecialchars(implode(', ',array_slice($key['permissions']??[],0,2))); ?></td><td class="muted-text"><?php echo $key['rate_limit']??100; ?>/h</td><td><button class="btn btn-danger" onclick="revokeKey('<?php echo htmlspecialchars($key['id']??''); ?>',this)">Revoke</button></td></tr><?php endforeach; ?>
    </tbody></table></div><?php endif; ?>
  </div>
</div>

<div class="section" id="section-docs">
  <div style="margin-bottom:14px;"><h2 style="font-weight:700;font-size:16px;margin-bottom:4px;color:var(--ink);">API Reference</h2><p style="font-size:12px;color:var(--ink-3);">Base: <span class="mono" style="color:var(--blue);"><?php echo htmlspecialchars($baseUrl); ?>/api/v1</span></p></div>
  <div class="code-block" style="margin-bottom:14px;border-radius:8px;">Authorization: Bearer sk_live_xxxxxxxxxxxxxxxx</div>
  <?php $endpoints=[['POST','/send.php','Send SMS','{ "numbers": ["+8801712345678"], "message": "Hello!" }','{ "ok": true, "message_id": "msg_abc", "count": 1 }',"curl -X POST \"$baseUrl/api/v1/send.php\" \\\n  -H \"Authorization: Bearer KEY\" \\\n  -H \"Content-Type: application/json\" \\\n  -d '{\"numbers\":[\"+8801712345678\"],\"message\":\"Hi\"}'"],['GET','/status.php?id=xxx','Check status',null,'{ "ok": true, "message_id": "msg_abc", "status": "sent" }',"curl \"$baseUrl/api/v1/status.php?id=msg_abc\" \\\n  -H \"Authorization: Bearer KEY\""],['GET','/statistics.php','Usage stats',null,'{ "ok": true, "total_sent": 1523, "today": 45 }',"curl \"$baseUrl/api/v1/statistics.php\" \\\n  -H \"Authorization: Bearer KEY\""],['POST','/validate.php','Validate number','{ "number": "+8801712345678" }','{ "ok": true, "valid": true, "country": "BD" }',"curl -X POST \"$baseUrl/api/v1/validate.php\" \\\n  -H \"Authorization: Bearer KEY\" \\\n  -H \"Content-Type: application/json\" \\\n  -d '{\"number\":\"+8801712345678\"}'"],['POST','/schedule.php','Schedule SMS','{ "numbers": ["+8801712345678"], "message": "Hello", "schedule_time": "2026-05-18T10:30", "recurring": "none" }','{ "ok": true, "sched_id": "sched_xxx" }',"curl -X POST \"$baseUrl/api/v1/schedule.php\" \\\n  -H \"Authorization: Bearer KEY\" \\\n  -H \"Content-Type: application/json\" \\\n  -d '{\"numbers\":[\"+8801712345678\"],\"message\":\"Hi\",\"schedule_time\":\"2026-05-18T10:30\",\"recurring\":\"none\"}'"]];foreach($endpoints as $ep):[$method,$path,$desc,$req,$res,$curl]=$ep;$cls=$method==='POST'?'method-post':'method-get';?>
  <div class="endpoint-card"><div class="endpoint-header" onclick="toggleEndpoint(this)"><span class="method-badge <?php echo $cls; ?>"><?php echo $method; ?></span><span class="endpoint-path"><?php echo htmlspecialchars($path); ?></span><span class="endpoint-desc"><?php echo htmlspecialchars($desc); ?></span><svg class="endpoint-arrow" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9"/></svg></div><div class="endpoint-body"><?php if($req): ?><div class="code-label">Request</div><div class="code-block"><?php echo htmlspecialchars($req); ?></div><?php endif; ?><div class="code-label" style="margin-top:10px;">Response</div><div class="code-block"><?php echo htmlspecialchars($res); ?></div><div class="code-label" style="margin-top:10px;">cURL</div><div class="code-block"><?php echo htmlspecialchars($curl); ?></div></div></div>
  <?php endforeach; ?>
</div>

<div class="section" id="section-export">
  <div class="card">
    <div class="card-title">Export Data</div>
    <div class="card-sub">Download your data as CSV files.</div>
    <div style="display:flex;flex-direction:column;gap:10px">
      <a href="?export=csv&type=sent" class="btn btn-primary" style="text-decoration:none">Export Sent SMS</a>
      <a href="?export=csv&type=received" class="btn btn-primary" style="text-decoration:none">Export Received SMS</a>
      <a href="?export=csv&type=scheduled" class="btn btn-primary" style="text-decoration:none">Export Scheduled</a>
      <a href="?export=csv&type=queue" class="btn btn-primary" style="text-decoration:none">Export Queue</a>
    </div>
  </div>
</div>

</div></div>

<div class="modal-backdrop hidden" id="deleteModal"><div class="modal"><div class="modal-head"><span class="modal-title">Confirm Delete</span><button class="modal-close" onclick="closeModal('deleteModal')"><svg width="10" height="10" viewBox="0 0 12 12" fill="none"><path d="M2 2L10 10M10 2L2 10" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg></button></div><div class="modal-body"><p style="font-size:12px;color:var(--ink-3);line-height:1.5;">Delete <strong id="deleteItemName" style="color:var(--ink);"></strong>?</p></div><div class="modal-foot"><button class="btn btn-ghost" onclick="closeModal('deleteModal')" style="font-size:11px;">Cancel</button><button class="btn btn-danger" id="deleteConfirmBtn" style="font-size:11px;height:32px;padding:0 14px;">Delete</button></div></div></div>
<div class="toast-wrap" id="toastWrap"></div>

<script>
const pageTitles={status:'Status',playground:'Send',schedule:'Schedule',templates:'Templates',devices:'Devices',apikeys:'API Keys',docs:'API Docs',export:'Export'};
window.showSection=function(name){document.querySelectorAll('.section').forEach(s=>s.classList.remove('active'));document.querySelectorAll('.tab-item').forEach(t=>t.classList.remove('active'));const sec=document.getElementById('section-'+name);if(sec)sec.classList.add('active');const tab=document.querySelector('.tab-item[data-section="'+name+'"]');if(tab)tab.classList.add('active');if(tab)tab.scrollIntoView({behavior:'smooth',block:'nearest',inline:'center'});history.replaceState(null,'','#'+name);};
document.getElementById('sendMessage').addEventListener('input',function(){const l=this.value.length;document.getElementById('charCount').textContent=l;document.getElementById('smsCount').textContent=Math.ceil(l/160)||1;});
document.getElementById('schedMessage').addEventListener('input',function(){const l=this.value.length;document.getElementById('schedCharCount').textContent=l;document.getElementById('schedSmsCount').textContent=Math.ceil(l/160)||1;});
(function(){const d=new Date();d.setHours(d.getHours()+1);d.setMinutes(0);d.setSeconds(0);const pad=n=>n<10?'0'+n:n;const el=document.getElementById('schedTime');if(el)el.value=d.getFullYear()+'-'+pad(d.getMonth()+1)+'-'+pad(d.getDate())+'T'+pad(d.getHours())+':'+pad(d.getMinutes());})();

window.applyTemplate = function(){
  var sel = document.getElementById('sendTemplate');
  var opt = sel.options[sel.selectedIndex];
  if(opt && opt.dataset.message){
    document.getElementById('sendMessage').value = opt.dataset.message;
    document.getElementById('sendMessage').dispatchEvent(new Event('input'));
  }
};

window.doSend=async function(){
  const numbers=document.getElementById('sendNumbers').value.trim();
  const message=document.getElementById('sendMessage').value.trim();
  const device=document.getElementById('sendDevice').value;
  const statusEl=document.getElementById('sendStatus');
  if(!numbers||!message){toast('Fill in all fields','err');return;}
  statusEl.textContent='Sending…';statusEl.className='status-msg';
  const fd=new FormData();fd.append('numbers',numbers);fd.append('message',message);fd.append('device',device);
  try{
    const r=await fetch('?action=send_sms',{method:'POST',body:fd});
    const d=await r.json();
    if(d.ok){
      statusEl.textContent='Queued '+d.count+' num(s) via '+d.device;statusEl.className='status-msg ok';
      document.getElementById('sendNumbers').value='';document.getElementById('sendMessage').value='';
      document.getElementById('charCount').textContent='0';document.getElementById('smsCount').textContent='1';
      toast('SMS queued','ok');
    }else{statusEl.textContent=d.error||'Failed';statusEl.className='status-msg err';toast(d.error||'Send failed','err');}
  }catch(e){statusEl.textContent='Error';statusEl.className='status-msg err';toast('Connection error','err');}
};

window.doSchedule=async function(){
  const numbers=document.getElementById('schedNumbers').value.trim();
  const message=document.getElementById('schedMessage').value.trim();
  const time=document.getElementById('schedTime').value;
  const recurring=document.getElementById('schedRecurring').value;
  const statusEl=document.getElementById('schedStatus');
  if(!numbers||!message||!time){toast('Fill in all fields','err');return;}
  statusEl.textContent='Scheduling…';statusEl.className='status-msg';
  const fd=new FormData();fd.append('numbers',numbers);fd.append('message',message);fd.append('schedule_time',time);fd.append('recurring',recurring);
  try{
    const r=await fetch('?action=schedule_sms',{method:'POST',body:fd});
    const d=await r.json();
    if(d.ok){statusEl.textContent='Scheduled!';statusEl.className='status-msg ok';document.getElementById('schedNumbers').value='';document.getElementById('schedMessage').value='';toast('SMS scheduled','ok');}
    else{statusEl.textContent=d.error||'Failed';statusEl.className='status-msg err';toast(d.error||'Failed','err');}
  }catch(e){statusEl.textContent='Error';statusEl.className='status-msg err';toast('Connection error','err');}
};

window.deleteSchedule=function(id,btn){document.getElementById('deleteItemName').textContent='this scheduled message';openModal('deleteModal');document.getElementById('deleteConfirmBtn').onclick=async()=>{closeModal('deleteModal');const fd=new FormData();fd.append('sched_id',id);const r=await fetch('?action=del_schedule',{method:'POST',body:fd});const d=await r.json();if(d.ok){const row=btn.closest('tr');if(row)row.remove();toast('Deleted','ok');}else toast('Failed','err');};};
window.retrySms=async function(id,btn){const fd=new FormData();fd.append('msg_id',id);const r=await fetch('?action=retry_sms',{method:'POST',body:fd});const d=await r.json();if(d.ok){const row=btn.closest('tr');if(row)row.remove();toast('Retrying…','ok');}else toast('Retry failed','err');};

window.genApiKey=async function(){
  const name=document.getElementById('keyName').value.trim();
  const rate=document.getElementById('keyRate').value;
  const statusEl=document.getElementById('keyStatus');
  if(!name){toast('Key name required','err');return;}
  statusEl.textContent='Generating…';statusEl.className='status-msg';
  const perms=['perm-send','perm-status','perm-schedule','perm-stats'].filter(id=>document.getElementById(id).checked).map(id=>document.getElementById(id).value);
  const fd=new FormData();fd.append('key_name',name);fd.append('rate_limit',rate);perms.forEach(p=>fd.append('permissions[]',p));
  try{
    const r=await fetch('?action=gen_api_key',{method:'POST',body:fd});
    const d=await r.json();
    if(d.ok){document.getElementById('keyText').textContent=d.key;document.getElementById('keyReveal').style.display='block';statusEl.textContent='Generated!';statusEl.className='status-msg ok';document.getElementById('keyName').value='';toast('Key generated — copy it now!','ok');}
    else{statusEl.textContent=d.error||'Failed';statusEl.className='status-msg err';toast(d.error||'Failed','err');}
  }catch(e){statusEl.textContent='Error';statusEl.className='status-msg err';toast('Connection error','err');}
};
window.copyKey=function(){const key=document.getElementById('keyText').textContent;if(navigator.clipboard){navigator.clipboard.writeText(key).then(()=>toast('Copied!','ok'));}else{const ta=document.createElement('textarea');ta.value=key;document.body.appendChild(ta);ta.select();document.execCommand('copy');document.body.removeChild(ta);toast('Copied!','ok');}};
window.revokeKey=function(id,btn){document.getElementById('deleteItemName').textContent='this API key';openModal('deleteModal');document.getElementById('deleteConfirmBtn').onclick=async()=>{closeModal('deleteModal');const fd=new FormData();fd.append('key_id',id);const r=await fetch('?action=del_api_key',{method:'POST',body:fd});const d=await r.json();if(d.ok){const row=btn.closest('tr');if(row)row.remove();toast('Revoked','ok');}else toast('Failed','err');};};
window.toggleEndpoint=function(header){header.closest('.endpoint-card').classList.toggle('open');};
function openModal(id){document.getElementById(id).classList.remove('hidden');}function closeModal(id){document.getElementById(id).classList.add('hidden');}
document.querySelectorAll('.modal-backdrop').forEach(bd=>{bd.addEventListener('click',e=>{if(e.target===bd)bd.classList.add('hidden');});});
const toastIcons={ok:`<svg width="10" height="10" viewBox="0 0 12 12" fill="none"><path d="M2 6L5 9L10 3" stroke="var(--green)" stroke-width="1.5" stroke-linecap="round"/></svg>`,err:`<svg width="10" height="10" viewBox="0 0 12 12" fill="none"><path d="M2 2L10 10M10 2L2 10" stroke="var(--red)" stroke-width="1.5" stroke-linecap="round"/></svg>`};
function toast(msg,type){const el=document.createElement('div');el.className='toast';el.innerHTML=`<span>${(toastIcons[type]||toastIcons.ok)}</span><span>${msg}</span>`;document.getElementById('toastWrap').appendChild(el);setTimeout(()=>{el.classList.add('exiting');setTimeout(()=>el.remove(),300);},2500);}

window.showTemplateForm = function(id){
  document.getElementById('templateFormCard').classList.remove('hidden');
  if(id){
    const tpl = window.allTemplates.find(t=>t.id===id);
    if(tpl){
      document.getElementById('tplId').value = tpl.id;
      document.getElementById('tplName').value = tpl.name;
      document.getElementById('tplMessage').value = tpl.message;
      document.getElementById('tplVars').value = (tpl.variables||[]).join(',');
      document.getElementById('templateFormTitle').textContent = 'Edit Template';
    }
  } else {
    document.getElementById('tplId').value = '';
    document.getElementById('tplName').value = '';
    document.getElementById('tplMessage').value = '';
    document.getElementById('tplVars').value = '';
    document.getElementById('templateFormTitle').textContent = 'New Template';
  }
};
window.hideTemplateForm = function(){ document.getElementById('templateFormCard').classList.add('hidden'); };
window.editTemplate = function(id){ window.showTemplateForm(id); };

window.saveTemplate = async function(){
  const id = document.getElementById('tplId').value;
  const name = document.getElementById('tplName').value.trim();
  const message = document.getElementById('tplMessage').value.trim();
  const vars = document.getElementById('tplVars').value.split(',').map(s=>s.trim()).filter(Boolean);
  if(!name||!message){toast('Name and message required','err');return;}
  const fd=new FormData();fd.append('id',id);fd.append('name',name);fd.append('message',message);fd.append('variables',JSON.stringify(vars));
  const r=await fetch('?action=save_template',{method:'POST',body:fd});const d=await r.json();
  if(d.ok){location.reload();}else{toast(d.error||'Failed','err');}
};
window.deleteTemplate = async function(id){
  if(!confirm('Delete this template?'))return;
  const fd=new FormData();fd.append('id',id);
  const r=await fetch('?action=del_template',{method:'POST',body:fd});const d=await r.json();
  if(d.ok){const row=document.querySelector(`#templatesTable tr[data-id="${id}"]`);if(row)row.remove();toast('Deleted','ok');}
  else toast('Failed','err');
};

window.allTemplates = <?php echo json_encode($templates); ?>;

let autoRefreshInterval = setInterval(function(){
  fetch(window.location.href).then(r=>r.text()).then(html=>{
    const parser = new DOMParser();
    const doc = parser.parseFromString(html, 'text/html');
    const newContent = doc.getElementById('contentPane');
    if(newContent){
      const currentSection = document.querySelector('.section.active');
      const sectionId = currentSection ? currentSection.id : 'section-status';
      document.getElementById('contentPane').innerHTML = newContent.innerHTML;
      document.querySelectorAll('.section').forEach(s=>s.classList.remove('active'));
      const sec = document.getElementById(sectionId);
      if(sec) sec.classList.add('active');
    }
  }).catch(()=>{});
}, 30000);

(function boot(){
  const hash=location.hash.replace('#','');
  const valid=['status','playground','schedule','templates','devices','apikeys','docs','export'];
  showSection(valid.includes(hash)?hash:'status');
})();
</script>
<?php endif; ?>
</body>
</html>