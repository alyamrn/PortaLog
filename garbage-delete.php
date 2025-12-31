<?php
session_start();
require_once "db_connect.php";

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$vessel_id = $_SESSION['vessel_id'] ?? null;
$garb_id = $_GET['id'] ?? null;
$date = $_GET['date'] ?? date("Y-m-d");

if ($garb_id && $vessel_id) {
    try {
        $stmt = $pdo->prepare("DELETE FROM garbagelogs WHERE garb_id = ? AND vessel_id = ?");
        $stmt->execute([$garb_id, $vessel_id]);

        // Redirect with success flag
        header("Location: garbage-report.php?date=" . urlencode($date) . "&success=deleted");
        exit;
    } catch (PDOException $e) {
        die("Database error: " . $e->getMessage());
    }
} else {
    header("Location: garbage-report.php?date=" . urlencode($date));
    exit;
}
