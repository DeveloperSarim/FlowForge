import { useEffect, useMemo, useRef, useState } from 'react';
import { ArrowLeft, Blocks, ChevronRight, X, Zap } from 'lucide-react';
import IntegrationIcon from '@/Components/IntegrationIcon';
import { CATEGORIES, categoryMeta, CORE_CATEGORIES } from '@/lib/categories';
import { cn } from '@/lib/utils';
import { plainText } from '@/Components/Markdown';

function Row({ icon, title, subtitle, onClick, chevron = false, leading }) {
    return (
        <button type="button" onClick={onClick} className="group flex w-full items-center gap-3 rounded-lg px-3 py-2.5 text-left transition-colors hover:bg-surface-2">
            {leading ?? <IntegrationIcon icon={icon} name={title} size="md" />}
            <span className="min-w-0 flex-1">
                <span className="block truncate text-13h font-medium">{title}</span>
                {subtitle && <span className="block truncate text-12 text-fg-subtle">{plainText(subtitle)}</span>}
            </span>
            {chevron && <ChevronRight className="size-4 text-fg-subtle group-hover:text-fg" />}
        </button>
    );
}

/**
 * Right-hand "What happens next?" panel: search every app and action, or drill down
 * categories → app → action. In trigger mode it lists triggers instead.
 */
export default function NodeCreator({ catalog, mode = 'action', onPick, onClose }) {
    const [query, setQuery] = useState('');
    const [view, setView] = useState({ type: 'home' }); // home | category | app
    const input = useRef(null);
    useEffect(() => input.current?.focus(), [view]);
    useEffect(() => {
        const handler = (e) => e.key === 'Escape' && onClose();
        window.addEventListener('keydown', handler);
        return () => window.removeEventListener('keydown', handler);
    }, [onClose]);

    const key = mode === 'trigger' ? 'triggers' : 'actions';
    const usable = useMemo(() => catalog.filter((app) => app[key].length > 0), [catalog, key]);

    const byCategory = useMemo(() => usable.reduce((acc, app) => ((acc[app.category] ??= []).push(app), acc), {}), [usable]);
    const pick = (app, op) => onPick(mode === 'trigger' ? { integration: app.key, trigger: op.key } : { integration: app.key, action: op.key, branch: op.branch });
    const openApp = (app) => (app[key].length === 1 ? pick(app, app[key][0]) : setView({ type: 'app', app, back: view }));

    const q = query.trim().toLowerCase();
    const results = useMemo(() => {
        if (!q) return null;
        const apps = usable.filter((a) => a.name.toLowerCase().includes(q) || a.category.toLowerCase().includes(q)).slice(0, 40);
        const ops = usable.flatMap((a) => a[key].filter((op) => op.name.toLowerCase().includes(q)).map((op) => ({ app: a, op }))).slice(0, 40);
        return { apps, ops };
    }, [q, usable, key]);

    const coreApps = usable.filter((a) => CORE_CATEGORIES.includes(a.category));
    const appCategories = CATEGORIES.filter((c) => !CORE_CATEGORIES.includes(c.name) && byCategory[c.name]?.length);

    return (
        <aside className="animate-slide-in absolute inset-y-0 right-0 z-30 flex w-[min(400px,100%)] flex-col border-l border-line bg-surface shadow-pop" aria-label={mode === 'trigger' ? 'Choose a trigger' : 'Add a step'}>
            <div className="border-b border-line px-4 pt-4 pb-3">
                <div className="mb-3 flex items-center gap-2">
                    {view.type !== 'home' && !q && (
                        <button type="button" onClick={() => setView(view.back ?? { type: 'home' })} className="rounded-md p-1 text-fg-muted hover:bg-surface-2 hover:text-fg" aria-label="Back"><ArrowLeft className="size-4" /></button>
                    )}
                    <h2 className="flex-1 text-16 font-semibold tracking-tight">
                        {view.type === 'app' && !q ? view.app.name : view.type === 'category' && !q ? view.category : mode === 'trigger' ? 'What starts this workflow?' : 'What happens next?'}
                    </h2>
                    <button type="button" onClick={onClose} className="rounded-md p-1 text-fg-muted hover:bg-surface-2 hover:text-fg" aria-label="Close"><X className="size-4" /></button>
                </div>
                <input ref={input} value={query} onChange={(e) => setQuery(e.target.value)} placeholder={`Search ${usable.length} apps and ${mode === 'trigger' ? 'triggers' : 'actions'}…`}
                    className="h-10 w-full rounded-lg border border-line bg-surface-2 px-3 text-base outline-none sm:text-sm placeholder:text-fg-subtle focus:border-primary focus:bg-surface focus:ring-[3px] focus:ring-primary/15" aria-label="Search" />
            </div>

            <div className="flex-1 overflow-y-auto p-2">
                {results ? (
                    <>
                        {results.apps.length > 0 && <p className="px-3 pt-2 pb-1 text-12 font-medium text-fg-subtle">Apps</p>}
                        {results.apps.map((app) => <Row key={app.key} icon={app.icon} title={app.name} subtitle={app.category} chevron={app[key].length > 1} onClick={() => { setQuery(''); openApp(app); }} />)}
                        {results.ops.length > 0 && <p className="px-3 pt-3 pb-1 text-12 font-medium text-fg-subtle">{mode === 'trigger' ? 'Triggers' : 'Actions'}</p>}
                        {results.ops.map(({ app, op }) => <Row key={`${app.key}.${op.key}`} icon={app.icon} title={op.name} subtitle={app.name} onClick={() => pick(app, op)} />)}
                        {results.apps.length + results.ops.length === 0 && <p className="px-3 py-8 text-center text-13 text-fg-muted">Nothing matches "{query}". Try the HTTP Request node for any API.</p>}
                    </>
                ) : view.type === 'app' ? (
                    <>
                        <p className="px-3 pt-2 pb-1 text-12 font-medium text-fg-subtle">{mode === 'trigger' ? 'Triggers' : 'Actions'}</p>
                        {view.app[key].map((op) => (
                            <Row key={op.key} title={op.name} onClick={() => pick(view.app, op)}
                                leading={<span className="flex size-9 shrink-0 items-center justify-center rounded-lg bg-surface-2">{mode === 'trigger' ? <Zap className="size-4 text-warn" /> : <IntegrationIcon icon={view.app.icon} name={view.app.name} bare className="[&_img]:size-5" />}</span>} />
                        ))}
                    </>
                ) : view.type === 'category' ? (
                    byCategory[view.category].map((app) => <Row key={app.key} icon={app.icon} title={app.name} subtitle={app.description} chevron={app[key].length > 1} onClick={() => openApp(app)} />)
                ) : (
                    <>
                        {coreApps.length > 0 && (
                            <>
                                <p className="px-3 pt-2 pb-1 text-12 font-medium text-fg-subtle">{mode === 'trigger' ? 'Built-in triggers' : 'Built-in nodes'}</p>
                                {coreApps.map((app) => <Row key={app.key} icon={app.icon} title={app.name} subtitle={app.description} chevron={app[key].length > 1} onClick={() => openApp(app)} />)}
                            </>
                        )}
                        <p className="px-3 pt-4 pb-1 text-12 font-medium text-fg-subtle">{mode === 'trigger' ? 'App triggers' : 'Action in an app'}</p>
                        {appCategories.map((c) => (
                            <Row key={c.name} title={c.name} subtitle={`${byCategory[c.name].length} app${byCategory[c.name].length === 1 ? '' : 's'}`} chevron onClick={() => setView({ type: 'category', category: c.name })}
                                leading={<span className="flex size-9 shrink-0 items-center justify-center rounded-lg" style={{ background: `${c.color}1f`, color: c.color }}><c.icon className="size-[18px]" /></span>} />
                        ))}
                        {appCategories.length === 0 && (
                            <p className="flex items-center gap-2 px-3 py-4 text-13 text-fg-muted"><Blocks className="size-4" />No app {mode === 'trigger' ? 'triggers' : 'actions'} installed.</p>
                        )}
                    </>
                )}
            </div>
        </aside>
    );
}

export { categoryMeta };
