<?php
$host = "localhost";
$dbname = "vdrs";
$username = "root";   // change if needed
$password = "";       // change if needed

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Connection failed: " . $e->getMessage());
}

function log_action($pdo, $user_id, $vessel_id, $action, $entity, $entity_id = null, $payload = null) {
    $sql = "INSERT INTO auditlog (user_id, vessel_id, log_date, entity, entity_id, action, payload_json)
            VALUES (?, ?, CURDATE(), ?, ?, ?, ?)";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$user_id, $vessel_id, $entity, $entity_id, $action, json_encode($payload)]);
}


?>