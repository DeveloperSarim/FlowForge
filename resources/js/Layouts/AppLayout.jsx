import { useEffect, useRef, useState } from 'react';
import AiIcon from '@/Components/AiIcon';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import {
    Blocks, CheckCircle2, History, Info, KeyRound, LayoutDashboard, LayoutTemplate, LogOut, Menu as MenuIcon, Plus, Settings,
    Workflow, X, XCircle, PhoneCall,
} from 'lucide-react';
import { Avatar, BottomSheet, Button, Dialog, Field, Input, Textarea, project, rubberband } from '@/Components/ui';
import { cn, compact, timeAgo } from '@/lib/utils';

export function Logo({ className }) {
    return (
        <svg viewBox="0 0 32 32" className={cn('size-8', className)} aria-hidden>
            <rect width="32" height="32" rx="9" fill="var(--primary)" />
            <path d="M9 22V10h10M9 16h7" stroke="white" strokeWidth="3.2" strokeLinecap="round" strokeLinejoin="round" fill="none" />
            <circle cx="23" cy="21" r="3" fill="white" />
        </svg>
    );
}

const TOAST_ICONS = { error: [XCircle, 'text-fail'], success: [CheckCircle2, 'text-ok'], info: [Info, 'text-info'] };

/**
 * One toast. It slides in from the right edge and leaves the same way, also when swiped: it follows the finger
 * once a drag passes 6px, and a flick or a drag past a third of its width throws it out. Hovering or touching it
 * pauses its timer; errors and toasts with an action (Undo) stay longer.
 */
function ToastItem({ toast: t, onDismiss }) {
    const [paused, setPaused] = useState(false);
    const card = useRef(null);
    const drag = useRef(null);
    const [Icon, tint] = TOAST_ICONS[t.tone] ?? TOAST_ICONS.success;

    useEffect(() => {
        if (paused || t.closing) return undefined;
        const timer = setTimeout(() => onDismiss(t.id), t.tone === 'error' ? 10000 : t.action ? 8000 : 5000);
        return () => clearTimeout(timer);
    }, [paused, t.closing]);

    const move = (x) => { card.current.style.transform = `translateX(${x}px)`; };
    const onPointerDown = (e) => {
        if (e.target.closest('button')) return;
        drag.current = { x0: e.clientX, x: 0, live: false, samples: [[e.timeStamp, 0]] };
        setPaused(true);
    };
    const onPointerMove = (e) => {
        const d = drag.current;
        if (!d) return;
        const dx = e.clientX - d.x0;
        if (!d.live) {
            if (Math.abs(dx) < 6) return;
            d.live = true;
            card.current.setPointerCapture(e.pointerId);
            card.current.style.animation = 'none';
            card.current.style.transition = 'none';
        }
        d.x = dx < 0 ? rubberband(dx, card.current.offsetWidth) : dx; // it only goes right
        d.samples = [...d.samples.slice(-4), [e.timeStamp, d.x]];
        move(d.x);
    };
    const onPointerUp = (e) => {
        const d = drag.current;
        drag.current = null;
        setPaused(false);
        if (!d?.live) return;
        const [t0, x0] = d.samples[0];
        const [t1, x1] = d.samples[d.samples.length - 1];
        const velocity = t1 > t0 && e.timeStamp - t1 < 60 ? ((x1 - x0) / (t1 - t0)) * 1000 : 0; // stopped before letting go: none
        card.current.style.animation = '';
        if (d.x + project(velocity) > card.current.offsetWidth / 3) return onDismiss(t.id); // the exit continues from here
        card.current.style.transition = 'transform 380ms var(--ease-sheet)';
        move(0);
    };

    return (
        <div ref={card} data-closing={t.closing ? '' : undefined} onMouseEnter={() => setPaused(true)} onMouseLeave={() => setPaused(false)}
            onPointerDown={onPointerDown} onPointerMove={onPointerMove} onPointerUp={onPointerUp} onPointerCancel={onPointerUp}
            className="ff-toast pointer-events-auto flex touch-pan-y items-start gap-2.5 rounded-xl border border-line bg-surface py-3 pr-2 pl-4 text-13 shadow-pop select-none">
            <Icon className={cn('mt-px size-4 shrink-0', tint)} />
            <span className="flex-1 leading-snug">{t.message}</span>
            {t.action && (
                <button type="button" onClick={() => { t.action.onClick(); onDismiss(t.id); }}
                    className="ff-press -my-0.5 shrink-0 rounded-md px-2 py-0.5 font-semibold text-primary transition-[background-color,scale] hover:bg-primary-soft motion-safe:active:scale-95">
                    {t.action.label}
                </button>
            )}
            <button type="button" onClick={() => onDismiss(t.id)} aria-label="Dismiss" className="-my-0.5 shrink-0 rounded-md p-1 text-fg-subtle hover:bg-surface-2 hover:text-fg">
                <X className="size-3.5" />
            </button>
        </div>
    );
}

export function Toaster() {
    const { flash } = usePage().props;
    const [toasts, setToasts] = useState([]);

    const push = (message, tone, action) => setToasts((list) => [...list.slice(-3), { id: Math.random(), message, tone, action }]);
    // Leaving: the toast slides out, then its row collapses so the others glide into place.
    const dismiss = (id) => {
        setToasts((list) => list.map((t) => (t.id === id ? { ...t, closing: true } : t)));
        setTimeout(() => setToasts((list) => list.filter((t) => t.id !== id)), 380);
    };

    useEffect(() => {
        if (flash?.success) push(flash.success, 'success');
        if (flash?.error) push(flash.error, 'error');
    }, [flash]);

    useEffect(() => {
        const handler = (event) => push(event.detail.message, event.detail.tone, event.detail.action);
        window.addEventListener('ff:toast', handler);
        return () => window.removeEventListener('ff:toast', handler);
    }, []);

    return (
        <div className="pointer-events-none fixed right-4 bottom-[calc(4.5rem+env(safe-area-inset-bottom))] z-[70] flex w-[min(380px,calc(100vw-2rem))] flex-col lg:right-5 lg:bottom-3" aria-live="polite">
            {toasts.map((t) => (
                <div key={t.id} data-closing={t.closing ? '' : undefined} className="ff-collapse">
                    <div className="pt-2"><ToastItem toast={t} onDismiss={dismiss} /></div>
                </div>
            ))}
        </div>
    );
}

/** Opened from the sidebar's "Create workflow" button (and by pages via the ff:create-workflow event). */
function CreateWorkflowDialog() {
    const [open, setOpen] = useState(false);
    const form = useForm({ name: '', description: '' });

    useEffect(() => {
        const handler = () => setOpen(true);
        window.addEventListener('ff:create-workflow', handler);
        return () => window.removeEventListener('ff:create-workflow', handler);
    }, []);

    const close = () => { setOpen(false); form.reset(); form.clearErrors(); };

    return (
        <Dialog open={open} onClose={close} title="Create workflow" description="It starts with a manual trigger. Change it on the canvas any time.">
            <form onSubmit={(e) => { e.preventDefault(); form.post('/workflows'); }} className="space-y-4">
                <Field label="Name" required error={form.errors.name}>
                    {(id) => <Input id={id} autoFocus placeholder="New Shopify orders to Slack" value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} />}
                </Field>
                <Field label="Description" error={form.errors.description}>
                    {(id) => <Textarea id={id} rows={2} placeholder="Optional" value={form.data.description} onChange={(e) => form.setData('description', e.target.value)} />}
                </Field>
                <div className="flex justify-end gap-2 pt-1">
                    <Button onClick={close}>Cancel</Button>
                    <Button type="submit" variant="primary" loading={form.processing}>Create and open</Button>
                </div>
            </form>
        </Dialog>
    );
}

export const openCreateWorkflow = () => window.dispatchEvent(new CustomEvent('ff:create-workflow'));

function SchedulerStatus({ lastRun }) {
    const healthy = lastRun && (Date.now() - new Date(lastRun).getTime()) / 60000 < 3;
    return (
        <Link href="/settings/general" className="flex items-start gap-2.5 rounded-xl bg-surface-2 px-3 py-2.5 text-12h hover:bg-surface-3" title="Scheduler settings">
            <span className={cn('mt-1.5 size-2 shrink-0 rounded-full', healthy ? 'bg-ok shadow-[0_0_0_3px_var(--ok-soft)]' : 'bg-warn shadow-[0_0_0_3px_var(--warn-soft)]')} aria-hidden />
            <div className="min-w-0">
                <p className="font-medium">{healthy ? 'Scheduler running' : 'Scheduler not running'}</p>
                <p className="truncate text-fg-subtle">{lastRun ? `Last tick ${timeAgo(lastRun)}` : 'Add the cron job to run workflows'}</p>
            </div>
        </Link>
    );
}

/** Bottom tab bar on phones: the main destinations one thumb away, "More" opens the full menu. */
function MobileTabBar({ can, isActive, onMore }) {
    const tabs = [
        { href: '/', label: 'Home', icon: LayoutDashboard, exact: true },
        can['workflows.view'] && { href: '/workflows', label: 'Workflows', icon: Workflow },
        can.ai && { href: '/assistant', label: 'AI', icon: AiIcon },
        can['workflows.view'] && { href: '/executions', label: 'Runs', icon: History },
    ].filter(Boolean);
    return (
        <nav className="ff-glass fixed inset-x-0 bottom-0 z-30 flex border-t border-line/70 pb-[env(safe-area-inset-bottom)] lg:hidden" aria-label="Main">
            {tabs.map((tab) => {
                const active = isActive(tab);
                return (
                    <Link key={tab.href} href={tab.href} aria-current={active ? 'page' : undefined}
                        className={cn('ff-press group flex min-h-14 flex-1 flex-col items-center justify-center gap-1 text-11 font-medium transition-colors', active ? 'text-primary' : 'text-fg-muted')}>
                        <span className={cn('flex h-7 w-12 items-center justify-center rounded-full transition-[background-color,scale] duration-150 group-active:duration-75 motion-safe:group-active:scale-90 group-active:bg-surface-3', active && 'bg-primary-soft')}><tab.icon className="size-[19px]" strokeWidth={1.9} /></span>
                        {tab.label}
                    </Link>
                );
            })}
            <button type="button" onClick={onMore} className="ff-press group flex min-h-14 flex-1 flex-col items-center justify-center gap-1 text-11 font-medium text-fg-muted">
                <span className="flex h-7 w-12 items-center justify-center rounded-full transition-[background-color,scale] duration-150 group-active:duration-75 motion-safe:group-active:scale-90 group-active:bg-surface-3"><MenuIcon className="size-[19px]" strokeWidth={1.9} /></span>
                More
            </button>
        </nav>
    );
}

export default function AppLayout({ title, description, actions, children, wide = false }) {
    const { auth, scheduler, appCount, updateAvailable } = usePage().props;
    const path = usePage().url;
    const [mobileOpen, setMobileOpen] = useState(false);
    // The top bar's divider only shows once content scrolls under it (a scroll edge, not a permanent line).
    const [scrolled, setScrolled] = useState(false);
    useEffect(() => {
        const onScroll = () => setScrolled(window.scrollY > 2);
        onScroll();
        window.addEventListener('scroll', onScroll, { passive: true });
        return () => window.removeEventListener('scroll', onScroll);
    }, []);

    const can = auth.user?.can ?? {};
    const nav = [
        { href: '/', label: 'Overview', icon: LayoutDashboard, exact: true },
        can['workflows.view'] && { href: '/workflows', label: 'Workflows', icon: Workflow },
        can['workflows.view'] && { href: '/voice', label: 'Voice agents', icon: PhoneCall },
        can.ai && { href: '/assistant', label: 'AI Builder', icon: AiIcon },
        can['workflows.view'] && { href: '/templates', label: 'Templates', icon: LayoutTemplate },
        can['workflows.view'] && { href: '/executions', label: 'Executions', icon: History },
        can['workflows.edit'] && { href: '/credentials', label: 'Credentials', icon: KeyRound },
        { href: '/apps', label: 'Apps', icon: Blocks, badge: appCount ? compact(appCount) : null },
    ].filter(Boolean);
    const isActive = (item) => (item.exact ? path === item.href || path.startsWith('/?') : path.startsWith(item.href));

    const sidebar = (
        <div className="flex h-full flex-col">
            <div className="flex items-center justify-between px-4 pt-4 pb-5">
                <Link href="/" className="flex items-center gap-2.5">
                    <Logo />
                    <span className="text-16 font-semibold tracking-tight">FlowForge</span>
                </Link>
                <button type="button" className="rounded-lg p-1.5 text-fg-muted hover:bg-surface-2 lg:hidden" onClick={() => setMobileOpen(false)} aria-label="Close menu"><X className="size-4" /></button>
            </div>
            {can['workflows.edit'] && (
                <div className="px-3">
                    <Button variant="primary" className="w-full justify-center" onClick={() => { setMobileOpen(false); openCreateWorkflow(); }}>
                        <Plus className="size-4" />Create workflow
                    </Button>
                </div>
            )}
            <nav className="mt-5 flex-1 space-y-0.5 px-3" aria-label="Main">
                {nav.map((item) => {
                    const active = isActive(item);
                    return (
                        <Link key={item.href} href={item.href} aria-current={active ? 'page' : undefined}
                            className={cn('group flex items-center gap-3 rounded-lg px-2.5 py-2 text-13h font-medium transition-colors',
                                active ? 'bg-primary-soft text-primary' : 'text-fg-muted hover:bg-surface-2 hover:text-fg active:bg-surface-3')}>
                            <item.icon className="size-[18px]" strokeWidth={1.9} />
                            <span className="flex-1">{item.label}</span>
                            {item.badge && <span className={cn('rounded-md px-1.5 text-11 tabular-nums', active ? 'bg-surface text-primary' : 'bg-surface-2 text-fg-subtle')}>{item.badge}</span>}
                        </Link>
                    );
                })}
            </nav>
            <div className="space-y-2 p-3">
                {can.admin && <SchedulerStatus lastRun={scheduler} />}
                <Link href={updateAvailable ? '/settings/updates' : '/settings'} className={cn('flex items-center gap-3 rounded-lg px-2.5 py-2 text-13h font-medium',
                    path.startsWith('/settings') ? 'bg-primary-soft text-primary' : 'text-fg-muted hover:bg-surface-2 hover:text-fg')}>
                    <Settings className="size-[18px]" strokeWidth={1.9} />Settings
                    {updateAvailable && <span className="ml-auto rounded-md bg-primary px-1.5 py-px text-11 font-semibold text-primary-fg" title={`Version ${updateAvailable} is available`}>Update</span>}
                </Link>
                <div className="flex items-center gap-2.5 border-t border-line px-1 pt-3">
                    <Avatar name={auth.user?.name} />
                    <div className="min-w-0 flex-1">
                        <p className="truncate text-13 font-medium">{auth.user?.name}</p>
                        <p className="truncate text-12 text-fg-subtle">{auth.user?.email}</p>
                    </div>
                    <button type="button" onClick={() => router.post('/logout')} className="rounded-lg p-1.5 text-fg-subtle hover:bg-surface-2 hover:text-fg" aria-label="Sign out" title="Sign out">
                        <LogOut className="size-4" />
                    </button>
                </div>
            </div>
        </div>
    );

    return (
        <div className="min-h-screen lg:pl-64">
            <Head title={title} />
            <aside className="fixed inset-y-0 left-0 z-30 hidden w-64 border-r border-line bg-surface lg:block">{sidebar}</aside>
            {/* Phones: "More" in the tab bar raises the full menu as a sheet from the bottom edge, where it was tapped. */}
            <BottomSheet open={mobileOpen} onClose={() => setMobileOpen(false)} label="Menu" className="lg:hidden">{sidebar}</BottomSheet>
            {/* Phones: an app-style top bar and bottom tab bar instead of the sidebar. */}
            <div className={cn('ff-glass sticky top-0 z-20 flex items-center gap-3 border-b px-4 pt-[max(0.625rem,env(safe-area-inset-top))] pb-2.5 transition-colors lg:hidden', scrolled ? 'border-line/70' : 'border-transparent')}>
                <Link href="/" aria-label="FlowForge home"><Logo className="size-7" /></Link>
                <span className="min-w-0 flex-1 truncate font-semibold">FlowForge</span>
                {can['workflows.edit'] && (
                    <button type="button" onClick={openCreateWorkflow} className="ff-press flex size-9 items-center justify-center rounded-xl bg-primary text-primary-fg transition-[scale,background-color] active:bg-primary-hover motion-safe:active:scale-90" aria-label="Create workflow"><Plus className="size-5" /></button>
                )}
            </div>
            <MobileTabBar can={can} isActive={isActive} onMore={() => setMobileOpen(true)} />
            {auth.user?.demo && (
                <div className="border-b border-primary/20 bg-primary-soft px-4 py-2 text-center text-12h text-fg">
                    <strong>Live demo.</strong> Build and explore freely; it resets every hour. Deleting, credentials, running workflows and settings are turned off.
                </div>
            )}
            <main className={cn('mx-auto px-4 pt-6 pb-[calc(6rem+env(safe-area-inset-bottom))] sm:px-8 lg:py-8', wide ? 'max-w-[1400px]' : 'max-w-6xl', title === null && wide && 'max-w-none')}>
                {(title || actions) && (
                    <header className="mb-6 flex flex-wrap items-end justify-between gap-4 lg:mb-7">
                        <div className="min-w-0">
                            <h1 className="text-22 font-semibold tracking-tight sm:text-26">{title}</h1>
                            {description && <p className="mt-1 max-w-2xl text-14 text-fg-muted">{description}</p>}
                        </div>
                        {actions && <div className="flex flex-wrap items-center gap-2">{actions}</div>}
                    </header>
                )}
                {children}
            </main>
            <CreateWorkflowDialog />
            <Toaster />
        </div>
    );
}
