<?php
require_once "db_connect.php"; // your PDO connection file

$message = "";
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $full_name = trim($_POST["full_name"]);
    $email = trim($_POST["email"]);
    $password = $_POST["password"];
    $role = $_POST["role"];
    $vessel_id = !empty($_POST["vessel_id"]) ? $_POST["vessel_id"] : null;

    if (!empty($full_name) && !empty($email) && !empty($password) && !empty($role)) {
        $passwordHash = password_hash($password, PASSWORD_BCRYPT);

        try {
            $stmt = $pdo->prepare("INSERT INTO users (vessel_id, role, full_name, email, password_hash, is_active) 
                                   VALUES (:vessel_id, :role, :full_name, :email, :password_hash, 1)");
            $stmt->bindValue(':vessel_id', $vessel_id, $vessel_id ? PDO::PARAM_INT : PDO::PARAM_NULL);
            $stmt->bindValue(':role', $role);
            $stmt->bindValue(':full_name', $full_name);
            $stmt->bindValue(':email', $email);
            $stmt->bindValue(':password_hash', $passwordHash);
            $stmt->execute();
            $message = "✅ User registered successfully!";
        } catch (PDOException $e) {
            if ($e->getCode() == 23000) {
                $message = "⚠️ Email already exists!";
            } else {
                $message = "❌ Error: " . $e->getMessage();
            }
        }
    } else {
        $message = "⚠️ Please fill in all required fields.";
    }
}

// Fetch vessels for dropdown (optional)
$vessels = $pdo->query("SELECT vessel_id, vessel_name FROM vessels ORDER BY vessel_name")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://fonts.googleapis.com/css2?family=Material+Icons+Sharp" rel="stylesheet" />
    <link rel="stylesheet" href="style.css">
    <title>Register User</title>
</head>

<body>
    <div class="login-form">
        <div class="form-container">
            <div class="form-header">
                <h2>Register User</h2>
            </div>

            <?php if (!empty($message)): ?>
                <p style="margin-bottom:10px;"><?php echo $message; ?></p>
            <?php endif; ?>

            <form method="POST">
                <input type="text" name="full_name" placeholder="Full Name" required>
                <input type="email" name="email" placeholder="Email" required>
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
                        <option value="<?php echo $v['vessel_id']; ?>">
                            <?php echo htmlspecialchars($v['vessel_name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <button type="submit">Register</button>
            </form>
            <div class="form-footer">
                <a href="login.php">Back to Login</a>
            </div>
        </div>
    </div>
</body>

</html>