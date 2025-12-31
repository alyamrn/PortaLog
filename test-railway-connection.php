<?php
echo "🔍 Railway Connection Diagnostic\n";
echo "================================\n\n";

$host = 'switchyard.proxy.rlwy.net';
$port = 43296;

// Test 1: Check DNS
echo "1️⃣  Testing DNS resolution for $host...\n";
$ip = gethostbyname($host);
if ($ip !== $host) {
    echo "   ✅ Resolved to: $ip\n";
} else {
    echo "   ❌ DNS resolution failed\n";
}

// Test 2: Check port connectivity
echo "\n2️⃣  Testing port connectivity...\n";
$connection = @fsockopen($host, $port, $errno, $errstr, 5);
if ($connection) {
    echo "   ✅ Port $port is reachable\n";
    fclose($connection);
} else {
    echo "   ❌ Port $port is NOT reachable\n";
    echo "   Error: $errstr (Code: $errno)\n";
}

// Test 3: Try MySQL connection with mysqli
echo "\n3️⃣  Testing MySQL connection with MySQLi...\n";
$mysqli = new mysqli(
    $host,
    'root',
    'xKmGMYqFBhQ0Qj1oaCPRu1LNpcvQPLFh',
    'railway',
    $port
);

if ($mysqli->connect_error) {
    echo "   ❌ MySQLi Connection failed:\n";
    echo "   Error: " . $mysqli->connect_error . "\n";
} else {
    echo "   ✅ MySQLi Connected successfully!\n";
    $mysqli->close();
}

// Test 4: Try MySQL connection with PDO
echo "\n4️⃣  Testing MySQL connection with PDO...\n";
try {
    $pdo = new PDO(
        "mysql:host=$host;port=$port;dbname=railway;charset=utf8mb4",
        'root',
        'xKmGMYqFBhQ0Qj1oaCPRu1LNpcvQPLFh',
        [PDO::ATTR_TIMEOUT => 5]
    );
    echo "   ✅ PDO Connected successfully!\n";
} catch (PDOException $e) {
    echo "   ❌ PDO Connection failed:\n";
    echo "   Error: " . $e->getMessage() . "\n";
}

echo "\n================================\n";
echo "📝 Summary:\n";
echo "If test 3 or 4 passes, your connection works!\n";
echo "If all fail, check:\n";
echo "  • Your internet connection\n";
echo "  • Firewall/antivirus blocking port 43296\n";
echo "  • Railway credentials are correct\n";
?>
