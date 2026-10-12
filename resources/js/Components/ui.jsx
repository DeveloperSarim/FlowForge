// shadcn/ui-style primitives (variant/size props, forwardRef, cn merging) on the FlowForge tokens.
import { forwardRef, useEffect, useId, useMemo, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import { Check, ChevronDown, Loader2, Search, X } from 'lucide-react';
import { cn } from '@/lib/utils';

const BUTTON_VARIANTS = {
    primary: 'bg-primary text-primary-fg hover:bg-primary-hover shadow-[0_1px_2px_rgb(0_0_0/0.08)] font-medium',
    secondary: 'bg-surface text-fg border border-line hover:bg-surface-2 hover:border-line-strong shadow-card font-medium',
    ghost: 'text-fg-muted hover:text-fg hover:bg-surface-2',
    subtle: 'bg-surface-2 text-fg hover:bg-surface-3 font-medium',
    danger: 'bg-fail-soft text-fail hover:bg-fail hover:text-white font-medium',
    outline: 'border border-line text-fg hover:border-primary hover:text-primary font-medium',
};
const BUTTON_SIZES = {
    xs: 'h-7 px-2 text-[12.5px] gap-1 rounded-md',
    sm: 'h-8 px-3 text-[13px] gap-1.5 rounded-lg',
    md: 'h-9 px-3.5 text-sm gap-2 rounded-lg',
    lg: 'h-11 px-5 text-[15px] gap-2 rounded-xl',
    icon: 'size-8 rounded-lg justify-center',
};

export const Button = forwardRef(function Button({ variant = 'secondary', size = 'md', loading = false, className, children, disabled, ...props }, ref) {
    return (
        <button
            ref={ref}
            type="button"
            disabled={disabled || loading}
            className={cn('inline-flex shrink-0 items-center whitespace-nowrap transition-colors disabled:pointer-events-none disabled:opacity-50',
                BUTTON_VARIANTS[variant], BUTTON_SIZES[size], className)}
            {...props}
        >
            {loading && <Loader2 className="size-4 animate-spin" aria-hidden />}
            {children}
        </button>
    );
});

const fieldBase = 'w-full rounded-lg border border-line bg-surface px-3 text-sm text-fg placeholder:text-fg-subtle transition-[border-color,box-shadow] hover:border-line-strong focus:border-primary focus:outline-none focus:ring-[3px] focus:ring-primary/15 disabled:opacity-60 aria-[invalid=true]:border-fail';

export const Input = forwardRef(function Input({ className, ...props }, ref) {
    return <input ref={ref} className={cn(fieldBase, 'h-9', className)} {...props} />;
});

export const Textarea = forwardRef(function Textarea({ className, mono, ...props }, ref) {
    return <textarea ref={ref} className={cn(fieldBase, 'min-h-20 py-2 leading-relaxed', mono && 'font-mono text-[12.5px]', className)} {...props} />;
});

export const Select = forwardRef(function Select({ className, children, ...props }, ref) {
    return (
        <select ref={ref} className={cn(fieldBase, 'h-9 cursor-pointer appearance-none bg-[length:16px] bg-[right_10px_center] bg-no-repeat pr-9', className)}
            style={{ backgroundImage: "url(\"data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%238a919d' stroke-width='2'%3E%3Cpath d='m6 9 6 6 6-6'/%3E%3C/svg%3E\")" }}
            {...props}>
            {children}
        </select>
    );
});

/**
 * Searchable select. options: [{value, label, group?, icon?, hint?, keywords?}]; `pinned` values are listed
 * first under "Popular" until the user types. Rendered in a portal so scrolling panes don't clip it.
 */
export function Combobox({ id, value, onChange, options, pinned = [], placeholder = 'Select…', searchPlaceholder = 'Search…', emptyText = 'Nothing matches', className, limit = 150 }) {
    const [open, setOpen] = useState(false);
    const [query, setQuery] = useState('');
    const [active, setActive] = useState(0);
    const [rect, setRect] = useState(null);
    const button = useRef(null);
    const panel = useRef(null);
    const list = useRef(null);
    const selected = options.find((o) => o.value === value);

    useEffect(() => {
        if (!open) return undefined;
        const place = () => setRect(button.current?.getBoundingClientRect() ?? null);
        const outside = (e) => !button.current?.contains(e.target) && !panel.current?.contains(e.target) && setOpen(false);
        place();
        window.addEventListener('resize', place);
        window.addEventListener('scroll', place, true);
        document.addEventListener('mousedown', outside);
        return () => {
            window.removeEventListener('resize', place);
            window.removeEventListener('scroll', place, true);
            document.removeEventListener('mousedown', outside);
        };
    }, [open]);

    const items = useMemo(() => {
        const q = query.trim().toLowerCase();
        if (!q) {
            const pins = pinned.map((v) => options.find((o) => o.value === v)).filter(Boolean).map((o) => ({ ...o, hint: o.hint ?? o.group, group: 'Popular' }));
            return [...pins, ...options.filter((o) => !pinned.includes(o.value))];
        }
        const words = q.split(/\s+/);
        return options
            .map((o) => ({ o, text: `${o.label} ${o.group ?? ''} ${o.keywords ?? ''}`.toLowerCase() }))
            .filter(({ text }) => words.every((w) => text.includes(w)))
            .sort((a, b) => Number(!(a.o.group ?? a.o.label).toLowerCase().startsWith(q)) - Number(!(b.o.group ?? b.o.label).toLowerCase().startsWith(q)))
            .map(({ o }) => o);
    }, [query, options, pinned]);
    const shown = items.slice(0, limit);

    useEffect(() => { setActive(0); }, [query]);
    useEffect(() => { list.current?.querySelector(`[data-index="${active}"]`)?.scrollIntoView({ block: 'nearest' }); }, [active]);

    const choose = (option) => { onChange(option.value, option); setOpen(false); setQuery(''); button.current?.focus(); };
    const onKeyDown = (e) => {
        if (e.key === 'ArrowDown') { e.preventDefault(); setActive((i) => Math.min(i + 1, shown.length - 1)); }
        else if (e.key === 'ArrowUp') { e.preventDefault(); setActive((i) => Math.max(i - 1, 0)); }
        else if (e.key === 'Enter') { e.preventDefault(); if (shown[active]) choose(shown[active]); }
        else if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); setOpen(false); button.current?.focus(); }
    };

    const width = Math.max(rect?.width ?? 0, 300);
    const below = !rect || window.innerHeight - rect.bottom > 360 || rect.top < 360;
    const style = rect && {
        left: Math.max(8, Math.min(rect.left, window.innerWidth - width - 8)),
        width,
        ...(below ? { top: rect.bottom + 6 } : { bottom: window.innerHeight - rect.top + 6 }),
    };

    return (
        <>
            <button ref={button} id={id} type="button" aria-haspopup="listbox" aria-expanded={open} onClick={() => setOpen((o) => !o)}
                onKeyDown={(e) => { if (e.key === 'ArrowDown' && !open) { e.preventDefault(); setOpen(true); } }}
                className={cn(fieldBase, 'flex h-9 items-center gap-2 text-left', className)}>
                {selected?.icon && <span className="shrink-0">{selected.icon}</span>}
                <span className={cn('min-w-0 flex-1 truncate', !selected && 'text-fg-subtle')}>{selected ? selected.label : placeholder}</span>
                {selected?.group && <span className="hidden shrink-0 truncate text-[12px] text-fg-subtle sm:inline">{selected.group}</span>}
                <ChevronDown className="size-4 shrink-0 text-fg-subtle" />
            </button>
            {open && rect && createPortal(
                <div ref={panel} style={style} className="animate-pop-in fixed z-[80] flex max-h-[360px] flex-col overflow-hidden rounded-xl border border-line bg-surface shadow-pop">
                    <div className="relative border-b border-line p-2">
                        <Search className="pointer-events-none absolute top-1/2 left-4.5 size-4 -translate-y-1/2 text-fg-subtle" />
                        <input autoFocus value={query} onChange={(e) => setQuery(e.target.value)} onKeyDown={onKeyDown} placeholder={searchPlaceholder}
                            role="combobox" aria-expanded aria-controls={`${id}-list`} aria-activedescendant={shown[active] ? `${id}-opt-${active}` : undefined}
                            className="h-9 w-full rounded-lg bg-surface-2 pr-3 pl-9 text-[13.5px] outline-none placeholder:text-fg-subtle focus:ring-[3px] focus:ring-primary/15" />
                    </div>
                    <div ref={list} id={`${id}-list`} role="listbox" className="min-h-0 flex-1 overflow-y-auto overscroll-contain p-1.5">
                        {shown.length === 0 && <p className="px-3 py-6 text-center text-[13px] text-fg-muted">{emptyText}</p>}
                        {shown.map((o, i) => (
                            <div key={`${o.group}-${o.value}`}>
                                {o.group && o.group !== shown[i - 1]?.group && (
                                    <p className={cn('px-2.5 pt-2 pb-1 text-[11.5px] font-semibold text-fg-subtle', i > 0 && 'mt-1 border-t border-line pt-2.5')}>{o.group}</p>
                                )}
                                <button type="button" role="option" id={`${id}-opt-${i}`} data-index={i} aria-selected={o.value === value}
                                    onMouseEnter={() => setActive(i)} onClick={() => choose(o)}
                                    className={cn('flex w-full items-center gap-2.5 rounded-lg px-2.5 py-2 text-left text-[13.5px]', i === active ? 'bg-surface-2' : '')}>
                                    {o.icon && <span className="shrink-0">{o.icon}</span>}
                                    <span className="min-w-0 flex-1 truncate">{o.label}</span>
                                    {o.hint && <span className="shrink-0 truncate text-[12px] text-fg-subtle">{o.hint}</span>}
                                    {o.value === value && <Check className="size-4 shrink-0 text-primary" />}
                                </button>
                            </div>
                        ))}
                        {items.length > shown.length && <p className="px-3 py-2 text-center text-[12px] text-fg-subtle">{items.length - shown.length} more: keep typing to narrow down.</p>}
                    </div>
                </div>,
                document.body,
            )}
        </>
    );
}

export function SearchInput({ className, ...props }) {
    return (
        <div className={cn('relative', className)}>
            <Search className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-fg-subtle" />
            <Input type="search" className="pl-9" {...props} />
        </div>
    );
}

export function Label({ className, ...props }) {
    return <label className={cn('text-[13px] font-medium text-fg', className)} {...props} />;
}

/** Label + control + help/error, wired with ids for accessibility. */
export function Field({ label, required, help, error, children, className, htmlFor, aside }) {
    const auto = useId();
    const id = htmlFor ?? auto;
    return (
        <div className={cn('space-y-1.5', className)}>
            {(label || aside) && (
                <div className="flex items-center justify-between gap-2">
                    {label && (
                        <Label htmlFor={id}>
                            {label}
                            {required && <span className="ml-0.5 text-primary" aria-hidden>*</span>}
                        </Label>
                    )}
                    {aside}
                </div>
            )}
            {typeof children === 'function' ? children(id) : children}
            {error ? <p className="text-[12.5px] text-fail">{error}</p> : help ? <p className="text-[12.5px] leading-snug text-fg-subtle">{help}</p> : null}
        </div>
    );
}

export function Switch({ checked, onChange, label, disabled, size = 'md' }) {
    return (
        <button
            type="button"
            role="switch"
            aria-checked={checked}
            aria-label={label}
            disabled={disabled}
            onClick={(e) => { e.stopPropagation(); onChange(!checked); }}
            className={cn('relative inline-flex shrink-0 items-center rounded-full transition-colors disabled:opacity-50',
                size === 'sm' ? 'h-5 w-9' : 'h-6 w-11', checked ? 'bg-ok' : 'bg-surface-3')}
        >
            <span className={cn('inline-block rounded-full bg-white shadow-sm transition-transform',
                size === 'sm' ? 'size-4' : 'size-5', checked ? (size === 'sm' ? 'translate-x-[18px]' : 'translate-x-[22px]') : 'translate-x-0.5')} />
        </button>
    );
}

const BADGE_TONES = {
    neutral: 'bg-surface-2 text-fg-muted',
    primary: 'bg-primary-soft text-primary',
    ok: 'bg-ok-soft text-ok',
    fail: 'bg-fail-soft text-fail',
    warn: 'bg-warn-soft text-warn',
    info: 'bg-info-soft text-info',
};

export function Badge({ tone = 'neutral', className, ...props }) {
    return <span className={cn('inline-flex items-center gap-1 rounded-md px-1.5 py-0.5 text-xs font-medium whitespace-nowrap', BADGE_TONES[tone], className)} {...props} />;
}

const STATUS = {
    completed: { tone: 'ok', label: 'Succeeded' },
    success: { tone: 'ok', label: 'Succeeded' },
    failed: { tone: 'fail', label: 'Failed' },
    running: { tone: 'info', label: 'Running' },
    pending: { tone: 'warn', label: 'Queued' },
    skipped: { tone: 'neutral', label: 'Skipped' },
};

export function StatusBadge({ status, className }) {
    const meta = STATUS[status] ?? { tone: 'neutral', label: status };
    return (
        <Badge tone={meta.tone} className={className}>
            <span className={cn('size-1.5 rounded-full bg-current', status === 'running' && 'animate-pulse')} aria-hidden />
            {meta.label}
        </Badge>
    );
}

export function Card({ className, ...props }) {
    return <div className={cn('rounded-xl border border-line bg-surface shadow-card', className)} {...props} />;
}

/** Segmented tabs: [{value, label, count?}] */
export function Tabs({ value, onChange, items, className }) {
    return (
        <div role="tablist" className={cn('inline-flex rounded-lg bg-surface-2 p-0.5', className)}>
            {items.map((item) => (
                <button key={item.value} type="button" role="tab" aria-selected={value === item.value} onClick={() => onChange(item.value)}
                    className={cn('inline-flex items-center gap-1.5 rounded-md px-3 py-1 text-[13px] font-medium transition-colors',
                        value === item.value ? 'bg-surface text-fg shadow-card' : 'text-fg-muted hover:text-fg')}>
                    {item.icon && <item.icon className="size-3.5" />}
                    {item.label}
                    {item.count !== undefined && <span className="text-fg-subtle tabular-nums">{item.count}</span>}
                </button>
            ))}
        </div>
    );
}

/** Freezes the page behind an open dialog so the wheel never scrolls it (or the dialog with it). */
function useScrollLock(open) {
    useEffect(() => {
        if (!open) return undefined;
        const root = document.documentElement;
        const previous = root.style.overflow;
        root.style.overflow = 'hidden';
        return () => { root.style.overflow = previous; };
    }, [open]);
}

function useEscape(open, onClose) {
    useEffect(() => {
        if (!open) return;
        const handler = (event) => event.key === 'Escape' && onClose();
        window.addEventListener('keydown', handler);
        return () => window.removeEventListener('keydown', handler);
    }, [open, onClose]);
}

/** Small dropdown menu: trigger is any element; items [{label, icon, onClick, danger}] */
export function Menu({ trigger, items, align = 'right' }) {
    const [open, setOpen] = useState(false);
    const ref = useRef(null);
    useEscape(open, () => setOpen(false));
    useEffect(() => {
        if (!open) return;
        const close = (e) => ref.current && !ref.current.contains(e.target) && setOpen(false);
        document.addEventListener('mousedown', close);
        return () => document.removeEventListener('mousedown', close);
    }, [open]);

    return (
        <div ref={ref} className="relative" onClick={(e) => e.stopPropagation()}>
            <div onClick={() => setOpen((o) => !o)}>{trigger}</div>
            {open && (
                <div role="menu" className={cn('animate-pop-in absolute top-full z-50 mt-1 min-w-44 rounded-xl border border-line bg-surface p-1 shadow-pop', align === 'right' ? 'right-0' : 'left-0')}>
                    {items.filter(Boolean).map((item) => (
                        <button key={item.label} type="button" role="menuitem" onClick={() => { setOpen(false); item.onClick(); }}
                            className={cn('flex w-full items-center gap-2 rounded-lg px-2.5 py-1.5 text-left text-[13px]', item.danger ? 'text-fail hover:bg-fail-soft' : 'text-fg hover:bg-surface-2')}>
                            {item.icon && <item.icon className="size-4 opacity-70" />}
                            {item.label}
                        </button>
                    ))}
                </div>
            )}
        </div>
    );
}

/** Centered modal. */
export function Dialog({ open, onClose, title, description, children, className, wide }) {
    useEscape(open, onClose);
    useScrollLock(open);
    if (!open) return null;
    // The overlay never scrolls; a tall dialog scrolls inside its body instead.
    return (
        <div className="fixed inset-0 z-50 flex items-start justify-center overflow-hidden overscroll-none bg-black/40 px-4 py-[6vh] backdrop-blur-[2px]" onMouseDown={(e) => e.target === e.currentTarget && onClose()}>
            <div role="dialog" aria-modal="true" aria-label={title} className={cn('animate-pop-in flex max-h-full w-full flex-col rounded-2xl border border-line bg-surface shadow-pop', wide ? 'max-w-3xl' : 'max-w-lg', className)}>
                <div className="flex shrink-0 items-start justify-between gap-4 px-6 pt-5 pb-3">
                    <div>
                        <h2 className="text-[17px] font-semibold tracking-tight">{title}</h2>
                        {description && <p className="mt-0.5 text-[13px] text-fg-muted">{description}</p>}
                    </div>
                    <Button variant="ghost" size="icon" onClick={onClose} aria-label="Close"><X className="size-4" /></Button>
                </div>
                <div className="min-h-0 overflow-y-auto overscroll-contain px-6 pb-6">{children}</div>
            </div>
        </div>
    );
}

/** Right-hand slide-over panel (shadcn "Sheet"). */
export function Sheet({ open, onClose, children, className, overlay = true }) {
    useEscape(open, onClose);
    useScrollLock(open && overlay);
    if (!open) return null;
    return (
        <>
            {overlay && <div className="fixed inset-0 z-40 bg-black/30" onClick={onClose} aria-hidden />}
            <aside role="dialog" aria-modal={overlay} className={cn('animate-slide-in fixed inset-y-0 right-0 z-50 flex w-full max-w-[min(100vw,900px)] flex-col border-l border-line bg-surface shadow-pop', className)}>
                {children}
            </aside>
        </>
    );
}

export function EmptyState({ icon: Icon, title, children, action, className }) {
    return (
        <div className={cn('flex flex-col items-center justify-center px-6 py-14 text-center', className)}>
            {Icon && (
                <span className="mb-4 flex size-12 items-center justify-center rounded-2xl bg-primary-soft text-primary">
                    <Icon className="size-6" strokeWidth={1.75} />
                </span>
            )}
            <p className="text-[15px] font-semibold">{title}</p>
            {children && <p className="mt-1 max-w-sm text-[13px] text-fg-muted">{children}</p>}
            {action && <div className="mt-5">{action}</div>}
        </div>
    );
}

/** Two-step destructive button: no browser confirm() dialogs. */
export function ConfirmButton({ onConfirm, children, confirmLabel = 'Click again to confirm', size = 'sm', ...props }) {
    const [armed, setArmed] = useState(false);
    useEffect(() => {
        if (!armed) return;
        const timer = setTimeout(() => setArmed(false), 4000);
        return () => clearTimeout(timer);
    }, [armed]);
    return (
        <Button variant={armed ? 'danger' : 'ghost'} size={size} onClick={() => (armed ? onConfirm() : setArmed(true))} onBlur={() => setArmed(false)} {...props}>
            {armed ? confirmLabel : children}
        </Button>
    );
}

export function Avatar({ name, className }) {
    const initials = (name ?? '?').split(/\s+/).map((p) => p[0]).slice(0, 2).join('').toUpperCase();
    return <span className={cn('inline-flex size-8 shrink-0 items-center justify-center rounded-full bg-primary-soft text-[12px] font-semibold text-primary', className)}>{initials}</span>;
}

export function Kbd({ children }) {
    return <kbd className="rounded border border-line bg-surface-2 px-1.5 py-px font-mono text-[11px] text-fg-muted">{children}</kbd>;
}
