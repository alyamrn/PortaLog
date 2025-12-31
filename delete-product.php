<?php
require_once "db_connect.php";
session_start();

$id = $_GET['id'] ?? '';
$vessel_id = $_SESSION['vessel_id'];

if ($id) {
    $stmt = $pdo->prepare("DELETE FROM rob_products WHERE product_id=? AND vessel_id=?");
    $stmt->execute([$id, $vessel_id]);
}

header("Location: engine-rob.php");
exit;
?>