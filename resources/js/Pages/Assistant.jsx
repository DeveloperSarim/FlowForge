import { useMemo, useState } from 'react';
import { Head, Link } from '@inertiajs/react';
import { ArrowUpRight, MessageSquare, Plus, Trash2, Workflow } from 'lucide-react';
import AiIcon from '@/Components/AiIcon';
import AppLayout from '@/Layouts/AppLayout';
import ChatPanel from '@/Components/Assistant/ChatPanel';
import { Button } from '@/Components/ui';
import { cn, timeAgo } from '@/lib/utils';

/** Today / Yesterday / Earlier buckets for the chat list. */
function groupByDay(conversations) {
    const day = (iso) => new Date(iso).toDateString();
    const today = new Date().toDateString();
    const yesterday = new Date(Date.now() - 864e5).toDateString();
    const groups = [['Today', []], ['Yesterday', []], ['Earlier', []]];
    conversations.forEach((c) => groups[day(c.updated_at) === today ? 0 : day(c.updated_at) === yesterday ? 1 : 2][1].push(c));
    return groups.filter(([, list]) => list.length);
}

export default function Assistant({ conversations: initial, ai, apps }) {
    const [conversations, setConversations] = useState(initial);
    const [activeId, setActiveId] = useState(() => Number(new URLSearchParams(window.location.search).get('c')) || initial[0]?.id || null);
    const [key, setKey] = useState(0); // remount the chat for "New chat"
    const active = conversations.find((c) => c.id === activeId);
    const groups = useMemo(() => groupByDay(conversations), [conversations]);

    const upsert = (c) => setConversations((list) => {
        const previous = list.find((x) => x.id === c.id);
        return [{ id: c.id, title: c.title, workflow_id: c.workflow_id, workflow_name: previous?.workflow_name ?? null, updated_at: new Date().toISOString() }, ...list.filter((x) => x.id !== c.id)];
    });
    const open = (id) => { setActiveId(id); setKey((k) => k + 1); };

    const remove = async (id) => {
        await window.axios.delete(`/assistant/conversations/${id}`);
        setConversations((list) => list.filter((c) => c.id !== id));
        if (activeId === id) open(null);
    };

    return (
        <AppLayout wide title={null}>
            <Head title="AI Builder" />
            <div className="-mx-4 -my-8 flex h-screen sm:-mx-8 max-lg:h-[calc(100vh-57px)]">
                <aside className="hidden w-72 shrink-0 flex-col border-r border-line bg-surface md:flex">
                    <div className="px-4 pt-5 pb-4">
                        <div className="flex items-center gap-2">
                            <AiIcon className="size-5 text-primary" />
                            <h1 className="text-[17px] font-semibold tracking-tight">AI Builder</h1>
                        </div>
                        <p className="mt-1 text-[12.5px] text-fg-muted">Build and fix workflows by chatting.</p>
                        <Button variant="primary" className="mt-4 w-full justify-center" onClick={() => open(null)}><Plus className="size-4" />New chat</Button>
                    </div>
                    <nav className="flex-1 overflow-y-auto px-2 pb-3" aria-label="Chats">
                        {groups.map(([label, list]) => (
                            <div key={label} className="mb-3">
                                <p className="px-2.5 pb-1 text-[11.5px] font-medium text-fg-subtle">{label}</p>
                                <ul className="space-y-0.5">
                                    {list.map((c) => (
                                        <li key={c.id} className="group relative">
                                            <button type="button" onClick={() => open(c.id)} aria-current={activeId === c.id ? 'page' : undefined}
                                                className={cn('flex w-full items-start gap-2.5 rounded-lg px-2.5 py-2 text-left transition-colors', activeId === c.id ? 'bg-primary-soft' : 'hover:bg-surface-2')}>
                                                <MessageSquare className={cn('mt-0.5 size-4 shrink-0', activeId === c.id ? 'text-primary' : 'text-fg-subtle')} />
                                                <span className="min-w-0 flex-1 pr-5">
                                                    <span className="block truncate text-[13px] font-medium">{c.title}</span>
                                                    <span className="block truncate text-[11.5px] text-fg-subtle">{c.workflow_name ?? (c.workflow_id ? `Workflow #${c.workflow_id}` : timeAgo(c.updated_at))}</span>
                                                </span>
                                            </button>
                                            <button type="button" onClick={() => remove(c.id)} aria-label={`Delete chat ${c.title}`}
                                                className="absolute top-2 right-2 rounded p-1 text-fg-subtle opacity-0 group-hover:opacity-100 hover:text-fail focus-visible:opacity-100">
                                                <Trash2 className="size-3.5" />
                                            </button>
                                        </li>
                                    ))}
                                </ul>
                            </div>
                        ))}
                        {conversations.length === 0 && <p className="px-3 py-6 text-center text-[12.5px] text-fg-subtle">Your chats appear here.</p>}
                    </nav>
                </aside>
                <section className="flex min-w-0 flex-1 flex-col bg-bg">
                    {active && (
                        <header className="flex items-center gap-3 border-b border-line bg-surface/80 px-6 py-3 backdrop-blur">
                            <p className="min-w-0 flex-1 truncate text-[14px] font-semibold">{active.title}</p>
                            {active.workflow_id && (
                                <Link href={`/workflows/${active.workflow_id}`} className="inline-flex shrink-0 items-center gap-1.5 rounded-lg border border-line px-2.5 py-1.5 text-[12.5px] font-medium hover:border-primary hover:text-primary">
                                    <Workflow className="size-3.5" />Open workflow<ArrowUpRight className="size-3.5" />
                                </Link>
                            )}
                        </header>
                    )}
                    <ChatPanel key={key} conversationId={activeId} configured={!!ai} provider={ai} apps={apps}
                        onConversation={(c) => { setActiveId(c.id); upsert(c); }} />
                </section>
            </div>
        </AppLayout>
    );
}
