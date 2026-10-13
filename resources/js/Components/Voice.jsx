import { useEffect, useState } from 'react';
import { Link, usePage } from '@inertiajs/react';
import { CalendarCheck, Globe2, Loader2, PhoneCall, PhoneIncoming, PhoneOutgoing, X } from 'lucide-react';
import { Badge, Button, Sheet } from '@/Components/ui';
import { cn, errorMessage } from '@/lib/utils';

/** Agents | Calls | Bookings, under the page title on every Voice page. */
export function VoiceNav() {
    const { url } = usePage();
    const items = [['/voice', 'Agents', PhoneCall], ['/voice/calls', 'Calls', PhoneIncoming], ['/voice/bookings', 'Bookings', CalendarCheck]];
    const active = (href) => (href === '/voice' ? url === '/voice' || url.startsWith('/voice?') || url.startsWith('/voice/agents') : url.startsWith(href));
    return (
        <nav className="no-scrollbar -mx-4 mb-5 flex gap-1.5 overflow-x-auto px-4 sm:mx-0 sm:px-0" aria-label="Voice agents sections">
            {items.map(([href, label, Icon]) => (
                <Link key={href} href={href} aria-current={active(href) ? 'page' : undefined}
                    className={cn('flex shrink-0 items-center gap-2 rounded-full border px-3.5 py-1.5 text-[13.5px] font-medium',
                        active(href) ? 'border-primary/30 bg-primary-soft text-primary' : 'border-line bg-surface text-fg-muted hover:bg-surface-2 hover:text-fg')}>
                    <Icon className="size-4" />{label}
                </Link>
            ))}
        </nav>
    );
}

export const CALL_STATUS = {
    queued: ['warn', 'Queued'], ringing: ['info', 'Ringing'], 'in-progress': ['info', 'In progress'],
    completed: ['ok', 'Completed'], 'no-answer': ['neutral', 'No answer'], failed: ['fail', 'Failed'],
};

export function CallStatus({ status }) {
    const [tone, label] = CALL_STATUS[status] ?? ['neutral', status];
    return (
        <Badge tone={tone}>
            <span className={cn('size-1.5 rounded-full bg-current', ['ringing', 'in-progress'].includes(status) && 'animate-pulse')} aria-hidden />{label}
        </Badge>
    );
}

export function DirectionIcon({ direction, className }) {
    const Icon = direction === 'inbound' ? PhoneIncoming : direction === 'outbound' ? PhoneOutgoing : Globe2;
    const label = direction === 'inbound' ? 'Incoming call' : direction === 'outbound' ? 'Outgoing call' : 'Browser conversation';
    return <Icon className={cn('size-4 shrink-0 text-fg-subtle', className)} aria-label={label} />;
}

export const PROVIDERS = {
    browser: { label: 'Browser', tone: 'neutral', hint: 'Free' },
    vapi: { label: 'Vapi', tone: 'info' },
    elevenlabs: { label: 'ElevenLabs', tone: 'primary' },
};

export function seconds(s) {
    if (s == null) return '—';
    const m = Math.floor(s / 60);
    return m ? `${m}m ${String(s % 60).padStart(2, '0')}s` : `${s}s`;
}

export function when(iso, timezone) {
    if (!iso) return null;
    return new Intl.DateTimeFormat(undefined, { weekday: 'short', day: 'numeric', month: 'short', hour: 'numeric', minute: '2-digit', timeZone: timezone || undefined }).format(new Date(iso));
}

function OutcomeBadge({ outcome }) {
    if (!outcome) return null;
    return <Badge tone={outcome === 'success' ? 'ok' : outcome === 'failure' ? 'fail' : 'neutral'}>{outcome === 'success' ? 'Goal met' : outcome === 'failure' ? 'Goal not met' : 'Unclear outcome'}</Badge>;
}

/** Side sheet with one call: recording, summary, collected details, transcript and bookings. */
export function CallSheet({ callId, onClose }) {
    const [call, setCall] = useState(null);
    const [error, setError] = useState(null);
    useEffect(() => {
        if (!callId) return;
        setCall(null);
        setError(null);
        window.axios.get(`/voice/calls/${callId}`).then(({ data }) => setCall(data.call)).catch((e) => setError(errorMessage(e)));
    }, [callId]);

    const details = call ? Object.entries(call.data ?? {}).filter(([key, value]) => key !== 'variables' && value !== null && value !== '' && typeof value !== 'object') : [];

    return (
        <Sheet open={!!callId} onClose={onClose} className="w-full max-w-xl">
            <div className="flex items-start justify-between gap-3 border-b border-line px-5 py-4">
                <div className="min-w-0">
                    <h2 className="truncate text-[15px] font-semibold">{call ? `${call.agent} · call #${call.id}` : 'Call'}</h2>
                    {call && <p className="mt-0.5 flex flex-wrap items-center gap-2 text-[12.5px] text-fg-muted">
                        <DirectionIcon direction={call.direction} />
                        {call.direction === 'web' ? 'Browser conversation' : `${call.from ?? 'Unknown'} → ${call.to ?? 'Unknown'}`}
                        <span>{when(call.started_at)}</span><span>{seconds(call.duration)}</span>
                    </p>}
                </div>
                <Button variant="ghost" size="icon" onClick={onClose} aria-label="Close"><X className="size-4" /></Button>
            </div>
            <div className="flex-1 space-y-5 overflow-y-auto px-5 py-5">
                {error && <p className="rounded-lg bg-fail-soft px-3 py-2 text-[13px] text-fail">{error}</p>}
                {!call && !error && <div className="flex items-center gap-2 text-[13px] text-fg-muted"><Loader2 className="size-4 animate-spin" />Loading the call…</div>}
                {call && (
                    <>
                        <div className="flex flex-wrap items-center gap-2"><CallStatus status={call.status} /><OutcomeBadge outcome={call.outcome} />{call.cost != null && <Badge>${call.cost.toFixed(3)}</Badge>}{call.ended_reason && <span className="text-[12px] text-fg-subtle">{call.ended_reason.replaceAll('-', ' ')}</span>}</div>
                        {call.recording && (
                            <section>
                                <h3 className="mb-2 text-[13px] font-semibold">Recording</h3>
                                <audio controls preload="none" src={call.recording} className="w-full" />
                            </section>
                        )}
                        {call.summary && (
                            <section>
                                <h3 className="mb-1.5 text-[13px] font-semibold">Summary</h3>
                                <p className="text-[13.5px] leading-relaxed text-fg-muted">{call.summary}</p>
                            </section>
                        )}
                        {details.length > 0 && (
                            <section>
                                <h3 className="mb-2 text-[13px] font-semibold">Collected details</h3>
                                <dl className="divide-y divide-line rounded-lg border border-line text-[13px]">
                                    {details.map(([key, value]) => (
                                        <div key={key} className="grid grid-cols-[minmax(0,2fr)_minmax(0,3fr)] gap-3 px-3 py-2">
                                            <dt className="text-fg-muted">{key.replaceAll('_', ' ')}</dt><dd className="break-words font-medium">{String(value)}</dd>
                                        </div>
                                    ))}
                                </dl>
                            </section>
                        )}
                        {call.booking_list?.length > 0 && (
                            <section>
                                <h3 className="mb-2 text-[13px] font-semibold">Booked on this call</h3>
                                {call.booking_list.map((b) => (
                                    <Link key={b.id} href="/voice/bookings?show=all" className="flex items-center gap-3 rounded-lg border border-ok/30 bg-ok-soft/50 px-3 py-2 text-[13px]">
                                        <CalendarCheck className="size-4 text-ok" />
                                        <span className="font-medium">{b.name}</span>
                                        <span className="text-fg-muted">{b.starts_at ? when(b.starts_at) : b.requested_time}</span>
                                        {b.service && <span className="truncate text-fg-subtle">{b.service}</span>}
                                    </Link>
                                ))}
                            </section>
                        )}
                        <section>
                            <h3 className="mb-2 text-[13px] font-semibold">Transcript</h3>
                            <Transcript turns={call.transcript} />
                        </section>
                    </>
                )}
            </div>
        </Sheet>
    );
}

export function Transcript({ turns, live }) {
    if (!turns?.length) return <p className="text-[13px] text-fg-subtle">{live ? 'The conversation will appear here.' : 'No transcript for this call.'}</p>;
    return (
        <ol className="space-y-2.5">
            {turns.map((t, i) => (
                <li key={i} className={cn('flex', t.role === 'user' ? 'justify-end' : 'justify-start')}>
                    <div className={cn('max-w-[85%] rounded-2xl px-3.5 py-2 text-[13.5px] leading-relaxed',
                        t.role === 'user' ? 'rounded-br-md bg-primary text-primary-fg' : 'rounded-bl-md bg-surface-2 text-fg')}>
                        <span className="sr-only">{t.role === 'user' ? 'Caller: ' : 'Agent: '}</span>{t.text}
                    </div>
                </li>
            ))}
        </ol>
    );
}
