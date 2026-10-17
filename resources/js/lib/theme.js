/**
 * Apply 'light' | 'dark' | 'system' to <html> (the server renders the saved choice on load). With `animate`, the
 * switch cross-fades instead of flashing from one brightness to the other (skipped for reduced motion).
 */
export function applyTheme(theme, { animate = false } = {}) {
    const root = document.documentElement;
    const apply = () => {
        root.dataset.theme = theme;
        const dark = theme === 'dark' || (theme === 'system' && window.matchMedia('(prefers-color-scheme: dark)').matches);
        root.classList.toggle('dark', dark);
        window.dispatchEvent(new CustomEvent('ff:theme', { detail: dark ? 'dark' : 'light' }));
    };
    const calm = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    if (animate && !calm && document.startViewTransition) document.startViewTransition(apply);
    else apply();
}

export const isDark = () => document.documentElement.classList.contains('dark');
