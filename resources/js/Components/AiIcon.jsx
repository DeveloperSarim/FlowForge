import { cn } from '@/lib/utils';

/**
 * FlowForge's AI mark: bold "AI" letters with three sparkles. The letters fill y 6–19 of the 24px box
 * (centred like text cap height) and the sparkles sit in the free space above and to the right.
 */
export default function AiIcon({ className, ...props }) {
    return (
        <svg viewBox="0 0 24 24" fill="currentColor" className={cn('size-4 shrink-0', className)} aria-hidden {...props}>
            <path fillRule="evenodd" stroke="currentColor" strokeWidth="0.5" strokeLinejoin="round"
                d="M1 19 5.77 6h2.81l4.77 13h-2.82l-.97-2.82H4.79L3.82 19Zm4.66-5.2h3.03L7.17 9.36Z" />
            <rect x="14.6" y="6" width="2.8" height="13" rx="0.5" />
            <path d="M12.2 0.3c0.31 1.76 0.84 2.29 2.6 2.6c-1.76 0.31-2.29 0.84-2.6 2.6c-0.31-1.76-0.84-2.29-2.6-2.6c1.76-0.31 2.29-0.84 2.6-2.6Z" />
            <path d="M19.4 1.2c0.17 0.95 0.45 1.23 1.4 1.4c-0.95 0.17-1.23 0.45-1.4 1.4c-0.17-0.95-0.45-1.23-1.4-1.4c0.95-0.17 1.23-0.45 1.4-1.4Z" />
            <path d="M21 5.8c0.28 1.62 0.78 2.12 2.4 2.4c-1.62 0.28-2.12 0.78-2.4 2.4c-0.28-1.62-0.78-2.12-2.4-2.4c1.62-0.28 2.12-0.78 2.4-2.4Z" />
        </svg>
    );
}
