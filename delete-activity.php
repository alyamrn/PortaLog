<?php
session_start();
require_once "db_connect.php";

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$vessel_id = $_SESSION['vessel_id'] ?? null;

// ✅ Validate ID
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header("Location: daily-report.php?error=invalid_id");
    exit;
}

$activity_id = (int) $_GET['id'];

// ✅ Check if the activity belongs to this vessel
$stmt = $pdo->prepare("SELECT * FROM activitylogs WHERE activity_id = ? AND vessel_id = ?");
$stmt->execute([$activity_id, $vessel_id]);
$activity = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$activity) {
    header("Location: daily-report.php?error=not_found");
    exit;
}

// ✅ Check if the day is locked
$checkLock = $pdo->prepare("SELECT status FROM dailystatus WHERE vessel_id=? AND log_date=? LIMIT 1");
$checkLock->execute([$vessel_id, $activity['log_date']]);
$status = $checkLock->fetchColumn();

if ($status === "LOCKED") {
    header("Location: daily-report.php?error=locked");
    exit;
}

// ✅ Delete the record
$delete = $pdo->prepare("DELETE FROM activitylogs WHERE activity_id = ? AND vessel_id = ?");
$delete->execute([$activity_id, $vessel_id]);

header("Location: daily-report.php?success=deleted");
exit;
?>