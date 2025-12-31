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
            $full_name = trim($_POST['full_name']);
            $email = trim($_POST['email']);
            $password = $_POST['password'];
            $u_role = $_POST['role'];
            $vessel_id = !empty($_POST['vessel_id']) ? (int) $_POST['vessel_id'] : null;

            if ($full_name && $email && $password && $u_role) {
                $hash = password_hash($password, PASSWORD_BCRYPT);
                $stmt = $pdo->prepare("INSERT INTO users (vessel_id, role, full_name, email, password_hash, is_active)
                                       VALUES (:vessel_id, :role, :full_name, :email, :hash, 1)");
                $stmt->bindValue(':vessel_id', $vessel_id, $vessel_id ? PDO::PARAM_INT : PDO::PARAM_NULL);
                $stmt->bindValue(':role', $u_role);
                $stmt->bindValue(':full_name', $full_name);
                $stmt->bindValue(':email', $email);
                $stmt->bindValue(':hash', $hash);
                $stmt->execute();
                $message = "✅ User created.";
            } else {
                $message = "⚠️ Please fill all required fields.";
            }
        }

        if ($_POST['action'] === 'edit') {
            $edit_id = (int) $_POST['user_id'];
            $full_name = trim($_POST['full_name']);
            $email = trim($_POST['email']);
            $u_role = $_POST['role'];
            $is_active = isset($_POST['is_active']) ? (int) $_POST['is_active'] : 1;
            $vessel_id = !empty($_POST['vessel_id']) ? (int) $_POST['vessel_id'] : null;

            $sql = "UPDATE users SET full_name=:full_name, email=:email, role=:role, is_active=:is_active, vessel_id=:vessel_id";
            $params = [
                ':full_name' => $full_name,
                ':email' => $email,
                ':role' => $u_role,
                ':is_active' => $is_active,
                ':vessel_id' => $vessel_id,
                ':id' => $edit_id
            ];

            if (!empty($_POST['password'])) {
                $sql .= ", password_hash=:hash";
                $params[':hash'] = password_hash($_POST['password'], PASSWORD_BCRYPT);
            }
            $sql .= " WHERE user_id=:id";

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $message = "✅ User updated.";
        }

        if ($_POST['action'] === 'delete') {
            $del_id = (int) $_POST['user_id'];
            $stmt = $pdo->prepare("DELETE FROM users WHERE user_id=:id");
            $stmt->execute([':id' => $del_id]);
            $message = "🗑️ User deleted.";
        }
    } catch (PDOException $e) {
        $message = "❌ Error: " . $e->getMessage();
    }
}

/* ---------------------- Filters ---------------------- */
$vessels = $pdo->query("SELECT vessel_id, vessel_name FROM vessels ORDER BY vessel_name")->fetchAll();

/* ✅ fetch crew list for dropdown */
$crewlist = $pdo->query("SELECT id, full_name, email FROM crewlist ORDER BY full_name ASC")->fetchAll();

$where = [];
$params = [];

if (!empty($_GET['role'])) {
    $where[] = "u.role = :role";
    $params[':role'] = $_GET['role'];
}
if (!empty($_GET['vessel_id'])) {
    $where[] = "u.vessel_id = :vessel_id";
    $params[':vessel_id'] = (int) $_GET['vessel_id'];
}
if (!empty($_GET['search'])) {
    $where[] = "(u.full_name LIKE :search OR u.email LIKE :search)";
    $params[':search'] = "%" . $_GET['search'] . "%";
}

$sql = "SELECT u.user_id, u.full_name, u.email, u.role, u.is_active, u.vessel_id, v.vessel_name
        FROM users u
        LEFT JOIN vessels v ON u.vessel_id = v.vessel_id";
if ($where)
    $sql .= " WHERE " . implode(" AND ", $where);
$sql .= " ORDER BY u.role, u.full_name";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$users = $stmt->fetchAll();
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
        /* Filters */
        .user-filter-box {
            display: flex;
            gap: 12px;
            margin-bottom: 15px;
            align-items: center
        }

        .user-filter-box select,
        .user-filter-box input {
            padding: 8px 12px;
            border: 1px solid var(--color-light);
            border-radius: var(--border-radius-1);
            min-width: 160px
        }

        /* Modal */
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
            width: 520px;
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

        .modal-content input,
        .modal-content select {
            padding: 8px;
            border: 1px solid var(--color-light);
            border-radius: var(--border-radius-1)
        }

        .user-filter-box {
            margin-bottom: 20px;
        }

        .user-filter-box form {
            display: flex;
            flex-direction: row;
            /* force horizontal layout */
            gap: 12px;
            /* space between items */
            align-items: center;
            /* align vertically */
        }

        .user-filter-box select,
        .user-filter-box input,
        .user-filter-box button {
            padding: 8px 12px;
            border: 1px solid var(--color-light);
            border-radius: var(--border-radius-1);
            min-width: 160px;
        }

        .user-filter-box button {
            background: var(--color-primary);
            color: #fff;
            cursor: pointer;
            border: none;
            transition: background 0.3s ease;
        }

        .user-filter-box button:hover {
            background: var(--color-primary-dark);
        }
        .password-container {
    position: relative;
    display: flex;
    align-items: center;
}

.password-container input {
    width: 100%;
    padding-right: 40px; /* space for the eye icon */
}

.toggle-password {
    position: absolute;
    right: 10px;
    cursor: pointer;
    color: var(--color-dark-variant);
    user-select: none;
    transition: color 0.3s ease;
}

.toggle-password:hover {
    color: var(--color-primary);
}

.toggle-password i {
    font-size: 20px;
    line-height: 1;
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
                <a href="manage-user.php" class="active"><span class="material-icons-sharp">group</span>
                    <h3>Manage Users</h3>
                </a>
                <a href="crew-list.php"><span class="material-icons-sharp">groups</span>
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

        <!-- Main -->
        <main>
            <h1>Manage Users</h1>
            <?php if (!empty($message)): ?>
                <p style="margin:10px 0"><?php echo htmlspecialchars($message); ?></p>
            <?php endif; ?>

            <!-- ✅ Filters restored -->
            <div class="user-filter-box">
                <form method="GET">
                    <select name="role" onchange="this.form.submit()">
                        <option value="">-- Filter by Role --</option>
                        <option value="ADMIN" <?php if (($_GET['role'] ?? '') === 'ADMIN')
                            echo 'selected'; ?>>Admin
                        </option>
                        <option value="CAPTAIN" <?php if (($_GET['role'] ?? '') === 'CAPTAIN')
                            echo 'selected'; ?>>Captain
                        </option>
                        <option value="OFFICE" <?php if (($_GET['role'] ?? '') === 'OFFICE')
                            echo 'selected'; ?>>Office
                        </option>
                        <option value="TEMP" <?php if (($_GET['role'] ?? '') === 'TEMP')
                            echo 'selected'; ?>>Temp</option>
                    </select>
                    <select name="vessel_id" onchange="this.form.submit()">
                        <option value="">-- Filter by Vessel --</option>
                        <?php foreach ($vessels as $v): ?>
                            <option value="<?= $v['vessel_id']; ?>" <?php if (($_GET['vessel_id'] ?? '') == $v['vessel_id'])
                                  echo 'selected'; ?>>
                                <?= htmlspecialchars($v['vessel_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <input type="text" name="search" placeholder="Search name or email"
                        value="<?= htmlspecialchars($_GET['search'] ?? ''); ?>" />
                    <button type="submit">Search</button>
                </form>
            </div>

            <!-- Users Table -->
            <div class="availability-table">
                <h2>Existing Users</h2>
                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Full Name</th>
                            <th>Email</th>
                            <th>Role</th>
                            <th>Vessel</th>
                            <th>Status</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($users as $u): ?>
                            <tr>
                                <td><?= $u['user_id']; ?></td>
                                <td><?= htmlspecialchars($u['full_name']); ?></td>
                                <td><?= htmlspecialchars($u['email']); ?></td>
                                <td><?= $u['role']; ?></td>
                                <td><?= $u['vessel_name'] ?: "<i>No Vessel</i>"; ?></td>
                                <td><?= $u['is_active'] ? "Active" : "Inactive"; ?></td>
                                <td>
                                    <button type="button" class="edit" onclick='openModal(JSON.parse(this.dataset.user))'
                                        data-user='<?= htmlspecialchars(json_encode($u), ENT_QUOTES, "UTF-8"); ?>'>Edit</button>
                                    <form method="POST" style="display:inline"
                                        onsubmit="return confirm('Delete this user?');">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="user_id" value="<?= $u['user_id']; ?>">
                                        <button type="submit" class="delete">Delete</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Add New User -->
            <div class="createApp">
                <h2>Create New User</h2>
                <form method="POST">
                    <input type="hidden" name="action" value="add">

                    <!-- Crew dropdown -->
                    <label>Select Crew</label>
                    <select id="crew_select" name="full_name" required>
                        <option value="">-- Select Crew --</option>
                        <?php foreach ($crewlist as $c): ?>
                            <option value="<?= htmlspecialchars($c['full_name']); ?>"
                                data-email="<?= htmlspecialchars($c['email']); ?>">
                                <?= htmlspecialchars($c['full_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <!-- auto-filled email -->
                    <label>Email</label>
                    <input type="email" id="crew_email" name="email" placeholder="Email" required readonly>

                    <input type="password" name="password" placeholder="Password" required>
                    <select name="role" required>
                        <option value="">-- Select Role --</option>
                        <option value="CAPTAIN">Captain</option>
                        <option value="OFFICE">Office</option>
                        <option value="TEMP">Temp</option>
                        <option value="ADMIN">Admin</option>
                    </select>
                    <select name="vessel_id">
                        <option value="">-- No Vessel Assigned --</option>
                        <?php foreach ($vessels as $v): ?>
                            <option value="<?= $v['vessel_id']; ?>"><?= htmlspecialchars($v['vessel_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit">Add User</button>
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

    <!-- Edit Modal -->
    <div id="editModal" class="modal">
        <div class="modal-content">
            <span class="modal-close" onclick="closeModal()">&times;</span>
            <h2>Edit User</h2>
            <form method="POST" id="editForm">
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="user_id" id="edit_user_id">

                <input type="text" name="full_name" id="edit_full_name" required placeholder="Full Name">
                <input type="email" name="email" id="edit_email" required placeholder="Email">

                <!-- Password input with toggle -->
                <div class="password-container">
                    <input type="password" name="password" id="edit_password" placeholder="New Password (optional)">
                    <span class="toggle-password" onclick="togglePassword('edit_password', this)">
                        <i class="material-icons-sharp">visibility_off</i>
                    </span>
                </div>

                <select name="role" id="edit_role" required>
                    <option value="ADMIN">Admin</option>
                    <option value="CAPTAIN">Captain</option>
                    <option value="OFFICE">Office</option>
                    <option value="TEMP">Temp</option>
                </select>

                <select name="is_active" id="edit_is_active" required>
                    <option value="1">Active</option>
                    <option value="0">Inactive</option>
                </select>

                <select name="vessel_id" id="edit_vessel_id">
                    <option value="">-- No Vessel Assigned --</option>
                    <?php foreach ($vessels as $v): ?>
                        <option value="<?php echo $v['vessel_id']; ?>">
                            <?php echo htmlspecialchars($v['vessel_name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <button type="submit" class="edit">Save Changes</button>
            </form>
        </div>
    </div>

<script>
  // Toggle show/hide password
  function togglePassword(fieldId, iconSpan) {
    const input = document.getElementById(fieldId);
    if (!input) return;
    const icon = iconSpan?.querySelector('i');
    const show = input.type === "password";
    input.type = show ? "text" : "password";
    if (icon) icon.textContent = show ? "visibility" : "visibility_off";
  }

  // Open/close modal (single source of truth)
  function openModal(user) {
    user = user || {};
    const byId = (id) => document.getElementById(id);

    byId('edit_user_id').value   = user.user_id ?? '';
    byId('edit_full_name').value = user.full_name ?? '';
    byId('edit_email').value     = user.email ?? '';
    byId('edit_role').value      = user.role ?? 'TEMP';
    byId('edit_is_active').value = String(user.is_active ?? '1');
    byId('edit_vessel_id').value = user.vessel_id ?? '';

    // Always clear password & reset eye icon
    const pwd = byId('edit_password');
    if (pwd) {
      pwd.type = 'password';
      pwd.value = '';
      const eye = pwd.parentElement?.querySelector('.toggle-password i');
      if (eye) eye.textContent = 'visibility_off';
    }

    const modal = byId('editModal');
    if (modal) modal.style.display = 'flex';
  }

  function closeModal() {
    const modal = document.getElementById('editModal');
    if (modal) modal.style.display = 'none';
  }

  // Optional: click outside to close + ESC to close
  document.addEventListener('click', (e) => {
    const modal = document.getElementById('editModal');
    if (!modal) return;
    if (e.target === modal) closeModal();
  });
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') closeModal();
  });

  // Safe hook for crew email autofill
  document.addEventListener('DOMContentLoaded', () => {
    const crewSelect = document.getElementById('crew_select');
    const crewEmail  = document.getElementById('crew_email');
    if (crewSelect && crewEmail) {
      crewSelect.addEventListener('change', function () {
        const opt   = this.options[this.selectedIndex];
        const email = opt?.getAttribute('data-email') || '';
        crewEmail.value = email;
      });
    }
  });
</script>


    <script src="index.js"></script>
    <script src="appointment.js"></script>
    <script src="clock.js"></script>
</body>

</html>