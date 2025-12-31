<?php
session_start();
require_once "db_connect.php";

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}
$username = $_SESSION['full_name'];
$role = $_SESSION['role'];
$vessel_id = $_SESSION['vessel_id'] ?? null;
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $log_date = $_POST['log_date'] ?? date("Y-m-d");
    $report_time = $_POST['report_time'] ?? null;
    $latitude = $_POST['latitude'] ?? null;
    $longitude = $_POST['longitude'] ?? null;
    $course_deg = $_POST['course_deg'] ?? null;
    $speed_kn = $_POST['speed_kn'] ?? null;
    $draught_fwd_m = $_POST['draught_fwd_m'] ?? null;
    $draught_aft_m = $_POST['draught_aft_m'] ?? null;
    $weather = $_POST['weather'] ?? null;
    $sea_state = $_POST['sea_state'] ?? null;
    $visibility_nm = $_POST['visibility_nm'] ?? null;
    $destination = $_POST['destination'] ?? null;
    $eta = $_POST['eta'] ?? null;
    $remarks = $_POST['remarks'] ?? null;

    if (!$vessel_id) {
        $errors[] = "Vessel ID not found in session.";
    }
    if (!$report_time) {
        $errors[] = "Report time is required.";
    }

    if (!$errors) {
        $sql = "INSERT INTO navigationreports
                (vessel_id, log_date, report_time, latitude, longitude, course_deg, speed_kn, draught_fwd_m, draught_aft_m, weather, sea_state, visibility_nm, destination, eta, remarks)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            $vessel_id,
            $log_date,
            $report_time,
            $latitude,
            $longitude,
            $course_deg,
            $speed_kn,
            $draught_fwd_m,
            $draught_aft_m,
            $weather,
            $sea_state,
            $visibility_nm,
            $destination,
            $eta,
            $remarks
        ]);

        $_SESSION['success'] = "Navigation record added successfully.";
        header("Location: navigation-report.php?date=" . urlencode($log_date));
        exit;
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

    /*form style*/
    .form-box {
        background: var(--card);
        border-radius: var(--border-radius-2);
        padding: 24px 28px;
        box-shadow: var(--box-shadow);
        max-width: 700px;
        margin: 40px auto;
    }

    .form-box h2 {
        margin-bottom: 18px;
        color: var(--ink);
        font-weight: 700;
        text-align: center;
    }

    /* Grid layout for inputs */
    .form-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 18px;
    }

    .form-group {
        display: flex;
        flex-direction: column;
    }

    .form-group.full {
        grid-column: 1 / -1;
        /* full width row */
    }

    .form-group label {
        margin-bottom: 6px;
        font-size: 0.9rem;
        font-weight: 600;
        color: var(--ink);
    }

    .form-group input,
    .form-group textarea {
        padding: 10px 12px;
        border: 1px solid var(--line);
        border-radius: var(--border-radius-1);
        font-size: 0.95rem;
        background: #fff;
        color: #111;
        /* darker text */
        transition: border-color 0.2s ease, box-shadow 0.2s ease;
    }

    .form-group input:focus,
    .form-group textarea:focus {
        border-color: var(--accent);
        box-shadow: 0 0 4px var(--accent);
        outline: none;
    }

    /* Buttons */
    .form-actions {
        display: flex;
        justify-content: center;
        gap: 14px;
        margin-top: 20px;
    }

    .btn-submit {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 10px 20px;
        background-color: var(--accent);
        color: #fff;
        font-weight: 600;
        border: none;
        border-radius: var(--border-radius-1);
        cursor: pointer;
        transition: background 0.3s ease, transform 0.2s;
    }

    .btn-submit:hover {
        background-color: #4a54d1;
        transform: translateY(-2px);
    }

    .btn-cancel {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 10px 20px;
        background-color: var(--color-danger);
        color: #fff;
        font-weight: 600;
        border-radius: var(--border-radius-1);
        text-decoration: none;
        transition: background 0.3s ease, transform 0.2s;
    }

    .btn-cancel:hover {
        background-color: #b71c1c;
        transform: translateY(-2px);
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
                <a href="maintainence-schedule.php"><span class="material-icons-sharp">schedule</span>
                    <h3>Maintanence Schedule</h3>
                </a>
                <a href="history.php"><span class="material-icons-sharp">history_edu</span>
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

        <!-- Main Content -->
        <main class="form-box">
            <h2>Add New Navigation Report</h2>

            <?php if ($errors): ?>
                <div class="errors">
                    <?php foreach ($errors as $e)
                        echo "<p>• " . htmlspecialchars($e) . "</p>"; ?>
                </div>
            <?php endif; ?>

            <form method="POST">
                <div class="form-grid">
                    <div class="form-group">
                        <label>Date</label>
                        <input type="date" name="log_date" value="<?= date('Y-m-d') ?>" required>
                    </div>

                    <div class="form-group">
                        <label>Report Time</label>
                        <input type="time" name="report_time" required>
                    </div>

                    <div class="form-group">
                        <label>Latitude</label>
                        <input type="text" name="latitude" placeholder="e.g., 1.234567">
                    </div>

                    <div class="form-group">
                        <label>Longitude</label>
                        <input type="text" name="longitude" placeholder="e.g., 103.456789">
                    </div>

                    <div class="form-group">
                        <label>Course (°)</label>
                        <input type="number" step="0.1" name="course_deg">
                    </div>

                    <div class="form-group">
                        <label>Speed (kn)</label>
                        <input type="number" step="0.1" name="speed_kn">
                    </div>

                    <div class="form-group">
                        <label>Draught FWD (m)</label>
                        <input type="number" step="0.01" name="draught_fwd_m">
                    </div>

                    <div class="form-group">
                        <label>Draught AFT (m)</label>
                        <input type="number" step="0.01" name="draught_aft_m">
                    </div>

                    <div class="form-group">
                        <label>Weather</label>
                        <input type="text" name="weather" placeholder="e.g., Clear / Rainy">
                    </div>

                    <div class="form-group">
                        <label>Sea State</label>
                        <input type="text" name="sea_state" placeholder="e.g., Calm / Rough">
                    </div>

                    <div class="form-group">
                        <label>Visibility (nm)</label>
                        <input type="number" step="0.1" name="visibility_nm">
                    </div>

                    <div class="form-group">
                        <label>Destination</label>
                        <input type="text" name="destination">
                    </div>

                    <div class="form-group">
                        <label>ETA</label>
                        <input type="time" name="eta">
                    </div>

                    <div class="form-group full">
                        <label>Remarks</label>
                        <textarea name="remarks" rows="3"></textarea>
                    </div>
                </div>

                <div class="form-actions">
                    <button type="submit" class="btn-submit">✔ Save</button>
                    <a href="navigation-report.php" class="btn-cancel">✖ Cancel</a>
                </div>
            </form>
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
            <div class="reminders">
                <div class="header">
                    <h2>Tips</h2>
                    <span class="material-icons-sharp">notifications_none</span>
                </div>
                <div class="notification">
                    <div class="icon"><span class="material-icons-sharp">info</span></div>
                    <div class="content">
                        <div class="info">
                            <h3>Check visas before locking</h3>
                            <small class="text-muted">Both Chief Eng. and Master must sign</small>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="index.js"></script>
    <script src="appointment.js"></script>
    <script src="clock.js"></script>
    <script src="local-storage.js"></script>
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


</body>

</html>