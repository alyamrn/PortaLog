<?php
session_start();
require_once "db_connect.php";

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$vessel_id = $_POST['vessel_id'] ?? ($_SESSION['vessel_id'] ?? null);
$log_date = $_POST['log_date'] ?? date("Y-m-d");

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $vessel_id) {
    // Arrays from the form
    $garb_ids = $_POST['garb_id'] ?? [];
    $latitudes = $_POST['latitude'] ?? [];
    $longitudes = $_POST['longitude'] ?? [];
    $categories = $_POST['category'] ?? [];
    $qtys = $_POST['qty_m3'] ?? [];
    $methods = $_POST['method'] ?? [];
    $ports = $_POST['port'] ?? [];
    $receipts = $_POST['receipt_ref'] ?? [];
    $remarks = $_POST['remarks'] ?? [];

    try {
        foreach ($categories as $i => $category) {
            $garb_id = !empty($garb_ids[$i]) ? $garb_ids[$i] : null;
            $latitude = ($latitudes[$i] !== "") ? $latitudes[$i] : null;
            $longitude = ($longitudes[$i] !== "") ? $longitudes[$i] : null;
            $qty_m3 = ($qtys[$i] !== "") ? $qtys[$i] : 0;
            $method = $methods[$i] ?? null;
            $port = !empty($ports[$i]) ? $ports[$i] : null;
            $receipt = !empty($receipts[$i]) ? $receipts[$i] : null;
            $remark = !empty($remarks[$i]) ? $remarks[$i] : null;

            if ($garb_id) {
                // UPDATE existing row
                $stmt = $pdo->prepare("
                    UPDATE garbagelogs
                    SET latitude=?, longitude=?, category=?, qty_m3=?, method=?, port=?, receipt_ref=?, remarks=?
                    WHERE garb_id=? AND vessel_id=?
                ");
                $stmt->execute([$latitude, $longitude, $category, $qty_m3, $method, $port, $receipt, $remark, $garb_id, $vessel_id]);
            } else {
                // INSERT new row
                $stmt = $pdo->prepare("
                    INSERT INTO garbagelogs
                    (vessel_id, log_date, entry_time, latitude, longitude, category, qty_m3, method, port, receipt_ref, remarks)
                    VALUES (?, ?, NOW(), ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([$vessel_id, $log_date, $latitude, $longitude, $category, $qty_m3, $method, $port, $receipt, $remark]);
            }
        }

        // Redirect back with success message
        header("Location: garbage-report.php?date=" . urlencode($log_date) . "&success=1");
        exit;
    } catch (PDOException $e) {
        die("Database error: " . $e->getMessage());
    }
} else {
    header("Location: garbage-report.php?date=" . urlencode($log_date));
    exit;
}
