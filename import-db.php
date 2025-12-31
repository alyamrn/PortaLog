<?php
session_start();

// Simple database import tool for Railway
// This should only be run once during setup

// Check if form was submitted
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Load .env file
    if (!file_exists(__DIR__ . '/.env')) {
        die("Error: .env file not found");
    }

    $envFile = __DIR__ . '/.env';
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $env = [];
    foreach ($lines as $line) {
        if (strpos($line, '=') !== false && strpos($line, '#') !== 0) {
            list($key, $value) = explode('=', $line, 2);
            $env[trim($key)] = trim($value);
        }
    }

    $railwayHost = $env['DB_HOST'] ?? 'localhost';
    $railwayPort = $env['DB_PORT'] ?? '3306';
    $railwayUser = $env['DB_USERNAME'] ?? 'root';
    $railwayPassword = $env['DB_PASSWORD'] ?? '';
    $railwayDatabase = $env['DB_DATABASE'] ?? 'railway';

    echo "<h2>🔄 Importing Database...</h2>";
    echo "<p>Host: $railwayHost</p>";
    echo "<p>Database: $railwayDatabase</p>";

    try {
        $pdo = new PDO(
            "mysql:host=$railwayHost;port=$railwayPort;dbname=$railwayDatabase;charset=utf8mb4",
            $railwayUser,
            $railwayPassword,
            [PDO::ATTR_TIMEOUT => 10]
        );
        echo "<p>✅ Connected to Railway MySQL</p>";

        // Read dump.sql
        $dumpFile = __DIR__ . '/dump.sql';
        if (!file_exists($dumpFile)) {
            die("❌ Error: dump.sql not found");
        }

        $sql = file_get_contents($dumpFile);
        
        // Split and execute statements
        $statements = array_filter(
            array_map('trim', explode(';', $sql)),
            function($s) { return !empty($s) && strpos($s, '--') !== 0; }
        );

        $count = 0;
        foreach ($statements as $statement) {
            if (!empty(trim($statement))) {
                try {
                    $pdo->exec($statement . ';');
                    $count++;
                } catch (Exception $e) {
                    // Skip duplicate key errors
                    if (strpos($e->getMessage(), 'Duplicate') === false) {
                        echo "<p>⚠️ " . htmlspecialchars($e->getMessage()) . "</p>";
                    }
                }
            }
        }

        echo "<h3>✅ Import Complete!</h3>";
        echo "<p>Executed: $count SQL statements</p>";
        echo "<p><a href='login.php'>Go to Login →</a></p>";

    } catch (PDOException $e) {
        echo "<h3>❌ Connection Error</h3>";
        echo "<p>" . htmlspecialchars($e->getMessage()) . "</p>";
        echo "<p>Make sure Railway MySQL is accessible and credentials in .env are correct.</p>";
    }
} else {
    ?>
    <html>
    <head>
        <title>Database Import Tool</title>
        <style>
            body { font-family: Arial, sans-serif; margin: 40px; }
            button { background: #4CAF50; color: white; padding: 10px 20px; border: none; cursor: pointer; }
            button:hover { background: #45a049; }
            .warning { color: #ff9800; padding: 10px; }
        </style>
    </head>
    <body>
        <h1>🔧 Database Import Tool</h1>
        <p>This tool will import your local <code>dump.sql</code> to Railway MySQL.</p>
        
        <div class="warning">
            <strong>⚠️ Warning:</strong> This should only be run once!
        </div>

        <form method="POST">
            <button type="submit">Start Import</button>
        </form>

        <p><a href="login.php">Cancel and go to Login</a></p>
    </body>
    </html>
    <?php
}
?>
