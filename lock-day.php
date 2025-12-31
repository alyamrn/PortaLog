<?php
session_start();
require_once "db_connect.php";

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== "CAPTAIN") {
    header("Location: login.php");
    exit;
}

$vessel_id = $_SESSION['vessel_id'];
$log_date = $_POST['log_date'];
$action = $_POST['action'];

if ($action === "lock") {
    $stmt = $pdo->prepare("
        INSERT INTO dailystatus (vessel_id, log_date, status, locked_by, locked_at)
        VALUES (?, ?, 'LOCKED', ?, NOW())
        ON DUPLICATE KEY UPDATE status='LOCKED', locked_by=?, locked_at=NOW()
    ");
    $stmt->execute([$vessel_id, $log_date, $_SESSION['user_id'], $_SESSION['user_id']]);
} elseif ($action === "unlock") {
    $stmt = $pdo->prepare("
        UPDATE dailystatus 
        SET status='OPEN', locked_by=NULL, locked_at=NULL 
        WHERE vessel_id=? AND log_date=?
    ");
    $stmt->execute([$vessel_id, $log_date]);
}

header("Location: dashboard.php");
exit;
?>