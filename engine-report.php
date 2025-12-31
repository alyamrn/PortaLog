<?php
session_start();
require_once "db_connect.php";
require_once "auto_cleanup_reminder.php";

autoCleanupReminders($pdo, $_SESSION['vessel_id']);

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$log_date = $_GET['date'] ?? date("Y-m-d");
$username = $_SESSION['full_name'];
$role = $_SESSION['role'];
$vessel_id = $_SESSION['vessel_id'];
$date = $_GET['date'] ?? date("Y-m-d");

// Load default machines for this vessel from vessel_machines table
$stmtDefault = $pdo->prepare("SELECT machine_name FROM vessel_machines WHERE vessel_id = ?");
$stmtDefault->execute([$vessel_id]);
$default_machines = $stmtDefault->fetchAll(PDO::FETCH_COLUMN);

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



// If no record yet, show these initial defaults
if (empty($default_machines)) {
    $default_machines = [
        "Main Generator 1",
        "Main Generator 2",
        "Main Generator 3",
        "Main Generator 4",
        "Emergency Generator"
    ];
}

// Fetch running hour records for this day
$stmt = $pdo->prepare("SELECT * FROM runninghours WHERE vessel_id=? AND log_date=? ORDER BY generator_name");
$stmt->execute([$vessel_id, $date]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Index by generator_name
$records = [];
foreach ($rows as $r) {
    $records[$r['generator_name']] = $r;
}

// Check if this day is locked
$q = $pdo->prepare("SELECT status FROM dailystatus WHERE vessel_id=? AND log_date=? LIMIT 1");
$q->execute([$vessel_id, $log_date]);
$status = $q->fetchColumn();
?>


<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://fonts.googleapis.com/css2?family=Material+Icons+Sharp" rel="stylesheet" />
    <link rel="stylesheet" href="style.css" />
    <link rel="stylesheet" href="locked.css" />
    <title>User Dashboard</title>
</head>
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

    /* Running Hours Filter Container */
    .running-hours-filter-wrapper {
        background: var(--color-white);
        border: 1px solid var(--line);
        border-radius: var(--border-radius-2);
        padding: 16px;
        margin-bottom: 20px;
        display: flex;
        flex-direction: column;
        align-items: flex-start;
        gap: 12px;
        width: 100%;
        box-sizing: border-box;
    }

    .running-hours-filter-wrapper label {
        font-weight: 600;
        color: var(--ink);
        font-size: 0.9rem;
        white-space: nowrap;
        margin: 0;
    }

    .running-hours-filter-form {
        display: flex;
        flex-direction: column;
        align-items: flex-start;
        gap: 12px;
        margin: 0;
        width: 100%;
    }

    .running-hours-filter-input {
        padding: 10px 12px;
        border: 1px solid var(--line);
        border-radius: 6px;
        font-size: 0.9rem;
        background: var(--color-primary);
        color: var(--color-dark);
        transition: border-color 0.2s ease, box-shadow 0.2s ease;
        width: 100%;
        max-width: 300px;
    }

    .running-hours-filter-input:focus {
        border-color: var(--accent);
        box-shadow: 0 0 4px var(--accent);
        outline: none;
    }

    .running-hours-filter-submit {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 10px 16px;
        border: none;
        border-radius: 6px;
        font-weight: 600;
        cursor: pointer;
        transition: background 0.25s ease, transform 0.15s ease;
        text-decoration: none;
        font-size: 0.9rem;
        background: var(--color-primary);
        color: var(--color-dark);
        box-shadow: 0 2px 6px rgba(0, 0, 0, 0.15);
        white-space: nowrap;
    }

    .running-hours-filter-submit:hover {
        background: #4a54d1;
        transform: translateY(-2px);
    }

    /* Arrange filters inline - smooth responsive layout */
    .controls {
        display: flex;
        align-items: center;
        justify-content: flex-start;
        flex-wrap: nowrap;
        gap: 12px;
        padding: 14px 16px;
        background: var(--card);
        border: 1px solid var(--line);
        border-radius: var(--border-radius-2);
        margin-bottom: 20px;
    }

    .controls label {
        font-weight: 600;
        color: var(--ink);
        font-size: 0.9rem;
        white-space: nowrap;
        margin: 0;
    }

    .controls input[type="date"] {
        padding: 8px 12px;
        border: 1px solid var(--line);
        border-radius: var(--border-radius-1);
        font-size: 0.9rem;
        background: var(--color-primary);
        color: var(--color-dark);
        transition: border-color 0.2s ease, box-shadow 0.2s ease;
        width: 160px;
        flex-shrink: 0;
        height: 36px;
        box-sizing: border-box;
    }

    .controls input[type="date"]:focus {
        border-color: var(--accent);
        box-shadow: 0 0 4px var(--accent);
        outline: none;
    }

    .controls .btn {
        flex-shrink: 0;
        white-space: nowrap;
        margin: 0;
        padding: 8px 16px;
        height: 36px;
        display: flex;
        align-items: center;
        justify-content: center;
        box-sizing: border-box;
    }

    /* Table styling */
    .table-wrap {
        border: 1px solid var(--line);
        border-radius: 6px;
        overflow-x: auto;
        background: var(--color-white);
        box-shadow: var(--box-shadow);
    }

    table {
        width: 100%;
        border-collapse: collapse;
    }

    th {
        background: var(--color-primary);
        color: var(--color-dark);
        font-weight: 700;
        text-align: center;
        padding: 10px;
    }

    td {
        padding: 8px;
        border-top: 1px solid var(--line);
        text-align: center;
    }

    tr:nth-child(even) td {
        background: var(--row);
    }

    input[type="time"] {
        padding: 6px 8px;
        border: 1px solid var(--line);
        border-radius: var(--border-radius-1);
        font-size: 0.9rem;
        background: var(--color-primary);
        color: var(--color-dark);
    }

    /* Buttons */
    .btn {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 8px 16px;
        border: none;
        border-radius: var(--border-radius-1);
        font-weight: 600;
        cursor: pointer;
        transition: background 0.25s ease, transform 0.15s ease;
        text-decoration: none;
        font-size: 0.9rem;
    }

    .btn-primary {
        background: var(--color-primary);
        color: var(--color-dark) ;
        box-shadow: 0 2px 6px rgba(0, 0, 0, 0.15);
    }

    .btn-primary:hover {
        background: #4a54d1;
        transform: translateY(-2px);
    }

    .btn-secondary {
        background: var(--card);
        color: var(--ink);
        border: 1px solid var(--line);
    }

    .btn-secondary:hover {
        background: var(--row);
        transform: translateY(-2px);
    }

    .btn-danger {
        background: var(--color-danger);
        color: #fff;
    }

    .btn-danger:hover {
        background: #b71c1c;
        transform: translateY(-2px);
    }

    /* Engine Tabs */
    .engine-tabs {
        display: flex;
        gap: 18px;
        border-bottom: 1px solid var(--line);
        margin-bottom: 18px;
        padding-bottom: 6px;
    }

    .engine-tabs .tab {
        text-decoration: none;
        font-weight: 600;
        font-size: 0.95rem;
        color: var(--ink);
        padding: 8px 14px;
        border-radius: 6px;
        transition: background 0.2s, color 0.2s;
    }

    .engine-tabs .tab:hover {
        background: var(--color-primary);
        color: var(--color-dark);
    }

    .engine-tabs .tab.active {
        background: var(--color-primary);
        color: var(--accent);
        position: relative;
    }

    .engine-tabs .tab.active::after {
        content: "";
        position: absolute;
        bottom: -6px;
        left: 15%;
        right: 15%;
        height: 3px;
        border-radius: 2px;
        background: var(--accent);
    }

    /* Improved Table Styling */
    .table-section {
        background: var(--card);
        padding: 20px;
        border-radius: 8px;
        border: 1px solid var(--line);
        margin-bottom: 24px;
    }

    .table-section h3 {
        margin-top: 0;
        margin-bottom: 16px;
        font-size: 1.1rem;
        color: var(--ink);
    }

    table.styled-table {
        width: 100%;
        border-collapse: collapse;
        background: var(--color-white);
    }

    table.styled-table thead {
        background: linear-gradient(135deg, var(--color-primary), #5a63d8);
        color: var(--color-dark);
    }

    table.styled-table th {
        padding: 14px 12px;
        text-align: center;
        font-weight: 700;
        font-size: 0.95rem;
        letter-spacing: 0.5px;
    }

    table.styled-table tbody tr {
        border-bottom: 1px solid var(--line);
        transition: background-color 0.2s ease;
    }

    table.styled-table tbody tr:hover {
        background: var(--row);
    }

    table.styled-table td {
        padding: 14px 12px;
        text-align: center;
        font-size: 0.95rem;
    }

    table.styled-table input[type="time"],
    table.styled-table input[type="text"] {
        width: 100%;
        max-width: 140px;
        padding: 8px 10px;
        border: 1px solid var(--line);
        border-radius: 6px;
        font-size: 0.9rem;
        background: var(--color-primary);
        color: var(--color-dark);
        transition: border-color 0.2s ease, box-shadow 0.2s ease;
    }

    table.styled-table input[type="time"]:focus,
    table.styled-table input[type="text"]:focus {
        outline: none;
        border-color: var(--accent);
        box-shadow: 0 0 6px rgba(74, 84, 209, 0.3);
    }

    /* Actions Container */
    .actions-container {
        display: flex;
        gap: 10px;
        justify-content: center;
        align-items: center;
        flex-wrap: wrap;
    }

    /* Manage Machine Form */
    .manage-machine-form {
        display: grid;
        grid-template-columns: 1fr auto;
        gap: 10px;
        margin-bottom: 20px;
    }

    .manage-machine-form input[type="text"] {
        padding: 10px 12px;
        border: 1px solid var(--line);
        border-radius: 6px;
        font-size: 0.95rem;
        background: var(--color-primary);
        color: var(--color-dark);
        transition: border-color 0.2s ease, box-shadow 0.2s ease;
    }

    .manage-machine-form input[type="text"]:focus {
        outline: none;
        border-color: var(--accent);
        box-shadow: 0 0 6px rgba(74, 84, 209, 0.3);
    }

    /* Form Actions */
    .form-actions {
        margin-top: 18px;
        display: flex;
        gap: 12px;
        flex-wrap: wrap;
    }

    .form-actions .btn {
        flex: 1;
        min-width: 200px;
    }

    /* Responsive */
    @media (max-width: 768px) {
        .manage-machine-form {
            grid-template-columns: 1fr;
        }

        table.styled-table input[type="time"],
        table.styled-table input[type="text"] {
            max-width: 100%;
        }

        .form-actions .btn {
            min-width: auto;
        }

        .controls {
            flex-wrap: nowrap;
            gap: 10px;
        }

        .controls input[type="date"] {
            flex: 1;
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
                        <a href="engine-report.php" class="active">Engine Hours & ROB</a>
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
                <a href="login.php" class="logout"><span class="material-icons-sharp">logout</span>
                    <h3>LogOut</h3>
                </a>
            </div>
        </aside>

        <!-- Main Content -->
        <main>
            <!-- Engine Tabs -->
            <nav class="engine-tabs">
                <a href="engine-report.php"
                    class="tab <?php echo basename($_SERVER['PHP_SELF']) == 'engine-report.php' ? 'active' : ''; ?>">Running
                    Hours</a>
                <a href="engine-rob.php"
                    class="tab <?php echo basename($_SERVER['PHP_SELF']) == 'engine-rob.php' ? 'active' : ''; ?>">ROB</a>
            </nav>

            <h2>Running Hours</h2>

            <div class="running-hours-filter-wrapper">
                <label>📅 Select Date:</label>
                <form method="GET" class="running-hours-filter-form">
                    <input type="date" class="running-hours-filter-input" name="date" value="<?= htmlspecialchars($date) ?>">
                    <button class="running-hours-filter-submit" type="submit">🔍 Filter</button>
                </form>
            </div>

            <?php if ($status === "LOCKED"): ?>
                <div class="locked-notice">
                    <h2>🔒 THE DAY HAS BEEN LOCKED BY MASTER</h2>
                    <p>For unlock it please submit request to the Captain.</p>
                    <a href="javascript:history.back()" class="btn-back">⬅ Go Back</a>
                </div>
            <?php else: ?>

                <div class="table-section">
                    <h3>📊 Running Hours Report</h3>
                    <form method="POST" action="save-running.php">
                        <input type="hidden" name="log_date" value="<?= htmlspecialchars($date) ?>">
                        <table class="styled-table">
                            <thead>
                                <tr>
                                    <th>Generator</th>
                                    <th>Start Time</th>
                                    <th>End Time</th>
                                    <th>Hours Today</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                // show default machines
                                foreach ($default_machines as $gen) {
                                    $r = $records[$gen] ?? null;
                                    $duration = $r ? floor($r['duration_min'] / 60) . "h " . ($r['duration_min'] % 60) . "m" : "00h00m";
                                    echo "<tr>
                                    <td>" . htmlspecialchars($gen) . " <input type='hidden' name='generator_name[]' value='" . htmlspecialchars($gen) . "'></td>
                                    <td><input type='time' name='start_time[]' value='" . ($r['start_time'] ?? "") . "'></td>
                                    <td><input type='time' name='end_time[]' value='" . ($r['end_time'] ?? "") . "'></td>
                                    <td><strong>$duration</strong></td>
                                </tr>";
                                }
                                // show any other custom machines found in runninghours
                                foreach ($records as $gen => $r) {
                                    if (!in_array($gen, $default_machines)) {
                                        $duration = floor($r['duration_min'] / 60) . "h " . ($r['duration_min'] % 60) . "m";
                                        echo "<tr>
                                        <td>" . htmlspecialchars($gen) . " <input type='hidden' name='generator_name[]' value='" . htmlspecialchars($gen) . "'></td>
                                        <td><input type='time' name='start_time[]' value='" . ($r['start_time'] ?? "") . "'></td>
                                        <td><input type='time' name='end_time[]' value='" . ($r['end_time'] ?? "") . "'></td>
                                        <td><strong>$duration</strong></td>
                                    </tr>";
                                    }
                                }
                                ?>
                            </tbody>
                        </table>
                        <div class="form-actions">
                            <button type="submit" class="btn btn-primary">💾 Save Running Hours</button>
                        </div>
                    </form>
                </div>

                <div class="table-section">
                    <h3>⚙️ Manage Default Machines</h3>

                    <form method="POST" action="manage-machine.php" class="manage-machine-form">
                        <input type="hidden" name="vessel_id" value="<?= htmlspecialchars($vessel_id) ?>">
                        <input type="text" name="machine_name" placeholder="Enter new machine name" required>
                        <button type="submit" name="action" value="add" class="btn btn-primary">➕ Add Machine</button>
                    </form>

                    <table class="styled-table">
                        <thead>
                            <tr>
                                <th>Machine Name</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($default_machines as $m): ?>
                                <tr>
                                    <td><?= htmlspecialchars($m) ?></td>
                                    <td>
                                        <form method="POST" action="manage-machine.php" style="display:inline;">
                                            <input type="hidden" name="vessel_id" value="<?= htmlspecialchars($vessel_id) ?>">
                                            <input type="hidden" name="machine_name" value="<?= htmlspecialchars($m) ?>">
                                            <button type="submit" name="action" value="delete" class="btn btn-danger">🗑 Remove</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
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
                        <p>Hey, <b><?php echo htmlspecialchars($username); ?></b></p>
                        <small class="text-muted"><?php echo htmlspecialchars($role); ?></small>
                    </div>
                    <div class="profile-photo"><img src="image/blankProf.png" alt="Profile Photo"></div>
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
        // Visa controller JS
        document.addEventListener("DOMContentLoaded", () => {
            document.querySelectorAll("tr").forEach(row => {
                const chief = row.querySelector('input[data-type="chief"]');
                const master = row.querySelector('input[data-type="master"]');
                const lockBtn = row.querySelector(".lock-btn");

                if (chief && master && lockBtn) {
                    function updateLockBtn() {
                        lockBtn.disabled = !(chief.checked && master.checked);
                    }
                    chief.addEventListener("change", updateLockBtn);
                    master.addEventListener("change", updateLockBtn);
                }
            });

            // Auto-zoom page to 90%
            document.body.style.zoom = "100%";
        });

    </script>
    <script>
        // Apply filter with reload
        document.getElementById("filterBtn").addEventListener("click", () => {
            const date = document.getElementById("datePick").value;
            const search = document.getElementById("searchBox").value;
            window.location = `navigation-report.php?date=${encodeURIComponent(date)}&search=${encodeURIComponent(search)}`;
        });

        // Live search in table
        document.getElementById("searchBox").addEventListener("input", function () {
            const term = this.value.toLowerCase();
            document.querySelectorAll("#navTable tr").forEach(row => {
                const text = row.innerText.toLowerCase();
                row.style.display = text.includes(term) ? "" : "none";
            });
        });
    </script>
    <script>
        function toMinutes(hhmm) {
            if (!hhmm) return null;
            const [h, m] = hhmm.split(":").map(Number);
            if (isNaN(h) || isNaN(m)) return null;
            return h * 60 + m;
        }

        function calcDuration(start, end) {
            let s = toMinutes(start);
            let e = toMinutes(end);
            if (s === null || e === null) return null;

            let diff = e - s;
            if (diff < 0) diff += 24 * 60; // handle overnight
            return diff;
        }

        function fmtHM(mins) {
            if (mins === null) return "00h00m";
            const h = Math.floor(mins / 60);
            const m = mins % 60;
            return String(h).padStart(2, "0") + "h" + String(m).padStart(2, "0") + "m";
        }

        // Attach listeners to all time inputs
        document.addEventListener("DOMContentLoaded", () => {
            const rows = document.querySelectorAll("tbody tr");
            rows.forEach((row, idx) => {
                const start = row.querySelector(`#start-${idx}`);
                const end = row.querySelector(`#end-${idx}`);
                const hours = row.querySelector(`#hours-${idx}`);

                function update() {
                    const mins = calcDuration(start.value, end.value);
                    hours.textContent = fmtHM(mins);
                }

                start.addEventListener("change", update);
                end.addEventListener("change", update);
            });
        });
    </script>



</body>

</html>