<?php
session_start();
require_once "db_connect.php";

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$vessel_id = $_GET['vessel_id'] ?? ($_SESSION['vessel_id'] ?? null);
$log_date = $_GET['date'] ?? date("Y-m-d");

$username = $_SESSION['full_name'] ?? '';
$role = $_SESSION['role'] ?? '';

// ========== STATUS ==========
$st = $pdo->prepare("SELECT status FROM dailystatus WHERE vessel_id=? AND log_date=?");
$st->execute([$vessel_id, $log_date]);
$status = $st->fetchColumn() ?: "OPEN";
$editable = ($status === 'OPEN');

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

// ========== UNLOCK REQUEST (POST) ==========
$toastMessage = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['request_unlock']) && !$editable) {
    // avoid duplicate unlock requests for same day
    $dup = $pdo->prepare("
        SELECT COUNT(*) 
        FROM reminders 
        WHERE vessel_id=? AND log_date=? AND modules_missing='UNLOCK REQUEST'
    ");
    $dup->execute([$vessel_id, $log_date]);

    if (!$dup->fetchColumn()) {

        // 🟢 1️⃣ Find Captain for this vessel
        $findCaptain = $pdo->prepare("
            SELECT email, full_name 
            FROM users 
            WHERE vessel_id=? AND role='CAPTAIN' 
            LIMIT 1
        ");
        $findCaptain->execute([$vessel_id]);
        $captain = $findCaptain->fetch(PDO::FETCH_ASSOC);

        $captainEmail = $captain['email'] ?? 'captain@bskh.com.my';
        $captainName = $captain['full_name'] ?? 'Captain';

        // 🟢 2️⃣ Create formatted unlock request message
        $messageText = "
        Dear Captain {$captainName},

        The report for <b>{$log_date}</b> is currently locked.
        A request has been submitted to unlock this report for review and necessary updates.

        Please verify and unlock the report if appropriate.

        Regards,<br>
        <b>{$username}</b>
        ";

        // 🟢 3️⃣ Insert reminder record
        $insert = $pdo->prepare("
            INSERT INTO reminders 
            (vessel_id, log_date, modules_missing, message, sent_by, sent_to_email, created_at)
            VALUES (?, ?, 'UNLOCK REQUEST', ?, ?, ?, NOW())
        ");
        $insert->execute([$vessel_id, $log_date, $messageText, $username ?: 'System', $captainEmail]);
    }

    $toastMessage = '✅ Unlock request sent';
}



// ========== HELPERS ==========
function fetchRows($pdo, $table, $vessel_id, $log_date)
{
    $q = $pdo->prepare("SELECT * FROM $table WHERE vessel_id=? AND log_date=?");
    $q->execute([$vessel_id, $log_date]);
    return $q->fetchAll(PDO::FETCH_ASSOC);
}
function col($row, $name, $fallback = '-')
{
    return isset($row[$name]) && $row[$name] !== '' ? htmlspecialchars((string) $row[$name]) : $fallback;
}

$today_for_pob = $log_date;
$activityData = fetchRows($pdo, 'activitylogs', $vessel_id, $log_date);
// Load POB from pob_stints (active stints as-of $log_date) so POB reflects crew onboard
$pobStmt = $pdo->prepare("SELECT c.full_name, s.category, s.crew_role, s.embark_date, s.disembark_date
        FROM pob_stints s
        JOIN crewlist c ON c.id = s.person_id
        WHERE s.vessel_id = ?
            AND s.embark_date <= ?
            AND (s.disembark_date IS NULL OR s.disembark_date >= ?)
        ORDER BY s.category, c.full_name");
$pobStmt->execute([$vessel_id, $today_for_pob, $today_for_pob]);
$pobData = $pobStmt->fetchAll(PDO::FETCH_ASSOC);
$navData = fetchRows($pdo, 'navigationreports', $vessel_id, $log_date);
$robData = fetchRows($pdo, 'rob_records', $vessel_id, $log_date);
$engineData = fetchRows($pdo, 'runninghours', $vessel_id, $log_date);
$garbageData = fetchRows($pdo, 'garbagelogs', $vessel_id, $log_date);
$oilData = fetchRows($pdo, 'oilrecordbook', $vessel_id, $log_date);

// Split ROB by category
$liquid = array_values(array_filter($robData, fn($r) => strtoupper($r['category'] ?? '') === 'LIQUID'));
$dry = array_values(array_filter($robData, fn($r) => strtoupper($r['category'] ?? '') === 'DRY'));
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Report for <?= htmlspecialchars($log_date) ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Material+Icons+Sharp" rel="stylesheet">
    <link rel="stylesheet" href="style.css">
    <style>
        /* Dropdown base */
        .dropdown .dropdown-menu {
            display: none;
            flex-direction: column;
            margin-left: 32px;
        }

        .dropdown .dropdown-menu a {
            padding: 8px 0;
            font-size: 0.9rem;
            color: var(--color-dark);
        }

        .dropdown .dropdown-menu a:hover {
            color: var(--color-primary);
        }

        .dropdown.active .dropdown-menu {
            display: flex;
        }

        .dropdown .arrow {
            margin-left: auto;
            transition: transform 0.3s ease;
        }

        .dropdown.active .arrow {
            transform: rotate(180deg);
        }

        main {
            padding: 1.5rem;
        }

        .btn-back,
        .btn-add,
        .btn-unlock {
            display: inline-block;
            padding: 8px 14px;
            border-radius: var(--border-radius-1);
            font-weight: 600;
            text-decoration: none;
            transition: background .25s ease;
        }

        .btn-back {
            background: var(--color-primary);
            color: #fff;
            margin-bottom: 12px;
        }

        .btn-back:hover {
            background: var(--color-dark);
        }

        .btn-add {
            background: var(--color-success);
            color: #fff;
            margin: 6px 0 10px;
        }

        .btn-add:hover {
            background: #157b65;
        }

        .btn-unlock {
            background: var(--color-warning);
            color: #000;
            border: none;
            cursor: pointer;
        }

        .btn-unlock:hover {
            filter: brightness(0.95);
        }

        .lock-banner {
            background: var(--color-danger);
            color: #fff;
            padding: 10px 14px;
            border-radius: var(--border-radius-1);
            margin: 0 0 12px;
        }

        .tabs {
            display: flex;
            gap: 8px;
            border-bottom: 2px solid var(--color-light);
            margin-bottom: 1rem;
            flex-wrap: wrap;
        }

        .tab-btn {
            padding: 8px 16px;
            border: none;
            background: var(--color-light);
            color: var(--color-dark);
            border-radius: var(--border-radius-1) var(--border-radius-1) 0 0;
            font-weight: 600;
            cursor: pointer;
            transition: .3s ease;
        }

        .tab-btn.active {
            background: var(--color-primary);
            color: #fff;
        }

        .tab-content {
            display: none;
            background: var(--color-white);
            border-radius: var(--border-radius-2);
            box-shadow: var(--box-shadow);
            padding: 1.5rem;
        }

        .tab-content.active {
            display: block;
        }

        .readonly {
            pointer-events: none;
            opacity: .85;
        }

        .table-wrap {
            overflow-x: auto;
            margin-top: 10px;
        }

        .report-table {
            width: 100%;
            border-collapse: collapse;
            font-size: .9rem;
        }

        .report-table th {
            background: var(--color-primary);
            color: #fff;
            text-align: center;
            padding: 8px;
            white-space: nowrap;
        }

        .report-table td {
            border: 1px solid var(--line);
            padding: 6px;
            text-align: center;
            white-space: nowrap;
        }

        .report-table tr:nth-child(even) td {
            background: #f9f9f9;
        }

        .section-title {
            font-size: 1.05rem;
            font-weight: 700;
            color: var(--ink);
            margin: 4px 0 8px;
        }

        /* Toast */
        .toast {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 9999;
            background: var(--color-success);
            color: #fff;
            padding: 10px 14px;
            border-radius: var(--border-radius-1);
            box-shadow: var(--box-shadow);
            font-weight: 600;
            opacity: 1;
            transition: opacity .5s ease;
        }

        .toast.hide {
            opacity: 0;
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
                        <a href="#"></a>
                    </div>
                </div>
                <a href="maintainence-schedule.php"><span class="material-icons-sharp">schedule</span>
                    <h3>Maintanence Schedule</h3>
                </a>
                <a href="history.php" class="active"><span class="material-icons-sharp">history_edu</span>
                    <h3>history</h3>
                </a>
                <a href="settings.php"><span class="material-icons-sharp">settings</span>
                    <h3>Settings</h3>
                </a>
                <a href="login.php" class="logout"><span class="material-icons-sharp">logout</span>
                    <h3>LogOut</h3>
                </a>
            </div>
        </aside>

        <!-- Main -->
        <main>
            <a href="history.php" class="btn-back">⬅ Back to History</a>
            <h2>📅 Report for <?= htmlspecialchars($log_date) ?></h2>

            <?php if (!$editable): ?>
                <div class="lock-banner">🔒 This report is LOCKED. To edit, request unlock from the Captain.</div>
                <form method="POST" style="margin-bottom:12px;">
                    <input type="hidden" name="request_unlock" value="1">
                    <button type="submit" class="btn-unlock">📩 Request Unlock</button>
                </form>
            <?php endif; ?>

            <!-- Tabs -->
            <div class="tabs">
                <button class="tab-btn active" data-tab="activity">Activity</button>
                <button class="tab-btn" data-tab="pob">POB</button>
                <button class="tab-btn" data-tab="navigation">Navigation</button>
                <button class="tab-btn" data-tab="rob">ROB</button>
                <button class="tab-btn" data-tab="runninghours">Running Hours</button>
                <button class="tab-btn" data-tab="garbage">Garbage</button>
                <button class="tab-btn" data-tab="oil">Oil Record</button>
            </div>

            <!-- ===== Activity ===== -->
            <div id="activity" class="tab-content active <?= !$editable ? 'readonly' : '' ?>">
                <h3>⚙️ Activity Log</h3>
                <?php if ($editable): ?>
                    <a href="daily-report.php?date=<?= urlencode($log_date) ?>" class="btn-add">📝 Open Activity Form</a>
                <?php endif; ?>
                <div class="table-wrap">
                    <table class="report-table">
                        <thead>
                            <tr>
                                <th>Start</th>
                                <th>End</th>
                                <th>Title</th>
                                <th>Description</th>
                                <th>Category</th>
                                <th>Assigned By</th>
                                <th>Assigned To</th>
                                <th>Duration (min)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($activityData)): ?>
                                <tr>
                                    <td colspan="8" style="color:gray;">No activities recorded.</td>
                                </tr>
                            <?php else:
                                foreach ($activityData as $r): ?>
                                    <tr>
                                        <td><?= col($r, 'start_time') ?></td>
                                        <td><?= col($r, 'end_time') ?></td>
                                        <td><?= col($r, 'title') ?></td>
                                        <td><?= col($r, 'description') ?></td>
                                        <td><?= col($r, 'category') ?></td>
                                        <td><?= col($r, 'assigned_by') ?></td>
                                        <td><?= col($r, 'assigned_to') ?></td>
                                        <td><?= col($r, 'duration_min', '0') ?></td>
                                    </tr>
                                <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- ===== POB ===== -->
            <div id="pob" class="tab-content <?= !$editable ? 'readonly' : '' ?>">
                <h3>👥 Persons On Board</h3>
                <?php if ($editable): ?>
                    <a href="pob-report.php?date=<?= urlencode($log_date) ?>" class="btn-add">🧾 Open POB Form</a>
                <?php endif; ?>
                <div class="table-wrap">
                    <table class="report-table">
                        <thead>
                            <tr>
                                <th>Full Name</th>
                                <th>Category</th>
                                <th>Crew Role</th>
                                <th>Embark</th>
                                <th>Disembark</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($pobData)): ?>
                                <tr>
                                    <td colspan="5" style="color:gray;">No POB data.</td>
                                </tr>
                            <?php else:
                                foreach ($pobData as $r): ?>
                                    <tr>
                                        <td><?= col($r, 'full_name') ?></td>
                                        <td><?= col($r, 'category') ?></td>
                                        <td><?= col($r, 'crew_role') ?></td>
                                        <td><?= col($r, 'embark_date') ?></td>
                                        <td><?= col($r, 'disembark_date', '-') ?></td>
                                    </tr>
                                <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- ===== Navigation ===== -->
            <div id="navigation" class="tab-content <?= !$editable ? 'readonly' : '' ?>">
                <h3>🧭 Navigation Report</h3>
                <?php if ($editable): ?>
                    <a href="navigation-report.php?date=<?= urlencode($log_date) ?>" class="btn-add">📄 Open Navigation
                        Form</a>
                <?php endif; ?>
                <div class="table-wrap">
                    <table class="report-table">
                        <thead>
                            <tr>
                                <th>Time</th>
                                <th>Lat</th>
                                <th>Lon</th>
                                <th>Course°</th>
                                <th>Speed (kn)</th>
                                <th>Draught Fwd (m)</th>
                                <th>Draught Aft (m)</th>
                                <th>Weather</th>
                                <th>Sea State</th>
                                <th>Visibility (nm)</th>
                                <th>Destination</th>
                                <th>ETA</th>
                                <th>Remarks</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($navData)): ?>
                                <tr>
                                    <td colspan="13" style="color:gray;">No navigation data.</td>
                                </tr>
                            <?php else:
                                foreach ($navData as $r): ?>
                                    <tr>
                                        <td><?= col($r, 'report_time') ?></td>
                                        <td><?= col($r, 'latitude') ?></td>
                                        <td><?= col($r, 'longitude') ?></td>
                                        <td><?= col($r, 'course_deg') ?></td>
                                        <td><?= col($r, 'speed_kn') ?></td>
                                        <td><?= col($r, 'draught_fwd_m') ?></td>
                                        <td><?= col($r, 'draught_aft_m') ?></td>
                                        <td><?= col($r, 'weather') ?></td>
                                        <td><?= col($r, 'sea_state') ?></td>
                                        <td><?= col($r, 'visibility_nm') ?></td>
                                        <td><?= col($r, 'destination') ?></td>
                                        <td><?= col($r, 'eta') ?></td>
                                        <td><?= col($r, 'remarks') ?></td>
                                    </tr>
                                <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- ===== ROB ===== -->
            <div id="rob" class="tab-content <?= !$editable ? 'readonly' : '' ?>">
                <h3>🛢 Remaining On Board (ROB)</h3>
                <?php if ($editable): ?>
                    <a href="engine-report.php?date=<?= urlencode($log_date) ?>" class="btn-add">📊 Open ROB & Engine
                        Form</a>
                <?php endif; ?>

                <div class="section-title">Liquid Bulk</div>
                <div class="table-wrap">
                    <table class="report-table">
                        <thead>
                            <tr>
                                <th>Product</th>
                                <th>Unit</th>
                                <th>Prev</th>
                                <th>Loaded</th>
                                <th>Discharged</th>
                                <th>Produced</th>
                                <th>Density</th>
                                <th>Daily Cons.</th>
                                <th>Adjustment</th>
                                <th>Current</th>
                                <th>Max Cap</th>
                                <th>Remarks</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($liquid)): ?>
                                <tr>
                                    <td colspan="12" style="color:gray;">No liquid bulk data.</td>
                                </tr>
                            <?php else:
                                foreach ($liquid as $r): ?>
                                    <tr>
                                        <td><?= col($r, 'product') ?></td>
                                        <td><?= col($r, 'unit') ?></td>
                                        <td><?= col($r, 'previous_rob', '0') ?></td>
                                        <td><?= col($r, 'loaded_today', '0') ?></td>
                                        <td><?= col($r, 'discharged_today', '0') ?></td>
                                        <td><?= col($r, 'produced_today', '0') ?></td>
                                        <td><?= col($r, 'density', '0') ?></td>
                                        <td><?= col($r, 'daily_consumption', '0') ?></td>
                                        <td><?= col($r, 'adjustment', '0') ?></td>
                                        <td><?= col($r, 'current_rob', '0') ?></td>
                                        <td><?= col($r, 'max_capacity', '0') ?></td>
                                        <td><?= col($r, 'remarks') ?></td>
                                    </tr>
                                <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>

                <div class="section-title">Dry Bulk</div>
                <div class="table-wrap">
                    <table class="report-table">
                        <thead>
                            <tr>
                                <th>Product</th>
                                <th>Unit</th>
                                <th>Prev</th>
                                <th>Loaded</th>
                                <th>Discharged</th>
                                <th>Produced</th>
                                <th>Density</th>
                                <th>Daily Cons.</th>
                                <th>Adjustment</th>
                                <th>Current</th>
                                <th>Max Cap</th>
                                <th>Remarks</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($dry)): ?>
                                <tr>
                                    <td colspan="12" style="color:gray;">No dry bulk data.</td>
                                </tr>
                            <?php else:
                                foreach ($dry as $r): ?>
                                    <tr>
                                        <td><?= col($r, 'product') ?></td>
                                        <td><?= col($r, 'unit') ?></td>
                                        <td><?= col($r, 'previous_rob', '0') ?></td>
                                        <td><?= col($r, 'loaded_today', '0') ?></td>
                                        <td><?= col($r, 'discharged_today', '0') ?></td>
                                        <td><?= col($r, 'produced_today', '0') ?></td>
                                        <td><?= col($r, 'density', '0') ?></td>
                                        <td><?= col($r, 'daily_consumption', '0') ?></td>
                                        <td><?= col($r, 'adjustment', '0') ?></td>
                                        <td><?= col($r, 'current_rob', '0') ?></td>
                                        <td><?= col($r, 'max_capacity', '0') ?></td>
                                        <td><?= col($r, 'remarks') ?></td>
                                    </tr>
                                <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- ===== Running Hours ===== -->
            <div id="runninghours" class="tab-content <?= !$editable ? 'readonly' : '' ?>">
                <h3>⚙️ Running Hours</h3>
                <?php if ($editable): ?>
                    <a href="engine-report.php?date=<?= urlencode($log_date) ?>" class="btn-add">⚙️ Open Engine Hours
                        Form</a>
                <?php endif; ?>
                <div class="table-wrap">
                    <table class="report-table">
                        <thead>
                            <tr>
                                <th>Equipment</th>
                                <th>Start Time</th>
                                <th>End Time</th>
                                <th>Duration (min)</th>
                                <th>Remarks</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($engineData)): ?>
                                <tr>
                                    <td colspan="5" style="color:gray;">No running hours data.</td>
                                </tr>
                            <?php else:
                                foreach ($engineData as $r): ?>
                                    <tr>
                                        <td><?= col($r, 'generator_name', col($r, 'machine_name', col($r, 'equipment', '-'))) ?>
                                        </td>
                                        <td><?= col($r, 'start_time') ?></td>
                                        <td><?= col($r, 'end_time') ?></td>
                                        <td><?= col($r, 'duration_min', col($r, 'running_hour', '0')) ?></td>
                                        <td><?= col($r, 'remarks') ?></td>
                                    </tr>
                                <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- ===== Garbage ===== -->
            <div id="garbage" class="tab-content <?= !$editable ? 'readonly' : '' ?>">
                <h3>🗑 Garbage Record</h3>
                <?php if ($editable): ?>
                    <a href="garbage-report.php?date=<?= urlencode($log_date) ?>" class="btn-add">♻️ Open Garbage Form</a>
                <?php endif; ?>
                <div class="table-wrap">
                    <table class="report-table">
                        <thead>
                            <tr>
                                <th>Time</th>
                                <th>Category</th>
                                <th>Qty (m³)</th>
                                <th>Method</th>
                                <th>Lat</th>
                                <th>Lon</th>
                                <th>Port</th>
                                <th>Receipt Ref</th>
                                <th>Remarks</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($garbageData)): ?>
                                <tr>
                                    <td colspan="9" style="color:gray;">No garbage entries.</td>
                                </tr>
                            <?php else:
                                foreach ($garbageData as $r): ?>
                                    <tr>
                                        <td><?= col($r, 'entry_time', col($r, 'time', '-')) ?></td>
                                        <td><?= col($r, 'category') ?></td>
                                        <td><?= col($r, 'qty_m3', col($r, 'quantity', '0')) ?></td>
                                        <td><?= col($r, 'method', col($r, 'disposal_method', '-')) ?></td>
                                        <td><?= col($r, 'latitude') ?></td>
                                        <td><?= col($r, 'longitude') ?></td>
                                        <td><?= col($r, 'port') ?></td>
                                        <td><?= col($r, 'receipt_ref', col($r, 'receipt', '-')) ?></td>
                                        <td><?= col($r, 'remarks') ?></td>
                                    </tr>
                                <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- ===== Oil Record ===== -->
            <div id="oil" class="tab-content <?= !$editable ? 'readonly' : '' ?>">
                <h3>🛠 Oil Record Book</h3>
                <?php if ($editable): ?>
                    <a href="oil-report.php?date=<?= urlencode($log_date) ?>" class="btn-add">🧾 Open Oil Record Form</a>
                <?php endif; ?>
                <div class="table-wrap">
                    <table class="report-table">
                        <thead>
                            <tr>
                                <th>Operation</th>
                                <th>Date/Time</th>
                                <th>Tank</th>
                                <th>Qty (MT)</th>
                                <th>Category</th>
                                <th>Lat</th>
                                <th>Lon</th>
                                <th>Remarks</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($oilData)): ?>
                                <tr>
                                    <td colspan="8" style="color:gray;">No oil record entries.</td>
                                </tr>
                            <?php else:
                                foreach ($oilData as $r): ?>
                                    <tr>
                                        <td><?= col($r, 'operation') ?></td>
                                        <td><?= col($r, 'date_time', col($r, 'entry_time', '-')) ?></td>
                                        <td><?= col($r, 'tank') ?></td>
                                        <td><?= col($r, 'qty_mt', col($r, 'quantity', '0')) ?></td>
                                        <td><?= col($r, 'category') ?></td>
                                        <td><?= col($r, 'latitude') ?></td>
                                        <td><?= col($r, 'longitude') ?></td>
                                        <td><?= col($r, 'remarks') ?></td>
                                    </tr>
                                <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
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

    <?php if ($toastMessage): ?>
        <div class="toast" id="toast"><?= htmlspecialchars($toastMessage) ?></div>
    <?php endif; ?>

    <script>
        // tab switching
        document.addEventListener("DOMContentLoaded", () => {
            const tabs = document.querySelectorAll(".tab-btn");
            const contents = document.querySelectorAll(".tab-content");
            tabs.forEach(btn => {
                btn.addEventListener("click", () => {
                    tabs.forEach(b => b.classList.remove("active"));
                    contents.forEach(c => c.classList.remove("active"));
                    btn.classList.add("active");
                    document.getElementById(btn.dataset.tab).classList.add("active");
                });
            });

            // toast auto-hide
            const toast = document.getElementById('toast');
            if (toast) {
                setTimeout(() => toast.classList.add('hide'), 2000);
                setTimeout(() => toast.remove(), 2600);
            }
        });
    </script>
    <script src="index.js"></script>
    <script src="appointment.js"></script>
    <script src="clock.js"></script>
    <script src="local-storage.js"></script>
    <script src="load-reminder.js"></script>
</body>

</html>