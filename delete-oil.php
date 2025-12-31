<?php
session_start();
require_once "db_connect.php";

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$vessel_id = $_SESSION['vessel_id'] ?? null;
$oil_id    = $_GET['id'] ?? null;
$date      = $_GET['date'] ?? date("Y-m-d");

if ($oil_id && $vessel_id) {
    try {
        $stmt = $pdo->prepare("DELETE FROM oilrecordbook WHERE oil_id=? AND vessel_id=?");
        $stmt->execute([$oil_id, $vessel_id]);
    } catch (PDOException $e) {
        die("Delete error: " . $e->getMessage());
    }
}

header("Location: oil-report.php?date=" . urlencode($date) . "&success=deleted");
exit;
?>
