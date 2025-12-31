<?php
require_once "db_connect.php";

if ($_SERVER['REQUEST_METHOD'] !== 'POST' && $_SERVER['REQUEST_METHOD'] !== 'GET') {
    die("Invalid request");
}

// Sanitize and collect input
$vessel_id = $_REQUEST['vessel_id'] ?? '';
$start_date = $_REQUEST['start_date'] ?? '';
$end_date = $_REQUEST['end_date'] ?? '';
$export_type = $_REQUEST['export'] ?? 'excel';

if (empty($vessel_id) || empty($start_date) || empty($end_date)) {
    die("Missing required parameters.");
}

// Get vessel name
$stmt = $pdo->prepare("SELECT vessel_name FROM vessels WHERE vessel_id = ?");
$stmt->execute([$vessel_id]);
$vessel_name = $stmt->fetchColumn() ?: 'Unknown Vessel';

// Fetch vessel report data
$query = $pdo->prepare("
    SELECT 
        d.log_date,
        COALESCE(d.status, '-') AS status,
        COALESCE(SUM(r.current_rob), 0) AS total_rob,
        COALESCE(SUM(r.daily_consumption), 0) AS total_consumption,
        (
            SELECT COUNT(*) 
            FROM pobpersons p 
            WHERE p.vessel_id = d.vessel_id 
              AND p.log_date = d.log_date
        ) AS pob_count
    FROM dailystatus d
    LEFT JOIN rob_records r 
        ON r.vessel_id = d.vessel_id 
       AND r.log_date = d.log_date
    WHERE d.vessel_id = :vessel_id 
      AND d.log_date BETWEEN :start AND :end
    GROUP BY d.log_date, d.status
    ORDER BY d.log_date ASC
");
$query->execute([
    'vessel_id' => $vessel_id,
    'start' => $start_date,
    'end' => $end_date
]);
$data = $query->fetchAll(PDO::FETCH_ASSOC);

// Handle empty result
if (empty($data)) {
    die("No data found for the selected period.");
}

/* =============================
   EXPORT TO PDF
============================= */
if ($export_type === 'pdf') {
    require_once('fpdf186/fpdf.php');

    $pdf = new FPDF('P', 'mm', 'A4');
    $pdf->AddPage();
    $pdf->SetFont('Arial', 'B', 16);

    // Header
    $pdf->Cell(0, 10, "Vessel Report - " . strtoupper($vessel_name), 0, 1, 'C');
    $pdf->SetFont('Arial', '', 12);
    $pdf->Ln(4);
    $pdf->Cell(0, 8, "Period: $start_date to $end_date", 0, 1, 'C');
    $pdf->Ln(5);

    // Table Header
    $pdf->SetFont('Arial', 'B', 11);
    $pdf->SetFillColor(108, 155, 207); // theme blue
    $pdf->SetTextColor(255);
    $pdf->Cell(35, 10, 'Date', 1, 0, 'C', true);
    $pdf->Cell(30, 10, 'Status', 1, 0, 'C', true);
    $pdf->Cell(35, 10, 'Total ROB', 1, 0, 'C', true);
    $pdf->Cell(50, 10, 'Consumption', 1, 0, 'C', true);
    $pdf->Cell(25, 10, 'POB', 1, 1, 'C', true);

    // Table Body
    $pdf->SetFont('Arial', '', 11);
    $pdf->SetTextColor(0);
    foreach ($data as $r) {
        $pdf->Cell(35, 8, $r['log_date'], 1);
        $pdf->Cell(30, 8, strtoupper($r['status']), 1);
        $pdf->Cell(35, 8, number_format($r['total_rob'], 2), 1, 0, 'R');
        $pdf->Cell(50, 8, number_format($r['total_consumption'], 2), 1, 0, 'R');
        $pdf->Cell(25, 8, $r['pob_count'], 1, 1, 'C');
    }

    $pdf->Ln(10);
    $pdf->SetFont('Arial', 'I', 10);
    $pdf->Cell(0, 10, 'Generated on ' . date('Y-m-d H:i:s'), 0, 0, 'R');

    $pdf->Output('D', "Vessel_Report_" . str_replace(' ', '_', $vessel_name) . ".pdf");
    exit;
}

/* =============================
   EXPORT TO CSV (Excel)
============================= */ else {
    header("Content-Type: text/csv; charset=utf-8");
    header("Content-Disposition: attachment; filename=Vessel_Report_{$vessel_name}.csv");
    header("Pragma: no-cache");
    header("Expires: 0");

    $out = fopen("php://output", "w");

    // UTF-8 BOM (fix for Excel)
    fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));

    // Header row
    fputcsv($out, ['Date', 'Status', 'Total ROB', 'Consumption', 'POB']);

    // Data rows
    foreach ($data as $r) {
        fputcsv($out, [
            $r['log_date'],
            strtoupper($r['status']),
            number_format($r['total_rob'], 2),
            number_format($r['total_consumption'], 2),
            $r['pob_count']
        ]);
    }

    fclose($out);
    exit;
}
?>