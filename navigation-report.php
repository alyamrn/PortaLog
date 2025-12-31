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
$vessel_id = $_SESSION['vessel_id'] ?? null;

$date = $_GET['date'] ?? date("Y-m-d");
$search = $_GET['search'] ?? '';

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



// build query
$sql = "SELECT * FROM navigationreports WHERE vessel_id=? AND log_date=?";
$params = [$vessel_id, $date];

if ($search !== '') {
    $sql .= " AND (destination LIKE ? OR remarks LIKE ? OR weather LIKE ?)";
    $like = "%$search%";
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}

$sql .= " ORDER BY report_time ASC, nav_id ASC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Check if this day is closed
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

    /* Arrange filters inline - smooth responsive layout */
    .controls {
        display: flex;
        align-items: center;
        justify-content: space-between;
        flex-wrap: wrap;
        gap: 16px;
        padding: 10px 14px;
        background: var(--card);
        border: 1px solid var(--line);
        border-radius: var(--border-radius-2);
    }

    /* Date input */
    .date-input {
        display: flex;
        align-items: center;
        gap: 8px;
    }

    .date-input label {
        font-weight: 600;
        color: var(--ink);
        font-size: 0.9rem;
    }

    .date-input input {
        padding: 6px 12px;
        border: 1px solid var(--line);
        border-radius: var(--border-radius-1);
        font-size: 0.9rem;
        background: var(--color-primary);
        color: var(--ink);
        transition: border-color 0.2s, box-shadow 0.2s;
    }

    .date-input input:focus {
        border-color: var(--accent);
        box-shadow: 0 0 4px var(--accent);
        outline: none;
    }

    /* Toggle container */
    .toggles {
        display: flex;
        gap: 20px;
        align-items: center;
    }

    /* Label text */
    .toggles label {
        display: flex;
        align-items: center;
        gap: 8px;
        font-size: 0.95rem;
        color: var(--ink);
        cursor: pointer;
    }

    /* Switch base */
    .switch {
        position: relative;
        display: inline-block;
        width: 40px;
        height: 20px;
    }

    .switch input {
        opacity: 0;
        width: 0;
        height: 0;
    }

    .slider {
        position: absolute;
        cursor: pointer;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background-color: #d3d6da;
        /* light grey when off */
        transition: 0.4s;
        border-radius: 20px;
    }

    .slider:before {
        position: absolute;
        content: "";
        height: 16px;
        width: 16px;
        left: 2px;
        bottom: 2px;
        background-color: #fff;
        border-radius: 50%;
        transition: 0.4s;
    }

    /* Checked = green */
    .switch input:checked+.slider {
        background-color: var(--color-success);
    }

    .switch input:checked+.slider:before {
        transform: translateX(20px);
    }


    /* Search + buttons row */
    .search-row {
        display: flex;
        align-items: center;
        gap: 10px;
        flex: 1;
        justify-content: flex-end;
    }

    .search-row input {
        padding: 6px 12px;
        border: 1px solid var(--line);
        border-radius: var(--border-radius-1);
        font-size: 0.9rem;
        width: 220px;
        transition: border-color 0.2s, box-shadow 0.2s;
    }

    .search-row input:focus {
        border-color: var(--accent);
        box-shadow: 0 0 4px var(--accent);
        outline: none;
    }


    /* Filter button */
    .btn-primary {
        background-color: var(--accent);
        color: #fff;
        padding: 6px 12px;
        border-radius: var(--border-radius-1);
        font-weight: 600;
        text-decoration: none;
        transition: background 0.2s;
    }

    .btn-primary:hover {
        background-color: var(--color-success);
    }

    /* New Activity / Event buttons */
    .btn-new {
        background-color: var(--color-success);
        color: #fff;
        padding: 6px 14px;
        border-radius: var(--border-radius-1);
        font-weight: 600;
        text-decoration: none;
        transition: background 0.2s;
    }

    .btn-new:hover {
        background-color: darkgreen;
    }

    /* Table styles */
    /* Wrapper */
    .table-responsive {
        width: 100%;
        overflow-x: auto;
        /* enable horizontal scroll if needed */
        overflow-y: auto;
        /* enable vertical scroll if needed */
        -webkit-overflow-scrolling: touch;
        /* smooth on mobile */
    }

    /* Table adjusts to container */
    .table-responsive table {
        width: 100%;
        min-width: 100%;
        /* optional: ensure readability */
        border-collapse: collapse;
        table-layout: auto;
        /* let browser auto-resize columns */
    }

    /* Table cells */
    .table-responsive th,
    .table-responsive td {
        padding: 8px 12px;
        border: 1px solid var(--line);
        text-align: center;
        white-space: nowrap;
        /* prevent text wrapping (optional) */
    }

    /* Navigation Report Table Only */
    .nav-table {
        width: 100%;
        /* keep inside container */
        table-layout: auto;
        /* auto distribute space */
        border-collapse: collapse;
    }

    .nav-table th,
    .nav-table td {
        padding: 8px 10px;
        border-bottom: 1px solid var(--line);
        font-size: 0.85rem;
        white-space: nowrap;
        /* prevent wrapping everywhere */
    }

    /* shrink course + speed */
    .nav-table th:nth-child(5),
    .nav-table td:nth-child(5),
    .nav-table th:nth-child(6),
    .nav-table td:nth-child(6) {
        width: 60px;
    }

    /* shrink draught columns */
    .nav-table th:nth-child(7),
    .nav-table td:nth-child(7),
    .nav-table th:nth-child(8),
    .nav-table td:nth-child(8) {
        width: 80px;
    }

    /* remarks column wraps */
    .nav-table th:nth-child(14),
    .nav-table td:nth-child(14) {
        max-width: 160px;
        white-space: normal;
        word-wrap: break-word;
    }

    .navigation-table {
        margin-top: 20px;
        background: var(--color-white);
        padding: var(--card-padding);
        border-radius: var(--card-border-radius);
        box-shadow: var(--box-shadow);
        width: 100%;
        overflow-x: auto;
    }

    .navigation-table thead {
        background: var(--color-primary);
        color: var(--color-white);
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
                        <a href="navigation-report.php" class="active">Navigation</a>
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
                <a href="login.php" class="logout"><span class="material-icons-sharp">logout</span>
                    <h3>LogOut</h3>
                </a>
            </div>
        </aside>

        <!-- Main Content -->
        <main>
            <div class="navigation-table">
                <h2>Navigation Report</h2>

                <!-- Controls -->
                <!-- Controls -->
                <form method="GET" class="controls">
                    <div class="date-input">
                        <label for="datePick"><strong>Date</strong></label>
                        <input type="date" id="datePick" name="date" value="<?php echo htmlspecialchars($date); ?>">
                    </div>

                    <div class="search-row">
                        <input type="search" id="searchBox" name="search"
                            placeholder="Search destination / remarks / weather..."
                            value="<?php echo htmlspecialchars($search); ?>">
                        <button type="submit" class="btn btn-primary">🔍 Filter</button>
                        <a class="btn btn-new" href="new-navigation.php">➕ New Navigation</a>
                    </div>
                </form>

                <?php if ($status === "LOCKED"): ?>
                    <div class="locked-notice">
                        <h2>🔒 THE DAY HAS BEEN LOCKED BY MASTER</h2>
                        <p>For unlock it please submit request to the Captain.</p>
                        <a href="javascript:history.back()" class="btn-back">⬅ Go Back</a>
                    </div>
                <?php else: ?>
                    <!-- Table -->
                    <div class="table-responsive">
                        <table class="nav-table">
                            <thead>
                                <tr>
                                    <th>No.</th>
                                    <th>Time</th>
                                    <th>Latitude</th>
                                    <th>Longitude</th>
                                    <th>Course (°)</th>
                                    <th>Speed (kn)</th>
                                    <th>Draught Fwd / Aft</th>
                                    <th>Weather</th>
                                    <th>Sea</th>
                                    <th>Visibility (nm)</th>
                                    <th>Destination</th>
                                    <th>ETA</th>
                                    <th>Remarks</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody id="navTable">
                                <?php if ($rows):
                                    $i = 1;
                                    foreach ($rows as $r): ?>
                                        <tr>
                                            <td><?php echo $i++; ?></td>
                                            <td><?php echo htmlspecialchars($r['report_time']); ?></td>
                                            <td><?php echo htmlspecialchars($r['latitude']); ?></td>
                                            <td><?php echo htmlspecialchars($r['longitude']); ?></td>
                                            <td><?php echo htmlspecialchars($r['course_deg']); ?></td>
                                            <td><?php echo htmlspecialchars($r['speed_kn']); ?></td>
                                            <td><?php echo htmlspecialchars($r['draught_fwd_m']) . ' / ' . htmlspecialchars($r['draught_aft_m']); ?>
                                            </td>
                                            <td><?php echo htmlspecialchars($r['weather']); ?></td>
                                            <td><?php echo htmlspecialchars($r['sea_state']); ?></td>
                                            <td><?php echo htmlspecialchars($r['visibility_nm']); ?></td>
                                            <td><?php echo htmlspecialchars($r['destination']); ?></td>
                                            <td><?php echo htmlspecialchars($r['eta']); ?></td>
                                            <td><?php echo htmlspecialchars($r['remarks']); ?></td>
                                            <td>
                                                <a href="edit-navigation.php?id=<?php echo (int) $r['nav_id']; ?>"
                                                    class="btn btn-primary">Edit</a>
                                                <a href="delete-navigation.php?id=<?php echo (int) $r['nav_id']; ?>"
                                                    class="btn btn-accent"
                                                    onclick="return confirm('Delete this record?');">Delete</a>
                                            </td>
                                        </tr>
                                    <?php endforeach;
                                else: ?>
                                    <tr>
                                        <td colspan="14" style="text-align:center;color:var(--muted)">No records found</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
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
        // Live search in table
        document.getElementById("searchBox").addEventListener("input", function() {
            const term = this.value.toLowerCase();
            document.querySelectorAll("#navTable tr").forEach(row => {
                const text = row.innerText.toLowerCase();
                row.style.display = text.includes(term) ? "" : "none";
            });
        });
    </script>


</body>

</html>