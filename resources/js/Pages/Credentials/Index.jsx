import { useEffect, useMemo, useState } from 'react';
import { router, useForm } from '@inertiajs/react';
import { ArrowLeft, CheckCircle2, CircleDashed, Copy, KeyRound, Loader2, MoreHorizontal, Pencil, Plus, RefreshCw, Trash2 } from 'lucide-react';
import AppLayout from '@/Layouts/AppLayout';
import IntegrationIcon from '@/Components/IntegrationIcon';
import { InputControl } from '@/Components/Drawer/DynamicForm';
import { Badge, Button, Card, Dialog, EmptyState, Field, Input, Menu, SearchInput, Select } from '@/Components/ui';
import { fetchIntegration } from '@/lib/integrations';
import { copy, errorMessage, timeAgo } from '@/lib/utils';
import { useCan } from '@/lib/can';

const AUTH_LABELS = { api_key: 'API key', bearer: 'Access token', basic: 'Username & password', oauth2: 'OAuth2', google_service_account: 'Service account', smtp: 'SMTP server', mysql: 'MySQL server' };
const SECRET = ['password', 'code'];

export default function Index({ credentials, integrations, redirectUri }) {
    const can = useCan();
    const preselect = new URLSearchParams(window.location.search).get('new');
    const [dialog, setDialog] = useState(() => (preselect ? { mode: 'create', integration: preselect } : null));
    const [deleting, setDeleting] = useState(null);

    return (
        <AppLayout title="Credentials" description="API keys, tokens and logins your steps use. Secrets are encrypted with your app key and never sent back to the browser."
            actions={<Button variant="primary" onClick={() => setDialog({ mode: 'create' })}><Plus className="size-4" />Add credential</Button>}>

            {credentials.length === 0 ? (
                <Card>
                    <EmptyState icon={KeyRound} title="No credentials yet" action={<Button variant="primary" onClick={() => setDialog({ mode: 'create' })}><Plus className="size-4" />Add credential</Button>}>
                        Connect an app once, then reuse it in any workflow step.
                    </EmptyState>
                </Card>
            ) : (
                <div className="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                    {credentials.map((credential) => (
                        <Card key={credential.id} className="flex items-start gap-3.5 p-4">
                            <IntegrationIcon icon={credential.icon} name={credential.integration_name} size="lg" />
                            <div className="min-w-0 flex-1">
                                <p className="truncate text-14 font-semibold">{credential.name}</p>
                                <p className="truncate text-12h text-fg-muted">{credential.integration_name}, {AUTH_LABELS[credential.auth_type] ?? credential.auth_type}</p>
                                <div className="mt-2 flex items-center gap-2">
                                    {credential.auth_type === 'oauth2' && !credential.connected
                                        ? <a href={`/credentials/${credential.id}/connect`} className="inline-flex h-7 items-center gap-1.5 rounded-md bg-primary px-2.5 text-12h font-medium text-primary-fg hover:bg-primary-hover"><CircleDashed className="size-3.5" />Connect</a>
                                        : <Badge tone="ok"><CheckCircle2 className="size-3" />Ready</Badge>}
                                    <span className="truncate text-12 text-fg-subtle">{credential.account ? `As ${credential.account}` : `Updated ${timeAgo(credential.updated_at)}`}</span>
                                </div>
                            </div>
                            <Menu
                                trigger={<Button variant="ghost" size="icon" aria-label={`Actions for ${credential.name}`}><MoreHorizontal className="size-4" /></Button>}
                                items={[
                                    { label: 'Edit', icon: Pencil, onClick: () => setDialog({ mode: 'edit', credential }) },
                                    credential.auth_type === 'oauth2' && { label: 'Reconnect', icon: RefreshCw, onClick: () => { window.location.href = `/credentials/${credential.id}/connect`; } },
                                    can['workflows.delete'] && { label: 'Delete', icon: Trash2, danger: true, onClick: () => setDeleting(credential) },
                                ]}
                            />
                        </Card>
                    ))}
                </div>
            )}

            {dialog && <CredentialDialog key={dialog.credential?.id ?? dialog.integration ?? 'new'} dialog={dialog} integrations={integrations} redirectUri={redirectUri} onClose={() => setDialog(null)} />}

            <Dialog open={!!deleting} onClose={() => setDeleting(null)} title={`Delete "${deleting?.name}"?`} description="Steps using it will fail until you pick another credential.">
                <div className="flex justify-end gap-2">
                    <Button onClick={() => setDeleting(null)}>Cancel</Button>
                    <Button variant="danger" onClick={() => router.delete(`/credentials/${deleting.id}`, { preserveScroll: true, onFinish: () => setDeleting(null) })}>Delete credential</Button>
                </div>
            </Dialog>
        </AppLayout>
    );
}

function AppPicker({ integrations, onPick }) {
    const [query, setQuery] = useState('');
    const q = query.trim().toLowerCase();
    const list = q ? integrations.filter((i) => i.name.toLowerCase().includes(q) || i.category.toLowerCase().includes(q)) : integrations.filter((i) => i.popular);

    return (
        <div>
            <SearchInput autoFocus value={query} onChange={(e) => setQuery(e.target.value)} placeholder={`Search ${integrations.length} apps`} aria-label="Search apps" />
            <p className="mt-4 mb-2 text-12h font-medium text-fg-muted">{q ? `${list.length} result${list.length === 1 ? '' : 's'}` : 'Popular apps'}</p>
            <div className="grid max-h-[46vh] grid-cols-2 gap-2 overflow-y-auto overscroll-contain pr-1 sm:grid-cols-3">
                {list.slice(0, 90).map((i) => (
                    <button key={i.key} type="button" onClick={() => onPick(i.key)} className="flex items-center gap-2.5 rounded-lg border border-line p-2.5 text-left hover:border-primary hover:bg-primary-soft/40">
                        <IntegrationIcon icon={i.icon} name={i.name} size="sm" />
                        <span className="truncate text-13 font-medium">{i.name}</span>
                    </button>
                ))}
            </div>
        </div>
    );
}

/** Field defaults go into the form state up front: what the inputs show is what gets saved. */
const defaultsOf = (auth) => Object.fromEntries((auth?.fields ?? []).filter((f) => f.default !== undefined && f.default !== null).map((f) => [f.key, f.default]));

function CredentialDialog({ dialog, integrations, redirectUri, onClose }) {
    const editing = dialog.mode === 'edit';
    const existing = dialog.credential;
    const [appKey, setAppKey] = useState(existing?.integration ?? dialog.integration ?? null);
    const [manifest, setManifest] = useState(null);
    const [loadError, setLoadError] = useState(null);
    const form = useForm({ name: existing?.name ?? '', integration: appKey ?? '', auth_type: existing?.auth_type ?? '', data: existing?.values ?? {} });

    useEffect(() => {
        if (!appKey) return;
        setManifest(null);
        fetchIntegration(appKey).then((m) => {
            setManifest(m);
            const firstAuth = m.auth.find((a) => a.type !== 'none');
            if (!editing) {
                form.setData((d) => ({ ...d, integration: appKey, auth_type: firstAuth?.type ?? '', name: d.name || `${m.name} account`, data: defaultsOf(firstAuth) }));
            } else {
                // Fields added to the app after this credential was saved start at their defaults too.
                const current = m.auth.find((a) => a.type === existing.auth_type);
                form.setData((d) => ({ ...d, data: { ...defaultsOf(current), ...Object.fromEntries(Object.entries(d.data).filter(([, v]) => v !== null && v !== undefined)) } }));
            }
        }).catch((e) => setLoadError(errorMessage(e)));
    }, [appKey]); // eslint-disable-line react-hooks/exhaustive-deps

    const authOptions = (manifest?.auth ?? []).filter((a) => a.type !== 'none');
    const auth = authOptions.find((a) => a.type === form.data.auth_type) ?? authOptions[0];

    const submit = (event) => {
        event.preventDefault();
        const options = { preserveScroll: true, onSuccess: onClose };
        editing ? form.put(`/credentials/${existing.id}`, options) : form.post('/credentials', options);
    };

    return (
        <Dialog open onClose={onClose} wide={!appKey}
            title={editing ? `Edit ${existing.name}` : appKey && manifest ? `Connect ${manifest.name}` : 'Add credential'}
            description={editing ? 'Leave secret fields blank to keep their current values.' : appKey ? 'Paste the details from the app\'s developer settings.' : 'Which app do you want to connect?'}>
            {!appKey ? (
                <AppPicker integrations={integrations} onPick={setAppKey} />
            ) : !manifest ? (
                loadError ? <p className="text-13 text-fail">{loadError}</p> : <p className="flex items-center gap-2 text-13 text-fg-muted"><Loader2 className="size-4 animate-spin" />Loading…</p>
            ) : (
                <form onSubmit={submit} className="space-y-4">
                    {!editing && (
                        <div className="flex items-center gap-3 rounded-xl bg-surface-2 p-3">
                            <IntegrationIcon icon={manifest.icon} name={manifest.name} size="md" />
                            <div className="min-w-0 flex-1">
                                <p className="text-13h font-semibold">{manifest.name}</p>
                                {manifest.docs_url && <a href={manifest.docs_url} target="_blank" rel="noreferrer" className="text-12 text-fg-muted hover:text-primary">Where do I find these details?</a>}
                            </div>
                            {!dialog.integration && <Button size="sm" variant="ghost" onClick={() => setAppKey(null)}><ArrowLeft className="size-3.5" />Change app</Button>}
                        </div>
                    )}

                    {!editing && authOptions.length > 1 && (
                        <Field label="Sign-in method">
                            {(id) => (
                                <Select id={id} value={form.data.auth_type} onChange={(e) => form.setData((d) => ({ ...d, auth_type: e.target.value, data: defaultsOf(authOptions.find((a) => a.type === e.target.value)) }))}>
                                    {authOptions.map((a) => <option key={a.type} value={a.type}>{AUTH_LABELS[a.type] ?? a.type}</option>)}
                                </Select>
                            )}
                        </Field>
                    )}

                    <Field label="Credential name" required error={form.errors.name} help="Shown when picking a credential on a step.">
                        {(id) => <Input id={id} value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} />}
                    </Field>

                    {auth?.fields.map((field) => (
                        <Field key={field.key} label={field.label} required={field.required && !(editing && SECRET.includes(field.type))} help={field.help} error={form.errors[`data.${field.key}`]}>
                            {(id) => (
                                <InputControl id={id} input={{ ...field, placeholder: editing && SECRET.includes(field.type) ? 'Unchanged' : field.placeholder }}
                                    value={form.data.data[field.key]} onChange={(value) => form.setData('data', { ...form.data.data, [field.key]: value })} />
                            )}
                        </Field>
                    ))}

                    {auth?.type === 'oauth2' && (
                        <div className="rounded-xl border border-line bg-surface-2 p-3 text-12h text-fg-muted">
                            <p className="mb-1.5">Add this redirect URL to your OAuth app, save here, then click <strong className="text-fg">Connect</strong>.</p>
                            <div className="flex items-center gap-2">
                                <code className="flex-1 truncate rounded bg-surface px-2 py-1 font-mono text-12 text-fg">{redirectUri}</code>
                                <Button size="xs" variant="ghost" onClick={() => copy(redirectUri, 'Redirect URL copied')}><Copy className="size-3.5" /></Button>
                            </div>
                        </div>
                    )}

                    <div className="flex justify-end gap-2 pt-1">
                        <Button onClick={onClose}>Cancel</Button>
                        <Button type="submit" variant="primary" loading={form.processing}>{editing ? 'Save changes' : 'Save credential'}</Button>
                    </div>
                </form>
            )}
        </Dialog>
    );
}
