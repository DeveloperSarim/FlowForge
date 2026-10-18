import { useEffect, useMemo, useRef, useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import { ArrowUpRight, MessageSquare, MoreHorizontal, PanelLeftClose, PanelLeftOpen, Pencil, Search, SquarePen, Trash2, Workflow, X } from 'lucide-react';
import AiIcon from '@/Components/AiIcon';
import AppLayout from '@/Layouts/AppLayout';
import ChatPanel from '@/Components/Assistant/ChatPanel';
import { BottomSheet, Button, Dialog, Menu } from '@/Components/ui';
import { MOD } from '@/Components/Canvas/CanvasMenu';
import { cn, errorMessage, toast } from '@/lib/utils';

const COLLAPSED = 'ff:chats-collapsed';

/*
 * The chat list outlives Back/Forward: Inertia restores the page with the props it had then, so chats created or
 * renamed since would vanish. Merge what the server sent with what this visit already knows (the newest copy of
 * each chat wins, deleted chats stay deleted).
 */
let chatCache = null;
const deletedChats = new Set();
function mergeChats(server) {
    const byId = new Map();
    [...server, ...(chatCache ?? [])].forEach((c) => {
        if (deletedChats.has(c.id)) return;
        const known = byId.get(c.id);
        if (!known || new Date(c.updated_at) >= new Date(known.updated_at)) byId.set(c.id, c);
    });
    return [...byId.values()].sort((a, b) => new Date(b.updated_at) - new Date(a.updated_at));
}

/** Today / Yesterday / Previous 7 days / Previous 30 days / Older, newest first, filtered by the search. */
function groupChats(conversations, query) {
    const q = query.trim().toLowerCase();
    const startOfToday = new Date().setHours(0, 0, 0, 0);
    const day = 864e5;
    const groups = [['Today', []], ['Yesterday', []], ['Previous 7 days', []], ['Previous 30 days', []], ['Older', []]];
    conversations
        .filter((c) => !q || c.title.toLowerCase().includes(q) || (c.workflow_name ?? '').toLowerCase().includes(q))
        .forEach((c) => {
            const t = new Date(c.updated_at).getTime();
            const at = t >= startOfToday ? 0 : t >= startOfToday - day ? 1 : t >= startOfToday - 7 * day ? 2 : t >= startOfToday - 30 * day ? 3 : 4;
            groups[at][1].push(c);
        });
    return groups.filter(([, list]) => list.length);
}

/** One chat in the list: opens on click; "⋯" renames (in place) or deletes it. */
function ChatItem({ chat, active, onOpen, onRename, onDelete }) {
    const [editing, setEditing] = useState(false);
    const [title, setTitle] = useState(chat.title);
    const save = () => {
        setEditing(false);
        if (title.trim() && title.trim() !== chat.title) onRename(chat.id, title.trim());
        else setTitle(chat.title);
    };

    if (editing) {
        return (
            <li className="px-1">
                <input autoFocus value={title} onChange={(e) => setTitle(e.target.value)} onBlur={save} maxLength={120} aria-label="Chat name"
                    onKeyDown={(e) => { if (e.key === 'Enter') save(); if (e.key === 'Escape') { setTitle(chat.title); setEditing(false); } }}
                    className="h-9 w-full rounded-lg border border-primary bg-surface px-2.5 text-base outline-none ring-[3px] ring-primary/15 sm:text-13" />
            </li>
        );
    }
    return (
        <li className="group relative">
            <button type="button" onClick={() => onOpen(chat.id)} aria-current={active ? 'page' : undefined} title={chat.workflow_name ? `${chat.title}\n${chat.workflow_name}` : chat.title}
                className={cn('flex w-full items-center gap-2 rounded-lg py-2 pr-9 pl-2.5 text-left text-13 transition-colors',
                    active ? 'bg-surface-3 font-medium text-fg' : 'text-fg-muted hover:bg-surface-2 hover:text-fg active:bg-surface-3')}>
                <span className="min-w-0 flex-1 truncate">{chat.title}</span>
                {chat.workflow_id && <Workflow className="size-3.5 shrink-0 text-fg-subtle" aria-label="Linked to a workflow" />}
            </button>
            <div className={cn('absolute top-1/2 right-1 -translate-y-1/2', active ? 'opacity-100' : 'opacity-0 group-hover:opacity-100 focus-within:opacity-100 pointer-coarse:opacity-100')}>
                <Menu align="right" items={[
                    { label: 'Rename', icon: Pencil, onClick: () => setEditing(true) },
                    { label: 'Delete', icon: Trash2, danger: true, onClick: () => onDelete(chat) },
                ]} trigger={(
                    <button type="button" aria-label={`Options for ${chat.title}`} className="flex size-7 items-center justify-center rounded-md text-fg-subtle hover:bg-surface-3 hover:text-fg">
                        <MoreHorizontal className="size-4" />
                    </button>
                )} />
            </div>
        </li>
    );
}

/** The chat history: search, grouped by date. Used by the desktop sidebar and the phone sheet. */
function ChatList({ conversations, activeId, onOpen, onRename, onDelete }) {
    const [query, setQuery] = useState('');
    const groups = useMemo(() => groupChats(conversations, query), [conversations, query]);
    return (
        <>
            {conversations.length > 4 && (
                <div className="relative px-3 pb-2">
                    <Search className="pointer-events-none absolute top-1/2 left-5.5 size-4 -translate-y-1/2 text-fg-subtle" />
                    <input type="search" value={query} onChange={(e) => setQuery(e.target.value)} placeholder="Search chats" aria-label="Search chats"
                        className="h-9 w-full rounded-lg bg-surface-2 pr-3 pl-8.5 text-base outline-none placeholder:text-fg-subtle focus:ring-[3px] focus:ring-primary/15 sm:text-13" />
                </div>
            )}
            <nav className="min-h-0 flex-1 overflow-y-auto px-2 pb-4" aria-label="Chats">
                {groups.map(([label, list]) => (
                    <section key={label} className="mb-4">
                        <h3 className="px-2.5 pb-1 text-11h font-semibold text-fg-subtle">{label}</h3>
                        <ul className="space-y-px">
                            {list.map((c) => <ChatItem key={`${c.id}-${c.title}`} chat={c} active={activeId === c.id} onOpen={onOpen} onRename={onRename} onDelete={onDelete} />)}
                        </ul>
                    </section>
                ))}
                {conversations.length === 0 && (
                    <div className="px-4 py-10 text-center">
                        <MessageSquare className="mx-auto size-5 text-fg-subtle" />
                        <p className="mt-2 text-12h text-fg-subtle">Your chats appear here.</p>
                    </div>
                )}
                {conversations.length > 0 && groups.length === 0 && <p className="px-3 py-6 text-center text-12h text-fg-subtle">No chat matches "{query}".</p>}
            </nav>
        </>
    );
}

export default function Assistant({ conversations: initial, active, ai, apps }) {
    const [conversations, setChats] = useState(() => (chatCache = mergeChats(initial)));
    const setConversations = (update) => setChats((list) => (chatCache = typeof update === 'function' ? update(list) : update));
    const [collapsed, setCollapsed] = useState(() => { try { return localStorage.getItem(COLLAPSED) === '1'; } catch { return false; } });
    const [listOpen, setListOpen] = useState(false);
    const [deleting, setDeleting] = useState(null);
    const [titleDraft, setTitleDraft] = useState(null); // renaming the open chat from its header
    const activeChat = conversations.find((c) => c.id === active) ?? null;

    // The chat panel is remounted for a new chat or another chat, but not when its own first message creates the
    // chat (the address changes to /assistant/c/{id} while the answer is still streaming in).
    const created = useRef(null);
    const [fresh, setFresh] = useState(0);
    const panelKey = active && active !== created.current ? `c${active}` : `new${fresh}`;
    useEffect(() => {
        if (active === null && created.current !== null) { created.current = null; setFresh((n) => n + 1); }
    }, [active]);

    const go = (id, replace = false) => router[replace ? 'replace' : 'push']({
        url: id ? `/assistant/c/${id}` : '/assistant', props: (p) => ({ ...p, active: id }), preserveState: true, preserveScroll: true,
    });
    const open = (id) => { setListOpen(false); if (id !== active) go(id); };
    const newChat = () => { setListOpen(false); created.current = null; setFresh((n) => n + 1); if (active !== null) go(null); };
    const toggleCollapsed = () => setCollapsed((c) => { try { localStorage.setItem(COLLAPSED, c ? '0' : '1'); } catch { /* not remembered */ } return !c; });

    const upsert = (c) => setConversations((list) => {
        const previous = list.find((x) => x.id === c.id);
        return [{ id: c.id, title: c.title, workflow_id: c.workflow_id, workflow_name: previous?.workflow_name ?? null, updated_at: new Date().toISOString() }, ...list.filter((x) => x.id !== c.id)];
    });
    const onConversation = (c) => {
        upsert(c);
        if (c.id !== active) { created.current = c.id; go(c.id, true); }
    };

    const rename = async (id, title) => {
        setConversations((list) => list.map((c) => (c.id === id ? { ...c, title } : c)));
        try {
            await window.axios.patch(`/assistant/conversations/${id}`, { title });
        } catch (error) {
            toast(errorMessage(error), 'error');
        }
    };
    const remove = async () => {
        const { id } = deleting;
        setDeleting(null);
        try {
            await window.axios.delete(`/assistant/conversations/${id}`);
            deletedChats.add(id);
            setConversations((list) => list.filter((c) => c.id !== id));
            if (active === id) newChat();
            toast('Chat deleted.', 'success');
        } catch (error) {
            toast(errorMessage(error), 'error');
        }
    };

    // ⌘/Ctrl+Shift+O: a new chat, as in ChatGPT.
    useEffect(() => {
        const onKey = (e) => {
            if ((e.metaKey || e.ctrlKey) && e.shiftKey && e.key.toLowerCase() === 'o') { e.preventDefault(); newChat(); }
        };
        window.addEventListener('keydown', onKey);
        return () => window.removeEventListener('keydown', onKey);
    });

    // The open chat's name; renaming turns it into a field (Enter or leaving it saves, Escape cancels).
    const title = (className) => (titleDraft !== null && activeChat ? (
        <input autoFocus value={titleDraft} onChange={(e) => setTitleDraft(e.target.value)} maxLength={120} aria-label="Chat name"
            onBlur={() => { if (titleDraft.trim() && titleDraft.trim() !== activeChat.title) rename(activeChat.id, titleDraft.trim()); setTitleDraft(null); }}
            onKeyDown={(e) => { if (e.key === 'Enter') e.currentTarget.blur(); if (e.key === 'Escape') setTitleDraft(null); }}
            className="h-9 min-w-0 flex-1 rounded-lg border border-primary bg-surface px-2.5 text-base font-semibold outline-none ring-[3px] ring-primary/15 sm:text-14" />
    ) : (
        <button type="button" onDoubleClick={() => activeChat && setTitleDraft(activeChat.title)} title={activeChat ? 'Double-click to rename' : undefined}
            className={cn('min-w-0 flex-1 cursor-default truncate font-semibold', className)}>{activeChat?.title ?? 'New chat'}</button>
    ));

    const list = <ChatList conversations={conversations} activeId={active} onOpen={open} onRename={rename} onDelete={setDeleting} />;
    const chatMenu = activeChat && (
        <Menu align="right" items={[
            { label: 'Rename', icon: Pencil, onClick: () => setTitleDraft(activeChat.title) },
            { label: 'Delete', icon: Trash2, danger: true, onClick: () => setDeleting(activeChat) },
        ]} trigger={<Button variant="ghost" size="icon" aria-label="Chat options"><MoreHorizontal className="size-4" /></Button>} />
    );

    return (
        <AppLayout wide title={null}>
            <Head title={activeChat ? `${activeChat.title} · AI Builder` : 'AI Builder'} />
            {/* Phones: fills the space between the top bar and the bottom tab bar; desktop: full height. */}
            <div className="flex max-lg:fixed max-lg:inset-x-0 max-lg:top-[calc(max(0.625rem,env(safe-area-inset-top))+2.875rem+1px)] max-lg:bottom-[calc(3.5rem+1px+env(safe-area-inset-bottom))] lg:-mx-8 lg:-my-8 lg:h-screen">
                {/* Desktop: the chat history, collapsible */}
                <aside className={cn('hidden shrink-0 flex-col border-r border-line bg-surface transition-[width] duration-200 md:flex', collapsed ? 'w-0 overflow-hidden border-r-0' : 'w-72')} aria-hidden={collapsed}>
                    <div className="flex items-center gap-1 px-3 pt-4 pb-3">
                        <AiIcon className="ml-1 size-5 text-primary" />
                        <h1 className="flex-1 text-16 font-semibold tracking-tight">AI Builder</h1>
                        <Button variant="ghost" size="icon" onClick={toggleCollapsed} aria-label="Hide chats" title="Hide chats"><PanelLeftClose className="size-4" /></Button>
                    </div>
                    <div className="px-3 pb-3">
                        <button type="button" onClick={newChat}
                            className="ff-press flex h-10 w-full items-center gap-2.5 rounded-xl border border-line bg-surface px-3 text-13h font-medium shadow-card transition-[background-color,border-color,scale] hover:border-line-strong hover:bg-surface-2 motion-safe:active:scale-[0.98]">
                            <SquarePen className="size-4 text-primary" />
                            <span className="flex-1 text-left">New chat</span>
                            <span className="text-11h font-normal text-fg-subtle">{MOD}⇧O</span>
                        </button>
                    </div>
                    {list}
                </aside>

                <section className="relative flex min-w-0 flex-1 flex-col bg-bg">
                    {/* Phones */}
                    <header className="flex items-center gap-1 border-b border-line bg-surface px-2 py-1.5 md:hidden">
                        <Button size="icon" variant="ghost" onClick={() => setListOpen(true)} aria-label="Chats"><MessageSquare className="size-4" /></Button>
                        {title('px-1 text-center text-13h')}
                        {chatMenu}
                        <Button size="icon" variant="ghost" onClick={newChat} aria-label="New chat"><SquarePen className="size-4" /></Button>
                    </header>
                    {/* Larger screens */}
                    <header className={cn('ff-glass hidden min-h-14 items-center gap-2 border-b px-4 md:flex', activeChat ? 'border-line/70' : 'border-transparent')}>
                        {collapsed && (
                            <>
                                <Button variant="ghost" size="icon" onClick={toggleCollapsed} aria-label="Show chats" title="Show chats"><PanelLeftOpen className="size-4" /></Button>
                                <Button variant="ghost" size="icon" onClick={newChat} aria-label="New chat" title={`New chat (${MOD}⇧O)`}><SquarePen className="size-4" /></Button>
                            </>
                        )}
                        {activeChat ? title('px-2 text-left text-14') : <span className="flex-1" />}
                        {activeChat?.workflow_id && (
                            <Link href={`/workflows/${activeChat.workflow_id}`} className="inline-flex shrink-0 items-center gap-1.5 rounded-lg border border-line px-2.5 py-1.5 text-12h font-medium transition-colors hover:border-primary hover:text-primary">
                                <Workflow className="size-3.5" /><span className="max-w-48 truncate">{activeChat.workflow_name ?? 'Open workflow'}</span><ArrowUpRight className="size-3.5" />
                            </Link>
                        )}
                        {chatMenu}
                    </header>
                    <ChatPanel key={panelKey} conversationId={active} configured={!!ai} provider={ai} apps={apps} onConversation={onConversation} />
                </section>
            </div>

            <BottomSheet open={listOpen} onClose={() => setListOpen(false)} label="Chats" className="md:hidden">
                <div className="flex items-center justify-between px-4 pt-1 pb-3">
                    <p className="text-16 font-semibold">Chats</p>
                    <Button size="sm" variant="primary" onClick={newChat}><SquarePen className="size-4" />New chat</Button>
                </div>
                <div className="flex max-h-[70dvh] min-h-0 flex-col">{list}</div>
            </BottomSheet>

            <Dialog open={!!deleting} onClose={() => setDeleting(null)} title="Delete chat?" description={deleting ? `"${deleting.title}" and its messages are deleted. Workflows it built stay.` : ''}>
                <div className="flex justify-end gap-2">
                    <Button onClick={() => setDeleting(null)}>Cancel</Button>
                    <Button variant="danger" onClick={remove}><Trash2 className="size-4" />Delete</Button>
                </div>
            </Dialog>
        </AppLayout>
    );
}
