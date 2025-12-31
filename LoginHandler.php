<?php
session_start();
require_once "db_connect.php"; // Database connection

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $email = trim($_POST["email"]);
    $password = trim($_POST["password"]);

    if (!empty($email) && !empty($password)) {
        try {
            // Check if user exists
            $stmt = $pdo->prepare("
                SELECT user_id, full_name, role, vessel_id, password_hash, is_active 
                FROM users 
                WHERE email = :email 
                LIMIT 1
            ");
            $stmt->execute([':email' => $email]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($user && $user['is_active'] == 1 && password_verify($password, $user['password_hash'])) {

                // ✅ Store session data
                $_SESSION['user_id'] = $user['user_id'];
                $_SESSION['full_name'] = $user['full_name'];
                $_SESSION['role'] = $user['role'];
                $_SESSION['vessel_id'] = $user['vessel_id'] ?? null;

                // ✅ Redirect based on role
                switch (strtoupper($user['role'])) {
                    case 'ADMIN':
                        header("Location: admin-dashboard.php");
                        break;

                    case 'OFFICE':
                        header("Location: office-dashboard.php");
                        break;

                    case 'CAPTAIN':
                    case 'VESSEL':
                    default:
                        header("Location: dashboard.php"); // Vessel dashboard
                        break;
                }
                exit;

            } else {
                // Invalid credentials or inactive account
                header("Location: login.php?status=invalid");
                exit;
            }

        } catch (PDOException $e) {
            error_log("Database error in login: " . $e->getMessage());
            header("Location: login.php?status=error");
            exit;
        }

    } else {
        header("Location: login.php?status=invalid");
        exit;
    }

} else {
    header("Location: login.php");
    exit;
}
?>