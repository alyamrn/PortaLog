<?php
require_once "db_connect.php";
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$vessel_id = $_SESSION['vessel_id'] ?? null;
if (!$vessel_id) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing vessel']);
    exit;
}

$op = $_POST['op'] ?? null;
$id = isset($_POST['id']) ? (int) $_POST['id'] : 0;

if (!$id || !in_array($op, ['mark_read', 'flag', 'unflag'], true)) {
    http_response_code(422);
    echo json_encode(['error' => 'Invalid parameters']);
    exit;
}

try {
    if ($op === 'mark_read') {
        $sql = "UPDATE reminders SET is_read = 1, read_at = NOW() WHERE reminder_id = ? AND vessel_id = ?";
        $pdo->prepare($sql)->execute([$id, $vessel_id]);
    } elseif ($op === 'flag') {
        $sql = "UPDATE reminders SET is_flagged = 1 WHERE reminder_id = ? AND vessel_id = ?";
        $pdo->prepare($sql)->execute([$id, $vessel_id]);
    } else { // unflag
        $sql = "UPDATE reminders SET is_flagged = 0 WHERE reminder_id = ? AND vessel_id = ?";
        $pdo->prepare($sql)->execute([$id, $vessel_id]);
    }
    echo json_encode(['ok' => true]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Server error', 'detail' => $e->getMessage()]);
}
