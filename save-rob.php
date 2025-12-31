<?php
session_start();
require_once "db_connect.php";

if (!isset($_SESSION['user_id'], $_SESSION['vessel_id'])) {
    die("Not logged in.");
}

$vessel_id = $_SESSION['vessel_id'];

// Collect POST data
$rob_id = $_POST['rob_id'] ?? null;
$log_date = $_POST['log_date'] ?? date("Y-m-d");
$category = $_POST['category'] ?? "";
$product = $_POST['product'] ?? "";
$unit = $_POST['unit'] ?? "";
$previous_rob = $_POST['previous_rob'] ?? 0;
$loaded_today = $_POST['loaded_today'] ?? 0;
$discharged_today = $_POST['discharged_today'] ?? 0;
$produced_today = $_POST['produced_today'] ?? 0;
$daily_consumption = $_POST['daily_consumption'] ?? 0;
$adjustment = $_POST['adjustment'] ?? 0;
$current_rob = $_POST['current_rob'] ?? 0;
$max_capacity = $_POST['max_capacity'] ?? 0;
$remarks = $_POST['remarks'] ?? "";

// If rob_id exists → UPDATE, otherwise INSERT
if (!empty($rob_id)) {
    $stmt = $pdo->prepare("UPDATE rob_records 
        SET product=?, unit=?, previous_rob=?, loaded_today=?, discharged_today=?, 
            produced_today=?, daily_consumption=?, adjustment=?, current_rob=?, 
            max_capacity=?, remarks=? 
        WHERE rob_id=? AND vessel_id=? AND log_date=?");
    $stmt->execute([
        $product,
        $unit,
        $previous_rob,
        $loaded_today,
        $discharged_today,
        $produced_today,
        $daily_consumption,
        $adjustment,
        $current_rob,
        $max_capacity,
        $remarks,
        $rob_id,
        $vessel_id,
        $log_date
    ]);
} else {
    $stmt = $pdo->prepare("INSERT INTO rob_records 
        (vessel_id, log_date, category, product, unit, previous_rob, loaded_today, discharged_today, 
         produced_today, daily_consumption, adjustment, current_rob, max_capacity, remarks) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([
        $vessel_id,
        $log_date,
        $category,
        $product,
        $unit,
        $previous_rob,
        $loaded_today,
        $discharged_today,
        $produced_today,
        $daily_consumption,
        $adjustment,
        $current_rob,
        $max_capacity,
        $remarks
    ]);
}

// Redirect back to ROB page
header("Location: engine-rob.php?date=" . urlencode($log_date));
exit;
