<?php
/**
 * src/includes/header.php
 * Common header and navigation structure
 */
require_once __DIR__ . '/auth.php';

// Determine the current page for active nav states
$current_page = basename($_SERVER['PHP_SELF']);
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>School Efficiency Tool</title>
    <link rel="stylesheet" href="/css/app_styles.css">
    <!-- Quick external font fallback to match the app style vibes -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <script src="/js/app.js" defer></script>
    <style>
        /* Fallback quick fixes if app_styles.css isn't perfectly carrying over all resets */
        body { font-family: 'Inter', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif; }
    </style>
</head>
<body>

<?php if (is_logged_in()): ?>
<div class="app-layout" id="appLayout">
    <!-- Sidebar Navigation -->
    <nav class="sidebar" id="sidebar">
        <div class="sidebar-header">
            <div>
                <div class="sidebar-title">Verwaltung</div>
                <span class="sidebar-schule">E-Anträge</span>
            </div>
            <button id="nav-toggle" aria-label="Toggle menu">☰</button>
        </div>
        
        <ul class="nav-menu">
            <li class="nav-label">Main</li>
            <li class="nav-item">
                <a href="/index.php" class="<?= $current_page == 'index.php' ? 'active' : '' ?>">
                    <span class="nav-icon">🏠</span>
                    <span class="nav-text">Dashboard</span>
                </a>
            </li>
            
            <li class="nav-label">Anträge</li>
            <li class="nav-item">
                <a href="/antrag_ausserunterrichtlich.php" class="<?= $current_page == 'antrag_ausserunterrichtlich.php' ? 'active' : '' ?>">
                    <span class="nav-icon">🚌</span>
                    <span class="nav-text">Außerunterrichtlich</span>
                </a>
            </li>
            <li class="nav-item">
                <a href="/antrag_freistellung.php" class="<?= $current_page == 'antrag_freistellung.php' ? 'active' : '' ?>">
                    <span class="nav-icon">🏖️</span>
                    <span class="nav-text">Freistellung</span>
                </a>
            </li>
            <li class="nav-item">
                <a href="/krankmeldung.php" class="<?= $current_page == 'krankmeldung.php' ? 'active' : '' ?>">
                    <span class="nav-icon">🤒</span>
                    <span class="nav-text">Krankmeldung</span>
                </a>
            </li>

            <div class="nav-separator"></div>

            <li class="nav-label">Übersicht</li>
            <li class="nav-item">
                <a href="/meine_antraege.php" class="<?= $current_page == 'meine_antraege.php' ? 'active' : '' ?>">
                    <span class="nav-icon">📂</span>
                    <span class="nav-text">Meine Anträge</span>
                </a>
            </li>
            
            <?php if (is_current_user_admin()): ?>
            <div class="nav-separator"></div>
            
            <li class="nav-label" style="color: var(--danger-color-dark);">Administration</li>
            <li class="nav-item">
                <a href="/admin_dashboard.php" class="<?= $current_page == 'admin_dashboard.php' ? 'active' : '' ?>">
                    <span class="nav-icon">🛡️</span>
                    <span class="nav-text">Verwaltung</span>
                </a>
            </li>
            <?php endif; ?>
            
            <div class="nav-separator"></div>
            
            <li class="nav-label">Benutzer</li>
            <li class="nav-item">
                <!-- Username is displayed as a non-link item mostly -->
                <div style="padding: 10px 12px; color: var(--text-muted); font-size: 0.9em; display:flex; align-items:center;">
                    <span class="nav-icon">👤</span>
                    <span class="nav-text"><?= htmlspecialchars(get_current_user_name()) ?></span>
                </div>
            </li>
            <li class="nav-item">
                <a href="/logout.php">
                    <span class="nav-icon">🚪</span>
                    <span class="nav-text">Abmelden</span>
                </a>
            </li>
        </ul>
    </nav>
    
    <!-- Main Content Area -->
    <main class="main-content">
<?php else: ?>
    <!-- Full page for login and other public routes -->
    <main class="login-layout" style="display: flex; justify-content: center; align-items: center; min-height: 100vh; background-color: var(--bg-main);">
<?php endif; ?>
