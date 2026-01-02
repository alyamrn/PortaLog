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


    /* ================= CONVERTER GRID ================= */

    .converter-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
        gap: 16px;
    }

    /* ================= CONVERTER CARD ================= */

    .converter-box {
        background: var(--color-background);
        border: 1px solid rgba(255, 255, 255, 0.12);
        border-radius: var(--border-radius-1);
        padding: 14px;
        transition:
            transform 0.15s ease,
            box-shadow 0.15s ease,
            border-color 0.15s ease;
    }

    /* Light mode border */
    body:not(.dark-mode) .converter-box {
        border-color: rgba(0, 0, 0, 0.12);
    }

    /* Hover */
    .converter-box:hover {
        transform: translateY(-2px);
        border-color: var(--accent);
        box-shadow: 0 10px 22px rgba(0, 0, 0, 0.12);
    }

    /* ================= INPUTS & SELECT ================= */

    .converter-box input,
    .converter-box select {
        width: 100%;
        padding: 8px 10px;
        margin-bottom: 8px;
        border-radius: var(--border-radius-1);
        border: 1px solid rgba(255, 255, 255, 0.08);
        background: var(--color-white);
        color: var(--color-dar);
        font-size: 0.9rem;
        transition: border-color 0.15s ease, box-shadow 0.15s ease;
    }

    /* Focus */
    .converter-box input:focus,
    .converter-box select:focus {
        outline: none;
        border-color: var(--accent);
        box-shadow: 0 0 0 2px rgba(100, 181, 246, 0.25);
    }

    /* ================= RESULT ================= */

    .converter-result {
        margin-top: 6px;
        padding: 8px 10px;
        border-radius: var(--border-radius-1);
        background: var(--color-white);
        border: 1px dashed var(--color-dark);
        font-weight: 500;
        font-size: 0.9rem;
        color: var(--color-dark);
    }







    /* ================= UTILITIES (NON-CONVERTER) ================= */

    .utility-card {
        background: var(--color-background);
        border: 1px solid var(--line);
        border-radius: var(--border-radius-2);
        padding: 18px 20px;
    }

    /* Utility grid (Time & Watch) */
    .utility-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
        gap: 16px;
    }

    /* Utility box (same visual weight as converter-box) */
    .utility-box {
        background: var(--card);
        border: 1px solid rgba(255, 255, 255, 0.15);
        border-radius: var(--border-radius-1);
        padding: 14px;
        transition: border-color 0.15s ease, box-shadow 0.15s ease;
    }



    /* Hover feedback */
    .utility-box:hover {
        border-color: var(--accent);
        box-shadow: 0 8px 18px rgba(0, 0, 0, 0.08);
    }

    /* Labels */
    .utility-box label {
        display: block;
        margin-bottom: 6px;
        font-size: 0.85rem;
        font-weight: 600;
        color: var(--ink);
    }

    /* Inputs (reuse converter feel) */
    .utility-box input {
        width: 100%;
        padding: 8px 10px;
        margin-bottom: 8px;
        border-radius: var(--border-radius-1);
        border: 1px solid rgba(255, 255, 255, 0.18);
        background: var(--color-white);
        color: var(--color-dark);
    }



    .utility-box input:focus {
        outline: none;
        border-color: var(--color-dark);
        box-shadow: 0 0 0 2px rgba(100, 181, 246, 0.25);
    }

    /* Utility results */
    .utility-result {
        margin-top: 6px;
        padding: 8px 10px;
        border-radius: var(--border-radius-1);
        background: rgba(255, 255, 255, 0.06);
        border: 1px dashed var(--color-dark);
        font-size: 0.9rem;
        font-weight: 500;
        color: var(--color-dark);
    }



    /* Info cards text */
    .info-card ul {
        padding-left: 18px;
        line-height: 1.7;
        font-size: 0.9rem;
    }


    @keyframes fadeIn {
        from {
            opacity: 0;
            transform: translateY(6px);
        }

        to {
            opacity: 1;
            transform: translateY(0);
        }
    }

    /* ================= RESPONSIVE ================= */

    @media (max-width: 768px) {

        .converter-grid,
        .info-grid,
        .two-col {
            grid-template-columns: 1fr;
        }

        .help-page .page-title {
            font-size: 1.2rem;
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
                <a href="help.php" class="active"><span class="material-icons-sharp">help</span>
                    <h3>Help</h3>
                </a>
                <a href="login.php" class="logout"><span class="material-icons-sharp">logout</span>
                    <h3>LogOut</h3>
                </a>
            </div>
        </aside>

        <!-- Main Content -->
        <main>
            <div class="availability-table help-page">

                <h2 class="page-title">
                    <span class="material-icons-sharp">support</span>
                    Help & Utilities
                </h2>

                <!-- ================= UNIT CONVERTER ================= -->
                <section class="help-card">
                    <header class="help-card-header">
                        <span class="material-icons-sharp">calculate</span>
                        <h3>Maritime Unit Converter</h3>
                    </header>

                    <div class="converter-grid">

                        <!-- Length -->
                        <div class="converter-box">
                            <h4>Length</h4>
                            <input type="number" id="lenInput" placeholder="Enter value">
                            <select id="lenType">
                                <option value="m2ft">Meters → Feet</option>
                                <option value="ft2m">Feet → Meters</option>
                                <option value="m2nm">Meters → Nautical Miles</option>
                                <option value="nm2m">Nautical Miles → Meters</option>
                            </select>
                            <div id="lenResult" class="converter-result"></div>
                        </div>

                        <!-- Speed -->
                        <div class="converter-box">
                            <h4>Speed</h4>
                            <input type="number" id="spdInput" placeholder="Enter value">
                            <select id="spdType">
                                <option value="knot2kmh">Knots → km/h</option>
                                <option value="kmh2knot">km/h → Knots</option>
                                <option value="knot2ms">Knots → m/s</option>
                                <option value="ms2knot">m/s → Knots</option>
                            </select>
                            <div id="spdResult" class="converter-result"></div>
                        </div>

                        <!-- Weight -->
                        <div class="converter-box">
                            <h4>Weight / Mass</h4>
                            <input type="number" id="wtInput" placeholder="Enter value">
                            <select id="wtType">
                                <option value="kg2lb">Kg → Lbs</option>
                                <option value="lb2kg">Lbs → Kg</option>
                                <option value="kg2mt">Kg → Metric Ton</option>
                                <option value="mt2kg">Metric Ton → Kg</option>
                            </select>
                            <div id="wtResult" class="converter-result"></div>
                        </div>

                        <!-- Volume -->
                        <div class="converter-box">
                            <h4>Volume</h4>
                            <input type="number" id="volInput" placeholder="Enter value">
                            <select id="volType">
                                <option value="l2m3">Litres → m³</option>
                                <option value="m32l">m³ → Litres</option>
                                <option value="m32bbl">m³ → Barrels</option>
                                <option value="bbl2m3">Barrels → m³</option>
                            </select>
                            <div id="volResult" class="converter-result"></div>
                        </div>

                        <!-- Fuel -->
                        <div class="converter-box highlight">
                            <h4>Fuel (Density Based)</h4>
                            <input type="number" id="fuelInput" placeholder="Volume / Mass">
                            <input type="number" id="densityInput" placeholder="Density (e.g. 0.85)">
                            <select id="fuelType">
                                <option value="l2mt">Litres → MT</option>
                                <option value="mt2l">MT → Litres</option>
                            </select>
                            <div id="fuelResult" class="converter-result"></div>
                        </div>

                        <!-- Pressure -->
                        <div class="converter-box">
                            <h4>Pressure</h4>
                            <input type="number" id="prsInput" placeholder="Enter value">
                            <select id="prsType">
                                <option value="bar2psi">Bar → PSI</option>
                                <option value="psi2bar">PSI → Bar</option>
                                <option value="bar2kpa">Bar → kPa</option>
                                <option value="kpa2bar">kPa → Bar</option>
                            </select>
                            <div id="prsResult" class="converter-result"></div>
                        </div>

                        <!-- Power -->
                        <div class="converter-box">
                            <h4>Power</h4>
                            <input type="number" id="pwrInput" placeholder="Enter value">
                            <select id="pwrType">
                                <option value="kw2hp">kW → HP</option>
                                <option value="hp2kw">HP → kW</option>
                            </select>
                            <div id="pwrResult" class="converter-result"></div>
                        </div>

                        <!-- Flow -->
                        <div class="converter-box">
                            <h4>Flow Rate</h4>
                            <input type="number" id="flowInput" placeholder="Enter value">
                            <select id="flowType">
                                <option value="lph2m3h">L/h → m³/h</option>
                                <option value="m3h2lph">m³/h → L/h</option>
                            </select>
                            <div id="flowResult" class="converter-result"></div>
                        </div>

                        <!-- Angle -->
                        <div class="converter-box">
                            <h4>Angle / Bearing</h4>
                            <input type="number" id="angInput" placeholder="Degrees">
                            <select id="angType">
                                <option value="deg2rad">Degrees → Radians</option>
                                <option value="rad2deg">Radians → Degrees</option>
                            </select>
                            <div id="angResult" class="converter-result"></div>
                        </div>

                        <!-- Temperature -->
                        <div class="converter-box">
                            <h4>Temperature</h4>
                            <input type="number" id="tmpInput" placeholder="Enter value">
                            <select id="tmpType">
                                <option value="c2f">°C → °F</option>
                                <option value="f2c">°F → °C</option>
                            </select>
                            <div id="tmpResult" class="converter-result"></div>
                        </div>

                    </div>
                </section>
                <br>

                <!-- ================= TIME & WATCH ================= -->
                <section class="help-card utility-card">
                    <header class="help-card-header">
                        <span class="material-icons-sharp">schedule</span>
                        <h3>Time & Watch Utilities</h3>
                    </header>

                    <div class="utility-grid">
                        <div class="utility-box">
                            <label>UTC Offset</label>
                            <input type="number" id="utcOffset" placeholder="e.g. +8">
                            <div id="utcResult" class="utility-result"></div>
                        </div>

                        <div class="utility-box">
                            <label>Duration Calculator</label>
                            <input type="time" id="timeStart">
                            <input type="time" id="timeEnd">
                            <div id="durationResult" class="utility-result"></div>
                        </div>
                    </div>
                </section>
                <br>

                <!-- ================= INFO SECTIONS ================= -->
                <section class="info-grid">
                    <div class="info-card utility-box">
                        <h4>🧭 System How-To</h4>
                        <ul>
                            <li>Complete all reports before end of day</li>
                            <li>Locked day requires Master approval</li>
                            <li>Reminders trigger automatically</li>
                        </ul>
                    </div>
                    <br>
                    <div class="info-card utility-box">
                        <h4>🛠 Troubleshooting</h4>
                        <ul>
                            <li>VSAT slow → check antenna & weather</li>
                            <li>Missing data → refresh after 1–2 min</li>
                            <li>Login issue → verify vessel account</li>
                        </ul>
                    </div>
                    ````` <br>
                    <div class="info-card utility-box">
                        <h4>📘 Maritime Notes</h4>
                        <ul>
                            <li>1 NM = 1852 meters</li>
                            <li>1 barrel ≈ 0.159 m³</li>
                            <li>Fuel density varies by temperature</li>
                        </ul>
                    </div>
                    <br>
                    <div class="info-card utility-box">
                        <h4>📞 Support</h4>
                        <ul>
                            <li>IT: it.support@company.com</li>
                            <li>Escalation: Superintendent</li>
                            <li>Critical: Inform Master</li>
                        </ul>
                    </div>
                </section>

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
    <script src="converter.js"></script>


</body>

</html>