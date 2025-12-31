<?php
session_start();
require_once "db_connect.php";

// ✅ Ensure login
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

// --- Session variables ---
$vessel_id = $_SESSION['vessel_id'];
$username = $_SESSION['full_name'];
$role = $_SESSION['role'];

// ===== Fetch reminders for this vessel =====
$reminderQuery = $pdo->prepare("
    SELECT modules_missing, log_date, message, sent_by, sent_to_email, created_at
    FROM reminders 
    WHERE vessel_id = ? 
    ORDER BY created_at DESC 
    LIMIT 5
");
$reminderQuery->execute([$vessel_id]);
$vesselReminders = $reminderQuery->fetchAll(PDO::FETCH_ASSOC);


// --- Fetch all report dates ---
$stmt = $pdo->prepare("
    SELECT log_date, status 
    FROM dailystatus 
    WHERE vessel_id = ?
    ORDER BY log_date DESC
");
$stmt->execute([$vessel_id]);
$reports = $stmt->fetchAll(PDO::FETCH_ASSOC);

// --- Function to calculate completion & missing forms ---
function getReportStatus($pdo, $vessel_id, $log_date)
{
    $tables = [
        'activitylogs' => 'Activity',
        // POB: treat as present when any active stint exists on that date
        'pob_stints' => 'POB',
        'garbagelogs' => 'Garbage',
        'navigationreports' => 'Navigation',
        'oilrecordbook' => 'Oil Record',
        'rob_records' => 'ROB',
        'runninghours' => 'Running Hours'
    ];

    $completed = 0;
    $missing = [];

    foreach ($tables as $table => $label) {
        if ($table === 'pob_stints') {
            // check active stints as-of $log_date
            $q = $pdo->prepare("SELECT COUNT(*) FROM pob_stints WHERE vessel_id=? AND embark_date <= ? AND (disembark_date IS NULL OR disembark_date >= ?)");
            $q->execute([$vessel_id, $log_date, $log_date]);
            $count = $q->fetchColumn();
        } else {
            $q = $pdo->prepare("SELECT COUNT(*) FROM $table WHERE vessel_id=? AND log_date=?");
            $q->execute([$vessel_id, $log_date]);
            $count = $q->fetchColumn();
        }
        if ($count > 0)
            $completed++;
        else
            $missing[] = $label;
    }

    $percentage = round(($completed / count($tables)) * 100);
    return ['percent' => $percentage, 'missing' => $missing];
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Report History</title>
    <link href="https://fonts.googleapis.com/css2?family=Material+Icons+Sharp" rel="stylesheet">
    <link rel="stylesheet" href="style.css">
    <style>
        /* Dropdown base */
        .dropdown .dropdown-menu {
            display: none;
            flex-direction: column;
            margin-left: 32px;
            /* indent child links */
        }

        .dropdown .dropdown-menu a {
            padding: 8px 0;
            font-size: 0.9rem;
            color: var(--color-dark);
        }

        .dropdown .dropdown-menu a:hover {
            color: var(--color-primary);
        }

        /* Expanded state */
        .dropdown.active .dropdown-menu {
            display: flex;
        }

        /* Rotate arrow when open */
        .dropdown .arrow {
            margin-left: auto;
            transition: transform 0.3s ease;
        }

        .dropdown.active .arrow {
            transform: rotate(180deg);
        }

        main {
            padding: 1.8rem;
        }

        h2 {
            margin-bottom: 1rem;
        }

        .report-section {
            margin-top: 2rem;
        }

        .report-section h3 {
            color: var(--color-dark);
            margin-bottom: 1rem;
            font-weight: 700;
        }

        /* === Scrollable Wrapper === */
        .report-scroll {
            max-height: 480px;
            /* adjustable scroll height */
            overflow-y: auto;
            padding-right: 6px;
            scrollbar-width: thin;
            scrollbar-color: var(--color-primary) var(--color-light);
            border-radius: var(--border-radius-2);
            box-shadow: inset 0 0 4px rgba(0, 0, 0, 0.08);
        }

        .report-scroll::-webkit-scrollbar {
            width: 8px;
        }

        .report-scroll::-webkit-scrollbar-track {
            background: var(--color-light);
            border-radius: 6px;
        }

        .report-scroll::-webkit-scrollbar-thumb {
            background: var(--color-primary);
            border-radius: 6px;
        }

        .report-scroll::-webkit-scrollbar-thumb:hover {
            background: var(--color-success);
        }

        .report-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
            gap: 1.4rem;
            padding-bottom: 1rem;
        }

        .report-card {
            background: var(--color-white);
            border-radius: var(--card-border-radius);
            padding: 1.2rem 1.4rem;
            box-shadow: var(--box-shadow);
            transition: all 0.3s ease;
            position: relative;
        }

        .report-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 1.2rem 2.4rem rgba(0, 0, 0, .1);
        }

        .report-card h4 {
            margin: .4rem 0;
            color: var(--color-dark);
            font-weight: 700;
        }

        .report-card p {
            font-size: .9rem;
            margin: .2rem 0;
            color: var(--color-dark-variant);
        }

        .status-tag {
            position: absolute;
            top: 12px;
            right: 12px;
            font-size: .8rem;
            font-weight: 600;
            color: #fff;
            padding: 4px 8px;
            border-radius: var(--border-radius-1);
        }

        .open .status-tag {
            background: var(--color-success);
        }

        .locked .status-tag {
            background: var(--color-primary);
        }

        .progress-container {
            background: var(--color-light);
            border-radius: 10px;
            height: 8px;
            overflow: hidden;
            margin: .6rem 0 .8rem;
        }

        .progress-fill {
            height: 100%;
            width: 0;
            transition: width 0.8s ease;
            background: linear-gradient(90deg, var(--color-success), var(--color-primary));
        }

        .missing {
            background: var(--color-light);
            border-radius: var(--border-radius-1);
            padding: .4rem .6rem;
            font-size: .85rem;
            margin-top: .4rem;
            line-height: 1.4;
        }

        .missing span {
            display: inline-block;
            margin: 2px 4px 0 0;
            padding: 4px 8px;
            border-radius: var(--border-radius-1);
            background: rgba(108, 155, 207, .25);
            color: var(--color-dark);
            font-weight: 600;
            font-size: .8rem;
            border: 1px solid rgba(108, 155, 207, .4);
            transition: background .2s ease;
        }

        .missing span:hover {
            background: var(--color-primary);
            color: #fff;
        }

        .btn-view {
            display: inline-block;
            background: var(--color-primary);
            color: #fff;
            padding: .5rem .9rem;
            border-radius: var(--border-radius-1);
            text-decoration: none;
            font-weight: 600;
            margin-top: .7rem;
            transition: background .25s ease;
        }

        .btn-view:hover {
            background: var(--color-success);
        }

        .locked .btn-view {
            background: var(--color-dark-variant);
        }

        .locked .btn-view:hover {
            background: var(--color-info-dark);
        }

        /* === Filter Bar === */
        .filter-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: var(--color-white);
            border-radius: var(--border-radius-2);
            padding: .8rem 1.2rem;
            box-shadow: var(--box-shadow);
            margin-bottom: 1.2rem;
            flex-wrap: wrap
        }

        .filter-form {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 1rem;
            width: 100%;
            justify-content: space-between
        }

        .filter-group {
            display: flex;
            align-items: center;
            gap: .5rem;
            flex-wrap: wrap
        }

        .filter-bar label {
            font-weight: 600;
            color: var(--color-dark);
            font-size: .9rem
        }

        .filter-bar select,
        .filter-bar input[type="date"] {
            padding: .45rem .7rem;
            border: 1px solid var(--line);
            border-radius: var(--border-radius-1);
            background: var(--color-background);
            color: var(--color-dark);
            font-size: .9rem
        }

        /* Target the calendar icon on Chrome / Edge / Safari */
        input[type="date"]::-webkit-calendar-picker-indicator {
            filter: invert(60%) sepia(60%) saturate(400%) hue-rotate(180deg);
            /* tweak hue-rotate to get your desired color */
            cursor: pointer;
        }

        /* optional hover effect */
        input[type="date"]::-webkit-calendar-picker-indicator:hover {
            filter: invert(70%) sepia(90%) saturate(600%) hue-rotate(120deg);
        }


        .btn-filter {
            padding: .55rem 1.1rem;
            border: none;
            border-radius: var(--border-radius-1);
            background: var(--color-primary);
            color: #fff;
            font-weight: 600;
            cursor: pointer
        }

        .btn-filter:hover {
            background: var(--color-success);
            transform: translateY(-2px)
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
                <a href="dashboard.php"><span class="material-icons-sharp">dashboard</span>
                    <h3>Dashboard</h3>
                </a>
                <div class="dropdown">
                    <a href="#" class="dropdown-toggle">
                        <span class="material-icons-sharp">analytics</span>
                        <h3>Daily Reports</h3>
                        <span class="material-icons-sharp arrow">expand_more</span>
                    </a>
                    <div class="dropdown-menu">
                        <a href="daily-report.php">Activity Log</a>
                        <a href="pob-report.php">POB</a>
                        <a href="navigation-report.php">Navigation</a>
                        <a href="engine-report.php">Engine Hours & ROB</a>
                        <a href="garbage-report.php">Garbage</a>
                        <a href="oil-report.php">Oil Record</a>
                        <a href="#"> </a>
                    </div>
                </div>
                <a href="captain-reminders.php"><span class="material-icons-sharp">schedule</span>
                    <h3>Reminders</h3>
                </a>
                <a href="history.php" class="active"><span class="material-icons-sharp">history_edu</span>
                    <h3>History</h3>
                </a>
                <a href="help.php"><span class="material-icons-sharp">help</span>
                    <h3>Help</h3>
                </a>
                <a href="login.php" class="logout"><span class="material-icons-sharp">logout</span>
                    <h3>Log Out</h3>
                </a>
            </div>
        </aside>

        <!-- Main -->
        <main>
            <h2>📖 Report History</h2>

            <!-- Filter -->
            <div class="filter-bar">
                <form method="GET" id="filterForm" class="filter-form">
                    <div class="filter-group">
                        <label for="dateFilter">Filter by:</label>
                        <select name="filter" id="dateFilter">
                            <option value="all" <?= (!isset($_GET['filter']) || $_GET['filter'] == 'all') ? 'selected' : '' ?>>Show All</option>
                            <option value="7days" <?= ($_GET['filter'] ?? '') == '7days' ? 'selected' : '' ?>>Last 7 Days
                            </option>
                            <option value="30days" <?= ($_GET['filter'] ?? '') == '30days' ? 'selected' : '' ?>>Last 30
                                Days</option>
                            <option value="custom" <?= ($_GET['filter'] ?? '') == 'custom' ? 'selected' : '' ?>>Custom
                                Range</option>
                        </select>
                        <div class="custom-date" id="customDate" style="display:none;">
                            <label>From:</label><input type="date" name="start" value="<?= $_GET['start'] ?? '' ?>">
                            <label>To:</label><input type="date" name="end" value="<?= $_GET['end'] ?? '' ?>">
                        </div>
                    </div>
                    <div class="filter-group">
                        <label for="searchDate">Search Date:</label>
                        <input type="date" name="searchDate" id="searchDate" value="<?= $_GET['searchDate'] ?? '' ?>">
                    </div>
                    <button type="submit" class="btn-filter">🔍 Apply</button>
                </form>
            </div>

            <!-- OPEN REPORTS -->
            <section class="report-section">
                <h3>🟢 Open Reports</h3>
                <div class="report-scroll">
                    <div class="report-grid">
                        <?php
                        $hasOpen = false;
                        foreach ($reports as $r):
                            if ($r['status'] === 'OPEN'):
                                $hasOpen = true;
                                $statusInfo = getReportStatus($pdo, $vessel_id, $r['log_date']);
                                ?>
                                <div class="report-card open">
                                    <span class="status-tag">OPEN</span>
                                    <h4><?= htmlspecialchars($r['log_date']) ?></h4>
                                    <div class="progress-container">
                                        <div class="progress-fill" style="width:<?= $statusInfo['percent'] ?>%;"></div>
                                    </div>
                                    <p><?= $statusInfo['percent'] ?>% Complete</p>
                                    <?php if (!empty($statusInfo['missing'])): ?>
                                        <div class="missing">Missing:
                                            <?php foreach ($statusInfo['missing'] as $m): ?><span><?= htmlspecialchars($m) ?></span><?php endforeach; ?>
                                        </div>
                                    <?php else: ?>
                                        <p style="color:var(--color-success);font-weight:600;">✅ All forms complete</p>
                                    <?php endif; ?>
                                    <a href="view-report.php?vessel_id=<?= $vessel_id ?>&date=<?= $r['log_date'] ?>"
                                        class="btn-view">📝 View & Fill</a>
                                </div>
                            <?php endif; endforeach;
                        if (!$hasOpen)
                            echo "<p style='color:gray;'>No open reports.</p>";
                        ?>
                    </div>
                </div>
            </section>

            <!-- LOCKED REPORTS -->
            <section class="report-section">
                <h3>🔒 Locked Reports</h3>
                <div class="report-scroll">
                    <div class="report-grid">
                        <?php
                        $hasLocked = false;
                        foreach ($reports as $r):
                            if ($r['status'] === 'LOCKED'):
                                $hasLocked = true;
                                $statusInfo = getReportStatus($pdo, $vessel_id, $r['log_date']);
                                ?>
                                <div class="report-card locked">
                                    <span class="status-tag">LOCKED</span>
                                    <h4><?= htmlspecialchars($r['log_date']) ?></h4>
                                    <div class="progress-container">
                                        <div class="progress-fill" style="width:<?= $statusInfo['percent'] ?>%;"></div>
                                    </div>
                                    <p><?= $statusInfo['percent'] ?>% Complete</p>
                                    <?php if (!empty($statusInfo['missing'])): ?>
                                        <div class="missing">Missing:
                                            <?php foreach ($statusInfo['missing'] as $m): ?><span><?= htmlspecialchars($m) ?></span><?php endforeach; ?>
                                        </div>
                                    <?php else: ?>
                                        <p style="color:var(--color-success);font-weight:600;">✅ All forms complete</p>
                                    <?php endif; ?>
                                    <a href="view-report.php?vessel_id=<?= $vessel_id ?>&date=<?= $r['log_date'] ?>"
                                        class="btn-view">👁 View Only</a>
                                </div>
                            <?php endif; endforeach;
                        if (!$hasLocked)
                            echo "<p style='color:gray;'>No locked reports yet.</p>";
                        ?>
                    </div>
                </div>
            </section>
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
                    <p>Captain Console</p>
                </div>
            </div>
            <div class="reminders" id="reminders-section">
                <div class="header">
                    <h2>Reminders</h2>
                    <span class="material-icons-sharp">notifications_none</span>
                </div>
                <div id="reminder-list">
                    <!-- reminders will be loaded here -->
                </div>
            </div>

        </div>
    </div>

    <script src="index.js"></script>
    <script src="appointment.js"></script>
    <script src="clock.js"></script>
    <script src="local-storage.js"></script>
    <script src="load-reminder.js"></script>
    <script>
        document.addEventListener("DOMContentLoaded", () => {
            // Animate progress bars
            document.querySelectorAll(".progress-fill").forEach(p => {
                const w = p.style.width; p.style.width = "0"; setTimeout(() => p.style.width = w, 150);
            });
            // Custom date visibility
            const dateFilter = document.getElementById("dateFilter");
            const customDate = document.getElementById("customDate");
            const toggleDate = () => customDate.style.display = dateFilter.value === "custom" ? "flex" : "none";
            toggleDate(); dateFilter.addEventListener("change", toggleDate);
        });
    </script>

</body>

</html>