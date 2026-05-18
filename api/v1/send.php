<?php

require_once __DIR__ . '/_auth.php';


if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendError(405, 'Method not allowed. Use POST.');
}


$input = json_decode(file_get_contents('php://input'), true);

if ($input === null && json_last_error() !== JSON_ERROR_NONE) {
   
    $input = $_POST;
}

$numbers = $input['numbers'] ?? [];
$message = trim($input['message'] ?? '');

if (empty($numbers) || !is_array($numbers)) {
    sendError(400, 'numbers is required and must be a non-empty array.');
}


foreach ($numbers as $number) {
    $number = trim($number);
    if (empty($number)) {
        sendError(400, 'Empty phone number found in numbers array.');
    }
    if (!str_starts_with($number, '+')) {
        sendError(400, 'Phone number must start with +: ' . $number);
    }
    $digitsOnly = preg_replace('/[^0-9]/', '', $number);
    $digitCount = strlen($digitsOnly);
    if ($digitCount < 7 || $digitCount > 15) {
        sendError(400, 'Phone number must have 7-15 digits after the +: ' . $number);
    }
}

if (empty($message)) {
    sendError(400, 'message is required and cannot be empty.');
}
if (strlen($message) > 1600) {
    sendError(400, 'Message exceeds maximum length of 1600 characters.');
}

$msgId = 'msg_' . uniqid();
$queue = loadJson('sms_queue.json');
if (!isset($queue['queue'])) $queue['queue'] = [];

$sentCount = 0;
foreach ($numbers as $number) {
    $number = trim($number);
    $queue['queue'][] = [
        'id' => $msgId . '_' . mt_rand(1000, 9999),
        'message_id' => $msgId,
        'to' => $number,
        'message' => $message,
        'status' => 'queued',
        'created_at' => date('Y-m-d H:i:s'),
        'sent_at' => null,
        'source' => 'api',
        'api_key_id' => $__keyData['id'] ?? null,
        'error' => null
    ];
    $sentCount++;
}

saveJson('sms_queue.json', $queue);

$numberList = implode(', ', $numbers);
logActivity('api_sms_sent', 'API sent SMS to ' . $sentCount . ' number(s): ' . $numberList);

echo json_encode([
    'ok' => true,
    'message_id' => $msgId,
    'sent_count' => $sentCount,
    'queued' => true
]);
