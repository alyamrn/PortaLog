<?php
session_start();
require_once "db_connect.php";

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$vessel_id = $_SESSION['vessel_id'] ?? null;
$id = $_GET['id'] ?? null;
$date = $_GET['date'] ?? date('Y-m-d');

if ($id && $vessel_id) {
    // Ensure only records from the current vessel can be deleted
    $stmt = $pdo->prepare("DELETE FROM pobpersons WHERE person_id = ? AND vessel_id = ?");
    $stmt->execute([$id, $vessel_id]);

    if ($stmt->rowCount() > 0) {
        $_SESSION['success'] = "POB record deleted successfully.";
    } else {
        $_SESSION['error'] = "Failed to delete record or record not found.";
    }
} else {
    $_SESSION['error'] = "Invalid delete request.";
}

header("Location: pob-report.php?date=" . urlencode($date));
exit;
