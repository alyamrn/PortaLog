<?php
require_once "db_connect.php";
header('Content-Type: application/json');

// === FUEL CONSUMPTION TREND ===
$fuelLabels = [];
$fuelValues = [];
$q = $pdo->query("
    SELECT DATE(log_date) AS d, SUM(COALESCE(daily_consumption,0)) AS total
    FROM rob_records
    WHERE category='LIQUID' AND product LIKE '%FUEL%'
    GROUP BY d
    ORDER BY d DESC
    LIMIT 7
");
while ($r = $q->fetch(PDO::FETCH_ASSOC)) {
    $fuelLabels[] = date('M d', strtotime($r['d']));
    $fuelValues[] = round($r['total'], 2);
}
$fuelLabels = array_reverse($fuelLabels);
$fuelValues = array_reverse($fuelValues);

// === CREW CATEGORY DISTRIBUTION ===
$crewLabels = [];
$crewValues = [];
$q = $pdo->query("
    SELECT category, COUNT(*) AS total
    FROM pobpersons
    WHERE log_date = (SELECT MAX(log_date) FROM pobpersons)
    GROUP BY category
");
while ($r = $q->fetch(PDO::FETCH_ASSOC)) {
    $crewLabels[] = $r['category'];
    $crewValues[] = $r['total'];
}

// === REPORT SUBMISSIONS ===
$repLabels = [];
$repValues = [];
$q = $pdo->query("
    SELECT DATE(log_date) AS d, COUNT(*) AS total
    FROM dailystatus
    GROUP BY d
    ORDER BY d DESC
    LIMIT 7
");
while ($r = $q->fetch(PDO::FETCH_ASSOC)) {
    $repLabels[] = date('M d', strtotime($r['d']));
    $repValues[] = $r['total'];
}
$repLabels = array_reverse($repLabels);
$repValues = array_reverse($repValues);

echo json_encode([
    "fuel" => ["labels" => $fuelLabels, "values" => $fuelValues],
    "crew" => ["labels" => $crewLabels, "values" => $crewValues],
    "reports" => ["labels" => $repLabels, "values" => $repValues]
]);
