<?php
session_start();
require_once "db_connect.php";

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'OFFICE') {
    header("Location: login.php");
    exit;
}

$username = $_SESSION['full_name'];
$role = $_SESSION['role'];

/* --- Fetch Vessels for Filter --- */
$vessels = $pdo->query("SELECT vessel_id, vessel_name FROM vessels ORDER BY vessel_name")->fetchAll();
$selectedVessel = isset($_GET['vessel_id']) && $_GET['vessel_id'] !== '' ? $_GET['vessel_id'] : null;

// Ensure as-of date and vessel id variables are defined for later queries
// $date used as the reference date (YYYY-MM-DD)
$date = date('Y-m-d');
// normalize vessel id: integer or null
$vessel_id = $selectedVessel !== null && $selectedVessel !== '' ? (int) $selectedVessel : null;

/* --- WHERE clause for filter --- */
$whereClause = $selectedVessel ? "WHERE vessel_id = $selectedVessel" : "";

/* === KPI DATA === */
$active_vessels = $pdo->query("
    SELECT COUNT(DISTINCT vessel_id)
    FROM dailystatus
    WHERE log_date = CURDATE()
")->fetchColumn();

$locked_reports = $pdo->query("
    SELECT COUNT(*)
    FROM dailystatus
    WHERE status='LOCKED' AND log_date = CURDATE()
")->fetchColumn();

// $pdo already set; $date is the as-of date (YYYY-MM-DD), e.g. $date = date('Y-m-d');
// $vessel_id can be null/empty or an integer

    $sql = "
    SELECT COUNT(DISTINCT s.person_id) AS total_pob
    FROM pob_stints s
    WHERE s.embark_date <= ?
      AND (s.disembark_date IS NULL OR s.disembark_date >= ?)
";

$params = [$date, $date];

if (!empty($vessel_id)) {
    $sql .= " AND s.vessel_id = ?";
    $params[] = $vessel_id;
}

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$total_pob = (int) $stmt->fetchColumn();

/* === CHARTS DATA === */

// Fuel data (use prepared statements to avoid direct interpolation)
$fuelParams = [];
$fuelSql = "
    SELECT log_date, SUM(current_rob) AS total_fuel
    FROM rob_records
    WHERE product = 'FUEL'
";
if (!empty($vessel_id)) {
    $fuelSql .= " AND vessel_id = ?";
    $fuelParams[] = $vessel_id;
}
$fuelSql .= "\n    GROUP BY log_date\n    ORDER BY log_date DESC\n    LIMIT 7\n";
$fuelStmt = $pdo->prepare($fuelSql);
$fuelStmt->execute($fuelParams);
$fuelData = $fuelStmt->fetchAll(PDO::FETCH_ASSOC);

$fuelLabels = [];
$fuelValues = [];
foreach (array_reverse($fuelData) as $row) {
    $fuelLabels[] = $row['log_date'];
    $fuelValues[] = round($row['total_fuel'], 2);
}

/* Crew Distribution (based on active stints as-of $date) */
$crewParams = [$date, $date];
$crewSql = "
    SELECT COALESCE(s.category, 'UNCATEGORIZED') AS category, COUNT(DISTINCT s.person_id) AS total
    FROM pob_stints s
    WHERE s.embark_date <= ?
      AND (s.disembark_date IS NULL OR s.disembark_date >= ?)
";
if (!empty($vessel_id)) {
    $crewSql .= " AND s.vessel_id = ?";
    $crewParams[] = $vessel_id;
}
$crewSql .= "\n    GROUP BY category\n";
$crewStmt = $pdo->prepare($crewSql);
$crewStmt->execute($crewParams);
$crewDist = $crewStmt->fetchAll(PDO::FETCH_ASSOC);

$crewLabels = [];
$crewValues = [];
foreach ($crewDist as $c) {
    $crewLabels[] = $c['category'];
    $crewValues[] = $c['total'];
}

?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://fonts.googleapis.com/css2?family=Material+Icons+Sharp" rel="stylesheet" />
    <link rel="stylesheet" href="style.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <title>Office Dashboard</title>
    <style>
        /* === Office Dashboard Additions === */

        main.office-main {
            margin-top: 1.4rem;
            padding-bottom: 2rem;
        }

        main.office-main h2 {
            font-size: 1.6rem;
            color: var(--color-dark);
            font-weight: 700;
        }

        main.office-main p {
            color: var(--color-info-dark);
        }

        /* KPI Card Style (Matches your theme) */
        .insights {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 1.8rem;
            margin-top: 1.8rem;
        }

        .insights>div {
            background: var(--color-white);
            border-radius: var(--card-border-radius);
            box-shadow: var(--box-shadow);
            padding: 1.6rem 1.8rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            transition: all 0.3s ease;
        }

        .insights>div:hover {
            transform: translateY(-5px);
            box-shadow: none;
        }

        .insights span {
            font-size: 3rem;
            color: var(--color-primary);
            opacity: 0.9;
        }

        .insights h3 {
            color: var(--color-dark);
            margin-bottom: 0.4rem;
        }

        .insights h1 {
            color: var(--color-primary);
            font-size: 1.8rem;
            font-weight: bold;
        }

        .insights small {
            color: var(--color-info-dark);
        }

        /* Chart cards */
        .chart-card {
            background: var(--color-white);
            border-radius: var(--card-border-radius);
            box-shadow: var(--box-shadow);
            padding: var(--card-padding);
            margin-top: 1.6rem;
        }

        .chart-card h3 {
            color: var(--color-dark);
            margin-bottom: 1rem;
        }

        .dashboard-grid {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 1.8rem;
            margin-top: 2rem;
        }

        /* Fix Donut shape */
        #crewChart {
            max-width: 100%;
            height: 320px !important;
            width: 320px !important;
            margin: 0 auto;
        }

        /* Recent Reports Timeline */
        .activity-log {
            background: var(--color-white);
            border-radius: var(--card-border-radius);
            box-shadow: var(--box-shadow);
            padding: var(--card-padding);
        }

        .activity-log ul {
            list-style: none;
            padding: 0;
        }

        .activity-log li {
            border-left: 3px solid var(--color-primary);
            padding-left: 10px;
            margin-bottom: 12px;
            position: relative;
        }

        .activity-log li::before {
            content: '';
            position: absolute;
            left: -7px;
            top: 5px;
            width: 10px;
            height: 10px;
            background: var(--color-primary);
            border-radius: 50%;
        }

        .activity-log strong {
            color: var(--color-dark);
        }

        .activity-log small {
            color: var(--color-info-dark);
            font-size: 0.8rem;
        }

        @media (max-width: 992px) {
            .dashboard-grid {
                grid-template-columns: 1fr;
            }

            #crewChart {
                height: 250px !important;
                width: 250px !important;
            }
        }

        /* Vessel Filter Dropdown */
        .filter-bar {
            background: var(--color-white);
            border-radius: var(--card-border-radius);
            box-shadow: var(--box-shadow);
            padding: 1rem 1.5rem;
            margin-top: 1.5rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
        }

        .filter-bar label {
            font-weight: 600;
            color: var(--color-dark);
        }

        .filter-bar select {
            padding: 0.6rem 1rem;
            border-radius: var(--border-radius-1);
            border: 1px solid var(--color-light);
            background: var(--color-background);
            color: var(--color-dark);
            font-size: 0.9rem;
            cursor: pointer;
            transition: border 0.2s ease, box-shadow 0.2s ease;
        }

        .filter-bar select:hover {
            border-color: var(--color-primary);
        }

        .filter-bar button {
            background: var(--color-primary);
            color: #fff;
            border: none;
            border-radius: var(--border-radius-1);
            padding: 0.6rem 1.2rem;
            cursor: pointer;
            font-weight: 600;
            transition: background 0.3s ease;
        }

        .filter-bar button:hover {
            background: var(--color-success);
        }

        /* Chart fix */
        #crewChart {
            width: 320px !important;
            height: 320px !important;
            margin: 0 auto;
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
                <a href="office-dashboard.php" class="active"><span class="material-icons-sharp">dashboard</span>
                    <h3>Dashboard</h3>
                </a>
                <a href="office-vessel-report.php"><span class="material-icons-sharp">directions_boat</span>
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
        <main class="office-main">
            <h2>Office Dashboard</h2>
            <p>Welcome back, <?= htmlspecialchars($username) ?>! Filter vessel to see detailed analytics.</p>

            <!-- Vessel Filter -->
            <form method="GET" class="filter-bar">
                <label for="vessel_id">Select Vessel:</label>
                <select name="vessel_id" id="vessel_id">
                    <option value="">All Vessels</option>
                    <?php foreach ($vessels as $v): ?>
                        <option value="<?= $v['vessel_id'] ?>" <?= $selectedVessel == $v['vessel_id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($v['vessel_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <button type="submit">Apply</button>
            </form>

            <!-- KPIs -->
            <div class="insights">
                <div>
                    <span class="material-icons-sharp">directions_boat</span>
                    <div>
                        <h3>Active Vessels</h3>
                        <h1><?= $active_vessels ?></h1>
                        <small>Reporting today</small>
                    </div>
                </div>
                <div>
                    <span class="material-icons-sharp">lock</span>
                    <div>
                        <h3>Reports Locked</h3>
                        <h1><?= $locked_reports ?></h1>
                        <small>Finalized today</small>
                    </div>
                </div>
                <div>
                    <span class="material-icons-sharp">groups</span>
                    <div>
                        <h3>Total POB</h3>
                        <h1><?= $total_pob ?></h1>
                        <small>Currently onboard</small>
                    </div>
                </div>
            </div>

            <!-- Charts + Recent Reports -->
            <div class="dashboard-grid">
                <div>
                    <div class="chart-card">
                        <h3>Fuel Consumption (Last 7 Days)</h3>
                        <canvas id="fuelChart"></canvas>
                    </div>

                    <div class="chart-card">
                        <h3>Crew Category Distribution</h3>
                        <canvas id="crewChart"></canvas>
                    </div>
                </div>

                <div class="activity-log">
                    <h3>Recent Reports</h3>
                    <ul>
                        <?php
                        // Recent activity -- use prepared statement
                        $activitiesParams = [];
                        $activitiesSql = "
                            SELECT v.vessel_name, d.log_date, d.status
                            FROM dailystatus d
                            JOIN vessels v ON d.vessel_id = v.vessel_id
                        ";
                        if (!empty($vessel_id)) {
                            $activitiesSql .= " WHERE d.vessel_id = ?";
                            $activitiesParams[] = $vessel_id;
                        }
                        $activitiesSql .= "\n                            ORDER BY d.log_date DESC\n                            LIMIT 6\n                        ";
                        $activitiesStmt = $pdo->prepare($activitiesSql);
                        $activitiesStmt->execute($activitiesParams);
                        $activities = $activitiesStmt->fetchAll();
                        foreach ($activities as $a): ?>
                            <li><strong><?= htmlspecialchars($a['vessel_name']) ?></strong> –
                                <?= htmlspecialchars($a['status']) ?><br>
                                <small><?= htmlspecialchars($a['log_date']) ?></small>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>
        </main>

        <!-- Right Section -->
        <div class="right-section">
            <div class="nav">
                <button id="menu-btn"><span class="material-icons-sharp">menu</span></button>
                <div class="dark-mode"><span class="material-icons-sharp active">light_mode</span><span
                        class="material-icons-sharp">dark_mode</span></div>
                <div class="profile">
                    <div class="info">
                        <p>Hey, <b><?= htmlspecialchars($username) ?></b></p><small
                            class="text-muted"><?= htmlspecialchars($role) ?></small>
                    </div>
                    <div class="profile-photo"><img src="image/blankProf.png" alt="Profile"></div>
                </div>
            </div>
            <div class="user-profile">
                <div class="logo"><img src="image/BSK_LOGO.jpg" alt="Logo">
                    <h2>PortaLog</h2>
                    <p>Office Console</p>
                </div>
            </div>
        </div>
    </div>

    <!-- Charts -->
    <script>
        const fuelCtx = document.getElementById('fuelChart').getContext('2d');
        new Chart(fuelCtx, {
            type: 'line',
            data: {
                labels: <?= json_encode($fuelLabels) ?>,
                datasets: [{
                    label: 'Fuel (m³)',
                    data: <?= json_encode($fuelValues) ?>,
                    borderColor: '#1B9C85',
                    backgroundColor: 'rgba(27,156,133,0.15)',
                    fill: true,
                    tension: 0.4
                }]
            },
            options: { responsive: true, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true } } }
        });

        const crewCtx = document.getElementById('crewChart').getContext('2d');
        new Chart(crewCtx, {
            type: 'doughnut',
            data: {
                labels: <?= json_encode($crewLabels) ?>,
                datasets: [{
                    data: <?= json_encode($crewValues) ?>,
                    backgroundColor: ['#6C9BCF', '#1B9C85', '#FF0060', '#F7D060'],
                    borderWidth: 1
                }]
            },
            options: { responsive: true, cutout: '65%', plugins: { legend: { position: 'bottom' } } }
        });
    </script>
    <script src="index.js"></script>
    <script src="appointment.js"></script>
    <script src="clock.js"></script>
</body>

</html>