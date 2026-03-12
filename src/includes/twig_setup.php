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

// Helper extension: Expose a function to fetch base URL or active navigation states if needed
$twig->addFunction(new \Twig\TwigFunction('is_current_page', function ($page) {
    return basename($_SERVER['PHP_SELF']) === $page;
}));
