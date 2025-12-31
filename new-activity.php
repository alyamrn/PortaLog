<?php
session_start();
require_once "db_connect.php";

// ✅ Ensure user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

// === Session Variables ===
$vessel_id = $_SESSION['vessel_id'] ?? null;
$user_id = $_SESSION['user_id'];
$username = $_SESSION['full_name'];
$role = $_SESSION['role'];

$today = date('Y-m-d');

// =============================
// 🔹 FETCH CURRENT CREW FROM POB (active stints)
// =============================
// Use `pob_stints` joined to `crewlist` so we reflect crew actually onboard.
$pobCrew = $pdo->prepare(
        "SELECT DISTINCT s.person_id, COALESCE(c.full_name, '') AS full_name, COALESCE(s.crew_role, c.crew_role) AS crew_role, s.disembark_date
         FROM pob_stints s
         LEFT JOIN crewlist c ON c.id = s.person_id
         WHERE s.vessel_id = ?
             AND s.embark_date <= CURDATE()
             AND (s.disembark_date IS NULL OR s.disembark_date >= CURDATE())
         ORDER BY COALESCE(c.full_name, '')"
);
$pobCrew->execute([$vessel_id]);
$crew = $pobCrew->fetchAll(PDO::FETCH_ASSOC);


// =============================
// 🔹 FORM SUBMISSION LOGIC
// =============================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = trim($_POST['title']);
    $description = trim($_POST['description']);
    $category = $_POST['category'];
    $start_time = $_POST['start_time'];
    $end_time = $_POST['end_time'];
    $assigned_to = $_POST['assigned_to'];

    // === Validate required fields ===
    if (empty($title) || empty($category) || empty($start_time) || empty($end_time) || empty($assigned_to)) {
        $error = "Please fill in all required fields.";
    } else {
        // === Insert new activity ===
        $insert = $pdo->prepare("
            INSERT INTO activitylogs 
            (vessel_id, log_date, title, description, category, start_time, end_time, assigned_by, assigned_to)
            VALUES (?, CURDATE(), ?, ?, ?, ?, ?, ?, ?)
        ");
        $insert->execute([
            $vessel_id,
            $title,
            $description,
            $category,
            $start_time,
            $end_time,
            $user_id,
            $assigned_to
        ]);

        header("Location: daily-report.php?success=added");
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

    /* Form box wrapper */
    .form-box {
        background: var(--card);
        /* use your card color (lighter than bg) */
        border-radius: var(--border-radius-2);
        padding: 24px 28px;
        box-shadow: var(--box-shadow);
        max-width: 500px;
        margin: auto;
    }

    /* Headline */
    .form-box h2 {
        margin-bottom: 18px;
        color: var(--ink);
        font-weight: 700;
        text-align: center;
    }

    /* Form groups */
    .form-group {
        display: flex;
        flex-direction: column;
        margin-bottom: 14px;
    }

    .form-group label {
        margin-bottom: 6px;
        font-size: 0.9rem;
        font-weight: 600;
        color: var(--ink);
        /* softer label color */
    }

    /* Inputs */
    .form-group input,
    .form-group textarea {
        padding: 10px 12px;
        border: 1px solid var(--line);
        border-radius: var(--border-radius-1);
        font-size: 0.95rem;
        background: #fff;
        /* keep inputs white */
        color: black;
        /* ensure text is dark enough */
        font-weight: 500;
        /* make it bolder for readability */
        transition: border-color 0.2s ease, box-shadow 0.2s ease;
    }

    /* Placeholder text */
    .form-group input::placeholder,
    .form-group textarea::placeholder {
        color: var(--ink);
        /* softer grey for placeholder */
        opacity: 1;
        /* make sure it's visible */
    }


    /* Actions */
    .form-actions {
        display: flex;
        justify-content: center;
        gap: 14px;
        margin-top: 20px;
    }

    /* Submit Button */
    .btn-submit {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 10px 18px;
        background-color: var(--accent);
        color: #0fe83eff;
        font-weight: 600;
        border: none;
        border-radius: var(--border-radius-1);
        cursor: pointer;
        transition: background 0.3s ease, transform 0.2s;
    }

    .btn-submit:hover {
        background-color: #4a54d1;
        /* darker accent shade */
        transform: translateY(-2px);
    }

    /* Cancel Button */
    .btn-cancel {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 10px 18px;
        background-color: var(--color-danger);
        color: #fff;
        font-weight: 600;
        border-radius: var(--border-radius-1);
        text-decoration: none;
        transition: background 0.3s ease, transform 0.2s;
    }

    .btn-cancel:hover {
        background-color: #b71c1c;
        /* darker red */
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
                        <a href="daily-report.php" class="active">Activity Log</a>
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
        <main>
            <div class="form-box">
                <h2>Add New Activity</h2>

                <?php if (!empty($error)): ?>
                    <div class="errors">
                        <div>• <?= htmlspecialchars($error) ?></div>
                    </div>
                <?php endif; ?>


                <?php if (empty($crew)): ?>
                    <div class="errors" style="margin-bottom:1rem;">
                        ⚠️ No onboard crew found for this vessel.<br>
                        Please update the <b>POB List</b> before assigning an activity.
                    </div>
                <?php endif; ?>

                <form method="POST" id="activityForm">
                    <div class="form-grid">
                        <div class="form-group">
                            <label>Date</label>
                            <input type="date" name="log_date" required value="<?= htmlspecialchars($today) ?>">
                        </div>

                        <div class="form-group">
                            <label>Title</label>
                            <input type="text" name="title" placeholder="e.g., Cargo operations at Berth 3" required>
                        </div>

                        <div class="form-group">
                            <label>Category</label>
                            <select name="category">
                                <option value="">-- Select category (optional) --</option>
                                <option value="ACTIVITY">Activity</option>
                                <option value="EVENT">Event</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label>Assigned To</label>
                            <select name="assigned_to" <?= empty($crew) ? 'disabled' : '' ?>>
                                <option value="">-- Not assigned --</option>
                                <?php foreach ($crew as $c): ?>
                                    <option value="<?= (int) $c['person_id'] ?>">
                                        <?= htmlspecialchars($c['full_name']) ?>
                                        (<?= htmlspecialchars($c['crew_role'] ?? 'N/A') ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="help">
                                Optional: assign to a crew member currently onboard this vessel.
                            </div>
                        </div>

                        <div class="form-group">
                            <label>Start Time</label>
                            <input type="time" name="start_time" required id="st">
                        </div>

                        <div class="form-group">
                            <label>End Time</label>
                            <input type="time" name="end_time" required id="et">
                        </div>

                        <div class="form-group full">
                            <label>Description</label>
                            <textarea name="description" rows="3" placeholder="Short description of the activity..."
                                required></textarea>
                        </div>

                        <div class="form-group" style="grid-column:1/-1;display:flex;justify-content:flex-end">
                            <div class="duration-badge">
                                Duration: <span id="durLabel">0</span> min
                            </div>
                        </div>
                    </div>

                    <div class="form-actions">
                        <button type="submit" class="btn-submit" <?= empty($crew) ? 'disabled' : '' ?>>
                            <span class="material-icons-sharp">check_circle</span> Save Activity
                        </button>
                        <a href="daily-report.php" class="btn-cancel">
                            <span class="material-icons-sharp">close</span> Cancel
                        </a>
                    </div>
                </form>

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

</body>

</html>