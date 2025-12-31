<?php
require_once "db_connect.php";

// ✅ Load Dompdf from Composer
$autoloadPath = __DIR__ . '/vendor/autoload.php';
if (!file_exists($autoloadPath)) {
    die("<p style='color:red;font-weight:bold;'>❌ Dompdf autoloader not found.<br>
    Please run <code>composer require dompdf/dompdf</code>.</p>");
}
require_once $autoloadPath;

use Dompdf\Dompdf;
use Dompdf\Options;

// --- Configure Dompdf ---
$options = new Options();
$options->set('isHtml5ParserEnabled', true);
$options->set('isRemoteEnabled', true);
$options->set('defaultFont', 'DejaVu Sans');
$dompdf = new Dompdf($options);

// --- Input ---
$vessel_id  = $_POST['vessel_id'] ?? '';
$vesselName = $_POST['vessel_name'] ?? 'Unknown Vessel';
$date       = $_POST['date'] ?? date('Y-m-d');
$sections   = $_POST['sections'] ?? [];

$logoPath = 'image/BSK_LOGO.jpg';

// --- Helper: Build Table ---
function buildTable($title, $rows)
{
    if (!$rows || count($rows) === 0) {
        return "<h3 style='margin-top:25px;'>$title</h3>
                <p style='color:gray;'>No data available for this section.</p>";
    }

    // Filter out ID columns automatically
    $headers = array_keys($rows[0]);
    $headers = array_filter($headers, fn($h) => stripos($h, '_id') === false);

    // Apply same filter to rows
    $filteredRows = [];
    foreach ($rows as $r) {
        $filteredRows[] = array_intersect_key($r, array_flip($headers));
    }

    $colCount = count($headers);
    $fontSize = $colCount > 7 ? "font-size:9px;" : "font-size:11px;";
    $headerColor = "#0052cc";

    $html = "
    <div style='margin-bottom:25px;'>
    <h3 style='margin-top:25px; color:#222; font-family:DejaVu Sans; border-left:4px solid $headerColor; padding-left:8px;'>$title</h3>
    <table style='width:100%; border-collapse:collapse; $fontSize margin-top:8px;'>
        <thead>
            <tr style='background:$headerColor; color:#fff; text-align:center;'>";

    foreach ($headers as $h) {
        $html .= "<th style='padding:6px; border:1px solid #ccc; font-weight:600;'>"
            . strtoupper(str_replace('_', ' ', $h)) . "</th>";
    }

    $html .= "</tr></thead><tbody>";

    $alt = false;
    foreach ($filteredRows as $r) {
        $rowColor = $alt ? '#f6f9fc' : '#ffffff';
        $html .= "<tr style='background:$rowColor;'>";
        foreach ($r as $v) {
            $val = htmlspecialchars($v ?? '-');
            $align = is_numeric($val) ? 'text-align:right;' : 'text-align:center;';
            $html .= "<td style='padding:6px; border:1px solid #ddd; $align vertical-align:middle;'>$val</td>";
        }
        $html .= "</tr>";
        $alt = !$alt;
    }

    $html .= "</tbody></table></div>";
    return $html;
}

// --- Header Section ---
function buildHeader($vesselName, $date, $logoPath)
{
    return "
    <div style='text-align:center; font-family:DejaVu Sans;'>
        <img src='$logoPath' width='70' style='margin-bottom:6px;'><br>
        <h2 style='margin:0; color:#003366;'>Daily Vessel Report</h2>
        <h3 style='margin:2px 0 8px 0; color:#007bff;'>" . htmlspecialchars($vesselName) . "</h3>
        <p style='margin:4px 0; font-size:13px;'><b>Date:</b> " . htmlspecialchars($date) . "</p>
    </div>
    <hr style='margin:10px 0; border:1px solid #ddd;'>";
}

// --- Begin Building the PDF HTML ---
$html = buildHeader($vesselName, $date, $logoPath);

// --- Process each section ---
foreach ($sections as $section) {
    switch ($section) {

        case 'activity':
            $q = $pdo->prepare("SELECT * FROM activitylogs WHERE vessel_id=? AND log_date=?");
            $q->execute([$vessel_id, $date]);
            $html .= buildTable("Activity Log", $q->fetchAll(PDO::FETCH_ASSOC));
            break;

        case 'pob':
            // ✅ FIXED: POB derived from pob_stints + crewlist
            $q = $pdo->prepare("
                SELECT
                    c.full_name   AS crew_name,
                    c.nationality AS nationality,
                    ps.crew_role,
                    ps.category,
                    ps.embark_date,
                    ps.disembark_date
                FROM pob_stints ps
                INNER JOIN crewlist c ON c.id = ps.person_id
                WHERE ps.vessel_id = ?
                  AND ps.embark_date <= ?
                  AND (ps.disembark_date IS NULL OR ps.disembark_date > ?)
                ORDER BY ps.crew_role, c.full_name
            ");
            $q->execute([$vessel_id, $date, $date]);
            $html .= buildTable("POB List", $q->fetchAll(PDO::FETCH_ASSOC));
            break;

        case 'garbage':
            $q = $pdo->prepare("SELECT * FROM garbagelogs WHERE vessel_id=? AND log_date=?");
            $q->execute([$vessel_id, $date]);
            $html .= buildTable("Garbage Log", $q->fetchAll(PDO::FETCH_ASSOC));
            break;

        case 'navigation':
            $q = $pdo->prepare("SELECT * FROM navigationreports WHERE vessel_id=? AND log_date=?");
            $q->execute([$vessel_id, $date]);
            $html .= buildTable("Navigation Report", $q->fetchAll(PDO::FETCH_ASSOC));
            break;

        case 'oil':
            $q = $pdo->prepare("SELECT * FROM oilrecordbook WHERE vessel_id=? AND log_date=?");
            $q->execute([$vessel_id, $date]);
            $html .= buildTable("Oil Record Book", $q->fetchAll(PDO::FETCH_ASSOC));
            break;

        case 'rob':
            $q1 = $pdo->prepare("SELECT * FROM rob_records WHERE vessel_id=? AND log_date=? AND category='LIQUID'");
            $q1->execute([$vessel_id, $date]);
            $liquidRows = $q1->fetchAll(PDO::FETCH_ASSOC);

            $q2 = $pdo->prepare("SELECT * FROM rob_records WHERE vessel_id=? AND log_date=? AND category='DRY'");
            $q2->execute([$vessel_id, $date]);
            $dryRows = $q2->fetchAll(PDO::FETCH_ASSOC);

            $html .= buildTable("ROB Records (Liquid Bulk)", $liquidRows);
            $html .= buildTable("ROB Records (Dry Bulk)", $dryRows);
            break;

        case 'engine':
            $q = $pdo->prepare("SELECT * FROM runninghours WHERE vessel_id=? AND log_date=?");
            $q->execute([$vessel_id, $date]);
            $html .= buildTable("Running Hours", $q->fetchAll(PDO::FETCH_ASSOC));
            break;
    }
}

// --- Set orientation (landscape only if ROB selected) ---
$orientation = in_array('rob', $sections) ? 'landscape' : 'portrait';

// --- Render final PDF ---
$dompdf->loadHtml($html);
$dompdf->setPaper('A4', $orientation);
$dompdf->render();

// --- Stream output ---
$filename = preg_replace('/[^A-Za-z0-9_-]/', '_', "{$vesselName}_{$date}_Report.pdf");
$dompdf->stream($filename, ["Attachment" => false]);
exit;
