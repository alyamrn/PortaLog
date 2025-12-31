<?php
require_once "db_connect.php";

// --- Input Variables ---
$vessel_id = $_POST['vessel_id'] ?? '';
$vesselName = $_POST['vessel_name'] ?? 'Unknown Vessel';
$date = $_POST['date'] ?? date('Y-m-d');
$sections = $_POST['sections'] ?? [];

// --- Generate Excel XML (SpreadsheetML) ---
$xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
$xml .= '<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet"' . "\n";
$xml .= ' xmlns:o="urn:schemas-microsoft-com:office:office"' . "\n";
$xml .= ' xmlns:x="urn:schemas-microsoft-com:office:excel"' . "\n";
$xml .= ' xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet">' . "\n";
$xml .= '<Styles>' . "\n";
$xml .= '<Style ss:ID="Header"><Font ss:Bold="1" ss:Color="#FFFFFF"/><Interior ss:Color="#4472C4" ss:Pattern="Solid"/><Alignment ss:Horizontal="Center"/></Style>' . "\n";
$xml .= '<Style ss:ID="AltRow"><Interior ss:Color="#F2F2F2" ss:Pattern="Solid"/></Style>' . "\n";
$xml .= '</Styles>' . "\n";
$xml .= '<Worksheet ss:Name="Vessel Report">' . "\n";

$rowNum = 1;

// --- Header Info ---
$xml .= '<Table>' . "\n";
$xml .= "<Row ss:Height='25'><Cell ss:MergeAcross='4' ss:StyleID='Header'><Data ss:Type='String'>Daily Vessel Report</Data></Cell></Row>\n";
$rowNum++;

$xml .= "<Row><Cell><Data ss:Type='String'>Vessel Name:</Data></Cell><Cell><Data ss:Type='String'>$vesselName</Data></Cell></Row>\n";
$rowNum++;

$xml .= "<Row><Cell><Data ss:Type='String'>Date:</Data></Cell><Cell><Data ss:Type='String'>$date</Data></Cell></Row>\n";
$rowNum += 2;

// --- Helper Function ---
function exportSection($pdo, &$xml, &$rowNum, $title, $table, $vessel_id, $date)
{
    $xml .= "<Row ss:Height='20'><Cell><Data ss:Type='String'><b>$title</b></Data></Cell></Row>\n";
    $rowNum++;

    try {
        // Build query based on table type
        if ($table === 'pob_stints') {
            // Special handling for POB - join with crewlist to get names
            $query = $pdo->prepare("
                SELECT 
                    ps.person_id,
                    c.full_name,
                    c.crew_role,
                    ps.embark_date,
                    ps.disembark_date
                FROM pob_stints ps
                INNER JOIN crewlist c ON c.id = ps.person_id
                WHERE ps.vessel_id = ?
                  AND ps.embark_date <= ?
                  AND (ps.disembark_date IS NULL OR ps.disembark_date >= ?)
                ORDER BY ps.crew_role, c.full_name
            ");
            $query->execute([$vessel_id, $date, $date]);
        } else {
            $query = $pdo->prepare("SELECT * FROM $table WHERE vessel_id = ? AND log_date = ?");
            $query->execute([$vessel_id, $date]);
        }
        
        $rows = $query->fetchAll(PDO::FETCH_ASSOC);

        if (!$rows) {
            $xml .= "<Row><Cell><Data ss:Type='String'>No data available for this section</Data></Cell></Row>\n";
            $rowNum += 2;
            return;
        }

        // Columns to exclude
        $excludeColumns = ['log_date', 'vessel_id', 'id'];
        $headers = array_filter(array_keys($rows[0]), function ($col) use ($excludeColumns) {
            return !in_array($col, $excludeColumns);
        });

        // Add headers
        $xml .= "<Row ss:StyleID='Header'>";
        foreach ($headers as $header) {
            $headerText = strtoupper(str_replace('_', ' ', $header));
            $xml .= "<Cell ss:StyleID='Header'><Data ss:Type='String'>$headerText</Data></Cell>";
        }
        $xml .= "</Row>\n";
        $rowNum++;

        // Add data rows
        $alt = false;
        foreach ($rows as $r) {
            $styleId = $alt ? "AltRow" : "";
            $xml .= $styleId ? "<Row ss:StyleID='$styleId'>" : "<Row>";
            
            foreach ($headers as $header) {
                $value = isset($r[$header]) ? htmlspecialchars($r[$header], ENT_XML1) : '';
                $xml .= "<Cell><Data ss:Type='String'>$value</Data></Cell>";
            }
            $xml .= "</Row>\n";
            $alt = !$alt;
            $rowNum++;
        }

        $rowNum += 2;
    } catch (Exception $e) {
        $xml .= "<Row><Cell><Data ss:Type='String'>Error loading data: " . htmlspecialchars($e->getMessage()) . "</Data></Cell></Row>\n";
        $rowNum += 2;
    }
}

// --- Export Selected Sections ---
foreach ($sections as $section) {
    switch ($section) {
        case 'activity':
            exportSection($pdo, $xml, $rowNum, 'Activity Log', 'activitylogs', $vessel_id, $date);
            break;
        case 'pob':
            exportSection($pdo, $xml, $rowNum, 'POB List', 'pob_stints', $vessel_id, $date);
            break;
        case 'garbage':
            exportSection($pdo, $xml, $rowNum, 'Garbage Log', 'garbagelogs', $vessel_id, $date);
            break;
        case 'navigation':
            exportSection($pdo, $xml, $rowNum, 'Navigation Report', 'navigationreports', $vessel_id, $date);
            break;
        case 'oil':
            exportSection($pdo, $xml, $rowNum, 'Oil Record Book', 'oilrecordbook', $vessel_id, $date);
            break;
        case 'rob':
            exportSection($pdo, $xml, $rowNum, 'ROB Records', 'rob_records', $vessel_id, $date);
            break;
        case 'engine':
            exportSection($pdo, $xml, $rowNum, 'Running Hours', 'runninghours', $vessel_id, $date);
            break;
    }
}

$xml .= "</Table>\n";
$xml .= "</Worksheet>\n";
$xml .= "</Workbook>\n";

// --- Clean buffer before sending headers ---
if (ob_get_length())
    ob_end_clean();

// --- Output Excel File (.xlsx) ---
$filename = preg_replace('/[^A-Za-z0-9_-]/', '_', "{$vesselName}_{$date}_Report.xlsx");
header("Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet");
header('Content-Disposition: attachment; filename="' . basename($filename) . '"; filename*=UTF-8\'\'' . rawurlencode($filename));
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: public");
header("Expires: 0");

echo $xml;
exit;
