import { cn } from '@/lib/utils';

/** FlowForge's AI mark: bold "AI" letters with three sparkles. Sized like a Lucide icon via className. */
export default function AiIcon({ className, ...props }) {
    return (
        <svg viewBox="0 0 24 24" fill="currentColor" className={cn('size-4 shrink-0', className)} aria-hidden {...props}>
            <path fillRule="evenodd" stroke="currentColor" strokeWidth="0.5" strokeLinejoin="round"
                d="M1.6 21 6 9h2.6L13 21h-2.6l-.9-2.6H5.1L4.2 21Zm4.3-4.8h2.8L7.3 12.1Z" />
            <rect x="14.4" y="9" width="2.6" height="12" rx="0.45" />
            <path d="M11.4 1.2c.4 2.3 1.1 3 3.4 3.4-2.3.4-3 1.1-3.4 3.4-.4-2.3-1.1-3-3.4-3.4 2.3-.4 3-1.1 3.4-3.4Z" />
            <path d="M18.6.7c.2 1.1.6 1.5 1.7 1.7-1.1.2-1.5.6-1.7 1.7-.2-1.1-.6-1.5-1.7-1.7 1.1-.2 1.5-.6 1.7-1.7Z" />
            <path d="M20.6 6.4c.3 1.6.8 2.1 2.4 2.4-1.6.3-2.1.8-2.4 2.4-.3-1.6-.8-2.1-2.4-2.4 1.6-.3 2.1-.8 2.4-2.4Z" />
        </svg>
    );
}
