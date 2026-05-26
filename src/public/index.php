<?php
/**
 * src/public/index.php
 * Front Controller & Router
 */

// 1. Security Headers
header("X-Frame-Options: DENY");
header("X-Content-Type-Options: nosniff");
header("Referrer-Policy: strict-origin-when-cross-origin");
header("Permissions-Policy: geolocation=(), camera=(), microphone=()");
header("X-XSS-Protection: 1; mode=block");
if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') {
    header("Strict-Transport-Security: max-age=31536000; includeSubDomains");
}
// Content-Security-Policy (Base)
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline' 'unsafe-eval' https://cdn.jsdelivr.net; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; img-src 'self' data: blob: https://api.qrserver.com; font-src 'self' https://fonts.gstatic.com; connect-src 'self';");


// 2. Routing
$request = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$request = trim($request, '/');

// Mapping routes to controller files
$routes = [
    '' => 'index.php',
    'login' => 'login.php',
    'logout' => 'logout.php',
    'change_password' => 'change_password.php',
    'profile_action' => 'profile_action.php',
    'admin/lehrer' => 'admin_lehrer.php',
    'admin/klassen' => 'admin_klassen.php',
    'admin/aud' => 'admin_aud.php',
    'admin/system' => 'admin_system.php',
    'admin/homework' => 'admin_homework.php',
    'admin/dashboard' => 'admin_dashboard.php',
    'student/homework' => 'student_homework.php',
    'student/feedback' => 'student_feedback.php',
    'krankmeldung' => 'krankmeldung.php',
    'antrag/freistellung' => 'antrag_freistellung.php',
    'antrag/ausserunterrichtlich' => 'antrag_ausserunterrichtlich.php',
    'meine-antraege' => 'meine_antraege.php',
    'feedback/trends' => 'feedback_trends.php',
    'feedback/view' => 'feedback_view.php'
];

// Fallback for legacy .php requests or exact matches
if (array_key_exists($request, $routes)) {
    $file = $routes[$request];
} elseif (preg_match('/^[a-zA-Z0-9_-]+\.php$/', $request) && file_exists(__DIR__ . '/../' . $request)) {
    // Securely allow direct access to root-level PHP controllers only (no directory traversal, no subdirectories like config/ or vendor/)
    $file = $request;
} else {
    $file = 'index.php'; // Default fallback
}

// Load session, auth, and database migrations
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/migrations.php';
run_all_migrations();

$controllerPath = __DIR__ . '/../' . $file;

try {
    if (file_exists($controllerPath)) {
        // Change directory to src so relative includes work
        chdir(__DIR__ . '/../');
        require_once $file;
    } else {
        http_response_code(404);
        echo "404 - Not Found";
    }
} catch (\Throwable $e) {
    // Log exception details
    error_log("Unhandled Exception in controller '$file': " . $e->getMessage() . "\n" . $e->getTraceAsString());
    
    // Set response code
    http_response_code(500);
    
    // Fallback error page
    ?>
    <!DOCTYPE html>
    <html lang="de">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Fehler - School Efficiency Tool</title>
        <link rel="stylesheet" href="/css/app_styles.css">
    </head>
    <body style="display: flex; justify-content: center; align-items: center; min-height: 100vh; background-color: #F7FAFC; font-family: sans-serif;">
        <div class="content-box" style="max-width: 500px; width: 90%; text-align: center; border-top: 4px solid var(--danger-color); margin: 20px;">
            <h1 style="color: var(--danger-color); font-size: 1.8em; margin-top: 0; margin-bottom: 15px;">Ein Fehler ist aufgetreten</h1>
            <p style="color: var(--text-dark); margin-bottom: 20px;">Das System konnte Ihre Anfrage nicht verarbeiten. Der Fehler wurde protokolliert.</p>
            <a href="/index.php" class="button-primary" style="display: inline-block; text-decoration: none;">Zum Dashboard</a>
        </div>
    </body>
    </html>
    <?php
    exit;
}
