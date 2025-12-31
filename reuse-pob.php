<?php
session_start();
require_once "db_connect.php";

if (!isset($_SESSION['user_id'], $_SESSION['vessel_id'])) {
    echo json_encode(["success" => false, "error" => "Not logged in."]);
    exit;
}

$vessel_id = $_SESSION['vessel_id'];
$today = date("Y-m-d");
$yesterday = date("Y-m-d", strtotime("-1 day"));

// check if yesterday has any records
$q = $pdo->prepare("SELECT COUNT(*) FROM pobpersons WHERE vessel_id=? AND log_date=?");
$q->execute([$vessel_id, $yesterday]);
$yesterday_count = $q->fetchColumn();

if ($yesterday_count == 0) {
    echo json_encode(["success" => false, "error" => "No POB data filled yesterday."]);
    exit;
}

// fetch yesterday’s POB
$q2 = $pdo->prepare("SELECT full_name, nationality, dob, category, crew_role, embark_date, disembark_date, remarks
                     FROM pobpersons 
                     WHERE vessel_id=? AND log_date=?");
$q2->execute([$vessel_id, $yesterday]);
$rows = $q2->fetchAll(PDO::FETCH_ASSOC);

if (!$rows) {
    echo json_encode(["success" => false, "error" => "No POB records found for yesterday."]);
    exit;
}

// insert into today (skip duplicates by full_name)
$insert = $pdo->prepare("INSERT INTO pobpersons 
    (vessel_id, log_date, full_name, nationality, dob, category, crew_role, embark_date, disembark_date, remarks)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

foreach ($rows as $row) {
    $check = $pdo->prepare("SELECT COUNT(*) FROM pobpersons 
                            WHERE vessel_id=? AND log_date=? AND full_name=?");
    $check->execute([$vessel_id, $today, $row['full_name']]);
    if ($check->fetchColumn() == 0) {
        $insert->execute([
            $vessel_id,
            $today,
            $row['full_name'],
            $row['nationality'],
            $row['dob'],
            $row['category'],
            $row['crew_role'],
            $row['embark_date'],
            $row['disembark_date'],
            $row['remarks']
        ]);
    }
}

// return today's data
$q3 = $pdo->prepare("SELECT * FROM pobpersons WHERE vessel_id=? AND log_date=? ORDER BY full_name");
$q3->execute([$vessel_id, $today]);
$todayRows = $q3->fetchAll(PDO::FETCH_ASSOC);

echo json_encode(["success" => true, "data" => $todayRows]);
