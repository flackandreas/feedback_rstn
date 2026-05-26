/**
 * src/js/app.js
 * Basic interactivity for the School Efficiency Tool
 */

// Theme initialization (runs immediately to prevent flash of light mode)
const savedTheme = localStorage.getItem('theme');
if (savedTheme === 'dark' || (!savedTheme && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
    document.body.classList.add('dark-mode');
}

document.addEventListener('DOMContentLoaded', () => {
    // Dark Mode Toggle Logic
    const darkModeBtn = document.getElementById('dark-mode-toggle');
    if (darkModeBtn) {
        darkModeBtn.addEventListener('click', () => {
            const isDark = document.body.classList.toggle('dark-mode');
            localStorage.setItem('theme', isDark ? 'dark' : 'light');
        });
    }

    // Mobile Sidebar Toggle
    const navToggle = document.getElementById('nav-toggle');
    const appLayout = document.getElementById('appLayout');

    if (navToggle && appLayout) {
        navToggle.addEventListener('click', () => {
            appLayout.classList.toggle('collapsed');
        });
    }

    // Auto-hide status messages after 5 seconds
    const statusMessages = document.querySelectorAll('.status.success');
    if (statusMessages.length > 0) {
        setTimeout(() => {
            statusMessages.forEach(msg => {
                msg.style.transition = 'opacity 0.5s ease';
                msg.style.opacity = '0';
                setTimeout(() => msg.remove(), 500);
            });
        }, 5000);
    }
});
