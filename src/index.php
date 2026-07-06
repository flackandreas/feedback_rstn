<?php
/**
 * src/index.php
 * Portal Choice Page
 */

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/migrations.php';
run_all_migrations();

require_login();

$user_name = get_current_user_name();

require_once __DIR__ . '/includes/twig_setup.php';

$flash_success = $_SESSION['flash_success'] ?? null;
$flash_error = $_SESSION['flash_error'] ?? null;
unset($_SESSION['flash_success'], $_SESSION['flash_error']);

$sso_secret = 'SchulHub_SSO_Secret_Key_2026';
$time_bucket = floor(time() / 300);
$token = hash('sha256', get_current_user_kuerzel() . $sso_secret . $time_bucket);

$host = $_SERVER['HTTP_HOST'];
$host_name = explode(':', $host)[0];
$url_antraege = '/dashboard';
$url_unterricht = '//' . $host_name . ':8889/login.php?autologin=1&kuerzel=' . urlencode(get_current_user_kuerzel()) . '&token=' . $token;

echo $twig->render('portal_choice.twig', [
    'current_user_name' => $user_name,
    'is_logged_in' => false,
    'url_antraege' => $url_antraege,
    'url_unterricht' => $url_unterricht,
    'flash_success' => $flash_success,
    'flash_error' => $flash_error
]);
