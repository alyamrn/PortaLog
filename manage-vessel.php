<?php
session_start();
require_once "db_connect.php";

// Guard
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'ADMIN') {
    header("Location: login.php");
    exit;
}

$userId = $_SESSION['user_id'];
$username = $_SESSION['full_name'];
$role = $_SESSION['role'];

$message = "";

/* ---------------------- POST actions ---------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    try {
        if ($_POST['action'] === 'add') {
            $stmt = $pdo->prepare("INSERT INTO vessels (vessel_name, imo_number, call_sign, flag, vessel_type, created_at)
                                   VALUES (:name, :imo, :call_sign, :flag, :type, NOW())");
            $stmt->execute([
                ':name' => trim($_POST['vessel_name']),
                ':imo' => trim($_POST['imo_number']),
                ':call_sign' => trim($_POST['call_sign']),
                ':flag' => trim($_POST['flag']),
                ':type' => trim($_POST['vessel_type']),
            ]);
            $message = "✅ Vessel created.";
        }

        if ($_POST['action'] === 'edit') {
            $stmt = $pdo->prepare("UPDATE vessels 
                                   SET vessel_name=:name, imo_number=:imo, call_sign=:call_sign,
                                       flag=:flag, vessel_type=:type
                                   WHERE vessel_id=:id");
            $stmt->execute([
                ':id' => (int) $_POST['vessel_id'],
                ':name' => trim($_POST['vessel_name']),
                ':imo' => trim($_POST['imo_number']),
                ':call_sign' => trim($_POST['call_sign']),
                ':flag' => trim($_POST['flag']),
                ':type' => trim($_POST['vessel_type']),
            ]);
            $message = "✅ Vessel updated.";
        }

        if ($_POST['action'] === 'delete') {
            $stmt = $pdo->prepare("DELETE FROM vessels WHERE vessel_id=:id");
            $stmt->execute([':id' => (int) $_POST['vessel_id']]);
            $message = "🗑️ Vessel deleted.";
        }
    } catch (PDOException $e) {
        $message = "❌ Error: " . $e->getMessage();
    }
}

/* ---------------------- Filters ---------------------- */
$where = [];
$params = [];

if (!empty($_GET['search'])) {
    $where[] = "(vessel_name LIKE :search OR imo_number LIKE :search OR call_sign LIKE :search OR flag LIKE :search)";
    $params[':search'] = "%" . $_GET['search'] . "%";
}

$sql = "SELECT vessel_id, vessel_name, imo_number, call_sign, flag, vessel_type, created_at 
        FROM vessels";
if ($where)
    $sql .= " WHERE " . implode(" AND ", $where);
$sql .= " ORDER BY vessel_name";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$vessels = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <link href="https://fonts.googleapis.com/css2?family=Material+Icons+Sharp" rel="stylesheet" />
    <link rel="stylesheet" href="style.css" />
    <title>Manage Vessels</title>
    <style>
        .user-filter-box form {
            display: flex;
            gap: 12px;
            align-items: center
        }

        .user-filter-box input,
        .user-filter-box button {
            padding: 8px 12px;
            border: 1px solid var(--color-light);
            border-radius: var(--border-radius-1);
        }

        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, .6);
            justify-content: center;
            align-items: center;
            z-index: 999
        }

        .modal-content {
            background: var(--color-white);
            padding: 20px;
            border-radius: var(--border-radius-2);
            width: 500px;
            max-width: 95%;
            position: relative;
            box-shadow: var(--box-shadow)
        }

        .modal-close {
            position: absolute;
            top: 10px;
            right: 15px;
            font-size: 20px;
            cursor: pointer;
            color: var(--color-danger)
        }

        .modal-content form {
            display: flex;
            flex-direction: column;
            gap: 12px
        }

        .modal-content input {
            padding: 8px;
            border: 1px solid var(--color-light);
            border-radius: var(--border-radius-1)
        }

        .user-table-wrapper {
            max-height: 400px;
            overflow-y: auto;
            border: 1px solid var(--color-light);
            border-radius: var(--border-radius-1)
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
                <a href="crew-list.php"><span class="material-icons-sharp">groups</span>
                    <h3>Crew List</h3>
                </a>
                <a href="manage-vessel.php" class="active"><span class="material-icons-sharp">directions_boat</span>
                    <h3>Manage Vessels</h3>
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

        <!-- Main -->
        <main>
            <h1>Manage Vessels</h1>
            <?php if (!empty($message)): ?>
                <p style="margin:10px 0"><?php echo htmlspecialchars($message); ?></p>
            <?php endif; ?>

            <!-- Filters -->
            <div class="user-filter-box">
                <form method="GET">
                    <input type="text" name="search" placeholder="Search vessel name / IMO / call sign / flag"
                        value="<?php echo htmlspecialchars($_GET['search'] ?? ''); ?>">
                    <button type="submit">Search</button>
                </form>
            </div>

            <!-- Vessel Table -->
            <div class="availability-table">
                <h2>Existing Vessels</h2>
                <div class="user-table-wrapper">
                    <table>
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Name</th>
                                <th>IMO</th>
                                <th>Call Sign</th>
                                <th>Flag</th>
                                <th>Type</th>
                                <th>Created</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($vessels as $v): ?>
                                <tr>
                                    <td><?php echo $v['vessel_id']; ?></td>
                                    <td><?php echo htmlspecialchars($v['vessel_name']); ?></td>
                                    <td><?php echo htmlspecialchars($v['imo_number']); ?></td>
                                    <td><?php echo htmlspecialchars($v['call_sign']); ?></td>
                                    <td><?php echo htmlspecialchars($v['flag']); ?></td>
                                    <td><?php echo htmlspecialchars($v['vessel_type']); ?></td>
                                    <td><?php echo $v['created_at']; ?></td>
                                    <td>
                                        <button type="button" class="edit"
                                            onclick='openModal(JSON.parse(this.dataset.vessel))'
                                            data-vessel='<?php echo htmlspecialchars(json_encode($v), ENT_QUOTES, "UTF-8"); ?>'>
                                            Edit
                                        </button>
                                        <form method="POST" style="display:inline"
                                            onsubmit="return confirm('Delete this vessel?');">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="vessel_id" value="<?php echo $v['vessel_id']; ?>">
                                            <button type="submit" class="delete">Delete</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Add Vessel -->
            <div class="createApp">
                <h2>Create New Vessel</h2>
                <form method="POST">
                    <input type="hidden" name="action" value="add">
                    <input type="text" name="vessel_name" placeholder="Vessel Name" required>
                    <input type="text" name="imo_number" placeholder="IMO Number">
                    <input type="text" name="call_sign" placeholder="Call Sign">
                    <input type="text" name="flag" placeholder="Flag">
                    <input type="text" name="vessel_type" placeholder="Vessel Type">
                    <button type="submit">Add Vessel</button>
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
        </div>
    </div>

    <!-- Edit Modal -->
    <div id="editModal" class="modal">
        <div class="modal-content">
            <span class="modal-close" onclick="closeModal()">&times;</span>
            <h2>Edit Vessel</h2>
            <form method="POST" id="editForm">
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="vessel_id" id="edit_vessel_id">
                <input type="text" name="vessel_name" id="edit_vessel_name" required>
                <input type="text" name="imo_number" id="edit_imo_number">
                <input type="text" name="call_sign" id="edit_call_sign">
                <input type="text" name="flag" id="edit_flag">
                <input type="text" name="vessel_type" id="edit_vessel_type">
                <button type="submit" class="edit">Save Changes</button>
            </form>
        </div>
    </div>

    <script>
        function openModal(vessel) {
            document.getElementById('edit_vessel_id').value = vessel.vessel_id;
            document.getElementById('edit_vessel_name').value = vessel.vessel_name;
            document.getElementById('edit_imo_number').value = vessel.imo_number;
            document.getElementById('edit_call_sign').value = vessel.call_sign;
            document.getElementById('edit_flag').value = vessel.flag;
            document.getElementById('edit_vessel_type').value = vessel.vessel_type;
            document.getElementById('editModal').style.display = 'flex';
        }
        function closeModal() {
            document.getElementById('editModal').style.display = 'none';
        }
    </script>

    <script src="index.js"></script>
    <script src="appointment.js"></script>
    <script src="clock.js"></script>
</body>

</html>