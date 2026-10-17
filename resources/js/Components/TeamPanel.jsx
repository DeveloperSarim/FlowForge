import { useState } from 'react';
import { usePage } from '@inertiajs/react';
import { Copy, Crown, Link2, MoreHorizontal, Pencil, ShieldCheck, Trash2, UserPlus } from 'lucide-react';
import { Avatar, Badge, Button, Card, Dialog, Field, Input, Menu } from '@/Components/ui';
import { cn, copy, errorMessage, toast } from '@/lib/utils';

const ROLE_LABEL = { owner: 'Owner', admin: 'Admin', member: 'Member' };
const WORKFLOW_LEVELS = ['workflows.view', 'workflows.edit', 'workflows.delete'];

/** "View, edit" style summary of a member's access. */
function summary(member, labels) {
    if (member.role !== 'member') return member.role === 'owner' ? 'Everything, can\'t be removed' : 'Everything, including settings and the team';
    const parts = [];
    const p = member.permissions;
    if (p.includes('workflows.delete')) parts.push('View, edit and delete workflows');
    else if (p.includes('workflows.edit')) parts.push('View and edit workflows');
    else if (p.includes('workflows.view')) parts.push('View workflows');
    if (p.includes('ai')) parts.push('AI builder');
    return parts.join(', ') || labels['workflows.view'];
}

function AccessForm({ value, onChange }) {
    const set = (patch) => onChange({ ...value, ...patch });
    const level = WORKFLOW_LEVELS.filter((p) => value.permissions.includes(p)).at(-1) ?? null;
    const setLevel = (next) => set({ permissions: [...WORKFLOW_LEVELS.slice(0, WORKFLOW_LEVELS.indexOf(next) + 1), ...value.permissions.filter((p) => !WORKFLOW_LEVELS.includes(p))] });
    const toggleAi = () => set({ permissions: value.permissions.includes('ai') ? value.permissions.filter((p) => p !== 'ai') : [...value.permissions, 'ai'] });

    return (
        <div className="space-y-4">
            <Field label="Role">
                <div className="grid gap-2 sm:grid-cols-2">
                    {[['member', 'Member', 'Only what you choose below'], ['admin', 'Admin', 'Everything, including settings and the team']].map(([role, label, hint]) => (
                        <button key={role} type="button" onClick={() => set({ role })} aria-pressed={value.role === role}
                            className={cn('rounded-xl border-2 p-3 text-left transition-colors', value.role === role ? 'border-primary bg-primary-soft/40' : 'border-line hover:border-line-strong')}>
                            <span className="block text-13h font-medium">{label}</span>
                            <span className="block text-12 text-fg-muted">{hint}</span>
                        </button>
                    ))}
                </div>
            </Field>

            {value.role === 'member' && (
                <>
                    <Field label="Workflows" help="Editing includes using and adding credentials (their secrets are never shown). Delete also covers credentials.">
                        <div className="grid grid-cols-4 gap-1.5 rounded-xl bg-surface-2 p-1" role="radiogroup" aria-label="Workflow access">
                            {[[null, 'No access'], ['workflows.view', 'View'], ['workflows.edit', 'View + edit'], ['workflows.delete', 'Edit + delete']].map(([key, label]) => (
                                <button key={label} type="button" role="radio" aria-checked={level === key} onClick={() => (key ? setLevel(key) : set({ permissions: value.permissions.filter((p) => !WORKFLOW_LEVELS.includes(p)) }))}
                                    className={cn('rounded-lg px-2 py-2 text-12h font-medium transition-colors', level === key ? 'bg-surface text-fg shadow-card' : 'text-fg-muted hover:text-fg')}>
                                    {label}
                                </button>
                            ))}
                        </div>
                    </Field>
                    <label className="flex cursor-pointer items-start gap-3 rounded-xl border border-line p-3">
                        <input type="checkbox" className="mt-0.5 size-4 accent-[var(--primary)]" checked={value.permissions.includes('ai')} onChange={toggleAi} />
                        <span>
                            <span className="block text-13h font-medium">AI builder</span>
                            <span className="block text-12 text-fg-muted">Chat to build and fix workflows. The AI can only do what this person's workflow access allows.</span>
                        </span>
                    </label>
                </>
            )}
        </div>
    );
}

function MemberDialog({ member, onClose, onSaved }) {
    const [value, setValue] = useState({ email: '', name: '', role: member?.role ?? 'member', permissions: member?.permissions ?? ['workflows.view'] });
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState(null);
    const [invite, setInvite] = useState(null);

    const save = async (e) => {
        e.preventDefault();
        setBusy(true);
        setError(null);
        try {
            const { data } = member
                ? await window.axios.put(`/settings/team/${member.id}`, { role: value.role, permissions: value.permissions })
                : await window.axios.post('/settings/team', value);
            onSaved(data.members);
            if (member) { toast('Access updated.', 'success'); onClose(); } else setInvite(data);
        } catch (err) {
            setError(errorMessage(err));
        } finally {
            setBusy(false);
        }
    };

    if (invite) {
        return (
            <Dialog open onClose={onClose} title="Invite created" description={invite.emailed ? `We emailed ${value.email} a link to join.` : 'Email isn\'t set up, so send this link yourself (WhatsApp, Slack…).'}>
                <InviteLink url={invite.invite_url} />
                <div className="mt-5 flex justify-end"><Button variant="primary" onClick={onClose}>Done</Button></div>
            </Dialog>
        );
    }

    return (
        <Dialog open onClose={onClose} title={member ? `Access for ${member.name}` : 'Invite a team member'} description={member ? member.email : 'They get a link to choose their own password.'}>
            <form onSubmit={save} className="space-y-4">
                {!member && (
                    <div className="grid gap-3 sm:grid-cols-2">
                        <Field label="Email" required>{(id) => <Input id={id} type="email" required autoFocus value={value.email} onChange={(e) => setValue({ ...value, email: e.target.value })} />}</Field>
                        <Field label="Name">{(id) => <Input id={id} placeholder="Optional" value={value.name} onChange={(e) => setValue({ ...value, name: e.target.value })} />}</Field>
                    </div>
                )}
                <AccessForm value={value} onChange={setValue} />
                {error && <p className="text-13 text-fail">{error}</p>}
                <div className="flex justify-end gap-2 pt-1">
                    <Button onClick={onClose}>Cancel</Button>
                    <Button type="submit" variant="primary" loading={busy}>{member ? 'Save access' : 'Send invite'}</Button>
                </div>
            </form>
        </Dialog>
    );
}

function InviteLink({ url }) {
    return (
        <div className="flex items-center gap-2 rounded-lg border border-line bg-surface-2 p-2">
            <Link2 className="size-4 shrink-0 text-fg-subtle" />
            <code className="min-w-0 flex-1 truncate font-mono text-12">{url}</code>
            <Button size="sm" variant="outline" onClick={() => copy(url, 'Invite link copied')}><Copy className="size-3.5" />Copy</Button>
        </div>
    );
}

/** Settings → Team (admins only). */
export default function TeamPanel({ team }) {
    const { auth } = usePage().props;
    const [members, setMembers] = useState(team.members);
    const [dialog, setDialog] = useState(null);
    const [link, setLink] = useState(null);
    const [removing, setRemoving] = useState(null);

    const act = async (fn, done) => {
        try { const { data } = await fn(); setMembers(data.members); done?.(data); } catch (err) { toast(errorMessage(err), 'error'); }
    };

    return (
        <Card className="p-6">
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h2 className="text-16 font-semibold">Team</h2>
                    <p className="mt-0.5 max-w-xl text-13 text-fg-muted">Invite people and choose what they can do. Everyone shares the same workflows and credentials; AI chats are private to each person.</p>
                </div>
                <Button variant="primary" onClick={() => setDialog({})}><UserPlus className="size-4" />Invite member</Button>
            </div>

            {link && <div className="mt-4"><p className="mb-1.5 text-12h text-fg-muted">New invite link for {link.email}{link.emailed ? ' (also emailed)' : ''}:</p><InviteLink url={link.url} /></div>}

            <ul className="mt-5 divide-y divide-line border-t border-line">
                {members.map((m) => {
                    const self = m.id === auth.user.id;
                    return (
                        <li key={m.id} className="flex items-center gap-3 py-3">
                            <Avatar name={m.name} />
                            <div className="min-w-0 flex-1">
                                <p className="flex items-center gap-2 truncate text-13h font-medium">
                                    {m.name}{self && <span className="text-fg-subtle">(you)</span>}
                                    {m.invited && <Badge tone={m.invite_expired ? 'fail' : 'warn'}>{m.invite_expired ? 'Invite expired' : 'Invited'}</Badge>}
                                </p>
                                <p className="truncate text-12h text-fg-muted">{m.email}</p>
                                <p className="truncate text-12 text-fg-subtle sm:hidden">{ROLE_LABEL[m.role]}: {summary(m, team.permissions)}</p>
                            </div>
                            <div className="hidden min-w-0 text-right sm:block">
                                <p className="flex items-center justify-end gap-1.5 text-13 font-medium">
                                    {m.role === 'owner' ? <Crown className="size-3.5 text-primary" /> : m.role === 'admin' ? <ShieldCheck className="size-3.5 text-info" /> : null}
                                    {ROLE_LABEL[m.role]}
                                </p>
                                <p className="max-w-64 truncate text-12 text-fg-subtle">{summary(m, team.permissions)}</p>
                            </div>
                            {m.role !== 'owner' && !self ? (
                                <Menu trigger={<Button variant="ghost" size="icon" aria-label={`Actions for ${m.name}`}><MoreHorizontal className="size-4" /></Button>}
                                    items={[
                                        { label: 'Change access', icon: Pencil, onClick: () => setDialog({ member: m }) },
                                        m.invited && { label: 'New invite link', icon: Link2, onClick: () => act(() => window.axios.post(`/settings/team/${m.id}/invite`), (d) => setLink({ email: m.email, url: d.invite_url, emailed: d.emailed })) },
                                        { label: 'Remove', icon: Trash2, danger: true, onClick: () => setRemoving(m) },
                                    ]} />
                            ) : <span className="size-8" />}
                        </li>
                    );
                })}
            </ul>

            {dialog && <MemberDialog member={dialog.member} onClose={() => setDialog(null)} onSaved={setMembers} />}
            <Dialog open={!!removing} onClose={() => setRemoving(null)} title={`Remove ${removing?.name}?`} description="They lose access straight away. Workflows they built stay; their AI chats are deleted.">
                <div className="flex justify-end gap-2">
                    <Button onClick={() => setRemoving(null)}>Cancel</Button>
                    <Button variant="danger" onClick={() => act(() => window.axios.delete(`/settings/team/${removing.id}`), () => { toast(`${removing.name} was removed.`, 'success'); setRemoving(null); })}>Remove</Button>
                </div>
            </Dialog>
        </Card>
    );
}
