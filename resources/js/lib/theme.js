/** Apply 'light' | 'dark' | 'system' to <html> immediately (the server renders the saved choice on load). */
export function applyTheme(theme) {
    const root = document.documentElement;
    root.dataset.theme = theme;
    const dark = theme === 'dark' || (theme === 'system' && window.matchMedia('(prefers-color-scheme: dark)').matches);
    root.classList.toggle('dark', dark);
    window.dispatchEvent(new CustomEvent('ff:theme', { detail: dark ? 'dark' : 'light' }));
}

export const isDark = () => document.documentElement.classList.contains('dark');
