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
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline' 'unsafe-eval'; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob:; font-src 'self'; connect-src 'self';");

// 2. Routing
$request = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$request = trim($request, '/');

// Mapping routes to controller files
$routes = [
    '' => 'index.php',
    'login' => 'login.php',
    'logout' => 'logout.php',
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

$controllerPath = __DIR__ . '/../' . $file;

if (file_exists($controllerPath)) {
    // Change directory to src so relative includes work
    chdir(__DIR__ . '/../');
    require_once $file;
} else {
    http_response_code(404);
    echo "404 - Not Found";
}
