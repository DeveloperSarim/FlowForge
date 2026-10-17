import { clsx } from 'clsx';
import { extendTailwindMerge } from 'tailwind-merge';

// The app's type scale (text-13, text-12h… in app.css) must count as font sizes, not colours, or merging would drop text colours.
const twMerge = extendTailwindMerge({ extend: { theme: { text: ['10', '10h', '11', '11h', '12', '12h', '13', '13h', '14', '14h', '15', '16', '17', '20', '22', '24', '26'] } } });

export const cn = (...inputs) => twMerge(clsx(inputs));

/** Fire a toast from anywhere; AppLayout renders it. tone: 'success' | 'error' | 'info'; action: {label, onClick}, e.g. Undo. */
export const toast = (message, tone = 'info', { action } = {}) =>
    window.dispatchEvent(new CustomEvent('ff:toast', { detail: { message, tone, action } }));

/** Best human-readable message from an axios error (Laravel validation, abort(), or network). */
export function errorMessage(error) {
    const data = error?.response?.data;
    if (data?.errors) return Object.values(data.errors).flat()[0];
    return data?.message || error?.message || 'Something went wrong.';
}

const rtf = new Intl.RelativeTimeFormat(undefined, { numeric: 'auto' });

export function timeAgo(iso) {
    if (!iso) return 'never';
    const seconds = Math.round((new Date(iso).getTime() - Date.now()) / 1000);
    const units = [['year', 31536000], ['month', 2592000], ['day', 86400], ['hour', 3600], ['minute', 60]];
    for (const [unit, size] of units) {
        if (Math.abs(seconds) >= size) return rtf.format(Math.round(seconds / size), unit);
    }
    return Math.abs(seconds) < 10 ? 'just now' : rtf.format(seconds, 'second');
}

export function duration(ms) {
    if (ms === null || ms === undefined) return '—';
    if (ms < 1000) return `${ms} ms`;
    if (ms < 60000) return `${(ms / 1000).toFixed(ms < 10000 ? 2 : 1)} s`;
    return `${Math.floor(ms / 60000)}m ${Math.round((ms % 60000) / 1000)}s`;
}

export const copy = async (text, label = 'Copied') => {
    try {
        await navigator.clipboard.writeText(text);
        toast(label, 'success');
    } catch {
        toast('Copy failed — select the text and copy it manually.', 'error');
    }
};

export const compact = (n) => new Intl.NumberFormat(undefined, { notation: 'compact', maximumFractionDigits: 1 }).format(n ?? 0);

/** True when the person asked the system for less motion. */
export const reducedMotion = () => window.matchMedia('(prefers-reduced-motion: reduce)').matches;
