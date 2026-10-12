import { useRef, useState } from 'react';
import { ArrowUpCircle, Check, FileArchive, Loader2, TriangleAlert, UploadCloud, X } from 'lucide-react';
import { Button, Card } from '@/Components/ui';
import { cn, errorMessage, timeAgo } from '@/lib/utils';

const CHUNK = 1.5 * 1024 * 1024; // stays under the smallest common upload_max_filesize (2 MB)
const STEPS = [
    ['extract', 'Unpacking the new version'],
    ['swap', 'Switching to it (the old version is kept as a backup)'],
    ['finish', 'Updating the database'],
];

/** Settings → Updates: upload flowforge-update-x.y.z.zip and the app updates itself. */
export default function UpdatePanel({ updates }) {
    const input = useRef(null);
    const [state, setState] = useState({ phase: 'idle' });
    const [dragging, setDragging] = useState(false);

    const upload = async (file) => {
        if (!file) return;
        if (!/\.zip$/i.test(file.name)) return setState({ phase: 'error', message: 'Choose the flowforge-update-….zip file.' });
        const id = `${Date.now().toString(36)}${Math.random().toString(36).slice(2, 10)}`;
        const total = Math.max(1, Math.ceil(file.size / CHUNK));
        try {
            for (let i = 0; i < total; i++) {
                setState({ phase: 'uploading', name: file.name, percent: Math.round((i / total) * 100) });
                const form = new FormData();
                form.append('upload_id', id);
                form.append('index', i);
                form.append('total', total);
                form.append('offset', i * CHUNK);
                form.append('chunk', file.slice(i * CHUNK, (i + 1) * CHUNK), 'chunk.bin');
                const { data } = await window.axios.post('/settings/updates/upload', form);
                if (data.package) setState({ phase: 'ready', name: file.name, pkg: data.package });
            }
        } catch (error) {
            setState({ phase: 'error', message: errorMessage(error) });
        }
    };

    const install = async () => {
        const pkg = state.pkg;
        const progress = (step, extra = {}) => setState({ phase: 'installing', pkg, step, ...extra });
        try {
            let from = 0;
            let token;
            for (;;) {
                const { data } = await window.axios.post('/settings/updates/extract', { from });
                progress('extract', { percent: Math.round((data.next / data.total) * 100) });
                if (data.done) { token = data.token; break; }
                from = data.next;
            }
            progress('swap');
            const response = await fetch(updates.runner, { method: 'POST', body: new URLSearchParams({ token }), credentials: 'same-origin' });
            const body = await response.json().catch(() => ({}));
            if (!response.ok) throw new Error(body.message ?? `Switching failed (HTTP ${response.status}).`);
            progress('finish');
            await window.axios.post('/settings/updates/finish');
            setState({ phase: 'done', pkg });
            setTimeout(() => window.location.reload(), 2000);
        } catch (error) {
            setState({ phase: 'error', message: errorMessage(error), pkg });
        }
    };

    const reset = () => { setState({ phase: 'idle' }); if (input.current) input.current.value = ''; };
    const stepIndex = STEPS.findIndex(([key]) => key === state.step);

    return (
        <div className="space-y-6">
            <Card className="p-6">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <h2 className="text-[16px] font-semibold">Updates</h2>
                        <p className="mt-0.5 max-w-xl text-[13px] text-fg-muted">Upload a <code className="text-[12px]">flowforge-update-x.y.z.zip</code> and FlowForge updates itself. Your settings, credentials, workflows and run history stay as they are.</p>
                    </div>
                    <span className="rounded-lg bg-surface-2 px-3 py-1.5 text-[13px]">Installed version <strong className="tabular-nums">{updates.version}</strong></span>
                </div>

                {['idle', 'uploading', 'error'].includes(state.phase) && !(state.phase === 'error' && state.pkg) && (
                    <label
                        onDragOver={(e) => { e.preventDefault(); setDragging(true); }}
                        onDragLeave={() => setDragging(false)}
                        onDrop={(e) => { e.preventDefault(); setDragging(false); upload(e.dataTransfer.files[0]); }}
                        className={cn('mt-5 flex cursor-pointer flex-col items-center justify-center rounded-xl border-2 border-dashed px-6 py-9 text-center transition-colors',
                            dragging ? 'border-primary bg-primary-soft/60' : 'border-line hover:border-line-strong', state.phase === 'uploading' && 'pointer-events-none')}>
                        <input ref={input} type="file" accept=".zip,application/zip" className="sr-only" onChange={(e) => upload(e.target.files[0])} />
                        {state.phase === 'uploading' ? (
                            <>
                                <Loader2 className="size-7 animate-spin text-primary" />
                                <p className="mt-3 text-[14px] font-medium">Uploading {state.name}…</p>
                                <div className="mt-3 h-2 w-64 overflow-hidden rounded-full bg-surface-2"><div className="h-full rounded-full bg-primary transition-[width]" style={{ width: `${state.percent}%` }} /></div>
                                <p className="mt-1.5 text-[12px] text-fg-subtle tabular-nums">{state.percent}%</p>
                            </>
                        ) : (
                            <>
                                <UploadCloud className="size-8 text-fg-subtle" />
                                <p className="mt-3 text-[14px] font-medium">Drop the update zip here, or click to choose it</p>
                                <p className="mt-1 text-[12.5px] text-fg-muted">Uploaded in small pieces, so your hosting's upload limit doesn't matter.</p>
                            </>
                        )}
                    </label>
                )}

                {state.phase === 'error' && (
                    <div className="mt-4 flex items-start gap-2.5 rounded-lg bg-fail-soft px-3.5 py-3 text-[13px] text-fail">
                        <TriangleAlert className="mt-px size-4 shrink-0" />
                        <span className="flex-1">{state.message}</span>
                        <button type="button" onClick={reset} className="shrink-0 font-medium underline-offset-2 hover:underline">Start again</button>
                    </div>
                )}

                {state.phase === 'ready' && (
                    <div className="animate-pop-in mt-5 rounded-xl border border-ok/40 bg-ok-soft/60 p-4">
                        <div className="flex items-center gap-3">
                            <FileArchive className="size-5 shrink-0 text-ok" />
                            <div className="min-w-0 flex-1">
                                <p className="text-[14px] font-medium">Version {state.pkg.version} is ready to install</p>
                                <p className="text-[12.5px] text-fg-muted">{updates.version} → {state.pkg.version}, {state.pkg.files.toLocaleString()} files. Takes about a minute; keep this tab open.</p>
                            </div>
                        </div>
                        <div className="mt-4 flex gap-2">
                            <Button variant="primary" onClick={install}><ArrowUpCircle className="size-4" />Install update</Button>
                            <Button onClick={reset}><X className="size-4" />Cancel</Button>
                        </div>
                    </div>
                )}

                {['installing', 'done'].includes(state.phase) && (
                    <ol className="mt-5 space-y-2.5" aria-live="polite">
                        {STEPS.map(([key, label], i) => {
                            const done = state.phase === 'done' || i < stepIndex;
                            const active = state.phase === 'installing' && i === stepIndex;
                            return (
                                <li key={key} className="flex items-center gap-3 text-[13.5px]">
                                    <span className={cn('flex size-6 items-center justify-center rounded-full', done ? 'bg-ok-soft text-ok' : active ? 'bg-primary-soft text-primary' : 'bg-surface-2 text-fg-subtle')}>
                                        {done ? <Check className="size-3.5" strokeWidth={3} /> : active ? <Loader2 className="size-3.5 animate-spin" /> : <span className="text-[11px] font-semibold">{i + 1}</span>}
                                    </span>
                                    <span className={cn(active ? 'font-medium' : done ? 'text-fg-muted' : 'text-fg-subtle')}>
                                        {label}{active && key === 'extract' && state.percent !== undefined ? ` (${state.percent}%)` : ''}
                                    </span>
                                </li>
                            );
                        })}
                        {state.phase === 'done' && <li className="pt-1 text-[13.5px] font-medium text-ok">Updated to {state.pkg.version}. Reloading…</li>}
                    </ol>
                )}
            </Card>

            {updates.history.length > 0 && (
                <Card className="p-6">
                    <h3 className="text-[14px] font-semibold">Update history</h3>
                    <ul className="mt-3 divide-y divide-line text-[13px]">
                        {updates.history.map((h) => (
                            <li key={h.at} className="flex items-center justify-between py-2">
                                <span>{h.from ?? '?'} → <strong>{h.to}</strong></span>
                                <span className="text-fg-subtle">{timeAgo(h.at)}</span>
                            </li>
                        ))}
                    </ul>
                </Card>
            )}
        </div>
    );
}
