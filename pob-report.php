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
$today = date("Y-m-d");

$date = $_GET['date'] ?? $today;
$search = $_GET['search'] ?? '';

/* =========================
   Build query (AS-OF model)
   - Show active stints as of $date
   - Join crewlist for person info
   ========================= */
$sql = "
SELECT
    s.stint_id,
    s.vessel_id,
    s.category,
    s.crew_role,
    s.embark_date,
    s.disembark_date,
    s.remarks,
    c.id           AS person_id,
    c.full_name,
    c.nationality,
    c.dob
FROM pob_stints s
JOIN crewlist c ON c.id = s.person_id
WHERE s.vessel_id = ?
  AND s.embark_date <= ?
  AND (s.disembark_date IS NULL OR s.disembark_date >= ?)
";
$params = [$vessel_id, $date, $date];

if ($search) {
    $sql .= " AND (
        c.full_name   LIKE ?
        OR c.nationality LIKE ?
        OR s.crew_role LIKE ?
        OR s.remarks   LIKE ?
    )";
    $like = "%$search%";
    $params = array_merge($params, [$like, $like, $like, $like]);
}

$sql .= " ORDER BY s.category, c.full_name";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

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

<?php
/* ===== LEGACY: Reuse yesterday snapshots (kept intact) =====
   Note: now that we use stints, this block typically won’t be used.
*/
if (isset($_POST['reuse_yesterday'])) {
    $today = date('Y-m-d');

    // Find the latest previous log_date for this vessel
    $q = $pdo->prepare("SELECT MAX(log_date) FROM pobpersons WHERE vessel_id=? AND log_date < ?");
    $q->execute([$vessel_id, $today]);
    $yesterday_date = $q->fetchColumn();

    if ($yesterday_date) {
        // Fetch yesterday’s POB list
        $q2 = $pdo->prepare("SELECT full_name, nationality, dob, category, crew_role, embark_date, disembark_date, remarks 
                             FROM pobpersons 
                             WHERE vessel_id=? AND log_date=?");
        $q2->execute([$vessel_id, $yesterday_date]);
        $yesterday_rows = $q2->fetchAll(PDO::FETCH_ASSOC);

        if ($yesterday_rows) {
            $insert = $pdo->prepare("INSERT INTO pobpersons 
                (vessel_id, log_date, full_name, nationality, dob, category, crew_role, embark_date, disembark_date, remarks)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

            foreach ($yesterday_rows as $row) {
                // check if already exists today
                $check = $pdo->prepare("SELECT COUNT(*) FROM pobpersons 
                                        WHERE vessel_id=? AND log_date=? AND full_name=?");
                $check->execute([$vessel_id, $today, $row['full_name']]);
                if ($check->fetchColumn() == 0) {
                    $insert->execute([
                        $vessel_id,
                        $today,
                        $row['full_name'],
                        $row['nationality'],
                        $row['dob'],
                        $row['category'],
                        $row['crew_role'],
                        $row['embark_date'],
                        $row['disembark_date'],
                        $row['remarks']
                    ]);
                }
            }

            $_SESSION['success'] = "Yesterday’s POB copied into today successfully.";
            header("Location: pob-report.php?date=" . urlencode($today));
            exit;
        }
    } else {
        $errors[] = "No previous POB data available to reuse.";
    }
}
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

    /* Delete Button (Danger) */
    .btn-danger {
        background-color: var(--color-danger, #e53935);
        /* use theme danger or fallback red */
        color: #fff;
        padding: 6px 12px;
        border-radius: var(--border-radius-1);
        font-weight: 600;
        text-decoration: none;
        transition: background 0.2s, transform 0.2s;
        display: inline-flex;
        align-items: center;
        gap: 4px;
    }

    .btn-danger:hover {
        background-color: #c62828;
        /* darker red */
        transform: translateY(-1px);
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
                        <a href="pob-report.php" class="active">POB</a>
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
                <h2>Persons On Board (<?php echo htmlspecialchars($date); ?>)</h2>

                <!-- Filters -->
                <form method="GET" class="controls">
                    <!-- Date -->
                    <div class="date-input">
                        <label for="datePick"><strong>Date</strong></label>
                        <input type="date" id="datePick" name="date"
                            value="<?php echo $_GET['date'] ?? date('Y-m-d'); ?>">
                        <button type="submit" class="btn btn-primary">🔍</button>
                    </div>
                    <!-- Search + Buttons -->
                    <div class="search-row">
                        <input type="search" id="searchBox" name="search" placeholder="Search...">
                        <button type="submit" class="btn btn-primary">🔍</button>
                        <a id="reuseBtn" href="#" class="btn-new">♻ Reuse Yesterday’s POB</a>
                        <a class="btn btn-new" href="new-pob.php">➕ New POB</a>
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
                    <table id="pobTable">
                        <thead>
                            <tr>
                                <th>No.</th>
                                <th>Full Name</th>
                                <th>Nationality</th>
                                <th>DOB</th>
                                <th>Category</th>
                                <th>Crew Role</th>
                                <th>Embark</th>
                                <th>Disembark</th>
                                <th>Remarks</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody id="pobTable">
                            <?php if ($rows):
                                $i = 1;
                                foreach ($rows as $r): ?>
                                    <tr>
                                        <td><?php echo $i++; ?></td>
                                        <td><?php echo htmlspecialchars($r['full_name']); ?></td>
                                        <td><?php echo htmlspecialchars($r['nationality']); ?></td>
                                        <td><?php echo $r['dob']; ?></td>
                                        <td><?php echo $r['category']; ?></td>
                                        <td><?php echo htmlspecialchars($r['crew_role']); ?></td>
                                        <td><?php echo $r['embark_date']; ?></td>
                                        <td><?php echo $r['disembark_date']; ?></td>
                                        <td><?php echo htmlspecialchars($r['remarks']); ?></td>
                                        <td>
                                            <!-- delete now uses stint_id -->
                                            <a href="delete-stint.php?stint_id=<?php echo $r['stint_id']; ?>&date=<?php echo $date; ?>"
                                                class="btn-danger"
                                                onclick="return confirm('Are you sure you want to delete <?php echo htmlspecialchars($r['full_name']); ?>?');">
                                                🗑 Delete
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach;
                            else: ?>
                                <tr>
                                    <td colspan="10" style="text-align:center; color:var(--muted);">No records for this date.
                                    </td>
                                </tr>
                            <?php endif; ?>
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
                <div id="reminder-list"><!-- reminders will be loaded here --></div>
            </div>
        </div>
    </div>

    <script src="index.js"></script>
    <script src="appointment.js"></script>
    <script src="clock.js"></script>
    <script src="local-storage.js"></script>
    <script src="load-reminder.js"></script>
    <script>
        // Visa controller JS (unchanged)
        document.addEventListener("DOMContentLoaded", () => {
            document.querySelectorAll("tr").forEach(row => {
                const chief = row.querySelector('input[data-type="chief"]');
                const master = row.querySelector('input[data-type="master"]');
                const lockBtn = row.querySelector(".lock-btn");

                if (chief && master && lockBtn) {
                    function updateLockBtn() {
                        lockBtn.disabled = !(chief.checked && master.checked);
                    }
                    chief?.addEventListener("change", updateLockBtn);
                    master?.addEventListener("change", updateLockBtn);
                }
            });
            document.body.style.zoom = "100%";
        });
    </script>

    <script>
        // (kept as in your file – note the toggles tgActivities/tgEvents must exist to use this)
        document.addEventListener("DOMContentLoaded", () => {
            const tgActivities = document.getElementById("tgActivities");
            const tgEvents = document.getElementById("tgEvents");
            const searchBox = document.getElementById("searchBox");
            const tbody = document.getElementById("pobTable");
            const rows = tbody.querySelectorAll("tr");

            function filterRows() {
                const showAct = tgActivities?.checked ?? true;
                const showEvt = tgEvents?.checked ?? true;
                const searchText = (searchBox?.value || "").toLowerCase();

                rows.forEach(row => {
                    const rowText = row.textContent.toLowerCase();
                    const type = row.querySelector("td:nth-child(6)")?.textContent.trim().toUpperCase() || "";
                    let matchType = true;
                    if (tgActivities && tgEvents) {
                        if (showAct && !showEvt) matchType = (type === "ACTIVITY");
                        else if (!showAct && showEvt) matchType = (type === "EVENT");
                    }
                    let matchSearch = !searchText || rowText.includes(searchText);
                    row.style.display = (matchType && matchSearch) ? "" : "none";
                });
            }

            tgActivities?.addEventListener("change", filterRows);
            tgEvents?.addEventListener("change", filterRows);
            searchBox?.addEventListener("keyup", filterRows);
            filterRows();
        });
    </script>

    <script>
        // Legacy reuse (left as-is)
        document.addEventListener("DOMContentLoaded", () => {
            const reuseBtn = document.getElementById("reuseBtn");
            reuseBtn.addEventListener("click", (e) => {
                e.preventDefault();
                fetch("reuse-pob.php")
                    .then(res => res.json())
                    .then(data => {
                        if (!data.success) {
                            alert(data.error);
                            return;
                        }
                        const tbody = document.querySelector("#pobTable tbody") || document.getElementById("pobTable");
                        if (!tbody) return;
                        tbody.innerHTML = "";
                        let i = 1;
                        data.data.forEach(r => {
                            tbody.innerHTML += `
                            <tr>
                                <td>${i++}</td>
                                <td>${r.full_name}</td>
                                <td>${r.nationality ?? ""}</td>
                                <td>${r.dob ?? ""}</td>
                                <td>${r.category}</td>
                                <td>${r.crew_role ?? ""}</td>
                                <td>${r.embark_date ?? ""}</td>
                                <td>${r.disembark_date ?? ""}</td>
                                <td>${r.remarks ?? ""}</td>
                            </tr>`;
                        });
                        alert("Yesterday’s POB copied into today successfully.");
                    })
                    .catch(err => alert("Error: " + err));
            });
        });
    </script>
</body>

</html>