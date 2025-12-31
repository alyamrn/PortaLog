<?php
session_start();
require_once "db_connect.php";

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'OFFICE') {
    header("Location: login.php");
    exit;
}

$username = $_SESSION['full_name'];
$role = $_SESSION['role'];

// ===== Fetch vessels =====
$vessels = $pdo->query("SELECT vessel_id, vessel_name FROM vessels ORDER BY vessel_name")->fetchAll(PDO::FETCH_ASSOC);
$vessel_id = $_GET['vessel_id'] ?? '';
$filter_date = $_GET['date'] ?? '';
$searchQuery = $_GET['q'] ?? '';

// ===== Get report status list =====
$query = "SELECT d.vessel_id, v.vessel_name, d.log_date, d.status
          FROM dailystatus d
          JOIN vessels v ON v.vessel_id = d.vessel_id
          WHERE 1=1";
$params = [];

if (!empty($vessel_id)) {
    $query .= " AND d.vessel_id=?";
    $params[] = $vessel_id;
}
if (!empty($filter_date)) {
    $query .= " AND d.log_date=?";
    $params[] = $filter_date;
}
$query .= " ORDER BY d.log_date DESC";
$stmt = $pdo->prepare($query);
$stmt->execute($params);
$reportDays = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ===== Function: get completion and missing modules =====
function getCompletion($pdo, $vessel_id, $log_date)
{
    $modules = [
        'Activity Log' => 'activitylogs',
        // Use pob_stints (active stints) to reflect crew onboard for the date
        'POB List' => 'pob_stints',
        'Garbage Log' => 'garbagelogs',
        'Navigation Report' => 'navigationreports',
        'Oil Record Book' => 'oilrecordbook',
        'ROB Record' => 'rob_records',
        'Running Hours' => 'runninghours'
    ];

    $completed = 0;
    $missing = [];

    foreach ($modules as $label => $table) {
        if ($table === 'pob_stints') {
            // check active stints as-of $log_date
            $check = $pdo->prepare("SELECT COUNT(*) FROM pob_stints WHERE vessel_id=? AND embark_date <= ? AND (disembark_date IS NULL OR disembark_date >= ?)");
            $check->execute([$vessel_id, $log_date, $log_date]);
            $count = $check->fetchColumn();
        } else {
            $check = $pdo->prepare("SELECT COUNT(*) FROM $table WHERE vessel_id=? AND log_date=?");
            $check->execute([$vessel_id, $log_date]);
            $count = $check->fetchColumn();
        }

        if ($count > 0) {
            $completed++;
        } else {
            $missing[] = $label;
        }
    }

    $completion = round(($completed / count($modules)) * 100);
    return ['completion' => $completion, 'missing' => $missing];
}

// ===== Send reminder =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_reminder'])) {
    $vessel_id = $_POST['vessel_id'] ?? null;
    $log_date = $_POST['log_date'] ?? null;
    $missing = $_POST['missing'] ?? '[]';

    // Convert missing modules (handle both JSON and plain array)
    $missingList = is_array($missing) ? $missing : json_decode($missing, true);
    if (!is_array($missingList)) {
        $missingList = [];
    }

    $modules_missing = implode(', ', $missingList);

    // Find Captain email for that vessel (case-insensitive)
    $getCaptain = $pdo->prepare("
        SELECT email, full_name 
        FROM users 
        WHERE vessel_id = ? 
          AND UPPER(role) = 'CAPTAIN' 
        LIMIT 1
    ");
    $getCaptain->execute([$vessel_id]);
    $captain = $getCaptain->fetch(PDO::FETCH_ASSOC);

    if ($captain && !empty($captain['email'])) {
        $to = $captain['email'];
        $subject = "Reminder: Incomplete Daily Report for " . $log_date;
        $message = "Dear Captain " . $captain['full_name'] . ",\n\n"
            . "Please complete the following forms for " . $log_date . ":\n"
            . "- " . implode("\n- ", $missingList) . "\n\n"
            . "This is an automated reminder from the Office System.\n\n"
            . "Best regards,\nBSK Office";

        $headers = "From: noreply@bsk.com.my\r\n";

        // Send Email
        @mail($to, $subject, $message, $headers);

        // Identify who sent it (store readable label)
        $sent_by = ($_SESSION['role'] ?? '') === 'office'
            ? 'OFFICE'
            : ($_SESSION['full_name'] ?? 'SYSTEM');

        // Store in reminders table
        $insert = $pdo->prepare("
            INSERT INTO reminders (vessel_id, log_date, modules_missing, message, sent_by, sent_to_email)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $insert->execute([
            $vessel_id,
            $log_date,
            $modules_missing,
            $message,
            $sent_by,   // use readable label
            $to
        ]);
    }

    header("Location: office-status.php?success=reminder");
    exit;
}

?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Report Status</title>
    <link href="https://fonts.googleapis.com/css2?family=Material+Icons+Sharp" rel="stylesheet">
    <link rel="stylesheet" href="style.css">
    <style>
        .status-container {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 1.2rem;
            margin-top: 1.5rem;
            max-height: 60vh;
            overflow-y: auto;
            padding-right: 8px;
        }

        .status-card {
            background: var(--color-white);
            padding: 1.2rem 1.5rem;
            border-radius: var(--border-radius-2);
            box-shadow: var(--box-shadow);
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }

        .status-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 1rem 2rem var(--color-light);
        }

        .status-card .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: .6rem;
        }

        .badge {
            padding: 0.2rem 0.6rem;
            border-radius: var(--border-radius-1);
            font-size: 0.8rem;
            font-weight: 600;
        }

        .locked {
            background: var(--color-success);
            color: #fff;
        }

        .open {
            background: var(--color-warning);
            color: #000;
        }

        .progress-bar {
            width: 100%;
            background: var(--color-light);
            height: 10px;
            border-radius: 1rem;
            overflow: hidden;
            margin: .5rem 0;
        }

        .progress-bar .fill {
            height: 100%;
            background: var(--color-primary);
            width: 0;
            transition: width 0.5s ease;
        }

        .missing {
            color: var(--color-dark-variant);
            font-size: 0.85rem;
            margin: 0.5rem 0;
        }

        .btn-remind {
            background: var(--color-danger);
            color: #fff;
            padding: 0.4rem 0.8rem;
            border: none;
            border-radius: var(--border-radius-1);
            font-weight: 600;
            cursor: pointer;
            transition: background 0.25s ease;
        }

        .btn-remind:hover {
            background: #e60055;
        }

        .success-toast {
            background: var(--color-success);
            color: #fff;
            padding: .6rem 1rem;
            border-radius: var(--border-radius-1);
            margin-bottom: 1rem;
            animation: fadeOut 3s forwards;
        }

        @keyframes fadeOut {
            0% {
                opacity: 1;
            }

            80% {
                opacity: 1;
            }

            100% {
                opacity: 0;
                display: none;
            }
        }

        /* ==== Office Reminder List ==== */
        .reminder-list {
            background: var(--color-white);
            border-radius: var(--border-radius-2);
            padding: 1rem 1.2rem;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.05);
            margin-top: 10px;
        }

        .reminder-header h3 {
            margin: 0 0 .8rem;
            font-size: 1rem;
            font-weight: 600;
            color: var(--color-dark);
            display: flex;
            align-items: center;
            gap: .4rem;
        }

        .reminder-items {
            list-style: none;
            margin: 0;
            padding: 0;
        }

        .reminder-card {
            background: var(--color-background);
            border: 1px solid var(--color-light);
            border-radius: var(--border-radius-2);
            padding: .7rem .9rem;
            margin-bottom: .7rem;
            transition: all .2s ease;
            cursor: default;
        }

        .reminder-card:hover {
            background: var(--color-light);
            transform: translateY(-2px);
        }

        .reminder-top {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: .3rem;
        }

        .vessel-name {
            font-weight: 600;
            color: var(--color-primary);
            font-size: .95rem;
        }

        .badge {
            padding: .25rem .55rem;
            border-radius: .5rem;
            font-size: .8rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: .3px;
        }

        .badge-missing {
            background: var(--color-danger);
            color: #fff;
        }

        .badge-unlock {
            background: var(--color-warning);
            color: #2d2d2d;
        }

        .reminder-body {
            display: flex;
            flex-wrap: wrap;
            gap: .5rem;
            font-size: .8rem;
            color: var(--color-dark-variant);
        }

        .no-reminders {
            color: var(--color-info-dark);
            font-style: italic;
            padding: .5rem 0;
        }

        .no-results {
            text-align: center;
            padding: 1rem;
            color: var(--color-dark-variant);
            background: var(--color-white);
            border-radius: var(--border-radius-2);
            box-shadow: 0 4px 12px rgba(0,0,0,0.04);
            margin: .5rem 0;
            display: none;
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
                <a href="office-pob.php"><span class="material-icons-sharp">groups</span>
                    <h3>POB Monitoring</h3>
                </a>
                <a href="office-status.php" class="active"><span
                        class="material-icons-sharp">assignment_turned_in</span>
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

        <!-- Main -->
        <main>
            <h2>📅 Report Status Overview</h2>

            <?php if (isset($_GET['success'])): ?>
                <div class="success-toast">📩 Reminder Sent Successfully</div>
            <?php endif; ?>

            <div class="filter-section">
                <form method="GET">
                    <label><b>Search:</b></label>
                    <input type="text" id="search" name="q" placeholder="Search by vessel name..." value="<?= htmlspecialchars($searchQuery) ?>" autocomplete="off" />

                    <label style="margin-left:.6rem"><b>Vessel:</b></label>
                    <select name="vessel_id">
                        <option value="">All Vessels</option>
                        <?php foreach ($vessels as $v): ?>
                            <option value="<?= $v['vessel_id'] ?>" <?= $vessel_id == $v['vessel_id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($v['vessel_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>


                    <button type="submit">🔍 Filter</button>
                </form>
            </div>

            <div class="status-container">
                <div id="no-results" class="no-results">No results found.</div>
                <?php if (empty($reportDays)): ?>
                    <p style="color:gray;">No report status available.</p>
                <?php else: ?>
                    <?php foreach ($reportDays as $r):
                        $stats = getCompletion($pdo, $r['vessel_id'], $r['log_date']);
                        $completion = $stats['completion'];
                        $missing = $stats['missing'];
                        $missingStr = !empty($missing) ? implode(', ', $missing) : 'None';
                        $dataVessel = strtolower(htmlspecialchars($r['vessel_name']));
                        $dataDate = strtolower(htmlspecialchars($r['log_date']));
                        ?>
                        <div class="status-card" data-vessel="<?= $dataVessel ?>" data-date="<?= $dataDate ?>">
                            <div class="header">
                                <h3><?= htmlspecialchars($r['log_date']) ?></h3>
                                <span class="badge <?= strtolower($r['status']) ?>"><?= htmlspecialchars($r['status']) ?></span>
                            </div>
                            <p><b>Vessel:</b> <?= htmlspecialchars($r['vessel_name']) ?></p>

                            <div class="progress-bar">
                                <div class="fill" style="width: <?= $completion ?>%;"></div>
                            </div>
                            <p><?= $completion ?>% Complete</p>

                            <div class="missing"><b>Missing Forms:</b> <?= htmlspecialchars($missingStr) ?></div>

                            <?php if (!empty($missing)): ?>
                                <form method="POST">
                                    <input type="hidden" name="vessel_id" value="<?= $r['vessel_id'] ?>">
                                    <input type="hidden" name="log_date" value="<?= $r['log_date'] ?>">
                                    <input type="hidden" name="missing" value='<?= json_encode($missing) ?>'>
                                    <button type="submit" name="send_reminder" class="btn-remind">📩 Send Reminder</button>
                                </form>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
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

            <div class="reminder-list">
                <div class="reminder-header">
                    <h3><span class="material-icons-sharp" style="vertical-align:middle;">notifications</span> Recent
                        Reminders</h3>
                </div>

                <?php
                $stmt = $pdo->query("
        SELECT r.*, v.vessel_name 
        FROM reminders r 
        JOIN vessels v ON v.vessel_id = r.vessel_id 
        ORDER BY r.created_at DESC 
        LIMIT 3
    ");
                $reminders = $stmt->fetchAll(PDO::FETCH_ASSOC);

                if (empty($reminders)): ?>
                    <p class="no-reminders">No reminders sent yet 🎉</p>
                <?php else: ?>
                    <ul class="reminder-items">
                        <?php foreach ($reminders as $r):
                            $type = strtoupper(trim($r['modules_missing']));
                            $badgeClass = ($type === 'UNLOCK REQUEST') ? 'badge-unlock' : 'badge-missing';
                            ?>
                            <li class="reminder-card" title="<?= htmlspecialchars($r['message'] ?? '') ?>">
                                <div class="reminder-top">
                                    <span class="vessel-name"><?= htmlspecialchars($r['vessel_name']) ?></span>
                                    <span class="badge <?= $badgeClass ?>">
                                        <?= htmlspecialchars($type === 'UNLOCK REQUEST' ? 'Unlock Request' : 'Missing Forms') ?>
                                    </span>
                                </div>
                                <div class="reminder-body">
                                    <small><?= htmlspecialchars(date('d M Y H:i', strtotime($r['created_at']))) ?></small>
                                    <?php if (!empty($r['log_date'])): ?>
                                        <small>• Log Date: <?= htmlspecialchars($r['log_date']) ?></small>
                                    <?php endif; ?>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>

        </div>
    </div>

    <script src="index.js"></script>
    <script>
        (function(){
            const searchInput = document.getElementById('search');
            if (!searchInput) return;

            const debounce = (fn, delay) => {
                let t;
                return function(...args) {
                    clearTimeout(t);
                    t = setTimeout(() => fn.apply(this, args), delay);
                };
            };

            const filterCards = () => {
                const q = searchInput.value.trim().toLowerCase();
                const cards = document.querySelectorAll('.status-card');
                const noResultsEl = document.getElementById('no-results');
                let anyVisible = false;

                cards.forEach(card => {
                    const vessel = (card.dataset.vessel || '');
                    const date = (card.dataset.date || '');
                    const text = (card.textContent || '');
                    const searchable = (vessel + ' ' + date + ' ' + text).toLowerCase();
                    if (q === '' || searchable.includes(q)) {
                        card.style.display = '';
                        anyVisible = true;
                    } else {
                        card.style.display = 'none';
                    }
                });

                if (noResultsEl) {
                    noResultsEl.style.display = anyVisible ? 'none' : '';
                }
            };

            const debouncedFilter = debounce(filterCards, 180);
            searchInput.addEventListener('input', debouncedFilter);

            // run once on load to apply initial GET q filter
            document.addEventListener('DOMContentLoaded', filterCards);
        })();
    </script>
</body>

</html>