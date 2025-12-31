<?php
session_start();
require_once "db_connect.php";

if (!isset($_SESSION['vessel_id'])) {
    die("Unauthorized access");
}

$vessel_id = $_SESSION['vessel_id'];
$category = $_POST['category'] ?? null;
$product_name = trim($_POST['product_name'] ?? '');
$unit = trim($_POST['unit'] ?? '');
$date = date("Y-m-d");

if (!$category || !$product_name || !$unit) {
    die("Missing required fields");
}

// ✅ Check if product already exists
$check = $pdo->prepare("SELECT COUNT(*) FROM rob_products WHERE vessel_id=? AND category=? AND product_name=?");
$check->execute([$vessel_id, $category, $product_name]);
if ($check->fetchColumn() > 0) {
    header("Location: engine-rob.php?error=exists");
    exit;
}

// ✅ Insert into rob_products
$stmt = $pdo->prepare("INSERT INTO rob_products (vessel_id, category, product_name, unit) VALUES (?, ?, ?, ?)");
$stmt->execute([$vessel_id, $category, $product_name, $unit]);

// ✅ Also insert to rob_records for the current date
$stmt2 = $pdo->prepare("INSERT INTO rob_records (vessel_id, log_date, category, product, unit) VALUES (?, ?, ?, ?, ?)");
$stmt2->execute([$vessel_id, $date, $category, $product_name, $unit]);

header("Location: engine-rob.php?saved=new");
exit;
?>