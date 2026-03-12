/**
 * src/js/app.js
 * Basic interactivity for the School Efficiency Tool
 */

document.addEventListener('DOMContentLoaded', () => {
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
