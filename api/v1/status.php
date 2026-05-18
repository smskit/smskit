<?php


require_once __DIR__ . '/_auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendError(405, 'Method not allowed. Use GET.');
}

$messageId = $_GET['id'] ?? '';

if (empty($messageId)) {
    sendError(400, 'id parameter is required');
}

$smsQueue = loadJson('sms_queue.json');
$queue = $smsQueue['queue'] ?? [];

$items = [];
$sentCount = 0;
$failedCount = 0;
$createdAt = null;
$worstStatus = 'queued';

foreach ($queue as $item) {
    if (($item['message_id'] ?? '') === $messageId) {
        $items[] = $item;
        $status = $item['status'] ?? 'queued';
        if ($createdAt === null && isset($item['created_at'])) {
            $createdAt = $item['created_at'];
        }
        if ($status === 'sent') {
            $sentCount++;
        } elseif ($status === 'failed') {
            $failedCount++;
            $worstStatus = 'failed';
        } elseif ($status === 'queued' && $worstStatus !== 'failed') {
            $worstStatus = 'queued';
        }
    }
}

if (empty($items)) {
    sendError(404, 'Message not found');
}


$totalItems = count($items);
if ($sentCount === $totalItems) {
    $overallStatus = 'sent';
} elseif ($failedCount === $totalItems) {
    $overallStatus = 'failed';
} elseif ($sentCount > 0 || $failedCount > 0) {
    $overallStatus = 'partial';
} else {
    $overallStatus = 'queued';
}

echo json_encode([
    'ok' => true,
    'message_id' => $messageId,
    'status' => $overallStatus,
    'total_count' => $totalItems,
    'sent_count' => $sentCount,
    'failed_count' => $failedCount,
    'queued_count' => $totalItems - $sentCount - $failedCount,
    'created_at' => $createdAt,
    'items' => $items
]);
