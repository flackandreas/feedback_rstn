<?php
/**
 * src/includes/twig_setup.php
 * Bootstrapper for the Twig template engine.
 */

require_once __DIR__ . '/../vendor/autoload.php';

// Prepare Twig Environment
$loader = new \Twig\Loader\FilesystemLoader(__DIR__ . '/../templates');

$twig = new \Twig\Environment($loader, [
    // We disable cache during active development. In production, provide a cache path.
    'cache' => false, 
    'auto_reload' => true,
    'strict_variables' => false
]);

// Helper extension: Expose a function to fetch active navigation states
/**
 * Haengt die Aenderungszeit einer Datei aus public/ als Version an ihre
 * Adresse. Ohne das liefern Zwischenspeicher - hinter Cloudflare bis zu vier
 * Stunden lang - noch die alte Fassung aus.
 */
$twig->addFunction(new \Twig\TwigFunction('asset', function (string $pfad): string {
    $datei = __DIR__ . '/../public/' . ltrim($pfad, '/');
    $stand = is_readable($datei) ? filemtime($datei) : time();
    return $pfad . '?v=' . $stand;
}));

$twig->addFunction(new \Twig\TwigFunction('is_current_page', function ($page) {
    return basename($_SERVER['PHP_SELF']) === $page;
}));

// Add json_decode filter
$twig->addFilter(new \Twig\TwigFilter('json_decode', function ($string) {
    return json_decode($string, true) ?: [];
}));

// Global pending counts for admin badges
if (isset($conn) && isset($_SESSION['is_admin']) && $_SESSION['is_admin'] == 1) {
    require_once __DIR__ . '/admin_helpers.php';
    $twig->addGlobal('pending_counts', get_pending_counts($conn));
}
