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



// date handling: ensure always valid date
$date = !empty($_GET['date']) ? $_GET['date'] : date("Y-m-d");

// fetch logs
try {
    $stmt = $pdo->prepare("
        SELECT * 
        FROM garbagelogs 
        WHERE vessel_id = ? 
        AND log_date = ? 
        ORDER BY entry_time
    ");
    $stmt->execute([$vessel_id, $date]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("Database error: " . $e->getMessage());
}

// categories aligned with DB values
$categories = [
    'PLASTICS' => 'A: Plastics',
    'FOOD_WASTE' => 'B: Food waste',
    'DOMESTIC_WASTE' => 'C: Domestic waste',
    'COOKING_OIL' => 'D: Cooking oil',
    'ASHES' => 'E: Incinerator ashes',
    'OPERATIONAL_WASTE' => 'F: Operational waste',
    'ANIMAL_CARCASS' => 'G: Animal carcass',
    'FISHING_GEAR' => 'H: Fishing gear'
];

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

    /* Garbage Controls */
    .garbage-controls {
        display: flex;
        justify-content: space-between;
        /* ⬅ keeps left + right groups */
        align-items: center;
        margin-bottom: 12px;
    }

    .garbage-nav {
        display: flex;
        gap: 8px;
        align-items: center;
        justify-content: center;
        flex: 1;
        /* ⬅ allows centering */
    }


    .garbage-nav input[type="date"] {
        padding: 6px 10px;
        border: 1px solid var(--line);
        border-radius: var(--border-radius-1);
        font-size: 0.9rem;
        background: var(--color-primary);
        color: var(--color-dark);
    }

    .category-legend {
        display: flex;
        flex-wrap: wrap;
        gap: 8px;
        margin-bottom: 8px;
    }

    .cat-label {
        background: var(--color-light);
        padding: 4px 8px;
        border-radius: var(--border-radius-1);
        font-size: 0.8rem;
        font-weight: 600;
        color: var(--color-dark);
    }

    /* Table */
    .garbage-table-wrap {
        border: 1px solid var(--line);
        border-radius: var(--border-radius-1);
        background: var(--color-white);
        overflow-x: auto;
        overflow-y: hidden;
        scrollbar-width: thin;
        scrollbar-color: var(--color-primary) var(--color-light);
        scroll-behavior: smooth;
        /* ⬅ smooth scroll */
    }


    .garbage-table-wrap::-webkit-scrollbar {
        height: 8px;
    }

    .garbage-table-wrap::-webkit-scrollbar-thumb {
        background: var(--color-primary);
        border-radius: var(--border-radius-1);
    }

    .garbage-table {
        width: 100%;
        /* let it stretch with container */
        min-width: 1200px;
        /* keep scroll if too many columns */
        border-collapse: collapse;
        font-size: 14px;
    }


    .garbage-table th {
        background: var(--color-primary);
        color: #fff;
        padding: 8px;
        text-align: center;
        position: sticky;
        top: 0;
    }

    .garbage-table td {
        padding: 6px;
        text-align: center;
        border-top: 1px solid var(--line);
        vertical-align: middle;
    }



    .garbage-table tr:nth-child(even) td {
        background: var(--row);
    }

    /* Buttons */
    .btn-new {
        padding: 8px 14px;
        background: var(--color-success);
        color: #fff;
        border-radius: var(--border-radius-1);
        text-decoration: none;
        font-weight: 600;
        cursor: pointer;
    }



    /* Action buttons container */
    .garbage-table td.actions {
        display: flex;
        justify-content: center;
        gap: 8px;
    }

    /* Shared button style */
    .garbage-save-btn,
    .garbage-delete-btn {
        padding: 4px 12px;
        /* slim padding */
        border-radius: 6px;
        font-size: 0.75rem;
        font-weight: 600;
        display: inline-flex;
        align-items: center;
        gap: 4px;
        cursor: pointer;
        border: 1px solid var(--line);
        /* 🔹 outline */
        transition: background 0.2s ease, transform 0.15s ease, border-color 0.2s ease;
    }

    /* Save button */
    .garbage-save-btn {
        background: var(--color-success);
        color: #fff;
        border-color: var(--color-success);
    }

    .garbage-save-btn:hover {
        background: #178f75;
        border-color: #178f75;
        transform: translateY(-1px);
    }

    /* Delete button */
    .garbage-delete-btn {
        background: var(--color-danger);
        color: #fff;
        border-color: var(--color-danger);
    }

    .garbage-delete-btn:hover {
        background: #b71c1c;
        border-color: #b71c1c;
        transform: translateY(-1px);
    }


    /* Garbage Table Inputs */
    .garbage-table input,
    .garbage-table select {
        width: 100%;
        padding: 6px 8px;
        border: 1px solid var(--line);
        border-radius: var(--border-radius-1);
        font-size: 0.85rem;
        color: var(--color-dark);
        background: var(--color-background);
        transition: border-color 0.2s, box-shadow 0.2s;
    }

    /* On focus */
    .garbage-table input:focus,
    .garbage-table select:focus {
        border-color: var(--color-primary);
        box-shadow: 0 0 4px var(--color-primary);
        outline: none;
    }

    /* Placeholder color (for empty fields) */
    .garbage-table input::placeholder {
        color: var(--color-info-dark);
        opacity: 0.8;
    }

    /* Select styling */
    .garbage-table select {
        appearance: none;
        background-image: url("data:image/svg+xml;utf8,<svg fill='%236C9BCF' height='20' viewBox='0 0 24 24' width='20' xmlns='http://www.w3.org/2000/svg'><path d='M7 10l5 5 5-5z'/></svg>");
        background-repeat: no-repeat;
        background-position: right 8px center;
        background-size: 16px;
        padding-right: 26px;
        cursor: pointer;
    }

    .toast {
        position: fixed;
        top: 20px;
        right: 20px;
        z-index: 9999;
        padding: 10px 16px;
        border-radius: 6px;
        font-weight: 600;
        opacity: 0;
        transform: translateY(-20px);
        animation: slideDown 0.5s forwards, fadeOut 0.5s 2.5s forwards;
        box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
    }

    .toast-success {
        background: #d4edda;
        color: #155724;
        border: 1px solid #c3e6cb;
    }

    /* Slide in */
    @keyframes slideDown {
        to {
            opacity: 1;
            transform: translateY(0);
        }
    }

    /* Fade away */
    @keyframes fadeOut {
        to {
            opacity: 0;
            transform: translateY(-20px);
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
                        <a href="engine-report.php">Engine Hours & ROB</a>
                        <a href="garbage-report.php" class="active">Garbage</a>
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

        <!-- Main -->
        <?php if (isset($_GET['success']) && $_GET['success'] === 'deleted'): ?>
            <div class="toast toast-success">🗑 Entry deleted successfully</div>
        <?php elseif (isset($_GET['success'])): ?>
            <div class="toast toast-success">✅ Entry saved successfully</div>
        <?php endif; ?>


        <main>
            <h1>Garbage Management</h1>
            <div class="garbage-controls">
                <div class="garbage-nav">
                    <a href="?date=<?= date('Y-m-d', strtotime($date . ' -1 day')) ?>" class="btn-secondary">◀
                        Previous</a>
                    <input type="date" value="<?= $date ?>" onchange="location='?date='+this.value">
                    <a href="?date=<?= date('Y-m-d', strtotime($date . ' +1 day')) ?>" class="btn-secondary">Next ▶</a>
                </div>
                <button type="button" class="btn-new" onclick="addGarbageRow()">➕ New</button>
            </div>

            <!-- Category Legend -->
            <div class="category-legend">
                <?php foreach ($categories as $c): ?>
                    <span class="cat-label"><?= $c ?></span>
                <?php endforeach; ?>
            </div>

            <?php if ($status === "LOCKED"): ?>
                <div class="locked-notice">
                    <h2>🔒 THE DAY HAS BEEN LOCKED BY MASTER</h2>
                    <p>For unlock it please submit request to the Captain.</p>
                    <a href="javascript:history.back()" class="btn-back">⬅ Go Back</a>
                </div>
            <?php else: ?>
                <!-- Table -->
                <div class="garbage-table-wrap">
                    <form method="POST" action="save-garbage.php" id="garbageForm">
                        <input type="hidden" name="vessel_id" value="<?= $vessel_id ?>">
                        <input type="hidden" name="log_date" value="<?= $date ?>">

                        <table class="garbage-table">
                            <thead>
                                <tr>
                                    <th>Date / Time</th>
                                    <th>Latitude</th>
                                    <th>Longitude</th>
                                    <th>Category</th>
                                    <th>Qty (m³)</th>
                                    <th>Method</th>
                                    <th>Port</th>
                                    <th>Receipt Ref</th>
                                    <th>Remarks</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody id="garbageBody">
                                <?php foreach ($rows as $r): ?>
                                    <tr>
                                        <input type="hidden" name="garb_id[]" value="<?= $r['garb_id'] ?>">
                                        <td><?= $r['log_date'] . ' ' . $r['entry_time'] ?></td>
                                        <td><input type="number" step="0.000001" min="-90" max="90" name="latitude[]"
                                                value="<?= $r['latitude'] ?>"></td>
                                        <td><input type="number" step="0.000001" min="-180" max="180" name="longitude[]"
                                                value="<?= $r['longitude'] ?>"></td>
                                        <td>
                                            <select name="category[]" required>
                                                <?php foreach ($categories as $val => $label): ?>
                                                    <option value="<?= $val ?>" <?= $r['category'] == $val ? 'selected' : '' ?>>
                                                        <?= $label ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </td>
                                        <td><input type="number" step="0.01" name="qty_m3[]" value="<?= $r['qty_m3'] ?>"></td>
                                        <td>
                                            <select name="method[]" required>
                                                <option value="GENERATED" <?= $r['method'] == 'GENERATED' ? 'selected' : '' ?>>
                                                    Generated</option>
                                                <option value="INCINERATED" <?= $r['method'] == 'INCINERATED' ? 'selected' : '' ?>>
                                                    Incinerated</option>
                                                <option value="LANDED" <?= $r['method'] == 'LANDED' ? 'selected' : '' ?>>Landed
                                                </option>
                                                <option value="RETAIN" <?= $r['method'] == 'RETAIN' ? 'selected' : '' ?>>Retain
                                                </option>
                                            </select>
                                        </td>
                                        <td><input type="text" name="port[]" value="<?= htmlspecialchars($r['port']) ?>"></td>
                                        <td><input type="text" name="receipt_ref[]"
                                                value="<?= htmlspecialchars($r['receipt_ref']) ?>"></td>
                                        <td><input type="text" name="remarks[]" value="<?= htmlspecialchars($r['remarks']) ?>">
                                        </td>
                                        <td class="actions">
                                            <a href="garbage-delete.php?id=<?= $r['garb_id'] ?>&date=<?= $date ?>"
                                                class="garbage-delete-btn"
                                                onclick="return confirm('Delete this entry permanently?')">🗑 Delete</a>
                                        </td>

                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>

                        <!-- Save All Button -->
                        <div style="margin-top:12px; text-align:right;">
                            <button type="submit" class="garbage-save-btn" style="padding:8px 16px; font-size:0.9rem;">💾
                                Save All</button>
                        </div>
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
        function addGarbageRow() {
            const tbody = document.getElementById("garbageBody");

            let tr = document.createElement("tr");
            tr.innerHTML = `
        <input type="hidden" name="garb_id[]" value="">
        <td><?= $date ?> <?= date("H:i:s") ?></td>
        <td><input type="number" step="0.000001" min="-90" max="90" name="latitude[]" placeholder="Lat"></td>
        <td><input type="number" step="0.000001" min="-180" max="180" name="longitude[]" placeholder="Long"></td>
        <td>
            <select name="category[]" required>
                <option value="PLASTICS">A: Plastics</option>
                <option value="FOOD_WASTE">B: Food waste</option>
                <option value="DOMESTIC_WASTE">C: Domestic waste</option>
                <option value="COOKING_OIL">D: Cooking oil</option>
                <option value="ASHES">E: Incinerator ashes</option>
                <option value="OPERATIONAL_WASTE">F: Operational waste</option>
                <option value="ANIMAL_CARCASS">G: Animal carcass</option>
                <option value="FISHING_GEAR">H: Fishing gear</option>
            </select>
        </td>
        <td><input type="number" step="0.01" name="qty_m3[]" value="0"></td>
        <td>
            <select name="method[]" required>
                <option value="GENERATED">Generated</option>
                <option value="INCINERATED">Incinerated</option>
                <option value="LANDED">Landed</option>
                <option value="RETAIN">Retain</option>
            </select>
        </td>
        <td><input type="text" name="port[]" placeholder="Port"></td>
        <td><input type="text" name="receipt_ref[]" placeholder="Ref"></td>
        <td><input type="text" name="remarks[]" placeholder="Remarks"></td>
        <td class="actions">
            <button type="button" class="garbage-delete-btn" onclick="removeRow(this)">❌ Remove</button>
        </td>
    `;
            tbody.appendChild(tr);
        }

        function removeRow(btn) {
            const row = btn.closest("tr");
            row.remove();
        }
    </script>
    <script>
        document.addEventListener("DOMContentLoaded", () => {
            const toast = document.querySelector(".toast");
            if (toast) {
                setTimeout(() => toast.remove(), 3000); // 3s
            }
        });
    </script>





</body>

</html>