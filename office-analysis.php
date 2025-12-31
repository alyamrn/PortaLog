<?php
session_start();
require_once "db_connect.php";

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'OFFICE') {
    header("Location: login.php");
    exit;
}

$username = $_SESSION['full_name'];
$role = $_SESSION['role'];

// === Filters ===
$vessel_id = $_GET['vessel_id'] ?? '';
$start_date = $_GET['start_date'] ?? date('Y-m-01');
$end_date = $_GET['end_date'] ?? date('Y-m-d');

// Get vessels for dropdown
$vessels = $pdo->query("SELECT vessel_id, vessel_name FROM vessels ORDER BY vessel_name")->fetchAll();

// === Your existing “overview” queries (unchanged) ===

// Total Reports
$totalReportsQuery = $pdo->prepare("
    SELECT COUNT(*) FROM dailystatus 
    WHERE (:vessel_id = '' OR vessel_id = :vessel_id)
    AND log_date BETWEEN :start AND :end
");
$totalReportsQuery->execute(['vessel_id' => $vessel_id, 'start' => $start_date, 'end' => $end_date]);
$totalReports = $totalReportsQuery->fetchColumn();

// Locked Reports
$lockedReportsQuery = $pdo->prepare("
    SELECT COUNT(*) FROM dailystatus 
    WHERE status = 'LOCKED' 
    AND (:vessel_id = '' OR vessel_id = :vessel_id)
    AND log_date BETWEEN :start AND :end
");
$lockedReportsQuery->execute(['vessel_id' => $vessel_id, 'start' => $start_date, 'end' => $end_date]);
$lockedReports = $lockedReportsQuery->fetchColumn();

// Avg POB
$avgPOBQuery = $pdo->prepare("
    SELECT AVG(cnt) FROM (
        SELECT COUNT(*) AS cnt 
        FROM pobpersons 
        WHERE (:vessel_id = '' OR vessel_id = :vessel_id)
        AND log_date BETWEEN :start AND :end
        GROUP BY log_date
    ) AS tmp
");
    // Avg POB (derived from active stints per report date)
    // We compute the average distinct persons on board per report date using dailystatus dates
        $avgPOBQuery = $pdo->prepare(
                "SELECT ROUND(AVG(cnt),2) FROM (\n"
                . "    SELECT DATE(d.log_date) AS d, COUNT(DISTINCT s.person_id) AS cnt\n"
                . "    FROM dailystatus d\n"
                . "    LEFT JOIN pob_stints s\n"
                . "      ON s.vessel_id = d.vessel_id\n"
                . "      AND s.embark_date <= d.log_date\n"
                . "      AND (s.disembark_date IS NULL OR s.disembark_date >= d.log_date)\n"
                . "    WHERE (:vessel_id = '' OR d.vessel_id = :vessel_id)\n"
                . "      AND d.log_date BETWEEN :start AND :end\n"
                . "    GROUP BY DATE(d.log_date)\n"
                . ") AS tmp"
        );
    $avgPOBQuery->execute(['vessel_id' => $vessel_id, 'start' => $start_date, 'end' => $end_date]);
    $avgPOB = round((float)$avgPOBQuery->fetchColumn(), 2) ?: 0;

// -------- Vessel Daily Overview Metrics --------
$vesselId = $_SESSION['vessel_id'] ?? null;
if ($vessel_id !== '') {
    $vesselId = $vessel_id;
}

$today = date('Y-m-d');
$since14 = date('Y-m-d', strtotime('-14 days'));
$since30 = date('Y-m-d', strtotime('-30 days'));
$since7 = date('Y-m-d', strtotime('-7 days'));

$openDays = $lockedDays = $reminders14 = $actToday = $pobToday = 0;
$avgAct7 = 0.0;
$fuelUse7 = 0.0;
$garbage7 = 0.0;

$statusLabels = [];
$statusOpen = [];
$statusLocked = [];

if ($vesselId) {
    $dateCursor = new DateTime($since30);
    $endDate = new DateTime($today);
    $perDay = [];
    while ($dateCursor <= $endDate) {
        $d = $dateCursor->format('Y-m-d');
        $perDay[$d] = ['open' => 0, 'locked' => 0];
        $dateCursor->modify('+1 day');
    }

    $q = $pdo->prepare("
        SELECT UPPER(TRIM(status)) AS s, COUNT(*) c
        FROM dailystatus
        WHERE vessel_id = ?
          AND DATE(log_date) BETWEEN ? AND ?
        GROUP BY UPPER(TRIM(status))
    ");
    $q->execute([$vesselId, $since30, $today]);
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
        if ($r['s'] === 'OPEN')
            $openDays = (int) $r['c'];
        if ($r['s'] === 'LOCKED')
            $lockedDays = (int) $r['c'];
    }

    $q = $pdo->prepare("
        SELECT DATE(log_date) AS d,
               SUM(CASE WHEN UPPER(TRIM(status))='OPEN'   THEN 1 ELSE 0 END) AS open_count,
               SUM(CASE WHEN UPPER(TRIM(status))='LOCKED' THEN 1 ELSE 0 END) AS locked_count
        FROM dailystatus
        WHERE vessel_id = ?
          AND DATE(log_date) BETWEEN ? AND ?
        GROUP BY DATE(log_date)
        ORDER BY DATE(log_date)
    ");
    $q->execute([$vesselId, $since30, $today]);
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $d = $r['d'];
        if (isset($perDay[$d])) {
            $perDay[$d]['open'] = (int) $r['open_count'];
            $perDay[$d]['locked'] = (int) $r['locked_count'];
        }
    }
    foreach ($perDay as $d => $counts) {
        $statusLabels[] = $d;
        $statusOpen[] = $counts['open'];
        $statusLocked[] = $counts['locked'];
    }

    $q = $pdo->prepare("
        SELECT COUNT(*) FROM reminders
        WHERE vessel_id = ? 
          AND DATE(log_date) BETWEEN ? AND ?
          AND UPPER(TRIM(modules_missing)) <> 'UNLOCK REQUEST'
    ");
    $q->execute([$vesselId, $since14, $today]);
    $reminders14 = (int) $q->fetchColumn();

    $q = $pdo->prepare("SELECT COUNT(*) FROM activitylogs WHERE vessel_id = ? AND DATE(log_date) = ?");
    $q->execute([$vesselId, $today]);
    $actToday = (int) $q->fetchColumn();

    $q = $pdo->prepare("
        SELECT AVG(NULLIF(duration_min,0)) 
        FROM activitylogs 
        WHERE vessel_id = ? AND DATE(log_date) BETWEEN ? AND ?
    ");
    $q->execute([$vesselId, $since7, $today]);
    $avgAct7 = (float) ($q->fetchColumn() ?: 0);

    $q = $pdo->prepare("SELECT COUNT(DISTINCT person_id) FROM pob_stints WHERE vessel_id = ? AND embark_date <= ? AND (disembark_date IS NULL OR disembark_date >= ?)");
    $q->execute([$vesselId, $today, $today]);
    $pobToday = (int) $q->fetchColumn();

    $q = $pdo->prepare("
        SELECT SUM(
            GREATEST(
                COALESCE(previous_rob,0) + COALESCE(loaded_today,0) - COALESCE(current_rob,0), 0
            )
        )
        FROM rob_records
        WHERE vessel_id = ?
          AND DATE(log_date) BETWEEN ? AND ?
          AND UPPER(TRIM(category)) = 'LIQUID'
    ");
    $q->execute([$vesselId, $since7, $today]);
    $fuelUse7 = (float) ($q->fetchColumn() ?: 0);

    $q = $pdo->prepare("
        SELECT SUM(COALESCE(qty_m3,0))
        FROM garbagelogs
        WHERE vessel_id = ?
          AND DATE(log_date) BETWEEN ? AND ?
    ");
    $q->execute([$vesselId, $since7, $today]);
    $garbage7 = (float) ($q->fetchColumn() ?: 0);
} else {
    $dateCursor = new DateTime($since30);
    $endDate = new DateTime($today);
    $perDay = [];
    while ($dateCursor <= $endDate) {
        $d = $dateCursor->format('Y-m-d');
        $perDay[$d] = ['open' => 0, 'locked' => 0];
        $dateCursor->modify('+1 day');
    }

    $q = $pdo->prepare("
        SELECT UPPER(TRIM(status)) AS s, COUNT(*) c
        FROM dailystatus
        WHERE DATE(log_date) BETWEEN ? AND ?
        GROUP BY UPPER(TRIM(status))
    ");
    $q->execute([$since30, $today]);
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
        if ($r['s'] === 'OPEN')
            $openDays = (int) $r['c'];
        if ($r['s'] === 'LOCKED')
            $lockedDays = (int) $r['c'];
    }

    $q = $pdo->prepare("
        SELECT DATE(log_date) AS d,
               SUM(CASE WHEN UPPER(TRIM(status))='OPEN'   THEN 1 ELSE 0 END) AS open_count,
               SUM(CASE WHEN UPPER(TRIM(status))='LOCKED' THEN 1 ELSE 0 END) AS locked_count
        FROM dailystatus
        WHERE DATE(log_date) BETWEEN ? AND ?
        GROUP BY DATE(log_date)
        ORDER BY DATE(log_date)
    ");
    $q->execute([$since30, $today]);
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $d = $r['d'];
        if (isset($perDay[$d])) {
            $perDay[$d]['open'] = (int) $r['open_count'];
            $perDay[$d]['locked'] = (int) $r['locked_count'];
        }
    }
    foreach ($perDay as $d => $counts) {
        $statusLabels[] = $d;
        $statusOpen[] = $counts['open'];
        $statusLocked[] = $counts['locked'];
    }

    $q = $pdo->prepare("
        SELECT COUNT(*) FROM reminders
        WHERE DATE(log_date) BETWEEN ? AND ?
          AND UPPER(TRIM(modules_missing)) <> 'UNLOCK REQUEST'
    ");
    $q->execute([$since14, $today]);
    $reminders14 = (int) $q->fetchColumn();

    $q = $pdo->prepare("SELECT COUNT(*) FROM activitylogs WHERE DATE(log_date) = ?");
    $q->execute([$today]);
    $actToday = (int) $q->fetchColumn();

    $q = $pdo->prepare("
        SELECT AVG(NULLIF(duration_min,0)) 
        FROM activitylogs 
        WHERE DATE(log_date) BETWEEN ? AND ?
    ");
    $q->execute([$since7, $today]);
    $avgAct7 = (float) ($q->fetchColumn() ?: 0);

    $q = $pdo->prepare("SELECT COUNT(DISTINCT person_id) FROM pob_stints WHERE embark_date <= ? AND (disembark_date IS NULL OR disembark_date >= ?)");
    $q->execute([$today, $today]);
    $pobToday = (int) $q->fetchColumn();

    $q = $pdo->prepare("
        SELECT SUM(
            GREATEST(
                COALESCE(previous_rob,0) + COALESCE(loaded_today,0) - COALESCE(current_rob,0), 0
            )
        )
        FROM rob_records
        WHERE DATE(log_date) BETWEEN ? AND ?
          AND UPPER(TRIM(category)) = 'LIQUID'
    ");
    $q->execute([$since7, $today]);
    $fuelUse7 = (float) ($q->fetchColumn() ?: 0);

    $q = $pdo->prepare("
        SELECT SUM(COALESCE(qty_m3,0))
        FROM garbagelogs
        WHERE DATE(log_date) BETWEEN ? AND ?
    ");
    $q->execute([$since7, $today]);
    $garbage7 = (float) ($q->fetchColumn() ?: 0);
}

$totalDays = $openDays + $lockedDays;
$lockRate = $totalDays > 0 ? round(($lockedDays / $totalDays) * 100) : 0;
$openRate = $totalDays > 0 ? 100 - $lockRate : 0;
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
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
            background: var(--accent);
            color: #fff;
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




        /* ——— your existing styles kept ——— */
        .tabs {
            display: flex;
            gap: 8px;
            margin: 16px 0;
            flex-wrap: wrap
        }

        .tab-btn {
            padding: .5rem 1rem;
            border: 1px solid var(--line);
            background: var(--color-primary);
            border-radius: 8px;
            cursor: pointer
        }

        .tab-btn.active {
            background: var(--color-success);
            color: #fff;
            border-color: var(--accent)
        }

        .tab-panel {
            display: none
        }

        .tab-panel.active {
            display: block
        }

        /* GRID: allow fewer, wider cards per row so nothing is cramped */
        .grid-2 {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(420px, 1fr));
            /* NEW: was 360px */
            gap: 24px;
            /* NEW: more space between cards */
        }

        /* Card visuals */
        .canvas-card {
            background: var(--color-white);
            border-radius: 18px;
            box-shadow: var(--box-shadow);
            padding: 16px 18px 18px 18px;
        }

        .canvas-card h4 {
            margin: 0 0 10px 0
        }

        /* IMPORTANT: give the canvas a real drawing height; don't stretch */
        .canvas-card canvas.chart {
            /* NEW */
            display: block;
            width: 100% !important;
            height: 360px !important;
            /* NEW: compact, crisp */
            max-width: 560px;
            /* NEW: keep chart smaller inside card */
            margin: 6px auto 0;
            /* NEW: center it */
        }

        .canvas-card canvas.chart.big {
            /* NEW: taller variant */
            height: 420px !important;
        }

        /* Remove stretching min-heights that caused squish */
        .canvas-card.big {
            min-height: unset;
        }

        /* NEW */
        .big {
            min-height: unset;
        }

        /* keep other elements free */

        @media (max-width: 900px) {
            .grid-2 {
                grid-template-columns: 1fr;
            }
        }

        /* (rest of your existing styles unchanged) */
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
            transition: .3s;
            text-align: center
        }

        .analytics-cards .card:hover {
            box-shadow: none
        }

        .analytics-cards h3 {
            color: var(--color-dark-variant)
        }

        .analytics-cards h1 {
            color: var(--color-dark);
            margin-top: 5px
        }

        .overview-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 16px;
            margin-top: 10px
        }

        .stat-card {
            background: var(--color-white);
            border-radius: var(--card-border-radius);
            box-shadow: var(--box-shadow);
            padding: 16px;
            display: flex;
            flex-direction: column;
            gap: 8px
        }

        .stat-title {
            font-size: .95rem;
            color: var(--color-dark-variant);
            font-weight: 600
        }

        .stat-kpi {
            font-size: 1.8rem;
            font-weight: 800;
            color: var(--ink, var(--color-dark));
            line-height: 1.2
        }

        .stat-sub {
            color: var(--color-dark-variant);
            font-size: .85rem
        }

        .stat-main {
            display: flex;
            gap: 8px;
            flex-wrap: wrap
        }

        .stat-pill {
            padding: 6px 10px;
            border-radius: 999px;
            font-weight: 700;
            font-size: .85rem
        }

        .stat-pill.open {
            background: #e7f6ec;
            color: #2e7d32
        }

        .stat-pill.locked {
            background: #fde7ec;
            color: #c62828
        }

        .progress {
            width: 100%;
            height: 8px;
            background: var(--color-light);
            border-radius: 999px;
            overflow: hidden
        }

        .progress .bar.locked {
            height: 100%;
            background: #c62828
        }
    </style>
</head>

<body>
    <div class="container">
        <aside>
            <div class="toggle">
                <div class="logo"><img src="image/BSK_LOGO.jpg" alt="Logo">
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
                <a href="office-analysis.php" class="active"><span class="material-icons-sharp">bar_chart</span>
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

        <main>
            <h2>📈 Data Analytics</h2>

            <!-- Filters (kept) -->
            <div class="filter-section">
                <form method="GET" id="filtersForm">
                    <div class="filter-group">
                        <label><b>Vessel:</b></label>
                        <select name="vessel_id" id="vessel_id">
                            <option value="">All Vessels</option>
                            <?php foreach ($vessels as $v): ?>
                                <option value="<?= $v['vessel_id'] ?>" <?= $vessel_id == $v['vessel_id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($v['vessel_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <label><b>From:</b></label>
                        <input type="date" name="start_date" id="start_date" value="<?= $start_date ?>">
                        <label><b>To:</b></label>
                        <input type="date" name="end_date" id="end_date" value="<?= $end_date ?>">
                    </div>
                    <div style="margin-left:auto;">
                        <button type="submit">🔍 Apply Filter</button>
                    </div>
                </form>
            </div>

            <!-- Tabs -->
            <div class="tabs">
                <button type="button" class="tab-btn active" data-tab="tab-overview">1) Vessel Daily Overview</button>
                <button type="button" class="tab-btn" data-tab="tab-pob">2) POB Insights</button>
                <button type="button" class="tab-btn" data-tab="tab-engine">3) Engine & Power</button>
                <button type="button" class="tab-btn" data-tab="tab-fuel">4) Fuel & Liquid</button>
                <button type="button" class="tab-btn" data-tab="tab-garbage">5) Garbage</button>
                <button type="button" class="tab-btn" data-tab="tab-nav">6) Navigation</button>
                <button type="button" class="tab-btn" data-tab="tab-oil">7) Oil & Maintenance</button>
                <button type="button" class="tab-btn" data-tab="tab-users">8) Users & Reminders</button>
            </div>

            <!-- Tab 1 -->
            <section id="tab-overview" class="tab-panel active">
                <div class="analytics-cards">
                    <div class="card">
                        <h3>Total Reports</h3>
                        <h1><?= $totalReports ?></h1>
                    </div>
                    <div class="card">
                        <h3>Locked Reports</h3>
                        <h1><?= $lockedReports ?></h1>
                    </div>
                    <div class="card">
                        <h3>Average POB</h3>
                        <h1><?= $avgPOB ?></h1>
                    </div>
                    <div class="card">
                        <h3>Period</h3>
                        <h1><?= date('M d', strtotime($start_date)) ?> - <?= date('M d', strtotime($end_date)) ?></h1>
                    </div>
                </div>

                <div class="overview-grid" style="margin-top:16px">
                    <div class="stat-card">
                        <div class="stat-title">Open vs Locked (30d)</div>
                        <div class="stat-main">
                            <div class="stat-pill open">Open: <?= $openDays ?></div>
                            <div class="stat-pill locked">Locked: <?= $lockedDays ?></div>
                        </div>
                        <div class="stat-sub">Lock rate: <b><?= $lockRate ?>%</b></div>
                        <div class="progress">
                            <div class="bar locked" style="width: <?= $lockRate ?>%;"></div>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-title">Reminders (14d)</div>
                        <div class="stat-kpi"><?= $reminders14 ?></div>
                        <div class="stat-sub">Missing forms/alerts issued</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-title">Activities Today</div>
                        <div class="stat-kpi"><?= $actToday ?></div>
                        <div class="stat-sub">Entries on <?= htmlspecialchars($today) ?></div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-title">Avg Activity Duration (7d)</div>
                        <div class="stat-kpi"><?= number_format($avgAct7, 1) ?> min</div>
                        <div class="stat-sub">Per activity</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-title">POB Today</div>
                        <div class="stat-kpi"><?= $pobToday ?></div>
                        <div class="stat-sub">Persons on board</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-title">Fuel Used (7d)</div>
                        <div class="stat-kpi"><?= number_format($fuelUse7, 1) ?></div>
                        <div class="stat-sub">Rob delta (liquid)</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-title">Garbage (7d)</div>
                        <div class="stat-kpi"><?= number_format($garbage7, 2) ?> m³</div>
                        <div class="stat-sub">Disposed/recorded</div>
                    </div>
                    <div class="stat-card" style="grid-column:1/-1">
                        <div class="stat-title">Open vs Locked by Day (Last 30 Days)</div>
                        <canvas id="statusChart" class="chart" style="width:100%;max-height:360px;"></canvas>
                        <!-- NEW: class="chart" -->
                    </div>
                </div>
            </section>

            <!-- Tab 2 -->
            <section id="tab-pob" class="tab-panel">
                <div class="grid-2">
                    <div class="canvas-card">
                        <h4>👨‍✈️ Crew vs 👷 Contractor (Category Share)</h4><canvas id="pobCategoryPie"
                            class="big chart"></canvas>
                    </div> <!-- NEW class -->
                    <div class="canvas-card">
                        <h4>🚢 Average Total POB per Day</h4><canvas id="pobDailyLine" class="big chart"></canvas>
                    </div> <!-- NEW class -->
                    <div class="canvas-card">
                        <h4>🔁 Crew Turnover (Embark/Disembark per Week)</h4><canvas id="pobTurnoverCol"
                            class="big chart"></canvas>
                    </div> <!-- NEW class -->
                    <div class="canvas-card">
                        <h4>🌍 Top 5 Nationalities</h4><canvas id="pobNationalityBar" class="big chart"></canvas>
                    </div> <!-- NEW class -->
                </div>
            </section>

            <!-- Tab 3 -->
            <section id="tab-engine" class="tab-panel">
                <div class="grid-2">
                    <div class="canvas-card">
                        <h4>⚡ Generator Utilization (Avg Running Hours / Day)</h4><canvas id="engUtilMulti"
                            class="big chart"></canvas>
                    </div> <!-- NEW class -->
                    <div class="canvas-card">
                        <h4>⏳ Total Running Time Today</h4>
                        <div id="engTotalToday" style="font-size:42px;font-weight:800;padding:24px">—</div>
                    </div>
                    <div class="canvas-card">
                        <h4>⚙️ Idle vs Active Machines</h4><canvas id="engIdleActiveGauge" class="big chart"></canvas>
                    </div> <!-- NEW class -->
                </div>
            </section>

            <!-- Tab 4 -->
            <section id="tab-fuel" class="tab-panel">
                <div class="grid-2">
                    <div class="canvas-card">
                        <h4>⛽ Fuel Usage per Day</h4><canvas id="fuelDailyBar" class="big chart"></canvas>
                    </div> <!-- NEW class -->
                    <div class="canvas-card">
                        <h4>💧 Fresh Water Usage</h4><canvas id="waterDailyLine" class="big chart"></canvas>
                    </div> <!-- NEW class -->
                    <div class="canvas-card">
                        <h4>📉 Top 5 Liquids Consumed</h4><canvas id="topLiquidsBar" class="big chart"></canvas>
                    </div> <!-- NEW class -->
                    <div class="canvas-card">
                        <h4>⚖️ Stock Capacity Utilization</h4><canvas id="stockUtilDonut" class="big chart"></canvas>
                    </div> <!-- NEW class -->
                </div>
            </section>

            <!-- Tab 5 -->
            <section id="tab-garbage" class="tab-panel">
                <div class="grid-2">
                    <div class="canvas-card">
                        <h4>🗑 Total Garbage by Method</h4><canvas id="garbageMethodPie" class="big chart"></canvas>
                    </div> <!-- NEW class -->
                    <div class="canvas-card">
                        <h4>🚢 Garbage Type Distribution</h4><canvas id="garbageTypeBar" class="big chart"></canvas>
                    </div> <!-- NEW class -->
                    <div class="canvas-card" style="grid-column:1/-1">
                        <h4>📆 Weekly Waste Trend</h4><canvas id="garbageWeeklyLine" class="big chart"></canvas>
                    </div> <!-- NEW class -->
                </div>
            </section>

            <!-- Tab 6 -->
            <section id="tab-nav" class="tab-panel">
                <div class="grid-2">
                    <div class="canvas-card">
                        <h4>📍 Navigation Entries per Day</h4><canvas id="navDailyLine" class="big chart"></canvas>
                    </div> <!-- NEW class -->
                    <div class="canvas-card">
                        <h4>🌤 Weather Frequency</h4><canvas id="navWeatherPie" class="big chart"></canvas>
                    </div> <!-- NEW class -->
                    <div class="canvas-card">
                        <h4>⚡ Average Vessel Speed</h4><canvas id="navAvgSpeedLine" class="big chart"></canvas>
                    </div> <!-- NEW class -->
                    <div class="canvas-card">
                        <h4>🧭 Top 5 Destinations</h4><canvas id="navTopDestBar" class="big chart"></canvas>
                    </div> <!-- NEW class -->
                </div>
            </section>

            <!-- Tab 7 -->
            <section id="tab-oil" class="tab-panel">
                <div class="grid-2">
                    <div class="canvas-card">
                        <h4>🛠 Common Oil Operations</h4><canvas id="oilOpsPie" class="big chart"></canvas>
                    </div> <!-- NEW class -->
                    <div class="canvas-card">
                        <h4>💡 Oil Quantity by Operation</h4><canvas id="oilQtyCol" class="big chart"></canvas>
                    </div> <!-- NEW class -->
                    <div class="canvas-card" style="grid-column:1/-1">
                        <h4>🧾 Maintenance Trend (Weekly)</h4><canvas id="maintTrendLine" class="big chart"></canvas>
                    </div> <!-- NEW class -->
                </div>
            </section>

            <!-- Tab 8 -->
            <section id="tab-users" class="tab-panel">
                <div class="grid-2">
                    <div class="canvas-card">
                        <h4>👥 Active Users by Role</h4><canvas id="usersRoleBar" class="big chart"></canvas>
                    </div> <!-- NEW class -->
                    <div class="canvas-card">
                        <h4>📨 Reminder History (per Month)</h4><canvas id="remindersMonthLine"
                            class="big chart"></canvas>
                    </div> <!-- NEW class -->
                    <div class="canvas-card">
                        <h4>🔒 Most Active Vessels (Logs)</h4><canvas id="activeVesselsBar" class="big chart"></canvas>
                    </div> <!-- NEW class -->
                    <div class="canvas-card">
                        <h4>⚙️ Top Logged Users (CREATE)</h4><canvas id="topUsersBar" class="big chart"></canvas>
                    </div> <!-- NEW class -->
                </div>
            </section>
        </main>

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

    <script src="index.js"></script>
    <script src="appointment.js"></script>
    <script src="clock.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

    <!-- NEW: Global Chart.js defaults for crisp, non-penyek charts -->
    <script>
        if (window.Chart) {
            const DPR = window.devicePixelRatio || 1;
            Chart.defaults.responsive = true;
            Chart.defaults.maintainAspectRatio = false;   // canvas height drives layout
            Chart.defaults.devicePixelRatio = DPR;        // sharp on retina/HiDPI
            Chart.defaults.color = '#cfd8dc';
            Chart.defaults.font = { family: "'Inter', system-ui, -apple-system, Segoe UI, Roboto, 'Helvetica Neue', Arial, sans-serif", size: 12 };
            Chart.defaults.layout = { padding: 8 };
            Chart.defaults.plugins.legend.position = 'bottom';
            Chart.defaults.plugins.legend.labels.boxWidth = 14;
            Chart.defaults.plugins.tooltip.mode = 'index';
            Chart.defaults.plugins.tooltip.intersect = false;
            Chart.defaults.elements.line.borderWidth = 2;
            Chart.defaults.elements.line.tension = 0.35;
            Chart.defaults.elements.point.radius = 3;
            Chart.defaults.elements.bar.borderRadius = 8;
            const gridColor = 'rgba(255,255,255,0.08)';
            const tickColor = '#90a4ae';
            ['x', 'y'].forEach(ax => {
                Chart.defaults.scales[ax] = Chart.defaults.scales[ax] || {};
                Chart.defaults.scales[ax].grid = { color: gridColor, drawBorder: false };
                Chart.defaults.scales[ax].ticks = { color: tickColor, maxTicksLimit: 7 };
            });
        }
    </script>

    <script>
        // Overview chart init (kept)
        (function () {
            const el = document.getElementById('statusChart');
            if (!el) return;
            const ctxStatus = el.getContext('2d');
            new Chart(ctxStatus, {
                type: 'bar',
                data: {
                    labels: <?= json_encode($statusLabels) ?>,
                    datasets: [
                        { label: 'Open', data: <?= json_encode($statusOpen) ?>, backgroundColor: 'rgba(46,125,50,0.7)' },
                        { label: 'Locked', data: <?= json_encode($statusLocked) ?>, backgroundColor: 'rgba(198,40,40,0.7)' }
                    ]
                },
                options: {
                    responsive: true,
                    plugins: { legend: { position: 'bottom' } },
                    datasets: { bar: { categoryPercentage: 0.7, barPercentage: 0.85 } }, /* slimmer bars */
                    scales: { x: { title: { display: true, text: 'Date' } }, y: { beginAtZero: true, title: { display: true, text: 'Count' } } }
                }
            });
        })();
    </script>

    <!-- NEW: Analysis bootstrap -->
    <script>
        window.ANALYTICS_FILTERS = {
            vessel_id: document.getElementById('vessel_id').value,
            start_date: document.getElementById('start_date').value,
            end_date: document.getElementById('end_date').value
        };
    </script>

    <!-- Auto-submit filters when vessel select changes; support clickable vessel links -->
    <script>
        (function(){
            var vesselSelect = document.getElementById('vessel_id');
            var form = document.getElementById('filtersForm');
            if (vesselSelect && form) {
                vesselSelect.addEventListener('change', function(){
                    // submit form immediately when vessel changed
                    form.submit();
                });
            }

            // If there are any clickable vessel elements elsewhere, mark them with data-vessel-id
            // e.g. <a href="#" data-vessel-id="123" class="vessel-link">Vessel Name</a>
            document.addEventListener('click', function(e){
                var t = e.target.closest && e.target.closest('[data-vessel-id]');
                if (!t) return;
                var id = t.getAttribute('data-vessel-id');
                if (!id) return;
                if (vesselSelect) {
                    vesselSelect.value = id;
                    form.submit();
                    e.preventDefault();
                }
            });
        })();
    </script>

    <script>
        // Simple, defensive tab switcher (kept)
        (function () {
            var btns = document.querySelectorAll('.tab-btn');
            var panels = document.querySelectorAll('.tab-panel');
            if (!btns.length || !panels.length) return;

            btns.forEach(function (b) {
                b.addEventListener('click', function (e) {
                    e.preventDefault();
                    btns.forEach(function (x) { x.classList.remove('active'); });
                    panels.forEach(function (p) { p.classList.remove('active'); });
                    b.classList.add('active');
                    var id = b.getAttribute('data-tab');
                    var panel = document.getElementById(id);
                    if (panel) panel.classList.add('active');
                    // ensure charts reflow sharply when showing a new tab
                    setTimeout(function () {
                        if (window.charts) Object.values(window.charts).forEach(function (ch) { try { ch.resize(); } catch (e) { } });
                    }, 0);
                });
            });
        })();
    </script>

    <script src="assets/js/analysis.js"></script>
</body>

</html>