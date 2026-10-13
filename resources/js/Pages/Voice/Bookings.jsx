import { useState } from 'react';
import { router } from '@inertiajs/react';
import { CalendarCheck, Mail, MoreHorizontal, Phone } from 'lucide-react';
import AppLayout from '@/Layouts/AppLayout';
import { Badge, Button, Card, EmptyState, Menu, Tabs } from '@/Components/ui';
import { CallSheet, VoiceNav, when } from '@/Components/Voice';
import { useCan } from '@/lib/can';

const SHOW = [['upcoming', 'Upcoming'], ['past', 'Past'], ['cancelled', 'Cancelled'], ['all', 'All']];
const STATUS = { booked: ['ok', 'Booked'], done: ['neutral', 'Done'], cancelled: ['fail', 'Cancelled'] };

export default function Bookings({ bookings, show, timezone }) {
    const can = useCan();
    const [openCall, setOpenCall] = useState(null);
    const setStatus = (b, status) => router.put(`/voice/bookings/${b.id}`, { status }, { preserveScroll: true });

    return (
        <AppLayout title="Voice agents" description={`Appointments your agents booked during calls. Times are in ${timezone}.`}>
            <VoiceNav />
            <Tabs value={show} onChange={(value) => router.get('/voice/bookings', { show: value }, { preserveState: true, replace: true })} items={SHOW.map(([value, label]) => ({ value, label }))} className="mb-4" />

            {bookings.data.length === 0 ? (
                <Card><EmptyState icon={CalendarCheck} title={show === 'upcoming' ? 'No upcoming bookings' : 'Nothing here'}>
                    Turn on Bookings for an agent, and appointments made during calls appear here. A workflow can also add them to Google Calendar or email you.
                </EmptyState></Card>
            ) : (
                <div className="space-y-2.5">
                    {bookings.data.map((b) => {
                        const [tone, label] = STATUS[b.status] ?? ['neutral', b.status];
                        return (
                            <Card key={b.id} className="flex flex-wrap items-center gap-4 px-4 py-3.5">
                                <div className="w-44 shrink-0">
                                    <p className="text-[14px] font-semibold">{b.starts_at ? when(b.starts_at, timezone) : b.requested_time}</p>
                                    {!b.starts_at && <p className="text-[12px] text-warn">Time to confirm</p>}
                                </div>
                                <div className="min-w-0 flex-1">
                                    <p className="truncate text-[14px] font-medium">{b.name}{b.service && <span className="font-normal text-fg-muted"> · {b.service}</span>}</p>
                                    <p className="mt-0.5 flex flex-wrap items-center gap-x-3 gap-y-0.5 text-[12.5px] text-fg-muted">
                                        {b.phone && <a href={`tel:${b.phone}`} className="flex items-center gap-1 hover:text-fg"><Phone className="size-3" />{b.phone}</a>}
                                        {b.email && <a href={`mailto:${b.email}`} className="flex items-center gap-1 hover:text-fg"><Mail className="size-3" />{b.email}</a>}
                                        <span>via {b.agent}</span>
                                    </p>
                                    {b.notes && <p className="mt-1 text-[12.5px] text-fg-subtle">{b.notes}</p>}
                                </div>
                                <Badge tone={tone}>{label}</Badge>
                                {b.call_id && <Button size="sm" variant="ghost" onClick={() => setOpenCall(b.call_id)}>Call</Button>}
                                {can['workflows.edit'] && (
                                    <Menu trigger={<Button size="icon" variant="ghost" aria-label="Booking actions"><MoreHorizontal className="size-4" /></Button>} items={[
                                        b.status !== 'done' && { label: 'Mark as done', onClick: () => setStatus(b, 'done') },
                                        b.status !== 'cancelled' && { label: 'Cancel booking', onClick: () => setStatus(b, 'cancelled'), danger: true },
                                        b.status !== 'booked' && { label: 'Mark as booked', onClick: () => setStatus(b, 'booked') },
                                    ].filter(Boolean)} />
                                )}
                            </Card>
                        );
                    })}
                </div>
            )}
            {(bookings.prev_page_url || bookings.next_page_url) && (
                <div className="mt-4 flex justify-end gap-2">
                    <Button size="sm" disabled={!bookings.prev_page_url} onClick={() => router.get(bookings.prev_page_url, {}, { preserveState: true })}>Previous</Button>
                    <Button size="sm" disabled={!bookings.next_page_url} onClick={() => router.get(bookings.next_page_url, {}, { preserveState: true })}>Next</Button>
                </div>
            )}
            <CallSheet callId={openCall} onClose={() => setOpenCall(null)} />
        </AppLayout>
    );
}
