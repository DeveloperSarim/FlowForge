import { useEffect, useState } from 'react';
import { Link, router } from '@inertiajs/react';
import { CalendarCheck, PhoneCall, Volume2 } from 'lucide-react';
import AppLayout from '@/Layouts/AppLayout';
import { Button, Card, EmptyState, Select, Tabs } from '@/Components/ui';
import { CallSheet, CallStatus, DirectionIcon, VoiceNav, seconds, when } from '@/Components/Voice';
import { cn } from '@/lib/utils';

const STATUSES = [['', 'All'], ['completed', 'Completed'], ['no-answer', 'No answer'], ['failed', 'Failed'], ['in-progress', 'In progress']];

export default function Calls({ calls, agents, filters }) {
    const [openId, setOpenId] = useState(() => Number(new URLSearchParams(window.location.search).get('open')) || null);
    const filter = (patch) => router.get('/voice/calls', { ...filters, open: undefined, ...patch }, { preserveState: true, replace: true });

    const inFlight = calls.data.some((c) => ['queued', 'ringing', 'in-progress'].includes(c.status) && c.direction !== 'web');
    useEffect(() => {
        if (!inFlight) return;
        const timer = setInterval(() => router.reload({ only: ['calls'] }), 8000);
        return () => clearInterval(timer);
    }, [inFlight]);

    return (
        <AppLayout title="Voice agents" description="Every call your agents made or answered, with the recording, transcript, summary and what was booked.">
            <VoiceNav />
            <div className="mb-4 flex flex-wrap items-center gap-3">
                <Select value={filters.agent ?? ''} onChange={(e) => filter({ agent: e.target.value || undefined, page: undefined })} className="w-56" aria-label="Filter by agent">
                    <option value="">All agents</option>
                    {agents.map((a) => <option key={a.id} value={a.id}>{a.name}</option>)}
                </Select>
                <Tabs value={filters.status ?? ''} onChange={(value) => filter({ status: value || undefined, page: undefined })} items={STATUSES.map(([value, label]) => ({ value, label }))} className="max-w-full overflow-x-auto" />
            </div>

            {calls.data.length === 0 ? (
                <Card><EmptyState icon={PhoneCall} title="No calls yet" action={<Link href="/voice"><Button>Go to your agents</Button></Link>}>Calls appear here when an agent answers or makes a call, or when you talk to one in the browser.</EmptyState></Card>
            ) : (
                <Card className="overflow-hidden">
                    <table className="w-full text-left text-13h">
                        <thead className="border-b border-line bg-surface-2 text-12h text-fg-muted">
                            <tr>
                                <th className="w-32 px-4 py-2.5 font-medium">Status</th>
                                <th className="px-4 py-2.5 font-medium">Call</th>
                                <th className="hidden px-4 py-2.5 font-medium md:table-cell">When</th>
                                <th className="hidden px-4 py-2.5 text-right font-medium sm:table-cell">Length</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-line">
                            {calls.data.map((c) => (
                                <tr key={c.id} onClick={() => setOpenId(c.id)} className={cn('cursor-pointer hover:bg-surface-2/70', openId === c.id && 'bg-primary-soft/50')}>
                                    <td className="px-4 py-3 align-top"><CallStatus status={c.status} /></td>
                                    <td className="max-w-0 px-4 py-3">
                                        <div className="flex items-center gap-2">
                                            <DirectionIcon direction={c.direction} />
                                            <button type="button" className="truncate text-left font-medium hover:text-primary">
                                                {c.direction === 'web' ? 'Browser conversation' : (c.direction === 'inbound' ? c.from : c.to) ?? 'Unknown number'}
                                            </button>
                                            <span className="shrink-0 truncate text-12h text-fg-subtle">{c.agent}</span>
                                            {c.has_recording && <Volume2 className="size-3.5 shrink-0 text-fg-subtle" aria-label="Has a recording" />}
                                            {c.bookings > 0 && <CalendarCheck className="size-3.5 shrink-0 text-ok" aria-label="Booked an appointment" />}
                                        </div>
                                        {c.summary && <p className="mt-0.5 truncate text-12h text-fg-muted">{c.summary}</p>}
                                        <p className="mt-0.5 text-12 text-fg-subtle md:hidden">{when(c.started_at)} · {seconds(c.duration)}</p>
                                    </td>
                                    <td className="hidden px-4 py-3 align-top whitespace-nowrap text-fg-muted md:table-cell">{when(c.started_at)}</td>
                                    <td className="hidden px-4 py-3 text-right align-top text-fg-muted tabular-nums sm:table-cell">{seconds(c.duration)}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                    {(calls.prev_page_url || calls.next_page_url) && (
                        <div className="flex items-center justify-between border-t border-line px-4 py-3 text-13">
                            <span className="text-fg-muted">Page {calls.current_page} of {calls.last_page}</span>
                            <div className="flex gap-2">
                                <Button size="sm" disabled={!calls.prev_page_url} onClick={() => router.get(calls.prev_page_url, {}, { preserveState: true })}>Previous</Button>
                                <Button size="sm" disabled={!calls.next_page_url} onClick={() => router.get(calls.next_page_url, {}, { preserveState: true })}>Next</Button>
                            </div>
                        </div>
                    )}
                </Card>
            )}
            <CallSheet callId={openId} onClose={() => setOpenId(null)} />
        </AppLayout>
    );
}
