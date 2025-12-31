<?php
session_start();
require_once "db_connect.php";

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$vessel_id = $_SESSION['vessel_id'];
$log_date = $_POST['log_date'] ?? null;
$names = $_POST['generator_name'] ?? [];
$starts = $_POST['start_time'] ?? [];
$ends   = $_POST['end_time'] ?? [];

if (!$vessel_id || !$log_date) {
    die("Invalid request: missing vessel or date");
}

try {
    $pdo->beginTransaction();

    // loop through submitted machines
    for ($i = 0; $i < count($names); $i++) {
        $genName = trim($names[$i]);
        $start   = $starts[$i] ?: null;
        $end     = $ends[$i] ?: null;

        // check if record exists
        $check = $pdo->prepare("SELECT rh_id FROM runninghours WHERE vessel_id=? AND log_date=? AND generator_name=?");
        $check->execute([$vessel_id, $log_date, $genName]);
        $existing = $check->fetchColumn();

        if ($existing) {
            // update
            $stmt = $pdo->prepare("UPDATE runninghours 
                SET start_time=?, end_time=? 
                WHERE rh_id=?");
            $stmt->execute([$start, $end, $existing]);
        } else {
            // insert
            $stmt = $pdo->prepare("INSERT INTO runninghours 
                (vessel_id, log_date, generator_name, start_time, end_time) 
                VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$vessel_id, $log_date, $genName, $start, $end]);
        }
    }

    $pdo->commit();
    $_SESSION['success'] = "Running hours saved successfully.";
    header("Location: engine-report.php?date=" . urlencode($log_date));
    exit;

} catch (Exception $e) {
    $pdo->rollBack();
    die("Error saving data: " . $e->getMessage());
}
