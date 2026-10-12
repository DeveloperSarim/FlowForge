import { useEffect, useMemo, useRef, useState } from 'react';
import { AlertCircle, ArrowLeft, BookOpen, Copy, ExternalLink, Loader2, MousePointerClick, Play, RefreshCw, Trash2, Zap } from 'lucide-react';
import DataView from '@/Components/DataView';
import IntegrationIcon from '@/Components/IntegrationIcon';
import DynamicForm, { InsertTargetContext } from '@/Components/Drawer/DynamicForm';
import { Badge, Button, Combobox, ConfirmButton, Field, Select, StatusBadge, Tabs } from '@/Components/ui';
import { useIntegration } from '@/lib/integrations';
import { copy, duration, timeAgo, toast } from '@/lib/utils';

const CORE_TRIGGERS = ['manual:manual', 'webhook:catch', 'schedule:cron', 'error_trigger:workflow_failed'];
const POPULAR_TRIGGER_APPS = ['gmail', 'google_sheets', 'slack', 'stripe', 'shopify', 'typeform', 'hubspot', 'github', 'telegram', 'calendly', 'woocommerce', 'google_forms', 'airtable', 'notion', 'jotform', 'trello', 'pipedrive', 'asana', 'zendesk'];

/** Every trigger in the catalog as Combobox options, with the built-ins and popular apps pinned on top. */
function useTriggerOptions(catalog) {
    return useMemo(() => {
        const options = catalog.filter((i) => i.triggers.length).flatMap((i) => i.triggers.map((t) => ({
            value: `${i.key}:${t.key}`,
            label: t.name,
            group: i.name,
            keywords: `${i.key} ${i.category} ${t.type}`,
            icon: <IntegrationIcon icon={i.icon} name={i.name} size="xs" />,
        })));
        const byKey = Object.fromEntries(catalog.map((i) => [i.key, i]));
        const popular = POPULAR_TRIGGER_APPS.filter((key) => byKey[key]?.triggers.length).map((key) => `${key}:${byKey[key].triggers[0].key}`).slice(0, 10);
        return [options, [...CORE_TRIGGERS, ...popular]];
    }, [catalog]);
}

const AUTH_LABELS = { api_key: 'API key', bearer: 'Token', basic: 'Username & password', oauth2: 'OAuth2', google_service_account: 'Service account', smtp: 'SMTP server', mysql: 'MySQL server' };

function Pane({ title, aside, children, className }) {
    return (
        <section className={`flex min-h-0 flex-col ${className ?? ''}`}>
            <div className="flex min-h-12 items-center justify-between gap-2 border-b border-line px-4 py-2">
                <h3 className="text-[12px] font-semibold tracking-wide text-fg-muted">{title}</h3>
                {aside}
            </div>
            <div className="min-h-0 flex-1 overflow-y-auto p-4">{children}</div>
        </section>
    );
}

function TriggerInfo({ spec, workflow }) {
    const state = workflow.trigger_state ?? {};
    if (spec?.type === 'webhook') {
        return (
            <div className="space-y-3">
                <Field label="Webhook URL">
                    <div className="flex items-center gap-2 rounded-lg border border-line bg-surface p-2">
                        <code className="flex-1 truncate font-mono text-[12px]">{workflow.webhook_url}</code>
                        <Button size="xs" variant="ghost" onClick={() => copy(workflow.webhook_url, 'Webhook URL copied')}><Copy className="size-3.5" /></Button>
                    </div>
                </Field>
                <p className="text-[12.5px] leading-relaxed text-fg-muted">
                    {workflow.is_active
                        ? 'Live: every request queues a run, picked up on the next scheduler tick.'
                        : state.sample_at
                            ? `Test data captured ${timeAgo(state.sample_at)}. While the workflow is inactive, requests are saved as test data instead of running.`
                            : 'While the workflow is inactive, send a request to this URL to capture realistic test data.'}
                </p>
            </div>
        );
    }
    if (spec?.type === 'polling') {
        return (
            <div className="space-y-2 text-[12.5px] text-fg-muted">
                <p>{state.last_polled_at ? `Last checked ${timeAgo(state.last_polled_at)}.` : 'Not checked yet.'} The first check only records existing items; later checks start one run per new item.</p>
                {state.last_error && <p className="flex gap-1.5 text-fail"><AlertCircle className="mt-0.5 size-3.5 shrink-0" />{state.last_error}</p>}
            </div>
        );
    }
    if (spec?.type === 'schedule') return <p className="text-[12.5px] text-fg-muted">Runs while the workflow is active. The scheduler checks it once a minute.</p>;
    return <p className="text-[12.5px] text-fg-muted">Runs when you click Execute workflow, using the test payload from the settings.</p>;
}

/**
 * n8n-style node details view: INPUT (upstream data, drag or click fields to map) | PARAMETERS | OUTPUT.
 */
export default function NodeDetails({ node, catalog, operators, workflow, upstream, runStep, executing, onChange, onExecute, onDelete, onClose, readOnly = false }) {
    const data = node.data;
    const isTrigger = node.type === 'trigger';
    const { integration, loading, error, reload } = useIntegration(data.integration);
    const [triggerOptions, pinnedTriggers] = useTriggerOptions(catalog);
    const [target, setTarget] = useState(null);
    const [inputFrom, setInputFrom] = useState(upstream[upstream.length - 1]?.id ?? null);
    // Phones show one pane at a time; after a run, jump to its output.
    const [pane, setPane] = useState('params');
    const wasExecuting = useRef(executing);
    useEffect(() => {
        if (wasExecuting.current && !executing) setPane('output');
        wasExecuting.current = executing;
    }, [executing]);
    const hide = (name) => (pane === name ? '' : 'max-lg:hidden');

    useEffect(() => {
        const handler = (e) => e.key === 'Escape' && !e.defaultPrevented && onClose();
        window.addEventListener('keydown', handler);
        return () => window.removeEventListener('keydown', handler);
    }, [onClose]);

    const operation = useMemo(() => {
        if (!integration) return null;
        return isTrigger ? integration.triggers.find((t) => t.key === data.trigger) : integration.actions.find((a) => a.key === data.action);
    }, [integration, isTrigger, data.trigger, data.action]);

    const authTypes = (integration?.auth ?? []).map((a) => a.type).filter((t) => t !== 'none');
    const credentials = (integration?.credentials ?? []).filter((c) => authTypes.includes(c.auth_type));
    const credentialOptional = (integration?.auth ?? []).some((a) => a.type === 'none');
    const source = upstream.find((u) => u.id === inputFrom) ?? upstream[upstream.length - 1];
    const setConfig = (key, value) => onChange({ config: { ...data.config, [key]: value } });
    const insert = (token) => (target ? target.insert(token) : toast('Click into a field first, then pick a value (or drag it in).', 'error'));

    return (
        <InsertTargetContext.Provider value={{ target, setTarget }}>
            <div className="fixed inset-0 z-50 flex bg-black/40 backdrop-blur-[2px] sm:p-3 lg:p-6" onMouseDown={(e) => e.target === e.currentTarget && onClose()}>
                <div role="dialog" aria-modal="true" aria-label={`${data.label ?? 'Step'} settings`} className="animate-pop-in flex min-h-0 w-full flex-col overflow-hidden border-line bg-surface shadow-pop sm:rounded-2xl sm:border">
                    <header className="flex items-center gap-2 border-b border-line px-2 pt-[max(0.75rem,env(safe-area-inset-top))] pb-3 sm:gap-3 sm:px-4 sm:pt-3">
                        <Button variant="ghost" size="sm" onClick={onClose}><ArrowLeft className="size-4" /><span className="hidden sm:inline">Back to canvas</span></Button>
                        <span className="h-6 w-px bg-line" />
                        <IntegrationIcon icon={integration?.icon} name={integration?.name} size="md" />
                        <div className="min-w-0 flex-1">
                            <input value={data.label ?? ''} onChange={(e) => onChange({ label: e.target.value })} readOnly={readOnly} placeholder={operation?.name ?? 'Untitled step'} aria-label="Step name"
                                className="w-full max-w-md truncate rounded bg-transparent px-1 text-base font-semibold sm:text-[15px] outline-none placeholder:text-fg-subtle hover:bg-surface-2 focus:bg-surface-2" />
                            <p className="px-1 text-[12px] text-fg-subtle">{integration?.name ?? data.integration}{operation ? `: ${operation.name}` : ''} <code className="ml-1 font-mono text-[11px]">steps.{node.id}</code></p>
                        </div>
                        {integration?.docs_url && <a href={integration.docs_url} target="_blank" rel="noreferrer" className="hidden items-center gap-1.5 rounded-lg px-2.5 py-1.5 text-[13px] text-fg-muted hover:bg-surface-2 hover:text-fg md:inline-flex"><BookOpen className="size-4" />Docs</a>}
                        {readOnly ? <span className="rounded-lg bg-surface-2 px-3 py-1.5 text-[12.5px] font-medium text-fg-muted">View only</span>
                            : <Button variant="primary" onClick={() => onExecute(node.id)} loading={executing}>{!executing && <Play className="size-3.5" fill="currentColor" />}<span className="sm:hidden">Run</span><span className="hidden sm:inline">Execute step</span></Button>}
                    </header>
                    <div className="border-b border-line p-2 lg:hidden">
                        <Tabs value={pane} onChange={setPane} className="grid w-full grid-cols-3"
                            items={[{ value: 'input', label: isTrigger ? 'Trigger' : 'Input' }, { value: 'params', label: 'Settings' }, { value: 'output', label: 'Output' }]} />
                    </div>

                    <div className="grid min-h-0 flex-1 grid-cols-1 grid-rows-[minmax(0,1fr)] overflow-hidden lg:grid-cols-[minmax(0,1fr)_minmax(400px,1.15fr)_minmax(0,1fr)]">
                        <Pane title={isTrigger ? 'TRIGGER' : 'INPUT'} className={`border-line bg-surface-2/40 lg:border-r ${hide('input')}`}
                            aside={!isTrigger && upstream.length > 1 && (
                                <Select value={source?.id ?? ''} onChange={(e) => setInputFrom(e.target.value)} className="h-8 w-44 text-[12.5px]" aria-label="Input from step">
                                    {upstream.map((u) => <option key={u.id} value={u.id}>{u.label}</option>)}
                                </Select>
                            )}>
                            {isTrigger ? (
                                <TriggerInfo spec={operation} workflow={workflow} />
                            ) : !source ? (
                                <div className="flex flex-col items-center py-10 text-center">
                                    <MousePointerClick className="mb-3 size-6 text-fg-subtle" />
                                    <p className="text-[13px] font-medium">No input connected</p>
                                    <p className="mt-1 text-[12.5px] text-fg-muted">Connect an earlier step to this one on the canvas.</p>
                                </div>
                            ) : source.sample === undefined ? (
                                <div className="flex flex-col items-center py-10 text-center">
                                    <IntegrationIcon icon={source.icon} name={source.integrationName} size="lg" />
                                    <p className="mt-3 text-[13px] font-medium">No data from "{source.label}" yet</p>
                                    <p className="mt-1 max-w-60 text-[12.5px] text-fg-muted">Execute it to see its fields here, then drag them into the settings.</p>
                                    <Button size="sm" className="mt-4" onClick={() => onExecute(source.id)} loading={executing}><Play className="size-3.5" />Execute previous step</Button>
                                </div>
                            ) : (
                                <>
                                    <p className="mb-3 flex items-center gap-2 text-[12.5px] text-fg-muted">
                                        <IntegrationIcon icon={source.icon} name={source.integrationName} size="xs" />
                                        Drag a field into a setting, or click a field to insert it at the cursor.
                                    </p>
                                    <DataView value={source.sample} base={`steps.${source.id}`} onPick={insert} />
                                </>
                            )}
                        </Pane>

                        <Pane title="PARAMETERS" className={`border-line lg:border-r ${hide('params')}`} aside={!isTrigger && !readOnly && <ConfirmButton size="xs" onConfirm={onDelete} confirmLabel="Delete?"><Trash2 className="size-3.5" />Delete</ConfirmButton>}>
                            <fieldset disabled={readOnly} className="min-w-0 space-y-5">
                                {isTrigger ? (
                                    <Field label="Starts when">
                                        {(id) => (
                                            <Combobox id={id} options={triggerOptions} pinned={pinnedTriggers} searchPlaceholder="Search 600+ triggers: Gmail, Stripe, webhook…"
                                                value={data.integration && data.trigger ? `${data.integration}:${data.trigger}` : ''} onChange={(value, option) => {
                                                    const [integrationKey, trigger] = value.split(':');
                                                    onChange({ integration: integrationKey, trigger, config: {}, credential_id: null, label: option.label });
                                                }} />
                                        )}
                                    </Field>
                                ) : integration && (
                                    <Field label="Action">
                                        {(id) => (
                                            <Combobox id={id} value={data.action ?? ''} searchPlaceholder={`Search ${integration.actions.length} ${integration.name} actions…`}
                                                options={integration.actions.map((a) => ({ value: a.key, label: a.name, keywords: a.description }))}
                                                onChange={(value) => {
                                                    const action = integration.actions.find((a) => a.key === value);
                                                    onChange({ action: action.key, config: {}, label: action.name }, action.outputs?.length ? 'branch' : 'action');
                                                }} />
                                        )}
                                    </Field>
                                )}
                                {operation?.description && <p className="-mt-2 text-[12.5px] text-fg-muted">{operation.description}</p>}

                                {loading && <p className="flex items-center gap-2 text-[13px] text-fg-muted"><Loader2 className="size-4 animate-spin" />Loading settings…</p>}
                                {error && <p className="text-[13px] text-fail">{error}</p>}

                                {authTypes.length > 0 && (
                                    <Field label="Credential" aside={!readOnly && (
                                        <span className="flex items-center gap-1">
                                            <button type="button" onClick={reload} className="rounded p-1 text-fg-subtle hover:text-fg" aria-label="Refresh credentials" title="Refresh"><RefreshCw className="size-3.5" /></button>
                                            <a href={`/credentials?new=${encodeURIComponent(data.integration)}`} target="_blank" rel="noreferrer" className="inline-flex items-center gap-1 text-[12.5px] font-medium text-primary hover:underline">
                                                Create new<ExternalLink className="size-3" />
                                            </a>
                                        </span>
                                    )}>
                                        {(id) => (
                                            <>
                                                <Select id={id} value={data.credential_id ?? ''} onChange={(e) => onChange({ credential_id: e.target.value ? Number(e.target.value) : null })}>
                                                    <option value="">{credentialOptional ? 'No authentication' : credentials.length ? 'Select a credential' : 'No credentials yet: create one'}</option>
                                                    {credentials.map((c) => <option key={c.id} value={c.id}>{c.name} ({AUTH_LABELS[c.auth_type] ?? c.auth_type})</option>)}
                                                </Select>
                                                {!credentialOptional && !data.credential_id && !readOnly && <p className="mt-1.5 text-[12.5px] text-warn">This step needs a credential to run.</p>}
                                            </>
                                        )}
                                    </Field>
                                )}

                                {operation && <DynamicForm inputs={operation.inputs} config={data.config} onChange={setConfig} operators={operators} />}
                            </fieldset>
                        </Pane>

                        <Pane title="OUTPUT" className={hide('output')} aside={runStep && (
                            <span className="flex items-center gap-2">
                                {runStep.http_status && <Badge tone={runStep.http_status < 300 ? 'ok' : 'fail'}>HTTP {runStep.http_status}</Badge>}
                                <StatusBadge status={runStep.status} />
                                {runStep.status !== 'skipped' && <span className="text-[12px] text-fg-subtle">{duration(runStep.duration_ms)}</span>}
                            </span>
                        )}>
                            {executing ? (
                                <p className="flex items-center justify-center gap-2 py-16 text-[13px] text-fg-muted"><Loader2 className="size-4 animate-spin" />Executing…</p>
                            ) : !runStep || runStep.status === 'skipped' ? (
                                <div className="flex flex-col items-center py-10 text-center">
                                    <span className="mb-3 flex size-10 items-center justify-center rounded-xl bg-primary-soft text-primary">{isTrigger ? <Zap className="size-5" /> : <Play className="size-5" />}</span>
                                    <p className="text-[13px] font-medium">No output yet</p>
                                    <p className="mt-1 max-w-60 text-[12.5px] text-fg-muted">Execute this step to see what it returns. Earlier steps run too.</p>
                                    <Button size="sm" variant="primary" className="mt-4" onClick={() => onExecute(node.id)}><Play className="size-3.5" />Execute step</Button>
                                </div>
                            ) : (
                                <>
                                    {runStep.error && <p className="mb-3 flex gap-2 rounded-lg bg-fail-soft px-3 py-2.5 text-[13px] text-fail"><AlertCircle className="mt-px size-4 shrink-0" />{runStep.error}</p>}
                                    {runStep.handle && <p className="mb-3"><Badge tone="info">Took the {runStep.handle} branch</Badge></p>}
                                    <DataView value={runStep.output} base={`steps.${node.id}`} empty="This step returned no data." />
                                </>
                            )}
                        </Pane>
                    </div>
                </div>
            </div>
        </InsertTargetContext.Provider>
    );
}
