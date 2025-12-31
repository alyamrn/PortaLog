<?php
session_start();
require_once "db_connect.php";

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'OFFICE') {
    header("Location: login.php");
    exit;
}

$username = $_SESSION['full_name'];
$role = $_SESSION['role'];

// --- Filters ---
$vessel_id = $_GET['vessel_id'] ?? '';
$date = $_GET['date'] ?? date('Y-m-d');

// Vessel list
$vessels = $pdo->query("SELECT vessel_id, vessel_name FROM vessels ORDER BY vessel_name")->fetchAll(PDO::FETCH_ASSOC);

/* === Fetch POB data (AS-OF by embark/disembark) ===
   Active on $date if: embark_date <= $date AND (disembark_date IS NULL OR disembark_date >= $date)
*/
$query = "
    SELECT 
        v.vessel_name,
        c.full_name,
        s.category,
        s.crew_role,
        s.embark_date,
        s.disembark_date,
        s.remarks
    FROM pob_stints s
    JOIN vessels v  ON v.vessel_id = s.vessel_id
    JOIN crewlist c ON c.id = s.person_id
    WHERE s.embark_date <= ?
      AND (s.disembark_date IS NULL OR s.disembark_date >= ?)
";
$params = [$date, $date];

if (!empty($vessel_id)) {
    $query .= " AND s.vessel_id = ?";
    $params[] = $vessel_id;
}

$query .= " ORDER BY v.vessel_name, c.full_name";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$pobList = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* === Totals by category (AS-OF on $date) === */
$totalQuery = "
    SELECT s.category, COUNT(*) AS total
    FROM pob_stints s
    WHERE s.embark_date <= ?
      AND (s.disembark_date IS NULL OR s.disembark_date >= ?)
";
$totalParams = [$date, $date];

if (!empty($vessel_id)) {
    $totalQuery .= " AND s.vessel_id = ?";
    $totalParams[] = $vessel_id;
}

$totalQuery .= " GROUP BY s.category";
$totalStmt = $pdo->prepare($totalQuery);
$totalStmt->execute($totalParams);
$totals = $totalStmt->fetchAll(PDO::FETCH_KEY_PAIR);
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>POB Monitoring</title>
    <link href="https://fonts.googleapis.com/css2?family=Material+Icons+Sharp" rel="stylesheet">
    <link rel="stylesheet" href="style.css">
    <style>
        /* === FILTER SECTION === */
        .filter-section {
            display: flex;
            align-items: center;
            justify-content: flex-start;
            background: var(--color-white);
            padding: 1.2rem 1.5rem;
            border-radius: var(--border-radius-2);
            box-shadow: var(--box-shadow);
            margin-bottom: 1.5rem;
            flex-wrap: wrap;
            gap: 1.5rem;
        }

        .filter-section form {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 1.5rem;
            width: 100%;
        }

        .filter-section label {
            font-weight: 600;
            color: var(--color-dark);
        }

        .filter-section select,
        .filter-section input[type="date"] {
            padding: 0.6rem 0.9rem;
            border: 1px solid var(--color-light);
            border-radius: var(--border-radius-1);
            background: var(--color-background);
            color: var(--color-dark);
            font-size: 0.9rem;
            min-width: 180px;
            transition: all 0.2s ease;
        }

        .filter-section select:focus,
        .filter-section input[type="date"]:focus {
            outline: none;
            border-color: var(--color-primary);
            box-shadow: 0 0 0 2px rgba(108, 155, 207, 0.25);
        }

        /* === Calendar Icon Styling for <input type="date"> === */
        input[type="date"]::-webkit-calendar-picker-indicator {
            filter: invert(47%) sepia(54%) saturate(500%) hue-rotate(180deg) brightness(96%) contrast(92%);
            cursor: pointer;
            transition: 0.3s ease;
        }

        input[type="date"]:hover::-webkit-calendar-picker-indicator {
            filter: invert(37%) sepia(83%) saturate(470%) hue-rotate(115deg) brightness(95%) contrast(92%);
        }

        .dark-mode-variables input[type="date"]::-webkit-calendar-picker-indicator {
            filter: invert(90%) sepia(10%) saturate(200%) hue-rotate(190deg) brightness(120%) contrast(90%);
        }

        .filter-section button {
            background: var(--color-primary);
            color: #fff;
            padding: 0.6rem 1.4rem;
            border: none;
            border-radius: var(--border-radius-1);
            cursor: pointer;
            font-weight: 600;
            transition: background 0.25s ease, transform 0.15s ease;
        }

        .filter-section button:hover {
            background: var(--color-success);
            transform: translateY(-2px);
        }

        /* === SUMMARY SECTION === */
        .summary-card {
            background: var(--color-white);
            padding: 1.5rem 1.8rem;
            border-radius: var(--border-radius-2);
            box-shadow: var(--box-shadow);
            margin-top: 1.5rem;
        }

        .summary-card h3 {
            color: var(--color-dark);
            font-weight: 600;
            margin-bottom: 1rem;
        }

        .summary-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 1.2rem;
        }

        .summary-item {
            background: var(--color-background);
            border-radius: var(--border-radius-1);
            padding: 1rem;
            text-align: center;
            box-shadow: inset 0 0 0 1px var(--color-light);
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }

        .summary-item:hover {
            transform: translateY(-3px);
            box-shadow: 0 0.5rem 1rem var(--color-light);
        }

        .summary-item h3 {
            color: var(--color-dark-variant);
            font-size: 0.9rem;
            margin-bottom: 0.4rem;
            text-transform: uppercase;
        }

        .summary-item p {
            font-size: 1.4rem;
            font-weight: 700;
            color: var(--color-primary);
        }

        /* === TABLE STYLING === */
        .pob-table {
            width: 100%;
            border-collapse: collapse;
            background: var(--color-white);
            border-radius: var(--border-radius-2);
            overflow: hidden;
            box-shadow: var(--box-shadow);
            margin-top: 1.8rem;
            font-size: 0.9rem;
        }

        .pob-table th {
            background: var(--color-primary);
            color: #fff;
            padding: 10px;
            text-align: center;
            font-weight: 600;
        }

        .pob-table td {
            border-bottom: 1px solid var(--color-light);
            padding: 10px;
            text-align: center;
            color: var(--color-dark);
            transition: background 0.2s ease;
        }

        .pob-table tr:nth-child(even) td {
            background: var(--color-background);
        }

        .pob-table tr:hover td {
            background: rgba(108, 155, 207, 0.1);
        }

        .pob-table td[colspan] {
            color: var(--color-info-dark);
            font-style: italic;
        }

        @media (max-width: 768px) {
            .summary-grid {
                grid-template-columns: 1fr 1fr;
            }

            .filter-section form {
                flex-direction: column;
                align-items: flex-start;
            }
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
                <a href="office-dashboard.php"><span class="material-icons-sharp">dashboard</span>
                    <h3>Dashboard</h3>
                </a>
                <a href="office-vessel-report.php"><span class="material-icons-sharp">directions_boat</span>
                    <h3>Vessel Reports</h3>
                </a>
                <a href="office-analysis.php"><span class="material-icons-sharp">bar_chart</span>
                    <h3>Data Analytics</h3>
                </a>
                <a href="office-export.php"><span class="material-icons-sharp">file_download</span>
                    <h3>Generate Reports</h3>
                </a>
                <a href="office-pob.php" class="active"><span class="material-icons-sharp">groups</span>
                    <h3>POB Monitoring</h3>
                </a>
                <a href="office-status.php"><span class="material-icons-sharp">assignment_turned_in</span>
                    <h3>Report Status</h3>
                </a>
                <a href="settings.php"><span class="material-icons-sharp">settings</span>
                    <h3>Settings</h3>
                </a>
                <a href="login.php" class="logout"><span class="material-icons-sharp">logout</span>
                    <h3>Logout</h3>
                </a>
            </div>
        </aside>

        <!-- Main Section -->
        <main>
            <h2>👥 POB Monitoring</h2>

            <!-- Filter -->
            <div class="filter-section">
                <form method="GET">
                    <label><b>Vessel:</b></label>
                    <select name="vessel_id">
                        <option value="">All Vessels</option>
                        <?php foreach ($vessels as $v): ?>
                            <option value="<?= $v['vessel_id'] ?>" <?= $vessel_id == $v['vessel_id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($v['vessel_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <label><b>Date:</b></label>
                    <input type="date" name="date" value="<?= htmlspecialchars($date) ?>">

                    <button type="submit">🔍 Filter</button>
                </form>
            </div>

            <!-- Summary -->
            <div class="summary-card">
                <h3>POB Summary for <?= htmlspecialchars($date) ?></h3>
                <div class="summary-grid">
                    <div class="summary-item">
                        <h3>Crew</h3>
                        <p><?= $totals['CREW'] ?? 0 ?></p>
                    </div>
                    <div class="summary-item">
                        <h3>Passenger</h3>
                        <p><?= $totals['PASSENGER'] ?? 0 ?></p>
                    </div>
                    <div class="summary-item">
                        <h3>Visitor</h3>
                        <p><?= $totals['VISITOR'] ?? 0 ?></p>
                    </div>
                    <div class="summary-item">
                        <h3>Contractor</h3>
                        <p><?= $totals['CONTRACTOR'] ?? 0 ?></p>
                    </div>
                </div>
            </div>

            <!-- Table -->
            <table class="pob-table">
                <thead>
                    <tr>
                        <th>Vessel Name</th>
                        <th>Full Name</th>
                        <th>Category</th>
                        <th>Crew Role</th>
                        <th>Embark Date</th>
                        <th>Disembark Date</th>
                        <th>Remarks</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($pobList)): ?>
                        <tr>
                            <td colspan="7" style="color:gray;">No POB data available.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($pobList as $p): ?>
                            <tr>
                                <td><?= htmlspecialchars($p['vessel_name']) ?></td>
                                <td><?= htmlspecialchars($p['full_name']) ?></td>
                                <td><?= htmlspecialchars($p['category']) ?></td>
                                <td><?= htmlspecialchars($p['crew_role'] ?? '') ?></td>
                                <td><?= htmlspecialchars($p['embark_date'] ?? '') ?></td>
                                <td><?= htmlspecialchars($p['disembark_date'] ?? '') ?></td>
                                <td><?= htmlspecialchars($p['remarks'] ?? '') ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
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
                        <p>Hey, <b><?= htmlspecialchars($username) ?></b></p>
                        <small class="text-muted"><?= htmlspecialchars($role) ?></small>
                    </div>
                    <div class="profile-photo"><img src="image/blankProf.png" alt="Profile"></div>
                </div>
            </div>
            <div class="user-profile">
                <div class="logo">
                    <img src="image/BSK_LOGO.jpg" alt="Logo">
                    <h2>PortaLog</h2>
                    <p>Office Console</p>
                </div>
            </div>
        </div>
    </div>

    <script src="index.js"></script>
</body>

</html>