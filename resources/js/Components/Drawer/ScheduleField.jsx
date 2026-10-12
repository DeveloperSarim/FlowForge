import { useEffect, useState } from 'react';
import { usePage } from '@inertiajs/react';
import { CalendarClock } from 'lucide-react';
import { Input, Select } from '@/Components/ui';
import { cn } from '@/lib/utils';

const DAYS = [['1', 'Mon'], ['2', 'Tue'], ['3', 'Wed'], ['4', 'Thu'], ['5', 'Fri'], ['6', 'Sat'], ['0', 'Sun']];
const MODES = [
    ['minutes', 'Every few minutes'], ['hours', 'Every few hours'], ['daily', 'Every day'], ['weekdays', 'Every weekday (Mon–Fri)'],
    ['weekly', 'Every week'], ['monthly', 'Every month'], ['custom', 'Custom (cron expression)'],
];
const num = (v) => /^\d+$/.test(v);
const pad = (n) => String(n).padStart(2, '0');

/** Reads a cron string back into the friendly form; anything it can't express stays "custom". */
export function parseCron(cron) {
    const parts = String(cron ?? '').trim().split(/\s+/);
    if (parts.length !== 5) return { mode: 'custom' };
    const [m, h, dom, mon, dow] = parts;
    const time = num(m) && num(h) ? `${pad(h)}:${pad(m)}` : null;
    if (mon !== '*') return { mode: 'custom' };
    if (h === '*' && dom === '*' && dow === '*') {
        if (m === '*') return { mode: 'minutes', every: 1 };
        if (/^\*\/\d+$/.test(m)) return { mode: 'minutes', every: Number(m.slice(2)) };
        if (num(m)) return { mode: 'hours', every: 1, minute: Number(m) };
    }
    if (num(m) && /^\*\/\d+$/.test(h) && dom === '*' && dow === '*') return { mode: 'hours', every: Number(h.slice(2)), minute: Number(m) };
    if (!time) return { mode: 'custom' };
    if (dom === '*' && dow === '*') return { mode: 'daily', time };
    if (dom === '*' && dow === '1-5') return { mode: 'weekdays', time };
    if (dom === '*' && /^[\d,-]+$/.test(dow)) {
        const days = dow.split(',').flatMap((part) => {
            const [a, b] = part.split('-').map(Number);
            return b === undefined ? [a % 7] : Array.from({ length: b - a + 1 }, (_, i) => (a + i) % 7);
        });
        return { mode: 'weekly', time, days: [...new Set(days.map(String))] };
    }
    if (num(dom) && dow === '*') return { mode: 'monthly', time, day: Number(dom) };
    return { mode: 'custom' };
}

export function buildCron(s) {
    const [h, m] = (s.time ?? '09:00').split(':').map(Number);
    switch (s.mode) {
        case 'minutes': return s.every > 1 ? `*/${s.every} * * * *` : '* * * * *';
        case 'hours': return `${s.minute ?? 0} ${s.every > 1 ? `*/${s.every}` : '*'} * * *`;
        case 'daily': return `${m} ${h} * * *`;
        case 'weekdays': return `${m} ${h} * * 1-5`;
        case 'weekly': return `${m} ${h} * * ${(s.days?.length ? s.days : ['1']).slice().sort().join(',')}`;
        case 'monthly': return `${m} ${h} ${s.day ?? 1} * *`;
        default: return s.cron ?? '';
    }
}

function describe(s, cron) {
    const at = s.time ? ` at ${s.time}` : '';
    switch (s.mode) {
        case 'minutes': return s.every > 1 ? `Every ${s.every} minutes` : 'Every minute';
        case 'hours': return `${s.every > 1 ? `Every ${s.every} hours` : 'Every hour'} at minute ${s.minute ?? 0}`;
        case 'daily': return `Every day${at}`;
        case 'weekdays': return `Monday to Friday${at}`;
        case 'weekly': return `Every ${DAYS.filter(([v]) => s.days?.includes(v)).map(([, l]) => l).join(', ') || 'Mon'}${at}`;
        case 'monthly': return `Day ${s.day ?? 1} of every month${at}`;
        default: return cron ? `Cron: ${cron}` : 'Enter a cron expression';
    }
}

/** Friendly schedule editor that stores a standard cron expression. */
export default function ScheduleField({ id, value, fallback, onChange }) {
    const { timezone } = usePage().props;
    const cron = value ?? fallback ?? '0 9 * * *';
    const [state, setState] = useState(() => ({ time: '09:00', every: 15, minute: 0, day: 1, days: ['1'], ...parseCron(cron), cron }));
    const [preview, setPreview] = useState(null);

    // Persist the default so a never-touched schedule still has a cron to activate with.
    useEffect(() => { if (value === undefined || value === null || value === '') onChange(cron); }, []); // eslint-disable-line react-hooks/exhaustive-deps

    useEffect(() => {
        const timer = setTimeout(() => {
            fetch(`/schedule/preview?cron=${encodeURIComponent(cron)}`, { headers: { Accept: 'application/json' } })
                .then((r) => r.json()).then(setPreview).catch(() => setPreview(null));
        }, 300);
        return () => clearTimeout(timer);
    }, [cron]);

    const update = (patch) => {
        const next = { ...state, ...patch };
        if (patch.mode && patch.mode !== 'custom') next.cron = buildCron(next);
        if (patch.mode === 'custom') next.cron = cron;
        setState(next);
        onChange(next.mode === 'custom' ? next.cron : buildCron(next));
    };

    const fmt = (iso) => new Intl.DateTimeFormat(undefined, { timeZone: preview?.timezone, weekday: 'short', day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' }).format(new Date(iso));
    const timeInput = <Input type="time" value={state.time} onChange={(e) => e.target.value && update({ time: e.target.value })} className="w-32" aria-label="Time" />;

    return (
        <div className="space-y-3">
            <Select id={id} value={state.mode} onChange={(e) => update({ mode: e.target.value })}>
                {MODES.map(([v, l]) => <option key={v} value={v}>{l}</option>)}
            </Select>

            {state.mode === 'minutes' && (
                <label className="flex items-center gap-2 text-[13px] text-fg-muted">Every
                    <Select value={state.every} onChange={(e) => update({ every: Number(e.target.value) })} className="w-24">
                        {[1, 2, 5, 10, 15, 20, 30].map((n) => <option key={n} value={n}>{n}</option>)}
                    </Select>minute{state.every > 1 && 's'}
                </label>
            )}
            {state.mode === 'hours' && (
                <div className="flex flex-wrap items-center gap-2 text-[13px] text-fg-muted">Every
                    <Select value={state.every} onChange={(e) => update({ every: Number(e.target.value) })} className="w-20" aria-label="Hours">
                        {[1, 2, 3, 4, 6, 8, 12].map((n) => <option key={n} value={n}>{n}</option>)}
                    </Select>hour{state.every > 1 && 's'}, at minute
                    <Select value={state.minute} onChange={(e) => update({ minute: Number(e.target.value) })} className="w-20" aria-label="Minute">
                        {[0, 5, 10, 15, 20, 30, 45].map((n) => <option key={n} value={n}>{pad(n)}</option>)}
                    </Select>
                </div>
            )}
            {['daily', 'weekdays'].includes(state.mode) && <label className="flex items-center gap-2 text-[13px] text-fg-muted">At {timeInput}</label>}
            {state.mode === 'weekly' && (
                <div className="space-y-2.5">
                    <div className="flex flex-wrap gap-1.5" role="group" aria-label="Days of the week">
                        {DAYS.map(([v, l]) => {
                            const on = state.days?.includes(v);
                            return (
                                <button key={v} type="button" aria-pressed={on} onClick={() => update({ days: on ? state.days.filter((d) => d !== v) : [...(state.days ?? []), v] })}
                                    className={cn('h-8 w-11 rounded-lg border text-[12.5px] font-medium transition-colors', on ? 'border-primary bg-primary-soft text-primary' : 'border-line text-fg-muted hover:border-line-strong')}>
                                    {l}
                                </button>
                            );
                        })}
                    </div>
                    <label className="flex items-center gap-2 text-[13px] text-fg-muted">At {timeInput}</label>
                </div>
            )}
            {state.mode === 'monthly' && (
                <div className="flex flex-wrap items-center gap-2 text-[13px] text-fg-muted">On day
                    <Select value={state.day} onChange={(e) => update({ day: Number(e.target.value) })} className="w-20" aria-label="Day of month">
                        {Array.from({ length: 28 }, (_, i) => i + 1).map((n) => <option key={n} value={n}>{n}</option>)}
                    </Select>at {timeInput}
                </div>
            )}
            {state.mode === 'custom' && (
                <div className="space-y-1.5">
                    <Input value={state.cron} onChange={(e) => { setState({ ...state, cron: e.target.value }); onChange(e.target.value); }} placeholder="0 9 * * 1-5" className="font-mono text-[13px]" aria-label="Cron expression" />
                    <p className="text-[12px] text-fg-subtle">minute hour day-of-month month day-of-week, e.g. <code>30 8 * * 1-5</code> = 08:30 on weekdays.</p>
                </div>
            )}

            <div className={cn('rounded-lg border px-3 py-2.5 text-[12.5px]', preview && !preview.valid ? 'border-fail/30 bg-fail-soft text-fail' : 'border-line bg-surface-2/60')}>
                <p className="flex items-center gap-2 font-medium text-fg"><CalendarClock className="size-4 text-primary" />{describe(state, cron)}</p>
                {preview?.valid === false ? <p className="mt-1">That cron expression isn't valid.</p> : preview?.next?.length > 0 && (
                    <p className="mt-1 text-fg-muted">Next: {preview.next.map(fmt).join(', ')}</p>
                )}
                <p className="mt-1 text-fg-subtle">Times are in {timezone ?? 'UTC'}. Change it in Settings, General.</p>
            </div>
        </div>
    );
}
