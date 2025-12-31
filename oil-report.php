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

// date handling
$date = $_GET['date'] ?? date("Y-m-d");

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



// fetch logs by category
$stmt = $pdo->prepare("SELECT * FROM oilrecordbook WHERE vessel_id=? AND log_date=? ORDER BY date_time");
$stmt->execute([$vessel_id, $date]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$part1 = array_filter($rows, fn($r) => strtoupper($r['category']) === 'PART1');
$part2 = array_filter($rows, fn($r) => strtoupper($r['category']) === 'PART2');

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

    /* Tabs */
    .tabs {
        display: flex;
        gap: 12px;
        margin-bottom: 12px;
    }

    .tab-btn {
        padding: 8px 14px;
        border: 1px solid var(--line);
        color: var(--color-dark);
        border-radius: 6px;
        cursor: pointer;
        background: var(--color-light);
        font-weight: 600;
    }

    .tab-btn.active {
        background: var(--color-primary);
        color: #fff;
    }

    .tab-content {
        display: none;
    }

    .tab-content.active {
        display: block;
    }

    .oil-table-wrap {
        overflow-x: auto;
        margin-bottom: 12px;
        border-radius: 15px;
    }

    .oil-table {
        width: 100%;
        min-width: 1000px;
        border-radius: 15px;
        border-collapse: collapse;
        font-size: 14px;
    }

    .oil-table th,
    .oil-table td {
        padding: 6px;
        border: 1px solid var(--line);
        text-align: center;
    }

    .oil-table th {
        background: var(--color-primary);
        color: #fff;
    }

    .oil-table input,
    .oil-table select {
        width: 100%;
        padding: 8px 10px;
        border: 1px solid var(--line);
        border-radius: 6px;
        font-size: 0.85rem;
        color: var(--color-dark);
        background: var(--color-white);
        transition: border-color 0.2s, box-shadow 0.2s, background 0.2s;
    }

    .oil-table input:focus,
    .oil-table select:focus {
        border-color: var(--color-primary);
        box-shadow: 0 0 5px var(--color-primary);
        outline: none;
        /* background stays the same */
    }


    .oil-table input::placeholder {
        color: var(--color-info-dark);
        opacity: 0.8;
    }

    .oil-remove-btn,
    .oil-delete-btn,
    .btn-save {
        padding: 6px 12px;
        border-radius: 6px;
        font-size: 0.8rem;
        font-weight: 600;
        cursor: pointer;
        border: 1px solid;
        transition: background 0.2s, transform 0.2s;
    }

    .oil-remove-btn {
        background: #ff5252;
        color: #fff;
        border-color: #e53935;
    }

    .oil-remove-btn:hover {
        background: #e53935;
        transform: translateY(-1px);
    }

    .oil-delete-btn {
        background: #c62828;
        color: #fff;
        border-color: #b71c1c;
    }

    .oil-delete-btn:hover {
        background: #b71c1c;
        transform: translateY(-1px);
    }

    .btn-save {
        background: var(--color-success);
        color: #fff;
        border-color: var(--color-success);
        margin-top: 10px;
    }

    .btn-save:hover {
        background: #178f75;
        transform: translateY(-1px);
    }

    .btn-new {
        display: inline-flex;
        align-items: center;
        margin-bottom: 5px;
        gap: 6px;
        padding: 8px 14px;
        background: var(--color-primary);
        color: #fff;
        border-radius: 6px;
        font-weight: 600;
        border: 1px solid var(--color-primary);
        cursor: pointer;
        transition: background 0.2s, transform 0.15s, box-shadow 0.2s;
    }

    .btn-new:hover {
        background: var(--color-success);
        border-color: var(--color-success);
        transform: translateY(-2px);
        box-shadow: 0 2px 6px rgba(0, 0, 0, 0.15);
    }

    .date-filter {
        display: flex;
        gap: 8px;
        align-items: center;
        margin-bottom: 1rem;
    }

    .date-filter input[type="date"] {
        padding: 6px 10px;
        border: 1px solid var(--line);
        border-radius: 6px;
    }

    .btn-secondary {
        padding: 6px 12px;
        border-radius: 6px;
        background: var(--color-primary);
        color: #fff;
        text-decoration: none;
    }

    .toast-message {
        position: fixed;
        top: 20px;
        right: 20px;
        background: #d4edda;
        color: #155724;
        border: 1px solid #c3e6cb;
        padding: 10px 16px;
        border-radius: 6px;
        font-weight: 600;
        box-shadow: 0 2px 6px rgba(0, 0, 0, 0.15);
        opacity: 0;
        transform: translateY(-20px);
        transition: opacity 0.5s ease, transform 0.5s ease;
        z-index: 9999;
    }

    /* Show animation */
    .toast-message.show {
        opacity: 1;
        transform: translateY(0);
    }

    /* Success style */
    .toast-success {
        background: #d4edda;
        color: #155724;
        border-color: #c3e6cb;
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
                        <a href="engine-report.php">Engine Hours & ROB</a>
                        <a href="garbage-report.php">Garbage</a>
                        <a href="oil-report.php" class="active">Oil Record</a>
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

        <!-- Main -->
        <?php if (isset($_GET['success']) && $_GET['success'] === 'deleted'): ?>
            <div class="toast-message toast-success">🗑 Entry deleted successfully</div>
        <?php elseif (isset($_GET['success'])): ?>
            <div class="toast-message toast-success">✅ Entry saved successfully</div>
        <?php endif; ?>



        <main>
            <h1>Oil Record Book</h1>

            <!-- Date Filter -->
            <div class="date-filter">
                <a href="?date=<?= date('Y-m-d', strtotime($date . ' -1 day')) ?>" class="btn-secondary">◀ Previous</a>
                <input type="date" value="<?= $date ?>" onchange="location='?date='+this.value">
                <a href="?date=<?= date('Y-m-d', strtotime($date . ' +1 day')) ?>" class="btn-secondary">Next ▶</a>
            </div>

            <?php if ($status === "LOCKED"): ?>
                <div class="locked-notice">
                    <h2>🔒 THE DAY HAS BEEN LOCKED BY MASTER</h2>
                    <p>For unlock it please submit request to the Captain.</p>
                    <a href="javascript:history.back()" class="btn-back">⬅ Go Back</a>
                </div>
            <?php else: ?>
                <!-- Tabs -->
                <div class="tabs">
                    <button class="tab-btn active" onclick="openTab('part1')">Part 1</button>
                    <button class="tab-btn" onclick="openTab('part2')">Part 2</button>
                </div>

                <!-- Part 1 -->
                <div id="part1" class="tab-content active">
                    <form method="POST" action="save-oil.php">
                        <input type="hidden" name="log_date" value="<?= $date ?>">
                        <input type="hidden" name="vessel_id" value="<?= $vessel_id ?>">

                        <table class="oil-table" id="table-part1">
                            <thead>
                                <tr>
                                    <th>Operation</th>
                                    <th>Time</th>
                                    <th>Tank</th>
                                    <th>Qty (MT)</th>
                                    <th>Latitude</th>
                                    <th>Longitude</th>
                                    <th>Remarks</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($part1 as $row): ?>
                                    <tr>
                                        <input type="hidden" name="oil_id[]" value="<?= $row['oil_id'] ?>">
                                        <input type="hidden" name="category[]" value="PART1">
                                        <td><input type="text" name="operation[]"
                                                value="<?= htmlspecialchars($row['operation']) ?>"></td>
                                        <td><?= $row['date_time'] ?></td>
                                        <td><input type="text" name="tank[]" value="<?= htmlspecialchars($row['tank']) ?>"></td>
                                        <td><input type="number" step="0.01" name="qty_mt[]" value="<?= $row['qty_mt'] ?>"></td>
                                        <td><input type="number" step="0.000001" name="latitude[]"
                                                value="<?= $row['latitude'] ?>"></td>
                                        <td><input type="number" step="0.000001" name="longitude[]"
                                                value="<?= $row['longitude'] ?>"></td>
                                        <td><input type="text" name="remarks[]"
                                                value="<?= htmlspecialchars($row['remarks']) ?>"></td>
                                        <td><a href="delete-oil.php?id=<?= $row['oil_id'] ?>&date=<?= $date ?>"
                                                class="oil-delete-btn">Delete</a></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>

                        <button type="button" class="btn btn-add" onclick="addRow('table-part1','PART1')">➕ Add New</button>
                        <button type="submit" class="btn btn-add">💾 Save All</button>
                    </form>
                </div>

                <!-- Part 2 -->
                <div id="part2" class="tab-content">
                    <form method="POST" action="save-oil.php">
                        <input type="hidden" name="log_date" value="<?= $date ?>">
                        <input type="hidden" name="vessel_id" value="<?= $vessel_id ?>">

                        <table class="oil-table" id="table-part2">
                            <thead>
                                <tr>
                                    <th>Operation</th>
                                    <th>Time</th>
                                    <th>Tank</th>
                                    <th>Qty (MT)</th>
                                    <th>Latitude</th>
                                    <th>Longitude</th>
                                    <th>Remarks</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($part2 as $row): ?>
                                    <tr>
                                        <input type="hidden" name="oil_id[]" value="<?= $row['oil_id'] ?>">
                                        <input type="hidden" name="category[]" value="PART2">
                                        <td><input type="text" name="operation[]"
                                                value="<?= htmlspecialchars($row['operation']) ?>"></td>
                                        <td><?= $row['date_time'] ?></td>
                                        <td><input type="text" name="tank[]" value="<?= htmlspecialchars($row['tank']) ?>"></td>
                                        <td><input type="number" step="0.01" name="qty_mt[]" value="<?= $row['qty_mt'] ?>"></td>
                                        <td><input type="number" step="0.000001" name="latitude[]"
                                                value="<?= $row['latitude'] ?>"></td>
                                        <td><input type="number" step="0.000001" name="longitude[]"
                                                value="<?= $row['longitude'] ?>"></td>
                                        <td><input type="text" name="remarks[]"
                                                value="<?= htmlspecialchars($row['remarks']) ?>"></td>
                                        <td><a href="delete-oil.php?id=<?= $row['oil_id'] ?>&date=<?= $date ?>"
                                                class="oil-delete-btn">Delete</a></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>

                        <button type="button" class="btn btn-add" onclick="addRow('table-part2','PART2')">➕ Add New</button>
                        <button type="submit" class="btn btn-add">💾 Save All</button>
                    </form>
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
        function openTab(tabId) {
            document.querySelectorAll('.tab-btn').forEach(btn => btn.classList.remove('active'));
            document.querySelectorAll('.tab-content').forEach(tab => tab.classList.remove('active'));
            document.querySelector(`button[onclick="openTab('${tabId}')"]`).classList.add('active');
            document.getElementById(tabId).classList.add('active');
        }

        function addRow(tableId, category) {
            const tbody = document.getElementById(tableId).querySelector('tbody');
            const tr = document.createElement('tr');

            // Generate current time in HH:MM:SS
            const now = new Date();
            const currentTime = now.toTimeString().slice(0, 8);

            tr.innerHTML = `
        <input type="hidden" name="oil_id[]" value="">
        <input type="hidden" name="category[]" value="${category}">
        <td><input type="text" name="operation[]" placeholder="Operation"></td>
        <td>${currentTime}</td>
        <td><input type="text" name="tank[]" placeholder="Tank"></td>
        <td><input type="number" step="0.01" name="qty_mt[]" value="0"></td>
        <td><input type="number" step="0.000001" name="latitude[]" placeholder="Lat"></td>
        <td><input type="number" step="0.000001" name="longitude[]" placeholder="Long"></td>
        <td><input type="text" name="remarks[]" placeholder="Remarks"></td>
        <td><button type="button" class="btn btn-remove" onclick="this.closest('tr').remove()">Remove</button></td>
    `;
            tbody.appendChild(tr);
        }

    </script>
    <script>
        document.addEventListener("DOMContentLoaded", function () {
            const toast = document.querySelector(".toast-message");
            if (toast) {
                // Show with animation
                setTimeout(() => {
                    toast.classList.add("show");
                }, 100);

                // Hide after 3s
                setTimeout(() => {
                    toast.classList.remove("show");
                    setTimeout(() => toast.remove(), 500); // remove from DOM after fade
                }, 3000);
            }
        });
    </script>





</body>

</html>