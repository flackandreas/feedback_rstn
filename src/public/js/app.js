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

    // Seitenleiste. Am Desktop klappt der Knopf sie auf Symbole ein. Auf
    // schmalen Bildschirmen ist sie eine Schublade ueber dem Inhalt: die
    // Kopfleiste oeffnet sie, der Knopf in ihr, ein Tipp daneben und Escape
    // schliessen sie. Die Grenze steht auch in app_styles.css.
    const navToggle = document.getElementById('nav-toggle');
    const appLayout = document.getElementById('appLayout');
    const navOeffnen = document.getElementById('nav-oeffnen');
    const navAbdeckung = document.getElementById('nav-abdeckung');
    const schmal = window.matchMedia('(max-width: 768px)');

    function navSchublade(offen, fokus = true) {
        appLayout.classList.toggle('nav-offen', offen);
        document.body.classList.toggle('nav-gesperrt', offen);
        if (navOeffnen) {
            navOeffnen.setAttribute('aria-expanded', offen ? 'true' : 'false');
        }
        if (fokus) {
            (offen ? navToggle : navOeffnen)?.focus();
        }
    }

    function navBeschriften() {
        navToggle.setAttribute('aria-label', schmal.matches ? 'Menü schließen' : 'Menü ein- oder ausklappen');
    }

    if (navToggle && appLayout) {
        navBeschriften();
        navToggle.addEventListener('click', () => {
            if (schmal.matches) {
                navSchublade(false);
            } else {
                appLayout.classList.toggle('collapsed');
            }
        });
        navOeffnen?.addEventListener('click', () => navSchublade(true));
        navAbdeckung?.addEventListener('click', () => navSchublade(false));
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && appLayout.classList.contains('nav-offen')) {
                navSchublade(false);
            }
        });
        // Wird das Fenster breiter (Tablet gedreht), gibt es keine Schublade mehr.
        schmal.addEventListener('change', () => {
            navBeschriften();
            if (!schmal.matches) {
                navSchublade(false, false);
            }
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
