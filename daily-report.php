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

// Get last 14 days with any logs
$sql = "
SELECT DISTINCT log_date 
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
ORDER BY log_date DESC
LIMIT 14
";
$stmt = $pdo->prepare($sql);
$stmt->execute([':vessel_id' => $vessel_id]);
$dates = $stmt->fetchAll(PDO::FETCH_COLUMN);

// Check if this day is closed
$q = $pdo->prepare("SELECT status FROM dailystatus WHERE vessel_id=? AND log_date=? LIMIT 1");
$q->execute([$vessel_id, $log_date]);
$status = $q->fetchColumn();

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
        background-color: var(--ink);
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

    .toast {
        margin-top: 10px;
        padding: 10px 14px;
        border-radius: var(--border-radius-1);
        font-weight: 500;
        animation: fadeOut 3s forwards;
    }

    .toast-success {
        background: var(--color-success);
        color: white;
    }

    .toast-error {
        background: var(--color-danger);
        color: white;
    }

    @keyframes fadeOut {

        0%,
        80% {
            opacity: 1;
        }

        100% {
            opacity: 0;
            display: none;
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
                        <a href="daily-report.php" class="active">Activity Log</a>
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
                <a href="login.php" class="logout"><span class="material-icons-sharp">logout</span>
                    <h3>LogOut</h3>
                </a>
            </div>
        </aside>

        <!-- Main Content -->
        <main>

            <div class="availability-table">
                <h2>Activities & Events</h2>

                <!-- Filters -->
                <form method="GET" class="controls">
                    <!-- Date -->
                    <div class="date-input">
                        <label for="datePick"><strong>Date</strong></label>
                        <input type="date" id="datePick" name="date"
                            value="<?php echo $_GET['date'] ?? date('Y-m-d'); ?>">
                        <button type="submit" class="btn btn-primary">🔍</button>
                    </div>

                    <!-- Toggles -->
                    <div class="toggles">
                        <label>
                            Activities
                            <div class="switch">
                                <input type="checkbox" id="tgActivities" name="show_activity">
                                <span class="slider"></span>
                            </div>
                        </label>

                        <label>
                            Events
                            <div class="switch">
                                <input type="checkbox" id="tgEvents" name="show_event">
                                <span class="slider"></span>
                            </div>
                        </label>
                    </div>




                    <!-- Search + Buttons -->
                    <div class="search-row">
                        <input type="search" id="searchBox" name="search" placeholder="Search...">
                        <button type="submit" class="btn btn-primary">🔍</button>
                        <a class="btn btn-new" href="new-activity.php">➕ New Activity</a>

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
                    <?php if (isset($_GET['success']) && $_GET['success'] === 'deleted'): ?>
                        <div class="toast toast-success">🗑 Activity deleted successfully</div>
                    <?php elseif (isset($_GET['error']) && $_GET['error'] === 'locked'): ?>
                        <div class="toast toast-error">⚠️ Cannot delete: this day has been locked.</div>
                    <?php elseif (isset($_GET['error'])): ?>
                        <div class="toast toast-error">❌ Failed to delete activity.</div>
                    <?php endif; ?>

                    <table>
                        <thead>
                            <tr>
                                <th>No.</th>
                                <th>Start Time</th>
                                <th>End Time</th>
                                <th>Title</th>
                                <th>Description</th>
                                <th>Category</th>
                                <th>Duration (min)</th>
                                <th>Assigned By</th>
                                <th>Assigned To</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody id="activityTableBody">
                            <?php
                            $date = $_GET['date'] ?? date("Y-m-d");
                            $search = $_GET['search'] ?? '';

                            $sql = "
            SELECT a.*, 
                   ub.full_name AS assigned_by_name,
                   ct.full_name AS assigned_to_name
            FROM activitylogs a
            LEFT JOIN users ub ON a.assigned_by = ub.user_id
            LEFT JOIN crewlist ct ON a.assigned_to = ct.id
            WHERE a.vessel_id=? AND a.log_date=?";
                            $params = [$vessel_id, $date];

                            if ($search) {
                                $sql .= " AND (a.title LIKE ? OR a.description LIKE ?)";
                                $params[] = "%$search%";
                                $params[] = "%$search%";
                            }

                            $sql .= " ORDER BY a.start_time ASC";
                            $stmt = $pdo->prepare($sql);
                            $stmt->execute($params);
                            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

                            if ($rows) {
                                $i = 1;
                                foreach ($rows as $r) {
                                    echo "<tr>
                        <td>{$i}</td>
                        <td>" . htmlspecialchars($r['start_time']) . "</td>
                        <td>" . htmlspecialchars($r['end_time']) . "</td>
                        <td>" . htmlspecialchars($r['title']) . "</td>
                        <td>" . htmlspecialchars($r['description']) . "</td>
                        <td>" . htmlspecialchars($r['category']) . "</td>
                        <td>" . htmlspecialchars($r['duration_min']) . "</td>
                        <td>" . ($r['assigned_by_name'] ? htmlspecialchars($r['assigned_by_name']) : '-') . "</td>
                        <td>" . ($r['assigned_to_name'] ? htmlspecialchars($r['assigned_to_name']) : '-') . "</td>
                        <td>
                            
                            <a href='delete-activity.php?id={$r['activity_id']}' class='btn btn-accent'
                               onclick=\"return confirm('Delete this record?');\"> 🗑 Delete</a>
                        </td>
                      </tr>";
                                    $i++;
                                }
                            } else {
                                echo "<tr><td colspan='10' style='text-align:center; color:var(--muted);'>
                    No records for selected filters.
                  </td></tr>";
                            }
                            ?>
                        </tbody>
                    </table>
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
            document.body.style.zoom = "99%";
        });
    </script>
    <script>
        document.addEventListener("DOMContentLoaded", () => {
            const tgActivities = document.getElementById("tgActivities");
            const tgEvents = document.getElementById("tgEvents");
            const searchBox = document.getElementById("searchBox"); // your search input
            const tbody = document.getElementById("activityTableBody"); // tbody must have id
            const rows = tbody.querySelectorAll("tr");

            function filterRows() {
                const showAct = tgActivities.checked;
                const showEvt = tgEvents.checked;
                const searchText = searchBox.value.toLowerCase();

                rows.forEach(row => {
                    const rowText = row.textContent.toLowerCase(); // ✅ all cells in row
                    const type = row.querySelector("td:nth-child(6)")?.textContent.trim().toUpperCase() || "";

                    // Filter by type
                    let matchType = true;
                    if (showAct && !showEvt) {
                        matchType = (type === "ACTIVITY");
                    } else if (!showAct && showEvt) {
                        matchType = (type === "EVENT");
                    }

                    // Filter by search across all columns
                    let matchSearch = !searchText || rowText.includes(searchText);

                    row.style.display = (matchType && matchSearch) ? "" : "none";
                });
            }

            tgActivities.addEventListener("change", filterRows);
            tgEvents.addEventListener("change", filterRows);
            searchBox.addEventListener("keyup", filterRows);

            // Run once at start
            filterRows();
        });
    </script>


</body>

</html>