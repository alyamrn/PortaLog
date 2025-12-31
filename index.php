<?php
// Router for Railway deployment
// This handles all requests and serves files or redirects to the appropriate page

$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$uri = str_replace('/index.php', '', $uri);

// If requesting root, redirect to login
if ($uri === '/' || $uri === '') {
    header('Location: /login.php');
    exit;
}

// If the file exists and is not a directory, serve it
if (file_exists(__DIR__ . $uri) && !is_dir(__DIR__ . $uri)) {
    // Let PHP serve the file directly
    return false;
}

// If it's a directory with index.php, serve that
if (is_dir(__DIR__ . $uri) && file_exists(__DIR__ . $uri . '/index.php')) {
    include __DIR__ . $uri . '/index.php';
    exit;
}

// Otherwise 404
http_response_code(404);
echo "404 Not Found: " . htmlspecialchars($uri);
?>
