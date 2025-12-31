<?php
session_start();
require_once "db_connect.php"; // uses $pdo (PDO connection)

// Only allow admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'ADMIN') {
    header("Location: login.php");
    exit;
}
$userId = $_SESSION['user_id'];
$username = $_SESSION['full_name'];
$role = $_SESSION['role'];

// ✅ Manual insert with duplicate check
if (isset($_POST['add_crew'])) {
    $name = trim($_POST['full_name']);
    $email = trim($_POST['email']);
    $nationality = $_POST['nationality'] ?? "";
    $category = $_POST['category'] ?? "";
    $role = $_POST['crew_role'] ?? "";
    $dob = !empty($_POST['dob']) ? $_POST['dob'] : null;

    if ($name !== "") {
        $check = $pdo->prepare("SELECT COUNT(*) FROM crewlist WHERE full_name = ? AND email = ?");
        $check->execute([$name, $email]);
        $exists = $check->fetchColumn();

        if ($exists == 0) {
            $sql = "INSERT INTO crewlist (full_name, email, nationality, category, crew_role, dob) 
                    VALUES (?, ?, ?, ?, ?, ?)";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$name, $email, $nationality, $category, $role, $dob]);
        } else {
            echo "<script>alert('Duplicate entry skipped: $name ($email)');</script>";
        }
    }
}
$toastMessage = ''; // notification text

// ✅ Delete crew record
if (isset($_POST['delete_crew'])) {
    $crew_id = $_POST['crew_id'] ?? null;
    if ($crew_id) {
        $delete = $pdo->prepare("DELETE FROM crewlist WHERE id = ?");
        $delete->execute([$crew_id]);
        $toastMessage = "✅ Crew record deleted successfully.";
    }
}

// ✅ CSV import with duplicate check & missing data handling
if (isset($_POST['import_excel'])) {
    if ($_FILES['excel_file']['error'] == 0) {
        $file = fopen($_FILES['excel_file']['tmp_name'], "r");
        fgetcsv($file); // Skip header

        $sql = "INSERT INTO crewlist (full_name, email, nationality, category, crew_role, dob) 
                VALUES (?, ?, ?, ?, ?, ?)";
        $stmt = $pdo->prepare($sql);

        while (($row = fgetcsv($file, 1000, ",")) !== FALSE) {
            $row = array_pad($row, 6, "");

            $name = trim($row[0]);
            $email = trim($row[1]);
            $nationality = !empty($row[2]) ? $row[2] : "";
            $category = !empty($row[3]) ? $row[3] : "";
            $role = !empty($row[4]) ? $row[4] : "";
            $dob = !empty($row[5]) ? $row[5] : null;

            if ($name === "")
                continue;

            $check = $pdo->prepare("SELECT COUNT(*) FROM crewlist WHERE full_name = ? AND email = ?");
            $check->execute([$name, $email]);
            $exists = $check->fetchColumn();

            if ($exists == 0) {
                $stmt->execute([$name, $email, $nationality, $category, $role, $dob]);
            }
        }
        fclose($file);
    }
}

// ✅ Inline update for crew record
if (isset($_POST['update_crew'])) {
    $crew_id = $_POST['crew_id'];
    $name = trim($_POST['full_name']);
    $email = trim($_POST['email']);
    $nationality = $_POST['nationality'] ?? "";
    $category = $_POST['category'] ?? "";
    $role = $_POST['crew_role'] ?? "";
    $dob = !empty($_POST['dob']) ? $_POST['dob'] : null;

    $update = $pdo->prepare("UPDATE crewlist 
                             SET full_name = ?, email = ?, nationality = ?, category = ?, crew_role = ?, dob = ? 
                             WHERE id = ?");
    $update->execute([$name, $email, $nationality, $category, $role, $dob, $crew_id]);
}

// Fetch crew list
$stmt = $pdo->query("SELECT * FROM crewlist ORDER BY full_name ASC");
$crewlist = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <link href="https://fonts.googleapis.com/css2?family=Material+Icons+Sharp" rel="stylesheet" />
    <link rel="stylesheet" href="style.css" />
    <title>Manage Users</title>
    <style>
        /* ---------- MAIN LAYOUT ---------- */
        main {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 2rem;
            padding: 20px;
        }

        /* ---------- FORM SECTION ---------- */
        .form-section {
            display: flex;
            gap: 2rem;
            justify-content: center;
            flex-wrap: wrap;
            width: 100%;
            max-width: 1200px;
        }

        .card-form {
            flex: 1;
            min-width: 320px;
            background: var(--color-white);
            padding: var(--card-padding);
            border-radius: var(--card-border-radius);
            box-shadow: var(--box-shadow);
            display: flex;
            flex-direction: column;
            gap: 1rem;
            transition: background 0.3s ease, box-shadow 0.3s ease;
        }

        .card-form h3 {
            margin: 0;
            color: var(--color-dark);
            font-size: 1.1rem;
            font-weight: 600;
        }

        /* Labels + Inputs */
        .form-group {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }

        .form-group label {
            font-size: 0.9rem;
            font-weight: 500;
            color: var(--color-dark);
        }

        .card-form input,
        .card-form select {
            padding: 0.8rem 1rem;
            border: 1px solid var(--color-light);
            border-radius: var(--border-radius-1);
            font-size: 0.95rem;
            background: var(--color-background);
            color: var(--color-dark);
            transition: border 0.2s ease, box-shadow 0.2s ease;
            width: 100%;
        }

        .card-form input:focus,
        .card-form select:focus {
            border-color: var(--color-primary);
            outline: none;
            box-shadow: 0 0 0 3px rgba(108, 155, 207, 0.25);
        }

        /* Grid layout inside form */
        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
        }

        /* ---------- BUTTONS ---------- */
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 0.8rem 1.4rem;
            background: var(--color-primary);
            color: var(--color-white);
            border: none;
            border-radius: 50px;
            cursor: pointer;
            font-size: 0.95rem;
            font-weight: 500;
            transition: background 0.3s ease, transform 0.15s ease, box-shadow 0.2s ease;
            box-shadow: 0 4px 10px rgba(108, 155, 207, 0.3);
        }

        .btn:hover {
            background: #5a84b2;
            transform: translateY(-2px);
            box-shadow: 0 6px 14px rgba(108, 155, 207, 0.35);
        }

        .btn:active {
            transform: translateY(1px);
        }

        .btn-success {
            background: var(--color-success);
            box-shadow: 0 4px 10px rgba(27, 156, 133, 0.3);
        }

        .btn-success:hover {
            background: #148a72;
        }

        .btn-danger {
            background: var(--color-danger);
            box-shadow: 0 4px 10px rgba(255, 0, 96, 0.3);
        }

        .btn-danger:hover {
            background: #d40050;
        }

        .btn-sm {
            padding: 4px 10px;
            font-size: 0.8rem;
            border-radius: 6px;
        }

        /* ---------- TABLE SECTION ---------- */
        .table-section {
            width: 100%;
            max-width: 1200px;
        }

        table {
            border-collapse: collapse;
            width: 100%;
            background: var(--color-white);
            border-radius: var(--card-border-radius);
            overflow: hidden;
            box-shadow: var(--box-shadow);
            margin-top: 1rem;
        }

        table th,
        table td {
            padding: 0.9rem 1rem;
            border-bottom: 1px solid var(--color-light);
            text-align: left;
            font-size: 0.9rem;
            color: var(--color-dark);
        }

        table th {
            background: var(--color-primary);
            color: var(--color-white);
            font-weight: 600;
            text-transform: uppercase;
            font-size: 0.8rem;
        }

        table tr:hover {
            background: var(--color-background);
        }

        /* Editable input mode inside table */
        table input,
        table select {
            padding: 6px 8px;
            font-size: 0.85rem;
            color: var(--color-dark);
            background-color: var(--color-background);
            border: 1px solid var(--color-primary);
            border-radius: var(--border-radius-1);
        }

        /* ---------- DATE PICKER ---------- */
        input[type="date"]::-webkit-calendar-picker-indicator {
            filter: invert(34%) sepia(91%) saturate(542%) hue-rotate(180deg) brightness(90%) contrast(95%);
            cursor: pointer;
        }

        input[type="date"] {
            color-scheme: light dark;
        }

        input[type="date"]::-moz-focus-inner {
            border: 0;
        }

        /* ---------- SEARCH & FILTER ---------- */
        .search-filters {
            display: flex;
            flex-direction: row;
            align-items: center;
            justify-content: flex-start;
            gap: 1rem;
            margin-bottom: 1rem;
            flex-wrap: wrap;
            /* allow stacking on small screens */
        }

        /* Search input */
        .search-input {
            padding: 0.6rem 1rem;
            border: 1px solid var(--color-light);
            border-radius: var(--border-radius-1);
            font-size: 0.9rem;
            background: var(--color-white);
            color: var(--color-dark);
            box-shadow: var(--box-shadow);
            width: 220px;
            transition: all 0.2s ease;
        }

        .search-input:focus {
            border-color: var(--color-primary);
            box-shadow: 0 0 0 3px rgba(108, 155, 207, 0.25);
            outline: none;
        }

        /* Filter dropdown */
        .filter-select {
            padding: 0.6rem 1rem;
            border: 1px solid var(--color-light);
            border-radius: var(--border-radius-1);
            font-size: 0.9rem;
            background: var(--color-white);
            color: var(--color-dark);
            box-shadow: var(--box-shadow);
            width: 180px;
            transition: all 0.2s ease;
        }

        .filter-select:focus {
            border-color: var(--color-primary);
            box-shadow: 0 0 0 3px rgba(108, 155, 207, 0.25);
            outline: none;
        }

        .scroll-table {
            max-height: 350px;
            /* about 6 rows */
            overflow-y: auto;
            border-radius: var(--card-border-radius);
            box-shadow: var(--box-shadow);
        }

        /* Fix the header from scrolling out */
        .scroll-table thead th {
            position: sticky;
            top: 0;
            background: var(--color-primary);
            color: white;
            z-index: 2;
        }

        /* Optional smoother scroll look */
        .scroll-table::-webkit-scrollbar {
            width: 8px;
        }

        .scroll-table::-webkit-scrollbar-thumb {
            background: #ccc;
            border-radius: 4px;
        }

        .scroll-table::-webkit-scrollbar-thumb:hover {
            background: #999;
        }

        /* ✅ Toast popup styling */
        .toast {
            position: fixed;
            bottom: 25px;
            right: 25px;
            z-index: 9999;
            background: var(--color-success);
            color: #fff;
            padding: 12px 18px;
            border-radius: 8px;
            box-shadow: 0 6px 18px rgba(0, 0, 0, 0.15);
            font-weight: 600;
            font-size: 0.95rem;
            opacity: 1;
            transition: opacity 0.5s ease;
        }

        .toast.hide {
            opacity: 0;
        }

        /* ---------- EDIT MODAL ---------- */
        .modal-backdrop {
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, .4);
            display: none;
            /* toggled via JS */
            align-items: center;
            justify-content: center;
            z-index: 10000;
        }

        .modal {
            width: min(680px, 92vw);
            background: var(--color-white);
            border-radius: var(--card-border-radius);
            box-shadow: 0 20px 60px rgba(0, 0, 0, .2);
            overflow: hidden;
        }

        .modal header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 1rem 1.25rem;
            background: var(--color-primary);
            color: #fff;
        }

        .modal header h3 {
            margin: 0;
            font-size: 1.05rem;
            font-weight: 600;
        }

        .modal header button {
            background: transparent;
            border: 0;
            color: #fff;
            font-size: 1.4rem;
            cursor: pointer;
        }

        .modal .content {
            padding: 1.25rem;
            display: grid;
            gap: 1rem;
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }

        .modal .content .full {
            grid-column: 1 / -1;
        }

        .modal .actions {
            display: flex;
            gap: .6rem;
            justify-content: flex-end;
            padding: 1rem 1.25rem;
            background: var(--color-background);
        }

        .modal input,
        .modal select {
            width: 100%;
            padding: .75rem 1rem;
            border: 1px solid var(--color-light);
            border-radius: var(--border-radius-1);
            background: var(--color-background);
            color: var(--color-dark);
        }

        /* show modal */
        .modal-backdrop.show {
            display: flex;
        }

        /* ---------- DARK MODE FIX FOR MODAL ---------- */
        body.dark-mode .modal {
            background: var(--color-dark);
            color: var(--color-white);
        }

        body.dark-mode .modal header {
            background: var(--color-primary);
            color: var(--color-white);
        }

        body.dark-mode .modal input,
        body.dark-mode .modal select {
            background: var(--color-dark-variant);
            color: var(--color-white);
            border: 1px solid var(--color-light);
        }

        body.dark-mode .modal label {
            color: var(--color-white);
        }
    </style>
</head>

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
                <a href="admin-dashboard.php"><span class="material-icons-sharp">dashboard</span>
                    <h3>Dashboard</h3>
                </a>
                <a href="manage-user.php"><span class="material-icons-sharp">group</span>
                    <h3>Manage Users</h3>
                </a>
                <a href="crew-list.php" class="active"><span class="material-icons-sharp">groups</span>
                    <h3>Crew List</h3>
                </a>
                <a href="manage-vessel.php"><span class="material-icons-sharp">directions_boat</span>
                    <h3>Manage Vessel</h3>
                </a>
                <a href="reports.php"><span class="material-icons-sharp">bar_chart</span>
                    <h3>Reports</h3>
                </a>
                <a href="settings.php"><span class="material-icons-sharp">settings</span>
                    <h3>Settings</h3>
                </a>
                <a href="login.php"><span class="material-icons-sharp">logout</span>
                    <h3>LogOut</h3>
                </a>
            </div>
        </aside>

        <main>
            <h1>Crew List</h1>

            <!-- 📌 OFFSHORE CREW TABLE -->
            <div class="table-section">

                <div class="search-filters">
                    <input type="text" id="crewSearch" class="search-input" placeholder="🔎 Search offshore crew...">
                    <select id="categoryFilter" class="filter-select">
                        <option value="">All Categories</option>
                        <option value="CREW">CREW</option>
                        <option value="PASSENGER">PASSENGER</option>
                        <option value="VISITOR">VISITOR</option>
                        <option value="CONTRACTOR">CONTRACTOR</option>
                        <option value="ADMIN">ADMIN</option>
                        <option value="OFFICE">OFFICE</option>
                    </select>
                </div>
                <h2>🚢 Offshore Crew (Vessel-Based)</h2>
                <div class="scroll-table">
                    <table id="crewTable">
                        <thead>
                            <tr>
                                <th>No.</th>
                                <th>Full Name</th>
                                <th>Email</th>
                                <th>Nationality</th>
                                <th>Category</th>
                                <th>Crew Role</th>
                                <th>DOB</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $offshoreStmt = $pdo->query("
                        SELECT * FROM crewlist 
    WHERE UPPER(category) NOT IN ('ADMIN', 'OFFICE') 
    ORDER BY full_name ASC
                    ");
                            $offshoreList = $offshoreStmt->fetchAll(PDO::FETCH_ASSOC);

                            if (count($offshoreList) > 0):
                                $i = 1;
                                foreach ($offshoreList as $row): ?>
                                    <tr data-id="<?= $row['id'] ?>">
                                        <td><?= $i++ ?></td>
                                        <td class="full_name"><?= htmlspecialchars($row['full_name']) ?></td>
                                        <td class="email"><?= htmlspecialchars($row['email']) ?></td>
                                        <td class="nationality"><?= htmlspecialchars($row['nationality']) ?></td>
                                        <td class="category"><?= htmlspecialchars($row['category']) ?></td>
                                        <td class="crew_role"><?= htmlspecialchars($row['crew_role']) ?></td>
                                        <td class="dob"><?= htmlspecialchars($row['dob']) ?></td>
                                        <td>
                                            <button type="button" class="btn btn-success btn-sm edit-btn">Edit</button>
                                            <form method="POST" style="display:inline;">
                                                <input type="hidden" name="crew_id" value="<?= $row['id'] ?>">
                                                <button type="submit" name="delete_crew" class="btn btn-danger btn-sm"
                                                    onclick="return confirm('Delete <?= htmlspecialchars($row['full_name']) ?>?');">
                                                    Delete
                                                </button>
                                            </form>
                                        </td>

                                    </tr>
                                <?php endforeach;
                            else: ?>
                                <tr>
                                    <td colspan="8">No offshore crew found</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- 📌 ONSHORE CREW TABLE -->
            <div class="table-section">
                <h2>🏢 Onshore Crew (Admin / Office)</h2>

                <div class="scroll-table">
                    <table id="onshoreTable">
                        <thead>
                            <tr>
                                <th>No.</th>
                                <th>Full Name</th>
                                <th>Email</th>
                                <th>Nationality</th>
                                <th>Category</th>
                                <th>Crew Role</th>
                                <th>DOB</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $onshoreStmt = $pdo->query("
                          SELECT * FROM crewlist 
    WHERE UPPER(category) IN ('ADMIN', 'OFFICE') 
    ORDER BY full_name ASC
                    ");
                            $onshoreList = $onshoreStmt->fetchAll(PDO::FETCH_ASSOC);

                            if (count($onshoreList) > 0):
                                $i = 1;
                                foreach ($onshoreList as $row): ?>
                                    <tr data-id="<?= $row['id'] ?>">
                                        <td><?= $i++ ?></td>
                                        <td class="full_name"><?= htmlspecialchars($row['full_name']) ?></td>
                                        <td class="email"><?= htmlspecialchars($row['email']) ?></td>
                                        <td class="nationality"><?= htmlspecialchars($row['nationality']) ?></td>
                                        <td class="category"><?= htmlspecialchars($row['category']) ?></td>
                                        <td class="crew_role"><?= htmlspecialchars($row['crew_role']) ?></td>
                                        <td class="dob"><?= htmlspecialchars($row['dob']) ?></td>
                                        <td>
                                            <button type="button" class="btn btn-success btn-sm edit-btn">Edit</button>
                                            <form method="POST" style="display:inline;">
                                                <input type="hidden" name="crew_id" value="<?= $row['id'] ?>">
                                                <button type="submit" name="delete_crew" class="btn btn-danger btn-sm"
                                                    onclick="return confirm('Delete <?= htmlspecialchars($row['full_name']) ?>?');">
                                                    Delete
                                                </button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach;
                            else: ?>
                                <tr>
                                    <td colspan="7">No onshore staff found</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- 📌 Forms Section -->
            <div class="form-section">
                <!-- Add Crew Form -->
                <form method="POST" class="card-form">
                    <h3>Add Crew</h3>
                    <div class="form-group"><label>Full Name</label><input type="text" name="full_name" required></div>
                    <div class="form-group"><label>Email</label><input type="email" name="email"></div>
                    <div class="form-group"><label>Nationality</label><input type="text" name="nationality"></div>
                    <div class="form-group">
                        <label>Category</label>
                        <select name="category" required>
                            <option value="">-- Select --</option>
                            <option value="CREW">CREW</option>
                            <option value="PASSENGER">PASSENGER</option>
                            <option value="VISITOR">VISITOR</option>
                            <option value="CONTRACTOR">CONTRACTOR</option>
                            <option value="ADMIN">ADMIN</option>
                            <option value="OFFICE">OFFICE</option>
                        </select>
                    </div>
                    <div class="form-group"><label>Crew Role</label><input type="text" name="crew_role"></div>
                    <div class="form-group"><label>DOB</label><input type="date" name="dob"></div>
                    <button type="submit" name="add_crew" class="btn btn-success">➕ Add</button>
                </form>

                <!-- Import Form -->
                <form method="POST" enctype="multipart/form-data" class="card-form">
                    <h3>Import CSV</h3>
                    <div class="form-group"><label>Upload File</label><input type="file" name="excel_file" accept=".csv"
                            required></div>
                    <button type="submit" name="import_excel" class="btn">📂 Import</button>
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
                    <p>Admin Console</p>
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
                            <h3>Use filters to narrow the list</h3>
                            <small class="text-muted">Role • Vessel • Search</small>
                        </div>
                    </div>
                </div>
            </div>
        </div><!-- /right-section -->
    </div><!-- /container -->

    <!-- TOAST -->
    <?php if ($toastMessage): ?>
        <div class="toast" id="toast"><?= htmlspecialchars($toastMessage) ?></div>
    <?php endif; ?>

    <!-- EDIT CREW MODAL -->
    <div class="modal-backdrop" id="editCrewModal">
        <div class="modal" role="dialog" aria-modal="true" aria-labelledby="editCrewTitle">
            <header>
                <h3 id="editCrewTitle">Edit Crew</h3>
                <button type="button" id="closeEditModal" title="Close">&times;</button>
            </header>

            <form method="POST" id="editCrewForm">
                <div class="content">
                    <input type="hidden" name="crew_id" id="edit_crew_id">

                    <div>
                        <label>Full Name</label>
                        <input type="text" name="full_name" id="edit_full_name" required>
                    </div>

                    <div>
                        <label>Email</label>
                        <input type="email" name="email" id="edit_email">
                    </div>

                    <div>
                        <label>Nationality</label>
                        <input type="text" name="nationality" id="edit_nationality">
                    </div>

                    <div>
                        <label>Category</label>
                        <select name="category" id="edit_category" required>
                            <option value="CREW">CREW</option>
                            <option value="PASSENGER">PASSENGER</option>
                            <option value="VISITOR">VISITOR</option>
                            <option value="CONTRACTOR">CONTRACTOR</option>
                            <option value="ADMIN">ADMIN</option>
                            <option value="OFFICE">OFFICE</option>
                        </select>
                    </div>

                    <div>
                        <label>Crew Role</label>
                        <input type="text" name="crew_role" id="edit_crew_role">
                    </div>

                    <div>
                        <label>DOB</label>
                        <input type="date" name="dob" id="edit_dob">
                    </div>
                </div>

                <div class="actions">
                    <button type="button" class="btn btn-danger btn-sm" id="cancelEdit">Cancel</button>
                    <button type="submit" name="update_crew" class="btn btn-success btn-sm">Save Changes</button>
                </div>
            </form>
        </div>
    </div>

<script>
  // 🔎 Search + Filter (applies to BOTH Offshore and Onshore tables)
  function filterCrew() {
    const textFilter = document.getElementById('crewSearch').value.toLowerCase().trim();
    const categoryFilter = document.getElementById('categoryFilter').value.toLowerCase().trim();

    ['#crewTable', '#onshoreTable'].forEach((sel) => {
      const rows = document.querySelectorAll(`${sel} tbody tr`);
      rows.forEach((row) => {
        const cells = row.getElementsByTagName('td');
        if (!cells || cells.length === 0) return; // skip safety

        const rowText = row.innerText.toLowerCase();
        const category = (cells[4]?.innerText || '').toLowerCase().trim();

        const matchText = rowText.includes(textFilter);
        const matchCategory = (categoryFilter === '' || category === categoryFilter);

        row.style.display = (matchText && matchCategory) ? '' : 'none';
      });
    });
  }

  document.getElementById('crewSearch').addEventListener('keyup', filterCrew);
  document.getElementById('categoryFilter').addEventListener('change', filterCrew);
</script>


    <script>
        // ✅ Modal logic (replaces old openModal/inline-edit)
        (function () {
            const modal = document.getElementById('editCrewModal');
            const closeBtn = document.getElementById('closeEditModal');
            const cancelBtn = document.getElementById('cancelEdit');
            const form = document.getElementById('editCrewForm');

            function showModal() {
                modal.classList.add('show');
                document.body.style.overflow = 'hidden';
            }
            function hideModal() {
                modal.classList.remove('show');
                document.body.style.overflow = '';
            }

            function getCellText(row, selector) {
                const cell = row.querySelector(selector);
                return cell ? cell.textContent.trim() : '';
            }

            function toISODateString(text) {
                if (!text) return '';
                if (/^\d{4}-\d{2}-\d{2}$/.test(text)) return text;
                const d = new Date(text);
                if (isNaN(d)) return '';
                const mm = String(d.getMonth() + 1).padStart(2, '0');
                const dd = String(d.getDate()).padStart(2, '0');
                return `${d.getFullYear()}-${mm}-${dd}`;
            }

            document.addEventListener('click', function (e) {
                if (!e.target.classList.contains('edit-btn')) return;

                const row = e.target.closest('tr');
                const id = row.getAttribute('data-id') || '';
                const name = getCellText(row, '.full_name');
                const email = getCellText(row, '.email');
                const nationality = getCellText(row, '.nationality');
                const category = getCellText(row, '.category').toUpperCase();
                const crewRole = getCellText(row, '.crew_role');
                const dobRaw = getCellText(row, '.dob');

                document.getElementById('edit_crew_id').value = id;
                document.getElementById('edit_full_name').value = name;
                document.getElementById('edit_email').value = email;
                document.getElementById('edit_nationality').value = nationality;
                document.getElementById('edit_category').value = ['CREW', 'PASSENGER', 'VISITOR', 'CONTRACTOR', 'ADMIN', 'OFFICE'].includes(category) ? category : 'CREW';
                document.getElementById('edit_crew_role').value = crewRole;
                document.getElementById('edit_dob').value = toISODateString(dobRaw);

                showModal();
            });

            closeBtn.addEventListener('click', hideModal);
            cancelBtn.addEventListener('click', hideModal);
            modal.addEventListener('click', (e) => { if (e.target === modal) hideModal(); });
            document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && modal.classList.contains('show')) hideModal(); });
        })();
    </script>

    <script>
        document.addEventListener("DOMContentLoaded", () => {
            const toast = document.getElementById('toast');
            if (toast) {
                setTimeout(() => toast.classList.add('hide'), 2000); // fade out after 2s
                setTimeout(() => toast.remove(), 2600); // remove from DOM
            }
        });
    </script>

    <script src="index.js"></script>
    <script src="appointment.js"></script>
    <script src="clock.js"></script>
</body>

</html>