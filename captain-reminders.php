<?php
session_start();
require_once "db_connect.php";

// ===== Ensure CAPTAIN login =====
if (!isset($_SESSION['user_id']) || strtoupper($_SESSION['role'] ?? '') !== 'CAPTAIN') {
    header("Location: login.php");
    exit;
}

$user_id = $_SESSION['user_id'];
$vessel_id = $_SESSION['vessel_id'];
$full_name = $_SESSION['full_name'] ?? 'Captain';

// ===== Extra variables for right panel (you referenced them later) =====
$username = $_SESSION['full_name'] ?? 'Captain';
$role = $_SESSION['role'] ?? 'CAPTAIN';

// Optional: show vessel name in the logo panel
$vesselName = '';
try {
    $vs = $pdo->prepare("SELECT vessel_name FROM vessels WHERE vessel_id = ?");
    $vs->execute([$vessel_id]);
    $vesselName = $vs->fetchColumn() ?: '';
} catch (Throwable $e) {
    $vesselName = '';
}

// --- Optional: timezone for consistent timestamps ---
if (function_exists('date_default_timezone_set')) {
    date_default_timezone_set('Asia/Kuala_Lumpur');
}

// ===== Handle Actions (POST) =====
// - action=done    -> delete reminder (mark as done)
// - action=flag    -> set is_flagged=1
// - action=unflag  -> set is_flagged=0
$status = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $id = isset($_POST['id']) ? (int) $_POST['id'] : 0;

    // Security: ensure the reminder belongs to this captain's vessel
    $chk = $pdo->prepare("SELECT reminder_id FROM reminders WHERE reminder_id = ? AND vessel_id = ?");
    $chk->execute([$id, $vessel_id]);
    $exists = $chk->fetchColumn();

    if ($exists) {
        if ($action === 'done') {
            // Delete = mark as done
            $del = $pdo->prepare("DELETE FROM reminders WHERE reminder_id = ? AND vessel_id = ?");
            $del->execute([$id, $vessel_id]);
            $status = 'done_ok';

        } elseif ($action === 'flag') {
            $upd = $pdo->prepare("UPDATE reminders SET is_flagged = 1 WHERE reminder_id = ? AND vessel_id = ?");
            $upd->execute([$id, $vessel_id]);
            $status = 'flag_ok';

        } elseif ($action === 'unflag') {
            $upd = $pdo->prepare("UPDATE reminders SET is_flagged = 0 WHERE reminder_id = ? AND vessel_id = ?");
            $upd->execute([$id, $vessel_id]);
            $status = 'unflag_ok';
        }
    } else {
        $status = 'not_found';
    }

    // PRG pattern
    $qs = $status ? "?status={$status}" : "";
    header("Location: captain-reminders.php{$qs}");
    exit;
}

// ===== Filters (GET) =====
$only_flagged = isset($_GET['flagged']) && $_GET['flagged'] === '1';
$search = trim($_GET['q'] ?? '');

// ===== Build query =====
$sql = "
    SELECT
        r.reminder_id AS id,
        r.vessel_id,
        r.log_date,
        r.modules_missing,
        r.message,
        r.sent_by,
        r.sent_to_email,
        r.created_at,
        COALESCE(r.is_flagged, 0) AS flagged
    FROM reminders r
    WHERE r.vessel_id = :vid
";
$params = [':vid' => $vessel_id];

if ($only_flagged) {
    $sql .= " AND COALESCE(r.is_flagged, 0) = 1";
}

if ($search !== '') {
    $sql .= " AND (r.message LIKE :q OR r.modules_missing LIKE :q OR r.log_date LIKE :q)";
    $params[':q'] = '%' . $search . '%';
}

$sql .= " ORDER BY r.created_at DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$reminders = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Captain Reminders</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://fonts.googleapis.com/css2?family=Material+Icons+Sharp" rel="stylesheet">
    <link rel="stylesheet" href="style.css">
    <style>
        /* Dropdown base */
        .dropdown .dropdown-menu {
            display: none;
            flex-direction: column;
            margin-left: 32px;
        }

        .dropdown .dropdown-menu a {
            padding: 8px 0;
            font-size: 0.9rem;
            color: var(--color-dark);
        }

        .dropdown .dropdown-menu a:hover {
            color: var(--color-primary);
        }

        .dropdown.active .dropdown-menu {
            display: flex;
        }

        .dropdown .arrow {
            margin-left: auto;
            transition: transform 0.3s ease;
        }

        .dropdown.active .arrow {
            transform: rotate(180deg);
        }



        .page-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
            margin-bottom: 0.6rem;
        }

        .page-header h2 {
            margin: 0;
        }

        .toolbar {
            display: flex;
            gap: .6rem;
            flex-wrap: wrap;
            align-items: center;
        }

        .toolbar input[type="text"] {
            padding: .5rem .7rem;
            border: 1px solid var(--color-light);
            border-radius: var(--border-radius-1);
            background: var(--color-white);
            min-width: 220px;
        }

        .toolbar .btn,
        .card-actions .btn {
            background: var(--color-primary);
            color: #fff;
            border: none;
            padding: .45rem .8rem;
            border-radius: var(--border-radius-1);
            cursor: pointer;
            font-weight: 600;
        }

        .toolbar .btn-secondary {
            background: var(--color-background);
            color: var(--color-dark);
            border: 1px solid var(--color-light);
        }

        .toolbar label {
            display: inline-flex;
            gap: .4rem;
            align-items: center;
            cursor: pointer;
        }

        .grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
            gap: 1rem;
            margin-top: .6rem;
        }

        .card {
            background: var(--color-white);
            border-radius: var(--border-radius-2);
            box-shadow: var(--box-shadow);
            padding: 1rem;
            transition: transform .18s ease, box-shadow .18s ease;
        }

        .card:hover {
            transform: translateY(-2px);
            box-shadow: 0 1rem 2rem var(--color-light);
        }

        .card-top {
            display: flex;
            justify-content: space-between;
            align-items: start;
            gap: .6rem;
        }

        .badge {
            padding: .2rem .55rem;
            border-radius: .6rem;
            font-size: .75rem;
            font-weight: 700;
        }

        .badge-flagged {
            background: var(--color-warning);
            color: #111;
        }

        .badge-missing {
            background: var(--color-danger);
            color: #fff;
        }

        .badge-date {
            background: var(--color-light);
            color: var(--color-dark);
        }

        .meta {
            color: var(--color-dark-variant);
            font-size: .85rem;
            display: flex;
            gap: .6rem;
            flex-wrap: wrap;
        }

        .label {
            font-weight: 600;
            color: var(--color-dark);
        }

        .message {
            background: var(--color-background);
            border: 1px solid var(--color-light);
            border-radius: var(--border-radius-2);
            padding: .7rem .8rem;
            margin-top: .6rem;
            white-space: pre-wrap;
            max-height: 160px;
            overflow: auto;
        }

        .modules {
            font-size: .85rem;
            color: var(--color-dark-variant);
            margin-top: .4rem;
        }

        .card-actions {
            display: flex;
            gap: .5rem;
            flex-wrap: wrap;
            margin-top: .8rem;
        }

        .btn-danger {
            background: var(--color-danger);
            color: #fff;
        }

        .btn-ghost {
            background: transparent;
            color: var(--color-dark);
            border: 1px solid var(--color-light);
        }

        .toast {
            position: fixed;
            right: 16px;
            bottom: 16px;
            background: var(--color-success);
            color: #fff;
            padding: .7rem 1rem;
            border-radius: var(--border-radius-1);
            box-shadow: var(--box-shadow);
            animation: fadeout 3.2s forwards;
            z-index: 9999;
        }

        .toast.error {
            background: var(--color-danger);
        }

        .toast.warn {
            background: var(--color-warning);
            color: #222;
        }

        @keyframes fadeout {

            0%,
            80% {
                opacity: 1;
            }

            100% {
                opacity: 0;
                transform: translateY(6px);
            }
        }

        .side {
            background: var(--color-white);
            border-radius: var(--border-radius-2);
            padding: 1rem;
            box-shadow: var(--box-shadow);
        }

        /* ===== Reminders header & filters ===== */
        .page-title {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin: 0 0 .8rem 0;
        }

        .page-title-left {
            display: flex;
            align-items: center;
            gap: .8rem;
        }

        .page-title-icon {
            font-size: 28px;
            color: var(--color-primary);
            background: var(--color-light);
            border-radius: 10px;
            padding: 6px;
        }

        .page-title h1 {
            margin: 0;
            font-weight: 700;
            font-size: 1.6rem;
            letter-spacing: .2px;
        }

        .page-title .subtitle {
            margin: 2px 0 0 0;
            color: var(--color-dark-variant);
            font-size: .95rem;
        }

        /* Card container for filters */
        .filters-card {
            background: var(--color-white);
            border-radius: var(--border-radius-2);
            box-shadow: var(--box-shadow);
            padding: .8rem;
            margin: 0 0 .8rem 0;
        }

        /* Horizontal control bar */
        .filters-row {
            display: grid;
            grid-template-columns: 1fr auto auto;
            gap: .8rem;
            align-items: center;
        }

        @media (max-width: 900px) {
            .filters-row {
                grid-template-columns: 1fr;
            }
        }

        /* Search with icon */
        .input-icon {
            position: relative;
            display: flex;
            align-items: center;
            background: var(--color-background);
            border: 1px solid var(--color-light);
            border-radius: var(--border-radius-1);
            padding-left: 36px;
            /* room for icon */
        }

        .input-icon>span {
            position: absolute;
            left: 8px;
            font-size: 18px;
            color: var(--color-info-dark);
        }

        .input-icon input {
            width: 100%;
            height: 38px;
            background: transparent;
            color: var(--color-dark);
            font-family: 'Poppins', sans-serif;
            padding: 0 .8rem 0 0;
        }

        /* Checkbox inline */
        .checkbox-inline {
            display: inline-flex;
            align-items: center;
            gap: .45rem;
            color: var(--color-dark);
            white-space: nowrap;
        }

        /* Actions (Apply / Clear) */
        .actions {
            display: inline-flex;
            gap: .5rem;
            justify-self: end;
        }

        .btn.btn-ghost.link-reset {
            border: 1px solid var(--color-light);
            background: transparent;
            color: var(--color-dark);
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: .35rem;
            padding: .45rem .8rem;
            border-radius: var(--border-radius-1);
        }

        .btn.btn-ghost.link-reset:hover {
            background: var(--color-light);
        }
    </style>
</head>

<body>
    <div class="container">

        <!-- (Optional) Left sidebar placeholder; reuse your app's sidebar if needed -->
        <aside>
            <div class="toggle">
                <div class="logo">
                    <img src="image/BSK_LOGO.jpg" alt="Logo">
                    <h2>Porta<span class="danger">Log</span></h2>
                </div>
                <div class="close" id="close-btn"><span class="material-icons-sharp">close</span></div>
            </div>
            <div class="sidebar">
                <a href="dashboard.php" ><span class="material-icons-sharp">dashboard</span>
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
                <a href="captain-reminders.php" class="active"><span class="material-icons-sharp">schedule</span>
                    <h3>Reminders</h3>
                </a>
                <a href="history.php"><span class="material-icons-sharp">history_edu</span>
                    <h3>history</h3>
                </a>
                <a href="help.php"><span class="material-icons-sharp">help</span>
                    <h3>Help</h3>
                </a>
                <a href="login.php"><span class="material-icons-sharp">logout</span>
                    <h3>LogOut</h3>
                </a>
            </div>
        </aside>

        <!-- Main -->
        <main>
            <!-- Page title -->
            <div class="page-title">
                <div class="page-title-left">
                    <span class="material-icons-sharp page-title-icon">notifications_active</span>
                    <div>
                        <h1>Reminders</h1>
                        <p class="subtitle">View, flag/unflag, and mark reminders as done</p>
                    </div>
                </div>
            </div>

            <!-- Filters row (compact control bar) -->
            <div class="filters-card">
                <form class="filters-row" method="GET" action="captain-reminders.php">
                    <div class="form-group input-icon">
                        <span class="material-icons-sharp">search</span>
                        <input id="q" type="text" name="q" placeholder="Message / module / date…"
                            value="<?= htmlspecialchars($search) ?>">
                    </div>

                    <label class="checkbox-inline">
                        <input type="checkbox" name="flagged" value="1" <?= $only_flagged ? 'checked' : '' ?>
                            onchange="this.form.submit()">
                        Flagged only
                    </label>

                    <div class="actions">
                        <button class="btn btn-secondary" type="submit">
                            <span class="material-icons-sharp" style="font-size:18px;vertical-align:middle">tune</span>
                            Apply
                        </button>
                        <a class="btn btn-ghost link-reset" href="captain-reminders.php">
                            <span class="material-icons-sharp" style="font-size:18px;vertical-align:middle">close</span>
                            Clear
                        </a>
                    </div>
                </form>
            </div>




            <?php if (isset($_GET['status'])): ?>
                <?php
                $map = [
                    'done_ok' => ['text' => 'Reminder marked as done and removed.', 'class' => ''],
                    'flag_ok' => ['text' => 'Reminder flagged.', 'class' => 'warn'],
                    'unflag_ok' => ['text' => 'Reminder unflagged.', 'class' => ''],
                    'not_found' => ['text' => 'Reminder not found or not yours.', 'class' => 'error'],
                ];
                $toast = $map[$_GET['status']] ?? null;
                if ($toast): ?>
                    <div class="toast <?= $toast['class'] ?>"><?= htmlspecialchars($toast['text']) ?></div>
                <?php endif; ?>
            <?php endif; ?>

            <?php if (empty($reminders)): ?>
                <p style="color:var(--color-info-dark);font-style:italic">No reminders at the moment 🎉</p>
            <?php else: ?>
                <div class="grid">
                    <?php foreach ($reminders as $r): ?>
                        <?php
                        $modules = trim($r['modules_missing'] ?? '');
                        $isUnlockReq = (strtoupper($modules) === 'UNLOCK REQUEST');
                        ?>
                        <div class="card">
                            <div class="card-top">
                                <div>
                                    <div class="meta">
                                        <?php if (!empty($r['log_date'])): ?>
                                            <span class="badge badge-date">Log: <?= htmlspecialchars($r['log_date']) ?></span>
                                        <?php endif; ?>
                                        <span
                                            class="badge badge-date"><?= htmlspecialchars(date('d M Y, H:i', strtotime($r['created_at'] ?? 'now'))) ?></span>
                                        <?php if ($r['flagged']): ?>
                                            <span class="badge badge-flagged">Flagged</span>
                                        <?php endif; ?>
                                        <?php if ($isUnlockReq): ?>
                                            <span class="badge badge-missing">Unlock Request</span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="modules">
                                        <span class="label">Missing Modules:</span>
                                        <?= $modules ? htmlspecialchars($modules) : '<em>None listed</em>' ?>
                                    </div>
                                </div>
                            </div>

                            <div class="message" id="msg-<?= (int) $r['id'] ?>">
                                <?= htmlspecialchars($r['message'] ?? '') ?>
                            </div>

                            <div class="card-actions">
                                <!-- Mark as done (delete) -->
                                <form method="POST" onsubmit="return confirm('Mark as done and remove this reminder?');">
                                    <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                                    <input type="hidden" name="action" value="done">
                                    <button class="btn btn-danger" type="submit">
                                        <span class="material-icons-sharp"
                                            style="vertical-align:middle;font-size:18px">check_circle</span>
                                        Mark as Done
                                    </button>
                                </form>

                                <!-- Flag / Unflag -->
                                <form method="POST">
                                    <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                                    <input type="hidden" name="action" value="<?= $r['flagged'] ? 'unflag' : 'flag' ?>">
                                    <button class="btn btn-ghost" type="submit"
                                        title="<?= $r['flagged'] ? 'Unflag' : 'Flag' ?>">
                                        <span class="material-icons-sharp" style="vertical-align:middle;font-size:18px">
                                            <?= $r['flagged'] ? 'flag' : 'outlined_flag' ?>
                                        </span>
                                        <?= $r['flagged'] ? 'Unflag' : 'Flag' ?>
                                    </button>
                                </form>
                            </div>
                        </div>
                    <?php endforeach; ?>
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
                        <p>Hey, <b><?= htmlspecialchars($username) ?></b></p>
                        <small class="text-muted"><?= htmlspecialchars($role) ?></small>
                    </div>
                    <div class="profile-photo"><img src="image/blankProf.png" alt="Profile Photo"></div>
                </div>
            </div>
            <div class="user-profile">
                <div class="logo">
                    <img src="image/BSK_LOGO.jpg" alt="Logo">
                    <h2>PortaLog</h2>
                    <p><?= $vesselName ? " • " . htmlspecialchars($vesselName) : "" ?></p>
                </div>
            </div>

            <div class="reminders" id="reminders-section">
                <div class="header">
                    <h2>Reminders</h2>
                    <span class="material-icons-sharp">notifications_none</span>
                </div>
                <div id="reminder-list">
                    <!-- You already render the reminders in <main>; this section can hold extra widgets if needed -->
                </div>
            </div>
        </div>

    </div>

    <script src="index.js"></script>
    <script src="appointment.js"></script>
    <script src="clock.js"></script>
    <script src="local-storage.js"></script>
    <script src="load-reminder.js"></script>
</body>

</html>