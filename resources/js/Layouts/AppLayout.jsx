import { useEffect, useState } from 'react';
import AiIcon from '@/Components/AiIcon';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import {
    Blocks, CheckCircle2, History, KeyRound, LayoutDashboard, LayoutTemplate, LogOut, Menu as MenuIcon, Plus, Settings,
    Workflow, X, XCircle,
} from 'lucide-react';
import { Avatar, Button, Dialog, Field, Input, Textarea } from '@/Components/ui';
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

export function Toaster() {
    const { flash } = usePage().props;
    const [toasts, setToasts] = useState([]);

    const push = (message, tone) => {
        const id = Math.random();
        setToasts((list) => [...list.slice(-3), { id, message, tone }]);
        setTimeout(() => setToasts((list) => list.filter((t) => t.id !== id)), 5000);
    };

    useEffect(() => {
        if (flash?.success) push(flash.success, 'success');
        if (flash?.error) push(flash.error, 'error');
    }, [flash]);

    useEffect(() => {
        const handler = (event) => push(event.detail.message, event.detail.tone);
        window.addEventListener('ff:toast', handler);
        return () => window.removeEventListener('ff:toast', handler);
    }, []);

    return (
        <div className="pointer-events-none fixed right-4 bottom-[calc(5rem+env(safe-area-inset-bottom))] z-[70] flex w-[min(380px,calc(100vw-2rem))] flex-col gap-2 lg:right-5 lg:bottom-5" aria-live="polite">
            {toasts.map((t) => (
                <div key={t.id} className="animate-pop-in pointer-events-auto flex items-start gap-2.5 rounded-xl border border-line bg-surface px-4 py-3 text-[13px] shadow-pop">
                    {t.tone === 'error' ? <XCircle className="mt-px size-4 shrink-0 text-fail" /> : <CheckCircle2 className="mt-px size-4 shrink-0 text-ok" />}
                    <span className="leading-snug">{t.message}</span>
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
        <Link href="/settings?tab=general" className="flex items-start gap-2.5 rounded-xl bg-surface-2 px-3 py-2.5 text-[12.5px] hover:bg-surface-3" title="Scheduler settings">
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
        <nav className="fixed inset-x-0 bottom-0 z-30 flex border-t border-line bg-surface/95 pb-[env(safe-area-inset-bottom)] backdrop-blur lg:hidden" aria-label="Main">
            {tabs.map((tab) => {
                const active = isActive(tab);
                return (
                    <Link key={tab.href} href={tab.href} aria-current={active ? 'page' : undefined}
                        className={cn('flex min-h-14 flex-1 flex-col items-center justify-center gap-1 text-[11px] font-medium transition-colors', active ? 'text-primary' : 'text-fg-muted')}>
                        <span className={cn('flex h-7 w-12 items-center justify-center rounded-full transition-colors', active && 'bg-primary-soft')}><tab.icon className="size-[19px]" strokeWidth={1.9} /></span>
                        {tab.label}
                    </Link>
                );
            })}
            <button type="button" onClick={onMore} className="flex min-h-14 flex-1 flex-col items-center justify-center gap-1 text-[11px] font-medium text-fg-muted">
                <span className="flex h-7 w-12 items-center justify-center rounded-full"><MenuIcon className="size-[19px]" strokeWidth={1.9} /></span>
                More
            </button>
        </nav>
    );
}

export default function AppLayout({ title, description, actions, children, wide = false }) {
    const { auth, scheduler, appCount } = usePage().props;
    const path = usePage().url;
    const [mobileOpen, setMobileOpen] = useState(false);

    const can = auth.user?.can ?? {};
    const nav = [
        { href: '/', label: 'Overview', icon: LayoutDashboard, exact: true },
        can['workflows.view'] && { href: '/workflows', label: 'Workflows', icon: Workflow },
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
                    <span className="text-[16px] font-semibold tracking-tight">FlowForge</span>
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
                            className={cn('group flex items-center gap-3 rounded-lg px-2.5 py-2 text-[13.5px] font-medium transition-colors',
                                active ? 'bg-primary-soft text-primary' : 'text-fg-muted hover:bg-surface-2 hover:text-fg')}>
                            <item.icon className="size-[18px]" strokeWidth={1.9} />
                            <span className="flex-1">{item.label}</span>
                            {item.badge && <span className={cn('rounded-md px-1.5 text-[11px] tabular-nums', active ? 'bg-surface text-primary' : 'bg-surface-2 text-fg-subtle')}>{item.badge}</span>}
                        </Link>
                    );
                })}
            </nav>
            <div className="space-y-2 p-3">
                {can.admin && <SchedulerStatus lastRun={scheduler} />}
                <Link href="/settings" className={cn('flex items-center gap-3 rounded-lg px-2.5 py-2 text-[13.5px] font-medium',
                    path.startsWith('/settings') ? 'bg-primary-soft text-primary' : 'text-fg-muted hover:bg-surface-2 hover:text-fg')}>
                    <Settings className="size-[18px]" strokeWidth={1.9} />Settings
                </Link>
                <div className="flex items-center gap-2.5 border-t border-line px-1 pt-3">
                    <Avatar name={auth.user?.name} />
                    <div className="min-w-0 flex-1">
                        <p className="truncate text-[13px] font-medium">{auth.user?.name}</p>
                        <p className="truncate text-[12px] text-fg-subtle">{auth.user?.email}</p>
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
            {mobileOpen && (
                <>
                    <div className="fixed inset-0 z-40 bg-black/30 lg:hidden" onClick={() => setMobileOpen(false)} aria-hidden />
                    <aside className="animate-slide-in fixed inset-y-0 left-0 z-50 w-72 border-r border-line bg-surface lg:hidden">{sidebar}</aside>
                </>
            )}
            {/* Phones: an app-style top bar and bottom tab bar instead of the sidebar. */}
            <div className="sticky top-0 z-20 flex items-center gap-3 border-b border-line bg-surface/90 px-4 pt-[max(0.625rem,env(safe-area-inset-top))] pb-2.5 backdrop-blur lg:hidden">
                <Link href="/" aria-label="FlowForge home"><Logo className="size-7" /></Link>
                <span className="min-w-0 flex-1 truncate font-semibold">FlowForge</span>
                {can['workflows.edit'] && (
                    <button type="button" onClick={openCreateWorkflow} className="flex size-9 items-center justify-center rounded-xl bg-primary text-primary-fg" aria-label="Create workflow"><Plus className="size-5" /></button>
                )}
            </div>
            <MobileTabBar can={can} isActive={isActive} onMore={() => setMobileOpen(true)} />
            {auth.user?.demo && (
                <div className="border-b border-primary/20 bg-primary-soft px-4 py-2 text-center text-[12.5px] text-fg">
                    <strong>Live demo.</strong> Build and explore freely; it resets every hour. Deleting, credentials, running workflows and settings are turned off.
                </div>
            )}
            <main className={cn('mx-auto px-4 pt-6 pb-[calc(6rem+env(safe-area-inset-bottom))] sm:px-8 lg:py-8', wide ? 'max-w-[1400px]' : 'max-w-6xl', title === null && wide && 'max-w-none')}>
                {(title || actions) && (
                    <header className="mb-6 flex flex-wrap items-end justify-between gap-4 lg:mb-7">
                        <div className="min-w-0">
                            <h1 className="text-[22px] font-semibold tracking-tight sm:text-[26px]">{title}</h1>
                            {description && <p className="mt-1 max-w-2xl text-[14px] text-fg-muted">{description}</p>}
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
