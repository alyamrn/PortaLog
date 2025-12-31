<?php
session_start();
require_once "db_connect.php";

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$vessel_id = $_SESSION['vessel_id'] ?? null;
$log_date = $_POST['log_date'] ?? date("Y-m-d");

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Loop through all rows
    $oil_ids = $_POST['oil_id'] ?? [];
    $categories = $_POST['category'] ?? [];
    $operations = $_POST['operation'] ?? [];
    $tanks = $_POST['tank'] ?? [];
    $qtys = $_POST['qty_mt'] ?? [];
    $latitudes = $_POST['latitude'] ?? [];
    $longitudes = $_POST['longitude'] ?? [];
    $remarks = $_POST['remarks'] ?? [];
    $times = $_POST['date_time'] ?? []; // if you later add time as input

    for ($i = 0; $i < count($categories); $i++) {
        $oil_id = !empty($oil_ids[$i]) ? $oil_ids[$i] : null;
        $category = $categories[$i];
        $operation = $operations[$i] ?? null;
        $tank = $tanks[$i] ?? null;
        $qty_mt = $qtys[$i] ?? 0;
        $latitude = !empty($latitudes[$i]) ? $latitudes[$i] : null;
        $longitude = !empty($longitudes[$i]) ? $longitudes[$i] : null;
        $remark = $remarks[$i] ?? null;
        $date_time = !empty($times[$i]) ? $times[$i] : date("H:i:s");

        if ($oil_id) {
            // UPDATE existing row
            $stmt = $pdo->prepare("UPDATE oilrecordbook 
                SET log_date=?, date_time=?, category=?, operation=?, tank=?, qty_mt=?, latitude=?, longitude=?, remarks=?
                WHERE oil_id=? AND vessel_id=?");
            $stmt->execute([$log_date, $date_time, $category, $operation, $tank, $qty_mt, $latitude, $longitude, $remark, $oil_id, $vessel_id]);
        } else {
            // INSERT new row
            $stmt = $pdo->prepare("INSERT INTO oilrecordbook
                (vessel_id, log_date, date_time, category, operation, tank, qty_mt, latitude, longitude, remarks)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$vessel_id, $log_date, $date_time, $category, $operation, $tank, $qty_mt, $latitude, $longitude, $remark]);
        }
    }

    header("Location: oil-report.php?date=" . urlencode($log_date) . "&success=1");
    exit;
}
?>