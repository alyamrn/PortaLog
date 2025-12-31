<?php
session_start();
require_once "db_connect.php";

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$vessel_id = $_POST['vessel_id'] ?? null;
$machine_name = trim($_POST['machine_name'] ?? '');
$action = $_POST['action'] ?? '';

if (!$vessel_id || !$machine_name) {
    header("Location: engine-report.php?error=missing_data");
    exit;
}

if ($action === 'add') {
    $stmt = $pdo->prepare("INSERT IGNORE INTO vessel_machines (vessel_id, machine_name) VALUES (?, ?)");
    $stmt->execute([$vessel_id, $machine_name]);
    header("Location: engine-report.php?success=machine_added");
    exit;
}

if ($action === 'delete') {
    $stmt = $pdo->prepare("DELETE FROM vessel_machines WHERE vessel_id = ? AND machine_name = ?");
    $stmt->execute([$vessel_id, $machine_name]);
    header("Location: engine-report.php?success=machine_removed");
    exit;
}

header("Location: engine-report.php");
exit;
?>