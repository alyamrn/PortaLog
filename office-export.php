<?php
session_start();
require_once "db_connect.php";

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'OFFICE') {
    header("Location: login.php");
    exit;
}

$username = $_SESSION['full_name'];
$role = $_SESSION['role'];

// --- Filters ---
$vessel_id = $_GET['vessel_id'] ?? '';
$date = $_GET['date'] ?? date('Y-m-d');

// Vessel list
$vessels = $pdo->query("SELECT vessel_id, vessel_name FROM vessels ORDER BY vessel_name")->fetchAll(PDO::FETCH_ASSOC);

// Vessel name
$vesselName = '';
if ($vessel_id) {
    $stmt = $pdo->prepare("SELECT vessel_name FROM vessels WHERE vessel_id=?");
    $stmt->execute([$vessel_id]);
    $vesselName = $stmt->fetchColumn();
}

// === Fetch data from all report tables ===
function getData($pdo, $table, $vessel_id, $date)
{
    $q = $pdo->prepare("SELECT * FROM $table WHERE vessel_id=? AND log_date=?");
    $q->execute([$vessel_id, $date]);
    return $q->fetchAll(PDO::FETCH_ASSOC);
}

$activityData = getData($pdo, 'activitylogs', $vessel_id, $date);
$pobData = getData($pdo, 'pobpersons', $vessel_id, $date);
$garbageData = getData($pdo, 'garbagelogs', $vessel_id, $date);
$navData = getData($pdo, 'navigationreports', $vessel_id, $date);
$oilData = getData($pdo, 'oilrecordbook', $vessel_id, $date);
$robData = getData($pdo, 'rob_records', $vessel_id, $date);
$engineData = getData($pdo, 'runninghours', $vessel_id, $date);

function displayTable($rows, $columns)
{
    if (empty($rows)) {
        return "<p style='color:gray;'>No data available for this section.</p>";
    }

    $html = "<div class='table-wrap'><table class='report-table'><thead><tr>";
    foreach ($columns as $col) {
        $html .= "<th>" . htmlspecialchars(strtoupper(str_replace('_', ' ', $col))) . "</th>";
    }
    $html .= "</tr></thead><tbody>";

    foreach ($rows as $r) {
        $html .= "<tr>";
        foreach ($columns as $col) {
            $html .= "<td>" . htmlspecialchars($r[$col] ?? '-') . "</td>";
        }
        $html .= "</tr>";
    }
    $html .= "</tbody></table></div>";

    return $html;
}

?>


<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Data Analytics</title>
    <link href="https://fonts.googleapis.com/css2?family=Material+Icons+Sharp" rel="stylesheet">
    <link rel="stylesheet" href="style.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        .filter-section {
            display: flex;
            align-items: center;
            justify-content: flex-start;
            background: var(--color-white);
            padding: 1.2rem 1.5rem;
            border-radius: var(--border-radius-2);
            box-shadow: var(--box-shadow);
            margin-bottom: 1.5rem;
            flex-wrap: wrap;
            gap: 1.5rem;
        }

        .filter-section form {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 1.5rem;
            width: 100%;
        }

        .filter-section label {
            font-weight: 600;
            color: var(--color-dark);
            margin-right: 6px;
        }

        .filter-section select,
        .filter-section input[type="date"] {
            padding: 0.6rem 0.9rem;
            border: 1px solid var(--line);
            border-radius: var(--border-radius-1);
            background: var(--color-background);
            color: var(--color-dark);
            font-size: 0.9rem;
            min-width: 160px;
        }

        .filter-section button {
            background: var(--color-primary);
            color: var(--color-white);
            padding: 0.6rem 1.4rem;
            border: none;
            border-radius: var(--border-radius-1);
            cursor: pointer;
            font-weight: 600;
            transition: background 0.25s ease, transform 0.15s ease;
        }

        .filter-section button:hover {
            background: var(--color-success);
            transform: translateY(-2px);
        }


        .analytics-cards {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 1.5rem;
        }

        .analytics-cards .card {
            background: var(--color-white);
            border-radius: var(--card-border-radius);
            padding: var(--card-padding);
            box-shadow: var(--box-shadow);
            transition: all 0.3s ease;
            text-align: center;
        }

        .analytics-cards .card:hover {
            box-shadow: none;
        }

        .analytics-cards h3 {
            color: var(--color-dark-variant);
        }

        .analytics-cards h1 {
            color: var(--color-dark);
            margin-top: 5px;
        }

        .report-options {
            background: var(--color-white);
            padding: 1rem;
            border-radius: var(--border-radius-2);
            box-shadow: var(--box-shadow);
            margin: 1rem 0;
        }

        .report-options label {
            margin-right: 1rem;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .btn-generate {
            display: inline-block;
            margin-top: 10px;
            padding: 10px 20px;
            background: var(--color-primary);
            color: #fff;
            border: none;
            border-radius: var(--border-radius-1);
            font-weight: 600;
            cursor: pointer;
            transition: background 0.2s ease, transform 0.1s ease;
        }

        .btn-generate:hover {
            background: var(--color-success);
            transform: translateY(-2px);
        }

        .report-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 10px;
        }

        .report-table th,
        .report-table td {
            border: 1px solid var(--line);
            padding: 8px;
            text-align: center;
        }

        .btn-excel {
            background: #1B9C85;
            color: #fff;
            padding: 10px 20px;
            border: none;
            border-radius: var(--border-radius-1);
            font-weight: 600;
            cursor: pointer;
            transition: background 0.25s ease, transform 0.15s ease;
        }

        .btn-excel:hover {
            background: #178f75;
            transform: translateY(-2px);
        }

        .checkbox-grid {
            display: flex;
            flex-wrap: wrap;
            gap: 12px 20px;
            margin-top: 8px;
        }

        .preview-section {
            background: var(--color-white);
            padding: 1.5rem;
            border-radius: var(--border-radius-2);
            box-shadow: var(--box-shadow);
            margin-top: 2rem;
        }

        .preview-section h4 {
            margin-top: 1.5rem;
            color: var(--color-dark);
            border-bottom: 2px solid var(--line);
            padding-bottom: 4px;
        }

        .table-wrap {
            overflow-x: auto;
            margin-top: 10px;
        }

        .report-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.9rem;
            margin-bottom: 1rem;
        }

        .report-table th {
            background: var(--accent);
            color: white;
            padding: 8px;
            text-align: center;
        }

        .report-table td {
            border: 1px solid var(--line);
            padding: 6px;
            text-align: center;
        }

        .report-table tr:nth-child(even) td {
            background: #f7f9fc;
        }
    </style>
</head>

<body>
    <div class="container">
        <aside>
            <div class="toggle">
                <div class="logo">
                    <img src="image/BSK_LOGO.jpg" alt="Logo">
                    <h2>Porta<span class="danger">Log</span></h2>
                </div>
                <div class="close" id="close-btn"><span class="material-icons-sharp">close</span></div>
            </div>
            <div class="sidebar">
                <a href="office-dashboard.php"><span class="material-icons-sharp">dashboard</span>
                    <h3>Dashboard</h3>
                </a>
                <a href="office-vessel-report.php"><span class="material-icons-sharp">directions_boat</span>
                    <h3>Vessel Reports</h3>
                </a>
                <a href="office-analysis.php"><span class="material-icons-sharp">bar_chart</span>
                    <h3>Data Analytics</h3>
                </a>
                <a href="office-export.php" class="active"><span class="material-icons-sharp">file_download</span>
                    <h3>Generate Reports</h3>
                </a>
                <a href="office-pob.php"><span class="material-icons-sharp">groups</span>
                    <h3>POB Monitoring</h3>
                </a>
                <a href="office-status.php"><span class="material-icons-sharp">assignment_turned_in</span>
                    <h3>Report Status</h3>
                </a>
                <a href="settings.php"><span class="material-icons-sharp">settings</span>
                    <h3>Settings</h3>
                </a>
                <a href="login.php" class="logout"><span class="material-icons-sharp">logout</span>
                    <h3>Logout</h3>
                </a>
            </div>
        </aside>

        <main>
            <h2>📄 Generate Reports</h2>

            <!-- 🔹 Filter Section -->
            <div class="filter-section">
                <form method="GET">
                    <label><b>Vessel:</b></label>
                    <select name="vessel_id" required>
                        <option value="">Select Vessel</option>
                        <?php foreach ($vessels as $v): ?>
                            <option value="<?= $v['vessel_id'] ?>" <?= $vessel_id == $v['vessel_id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($v['vessel_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <label><b>Date:</b></label>
                    <input type="date" name="date" value="<?= htmlspecialchars($date) ?>" required>

                    <button type="submit">🔍 Filter</button>
                </form>
            </div>

            <!-- 🔹 Report Section Selection -->
            <form method="POST" id="reportForm" target="_blank">
                <input type="hidden" name="vessel_id" value="<?= htmlspecialchars($vessel_id) ?>">
                <input type="hidden" name="vessel_name" value="<?= htmlspecialchars($vesselName) ?>">
                <input type="hidden" name="date" value="<?= htmlspecialchars($date) ?>">

                <div class="report-options">
                    <h3>Select Reports to Include:</h3>
                    <div class="checkbox-grid">
                        <label><input type="checkbox" name="sections[]" value="activity" checked> Activity Log</label>
                        <label><input type="checkbox" name="sections[]" value="pob" checked> POB List</label>
                        <label><input type="checkbox" name="sections[]" value="garbage" checked> Garbage Log</label>
                        <label><input type="checkbox" name="sections[]" value="navigation" checked> Navigation
                            Report</label>
                        <label><input type="checkbox" name="sections[]" value="oil" checked> Oil Record Book</label>
                        <label><input type="checkbox" name="sections[]" value="rob" checked> ROB Records</label>
                        <label><input type="checkbox" name="sections[]" value="engine" checked> Running Hours</label>
                    </div>
                </div>

                <div style="margin-top:15px; display:flex; gap:10px;">
                    <button type="submit" formaction="generate-pdf.php" class="btn-generate">📄 Generate PDF</button>
                    <button type="submit" formaction="generate-excel.php" class="btn-excel">📊 Generate Excel</button>
                </div>
            </form>

            <!-- 🔹 Data Preview Section -->
        </main>

        <!-- Right Section -->
        <div class="right-section">
            <div class="nav">
                <button id="menu-btn"><span class="material-icons-sharp">menu</span></button>
                <div class="dark-mode">
                    <span class="material-icons-sharp active">light_mode</span>
                    <span class="material-icons-sharp">dark_mode</span>
                </div>
                <div class="profile">
                    <div class="info">
                        <p>Hey, <b><?= htmlspecialchars($username) ?></b></p>
                        <small class="text-muted"><?= htmlspecialchars($role) ?></small>
                    </div>
                    <div class="profile-photo"><img src="image/BSK_LOGO.jpg" alt="Profile"></div>
                </div>
            </div>
            <div class="user-profile">
                <div class="logo">
                    <img src="image/BSK_LOGO.jpg" alt="Logo">
                    <h2>PortaLog</h2>
                    <p>Office Console</p>
                </div>
            </div>
        </div>

    </div>


    <script src="index.js"></script>
    <script src="appointment.js"></script>
    <script src="clock.js"></script>
</body>

</html>