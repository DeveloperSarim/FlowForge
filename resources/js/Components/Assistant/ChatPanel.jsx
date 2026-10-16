import { Fragment, useEffect, useRef, useState } from 'react';
import { Link } from '@inertiajs/react';
import { AlertTriangle, ArrowUp, Check, ChevronRight, Copy, FlaskConical, Loader2, Pencil, Plus, Power, Trash2, Wrench, X } from 'lucide-react';
import IntegrationIcon from '@/Components/IntegrationIcon';
import { Logo } from '@/Layouts/AppLayout';
import { Button } from '@/Components/ui';
import { cn, compact, errorMessage, toast } from '@/lib/utils';

const SUGGESTIONS = [
    { text: 'When a Typeform response arrives, add a row to Google Sheets and post it to Slack', apps: ['typeform', 'google_sheets', 'slack'] },
    { text: 'Every weekday at 11, write a LinkedIn post with DeepSeek and publish it', apps: ['deepseek', 'linkedin'] },
    { text: 'Har naye Shopify order par customer ko WhatsApp message bhejo', apps: ['shopify', 'whatsapp_business'] },
    { text: 'Explain why my last run failed and fix it', apps: [] },
];

/** The assistant's avatar is the FlowForge mark; the ring turns while it is working. */
export function AiAvatar({ working = false, size = 'md' }) {
    return (
        <span className={cn('relative inline-flex shrink-0', size === 'lg' ? 'size-12 rounded-[14px]' : 'size-8 rounded-[9px]', working && 'ai-ring')} aria-hidden>
            <Logo className="size-full" />
        </span>
    );
}

/** Inline Markdown: **bold**, *italic*, `code`, [text](url), bare URLs and /workflows/ID links. Rendered as elements, never HTML. */
function inline(text, key) {
    return text.split(/(`[^`]+`|\*\*[^*]+\*\*|\[[^\]]+\]\((?:https?:\/\/|\/)[^)\s]+\)|\/workflows\/\d+|https?:\/\/[^\s)]+|\*(?!\s)[^*]+?(?<!\s)\*)/g).map((part, i) => {
        const k = `${key}-${i}`;
        if (!part) return null;
        if (/^`[^`]+`$/.test(part)) return <code key={k} className="rounded bg-surface-3 px-1 py-px font-mono text-[12.5px]">{part.slice(1, -1)}</code>;
        if (/^\*\*.+\*\*$/.test(part)) return <strong key={k}>{part.slice(2, -2)}</strong>;
        const link = part.match(/^\[([^\]]+)\]\(([^)\s]+)\)$/);
        if (link) return link[2].startsWith('/') ? <Link key={k} href={link[2]} className="font-medium text-primary underline-offset-2 hover:underline">{link[1]}</Link>
            : <a key={k} href={link[2]} target="_blank" rel="noreferrer" className="text-primary underline-offset-2 hover:underline">{link[1]}</a>;
        if (/^\/workflows\/\d+$/.test(part)) return <Link key={k} href={part} className="font-medium text-primary underline-offset-2 hover:underline">{part}</Link>;
        if (/^https?:\/\//.test(part)) return <a key={k} href={part} target="_blank" rel="noreferrer" className="break-all text-primary underline-offset-2 hover:underline">{part}</a>;
        if (/^\*.+\*$/.test(part)) return <em key={k}>{part.slice(1, -1)}</em>;
        return <Fragment key={k}>{part}</Fragment>;
    });
}

/** A fenced code block: language label, copy button, and horizontal scroll for long lines. */
function CodeBlock({ lang, code }) {
    const [copied, setCopied] = useState(false);
    const copy = () => navigator.clipboard?.writeText(code).then(() => { setCopied(true); setTimeout(() => setCopied(false), 1500); }, () => toast('Select the code and copy it.', 'error'));
    return (
        <div className="my-2 overflow-hidden rounded-xl border border-line bg-surface-2">
            <div className="flex items-center justify-between border-b border-line px-3 py-1.5 text-[11.5px] text-fg-muted">
                <span className="font-mono">{lang || 'code'}</span>
                <button type="button" onClick={copy} className="inline-flex items-center gap-1 rounded-md px-1.5 py-0.5 font-medium hover:bg-surface-3 hover:text-fg">
                    {copied ? <Check className="size-3.5 text-ok" /> : <Copy className="size-3.5" />}{copied ? 'Copied' : 'Copy'}
                </button>
            </div>
            <pre className="overflow-x-auto px-3 py-2.5 font-mono text-[12.5px] leading-relaxed"><code>{code}</code></pre>
        </div>
    );
}

const cells = (row) => row.trim().replace(/^\||\|$/g, '').split('|').map((c) => c.trim());

/**
 * Safe Markdown for the assistant's answers: paragraphs, headings, lists, quotes, rules, tables and ``` code blocks
 * (an unclosed block still shows as code while the answer streams). Built from elements, so nothing becomes HTML.
 */
function Rich({ text }) {
    const lines = text.split('\n');
    const blocks = [];
    let list = [];
    let ordered = false;
    const flush = (key) => {
        if (!list.length) return;
        blocks.push(ordered
            ? <ol key={key} className="my-1.5 list-decimal space-y-0.5 pl-5">{list}</ol>
            : <ul key={key} className="my-1.5 list-disc space-y-0.5 pl-5">{list}</ul>);
        list = [];
    };

    for (let i = 0; i < lines.length; i++) {
        const line = lines[i];
        const fence = line.match(/^\s*(```|~~~)\s*([\w#+.-]*)/);
        if (fence) {
            flush(`l${i}`);
            const body = [];
            let j = i + 1;
            while (j < lines.length && !lines[j].trim().startsWith(fence[1])) body.push(lines[j++]);
            blocks.push(<CodeBlock key={i} lang={fence[2]} code={body.join('\n')} />);
            i = j;
            continue;
        }
        // A table: a header row, a |---| separator, then rows.
        if (line.includes('|') && /^\s*\|?\s*:?-{3,}/.test(lines[i + 1] ?? '')) {
            flush(`l${i}`);
            const head = cells(line);
            const rows = [];
            let j = i + 2;
            while (j < lines.length && lines[j].includes('|') && lines[j].trim()) rows.push(cells(lines[j++]));
            blocks.push(
                <div key={i} className="my-2 overflow-x-auto rounded-xl border border-line">
                    <table className="w-full text-left text-[13px]">
                        <thead className="bg-surface-2"><tr>{head.map((h, c) => <th key={c} className="px-3 py-1.5 font-semibold">{inline(h, `${i}h${c}`)}</th>)}</tr></thead>
                        <tbody className="divide-y divide-line">{rows.map((r, ri) => <tr key={ri}>{r.map((v, c) => <td key={c} className="px-3 py-1.5 align-top">{inline(v, `${i}r${ri}c${c}`)}</td>)}</tr>)}</tbody>
                    </table>
                </div>,
            );
            i = j - 1;
            continue;
        }
        const bullet = line.match(/^\s*[-*•]\s+(.*)/);
        const numbered = line.match(/^\s*\d+[.)]\s+(.*)/);
        if (bullet || numbered) {
            if (list.length && ordered !== !!numbered) flush(`l${i}`);
            ordered = !!numbered;
            list.push(<li key={i}>{inline((bullet ?? numbered)[1], i)}</li>);
            continue;
        }
        flush(`l${i}`);
        const heading = line.match(/^(#{1,4})\s+(.*)/);
        if (heading) blocks.push(<p key={i} className={cn('mb-1 font-semibold', heading[1].length <= 2 ? 'mt-3 text-[15px]' : 'mt-2.5')}>{inline(heading[2], i)}</p>);
        else if (/^\s*>\s?/.test(line)) blocks.push(<blockquote key={i} className="my-1.5 border-l-2 border-line-strong pl-3 text-fg-muted">{inline(line.replace(/^\s*>\s?/, ''), i)}</blockquote>);
        else if (/^\s*(-{3,}|\*{3,})\s*$/.test(line)) blocks.push(<hr key={i} className="my-3 border-line" />);
        else if (line.trim()) blocks.push(<p key={i} className="my-1">{inline(line, i)}</p>);
    }
    flush('last');
    return <div className="min-w-0 text-[14px] leading-relaxed">{blocks}</div>;
}

const ACTION_META = {
    created: { icon: Plus, label: 'Created', tone: 'text-ok' },
    updated: { icon: Pencil, label: 'Updated', tone: 'text-info' },
    activated: { icon: Power, label: 'Switched on', tone: 'text-ok' },
    deactivated: { icon: Power, label: 'Switched off', tone: 'text-fg-muted' },
    tested: { icon: FlaskConical, label: 'Tested', tone: 'text-info' },
    deleted: { icon: Trash2, label: 'Deleted', tone: 'text-fg-muted' },
};

function Action({ action, onConfirmDelete }) {
    const [busy, setBusy] = useState(false);
    if (action.type === 'delete_pending') {
        return (
            <div className="mt-3 flex items-center gap-3 rounded-xl border border-fail/30 bg-fail-soft px-3 py-2.5">
                <Trash2 className="size-4 shrink-0 text-fail" />
                <p className="flex-1 text-[13px]">Delete <strong>{action.name}</strong> and its run history?</p>
                <Button size="sm" variant="danger" loading={busy} onClick={async () => { setBusy(true); await onConfirmDelete(action.workflow_id); setBusy(false); }}>Delete</Button>
            </div>
        );
    }
    const meta = ACTION_META[action.type] ?? { icon: Check, label: action.type, tone: 'text-fg-muted' };
    return (
        <Link href={action.type === 'deleted' ? '#' : action.execution_id ? `/executions?open=${action.execution_id}` : `/workflows/${action.workflow_id}`}
            className="mt-3 mr-2 inline-flex items-center gap-2 rounded-xl border border-line bg-surface px-3 py-2 text-[12.5px] shadow-card transition-colors hover:border-primary">
            <meta.icon className={cn('size-3.5', meta.tone)} />
            <span className="text-fg-muted">{meta.label}</span>
            <span className="font-medium">{action.name ?? `workflow #${action.workflow_id}`}</span>
            {action.status && <span className={action.status === 'completed' ? 'text-ok' : 'text-fail'}>{action.status === 'completed' ? 'passed' : 'failed'}</span>}
            {action.type !== 'deleted' && <ChevronRight className="size-3.5 text-fg-subtle" />}
        </Link>
    );
}

const STEP_ICON = {
    running: <Loader2 className="size-3.5 animate-spin text-primary" />,
    done: <Check className="size-3 text-ok" strokeWidth={3} />,
    warn: <Wrench className="size-3 text-warn" />,
    error: <X className="size-3 text-fail" strokeWidth={3} />,
};

function StepRow({ step }) {
    return (
        <li className="animate-step-in flex gap-2.5">
            <span className={cn('mt-0.5 flex size-5 shrink-0 items-center justify-center rounded-full',
                { running: 'bg-primary-soft', done: 'bg-ok-soft', warn: 'bg-warn-soft', error: 'bg-fail-soft' }[step.state])}>
                {STEP_ICON[step.state]}
            </span>
            <span className="min-w-0 pt-px">
                <span className={cn('block text-[13px]', step.state === 'running' ? 'text-shimmer font-medium' : 'text-fg-muted')}>{step.label}</span>
                {step.detail && <span className={cn('block text-[12px]', step.state === 'error' ? 'text-fail' : 'text-fg-subtle')}>{step.detail}</span>}
            </span>
        </li>
    );
}

/** What the assistant is doing right now, fed by the streamed progress events. */
function LiveProgress({ live, elapsed }) {
    const running = live.steps.some((s) => s.state === 'running');
    return (
        <div className="animate-msg-in flex gap-3" aria-live="polite">
            <AiAvatar working />
            <div className="min-w-0 flex-1 pt-1">
                {live.note && <p className="mb-2.5 text-[13.5px] text-fg">{live.note}</p>}
                <ol className="space-y-2">
                    {live.steps.map((s, i) => <StepRow key={i} step={s} />)}
                    {!running && (
                        <li className="animate-step-in flex items-center gap-2.5">
                            <span className="flex size-5 items-center justify-center"><span className="animate-running size-2 rounded-full bg-primary" /></span>
                            <span className="text-shimmer text-[13px] font-medium">{live.thinking ?? 'Working'}</span>
                        </li>
                    )}
                </ol>
                {elapsed > 2 && <p className="mt-2.5 text-[11.5px] text-fg-subtle tabular-nums">{elapsed}s</p>}
            </div>
        </div>
    );
}

function StepsSummary({ steps, ms }) {
    if (!steps?.length) return null;
    const fixes = steps.filter((s) => s.state !== 'done').length;
    return (
        <details className="group mb-2">
            <summary className="inline-flex cursor-pointer list-none items-center gap-1.5 rounded-md py-0.5 text-[12.5px] text-fg-subtle select-none hover:text-fg-muted">
                <ChevronRight className="size-3.5 transition-transform group-open:rotate-90" />
                Worked through {steps.length} step{steps.length === 1 ? '' : 's'}{ms ? ` in ${Math.max(1, Math.round(ms / 1000))}s` : ''}
                {fixes > 0 && <span className="inline-flex items-center gap-1 text-warn"><AlertTriangle className="size-3" />fixed {fixes} issue{fixes === 1 ? '' : 's'}</span>}
            </summary>
            <ol className="mt-2 mb-1 space-y-2 border-l border-line pl-3">{steps.map((s, i) => <StepRow key={i} step={s} />)}</ol>
        </details>
    );
}

const xsrfToken = () => decodeURIComponent(document.cookie.split('; ').find((c) => c.startsWith('XSRF-TOKEN='))?.split('=')[1] ?? '');

/** POSTs a message and feeds each NDJSON progress event to onEvent (all at once if a proxy buffers the stream). */
async function streamMessage(conversationId, message, onEvent) {
    const response = await fetch(`/assistant/conversations/${conversationId}/messages`, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', Accept: 'application/x-ndjson', 'X-Requested-With': 'XMLHttpRequest', 'X-XSRF-TOKEN': xsrfToken() },
        body: JSON.stringify({ message }),
    });
    if (!response.ok) {
        const body = await response.json().catch(() => ({}));
        throw new Error(body.errors ? Object.values(body.errors).flat()[0] : body.message || `The request failed (HTTP ${response.status}).`);
    }
    const reader = response.body.getReader();
    const decoder = new TextDecoder();
    let buffer = '';
    for (;;) {
        const { value, done } = await reader.read();
        buffer += decoder.decode(value ?? new Uint8Array(), { stream: !done });
        let newline;
        while ((newline = buffer.indexOf('\n')) >= 0) {
            const line = buffer.slice(0, newline).trim();
            buffer = buffer.slice(newline + 1);
            if (line) onEvent(JSON.parse(line));
        }
        if (done) break;
    }
}

/**
 * Chat with the FlowForge AI builder. Used full-page (/assistant) and as the builder's "Ask AI" panel
 * (scoped to one workflow; onWorkflowChanged reloads the canvas after the assistant edits it).
 */
export default function ChatPanel({ conversationId, workflowId = null, onConversation, onWorkflowChanged, beforeSend, configured, compact: isCompact = false, apps = {}, provider = null }) {
    const [conversation, setConversation] = useState(null);
    // ?prompt= (e.g. from Apps → "Build a custom app") fills the box; the person still presses send.
    const [input, setInput] = useState(() => (isCompact ? '' : new URLSearchParams(window.location.search).get('prompt') ?? ''));
    const [sending, setSending] = useState(false);
    const [live, setLive] = useState(null);
    const [elapsed, setElapsed] = useState(0);
    const scroller = useRef(null);
    const field = useRef(null);

    const loadedId = useRef(null);
    useEffect(() => {
        if (!conversationId) return setConversation(null);
        if (loadedId.current === conversationId) return undefined; // we created it ourselves: keep the optimistic message
        window.axios.get(`/assistant/conversations/${conversationId}`).then(({ data }) => setConversation(data.conversation)).catch(() => setConversation(null));
    }, [conversationId]);

    useEffect(() => {
        if (!conversation?.messages?.length && !sending) return; // the empty state reads from the top
        scroller.current?.scrollTo({ top: scroller.current.scrollHeight, behavior: 'smooth' });
    }, [conversation?.messages?.length, sending, live]);

    useEffect(() => {
        if (!sending) return undefined;
        setElapsed(0);
        const timer = setInterval(() => setElapsed((s) => s + 1), 1000);
        return () => clearInterval(timer);
    }, [sending]);

    useEffect(() => {
        const el = field.current;
        if (!el) return;
        el.style.height = 'auto';
        el.style.height = `${Math.min(el.scrollHeight, 180)}px`;
    }, [input]);

    const send = async (text = input) => {
        const message = text.trim();
        if (!message || sending) return;
        setSending(true);
        setInput('');
        setLive({ steps: [], thinking: 'Understanding your request', note: '' });
        try {
            await beforeSend?.();
            let current = conversation;
            if (!current) {
                const { data } = await window.axios.post('/assistant/conversations', { workflow_id: workflowId });
                current = data.conversation;
                loadedId.current = current.id;
                onConversation?.(current);
            }
            setConversation({ ...current, messages: [...(current.messages ?? []), { role: 'user', text: message, pending: true }] });

            let result = null;
            let failure = null;
            await streamMessage(current.id, message, (event) => {
                if (event.type === 'thinking') setLive((l) => ({ ...l, thinking: event.label }));
                else if (event.type === 'text') setLive((l) => ({ ...l, note: event.text }));
                else if (event.type === 'step') setLive((l) => { const steps = [...l.steps]; steps[event.index] = event; return { ...l, steps }; });
                else if (event.type === 'done') result = event.conversation;
                else if (event.type === 'error') failure = event.message;
            });
            if (failure) throw new Error(failure);
            if (!result) throw new Error('The connection closed before the answer arrived. Reload the page: the reply may still have been saved.');

            setConversation(result);
            onConversation?.(result);
            const last = result.messages.at(-1);
            if (last?.actions?.some((a) => ['created', 'updated', 'activated', 'deactivated', 'deleted'].includes(a.type))) onWorkflowChanged?.(last.actions);
        } catch (error) {
            toast(errorMessage(error), 'error');
            setConversation((c) => c && { ...c, messages: c.messages.filter((m) => !m.pending) });
            setInput(message);
        } finally {
            setSending(false);
            setLive(null);
            field.current?.focus();
        }
    };

    const confirmDelete = async (workflowIdToDelete) => {
        try {
            const { data } = await window.axios.post(`/assistant/conversations/${conversation.id}/confirm-delete`, { workflow_id: workflowIdToDelete });
            setConversation(data.conversation);
            onWorkflowChanged?.([{ type: 'deleted', workflow_id: workflowIdToDelete }]);
            toast('Workflow deleted.', 'success');
        } catch (error) {
            toast(errorMessage(error), 'error');
        }
    };

    const messages = conversation?.messages ?? [];

    if (!configured) {
        return (
            <div className="flex flex-1 flex-col items-center justify-center p-8 text-center">
                <AiAvatar size="lg" />
                <p className="mt-4 text-[16px] font-semibold">Connect an AI provider</p>
                <p className="mt-1 max-w-sm text-[13px] text-fg-muted">Use DeepSeek, Qwen, OpenAI, Claude or Gemini with your own API key. The key stays encrypted on your server.</p>
                <Link href="/settings?tab=ai" className="mt-5 inline-flex h-9 items-center rounded-lg bg-primary px-4 text-sm font-medium text-primary-fg hover:bg-primary-hover">Set up the AI builder</Link>
            </div>
        );
    }

    const workflowSuggestions = ['Add a Slack message at the end', 'Only continue when the email ends with my company domain', 'Explain what this workflow does'];

    return (
        <div className="flex min-h-0 flex-1 flex-col">
            <div ref={scroller} className={cn('min-h-0 flex-1 overflow-y-auto', isCompact ? 'px-4 py-4' : 'px-6 py-8')}>
                {messages.length === 0 && !sending && (
                    <div className={cn('animate-msg-in mx-auto', isCompact ? '' : 'flex min-h-full max-w-2xl flex-col pb-10 [&>:first-child]:mt-auto [&>:last-child]:mb-auto')}>
                        <AiAvatar size={isCompact ? 'md' : 'lg'} />
                        <p className={cn('mt-4 font-semibold tracking-tight', isCompact ? 'text-[16px]' : 'text-[24px]')}>{workflowId ? 'What should change in this workflow?' : 'What should we automate?'}</p>
                        <p className="mt-1.5 max-w-xl text-[14px] text-fg-muted">Describe it in your own words, in English or Roman Urdu. I'll pick the apps, connect the steps, check the data between them and tell you what's left to fill in.</p>
                        <div className={cn('mt-6 grid gap-2.5', !isCompact && !workflowId && 'sm:grid-cols-2')}>
                            {(workflowId ? workflowSuggestions.map((text) => ({ text, apps: [] })) : SUGGESTIONS).map((s) => (
                                <button key={s.text} type="button" onClick={() => send(s.text)}
                                    className="group flex flex-col gap-2.5 rounded-xl border border-line bg-surface p-3.5 text-left shadow-card transition-colors hover:border-primary">
                                    {s.apps.some((a) => apps[a]) && (
                                        <span className="flex items-center gap-1">
                                            {s.apps.filter((a) => apps[a]).map((a) => <IntegrationIcon key={a} icon={apps[a].icon} name={apps[a].name} size="xs" />)}
                                        </span>
                                    )}
                                    <span className="text-[13px] leading-snug text-fg-muted group-hover:text-fg">{s.text}</span>
                                </button>
                            ))}
                        </div>
                    </div>
                )}
                <div className={cn('space-y-6', !isCompact && 'mx-auto max-w-3xl')}>
                    {messages.map((m, i) => m.role === 'user' ? (
                        <div key={i} className="animate-msg-in flex justify-end">
                            <div className="max-w-[85%] rounded-2xl rounded-br-md bg-primary px-4 py-2.5 text-[14px] leading-relaxed whitespace-pre-wrap text-primary-fg shadow-sm">{m.text}</div>
                        </div>
                    ) : (
                        <div key={i} className="animate-msg-in flex gap-3">
                            <AiAvatar />
                            <div className="min-w-0 flex-1 pt-1">
                                <StepsSummary steps={m.steps} ms={m.ms} />
                                <Rich text={m.text} />
                                {m.actions?.length > 0 && <div>{m.actions.map((a, j) => <Action key={j} action={a} onConfirmDelete={confirmDelete} />)}</div>}
                                {m.usage && (
                                    <p className="mt-2 text-[11px] text-fg-subtle" title="Tokens for this answer: input (of which cached) / output">
                                        {compact(m.usage.input)} in{m.usage.cached ? ` (${compact(m.usage.cached)} cached)` : ''}, {compact(m.usage.output)} out, {m.usage.calls} call{m.usage.calls === 1 ? '' : 's'}
                                    </p>
                                )}
                            </div>
                        </div>
                    ))}
                    {sending && live && <LiveProgress live={live} elapsed={elapsed} />}
                </div>
            </div>
            <form onSubmit={(e) => { e.preventDefault(); send(); }} className={cn(isCompact ? 'border-t border-line bg-surface p-3' : 'px-6 pt-2 pb-5')}>
                <div className={cn('rounded-2xl border border-line bg-surface shadow-card transition-[border-color,box-shadow] focus-within:border-primary focus-within:ring-[3px] focus-within:ring-primary/15', !isCompact && 'mx-auto max-w-3xl')}>
                    <textarea ref={field} value={input} onChange={(e) => setInput(e.target.value)} rows={isCompact ? 1 : 2} disabled={sending}
                        onKeyDown={(e) => { if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); send(); } }}
                        placeholder={workflowId ? 'Ask for a change…' : 'Describe a workflow, or ask me to edit, test or delete one…'} aria-label="Message the AI builder"
                        className="block w-full resize-none bg-transparent px-4 pt-3 pb-1 text-base outline-none sm:text-[14px] placeholder:text-fg-subtle disabled:opacity-60" />
                    <div className="flex items-center gap-2 px-2.5 pb-2.5">
                        {provider && !isCompact ? (
                            <Link href="/settings?tab=ai" className="inline-flex min-w-0 items-center gap-1.5 rounded-lg px-2 py-1 text-[12px] text-fg-subtle hover:bg-surface-2 hover:text-fg" title="Change the AI model">
                                <IntegrationIcon icon={provider.icon} name={provider.provider} size="xs" />
                                <span className="truncate">{provider.model}</span>
                            </Link>
                        ) : <span />}
                        <span className="ml-auto hidden text-[11.5px] text-fg-subtle sm:inline">{sending ? 'Working…' : 'Enter to send, Shift+Enter for a new line'}</span>
                        <Button type="submit" variant="primary" size="icon" disabled={!input.trim() || sending} aria-label="Send" className="rounded-xl">
                            {sending ? <Loader2 className="size-4 animate-spin" /> : <ArrowUp className="size-4" />}
                        </Button>
                    </div>
                </div>
                {!isCompact && <p className="mx-auto mt-2 max-w-3xl text-center text-[11.5px] text-fg-subtle">It can create, edit and test workflows, and delete them only after you confirm. Check what it builds before switching it on.</p>}
            </form>
        </div>
    );
}
