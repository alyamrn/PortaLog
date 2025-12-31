<?php
session_start();
require_once "db_connect.php";

if (!isset($_SESSION['user_id'], $_SESSION['vessel_id'])) {
    die("Not logged in.");
}

$vessel_id = $_SESSION['vessel_id'];
$rob_id = $_GET['id'] ?? null;

if ($rob_id) {
    // Reset entries, don't delete the product
    $stmt = $pdo->prepare("UPDATE rob_records 
        SET previous_rob=0, loaded_today=0, discharged_today=0, produced_today=0,
            daily_consumption=0, adjustment=0, current_rob=0, max_capacity=0,
            remarks=''
        WHERE rob_id=? AND vessel_id=?");
    $stmt->execute([$rob_id, $vessel_id]);
}

header("Location: engine-rob.php?date=" . urlencode($_GET['date'] ?? date("Y-m-d")));
exit;
