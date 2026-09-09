<?php
/**
 * src/sso_start.php
 * Schickt den Browser zur Anmeldung ans SchulOS-Portal.
 */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/sso.php';

use SchulOS\Sso\SsoFehler;

if (!sso_aktiv()) {
    header('Location: /login.php');
    exit;
}

if (is_logged_in()) {
    header('Location: /index.php');
    exit;
}

try {
    header('Location: ' . sso_anmeldung()->startAdresse($_GET['weiter'] ?? '/dashboard'));
    exit;
} catch (SsoFehler $e) {
    error_log('Antragssystem: Anmeldung am Portal nicht startbar: ' . $e->getMessage());

    $_SESSION['flash_error'] = 'Das SchulOS-Portal ist gerade nicht erreichbar.';
    header('Location: /login.php?lokal=1');
    exit;
}
