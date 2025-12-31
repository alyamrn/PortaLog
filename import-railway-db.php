<?php
// This script imports the local database dump to Railway MySQL
// Run this ONCE then delete it

set_time_limit(300); // 5 minutes timeout

$railwayHost = 'switchyard.proxy.rlwy.net'; // Use public URL for local import
$railwayPort = 43296;
$railwayUser = 'root';
$railwayPassword = 'xKmGMYqFBhQ0Qj1oaCPRu1LNpcvQPLFh';
$railwayDatabase = 'railway';

$dumpFile = __DIR__ . '/dump.sql';

if (!file_exists($dumpFile)) {
    die("❌ Error: dump.sql not found in project root");
}

try {
    echo "🔄 Connecting to Railway MySQL...\n";
    $pdo = new PDO(
        "mysql:host=$railwayHost;port=$railwayPort;dbname=$railwayDatabase;charset=utf8mb4",
        $railwayUser,
        $railwayPassword
    );
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    echo "✅ Connected to Railway!\n\n";

    // Read and execute SQL dump
    echo "📥 Reading dump.sql...\n";
    $sqlContent = file_get_contents($dumpFile);
    
    // Split by semicolon and execute each statement
    $statements = array_filter(
        array_map('trim', explode(';', $sqlContent)),
        function($s) { return !empty($s) && strpos($s, '--') !== 0; }
    );

    $count = 0;
    foreach ($statements as $statement) {
        if (!empty(trim($statement))) {
            try {
                $pdo->exec($statement . ';');
                $count++;
                if ($count % 50 === 0) {
                    echo "  ✅ Executed $count statements...\n";
                }
            } catch (PDOException $e) {
                // Skip errors from duplicate keys, etc.
                if (strpos($e->getMessage(), 'Duplicate entry') === false) {
                    echo "  ⚠️  Warning: " . $e->getMessage() . "\n";
                }
            }
        }
    }

    echo "\n✅ Database import complete!\n";
    echo "📊 Total statements executed: $count\n";
    echo "\n🎉 Your Railway database is now ready!\n";
    echo "You can delete this file (import-railway-db.php) now.\n";

} catch (PDOException $e) {
    echo "❌ Connection failed: " . $e->getMessage() . "\n";
    echo "Make sure you're on a network that can reach Railway MySQL.\n";
}
?>
