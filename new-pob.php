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

/* ✅ Fetch crewlist for dropdown (acts as the “person” table) */
$crewlist = $pdo->query("
    SELECT id, full_name, nationality, dob, category, crew_role
    FROM crewlist
    ORDER BY full_name ASC
")->fetchAll(PDO::FETCH_ASSOC);

/* ===========================================
   POST → Insert into pob_stints (NOT pobpersons)
   =========================================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Select now returns person_id (we keep the name in a hidden field purely for convenience if needed)
    $person_id = isset($_POST['full_name']) ? (int) $_POST['full_name'] : 0;
    $category = $_POST['category'] ?? '';
    $crew_role = trim($_POST['crew_role'] ?? '');
    $embark_date = $_POST['embark_date'] ?? null;
    $disembark_date = $_POST['disembark_date'] ?? null;
    $remarks = trim($_POST['remarks'] ?? '');

    if (!$vessel_id)
        $errors[] = "Vessel is not set for your account.";
    if ($person_id <= 0)
        $errors[] = "Please select a crew/person.";
    if ($category === '')
        $errors[] = "Category is required.";
    if (!$embark_date)
        $errors[] = "Embark date is required.";
    if ($disembark_date && $disembark_date < $embark_date)
        $errors[] = "Disembark date cannot be before embark date.";

    if (!$errors) {
        // If there is an open stint for this person on this vessel, optionally close it
        $q = $pdo->prepare("
            SELECT stint_id, embark_date, disembark_date
            FROM pob_stints
            WHERE vessel_id=? AND person_id=? AND disembark_date IS NULL
            LIMIT 1
        ");
        $q->execute([$vessel_id, $person_id]);
        $open = $q->fetch(PDO::FETCH_ASSOC);

        if ($open && $embark_date <= $open['embark_date']) {
            $errors[] = "There is already an active stint for this person (embarked {$open['embark_date']}).";
        }

        if (!$errors && $open && !$disembark_date) {
            // Auto-close the previous open stint the day before the new embark
            $upd = $pdo->prepare("UPDATE pob_stints SET disembark_date = DATE_SUB(?, INTERVAL 1 DAY) WHERE stint_id=?");
            $upd->execute([$embark_date, $open['stint_id']]);
        }

        if (!$errors) {
            // Insert the new stint (unique by vessel_id+person_id+embark_date)
            $ins = $pdo->prepare("
                INSERT INTO pob_stints
                    (vessel_id, person_id, category, crew_role, embark_date, disembark_date, remarks)
                VALUES (?,?,?,?,?,?,?)
            ");
            $ins->execute([
                $vessel_id,
                $person_id,
                $category,
                $crew_role ?: null,
                $embark_date,
                $disembark_date ?: null,
                $remarks ?: null
            ]);

            $_SESSION['success'] = "POB stint added.";
            // Send user to the report for that embark date (pob-report.php reads from pob_stints by 'as of' date)
            header("Location: pob-report.php?date=" . urlencode($embark_date));
            exit;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://fonts.googleapis.com/css2?family=Material+Icons+Sharp" rel="stylesheet" />
    <script src="https://cdn.jsdelivr.net/npm/jquery@3.6.0/dist/jquery.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <link rel="stylesheet" href="style.css">
    <title>User Dashboard</title>
</head>
<style>
    /* Dropdown base */
    .dropdown .dropdown-menu {
        display: flex;
        flex-direction: column;
        margin-left: 32px;
        display: none;
    }

    .dropdown .dropdown-menu a {
        padding: 8px 0;
        font-size: .9rem;
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
        transition: transform .3s ease;
    }

    .dropdown.active .arrow {
        transform: rotate(180deg);
    }

    /* Form box */
    .form-box {
        background: var(--card);
        border-radius: var(--border-radius-2);
        padding: 24px 28px;
        box-shadow: var(--box-shadow);
        max-width: 500px;
        margin: auto;
    }

    .form-box h2 {
        margin-bottom: 18px;
        color: var(--ink);
        font-weight: 700;
        text-align: center;
    }

    .form-group {
        display: flex;
        flex-direction: column;
        margin-bottom: 14px;
    }

    .form-group label {
        margin-bottom: 6px;
        font-size: .9rem;
        font-weight: 600;
        color: var(--ink);
    }

    .form-group input,
    .form-group textarea,
    .form-group select {
        padding: 10px 12px;
        border: 1px solid var(--line);
        border-radius: var(--border-radius-1);
        font-size: .95rem;
        background: #fff;
        color: #000;
        font-weight: 500;
        transition: border-color .2s ease, box-shadow .2s ease;
    }

    .form-group input::placeholder,
    .form-group textarea::placeholder {
        color: var(--ink);
        opacity: 1;
    }

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
        padding: 10px 18px;
        background-color: var(--accent);
        color: #0fe83e;
        font-weight: 600;
        border: none;
        border-radius: var(--border-radius-1);
        cursor: pointer;
        transition: background .3s ease, transform .2s;
    }

    .btn-submit:hover {
        background-color: #4a54d1;
        transform: translateY(-2px);
    }

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
        transition: background .3s ease, transform .2s;
    }

    .btn-cancel:hover {
        background-color: #b71c1c;
        transform: translateY(-2px);
    }

    /* Select2 tweaks */
    .select2-container {
        width: 100% !important;
    }

    .select2-container .select2-selection--single {
        height: 42px;
        padding: 6px 12px;
        border: 1px solid var(--line);
        border-radius: var(--border-radius-1);
        background: #fff;
        font-size: .95rem;
        font-weight: 500;
        color: #000;
        display: flex;
        align-items: center;
    }

    .select2-dropdown {
        background: var(--color-white);
        border: 1px solid var(--line);
        border-radius: var(--border-radius-1);
        box-shadow: var(--box-shadow);
    }

    .select2-results__option {
        padding: 8px 12px;
        font-size: .9rem;
        color: var(--color-dark);
        background: var(--color-white);
    }

    .select2-results__option--highlighted {
        background: var(--color-primary) !important;
        color: #fff !important;
    }

    .select2-results__option[aria-selected=true] {
        background: var(--color-white);
        color: var(--ink);
    }

    .select2-selection__rendered {
        color: #000 !important;
        font-weight: 500;
    }

    .select2-selection__clear {
        display: flex !important;
        align-items: center;
        justify-content: center;
        width: 20px;
        height: 20px;
        line-height: 1;
        font-size: 16px !important;
        font-weight: 900 !important;
        color: #e60026 !important;
        cursor: pointer;
        margin-left: 8px;
        margin-right: 2px;
    }

    .select2-selection__clear:hover {
        color: #cc0020 !important;
        transform: scale(1.2);
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
                <h2>Add New Person On Board</h2>

                <?php if ($errors): ?>
                    <div class="errors">
                        <?php foreach ($errors as $e)
                            echo "<div>• " . htmlspecialchars($e) . "</div>"; ?>
                    </div>
                <?php endif; ?>

                <form method="POST">
                    <!-- ✅ Crew Dropdown (value = person_id) -->
                    <div class="form-group">
                        <label>Select Crew</label>
                        <select id="crew_select" name="full_name" required>
                            <option value="">-- Select Crew --</option>
                            <?php foreach ($crewlist as $c): ?>
                                <option value="<?= (int) $c['id']; ?>"
                                    data-fullname="<?= htmlspecialchars($c['full_name']); ?>"
                                    data-nationality="<?= htmlspecialchars($c['nationality']); ?>"
                                    data-dob="<?= htmlspecialchars($c['dob']); ?>"
                                    data-category="<?= htmlspecialchars($c['category']); ?>"
                                    data-role="<?= htmlspecialchars($c['crew_role']); ?>">
                                    <?= htmlspecialchars($c['full_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <!-- Keep full name around if you want it (not required for insert) -->
                        <input type="hidden" id="full_name" name="full_name_text">
                    </div>

                    <div class="form-group">
                        <label>Nationality</label>
                        <input type="text" id="nationality" readonly>
                    </div>

                    <div class="form-group">
                        <label>Date of Birth</label>
                        <input type="date" id="dob" readonly>
                    </div>

                    <div class="form-group">
                        <label>Category</label>
                        <input type="text" id="category" name="category" readonly required>
                    </div>

                    <div class="form-group">
                        <label>Crew Role</label>
                        <input type="text" id="crew_role" name="crew_role" readonly>
                    </div>

                    <div class="form-group">
                        <label>Embark Date</label>
                        <input type="date" name="embark_date" required>
                    </div>

                    <div class="form-group">
                        <label>Disembark Date</label>
                        <input type="date" name="disembark_date">
                    </div>

                    <div class="form-group">
                        <label>Remarks</label>
                        <textarea name="remarks" rows="3"></textarea>
                    </div>

                    <div class="form-actions">
                        <button type="submit" class="btn-submit">
                            <span class="material-icons-sharp">check_circle</span> Save POB
                        </button>
                        <a href="pob-report.php" class="btn-cancel">
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
                <div class="dark-mode"><span class="material-icons-sharp active">light_mode</span><span
                        class="material-icons-sharp">dark_mode</span></div>
                <div class="profile">
                    <div class="info">
                        <p>Hey, <b><?= htmlspecialchars($username); ?></b></p>
                        <small class="text-muted"><?= htmlspecialchars($role); ?></small>
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

    <script>
        /* Sidebar dropdown */
        document.addEventListener("click", (e) => {
            const toggle = e.target.closest(".dropdown-toggle");
            if (!toggle) return;
            e.preventDefault();
            toggle.parentElement.classList.toggle("active");
        });
    </script>

    <script>
        /* Enable/disable crew role when category changes (kept) */
        document.addEventListener("DOMContentLoaded", function() {
            const category = document.getElementById("category");
            const crewRole = document.getElementById("crew_role");
            if (!category || !crewRole) return;
            category.addEventListener("change", function() {
                if (this.value === "CREW") {
                    crewRole.disabled = false;
                } else {
                    crewRole.disabled = true;
                    crewRole.value = "";
                }
            });
        });
    </script>

    <script>
        /* Vanilla JS autofill (in case jQuery not loaded yet) */
        document.getElementById("crew_select")?.addEventListener("change", function() {
            const opt = this.options[this.selectedIndex];
            if (!opt) return;
            document.getElementById("full_name").value = opt.getAttribute("data-fullname") || "";
            document.getElementById("nationality").value = opt.getAttribute("data-nationality") || "";
            document.getElementById("dob").value = opt.getAttribute("data-dob") || "";
            document.getElementById("category").value = opt.getAttribute("data-category") || "";
            document.getElementById("crew_role").value = opt.getAttribute("data-role") || "";
        });
    </script>

    <script>
        /* Select2 init + autofill */
        $(function() {
            $('#crew_select').select2({
                placeholder: "-- Select or Search Crew --",
                allowClear: true,
                width: '100%'
            });

            $('#crew_select').on('change', function() {
                let s = $(this).find(':selected');
                $('#full_name').val(s.data('fullname') || "");
                $('#nationality').val(s.data('nationality') || "");
                $('#dob').val(s.data('dob') || "");
                $('#category').val(s.data('category') || "");
                $('#crew_role').val(s.data('role') || "");
            });
        });
    </script>

</body>

</html>