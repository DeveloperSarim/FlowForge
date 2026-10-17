import { useMemo, useState } from 'react';
import { ChevronRight, Copy } from 'lucide-react';
import { cn, copy } from '@/lib/utils';

const TYPE = (v) => (v === null ? 'null' : Array.isArray(v) ? 'array' : typeof v);
const TYPE_MARK = { string: 'T', number: '#', boolean: '◐', null: '∅', array: '[ ]', object: '{ }' };

/** JSON with light syntax colouring (React text nodes, never innerHTML). */
export function JsonText({ value }) {
    const text = useMemo(() => (typeof value === 'string' ? value : JSON.stringify(value, null, 2)), [value]);
    const parts = useMemo(() => {
        const out = [];
        let last = 0;
        for (const m of text.matchAll(/("(?:\\.|[^"\\])*")(\s*:)?|\b(true|false|null)\b|(-?\d+(?:\.\d+)?(?:[eE][+-]?\d+)?)/g)) {
            if (m.index > last) out.push(text.slice(last, m.index));
            const [whole, str, colon, lit, num] = m;
            out.push(<span key={m.index} className={str ? (colon ? 'text-info' : 'text-ok') : lit ? 'text-[#a855f7]' : num ? 'text-warn' : ''}>{str ?? whole}</span>);
            if (colon) out.push(colon);
            last = m.index + whole.length;
        }
        out.push(text.slice(last));
        return out;
    }, [text]);
    return <pre className="font-mono text-12 leading-relaxed whitespace-pre-wrap break-all text-fg-muted">{parts}</pre>;
}

function SchemaNode({ name, value, path, base, depth, onPick }) {
    const type = TYPE(value);
    const container = type === 'array' || type === 'object';
    const [open, setOpen] = useState(depth < 2);
    const entries = container ? (type === 'array' ? (value.length ? [[0, value[0]]] : []) : Object.entries(value)) : [];
    const token = base ? `{{ ${base}${path ? `.${path}` : ''} }}` : null;

    return (
        <li>
            <div
                draggable={!!token}
                onDragStart={(e) => { e.dataTransfer.setData('text/plain', token); e.dataTransfer.effectAllowed = 'copy'; }}
                onClick={() => (container ? setOpen(!open) : onPick?.(token))}
                title={token ? `Drag into a field, or click to insert ${token}` : undefined}
                className={cn('group flex cursor-pointer items-center gap-2 rounded-md py-[3px] pr-2 hover:bg-surface-2', token && 'active:cursor-grabbing')}
                style={{ paddingLeft: depth * 14 + 4 }}
            >
                {container ? <ChevronRight className={cn('size-3 shrink-0 text-fg-subtle transition-transform', open && 'rotate-90')} /> : <span className="w-3" />}
                <span className="w-6 shrink-0 text-center font-mono text-10 text-fg-subtle">{TYPE_MARK[type]}</span>
                <span className="shrink-0 rounded bg-surface-2 px-1.5 py-px font-mono text-11h font-medium text-fg group-hover:bg-primary-soft group-hover:text-primary">{name}</span>
                {!container && <span className="truncate text-12 text-fg-muted">{value === null ? 'null' : String(value)}</span>}
                {type === 'array' && <span className="text-11h text-fg-subtle">{value.length} item{value.length === 1 ? '' : 's'}</span>}
                {onPick && token && !container && <span className="ml-auto hidden text-11 text-primary group-hover:inline">insert</span>}
            </div>
            {container && open && entries.length > 0 && (
                <ul>{entries.map(([k, v]) => <SchemaNode key={k} name={type === 'array' ? `[${k}]` : k} value={v} path={path ? `${path}.${k}` : String(k)} base={base} depth={depth + 1} onPick={onPick} />)}</ul>
            )}
        </li>
    );
}

/**
 * Schema / JSON view of step data. With `base` (e.g. "steps.step_1"), fields are draggable into inputs
 * and clickable (onPick) to insert {{ base.path }}.
 */
export default function DataView({ value, base, onPick, empty = 'No data.', className, defaultMode = 'schema' }) {
    const [mode, setMode] = useState(defaultMode);

    if (value === undefined || value === null || (typeof value === 'object' && Object.keys(value).length === 0 && !Array.isArray(value))) {
        return <p className={cn('text-13 text-fg-subtle', className)}>{empty}</p>;
    }
    if (value && value._truncated) {
        return (
            <div className={className}>
                <p className="mb-2 text-12 text-warn">Too large to store in full ({Math.round(value.bytes / 1024)} KB). Showing the beginning.</p>
                <JsonText value={value.preview} />
            </div>
        );
    }

    const scalar = typeof value !== 'object';

    return (
        <div className={className}>
            <div className="mb-2 flex items-center gap-1">
                {!scalar && ['schema', 'json'].map((m) => (
                    <button key={m} type="button" onClick={() => setMode(m)} aria-pressed={mode === m}
                        className={cn('rounded-md px-2 py-0.5 text-12 font-medium capitalize', mode === m ? 'bg-surface-3 text-fg' : 'text-fg-subtle hover:text-fg')}>{m === 'json' ? 'JSON' : 'Schema'}</button>
                ))}
                <button type="button" onClick={() => copy(typeof value === 'string' ? value : JSON.stringify(value, null, 2), 'Copied')} className="ml-auto rounded p-1 text-fg-subtle hover:text-fg" aria-label="Copy data">
                    <Copy className="size-3.5" />
                </button>
            </div>
            {scalar || mode === 'json'
                ? <JsonText value={value} />
                : <ul className="-ml-1">{(Array.isArray(value) ? (value.length ? [[0, value[0]]] : []) : Object.entries(value)).map(([k, v]) => (
                    <SchemaNode key={k} name={Array.isArray(value) ? `[${k}]` : k} value={v} path={String(k)} base={base} depth={0} onPick={onPick} />
                ))}</ul>}
        </div>
    );
}
