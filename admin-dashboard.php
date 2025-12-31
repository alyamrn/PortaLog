<?php
session_start();
require_once "db_connect.php";

// Check login and role
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'ADMIN') {
    header("Location: login.php");
    exit;
}

$userId = $_SESSION['user_id'];
$username = $_SESSION['full_name'];
$role = $_SESSION['role'];
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://fonts.googleapis.com/css2?family=Material+Icons+Sharp" rel="stylesheet" />
    <link rel="stylesheet" href="style.css">
    <title>Admin Dashboard</title>
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
                <a href="admin-dashboard.php" class="active"><span class="material-icons-sharp">dashboard</span>
                    <h3>Dashboard</h3>
                </a>
                <a href="manage-user.php"><span class="material-icons-sharp">group</span>
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
                <a href="login.php" class="logout"><span class="material-icons-sharp">logout</span>
                    <h3>LogOut</h3>
                </a>
            </div>
        </aside>
        <style>
            /* Admin Dashboard User Filter */
            .user-filter-box {
                background: var(--color-white);
                padding: var(--card-padding);
                border-radius: var(--card-border-radius);
                box-shadow: var(--box-shadow);
                margin-bottom: 20px;
                display: flex;
                align-items: center;
                gap: 15px;
            }

            .user-filter-box label {
                font-weight: bold;
                font-size: 1rem;
                color: var(--color-dark);
            }

            .user-filter-box select {
                padding: 10px 14px;
                border-radius: var(--border-radius-1);
                border: 1px solid var(--color-light);
                background-color: var(--color-primary);
                font-size: 0.95rem;
                min-width: 200px;
                cursor: pointer;
                transition: all 0.3s ease;
            }

            .user-filter-box select:hover {
                border-color: var(--color-primary);
                background-color: whitesmoke;
            }

            .user-filter-box select:focus {
                outline: none;
                border-color: var(--color-primary);
                box-shadow: 0 0 6px var(--color-primary);
            }

            /* Limit height of user table and make it scrollable */
            .user-table-wrapper {
                max-height: 400px;
                /* adjust to how tall you want it */
                overflow-y: auto;
                /* vertical scrollbar */
                border: 1px solid var(--color-light);
                border-radius: var(--border-radius-1);
            }

            /* Keep table header fixed while scrolling */
            .user-table-wrapper table {
                width: 100%;
                border-collapse: collapse;
            }

            .user-table-wrapper thead th {
                position: sticky;
                top: 0;
                background: var(--color-background);
                z-index: 2;
            }
        </style>
        <!-- Main -->
        <main>
            <?php
            require_once "db_connect.php";

            // Fetch vessels for filter dropdown
            $vessels = $pdo->query("SELECT vessel_id, vessel_name FROM vessels ORDER BY vessel_name")->fetchAll();

            // Handle filter
            $filterVessel = isset($_GET['vessel_id']) ? $_GET['vessel_id'] : "";

            if (!empty($filterVessel)) {
                $stmt = $pdo->prepare("SELECT u.user_id, u.full_name, u.email, u.role, u.is_active, v.vessel_name
                           FROM users u
                           LEFT JOIN vessels v ON u.vessel_id = v.vessel_id
                           WHERE u.vessel_id = :vessel_id
                           ORDER BY u.role, u.full_name");
                $stmt->execute([':vessel_id' => $filterVessel]);
            } else {
                $stmt = $pdo->query("SELECT u.user_id, u.full_name, u.email, u.role, u.is_active, v.vessel_name
                         FROM users u
                         LEFT JOIN vessels v ON u.vessel_id = v.vessel_id
                         ORDER BY u.role, u.full_name");
            }
            $users = $stmt->fetchAll();
            ?>

            <div class="app-Table">
                <h2>User List</h2>

                <!-- Filter Form -->
                <div class="user-filter-box">
                    <form method="GET" style="display:flex; gap:10px; align-items:center; width:100%;">
                        <label for="vessel_id">Filter by Vessel:</label>
                        <select name="vessel_id" id="vessel_id" onchange="this.form.submit()">
                            <option value="">-- All Vessels --</option>
                            <?php foreach ($vessels as $v): ?>
                                <option value="<?php echo $v['vessel_id']; ?>" <?php if ($filterVessel == $v['vessel_id'])
                                       echo 'selected'; ?>>
                                    <?php echo htmlspecialchars($v['vessel_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </form>
                </div>


                <!-- User Table -->
                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Full Name</th>
                            <th>Email</th>
                            <th>Role</th>
                            <th>Vessel</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($users): ?>
                            <?php foreach ($users as $u): ?>
                                <tr>
                                    <td><?php echo $u['user_id']; ?></td>
                                    <td><?php echo htmlspecialchars($u['full_name']); ?></td>
                                    <td><?php echo htmlspecialchars($u['email']); ?></td>
                                    <td><?php echo $u['role']; ?></td>
                                    <td><?php echo $u['vessel_name'] ?: "<i>No Vessel</i>"; ?></td>
                                    <td><?php echo $u['is_active'] ? "Active" : "Inactive"; ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="6"><i>No users found.</i></td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
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
                        <small class="text-muted"><?php echo $role; ?></small>
                    </div>
                    <div class="profile-photo"><img src="image/blankProf.png" alt="Profile Photo"></div>
                </div>

            </div>
            <div class="user-profile">
                <div class="logo">
                    <img src="image/BSK_LOGO.jpg">
                    <h2>PortaLog</h2>
                    <p>VesselDaily Report System </p>
                </div>
            </div>
        </div>
    </div>

    <script src="index.js"></script>
    <script src="appointment.js"></script>
    <script src="clock.js"></script>
</body>

</html>