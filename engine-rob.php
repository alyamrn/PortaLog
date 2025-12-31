<?php
session_start();
require_once "db_connect.php";
require_once "auto_cleanup_reminder.php";

autoCleanupReminders($pdo, $_SESSION['vessel_id']);

$vessel_id = $_SESSION['vessel_id'];
$date = $_GET['date'] ?? date("Y-m-d");

$userId = $_SESSION['user_id'];
$username = $_SESSION['full_name'];
$role = $_SESSION['role'];

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



// --- Default product lists ---
$default_liquid = [
    ["FUEL", "m3"],
    ["FRESH WATER", "m3"],
    ["BASE OIL", "m3"],
    ["BRINE", "m3"],
    ["DNABM", "m3"],
    ["DNABM PREMIX", "m3"],
    ["LUB OIL", "L"],
    ["METHANOL", "m3"],
    ["NABM", "m3"],
    ["RNABM", "m3"],
    ["SIEVED MUD", "m3"],
    ["SLOPS / SLUDGE", "m3"],
    ["SNABM PREMIX", "m3"]
];

$default_dry = [
    ["BARACARB", "MT"],
    ["BARACARB 25", "MT"],
    ["BARACARB 5", "MT"],
    ["BARACARB 50", "MT"],
    ["CEMENT", "MT"],
    ["LITE CRETE", "MT"],
    ["LITEFILL", "MT"]
];

// --- Check vessel product list ---
$count = $pdo->prepare("SELECT COUNT(*) FROM rob_products WHERE vessel_id=?");
$count->execute([$vessel_id]);
if ($count->fetchColumn() == 0) {
    // Use INSERT IGNORE to avoid duplicate primary issues if table lacks proper AUTO_INCREMENT
    $insert = $pdo->prepare("INSERT IGNORE INTO rob_products (vessel_id, category, product_name, unit) VALUES (?, ?, ?, ?)");
    $insert2 = $pdo->prepare("INSERT IGNORE INTO rob_records (vessel_id, log_date, category, product, unit) VALUES (?, ?, ?, ?, ?)");
    foreach ($default_liquid as $p) {
        $insert->execute([$vessel_id, 'LIQUID', $p[0], $p[1]]);
        $insert2->execute([$vessel_id, $date, 'LIQUID', $p[0], $p[1]]);
    }
    foreach ($default_dry as $p) {
        $insert->execute([$vessel_id, 'DRY', $p[0], $p[1]]);
        $insert2->execute([$vessel_id, $date, 'DRY', $p[0], $p[1]]);
    }
}

// --- Auto-create missing rob_records for today ---
$check = $pdo->prepare("SELECT COUNT(*) FROM rob_records WHERE vessel_id=? AND log_date=?");
$check->execute([$vessel_id, $date]);
if ($check->fetchColumn() == 0) {
    $prodList = $pdo->prepare("SELECT product_name, unit, category FROM rob_products WHERE vessel_id=?");
    $prodList->execute([$vessel_id]);
    foreach ($prodList->fetchAll(PDO::FETCH_ASSOC) as $p) {
        $ins = $pdo->prepare("INSERT INTO rob_records (vessel_id, log_date, category, product, unit)
                              VALUES (?, ?, ?, ?, ?)");
        $ins->execute([$vessel_id, $date, $p['category'], $p['product_name'], $p['unit']]);
    }
}

// --- Check status of this date for this vessel ---
$statusQuery = $pdo->prepare("SELECT status FROM dailystatus WHERE vessel_id = ? AND log_date = ?");
$statusQuery->execute([$vessel_id, $date]);
$status = $statusQuery->fetchColumn() ?: "UNLOCKED"; // default if no record found


// --- Fetch all products and today’s ROB data ---
$q = $pdo->prepare("
    SELECT 
        p.product_id, p.vessel_id, p.category, p.product_name, p.unit,
        r.rob_id, r.log_date, r.previous_rob, r.loaded_today, r.discharged_today,
        r.produced_today, r.density, r.daily_consumption, r.adjustment,
        r.current_rob, r.max_capacity, r.remarks
    FROM rob_products p
    LEFT JOIN rob_records r
      ON p.vessel_id = r.vessel_id
     AND p.product_name = r.product
     AND r.log_date = ?
    WHERE p.vessel_id = ?
    ORDER BY p.category, p.product_name
");
$q->execute([$date, $vessel_id]);
$rows = $q->fetchAll(PDO::FETCH_ASSOC);

// Split by category
$liquidRows = array_filter($rows, fn($r) => $r['category'] === 'LIQUID');
$dryRows = array_filter($rows, fn($r) => $r['category'] === 'DRY');

// Fetch previous day's ROB to prefill 'Prev' column when today's previous_rob is NULL
$prevDate = date('Y-m-d', strtotime($date . ' -1 day'));
$prevQ = $pdo->prepare("SELECT product, current_rob FROM rob_records WHERE vessel_id = ? AND log_date = ?");
$prevQ->execute([$vessel_id, $prevDate]);
$prevRows = $prevQ->fetchAll(PDO::FETCH_ASSOC);
$prevMap = [];
foreach ($prevRows as $pr) {
    $prevMap[$pr['product']] = $pr['current_rob'];
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

    /* Controls container: space between left and right */
    .controls {
        display: flex;
        align-items: center;
        justify-content: space-between;
        /* separates left + right */
        gap: 16px;
        padding: 10px 14px;
        background: var(--color-white);
        border: 1px solid var(--line);
        border-radius: var(--border-radius-2);
    }

    /* Group for Date + Filter on the left */
    .left-group {
        display: flex;
        align-items: center;
        gap: 12px;
    }

    /* Date group stays compact */
    .date-group {
        display: flex;
        align-items: center;
        gap: 8px;
    }

    /* Search input aligned right */
    .search-group input[type="text"] {
        padding: 8px 12px;
        color: var(--color-dark);
        border: 1px solid var(--line);
        border-radius: var(--border-radius-1);
        font-size: 0.9rem;
        min-width: 220px;
        background: var(--color-background);
        transition: border-color 0.2s ease, box-shadow 0.2s ease;
    }

    .search-group input[type="text"]:focus {
        border-color: var(--accent);
        box-shadow: 0 0 4px var(--accent);
        outline: none;
    }

    /* Make Filter look like a button */
    .filter-btn {
        padding: 8px 16px;
        background: var(--color-primary);
        color: #fff;
        border: none;
        border-radius: var(--border-radius-1);
        font-weight: 600;
        cursor: pointer;
        transition: background 0.25s ease, transform 0.15s ease;
    }

    .filter-btn:hover {
        background: var(--color-success);
        transform: translateY(-2px);
    }




    .controls label {
        font-weight: 600;
        color: var(--ink);
        font-size: 0.9rem;
    }

    .controls input[type="date"] {
        padding: 6px 10px;
        border: 1px solid var(--line);
        border-radius: var(--border-radius-1);
        font-size: 0.9rem;
        background: var(--color-primary);
        color: var(--color-dark);
        transition: border-color 0.2s ease, box-shadow 0.2s ease;
    }

    .controls input[type="date"]:focus {
        border-color: var(--accent);
        box-shadow: 0 0 4px var(--accent);
        outline: none;
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
        background: var(--accent);
        color: var(--color-white);
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

    /* === ROB PAGE ONLY === */
    .rob-container {
        overflow-x: auto;
        overflow-y: hidden;
        max-width: 100%;
        padding: 10px;
    }

    /* Smooth horizontal scrolling for ROB tables */
    /* ROB Table Scrollbar - synced with your theme */
    .rob-table-wrap {
        overflow-x: auto;
        overflow-y: hidden;
        scrollbar-width: thin;
        scrollbar-color: var(--color-primary) var(--color-light);
        border-radius: 15px;
    }

    .rob-table-wrap::-webkit-scrollbar {
        height: 8px;
    }

    .rob-table-wrap::-webkit-scrollbar-track {
        background: var(--color-light);
        border-radius: var(--border-radius-1);
    }

    .rob-table-wrap::-webkit-scrollbar-thumb {
        background: var(--color-primary);
        border-radius: var(--border-radius-1);
    }

    .rob-table-wrap::-webkit-scrollbar-thumb:hover {
        background: var(--color-success);
    }

    /* Per-row save button */
    .rob-save-btn {
        background: var(--color-success);
        color: var(--color-white);
        padding: 6px 12px;
        border-radius: var(--border-radius-1);
        font-size: 0.8rem;
        font-weight: 600;
        cursor: pointer;
        border: none;
        transition: background 0.2s ease, transform 0.15s ease;
    }

    .rob-save-btn:hover {
        background: #178f75;
        transform: translateY(-2px);
    }

    /* Add Product button (top) */
    .rob-add-btn {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        margin-bottom: 10px;
        padding: 8px 14px;
        background: var(--color-primary);
        color: var(--color-white);
        font-weight: 600;
        border-radius: var(--border-radius-1);
        text-decoration: none;
        transition: background 0.25s ease, transform 0.15s ease;
    }

    .rob-add-btn:hover {
        background: var(--color-success);
        transform: translateY(-2px);
    }



    /* Section title */
    .rob-section-title {
        font-size: 1.1rem;
        font-weight: 700;
        margin: 12px 0;
        color: var(--ink);
    }

    /* ROB Table */
    .rob-table {
        width: 100%;
        border-collapse: collapse;
        font-size: 14px;
        background-color: var(--color-white);

    }

    .rob-table thead th {
        background: var(--color-primary);
        color: #fff;
        font-weight: 700;
        text-align: center;
        padding: 10px;
        position: sticky;
        top: 0;
        z-index: 2;
        white-space: nowrap;
    }

    .rob-table td {
        padding: 8px;
        border-top: 1px solid var(--line);
        text-align: center;
        white-space: nowrap;
    }

    .rob-table tr:nth-child(even) td {
        background: var(--row);
    }

    .rob-actions {
        margin-top: 2px;
        display: flex;
        justify-content: flex-end;
        background: var(--color-white);
    }

    /* ROB table input fields */
    /* ROB table text inputs - always show full text */
    .rob-table input[type="text"] {
        min-width: 120px;
        /* give enough space */
        white-space: nowrap;
        /* prevent text wrapping */
        overflow: visible;
        /* ensure full visibility */
        text-overflow: clip;
        padding: 6px 8px;
        border: 1px solid var(--line);
        border-radius: var(--border-radius-1);
        font-size: 0.85rem;
        background: var(--color-background);
        color: var(--color-dark);
        box-sizing: border-box;
        transition: border-color 0.2s ease, box-shadow 0.2s ease;
        /* no truncation */
    }


    .rob-table input[type="number"],
    .rob-table input[type="time"],
    .rob-table textarea {

        padding: 6px 8px;
        border: 1px solid var(--line);
        border-radius: var(--border-radius-1);
        font-size: 0.85rem;
        background: var(--color-background);
        color: var(--color-dark);
        box-sizing: border-box;
        transition: border-color 0.2s ease, box-shadow 0.2s ease;
    }

    .rob-table input[type="number"]::-webkit-inner-spin-button,
    .rob-table input[type="number"]::-webkit-outer-spin-button {
        -webkit-appearance: none;
        /* remove default browser style */
        appearance: none;
        margin: 0;
    }

    /* Custom spinner buttons */
    .rob-table input[type="number"] {
        position: relative;
        padding-right: 24px;
        /* leave space for custom arrows */
    }

    /* Add custom arrows using background */
    .rob-table input[type="number"]::-webkit-inner-spin-button {
        opacity: 1;
        cursor: pointer;
        background: var(--color-primary);
        border-radius: 50%;
        width: 16px;
        height: 16px;
        margin-left: 4px;
    }

    /* Firefox specific */
    .rob-table input[type="number"] {
        -moz-appearance: textfield;
        /* remove default arrows */
    }

    /* On focus highlight */
    .rob-table input:focus,
    .rob-table textarea:focus {
        border-color: var(--color-primary);
        box-shadow: 0 0 4px var(--color-primary);
        outline: none;
        background: var(--color-white);
    }

    /* Numeric fields aligned right */
    .rob-table input[type="number"] {
        text-align: right;
    }

    /* Remarks textarea */
    .rob-table textarea {
        resize: none;
        min-height: 30px;
    }

    .rob-delete-btn {
        background: var(--color-danger);
        color: var(--color-white);
        padding: 6px 12px;
        border-radius: var(--border-radius-1);
        font-size: 0.8rem;
        font-weight: 600;
        cursor: pointer;
        border: none;
        transition: background 0.2s ease, transform 0.15s ease;
    }

    .rob-delete-btn:hover {
        background: #cc004c;
        transform: translateY(-2px);
    }

    /* ===== ADD PRODUCT MODAL ===== */
    #addProductModal {
        display: none;
        /* hidden by default */
        position: fixed;
        inset: 0;
        /* shorthand for top:0; right:0; bottom:0; left:0 */
        background: rgba(0, 0, 0, 0.6);
        /* dark overlay */
        backdrop-filter: blur(4px);
        /* smooth blur effect */
        justify-content: center;
        align-items: center;
        z-index: 9999;
    }

    /* Modal Box */
    #addProductModal .modal-content {
        background: var(--color-white);
        color: var(--ink);
        width: 360px;
        border-radius: 12px;
        box-shadow: 0 8px 24px rgba(0, 0, 0, 0.25);
        padding: 24px 22px;
        display: flex;
        flex-direction: column;
        gap: 12px;
        animation: fadeIn 0.25s ease-out;
    }

    /* Modal Title */
    #addProductModal h3 {
        margin-top: 0;
        text-align: center;
        font-size: 1.2rem;
        font-weight: 700;
        color: var(--ink);
    }

    /* Label styling */
    #addProductModal label {
        font-weight: 600;
        font-size: 0.9rem;
        color: var(--muted);
        margin-bottom: 4px;
    }

    /* Inputs and select */
    #addProductModal input[type="text"],
    #addProductModal select {
        width: 100%;
        padding: 8px 10px;
        border: 1px solid var(--line);
        border-radius: var(--border-radius-1);
        background: var(--color-background);
        color: var(--color-dark);
        font-size: 0.9rem;
        transition: border-color 0.2s ease, box-shadow 0.2s ease;
    }

    #addProductModal input:focus,
    #addProductModal select:focus {
        border-color: var(--accent);
        box-shadow: 0 0 5px var(--accent);
        outline: none;
        background: var(--color-white);
    }

    /* Buttons container */
    #addProductModal .modal-buttons {
        display: flex;
        justify-content: space-between;
        margin-top: 14px;
    }

    /* Buttons */
    #addProductModal .rob-save-btn {
        flex: 1;
        margin-right: 8px;
        padding: 8px 0;
        background: var(--color-success);
        color: #fff;
        border: none;
        border-radius: var(--border-radius-1);
        cursor: pointer;
        font-weight: 600;
        transition: background 0.2s ease, transform 0.15s ease;
    }

    #addProductModal .rob-save-btn:hover {
        background: #178f75;
        transform: translateY(-2px);
    }

    #addProductModal .rob-delete-btn {
        flex: 1;
        padding: 8px 0;
        background: var(--color-danger);
        color: #fff;
        border: none;
        border-radius: var(--border-radius-1);
        cursor: pointer;
        font-weight: 600;
        transition: background 0.2s ease, transform 0.15s ease;
    }

    #addProductModal .rob-delete-btn:hover {
        background: #b71c1c;
        transform: translateY(-2px);
    }

    /* Fade-in animation */
    @keyframes fadeIn {
        from {
            opacity: 0;
            transform: scale(0.9);
        }

        to {
            opacity: 1;
            transform: scale(1);
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
        <main class="rob-container">
            <!-- Tabs -->
            <nav class="engine-tabs">
                <a href="engine-report.php" class="tab">Running Hours</a>
                <a href="engine-rob.php" class="tab active">ROB</a>
            </nav>

            <h2>ROB (Remaining On Board)</h2>


            <!-- Date, Filter, and Search bar in one line -->
            <form method="GET" class="controls">
                <div class="left-group">
                    <div class="date-group">
                        <label for="date">Date</label>
                        <input type="date" id="date" name="date" value="<?= htmlspecialchars($date) ?>">
                    </div>
                    <button class="filter-btn" type="submit">🔍 Filter</button>
                </div>

                <div class="search-group">
                    <input type="text" id="searchBox" placeholder="🔎 Search product...">
                </div>
            </form>

            <?php if ($status === "LOCKED"): ?>
                <div class="locked-notice">
                    <h2>🔒 THE DAY HAS BEEN LOCKED BY MASTER</h2>
                    <p>For unlock it please submit request to the Captain.</p>
                    <a href="javascript:history.back()" class="btn-back">⬅ Go Back</a>
                </div>
            <?php else: ?>


                <!-- ========== LIQUID BULK ========== -->
                <div class="rob-section-title">Liquid Bulk</div>


                <form method="POST" action="save-rob-all.php">
                    <input type="hidden" name="log_date" value="<?= htmlspecialchars($date) ?>">
                    <input type="hidden" name="category" value="LIQUID">

                    <div class="rob-table-wrap">
                        <table class="rob-table" id="liquidTable">
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
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($liquidRows as $prod): ?>
                                    <tr>
                                        <td><input type="text" name="product[]"
                                                value="<?= htmlspecialchars($prod['product_name']) ?>" readonly></td>
                                        <td><input type="text" name="unit[]" value="<?= htmlspecialchars($prod['unit']) ?>"
                                                readonly></td>

                                        <td><input type="number" step="0.01" name="previous_rob[]"
                                            value="<?= isset($prod['previous_rob']) && $prod['previous_rob'] !== null ? $prod['previous_rob'] : (isset($prevMap[$prod['product_name']]) ? $prevMap[$prod['product_name']] : 0) ?>"></td>
                                        <td><input type="number" step="0.01" name="loaded_today[]"
                                                value="<?= $prod['loaded_today'] ?? 0 ?>"></td>
                                        <td><input type="number" step="0.01" name="discharged_today[]"
                                                value="<?= $prod['discharged_today'] ?? 0 ?>"></td>
                                        <td><input type="number" step="0.01" name="produced_today[]"
                                                value="<?= $prod['produced_today'] ?? 0 ?>"></td>
                                        <td><input type="number" step="0.01" name="density[]"
                                                value="<?= $prod['density'] ?? 0 ?>"></td>
                                        <td><input type="number" step="0.01" name="daily_consumption[]"
                                                value="<?= $prod['daily_consumption'] ?? 0 ?>"></td>
                                        <td><input type="number" step="0.01" name="adjustment[]"
                                                value="<?= $prod['adjustment'] ?? 0 ?>"></td>
                                        <td><input type="number" step="0.01" name="current_rob[]"
                                                value="<?= $prod['current_rob'] ?? 0 ?>"></td>
                                        <td><input type="number" step="0.01" name="max_capacity[]"
                                                value="<?= $prod['max_capacity'] ?? 0 ?>"></td>
                                        <td><input type="text" name="remarks[]"
                                                value="<?= htmlspecialchars($prod['remarks'] ?? '') ?>"></td>

                                        <td>
                                            <a href="delete-product.php?id=<?= $prod['product_id'] ?>" class="rob-delete-btn"
                                                onclick="return confirm('Delete this product?')">🗑 Delete</a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>

                        </table>
                    </div>

                    <div style="display:flex; justify-content:center; margin-top:10px; gap:8px;">
                        <button type="submit" class="rob-save-btn" style="font-size:1rem; padding:10px 24px;">💾 Save Liquid Bulk</button>
                        <button type="submit" name="confirm" value="1" class="rob-save-btn" style="font-size:1rem; padding:10px 24px;">✅ Confirm Completed</button>
                    </div>
                </form>


                <!-- ========== DRY BULK ========== -->
                <div class="rob-section-title">Dry Bulk</div>

                <form method="POST" action="save-rob-all.php">
                    <input type="hidden" name="log_date" value="<?= htmlspecialchars($date) ?>">
                    <input type="hidden" name="category" value="DRY">

                    <div class="rob-table-wrap">
                        <table class="rob-table" id="dryTable">
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
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($dryRows as $prod): ?>
                                    <tr>
                                        <td><input type="text" name="product[]"
                                                value="<?= htmlspecialchars($prod['product_name']) ?>" readonly></td>
                                        <td><input type="text" name="unit[]" value="<?= htmlspecialchars($prod['unit']) ?>"
                                                readonly></td>

                                        <td><input type="number" step="0.01" name="previous_rob[]"
                                            value="<?= isset($prod['previous_rob']) && $prod['previous_rob'] !== null ? $prod['previous_rob'] : (isset($prevMap[$prod['product_name']]) ? $prevMap[$prod['product_name']] : 0) ?>"></td>
                                        <td><input type="number" step="0.01" name="loaded_today[]"
                                                value="<?= $prod['loaded_today'] ?? 0 ?>"></td>
                                        <td><input type="number" step="0.01" name="discharged_today[]"
                                                value="<?= $prod['discharged_today'] ?? 0 ?>"></td>
                                        <td><input type="number" step="0.01" name="produced_today[]"
                                                value="<?= $prod['produced_today'] ?? 0 ?>"></td>
                                        <td><input type="number" step="0.01" name="density[]"
                                                value="<?= $prod['density'] ?? 0 ?>"></td>
                                        <td><input type="number" step="0.01" name="daily_consumption[]"
                                                value="<?= $prod['daily_consumption'] ?? 0 ?>"></td>
                                        <td><input type="number" step="0.01" name="adjustment[]"
                                                value="<?= $prod['adjustment'] ?? 0 ?>"></td>
                                        <td><input type="number" step="0.01" name="current_rob[]"
                                                value="<?= $prod['current_rob'] ?? 0 ?>"></td>
                                        <td><input type="number" step="0.01" name="max_capacity[]"
                                                value="<?= $prod['max_capacity'] ?? 0 ?>"></td>
                                        <td><input type="text" name="remarks[]"
                                                value="<?= htmlspecialchars($prod['remarks'] ?? '') ?>"></td>

                                        <td>
                                            <a href="delete-product.php?id=<?= $prod['product_id'] ?>" class="rob-delete-btn"
                                                onclick="return confirm('Delete this product?')">🗑 Delete</a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>

                        </table>
                    </div>

                    <div style="display:flex; justify-content:center; margin-top:10px; gap:8px;">
                        <button type="submit" class="rob-save-btn" style="font-size:1rem; padding:10px 24px;">💾 Save Dry Bulk</button>
                        <button type="submit" name="confirm" value="1" class="rob-save-btn" style="font-size:1rem; padding:10px 24px;">✅ Confirm Completed</button>
                    </div>
                </form>


                <!-- Add Product Button (Popup) -->
                <div style="margin-top:25px;">
                    <button class="rob-add-btn" onclick="openAddProductModal()">➕ Add Product</button>
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
    <!-- Modal -->
    <!-- ADD PRODUCT MODAL -->
    <div id="addProductModal">
        <div class="modal-content">
            <h3>Add New Product</h3>
            <form method="POST" action="add-product.php">
                <!-- Hidden vessel_id from session -->
                <input type="hidden" name="vessel_id" value="<?= htmlspecialchars($_SESSION['vessel_id']) ?>">

                <label>Category:</label>
                <select name="category" required>
                    <option value="LIQUID">Liquid Bulk</option>
                    <option value="DRY">Dry Bulk</option>
                </select>

                <label>Product Name:</label>
                <input type="text" name="product_name" required placeholder="Enter product name">

                <label>Unit:</label>
                <input type="text" name="unit" required placeholder="e.g. m3, MT, L">

                <div class="modal-buttons">
                    <button type="submit" class="rob-save-btn">💾 Save</button>
                    <button type="button" class="rob-delete-btn" onclick="closeAddProductModal()">Cancel</button>
                </div>
            </form>
        </div>
    </div>



    <script>
        function openAddProductModal() {
            document.getElementById("addProductModal").style.display = "flex";
        }

        function closeAddProductModal() {
            document.getElementById("addProductModal").style.display = "none";
        }
    </script>



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
        document.getElementById("searchBox").addEventListener("input", function() {
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
    <script>
        function addRobRow(category) {
            const tableId = category === "LIQUID" ? "liquidTable" : "dryTable";
            const tbody = document.querySelector(`#${tableId} tbody`);

            const tr = document.createElement("tr");
            tr.innerHTML = `
    <form method="POST" action="save-rob.php">
        <input type="hidden" name="log_date" value="<?= htmlspecialchars($date) ?>">
        <input type="hidden" name="category" value="${category}">
        <td><input type="text" name="product" placeholder="New Product"></td>
        <td><input type="text" name="unit" placeholder="Unit"></td>
        <td><input type="number" step="0.01" name="previous_rob" value="0"></td>
        <td><input type="number" step="0.01" name="loaded_today" value="0"></td>
        <td><input type="number" step="0.01" name="discharged_today" value="0"></td>
        <td><input type="number" step="0.01" name="produced_today" value="0"></td>
        <td><input type="number" step="0.01" name="density" value="0"></td>
        <td><input type="number" step="0.01" name="daily_consumption" value="0"></td>
        <td><input type="number" step="0.01" name="adjustment" value="0"></td>
        <td><input type="number" step="0.01" name="current_rob" value="0"></td>
        <td><input type="number" step="0.001" name="max_capacity" value="0"></td>
        <td><input type="text" name="remarks" placeholder="Remarks"></td>
        <td>
            <button type="submit" class="rob-save-btn">💾 Save</button>
            <button type="button" class="rob-delete-btn" onclick="this.closest('tr').remove()">🗑 Delete</button>
        </td>
    </form>
    `;
            tbody.appendChild(tr);
        }
    </script>

    <script>
        document.querySelectorAll('.rob-table input[type="text"]').forEach(input => {
            input.addEventListener('input', function() {
                this.style.width = ((this.value.length + 2) * 8) + 'px';
            });
        });
    </script>
    <script>
        document.addEventListener("DOMContentLoaded", function() {
            const searchBox = document.getElementById("searchBox");

            searchBox.addEventListener("input", function() {
                const term = this.value.toLowerCase();

                // Get all rows from both tables
                const rows = document.querySelectorAll(".rob-table tbody tr");

                rows.forEach(row => {
                    const productCell = row.querySelector("td input[name='product'], td:first-child");
                    const productName = productCell ? productCell.value || productCell.textContent : "";

                    if (productName.toLowerCase().includes(term)) {
                        row.style.display = "";
                    } else {
                        row.style.display = "none";
                    }
                });
            });
        });
    </script>





</body>

</html>