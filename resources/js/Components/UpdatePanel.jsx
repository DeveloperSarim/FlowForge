import { useEffect, useRef, useState } from 'react';
import { ArrowUpCircle, Check, ChevronDown, ExternalLink, FileArchive, Loader2, RefreshCw, Sparkles, TriangleAlert, UploadCloud, X } from 'lucide-react';
import { Button, Card, buttonClass } from '@/Components/ui';
import RichText from '@/Components/RichText';
import { cn, errorMessage, timeAgo } from '@/lib/utils';

const CHUNK = 1.5 * 1024 * 1024; // stays under the smallest common upload_max_filesize (2 MB)
const STEPS = [
    ['download', 'Downloading from GitHub and checking it'],
    ['extract', 'Unpacking the new version'],
    ['swap', 'Switching to it (the old version is kept as a backup)'],
    ['finish', 'Updating the database'],
];

/**
 * Settings → Updates. The usual way: FlowForge checks GitHub for a newer release and installs it when an
 * admin clicks. Uploading flowforge-update-x.y.z.zip by hand stays available underneath.
 */
export default function UpdatePanel({ updates }) {
    const input = useRef(null);
    const [state, setState] = useState({ phase: 'idle' });
    const [dragging, setDragging] = useState(false);
    const [check, setCheck] = useState(() => (updates.latest ? summarize(updates.latest, updates.version) : { status: 'checking' }));
    const [manualOpen, setManualOpen] = useState(false);

    const runCheck = async (fresh = false) => {
        setCheck((c) => ({ ...c, status: 'checking' }));
        try {
            const { data } = await window.axios.get('/settings/updates/check', { params: fresh ? { fresh: 1 } : {} });
            setCheck(summarize(data.latest, data.current));
        } catch (error) {
            setCheck({ status: 'error', message: errorMessage(error) });
        }
    };
    useEffect(() => { runCheck(); }, []);

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

    /** The shared second half: unpack, swap (outside Laravel), migrate on the new code. */
    const install = async (pkg, source) => {
        const progress = (step, extra = {}) => setState({ phase: 'installing', pkg, source, step, ...extra });
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
            setState({ phase: 'done', pkg, source });
            setTimeout(() => window.location.assign('/settings/updates'), 2000);
        } catch (error) {
            setState({ phase: 'error', message: errorMessage(error), pkg, source });
        }
    };

    const installFromGitHub = async () => {
        const pkg = { version: check.latest.version };
        setState({ phase: 'installing', pkg, source: 'github', step: 'download' });
        try {
            const { data } = await window.axios.post('/settings/updates/download');
            await install(data.package, 'github');
        } catch (error) {
            setState({ phase: 'error', message: errorMessage(error), pkg, source: 'github' });
        }
    };

    const reset = () => { setState({ phase: 'idle' }); if (input.current) input.current.value = ''; };
    const busy = ['uploading', 'installing', 'done'].includes(state.phase);
    const steps = STEPS.filter(([key]) => key !== 'download' || state.source === 'github');
    const stepIndex = steps.findIndex(([key]) => key === state.step);

    return (
        <div className="space-y-6">
            <Card className="p-6">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <h2 className="text-[16px] font-semibold">Updates</h2>
                        <p className="mt-0.5 max-w-xl text-[13px] text-fg-muted">FlowForge checks GitHub for new versions and installs one when you click. Your settings, credentials, workflows and run history stay as they are.</p>
                    </div>
                    <span className="rounded-lg bg-surface-2 px-3 py-1.5 text-[13px]">Installed version <strong className="tabular-nums">{updates.version}</strong></span>
                </div>

                {!busy && state.phase !== 'ready' && <GitHubStatus check={check} repo={updates.repo} current={updates.version} onCheck={() => runCheck(true)} onInstall={installFromGitHub} />}

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
                        <div className="mt-4 flex flex-wrap gap-2">
                            <Button variant="primary" onClick={() => install(state.pkg, 'upload')}><ArrowUpCircle className="size-4" />Install update</Button>
                            <Button onClick={reset}><X className="size-4" />Cancel</Button>
                        </div>
                    </div>
                )}

                {['installing', 'done'].includes(state.phase) && (
                    <ol className="mt-5 space-y-2.5" aria-live="polite">
                        {steps.map(([key, label], i) => {
                            const done = state.phase === 'done' || i < stepIndex;
                            const active = state.phase === 'installing' && i === stepIndex;
                            return (
                                <li key={key} className="flex items-center gap-3 text-[13.5px]">
                                    <span className={cn('flex size-6 shrink-0 items-center justify-center rounded-full', done ? 'bg-ok-soft text-ok' : active ? 'bg-primary-soft text-primary' : 'bg-surface-2 text-fg-subtle')}>
                                        {done ? <Check className="size-3.5" strokeWidth={3} /> : active ? <Loader2 className="size-3.5 animate-spin" /> : <span className="text-[11px] font-semibold">{i + 1}</span>}
                                    </span>
                                    <span className={cn(active ? 'font-medium' : done ? 'text-fg-muted' : 'text-fg-subtle')}>
                                        {label}{active && key === 'extract' && state.percent !== undefined ? ` (${state.percent}%)` : ''}
                                    </span>
                                </li>
                            );
                        })}
                        {state.phase === 'installing' && <li className="pt-1 text-[12.5px] text-fg-subtle">Takes about a minute. Keep this tab open.</li>}
                        {state.phase === 'done' && <li className="pt-1 text-[13.5px] font-medium text-ok">Updated to {state.pkg.version}. Reloading…</li>}
                    </ol>
                )}

                {/* Optional: install from a zip file (offline servers, a specific version, or when GitHub can't be reached). */}
                {['idle', 'uploading', 'error'].includes(state.phase) && !(state.phase === 'error' && state.pkg) && (
                    <div className="mt-5 border-t border-line pt-4">
                        <button type="button" onClick={() => setManualOpen((o) => !o)} aria-expanded={manualOpen || state.phase === 'uploading'}
                            className="flex w-full items-center gap-2 text-left text-[13.5px] font-medium text-fg-muted hover:text-fg">
                            <ChevronDown className={cn('size-4 transition-transform', (manualOpen || state.phase === 'uploading') && 'rotate-180')} />
                            Install from a zip file instead
                            <span className="font-normal text-fg-subtle">(optional)</span>
                        </button>
                        {(manualOpen || state.phase === 'uploading') && (
                            <label
                                onDragOver={(e) => { e.preventDefault(); setDragging(true); }}
                                onDragLeave={() => setDragging(false)}
                                onDrop={(e) => { e.preventDefault(); setDragging(false); upload(e.dataTransfer.files[0]); }}
                                className={cn('mt-3 flex cursor-pointer flex-col items-center justify-center rounded-xl border-2 border-dashed px-6 py-8 text-center transition-colors',
                                    dragging ? 'border-primary bg-primary-soft/60' : 'border-line hover:border-line-strong', state.phase === 'uploading' && 'pointer-events-none')}>
                                <input ref={input} type="file" accept=".zip,application/zip" className="sr-only" onChange={(e) => upload(e.target.files[0])} />
                                {state.phase === 'uploading' ? (
                                    <>
                                        <Loader2 className="size-7 animate-spin text-primary" />
                                        <p className="mt-3 text-[14px] font-medium">Uploading {state.name}…</p>
                                        <div className="mt-3 h-2 w-64 max-w-full overflow-hidden rounded-full bg-surface-2"><div className="h-full rounded-full bg-primary transition-[width]" style={{ width: `${state.percent}%` }} /></div>
                                        <p className="mt-1.5 text-[12px] text-fg-subtle tabular-nums">{state.percent}%</p>
                                    </>
                                ) : (
                                    <>
                                        <UploadCloud className="size-8 text-fg-subtle" />
                                        <p className="mt-3 text-[14px] font-medium">Drop flowforge-update-x.y.z.zip here, or click to choose it</p>
                                        <p className="mt-1 text-[12.5px] text-fg-muted">Uploaded in small pieces, so your hosting's upload limit doesn't matter.</p>
                                    </>
                                )}
                            </label>
                        )}
                    </div>
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

function summarize(latest, current) {
    const newer = latest && compareVersions(latest.version, current) > 0;
    return { status: newer && latest.zip ? 'available' : newer ? 'no-package' : 'latest', latest };
}

function compareVersions(a, b) {
    const [x, y] = [a, b].map((v) => String(v).split('.').map(Number));
    for (let i = 0; i < 3; i++) if ((x[i] ?? 0) !== (y[i] ?? 0)) return (x[i] ?? 0) - (y[i] ?? 0);
    return 0;
}

function GitHubStatus({ check, repo, current, onCheck, onInstall }) {
    const [notesOpen, setNotesOpen] = useState(false);
    const { latest } = check;
    const checked = latest?.checked_at && `Checked ${timeAgo(latest.checked_at)}`;

    if (check.status === 'available') {
        return (
            <div className="animate-pop-in mt-5 rounded-xl border border-primary/30 bg-primary-soft/50 p-4">
                <div className="flex items-start gap-3">
                    <span className="flex size-9 shrink-0 items-center justify-center rounded-lg bg-primary text-primary-fg"><Sparkles className="size-[18px]" /></span>
                    <div className="min-w-0 flex-1">
                        <p className="text-[14.5px] font-semibold">Version {latest.version} is available</p>
                        <p className="text-[12.5px] text-fg-muted">
                            You have {current}.{latest.published_at && ` Released ${timeAgo(latest.published_at)}.`} FlowForge downloads it from GitHub, checks it and installs it in about a minute.
                        </p>
                    </div>
                </div>
                {latest.notes && (
                    <div className="mt-3">
                        <button type="button" onClick={() => setNotesOpen((o) => !o)} aria-expanded={notesOpen} className="flex items-center gap-1.5 text-[13px] font-medium text-primary hover:underline">
                            <ChevronDown className={cn('size-4 transition-transform', notesOpen && 'rotate-180')} />What's new
                        </button>
                        {notesOpen && <div className="mt-2 max-h-80 overflow-y-auto break-words rounded-lg bg-surface px-3.5 py-3"><RichText text={latest.notes} className="text-[13px] text-fg-muted [&_strong]:text-fg" /></div>}
                    </div>
                )}
                <div className="mt-4 flex flex-wrap gap-2">
                    <Button variant="primary" onClick={onInstall}><ArrowUpCircle className="size-4" />Download and install {latest.version}</Button>
                    {latest.url && <a href={latest.url} target="_blank" rel="noreferrer" className={buttonClass()}><ExternalLink className="size-4" />View release</a>}
                </div>
            </div>
        );
    }

    return (
        <div className="mt-5 flex flex-wrap items-center gap-3 rounded-xl border border-line px-4 py-3.5">
            {check.status === 'checking' ? <Loader2 className="size-5 shrink-0 animate-spin text-fg-subtle" />
                : check.status === 'error' ? <TriangleAlert className="size-5 shrink-0 text-warn" />
                : <span className="flex size-6 shrink-0 items-center justify-center rounded-full bg-ok-soft text-ok"><Check className="size-3.5" strokeWidth={3} /></span>}
            <div className="min-w-0 flex-1 text-[13px]">
                {check.status === 'checking' && <p className="font-medium">Checking GitHub for a new version…</p>}
                {check.status === 'latest' && <><p className="font-medium">You're on the latest version.</p><p className="text-fg-subtle">{checked}{checked && ' from '}<a href={`https://github.com/${repo}/releases`} target="_blank" rel="noreferrer" className="hover:underline">{repo}</a></p></>}
                {check.status === 'no-package' && <><p className="font-medium">Version {latest.version} is out, but its update package isn't on GitHub yet.</p><p className="text-fg-subtle">Check again later, or upload the zip below.</p></>}
                {check.status === 'error' && <><p className="font-medium">Couldn't check for updates.</p><p className="text-fg-subtle">{check.message}</p></>}
            </div>
            {check.status !== 'checking' && <Button size="sm" onClick={onCheck}><RefreshCw className="size-3.5" />Check again</Button>}
        </div>
    );
}
