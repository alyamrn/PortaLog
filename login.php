<?php
// Start session if needed
session_start();

// Define context path dynamically (adjust if your project is in a subfolder)
$contextPath = ""; // e.g., "/aams" if deployed under subfolder

// Handle invalid login message
$status = isset($_GET['status']) ? $_GET['status'] : "";
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://fonts.googleapis.com/css2?family=Material+Icons+Sharp" rel="stylesheet" />
    <link rel="stylesheet" href="<?php echo $contextPath; ?>style.css">
    <title>Login Page</title>
</head>

<body>
    <div class="container">
        <aside>
            <div class="toggle">
                <div class="logo">
                    <img src="<?php echo $contextPath; ?>image/BSK_LOGO.jpg" alt="Logo">
                    <h2>Porta<span class="danger">Log</span></h2>
                </div>
                <div class="close" id="close-btn">
                    <span class="material-icons-sharp">close</span>
                </div>
            </div>
        </aside>
        <!--End of sidebar-->

        <!--Main content-->
        <main>
            <div class="login-form">
                <div class="form-container">
                    <div class="form-header">
                        <img src="<?php echo $contextPath; ?>image/BSK_LOGO.jpg" alt="User Icon"
                            style="width: 80px; margin-bottom: 10px; boader-radius:20%;">
                        <h2>Login</h2>
                    </div>

                    <form action="LoginHandler.php" method="POST">
                        <label for="email">Email:</label>
                        <input type="email" id="email" name="email" placeholder="email" required>

                        <label for="password">Password:</label>
                        <input type="password" id="password" name="password" placeholder="password" required>
                        <?php if ($status === "invalid") { ?>
                            <script>
                                alert("Invalid username or password.");
                            </script>
                        <?php } ?>

                        <button type="submit">Login</button>
                    </form>

                    <div class="form-footer">
                        <a href="<?php echo $contextPath; ?>register.php">Doesn't have an account? Click Here</a>
                    </div>
                </div>
            </div>
        </main>
        <!--End of main-->

        <!--Right section-->
        <div class="right-section">
            <div class="nav">
                <button id="menu-btn">
                    <span class="material-icons-sharp">menu</span>
                </button>
                <div class="dark-mode">
                    <span class="material-icons-sharp active">light_mode</span>
                    <span class="material-icons-sharp">dark_mode</span>
                </div>
                <div class="profile">
                    <div class="info">
                        <p>Hey, <b>Welcome</b></p>
                        <small class="text-muted"></small>
                    </div>
                    <div class="profile-photo">
                        <img src="<?php echo $contextPath; ?>/image/blankProf.png" alt="Profile Photo">
                    </div>
                </div>
            </div>
            <!--End of nav-->
        </div>
    </div>

    <script src="<?php echo $contextPath; ?>index.js"></script>
    <script src="<?php echo $contextPath; ?>/appointment.js"></script>
</body>

</html>