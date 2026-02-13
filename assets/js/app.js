/**
 * ComeCome - Main Application JavaScript
 * ADHD-Friendly interactions
 */

// Register service worker for PWA
if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => {
        navigator.serviceWorker.register('/sw.js')
            .then(reg => console.log('Service Worker registered'))
            .catch(err => console.log('Service Worker registration failed', err));
    });
}

// Theme switcher (respects system preference)
function initTheme() {
    if (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches) {
        document.documentElement.setAttribute('data-theme', 'dark');
    }

    // Listen for changes
    window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', e => {
        document.documentElement.setAttribute('data-theme', e.matches ? 'dark' : 'light');
    });
}

// Enhanced touch interactions for mobile
function initTouchInteractions() {
    // Prevent double-tap zoom on buttons
    document.querySelectorAll('button, .btn-primary, .btn-secondary').forEach(btn => {
        btn.addEventListener('touchend', function(e) {
            e.preventDefault();
            this.click();
        });
    });
}

// Dialog polyfill for older browsers
function initDialogs() {
    if (!window.HTMLDialogElement) {
        console.warn('Dialog element not supported');
    }
}

// Auto-save forms (for check-ins)
function initAutoSave() {
    const forms = document.querySelectorAll('[data-autosave]');
    forms.forEach(form => {
        const inputs = form.querySelectorAll('input, textarea, select');
        inputs.forEach(input => {
            input.addEventListener('change', () => {
                const formData = new FormData(form);
                const key = 'autosave_' + form.id;
                const data = Object.fromEntries(formData);
                localStorage.setItem(key, JSON.stringify(data));
            });
        });

        // Restore on load
        const key = 'autosave_' + form.id;
        const saved = localStorage.getItem(key);
        if (saved) {
            const data = JSON.parse(saved);
            Object.keys(data).forEach(name => {
                const input = form.querySelector(`[name="${name}"]`);
                if (input) input.value = data[name];
            });
        }
    });
}

// Vibration feedback for important actions
function vibrate(pattern = 50) {
    if ('vibrate' in navigator) {
        navigator.vibrate(pattern);
    }
}

// Add haptic feedback to food selection
document.addEventListener('DOMContentLoaded', () => {
    initTheme();
    initTouchInteractions();
    initDialogs();
    initAutoSave();

    // Add success vibration to food logging
    document.querySelectorAll('.portion-btn').forEach(btn => {
        btn.addEventListener('click', () => vibrate([50, 100, 50]));
    });
});

// Offline detection
window.addEventListener('online', () => {
    console.log('Back online');
});

window.addEventListener('offline', () => {
    console.log('Offline mode');
});
