<?php
session_start();
require_once "db_connect.php";
require_once "auto_cleanup_reminder.php";

autoCleanupReminders($pdo, $_SESSION['vessel_id']);

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$username = $_SESSION['full_name'];
$role = $_SESSION['role'];
$vessel_id = $_SESSION['vessel_id'] ?? null;

/* ================= Filters (NEW) ================= */
$searchDate = isset($_GET['search_date']) && $_GET['search_date'] !== ''
    ? date('Y-m-d', strtotime($_GET['search_date']))
    : null;

$statusFilter = isset($_GET['status']) ? strtoupper(trim($_GET['status'])) : 'ALL';
if (!in_array($statusFilter, ['ALL', 'OPEN', 'LOCKED'], true)) {
    $statusFilter = 'ALL';
}

$vesselName = '';
if (isset($_SESSION['vessel_id'])) {
    $vessel_id = $_SESSION['vessel_id'];
    $stmt = $pdo->prepare("SELECT vessel_name FROM vessels WHERE vessel_id = ?");
    $stmt->execute([$vessel_id]);
    $vesselName = $stmt->fetchColumn() ?: '';
}

/* ================= Build date list =================
   - Keep your UNION over modules so days with logs appear even if dailystatus missing
   - Exclude CLOSED
   - Apply optional status filter (OPEN/LOCKED) against dailystatus
*/
$params = [':vessel_id' => $vessel_id];

$statusExistsClause = " 
AND (
    :status = 'ALL'
    OR EXISTS (
        SELECT 1 FROM dailystatus sx
        WHERE sx.vessel_id = :vessel_id
          AND sx.log_date = d.log_date
          AND sx.status = :status
    )
)";

if ($searchDate) {
    $sqlSearch = "
    SELECT DISTINCT :the_date AS log_date
    FROM (
      SELECT log_date FROM activitylogs     WHERE vessel_id = :vessel_id AND log_date = :the_date
      UNION SELECT log_date FROM pobpersons WHERE vessel_id = :vessel_id AND log_date = :the_date
      UNION SELECT log_date FROM navigationreports WHERE vessel_id = :vessel_id AND log_date = :the_date
      UNION SELECT log_date FROM rob_records WHERE vessel_id = :vessel_id AND log_date = :the_date
      UNION SELECT log_date FROM runninghours WHERE vessel_id = :vessel_id AND log_date = :the_date
      UNION SELECT log_date FROM garbagelogs WHERE vessel_id = :vessel_id AND log_date = :the_date
      UNION SELECT log_date FROM oilrecordbook WHERE vessel_id = :vessel_id AND log_date = :the_date
      UNION SELECT log_date FROM dailystatus WHERE vessel_id = :vessel_id AND log_date = :the_date
    ) d
    WHERE NOT EXISTS (
        SELECT 1 FROM dailystatus s 
        WHERE s.vessel_id = :vessel_id 
          AND s.log_date = :the_date 
          AND s.status = 'CLOSED'
    )
    {$statusExistsClause}
    ";
    $stmt = $pdo->prepare($sqlSearch);
    $params[':the_date'] = $searchDate;
    $params[':status'] = $statusFilter;
    $stmt->execute($params);
    $dates = $stmt->fetchAll(PDO::FETCH_COLUMN);
} else {
    $sql = "
    SELECT DISTINCT d.log_date
    FROM (
      SELECT log_date FROM activitylogs WHERE vessel_id = :vessel_id
      UNION SELECT log_date FROM pobpersons WHERE vessel_id = :vessel_id
      UNION SELECT log_date FROM navigationreports WHERE vessel_id = :vessel_id
      UNION SELECT log_date FROM rob_records WHERE vessel_id = :vessel_id
      UNION SELECT log_date FROM runninghours WHERE vessel_id = :vessel_id
      UNION SELECT log_date FROM garbagelogs WHERE vessel_id = :vessel_id
      UNION SELECT log_date FROM oilrecordbook WHERE vessel_id = :vessel_id
      UNION SELECT log_date FROM dailystatus WHERE vessel_id = :vessel_id
    ) d
    WHERE NOT EXISTS (
        SELECT 1 FROM dailystatus s 
        WHERE s.vessel_id = :vessel_id 
          AND s.log_date = d.log_date 
          AND s.status = 'CLOSED'
    )
    {$statusExistsClause}
    ORDER BY d.log_date DESC
    LIMIT 14
    ";
    $stmt = $pdo->prepare($sql);
    $params[':status'] = $statusFilter;
    $stmt->execute($params);
    $dates = $stmt->fetchAll(PDO::FETCH_COLUMN);
}

/* Ensure each date exists in dailystatus (OPEN) */
if (!empty($dates)) {
    foreach ($dates as $date) {
        $chk = $pdo->prepare("SELECT COUNT(*) FROM dailystatus WHERE vessel_id=? AND log_date=?");
        $chk->execute([$vessel_id, $date]);
        if ($chk->fetchColumn() == 0) {
            $ins = $pdo->prepare("INSERT INTO dailystatus (vessel_id, log_date, status) VALUES (?, ?, 'OPEN')");
            $ins->execute([$vessel_id, $date]);
        }
    }
}

/* Ensure today exists */
$today = date("Y-m-d");
$chkToday = $pdo->prepare("SELECT COUNT(*) FROM dailystatus WHERE vessel_id=? AND log_date=?");
$chkToday->execute([$vessel_id, $today]);
if ($chkToday->fetchColumn() == 0) {
    $pdo->prepare("INSERT INTO dailystatus (vessel_id, log_date, status) VALUES (?, ?, 'OPEN')")
        ->execute([$vessel_id, $today]);
}

/* Reminders */
$reminderQuery = $pdo->prepare("
    SELECT modules_missing, log_date, message, sent_by, sent_to_email, created_at
    FROM reminders 
    WHERE vessel_id = ? 
    ORDER BY created_at DESC 
    LIMIT 5
");
$reminderQuery->execute([$vessel_id]);
$vesselReminders = $reminderQuery->fetchAll(PDO::FETCH_ASSOC);

/* Current POB (active) from pob_stints (as-of today) */
$activePob = [];
$categoryCounts = [];
$grandTotal = 0;
try {
    $pobStmt = $pdo->prepare("
        SELECT
            c.full_name,
            s.category,
            s.crew_role,
            s.embark_date,
            s.disembark_date
        FROM pob_stints s
        JOIN crewlist c ON c.id = s.person_id
        WHERE s.vessel_id = :vessel_id
          AND s.embark_date <= :today
          AND (s.disembark_date IS NULL OR s.disembark_date >= :today)
        ORDER BY s.category, c.full_name
    ");
    $pobStmt->execute([
        ':vessel_id' => $vessel_id,
        ':today' => $today
    ]);

    $activePob = $pobStmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($activePob as $row) {
        $cat = $row['category'] ?: 'UNCATEGORIZED';
        if (!isset($categoryCounts[$cat]))
            $categoryCounts[$cat] = 0;
        $categoryCounts[$cat]++;
        $grandTotal++;
    }
} catch (Exception $e) {
    $activePob = null;
}

?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <link href="https://fonts.googleapis.com/css2?family=Material+Icons+Sharp" rel="stylesheet" />
    <link rel="stylesheet" href="style.css" />
    <title>User Dashboard</title>
</head>
<style>
    /* Sidebar Dropdown */
    .dropdown .dropdown-menu {
        display: none;
        flex-direction: column;
        margin-left: 32px
    }

    .dropdown .dropdown-menu a {
        padding: 8px 0;
        font-size: .9rem;
        color: var(--color-dark)
    }

    .dropdown .dropdown-menu a:hover {
        color: var(--color-primary)
    }

    .dropdown.active .dropdown-menu {
        display: flex
    }

    .dropdown .arrow {
        margin-left: auto;
        transition: transform .3s ease
    }

    .dropdown.active .arrow {
        transform: rotate(180deg)
    }

    /* Buttons */
    .lock-btn {
        background: var(--color-success);
        color: var(--color-white);
        padding: 6px 12px;
        border: none;
        border-radius: var(--border-radius-1);
        cursor: pointer
    }

    .unlock-btn {
        background: var(--color-danger);
        color: var(--color-white);
        padding: 6px 12px;
        border: none;
        border-radius: var(--border-radius-1);
        cursor: pointer
    }

    /* Cards */
    .card {
        background: var(--color-white);
        border-radius: var(--border-radius-2);
        box-shadow: var(--box-shadow);
        padding: 0;
        overflow: hidden;
        margin-bottom: 24px
    }

    .card-header {
        background: var(--color-white) !important;
        border-bottom: 0;
        display: flex;
        align-items: center;
        gap: 10px;
        padding: 14px 18px
    }

    .card-header h3,
    .card-header .card-sub {
        color: var(--color-dark) !important
    }

    .card-header .icon {
        background: var(--color-white);
        color: var(--color-dark)
    }

    .card-body {
        padding: 16px 18px 18px
    }


    .btn {
        padding: 6px 12px;
        border-radius: var(--border-radius-1);
        border: 1px solid var(--color-primary);
        background: var(--color-primary);
        color: var(--color-white);
        cursor: pointer;
        transition: background .3s ease
    }

    .btn:hover {
        opacity: .9
    }

    .btn.primary {
        background: var(--color-primary);
        color: var(--color-white)
    }

    .btn.ghost {
        background: transparent;
        color: var(--color-primary);
        border-color: var(--color-primary)
    }



    /* Table */
    .table-responsive {
        max-height: 520px;
        overflow: auto;
        border-radius: var(--border-radius-1);
        border: 1px solid var(--color-light);
        box-shadow: inset 0 0 0 1px var(--color-light);
        background: var(--color-white)
    }

    table {
        width: 100%;
        border-collapse: collapse;
        color: var(--color-dark)
    }

    thead th {
        position: sticky;
        top: 0;
        z-index: 3;
        background: var(--color-primary);
        color: var(--color-white);
        text-align: left;
        padding: 10px 12px;
        font-weight: 600;
        border-bottom: 2px solid var(--color-primary)
    }

    tbody td {
        padding: 10px 12px;
        border-bottom: 1px solid var(--color-light)
    }

    tbody tr:hover {
        background: rgba(108, 155, 207, .12)
    }

    /* POB Section */
    .pob-grid {
        display: grid;
        grid-template-columns: 1.7fr .9fr;
        gap: 14px
    }

    @media (max-width:900px) {
        .pob-grid {
            grid-template-columns: 1fr
        }
    }

    .pob-table-wrap {
        max-height: 420px;
        overflow: auto;
        border-radius: var(--border-radius-1);
        border: 1px solid var(--color-light);
        background: var(--color-white)
    }

    .pill {
        display: inline-block;
        padding: 2px 8px;
        border-radius: 999px;
        background: rgba(108, 155, 207, .18);
        font-size: 12px;
        color: var(--color-primary)
    }

    .totals {
        display: flex;
        flex-direction: column;
        gap: 10px
    }

    .totals .pill {
        background: rgba(108, 155, 207, .18)
    }

    /* === FORCE the toolbar to be a single horizontal row === */
    .toolbar,
    .toolbar .left,
    .toolbar form {
        display: flex !important;
        align-items: center !important;
        gap: 10px !important;
        flex-wrap: nowrap !important;
        flex-direction: row !important;
        /* beats any column/grid setting */
    }

    /* defeat global width:100% / display:block on form children */
    .toolbar form>* {
        width: auto !important;
        display: inline-flex !important;
        margin: 0 !important;
        white-space: nowrap;
        flex: 0 0 auto !important;
    }

    /* keep label next to its control (some themes force label:block) */
    .toolbar form label {
        display: inline-flex !important;
        align-items: center;
    }

    /* give inputs a sane min width so they don’t collapse */
    .toolbar form select,
    .toolbar form input[type="date"] {
        min-width: 160px;
    }

    /* on narrow screens, allow wrapping */
    @media (max-width: 720px) {

        .toolbar,
        .toolbar .left,
        .toolbar form {
            flex-wrap: wrap !important;
        }
    }
</style>

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
                <a href="dashboard.php" class="active"><span class="material-icons-sharp">dashboard</span>
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
                <a href="captain-reminders.php"><span class="material-icons-sharp">schedule</span>
                    <h3>Reminders</h3>
                </a>
                <a href="history.php"><span class="material-icons-sharp">history_edu</span>
                    <h3>history</h3>
                </a>
                <a href="help.php"><span class="material-icons-sharp">help</span>
                    <h3>Help</h3>
                </a>
                <a href="login.php"><span class="material-icons-sharp">logout</span>
                    <h3>LogOut</h3>
                </a>
            </div>
        </aside>

        <!-- Main -->
        <main>
            <!-- ===== Daily Logbook Card ===== -->
            <div class="card">
                <div class="card-header">
                    <div class="icon"><span class="material-icons-sharp">event_note</span></div>
                    <h1>Daily Logbook Status</h1>
                    <div class="card-sub">
                        <?php
                        $sub = [];
                        $sub[] = $searchDate ? ("Date: " . htmlspecialchars($searchDate)) : "Last 14 Days";
                        if ($statusFilter !== 'ALL')
                            $sub[] = "Status: " . htmlspecialchars($statusFilter);
                        echo implode(" • ", $sub);
                        ?>
                    </div>
                </div>
                <div class="card-body">
                    <!-- Filter toolbar -->
                    <div class="toolbar">
                        <div class="left">
                            <form method="get" action="">
                                <label for="status">Status</label>
                                <select id="status" name="status">
                                    <option value="ALL" <?= $statusFilter === 'ALL' ? 'selected' : ''; ?>>All</option>
                                    <option value="OPEN" <?= $statusFilter === 'OPEN' ? 'selected' : ''; ?>>Open</option>
                                    <option value="LOCKED" <?= $statusFilter === 'LOCKED' ? 'selected' : ''; ?>>Locked
                                    </option>
                                </select>

                                <label for="search_date">Date</label>
                                <input type="date" id="search_date" name="search_date"
                                    value="<?= htmlspecialchars($searchDate ?? '') ?>">

                                <button type="submit" class="btn primary">Apply</button>
                                <a class="btn ghost" href="dashboard.php">Clear</a>
                            </form>
                        </div>
                        <div class="right"></div>
                    </div>
                    <br>

                    <div class="table-responsive">
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
                                    <?php if ($role === "CAPTAIN"): ?>
                                        <th>Chief Eng. Visa</th>
                                        <th>Master Visa</th>
                                        <th>Lock</th>
                                    <?php endif; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!empty($dates)): ?>
                                    <?php foreach ($dates as $date): ?>
                                        <?php
                                        $check = function ($table) use ($pdo, $vessel_id, $date) {
                                            $q = $pdo->prepare("SELECT COUNT(*) FROM $table WHERE vessel_id=? AND log_date=?");
                                            $q->execute([$vessel_id, $date]);
                                            return $q->fetchColumn() > 0 ? "✅" : "❌";
                                        };
                                        $activity = $check("activitylogs");
                                        $activity = $check("activitylogs");

                                                                                // POB present if any active stint exists on that date
                                                                                // Users don't need to submit the POB form every day — crew stints determine POB.
                                                                                $pobStmtDay = $pdo->prepare(
    "SELECT COUNT(*) FROM pob_stints WHERE vessel_id = ? AND embark_date <= ? AND (disembark_date IS NULL OR disembark_date >= ?)"
);
                                                                                $pobStmtDay->execute([$vessel_id, $date, $date]);
                                                                                $pob = ($pobStmtDay->fetchColumn() > 0) ? "✅" : "❌";

                                        $nav = $check("navigationreports");
                                        $rob = $check("rob_records");
                                        $engine = $check("runninghours");
                                        $garbage = $check("garbagelogs");
                                        $oil = $check("oilrecordbook");

                                        $nav = $check("navigationreports");
                                        $rob = $check("rob_records");
                                        $engine = $check("runninghours");
                                        $garbage = $check("garbagelogs");
                                        $oil = $check("oilrecordbook");

                                        $statusQ = $pdo->prepare("SELECT status FROM dailystatus WHERE vessel_id=? AND log_date=? LIMIT 1");
                                        $statusQ->execute([$vessel_id, $date]);
                                        $dayStatus = $statusQ->fetchColumn() ?: "OPEN";
                                        ?>
                                        <tr>
                                            <td><?= htmlspecialchars($date) ?></td>
                                            <td><?= $activity ?></td>
                                            <td><?= $nav ?></td>
                                            <td><?= $pob ?></td>
                                            <td><?= $engine ?></td>
                                            <td><?= $rob ?></td>
                                            <td><?= $garbage ?></td>
                                            <td><?= $oil ?></td>
                                            <td><?= htmlspecialchars($dayStatus) ?></td>

                                            <?php if ($role === "CAPTAIN"): ?>
                                                <td><input type="checkbox" class="visa-check" data-type="chief"
                                                        <?= ($dayStatus === "LOCKED") ? "checked disabled" : ""; ?>></td>
                                                <td><input type="checkbox" class="visa-check" data-type="master"
                                                        <?= ($dayStatus === "LOCKED") ? "checked disabled" : ""; ?>></td>
                                                <td>
                                                    <form method="POST" action="lock-day.php">
                                                        <input type="hidden" name="log_date" value="<?= htmlspecialchars($date) ?>">
                                                        <?php if ($dayStatus === "LOCKED"): ?>
                                                            <button type="submit" name="action" value="unlock" class="btn unlock-btn">🔓
                                                                Unlock</button>
                                                        <?php else: ?>
                                                            <button type="submit" name="action" value="lock" class="btn lock-btn"
                                                                disabled>🔒 Lock</button>
                                                        <?php endif; ?>
                                                    </form>
                                                </td>
                                            <?php endif; ?>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="<?= ($role === 'CAPTAIN' ? 12 : 9) ?>">No data found for the selected
                                            filter.</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- ===== Current POB Card ===== -->
            <div class="card">
                <div class="card-header">
                    <div class="icon"><span class="material-icons-sharp">groups</span></div>
                    <h1>Current POB List</h1>
                    <div class="card-sub">Active by Disembark Date</div>
                </div>
                <div class="card-body">
                    <p class="muted">Shows persons where <strong>disembark date is empty or on/after
                            <?= htmlspecialchars($today) ?></strong>.</p>

                    <?php if ($activePob === null): ?>
                        <p style="color:#c62828"><strong>Note:</strong> Please confirm the <code>pobpersons</code>
                            table/columns. Expected:
                            <code>full_name, category, crew_role, embark_date, disembark_date</code>.
                        </p>
                    <?php else: ?>
                        <div class="pob-grid">
                            <div>
                                <div class="pob-table-wrap">
                                    <table>
                                        <thead>
                                            <tr>
                                                <th>Name</th>
                                                <th>Category</th>
                                                <th>Role</th>
                                                <th>Embark</th>
                                                <th>Disembark</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if (count($activePob) === 0): ?>
                                                <tr>
                                                    <td colspan="5">No active POB.</td>
                                                </tr>
                                            <?php else: ?>
                                                <?php foreach ($activePob as $p): ?>
                                                    <tr>
                                                        <td><?= htmlspecialchars($p['full_name']) ?></td>
                                                        <td><span
                                                                class="pill"><?= htmlspecialchars($p['category'] ?: 'UNCATEGORIZED') ?></span>
                                                        </td>
                                                        <td><?= htmlspecialchars($p['crew_role'] ?: '-') ?></td>
                                                        <td><?= $p['embark_date'] ? htmlspecialchars($p['embark_date']) : '-' ?>
                                                        </td>
                                                        <td><?= $p['disembark_date'] ? htmlspecialchars($p['disembark_date']) : '-' ?>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                            <div class="totals">
                                <div class="row">
                                    <?php if (!empty($categoryCounts)): ?>
                                        <?php foreach ($categoryCounts as $cat => $cnt): ?>
                                            <span class="pill"><?= htmlspecialchars($cat) ?>:
                                                <strong><?= (int) $cnt ?></strong></span>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <span class="muted">No categories to summarize.</span>
                                    <?php endif; ?>
                                </div>
                                <div>Grand Total: <strong><?= (int) $grandTotal ?></strong></div>
                            </div>
                        </div>
                    <?php endif; ?>
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
                        <p>Hey, <b><?= htmlspecialchars($username); ?></b></p>
                        <small class="text-muted"><?= htmlspecialchars($role); ?></small>
                    </div>
                    <div class="profile-photo"><img src="image/blankProf.png" alt="Profile Photo"></div>
                </div>
            </div>

            <div class="user-profile">
                <div class="logo">
                    <img src="image/BSK_LOGO.jpg" alt="Logo">
                    <h2>PortaLog</h2>
                    <p><?= $vesselName ? " • " . htmlspecialchars($vesselName) : "" ?></p>
                </div>
            </div>

            <div class="reminders" id="reminders-section">
                <div class="header">
                    <h2>Reminders</h2>
                    <span class="material-icons-sharp">notifications_none</span>
                </div>
                <div id="reminder-list"><!-- loaded by JS --></div>
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
            document.querySelectorAll("tr").forEach(row => {
                const chief = row.querySelector('input[data-type="chief"]');
                const master = row.querySelector('input[data-type="master"]');
                const lockBtn = row.querySelector(".lock-btn");
                if (chief && master && lockBtn) {
                    function updateLockBtn() { lockBtn.disabled = !(chief.checked && master.checked); }
                    chief?.addEventListener("change", updateLockBtn);
                    master?.addEventListener("change", updateLockBtn);
                }
            });
            document.body.style.zoom = "100%";
        });

        // Sidebar dropdown toggle

    </script>
</body>

</html>