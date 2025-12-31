<?php
session_start();
require_once "db_connect.php";

// ✅ Access control
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'OFFICE') {
    header("Location: login.php");
    exit;
}

$username = $_SESSION['full_name'];
$role = $_SESSION['role'];

// --- Get vessels for filter dropdown ---
$vessels = $pdo->query("SELECT vessel_id, vessel_name FROM vessels ORDER BY vessel_name")->fetchAll(PDO::FETCH_ASSOC);

// --- Get filters ---
$selectedVessel = $_GET['vessel_id'] ?? '';
$startDate = $_GET['start_date'] ?? date('Y-m-01');
$endDate = $_GET['end_date'] ?? date('Y-m-d');

$reports = [];
if ($selectedVessel) {
    $stmt = $pdo->prepare("
        SELECT 
            d.log_date, d.status,
            COALESCE(SUM(r.current_rob), 0) AS total_rob,
            COALESCE(SUM(r.daily_consumption), 0) AS total_consumption,
            COALESCE((SELECT COUNT(DISTINCT s.person_id) FROM pob_stints s WHERE s.vessel_id = d.vessel_id AND s.embark_date <= d.log_date AND (s.disembark_date IS NULL OR s.disembark_date >= d.log_date)), 0) AS pob_count
        FROM dailystatus d
        LEFT JOIN rob_records r ON r.vessel_id = d.vessel_id AND r.log_date = d.log_date
        WHERE d.vessel_id = :vessel_id AND d.log_date BETWEEN :start AND :end
        GROUP BY d.log_date, d.status
        ORDER BY d.log_date DESC
    ");
    $stmt->execute(['vessel_id' => $selectedVessel, 'start' => $startDate, 'end' => $endDate]);
    $reports = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Summary KPIs
    $totalReports = count($reports);
    $lockedReports = array_sum(array_map(fn($r) => $r['status'] === 'LOCKED' ? 1 : 0, $reports));
    $avgRob = $pdo->prepare("SELECT AVG(current_rob) FROM rob_records WHERE vessel_id = ? AND log_date BETWEEN ? AND ?");
    $avgRob->execute([$selectedVessel, $startDate, $endDate]);
    $avgRob = round($avgRob->fetchColumn() ?? 0, 2);
    $avgConsumption = $pdo->prepare("SELECT AVG(daily_consumption) FROM rob_records WHERE vessel_id = ? AND log_date BETWEEN ? AND ?");
    $avgConsumption->execute([$selectedVessel, $startDate, $endDate]);
    $avgConsumption = round($avgConsumption->fetchColumn() ?? 0, 2);
}
?>
<?php
$selectedVessel = $_GET['vessel_id'] ?? null;

if ($selectedVessel) {
    try {
        $query = $pdo->prepare("
            SELECT 
                d.vessel_id,
                d.log_date,
                d.status,

                -- Activity Log
                (SELECT COUNT(*) FROM activitylogs a 
                    WHERE a.vessel_id = d.vessel_id 
                    AND a.log_date = d.log_date) AS activity_filled,

                -- Navigation Report
                (SELECT COUNT(*) FROM navigationreports n 
                    WHERE n.vessel_id = d.vessel_id 
                    AND n.log_date = d.log_date) AS nav_filled,

                                -- POB (based on active stints on that date)
                                (SELECT COUNT(DISTINCT s.person_id) FROM pob_stints s
                                        WHERE s.vessel_id = d.vessel_id
                                            AND s.embark_date <= d.log_date
                                            AND (s.disembark_date IS NULL OR s.disembark_date >= d.log_date)
                                ) AS pob_filled,

                -- Running Hours
                (SELECT COUNT(*) FROM runninghours rh 
                    WHERE rh.vessel_id = d.vessel_id 
                    AND rh.log_date = d.log_date) AS rh_filled,

                -- ROB
                (SELECT COUNT(*) FROM rob_records rr 
                    WHERE rr.vessel_id = d.vessel_id 
                    AND rr.log_date = d.log_date) AS rob_filled,

                -- Garbage Record
                (SELECT COUNT(*) FROM garbagelogs g 
                    WHERE g.vessel_id = d.vessel_id 
                    AND g.log_date = d.log_date) AS garbage_filled,

                -- Oil Record
                (SELECT COUNT(*) FROM oilrecordbook o 
                    WHERE o.vessel_id = d.vessel_id 
                    AND o.log_date = d.log_date) AS oil_filled

            FROM dailystatus d
            WHERE d.vessel_id = :vessel_id
            ORDER BY d.log_date DESC
            LIMIT 14
        ");
        $query->execute(['vessel_id' => $selectedVessel]);
        $logbookRows = $query->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        echo "<p style='color:red;'>Database Error: " . htmlspecialchars($e->getMessage()) . "</p>";
        $logbookRows = [];
    }
} else {
    $logbookRows = [];
}
?>


<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://fonts.googleapis.com/css2?family=Material+Icons+Sharp" rel="stylesheet">
    <link rel="stylesheet" href="style.css">
    <title>Vessel Reports</title>
    <style>
        .filter-bar {
            background: var(--color-white);
            padding: 1rem;
            border-radius: var(--card-border-radius);
            box-shadow: var(--box-shadow);
            display: flex;
            align-items: center;
            gap: 1rem;
            justify-content: space-between;
        }

        .filter-bar form {
            display: flex;
            gap: 1rem;
            align-items: center;
            width: 100%;
        }

        .filter-bar select,
        .filter-bar input[type="date"] {
            padding: 8px 12px;
            border: 1px solid var(--color-light);
            border-radius: var(--border-radius-1);
            background: var(--color-white);
            color: var(--color-dark);
            font-size: 0.9rem;
        }
        /* Change the calendar icon color without hiding it */
input[type="date"]::-webkit-calendar-picker-indicator {
    filter: invert(70%) sepia(80%) saturate(400%) hue-rotate(180deg);
    opacity: 0.9;
    cursor: pointer;
}

        .filter-bar button {
            background: var(--color-primary);
            color: white;
            padding: 8px 16px;
            border: none;
            border-radius: var(--border-radius-1);
            cursor: pointer;
            transition: 0.3s;
        }

        .filter-bar button:hover {
            background: var(--color-success);
        }

        .kpi-grid {
            margin-top: 1.5rem;
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 1.2rem;
        }

        .kpi-card {
            background: var(--color-white);
            padding: 1.2rem;
            border-radius: var(--card-border-radius);
            text-align: center;
            box-shadow: var(--box-shadow);
            transition: 0.3s;
        }

        .kpi-card:hover {
            transform: translateY(-4px);
            box-shadow: none;
        }

        .kpi-card h3 {
            color: var(--color-dark);
        }

        .kpi-card h1 {
            color: var(--color-primary);
            font-size: 2rem;
            margin: 0.3rem 0;
        }

        table.vessel-report {
            width: 100%;
            margin-top: 2rem;
            border-collapse: collapse;
            background: var(--color-white);
            border-radius: var(--card-border-radius);
            overflow: hidden;
            box-shadow: var(--box-shadow);
        }

        table.vessel-report th,
        table.vessel-report td {
            padding: 12px;
            text-align: center;
            border-bottom: 1px solid var(--color-light);
        }

        table.vessel-report th {
            background: var(--color-primary);
            color: white;
        }

        table.vessel-report tr:hover {
            background: var(--color-light);
        }

        /* Horizontal filter bar */
        .filter-bar {
            background: var(--color-white);
            padding: 1rem 1.5rem;
            border-radius: var(--card-border-radius);
            box-shadow: var(--box-shadow);
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 1rem;
        }

        /* Inner form layout */
        .filter-bar form {
            display: flex;
            align-items: center;
            gap: 1rem;
            flex-wrap: wrap;
            width: 100%;
        }

        /* Input and select styling */
        .filter-bar select,
        .filter-bar input[type="date"] {
            padding: 8px 14px;
            border-radius: var(--border-radius-1);
            border: 1px solid var(--color-light);
            background: var(--color-background);
            color: var(--color-dark);
            font-size: 0.9rem;
            min-width: 160px;
            transition: all 0.3s ease;
        }

        .filter-bar label {
            font-weight: 600;
            color: var(--color-dark);
        }

        /* Buttons inline */
        .filter-bar button {
            background: var(--color-primary);
            color: white;
            padding: 8px 18px;
            border: none;
            border-radius: var(--border-radius-1);
            cursor: pointer;
            transition: background 0.3s ease;
            font-weight: 600;
        }

        .filter-bar button:hover {
            background: var(--color-success);
        }

        /* Compact form elements on small screens */
        @media (max-width: 768px) {
            .filter-bar form {
                flex-direction: column;
                align-items: flex-start;
            }
        }
        /* --- Logbook Status Table --- */
.log-status-table {
    margin-top: 2rem;
    background: var(--color-white);
    padding: var(--card-padding);
    border-radius: var(--card-border-radius);
    box-shadow: var(--box-shadow);
    transition: all 0.3s ease;
}

.log-status-table:hover {
    box-shadow: none;
}

.log-status-table h2 {
    color: var(--color-dark);
    font-size: 1.2rem;
    font-weight: 700;
    margin-bottom: 1rem;
}

/* Table styling */
.log-status-table table {
    width: 100%;
    border-collapse: collapse;
    text-align: center;
    background: var(--color-background);
    border-radius: 10px;
    overflow: hidden;
}

/* Header */
.log-status-table thead th {
    background: var(--color-primary);
    color: #fff;
    padding: 12px;
    font-weight: 600;
    letter-spacing: 0.5px;
    border-bottom: 2px solid var(--color-light);
    text-transform: uppercase;
}

/* Rows */
.log-status-table tbody td {
    padding: 10px;
    font-size: 0.95rem;
    color: var(--color-dark-variant);
    border-bottom: 1px solid var(--color-light);
}

/* Alternate row background for readability */
.log-status-table tbody tr:nth-child(even) td {
    background: rgba(108, 155, 207, 0.05);
}

/* Hover effect */
.log-status-table tbody tr:hover {
    background: rgba(108, 155, 207, 0.15);
    transform: scale(1.005);
    transition: all 0.2s ease;
}

/* Status icons */
.log-status-table td {
    vertical-align: middle;
}

.log-status-table td:contains('✅') {
    color: var(--color-success);
    font-weight: 700;
}

.log-status-table td:contains('❌') {
    color: var(--color-danger);
    font-weight: 700;
}

/* Status column */
.log-status-table td:last-child {
    text-transform: uppercase;
    font-weight: 600;
}

/* When table is empty */
.log-status-table em {
    color: var(--color-info-dark);
    font-style: italic;
}

/* Responsive: horizontal scroll for small screens */
@media (max-width: 900px) {
    .log-status-table {
        overflow-x: auto;
    }

    .log-status-table table {
        min-width: 800px;
    }
}

    </style>
</head>

<body>
    <div class="container">
        <!-- Sidebar -->
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
                <a href="office-vessel-report.php" class="active"><span
                        class="material-icons-sharp">directions_boat</span>
                    <h3>Vessel Reports</h3>
                </a>
                <a href="office-analysis.php"><span class="material-icons-sharp">bar_chart</span>
                    <h3>Data Analytics</h3>
                </a>
                <a href="office-export.php"><span class="material-icons-sharp">file_download</span>
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

        <!-- Main -->
        <main>
            <h2>Vessel Reports</h2>

            <div class="filter-bar">
                <form method="GET">
                    <div style="display: flex; align-items: center; gap: 1rem; flex-wrap: wrap;">
                        <select name="vessel_id" required>
                            <option value="">-- Select Vessel --</option>
                            <?php foreach ($vessels as $v): ?>
                                <option value="<?= $v['vessel_id'] ?>" <?= $selectedVessel == $v['vessel_id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($v['vessel_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>

                        <label>From</label>
                        <input type="date" name="start_date" value="<?= $startDate ?>">

                        <label>To</label>
                        <input type="date" name="end_date" value="<?= $endDate ?>">

                        <button type="submit">🔍 Filter</button>

                        <?php if ($selectedVessel): ?>
                            <button type="submit" formaction="export-vessel-report.php" name="export" value="excel">📊
                                Excel</button>
                            <button type="submit" formaction="export-vessel-report.php" name="export" value="pdf">📄
                                PDF</button>
                        <?php endif; ?>
                    </div>
                </form>
            </div>




            <?php if ($selectedVessel): ?>
                <div class="kpi-grid">
                    <div class="kpi-card">
                        <h3>Total Reports</h3>
                        <h1><?= $totalReports ?></h1>
                    </div>
                    <div class="kpi-card">
                        <h3>Locked Reports</h3>
                        <h1><?= $lockedReports ?></h1>
                    </div>
                    <div class="kpi-card">
                        <h3>Avg Fuel ROB</h3>
                        <h1><?= $avgRob ?></h1>
                    </div>
                    <div class="kpi-card">
                        <h3>Avg Daily Consumption</h3>
                        <h1><?= $avgConsumption ?></h1>
                    </div>
                </div>

                <table class="vessel-report">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Status</th>
                            <th>Total Fuel (L)</th>
                            <th>Daily Consumption</th>
                            <th>POB</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($reports as $r): ?>
                            <tr>
                                <td><?= htmlspecialchars($r['log_date']) ?></td>
                                <td><?= htmlspecialchars($r['status']) ?></td>
                                <td><?= number_format($r['total_rob'], 2) ?></td>
                                <td><?= number_format($r['total_consumption'], 2) ?></td>
                                <td><?= $r['pob_count'] ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <div class="log-status-table">
                    <h2>Daily Logbook Status (Last 14 Days)</h2>
                    <table>
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Activity</th>
                                <th>Navigation</th>
                                <th>POB</th>
                                <th>Running Hours</th>
                                <th>ROB</th>
                                <th>Garbage</th>
                                <th>Oil</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($logbookRows)): ?>
                                <?php foreach ($logbookRows as $r): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($r['log_date']) ?></td>
                                        <td><?= $r['activity_filled'] ? "✅" : "❌" ?></td>
                                        <td><?= $r['nav_filled'] ? "✅" : "❌" ?></td>
                                        <td><?= $r['pob_filled'] ? "✅" : "❌" ?></td>
                                        <td><?= $r['rh_filled'] ? "✅" : "❌" ?></td>
                                        <td><?= $r['rob_filled'] ? "✅" : "❌" ?></td>
                                        <td><?= $r['garbage_filled'] ? "✅" : "❌" ?></td>
                                        <td><?= $r['oil_filled'] ? "✅" : "❌" ?></td>
                                        <td style="font-weight:600;"><?= htmlspecialchars($r['status']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="9"><em>No data available — please select a vessel.</em></td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

            <?php else: ?>
                <p style="margin-top:20px; color: var(--color-info-dark);">Select a vessel to view report data.</p>
            <?php endif; ?>

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
                    <div class="profile-photo"><img src="image/blankProf.png" alt="Profile"></div>
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
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script>
        document.addEventListener("DOMContentLoaded", function () {
            const vesselSelect = document.querySelector("select[name='vessel_id']");
            const startDate = document.querySelector("input[name='start_date']");
            const endDate = document.querySelector("input[name='end_date']");

            // Smooth fade-in animation for KPIs
            document.querySelectorAll('.kpi-card').forEach(card => {
                card.style.opacity = 0;
                setTimeout(() => {
                    card.style.transition = 'opacity 0.8s ease';
                    card.style.opacity = 1;
                }, 100);
            });

            // Auto-update chart if filter is changed
            vesselSelect.addEventListener("change", () => document.forms[0].submit());

            // Optional: Live chart reload (AJAX style)
            const fuelCanvas = document.getElementById("fuelChart");
            if (fuelCanvas) {
                fetch(`fetch-fuel-data.php?vessel_id=${vesselSelect.value}&start=${startDate.value}&end=${endDate.value}`)
                    .then(res => res.json())
                    .then(data => {
                        new Chart(fuelCanvas, {
                            type: "line",
                            data: {
                                labels: data.labels,
                                datasets: [{
                                    label: "Fuel (L)",
                                    data: data.values,
                                    borderColor: "#1B9C85",
                                    fill: true,
                                    backgroundColor: "rgba(27,156,133,0.15)"
                                }]
                            },
                            options: {
                                responsive: true,
                                plugins: {
                                    legend: { display: false }
                                },
                                scales: {
                                    x: { grid: { color: 'rgba(255,255,255,0.1)' } },
                                    y: { grid: { color: 'rgba(255,255,255,0.1)' } }
                                }
                            }
                        });
                    });
            }
        });
    </script>
    <script src="index.js"></script>
    <script src="appointment.js"></script>
    <script src="clock.js"></script>

</body>


</html>