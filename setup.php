<?php
if (file_exists(__DIR__ . '/config/admin.json')) {
    header('Location: dashboard/');
    exit;
}

error_reporting(E_ALL);
ini_set('display_errors', 1);

$step = isset($_POST['step']) ? (int)$_POST['step'] : 1;
$error = '';
$success = false;

$timezones = DateTimeZone::listIdentifiers();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $step === 2) {
    $username = isset($_POST['username']) ? trim($_POST['username']) : '';
    $email = isset($_POST['email']) ? trim($_POST['email']) : '';
    $password = isset($_POST['password']) ? $_POST['password'] : '';
    $password_confirm = isset($_POST['password_confirm']) ? $_POST['password_confirm'] : '';
    $gateway_name = isset($_POST['gateway_name']) ? trim($_POST['gateway_name']) : '';
    $timezone = isset($_POST['timezone']) ? $_POST['timezone'] : 'UTC';

    $errors = [];

    if (strlen($username) < 3) {
        $errors[] = 'Username must be at least 3 characters.';
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Please enter a valid email address.';
    }
    if (strlen($password) < 8) {
        $errors[] = 'Password must be at least 8 characters.';
    }
    if ($password !== $password_confirm) {
        $errors[] = 'Passwords do not match.';
    }
    if (empty($gateway_name)) {
        $errors[] = 'Gateway name is required.';
    }
    if (!in_array($timezone, $timezones)) {
        $errors[] = 'Invalid timezone selected.';
    }

    if (empty($errors)) {
        @mkdir(__DIR__ . '/config', 0755, true);
        @mkdir(__DIR__ . '/config/logs', 0755, true);

        $now = date('Y-m-d H:i:s');

        $adminData = [
            'username' => $username,
            'password' => password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]),
            'email' => $email,
            'created_at' => $now,
            'last_login' => null
        ];
        file_put_contents(__DIR__ . '/config/admin.json', json_encode($adminData, JSON_PRETTY_PRINT));

        $settingsData = [
            'gateway_name' => $gateway_name,
            'timezone' => $timezone,
            'sms_rate_limit' => 100,
            'enable_api' => true,
            'version' => '1.0.0',
            'installed_at' => $now
        ];
        file_put_contents(__DIR__ . '/config/settings.json', json_encode($settingsData, JSON_PRETTY_PRINT));

        $databaseData = [
            'api_keys' => [],
            'scheduled_sms' => []
        ];
        file_put_contents(__DIR__ . '/config/database.json', json_encode($databaseData, JSON_PRETTY_PRINT));

        $activityData = [
            'logs' => []
        ];
        file_put_contents(__DIR__ . '/config/activity.json', json_encode($activityData, JSON_PRETTY_PRINT));

        $deviceTokensData = [
            'devices' => []
        ];
        file_put_contents(__DIR__ . '/config/device_tokens.json', json_encode($deviceTokensData, JSON_PRETTY_PRINT));

        $smsQueueData = [
            'queue' => []
        ];
        file_put_contents(__DIR__ . '/config/sms_queue.json', json_encode($smsQueueData, JSON_PRETTY_PRINT));

        $htaccessContent = "Order deny,allow\nDeny from all\n";
        file_put_contents(__DIR__ . '/config/.htaccess', $htaccessContent);

        $activityData = json_decode(file_get_contents(__DIR__ . '/config/activity.json'), true);
        $activityData['logs'][] = [
            'type' => 'system',
            'detail' => 'SmsKit gateway installed successfully',
            'timestamp' => $now
        ];
        file_put_contents(__DIR__ . '/config/activity.json', json_encode($activityData, JSON_PRETTY_PRINT));

        while (ob_get_level()) {
            ob_end_clean();
        }

        header('Location: dashboard/');
        exit;
    } else {
        $error = implode('<br>', $errors);
    }
}

$checks = [
    'PHP 7.4+' => [
        'pass' => PHP_VERSION_ID >= 70400,
        'value' => PHP_VERSION
    ],
    'cURL Extension' => [
        'pass' => extension_loaded('curl'),
        'value' => extension_loaded('curl') ? 'Installed' : 'Missing'
    ],
    'JSON Extension' => [
        'pass' => extension_loaded('json'),
        'value' => extension_loaded('json') ? 'Installed' : 'Missing'
    ],
    'OpenSSL Extension' => [
        'pass' => extension_loaded('openssl'),
        'value' => extension_loaded('openssl') ? 'Installed' : 'Missing'
    ],
    'Config Writable' => [
        'pass' => is_writable(__DIR__) || @mkdir(__DIR__ . '/config_test', 0755, true),
        'value' => (is_writable(__DIR__) || @mkdir(__DIR__ . '/config_test', 0755, true)) ? 'Yes' : 'No'
    ],
    'HTTPS' => [
        'pass' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https'),
        'value' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') ? 'Enabled' : 'Not detected'
    ]
];

@rmdir(__DIR__ . '/config_test');

$allPass = true;
foreach ($checks as $check) {
    if (!$check['pass']) {
        $allPass = false;
        break;
    }
}

if (isset($_GET['ajax_check'])) {
    header('Content-Type: application/json');
    echo json_encode(['all_pass' => $allPass, 'checks' => $checks]);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SMSKIT SMS Gateway - Setup</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&family=Fira+Code:wght@300;400;500&display=swap" rel="stylesheet">
    <style>
        :root {
            --white: #FFFFFF;
            --bg: #F7F7F5;
            --bg-2: #F0F0ED;
            --bg-3: #E8E8E4;
            --ink: #0C0C0A;
            --ink-2: #4A4A46;
            --ink-3: #9A9A94;
            --ink-4: #C8C8C2;
            --border: #E4E4DF;
            --border-2: #D0D0CA;
            --blue: #0057FF;
            --blue-bg: #EEF3FF;
            --blue-mid: rgba(0,87,255,0.08);
            --green: #00875A;
            --green-bg: #E6F5EF;
            --red: #D92D20;
            --red-bg: #FEF3F2;
            --amber: #B54708;
            --amber-bg: #FFFAEB;
            --f-ui: 'Plus Jakarta Sans', sans-serif;
            --f-code: 'Fira Code', monospace;
            --ease: cubic-bezier(0.16,1,0.3,1);
            --sh-sm: 0 1px 4px rgba(12,12,10,0.07), 0 1px 2px rgba(12,12,10,0.04);
            --sh-md: 0 4px 16px rgba(12,12,10,0.09), 0 2px 6px rgba(12,12,10,0.05);
            --sh-lg: 0 16px 48px rgba(12,12,10,0.11), 0 4px 12px rgba(12,12,10,0.06);
            --r: 12px;
        }

        *,*::before,*::after { box-sizing: border-box; margin: 0; padding: 0; }
        html { height: 100%; }

        body {
            min-height: 100%;
            background: var(--bg);
            color: var(--ink);
            font-family: var(--f-ui);
            font-size: 14px;
            -webkit-font-smoothing: antialiased;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 1.25rem;
        }

        body::before {
            content: '';
            position: fixed;
            inset: 0;
            background-image: radial-gradient(circle, var(--ink-4) 1px, transparent 1px);
            background-size: 28px 28px;
            opacity: 0.22;
            pointer-events: none;
            z-index: 0;
        }

        .card {
            position: relative;
            z-index: 1;
            background: var(--white);
            border: 1px solid var(--border);
            border-radius: 18px;
            box-shadow: var(--sh-lg);
            width: 100%;
            max-width: 520px;
            overflow: hidden;
            animation: card-in 0.55s var(--ease) both;
        }

        @keyframes card-in {
            from { opacity: 0; transform: translateY(18px) scale(0.98); }
            to { opacity: 1; transform: translateY(0) scale(1); }
        }

        .card-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 1rem 1.25rem;
            border-bottom: 1px solid var(--border);
            background: var(--white);
            gap: 0.75rem;
        }

        .logo {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .logo-icon {
            width: 28px;
            height: 28px;
            background: var(--ink);
            border-radius: 7px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        .logo-mark {
            font-size: 13px;
            font-weight: 700;
            letter-spacing: -0.03em;
            color: var(--ink);
            line-height: 1;
        }

        .logo-mark span { color: var(--blue); }

        .logo-badge {
            font-family: var(--f-code);
            font-size: 9px;
            font-weight: 500;
            padding: 2px 6px;
            border-radius: 5px;
            background: var(--blue-bg);
            color: var(--blue);
            letter-spacing: 0.04em;
            text-transform: uppercase;
        }

        .header-right {
            display: flex;
            align-items: center;
            gap: 0.625rem;
        }

        .docs-btn {
            font-family: var(--f-code);
            font-size: 10px;
            font-weight: 500;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            padding: 0.3rem 0.75rem;
            border-radius: 6px;
            border: 1px solid var(--border);
            background: transparent;
            color: var(--ink-2);
            cursor: pointer;
            transition: all 0.18s;
        }

        .docs-btn:hover {
            background: var(--bg);
            border-color: var(--border-2);
            color: var(--ink);
        }

        .docs-btn.active {
            background: var(--ink);
            border-color: var(--ink);
            color: white;
        }

        .status-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: var(--ink-4);
            flex-shrink: 0;
            transition: background 0.3s, box-shadow 0.3s;
        }

        .status-dot.success {
            background: var(--green);
            box-shadow: 0 0 0 3px rgba(0,135,90,0.15);
            animation: pulse-dot 2s ease-in-out infinite;
        }

        .status-dot.error {
            background: var(--red);
            box-shadow: 0 0 0 3px rgba(217,45,32,0.15);
        }

        @keyframes pulse-dot {
            0%,100% { box-shadow: 0 0 0 3px rgba(0,135,90,0.15); }
            50% { box-shadow: 0 0 0 5px rgba(0,135,90,0.08); }
        }

        .card-body {
            padding: 1.25rem;
        }

        .alert {
            display: flex;
            align-items: center;
            gap: 0.625rem;
            padding: 0.75rem 0.875rem;
            border-radius: 10px;
            border: 1px solid transparent;
            font-family: var(--f-code);
            font-size: 11px;
            font-weight: 500;
            letter-spacing: 0.04em;
            margin-bottom: 1.125rem;
            animation: alert-in 0.35s var(--ease) both;
        }

        .alert-dot {
            width: 6px;
            height: 6px;
            border-radius: 50%;
            flex-shrink: 0;
        }

        .alert.success {
            background: var(--green-bg);
            border-color: rgba(0,135,90,0.2);
            color: var(--green);
        }

        .alert.success .alert-dot { background: var(--green); }

        .alert.error {
            background: var(--red-bg);
            border-color: rgba(217,45,32,0.2);
            color: var(--red);
        }

        .alert.error .alert-dot { background: var(--red); }

        @keyframes alert-in {
            from { opacity: 0; transform: translateY(-6px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .form-grid {
            display: flex;
            flex-direction: column;
            gap: 0.875rem;
        }

        .row-2 {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 0.875rem;
        }

        .field {
            display: flex;
            flex-direction: column;
            gap: 0.35rem;
        }

        .field-label {
            font-family: var(--f-code);
            font-size: 9.5px;
            font-weight: 500;
            letter-spacing: 0.1em;
            text-transform: uppercase;
            color: var(--ink-3);
            display: flex;
            align-items: center;
            gap: 0.3rem;
        }

        .field-label .req { color: var(--blue); font-size: 10px; line-height: 1; }

        .field-wrap {
            position: relative;
            display: flex;
            align-items: center;
        }

        .field-icon {
            position: absolute;
            left: 0.75rem;
            color: var(--ink-4);
            pointer-events: none;
            flex-shrink: 0;
            display: flex;
            align-items: center;
        }

        input, textarea, select {
            width: 100%;
            padding: 0.625rem 0.875rem;
            background: var(--bg);
            border: 1.5px solid var(--border);
            border-radius: var(--r);
            color: var(--ink);
            font-family: var(--f-code);
            font-size: 12px;
            outline: none;
            transition: border-color 0.18s, background 0.18s, box-shadow 0.18s;
            -webkit-appearance: none;
        }

        input.has-icon { padding-left: 2.25rem; }

        input::placeholder, textarea::placeholder, select::placeholder { color: var(--ink-4); font-size: 11.5px; }

        input:focus, textarea:focus, select:focus {
            border-color: var(--blue);
            background: var(--white);
            box-shadow: 0 0 0 3px var(--blue-mid);
        }

        input:valid:not(:placeholder-shown):not(:focus) {
            border-color: var(--border-2);
            background: var(--white);
        }

        textarea {
            resize: vertical;
            min-height: 110px;
            line-height: 1.6;
            font-size: 12px;
        }

        select {
            cursor: pointer;
            appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%239A9A94' stroke-width='2'%3E%3Cpolyline points='6 9 12 15 18 9'%3E%3C/polyline%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 14px center;
            padding-right: 40px;
        }

        .eye-btn {
            position: absolute;
            right: 0.75rem;
            background: none;
            border: none;
            cursor: pointer;
            color: var(--ink-4);
            padding: 0.25rem;
            display: flex;
            align-items: center;
            transition: color 0.15s;
        }

        .eye-btn:hover { color: var(--ink-2); }

        .toggle-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0.75rem 0.875rem;
            background: var(--bg);
            border: 1.5px solid var(--border);
            border-radius: var(--r);
            cursor: pointer;
            transition: border-color 0.18s, background 0.18s;
            user-select: none;
            -webkit-tap-highlight-color: transparent;
        }

        .toggle-row:hover {
            border-color: var(--border-2);
            background: var(--bg-2);
        }

        .toggle-row:has(input:checked) {
            border-color: rgba(0,87,255,0.3);
            background: var(--blue-bg);
        }

        .toggle-info {
            display: flex;
            flex-direction: column;
            gap: 2px;
        }

        .toggle-title {
            font-size: 12px;
            font-weight: 600;
            color: var(--ink);
        }

        .toggle-sub {
            font-family: var(--f-code);
            font-size: 10px;
            color: var(--ink-3);
        }

        .toggle-switch {
            position: relative;
            width: 36px;
            height: 20px;
            background: var(--bg-3);
            border-radius: 20px;
            border: 1.5px solid var(--border-2);
            transition: background 0.25s, border-color 0.25s;
            flex-shrink: 0;
        }

        .toggle-switch::after {
            content: '';
            position: absolute;
            top: 2px;
            left: 2px;
            width: 12px;
            height: 12px;
            background: var(--ink-4);
            border-radius: 50%;
            transition: transform 0.25s var(--ease), background 0.25s;
        }

        input[type="checkbox"] { display: none; }

        input[type="checkbox"]:checked ~ .toggle-switch,
        .toggle-row:has(input:checked) .toggle-switch {
            background: var(--blue);
            border-color: var(--blue);
        }

        input[type="checkbox"]:checked ~ .toggle-switch::after,
        .toggle-row:has(input:checked) .toggle-switch::after {
            transform: translateX(16px);
            background: white;
        }

        .submit-btn {
            width: 100%;
            padding: 0.8rem 1rem;
            background: var(--ink);
            color: white;
            border: none;
            border-radius: var(--r);
            font-family: var(--f-ui);
            font-size: 13px;
            font-weight: 700;
            letter-spacing: 0.02em;
            cursor: pointer;
            transition: background 0.18s, transform 0.15s, box-shadow 0.18s;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            margin-top: 0.25rem;
            -webkit-tap-highlight-color: transparent;
        }

        .submit-btn:hover {
            background: #222220;
            box-shadow: 0 4px 14px rgba(12,12,10,0.18);
            transform: translateY(-1px);
        }

        .submit-btn:active {
            transform: translateY(0);
            box-shadow: none;
        }

        .submit-btn:disabled {
            opacity: 0.4;
            cursor: not-allowed;
            pointer-events: none;
        }

        .card-footer {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0.75rem 1.25rem;
            border-top: 1px solid var(--border);
            background: var(--bg);
            gap: 0.5rem;
            flex-wrap: wrap;
        }

        .meta-item {
            display: flex;
            align-items: center;
            gap: 0.3rem;
            font-family: var(--f-code);
            font-size: 9.5px;
            letter-spacing: 0.06em;
            text-transform: uppercase;
            color: var(--ink-4);
        }

        .meta-val {
            color: var(--ink-2);
            font-weight: 500;
        }

        .remain-badge {
            display: flex;
            align-items: center;
            gap: 0.3rem;
            font-family: var(--f-code);
            font-size: 9.5px;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            padding: 2px 8px;
            border-radius: 20px;
            background: var(--green-bg);
            color: var(--green);
            border: 1px solid rgba(0,135,90,0.15);
        }

        .remain-dot {
            width: 5px;
            height: 5px;
            border-radius: 50%;
            background: var(--green);
        }

        .docs-body {
            display: flex;
            flex-direction: column;
            gap: 0;
        }

        .doc-section {
            padding: 1rem 0;
            border-bottom: 1px solid var(--border);
        }

        .doc-section:first-child { padding-top: 0; }
        .doc-section:last-child { border-bottom: none; padding-bottom: 0; }

        .doc-head {
            display: flex;
            align-items: center;
            gap: 0.625rem;
            margin-bottom: 0.625rem;
        }

        .doc-num {
            font-family: var(--f-code);
            font-size: 9px;
            font-weight: 500;
            padding: 2px 6px;
            border-radius: 5px;
            background: var(--bg-2);
            border: 1px solid var(--border);
            color: var(--ink-3);
            letter-spacing: 0.08em;
            flex-shrink: 0;
        }

        .doc-title {
            font-size: 12px;
            font-weight: 700;
            letter-spacing: -0.01em;
            color: var(--ink);
        }

        .doc-text {
            font-size: 12px;
            color: var(--ink-2);
            line-height: 1.65;
            margin-bottom: 0.5rem;
        }

        .doc-text:last-child { margin-bottom: 0; }

        .doc-list {
            display: flex;
            flex-direction: column;
            gap: 0.375rem;
            margin-top: 0.375rem;
        }

        .doc-list-item {
            display: flex;
            gap: 0.5rem;
            font-size: 12px;
            color: var(--ink-2);
            line-height: 1.6;
        }

        .doc-list-item::before {
            content: '\203A';
            color: var(--blue);
            font-weight: 700;
            flex-shrink: 0;
            margin-top: 1px;
        }

        .doc-chip {
            display: inline;
            font-family: var(--f-code);
            font-size: 10px;
            padding: 1px 5px;
            border-radius: 4px;
            background: var(--bg-2);
            border: 1px solid var(--border);
            color: var(--ink-2);
        }

        .doc-highlight { color: var(--ink); font-weight: 600; }

        .doc-warning {
            display: flex;
            gap: 0.5rem;
            padding: 0.625rem 0.75rem;
            background: var(--red-bg);
            border: 1px solid rgba(217,45,32,0.18);
            border-radius: 8px;
            font-size: 11.5px;
            color: var(--red);
            line-height: 1.55;
            margin-top: 0.375rem;
        }

        .back-btn {
            width: 100%;
            padding: 0.7rem 1rem;
            background: transparent;
            color: var(--ink-2);
            border: 1.5px solid var(--border);
            border-radius: var(--r);
            font-family: var(--f-ui);
            font-size: 12px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.18s;
            margin-top: 1rem;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.4rem;
            -webkit-tap-highlight-color: transparent;
        }

        .back-btn:hover {
            background: var(--bg);
            border-color: var(--border-2);
            color: var(--ink);
        }

        .view {
            animation: view-in 0.3s var(--ease) both;
        }

        .hidden { display: none !important; }

        @keyframes view-in {
            from { opacity: 0; transform: translateY(8px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .step-indicator {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            margin-bottom: 1rem;
        }

        .step-dot {
            width: 10px;
            height: 10px;
            border-radius: 50%;
            background: var(--border-2);
            transition: all 0.25s;
        }

        .step-dot.active { background: var(--blue); box-shadow: 0 0 0 3px var(--blue-mid); }
        .step-dot.done { background: var(--green); }

        .step-line {
            width: 32px;
            height: 2px;
            background: var(--border);
            border-radius: 1px;
            transition: background 0.25s;
        }

        .step-line.done { background: var(--green); }

        .check-list {
            display: flex;
            flex-direction: column;
            gap: 0.625rem;
            margin-bottom: 1.25rem;
        }

        .check-item {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.625rem 0.875rem;
            background: var(--bg);
            border: 1.5px solid var(--border);
            border-radius: 10px;
            font-size: 12px;
            transition: border-color 0.18s;
        }

        .check-item:hover { border-color: var(--border-2); }

        .check-icon {
            width: 22px;
            height: 22px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 11px;
            flex-shrink: 0;
            font-weight: 700;
        }

        .check-icon.pass {
            background: var(--green-bg);
            color: var(--green);
        }

        .check-icon.fail {
            background: var(--red-bg);
            color: var(--red);
        }

        .check-label {
            flex: 1;
            font-weight: 500;
            color: var(--ink);
        }

        .check-value {
            font-family: var(--f-code);
            font-size: 10px;
            color: var(--ink-3);
        }

        .section-title {
            font-family: var(--f-code);
            font-size: 9.5px;
            font-weight: 500;
            letter-spacing: 0.1em;
            text-transform: uppercase;
            color: var(--blue);
            margin-bottom: 0.75rem;
            margin-top: 0.5rem;
            padding-bottom: 0.5rem;
            border-bottom: 1px solid var(--border);
        }

        .section-title:first-child { margin-top: 0; }

        .strength-meter {
            display: flex;
            gap: 5px;
            margin-top: 8px;
        }

        .strength-bar {
            flex: 1;
            height: 3px;
            background: var(--border);
            border-radius: 2px;
            transition: all 0.3s ease;
        }

        .strength-bar.weak { background: var(--red); }
        .strength-bar.fair { background: var(--amber); }
        .strength-bar.good { background: #5B9BD5; }
        .strength-bar.strong { background: var(--green); }

        .strength-label {
            font-family: var(--f-code);
            font-size: 9px;
            color: var(--ink-3);
            margin-top: 4px;
            font-weight: 500;
        }

        ::-webkit-scrollbar { width: 3px; }
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb { background: var(--ink-4); border-radius: 2px; }

        @media (max-width: 520px) {
            body { padding: 0; align-items: flex-start; }
            .card {
                max-width: 100%;
                border-radius: 0;
                border-left: none;
                border-right: none;
                border-top: none;
                box-shadow: none;
                min-height: 100svh;
                display: flex;
                flex-direction: column;
                animation: none;
            }
            .card-body { flex: 1; }
            .row-2 { grid-template-columns: 1fr; }
            .card-footer { gap: 0.375rem; }
            .meta-item:nth-child(2) { display: none; }
        }

        @media (max-width: 360px) {
            .card-header { padding: 0.875rem 1rem; }
            .card-body { padding: 1rem; }
            .card-footer { padding: 0.625rem 1rem; }
            .logo-badge { display: none; }
        }

        @media (hover: none) {
            .submit-btn:hover { transform: none; box-shadow: none; }
            .docs-btn:hover { background: transparent; }
        }
    </style>
</head>
<body>
<div class="card">
    <div class="card-header">
        <div class="logo">
            <div class="logo-icon">
                <svg width="13" height="13" viewBox="0 0 13 13" fill="none">
                    <path d="M2 6.5H11M6.5 2L11 6.5L6.5 11" stroke="white" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
            </div>
            <span class="logo-mark">SMSKIT<span>.</span></span>
            <span class="logo-badge">SMS</span>
        </div>
        <div class="header-right">
            <button class="docs-btn" id="docsToggleBtn" onclick="toggleDocs()">Info</button>
            <div class="status-dot <?php echo $allPass ? 'success' : 'error'; ?>" title="<?php echo $allPass ? 'All checks passed' : 'Issues detected'; ?>"></div>
        </div>
    </div>

    <div id="mainView" class="card-body view">
        <?php if ($step === 1): ?>
        <div class="step-indicator">
            <div class="step-dot active"></div>
            <div class="step-line"></div>
            <div class="step-dot"></div>
        </div>

        <form method="post" id="step1Form">
            <input type="hidden" name="step" value="2">
            <div class="form-grid">
                <div class="field">
                    <label class="field-label">
                        <svg width="10" height="10" viewBox="0 0 10 10" fill="none"><rect x="1" y="1.5" width="8" height="7" rx="1" stroke="currentColor" stroke-width="1.15"/><path d="M3 4H7M3 6H5.5" stroke="currentColor" stroke-width="1.15" stroke-linecap="round"/></svg>
                        Server Requirements
                    </label>
                    <div class="check-list">
                        <?php foreach ($checks as $label => $check): ?>
                        <div class="check-item">
                            <div class="check-icon <?php echo $check['pass'] ? 'pass' : 'fail'; ?>">
                                <?php echo $check['pass'] ? '✓' : '✗'; ?>
                            </div>
                            <div class="check-label"><?php echo htmlspecialchars($label); ?></div>
                            <div class="check-value"><?php echo htmlspecialchars($check['value']); ?></div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <button type="submit" class="submit-btn" id="continueBtn" <?php echo $allPass ? '' : 'disabled'; ?>>
                    <svg width="14" height="14" viewBox="0 0 14 14" fill="none">
                        <path d="M1 7H13M8 2L13 7L8 12" stroke="white" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                    Continue to Configuration
                </button>

                <?php if (!$allPass): ?>
                <button type="button" class="back-btn" onclick="location.reload();">
                    <svg width="12" height="12" viewBox="0 0 12 12" fill="none">
                        <path d="M3 1.5L3 3.5M3 3.5C4.5 2 7 1.5 9 3M3 3.5L5.5 6M9 9C7.5 10.5 5 11 3 9.5" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                    Recheck Server
                </button>
                <?php endif; ?>
            </div>
        </form>

        <?php elseif ($step === 2): ?>
        <div class="step-indicator">
            <div class="step-dot done"></div>
            <div class="step-line done"></div>
            <div class="step-dot active"></div>
        </div>

        <?php if ($error): ?>
        <div class="alert error">
            <div class="alert-dot"></div>
            <?php echo $error; ?>
        </div>
        <?php endif; ?>

        <form method="post" id="configForm" autocomplete="off">
            <input type="hidden" name="step" value="2">
            <div class="form-grid">
                <div class="section-title">Admin Account</div>

                <div class="field">
                    <label class="field-label" for="f_username">
                        <svg width="10" height="10" viewBox="0 0 10 10" fill="none"><circle cx="5" cy="3.5" rx="2" stroke="currentColor" stroke-width="1.15"/><path d="M1.5 8.5C1.5 7 3.1 6 5 6C6.9 6 8.5 7 8.5 8.5" stroke="currentColor" stroke-width="1.15" stroke-linecap="round"/></svg>
                        Username <span class="req">*</span>
                    </label>
                    <input type="text" id="f_username" name="username" placeholder="admin" required minlength="3" value="<?php echo isset($_POST['username']) ? htmlspecialchars($_POST['username']) : ''; ?>">
                </div>

                <div class="field">
                    <label class="field-label" for="f_email">
                        <svg width="10" height="10" viewBox="0 0 10 10" fill="none"><rect x="1" y="2.5" width="8" height="5.5" rx="1" stroke="currentColor" stroke-width="1.15"/><path d="M1 3.5L5 6L9 3.5" stroke="currentColor" stroke-width="1.15" stroke-linecap="round"/></svg>
                        Email <span class="req">*</span>
                    </label>
                    <input type="email" id="f_email" name="email" placeholder="admin@example.com" required value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>">
                </div>

                <div class="field">
                    <label class="field-label" for="f_password">
                        <svg width="10" height="10" viewBox="0 0 10 10" fill="none"><rect x="3" y="4.5" width="4" height="4" rx="0.75" stroke="currentColor" stroke-width="1.15"/><path d="M3.5 4.5V3.5C3.5 2.4 4 2 5 2C6 2 6.5 2.4 6.5 3.5V4.5" stroke="currentColor" stroke-width="1.15" stroke-linecap="round"/></svg>
                        Password <span class="req">*</span>
                    </label>
                    <div class="field-wrap">
                        <input type="password" id="f_password" name="password" class="has-icon" placeholder="Min. 8 characters" required minlength="8">
                        <button type="button" class="eye-btn" onclick="toggleEye(this,'f_password')" title="Show/hide">
                            <svg width="14" height="14" viewBox="0 0 14 14" fill="none">
                                <path d="M1 7C1 7 3 3.5 7 3.5C11 3.5 13 7 13 7C13 7 11 10.5 7 10.5C3 10.5 1 7 1 7Z" stroke="currentColor" stroke-width="1.25"/>
                                <circle cx="7" cy="7" r="1.75" stroke="currentColor" stroke-width="1.25"/>
                            </svg>
                        </button>
                    </div>
                    <div class="strength-meter">
                        <div class="strength-bar" id="bar1"></div>
                        <div class="strength-bar" id="bar2"></div>
                        <div class="strength-bar" id="bar3"></div>
                        <div class="strength-bar" id="bar4"></div>
                    </div>
                    <div class="strength-label" id="strengthLabel"></div>
                </div>

                <div class="field">
                    <label class="field-label" for="f_password_confirm">
                        <svg width="10" height="10" viewBox="0 0 10 10" fill="none"><rect x="3" y="4.5" width="4" height="4" rx="0.75" stroke="currentColor" stroke-width="1.15"/><path d="M3.5 4.5V3.5C3.5 2.4 4 2 5 2C6 2 6.5 2.4 6.5 3.5V4.5" stroke="currentColor" stroke-width="1.15" stroke-linecap="round"/></svg>
                        Confirm Password <span class="req">*</span>
                    </label>
                    <div class="field-wrap">
                        <input type="password" id="f_password_confirm" name="password_confirm" class="has-icon" placeholder="Repeat password" required minlength="8">
                        <button type="button" class="eye-btn" onclick="toggleEye(this,'f_password_confirm')" title="Show/hide">
                            <svg width="14" height="14" viewBox="0 0 14 14" fill="none">
                                <path d="M1 7C1 7 3 3.5 7 3.5C11 3.5 13 7 13 7C13 7 11 10.5 7 10.5C3 10.5 1 7 1 7Z" stroke="currentColor" stroke-width="1.25"/>
                                <circle cx="7" cy="7" r="1.75" stroke="currentColor" stroke-width="1.25"/>
                            </svg>
                        </button>
                    </div>
                </div>

                <div class="section-title">Gateway Settings</div>

                <div class="field">
                    <label class="field-label" for="f_gateway_name">
                        <svg width="10" height="10" viewBox="0 0 10 10" fill="none"><rect x="1" y="1.5" width="8" height="7" rx="1" stroke="currentColor" stroke-width="1.15"/><path d="M3 4H7M3 6H5.5" stroke="currentColor" stroke-width="1.15" stroke-linecap="round"/></svg>
                        Gateway Name <span class="req">*</span>
                    </label>
                    <input type="text" id="f_gateway_name" name="gateway_name" placeholder="My SMS Gateway" required value="<?php echo isset($_POST['gateway_name']) ? htmlspecialchars($_POST['gateway_name']) : ''; ?>">
                </div>

                <div class="field">
                    <label class="field-label" for="f_timezone">
                        <svg width="10" height="10" viewBox="0 0 10 10" fill="none"><circle cx="5" cy="5" r="3.5" stroke="currentColor" stroke-width="1.15"/><path d="M5 3V5L6.5 6.5" stroke="currentColor" stroke-width="1.15" stroke-linecap="round"/></svg>
                        Timezone
                    </label>
                    <select id="f_timezone" name="timezone">
                        <option value="">-- Select Timezone --</option>
                        <?php foreach ($timezones as $tz): ?>
                        <option value="<?php echo htmlspecialchars($tz); ?>" <?php echo (isset($_POST['timezone']) && $_POST['timezone'] === $tz) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($tz); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <button type="submit" class="submit-btn">
                    <svg width="14" height="14" viewBox="0 0 14 14" fill="none">
                        <path d="M1 7H13M8 2L13 7L8 12" stroke="white" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                    Complete Setup
                </button>
            </div>
        </form>
        <?php endif; ?>
    </div>

    <div id="docsView" class="card-body view hidden">
        <div class="docs-body">
            <div class="doc-section">
                <div class="doc-head">
                    <span class="doc-num">01</span>
                    <span class="doc-title">Server Requirements</span>
                </div>
                <p class="doc-text">Your server must meet all <span class="doc-highlight">minimum requirements</span> before installation can proceed.</p>
                <div class="doc-list">
                    <div class="doc-list-item">
                        <span><span class="doc-highlight">PHP 7.4+ —</span> Required for modern language features and security patches.</span>
                    </div>
                    <div class="doc-list-item">
                        <span><span class="doc-highlight">Extensions —</span> cURL, JSON, and OpenSSL must be enabled for API communication.</span>
                    </div>
                    <div class="doc-list-item">
                        <span><span class="doc-highlight">Writable Config —</span> The <span class="doc-chip">/config</span> directory must be writable to store settings.</span>
                    </div>
                </div>
            </div>

            <div class="doc-section">
                <div class="doc-head">
                    <span class="doc-num">02</span>
                    <span class="doc-title">Admin Account</span>
                </div>
                <p class="doc-text">Create your <span class="doc-highlight">administrator account</span> with a strong password. This account has full access to the gateway dashboard.</p>
                <p class="doc-text">Passwords are hashed using <span class="doc-chip">bcrypt</span> with a cost factor of 12 for maximum security.</p>
            </div>

            <div class="doc-section">
                <div class="doc-head">
                    <span class="doc-num">03</span>
                    <span class="doc-title">Gateway Configuration</span>
                </div>
                <p class="doc-text">Set your <span class="doc-highlight">gateway name</span> and <span class="doc-highlight">timezone</span>. These settings control how your SMS gateway identifies itself and processes scheduled messages.</p>
                <div class="doc-warning">
                    <svg width="14" height="14" viewBox="0 0 14 14" fill="none" style="flex-shrink:0;margin-top:1px;">
                        <path d="M7 1L13 12H1L7 1Z" stroke="currentColor" stroke-width="1.25" stroke-linejoin="round"/>
                        <path d="M7 5V8" stroke="currentColor" stroke-width="1.25" stroke-linecap="round"/>
                        <circle cx="7" cy="10" r="0.5" fill="currentColor"/>
                    </svg>
                    Keep your admin credentials safe. You'll be redirected to the dashboard after setup.
                </div>
            </div>
        </div>
        <button class="back-btn" onclick="toggleDocs()">
            <svg width="12" height="12" viewBox="0 0 12 12" fill="none">
                <path d="M7 2L2 6L7 10M2 6H10" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
            Return to Setup
        </button>
    </div>

    <div class="card-footer" id="metaFooter">
        <div class="remain-badge">
            <div class="remain-dot"></div>
            Step <?php echo $step; ?>/2
        </div>
        <div class="meta-item">
            PHP <span class="meta-val"><?php echo PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION . '.' . PHP_RELEASE_VERSION; ?></span>
        </div>
        <div class="meta-item">
            Ver <span class="meta-val">1.0.0</span>
        </div>
    </div>
</div>

<script>
(function() {
    var mainView = document.getElementById('mainView');
    var docsView = document.getElementById('docsView');
    var docsBtn = document.getElementById('docsToggleBtn');
    var docsOpen = false;

    window.toggleDocs = function() {
        docsOpen = !docsOpen;
        if (docsOpen) {
            mainView.classList.add('hidden');
            docsView.classList.remove('hidden');
            docsBtn.textContent = 'Close';
            docsBtn.classList.add('active');
        } else {
            docsView.classList.add('hidden');
            mainView.classList.remove('hidden');
            docsBtn.textContent = 'Info';
            docsBtn.classList.remove('active');
        }
    };

    window.toggleEye = function(btn, fieldId) {
        var inp = document.getElementById(fieldId);
        var isText = inp.type === 'text';
        inp.type = isText ? 'password' : 'text';
        btn.style.color = isText ? 'var(--ink-4)' : 'var(--blue)';
    };

    var pwdInput = document.getElementById('f_password');
    if (pwdInput) {
        pwdInput.addEventListener('input', function() {
            var val = this.value;
            var score = 0;
            if (val.length >= 8) score++;
            if (val.length >= 12) score++;
            if (/[A-Z]/.test(val)) score++;
            if (/[0-9]/.test(val)) score++;
            if (/[^A-Za-z0-9]/.test(val)) score++;

            var bars = [
                document.getElementById('bar1'),
                document.getElementById('bar2'),
                document.getElementById('bar3'),
                document.getElementById('bar4')
            ];
            var label = document.getElementById('strengthLabel');

            bars.forEach(function(bar) {
                bar.className = 'strength-bar';
            });

            var filled = 0;
            var labelText = '';
            var labelColor = '';

            if (val.length === 0) {
                filled = 0;
                labelText = '';
            } else if (score < 2) {
                filled = 1;
                labelText = 'Weak';
                labelColor = 'var(--red)';
            } else if (score < 3) {
                filled = 2;
                labelText = 'Fair';
                labelColor = 'var(--amber)';
            } else if (score < 4) {
                filled = 3;
                labelText = 'Good';
                labelColor = '#5B9BD5';
            } else {
                filled = 4;
                labelText = 'Strong';
                labelColor = 'var(--green)';
            }

            var classes = ['weak', 'fair', 'good', 'strong'];
            for (var i = 0; i < filled; i++) {
                if (bars[i]) bars[i].classList.add(classes[filled - 1]);
            }

            if (label) {
                label.textContent = labelText;
                label.style.color = labelColor || '';
            }
        });
    }

    var configForm = document.getElementById('configForm');
    if (configForm) {
        configForm.addEventListener('submit', function(e) {
            var pwd = configForm.querySelector('[name="password"]').value;
            var pwdConfirm = configForm.querySelector('[name="password_confirm"]').value;
            if (pwd !== pwdConfirm) {
                e.preventDefault();
                alert('Passwords do not match.');
                return false;
            }
        });
    }

    var tzSelect = document.getElementById('f_timezone');
    if (tzSelect && !tzSelect.value) {
        try {
            var detected = Intl.DateTimeFormat().resolvedOptions().timeZone;
            var options = tzSelect.querySelectorAll('option');
            for (var i = 0; i < options.length; i++) {
                if (options[i].value === detected) {
                    options[i].selected = true;
                    break;
                }
            }
        } catch (e) {}
    }
})();
</script>
</body>
</html>