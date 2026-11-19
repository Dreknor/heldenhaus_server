import type { Display } from '@/types';

/**
 * Wendet Darstellung und Barrierefreiheit (§11.4, Phase 8) am <html> an. Die Werte kommen als
 * Shared-Prop `auth.display` vom Server; app.blade.php setzt sie schon beim ersten Laden, damit nichts flackert.
 *
 * - data-theme / .dark: Theme aus config/game.php, dunkle Themes erzwingen den Dunkelmodus
 * - .hh-reduce-motion: Animationen aus (wirkt wie prefers-reduced-motion)
 * - .hh-contrast, .hh-readable, data-cv: hoher Kontrast, lesefreundliche Schrift, Farbsehschwäche
 * - font-size: Textgröße (alle rem-Größen skalieren mit)
 */
export function applyDisplay(display: Display | null | undefined) {
    if (typeof document === 'undefined') return;
    const root = document.documentElement;

    if (!display) {
        delete root.dataset.theme;
        delete root.dataset.themeDark;
        delete root.dataset.cv;
        root.classList.remove('hh-reduce-motion', 'hh-contrast', 'hh-readable');
        root.style.fontSize = '';
        syncDark();
        return;
    }

    root.dataset.theme = display.theme;
    if (display.theme_dark) root.dataset.themeDark = '1';
    else delete root.dataset.themeDark;
    if (display.color_vision && display.color_vision !== 'normal') root.dataset.cv = display.color_vision;
    else delete root.dataset.cv;

    root.classList.toggle('hh-reduce-motion', !!display.reduce_motion);
    root.classList.toggle('hh-contrast', !!display.high_contrast);
    root.classList.toggle('hh-readable', !!display.dyslexia_font);
    root.style.fontSize = display.text_scale && display.text_scale !== 1 ? `${Math.round(display.text_scale * 100)}%` : '';

    syncDark();
}

/** Ist ein dunkles Theme gewählt? Dann gilt der Dunkelmodus unabhängig von hell/dunkel/System. */
export function themeForcesDark(): boolean {
    return typeof document !== 'undefined' && document.documentElement.dataset.themeDark === '1';
}

function syncDark() {
    let saved: string | null = null;
    try {
        saved = localStorage.getItem('appearance');
    } catch {
        // Kein Speicher verfügbar: Systemeinstellung
    }
    const systemDark = window.matchMedia?.('(prefers-color-scheme: dark)').matches ?? false;
    const dark = themeForcesDark() || saved === 'dark' || ((saved === null || saved === 'system') && systemDark);
    document.documentElement.classList.toggle('dark', dark);
}
