import { clsx } from 'clsx';
import { twMerge } from 'tailwind-merge';

export const cn = (...inputs) => twMerge(clsx(inputs));

/** Fire a toast from anywhere; AppLayout renders it. tone: 'success' | 'error' | 'info' */
export const toast = (message, tone = 'info') =>
    window.dispatchEvent(new CustomEvent('ff:toast', { detail: { message, tone } }));

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
