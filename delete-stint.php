<?php
session_start();
require_once "db_connect.php";

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$vessel_id = $_SESSION['vessel_id'] ?? null;
$stint_id  = $_GET['stint_id'] ?? null; // renamed for pob_stints
$date      = $_GET['date'] ?? date('Y-m-d');

if ($stint_id && $vessel_id) {
    // Ensure the stint belongs to the current vessel before deleting
    $stmt = $pdo->prepare("DELETE FROM pob_stints WHERE stint_id = ? AND vessel_id = ?");
    $stmt->execute([$stint_id, $vessel_id]);

    if ($stmt->rowCount() > 0) {
        $_SESSION['success'] = "POB record deleted successfully.";
    } else {
        $_SESSION['error'] = "Failed to delete record or record not found.";
    }
} else {
    $_SESSION['error'] = "Invalid delete request.";
}

// Redirect back to the report page for the same date
header("Location: pob-report.php?date=" . urlencode($date));
exit;
?>
