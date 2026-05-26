<?php
/**
 * src/logout.php
 * Logout handler
 */

require_once __DIR__ . '/includes/auth.php';

session_unset();
session_destroy();

header("Location: /login.php");
exit;
?>
