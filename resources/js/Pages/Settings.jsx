import { useState } from 'react';
import { router, useForm, usePage } from '@inertiajs/react';
import { BellRing, Check, CheckCircle2, Copy, ExternalLink, Mail, Monitor, Moon, Palette, Globe2, Sun, ArrowUpCircle, Users, Plug, Presentation, UserRound, XCircle } from 'lucide-react';
import AiIcon from '@/Components/AiIcon';
import IntegrationIcon from '@/Components/IntegrationIcon';
import AppLayout from '@/Layouts/AppLayout';
import { Button, Card, Combobox, Field, Input, Select, Switch } from '@/Components/ui';
import { applyTheme } from '@/lib/theme';
import CronAutoSetup from '@/Components/CronAutoSetup';
import UpdatePanel from '@/Components/UpdatePanel';
import TeamPanel from '@/Components/TeamPanel';
import McpPanel from '@/Components/McpPanel';
import DemoPanel from '@/Components/DemoPanel';
import { useTimezones } from '@/lib/timezones';
import { cn, copy, errorMessage, timeAgo } from '@/lib/utils';

const THEMES = [
    { value: 'light', label: 'Light', icon: Sun, preview: { bg: '#f6f7f9', panel: '#ffffff', line: '#e4e7ec', bar: '#ee5a24' } },
    { value: 'dark', label: 'Dark', icon: Moon, preview: { bg: '#0e1014', panel: '#16191f', line: '#262b34', bar: '#f2683a' } },
    { value: 'system', label: 'Match system', icon: Monitor },
];

function Preview({ colors }) {
    if (!colors) {
        return (
            <div className="flex h-24 overflow-hidden rounded-lg border border-line">
                <div className="flex-1"><Preview.Inner colors={THEMES[0].preview} /></div>
                <div className="flex-1"><Preview.Inner colors={THEMES[1].preview} /></div>
            </div>
        );
    }
    return <div className="h-24 overflow-hidden rounded-lg border border-line"><Preview.Inner colors={colors} /></div>;
}
Preview.Inner = function Inner({ colors }) {
    return (
        <div className="flex h-full gap-1.5 p-2" style={{ background: colors.bg }}>
            <div className="w-1/4 rounded" style={{ background: colors.panel, border: `1px solid ${colors.line}` }}>
                <div className="m-1.5 h-1.5 rounded-full" style={{ background: colors.bar }} />
            </div>
            <div className="flex-1 space-y-1.5 rounded p-1.5" style={{ background: colors.panel, border: `1px solid ${colors.line}` }}>
                <div className="h-1.5 w-2/3 rounded-full" style={{ background: colors.line }} />
                <div className="h-1.5 w-1/2 rounded-full" style={{ background: colors.line }} />
                <div className="h-4 w-1/3 rounded" style={{ background: colors.bar, opacity: 0.85 }} />
            </div>
        </div>
    );
};

export default function Settings({ tab = 'account', mail, notifications, ai, providers, timezone, cron, updates, team, mcp, demo }) {
    const { auth } = usePage().props;
    // Each section has its own address (/settings/updates); switching is instant, no request, and Back works.
    const setTab = (value) => router.push({ url: `/settings/${value}`, props: (current) => ({ ...current, tab: value }), preserveState: true, preserveScroll: true });
    const [theme, setTheme] = useState(auth.user.theme ?? 'light');

    const chooseTheme = (value) => {
        setTheme(value);
        applyTheme(value);
        router.put('/settings/appearance', { theme: value }, { preserveScroll: true, preserveState: true });
    };

    return (
        <AppLayout title="Settings" description={auth.user.can.admin ? "Your account, the team, timezone, email alerts, the AI builder and updates." : "Your account and how FlowForge looks."}>
            <div className="grid grid-cols-[minmax(0,1fr)] gap-5 md:grid-cols-[200px_minmax(0,1fr)] md:gap-8">
                {/* Phones: a swipeable row of chips; desktop: a side list. */}
                <nav className="no-scrollbar -mx-4 flex gap-1.5 overflow-x-auto px-4 sm:mx-0 sm:px-0 md:flex-col md:overflow-visible" aria-label="Settings sections">
                    {[['account', 'Account', UserRound], ['general', 'General', Globe2, true], ['team', 'Team', Users, true], ['appearance', 'Appearance', Palette], ['email', 'Email & alerts', Mail, true], ['ai', 'AI builder', AiIcon, true], ['api', 'API & MCP', Plug], ...(demo ? [['demo', 'Live demo', Presentation, true]] : []), ['updates', 'Updates', ArrowUpCircle, true]].filter(([, , , adminOnly]) => !adminOnly || auth.user.can.admin).map(([value, label, Icon]) => (
                        <button key={value} type="button" onClick={() => setTab(value)} aria-current={tab === value}
                            className={cn('flex shrink-0 items-center gap-2 rounded-full border px-3.5 py-2 text-left text-[13.5px] font-medium whitespace-nowrap md:gap-2.5 md:rounded-lg md:border-0 md:px-3',
                                tab === value ? 'border-primary/30 bg-primary-soft text-primary' : 'border-line bg-surface text-fg-muted hover:bg-surface-2 hover:text-fg md:bg-transparent')}>
                            <Icon className="size-4" />{label}
                        </button>
                    ))}
                </nav>

                {!auth.user.can.admin && !['account', 'appearance', 'api'].includes(tab) ? <AccountForm user={auth.user} />
                : tab === 'api' ? <McpPanel mcp={mcp} />
                : tab === 'demo' && demo ? <DemoPanel demo={demo} />
                : tab === 'team' ? <TeamPanel team={team} />
                : tab === 'updates' ? <UpdatePanel updates={updates} />
                : tab === 'general' ? <div className="space-y-6"><GeneralSettings timezone={timezone} /><SchedulerSettings cron={cron} /></div>
                : tab === 'email' ? <EmailSettings mail={mail} notifications={notifications} adminEmail={auth.user.email} />
                : tab === 'ai' ? <AiSettings ai={ai} providers={providers} />
                : tab === 'appearance' ? (
                    <Card className="p-6">
                        <h2 className="text-[16px] font-semibold">Theme</h2>
                        <p className="mt-0.5 text-[13px] text-fg-muted">Light is the default. Your choice is saved to your account.</p>
                        <div className="mt-5 grid gap-4 sm:grid-cols-3">
                            {THEMES.map((t) => (
                                <button key={t.value} type="button" onClick={() => chooseTheme(t.value)} aria-pressed={theme === t.value}
                                    className={cn('rounded-xl border-2 p-2.5 text-left transition-colors', theme === t.value ? 'border-primary' : 'border-line hover:border-line-strong')}>
                                    <Preview colors={t.preview} />
                                    <span className="mt-2.5 flex items-center gap-2 px-0.5 text-[13.5px] font-medium">
                                        <t.icon className="size-4 text-fg-muted" />{t.label}
                                        {theme === t.value && <Check className="ml-auto size-4 text-primary" />}
                                    </span>
                                </button>
                            ))}
                        </div>
                    </Card>
                ) : <AccountForm user={auth.user} />}
            </div>
        </AppLayout>
    );
}

function GeneralSettings({ timezone }) {
    const form = useForm({ timezone });
    const zones = useTimezones();
    const detected = Intl.DateTimeFormat().resolvedOptions().timeZone;
    const now = new Intl.DateTimeFormat(undefined, { timeZone: form.data.timezone, weekday: 'long', hour: '2-digit', minute: '2-digit' }).format(new Date());

    return (
        <Card className="p-6">
            <h2 className="text-[16px] font-semibold">Timezone</h2>
            <p className="mt-0.5 max-w-xl text-[13px] text-fg-muted">Schedules run in this timezone: "every day at 11:00" means 11:00 here. The AI builder uses it too.</p>
            <form className="mt-5 max-w-md space-y-3" onSubmit={(e) => { e.preventDefault(); form.put('/settings/general', { preserveScroll: true }); }}>
                <Field label="Timezone" error={form.errors.timezone} help={`It's ${now} there right now.`}>
                    {(id) => <Combobox id={id} value={form.data.timezone} onChange={(v) => form.setData('timezone', v)} options={zones}
                        pinned={[...new Set([detected, 'UTC'])].filter((z) => z && z !== form.data.timezone)} searchPlaceholder="Search city or offset: Karachi, London, GMT+5…" />}
                </Field>
                {detected && detected !== form.data.timezone && (
                    <button type="button" className="text-[12.5px] font-medium text-primary hover:underline" onClick={() => form.setData('timezone', detected)}>Use this browser's timezone ({detected})</button>
                )}
                <div><Button type="submit" variant="primary" loading={form.processing} disabled={!form.isDirty}>Save timezone</Button></div>
            </form>
        </Card>
    );
}

/** Is cron running? If not: one-click setup, or the line to paste in the hosting panel. */
function SchedulerSettings({ cron }) {
    const { scheduler } = usePage().props;
    const healthy = scheduler && (Date.now() - new Date(scheduler).getTime()) / 60000 < 3;
    return (
        <Card className="p-6">
            <h2 className="text-[16px] font-semibold">Scheduler (cron job)</h2>
            <p className="mt-0.5 max-w-xl text-[13px] text-fg-muted">One cron job runs every workflow: schedules, webhooks and app triggers. You never need one per workflow.</p>
            <p className={cn('mt-4 flex items-center gap-2 text-[13.5px] font-medium', healthy ? 'text-ok' : 'text-warn')}>
                {healthy ? <CheckCircle2 className="size-4" /> : <XCircle className="size-4" />}
                {healthy ? `Running: last run ${timeAgo(scheduler)}.` : scheduler ? `Not running since ${timeAgo(scheduler)}.` : 'Not set up yet.'}
            </p>
            {!healthy && <CronAutoSetup endpoint="/settings/scheduler/auto" className="mt-4" />}
            <p className="mt-4 text-[12.5px] text-fg-muted">The line to use in your hosting panel's Cron Jobs (every minute):</p>
            <div className="mt-1.5 flex items-start gap-2 rounded-lg border border-line bg-surface-2 p-2.5">
                <code className="flex-1 font-mono text-[12px] leading-relaxed break-all">{cron}</code>
                <Button size="sm" variant="outline" onClick={() => copy(cron, 'Cron command copied')}><Copy className="size-3.5" />Copy</Button>
            </div>
        </Card>
    );
}

function AccountForm({ user }) {
    const form = useForm({ name: user.name, email: user.email, current_password: '', password: '', password_confirmation: '' });
    const bind = (key) => ({ value: form.data[key], onChange: (e) => form.setData(key, e.target.value), 'aria-invalid': !!form.errors[key] });

    return (
        <Card className="p-6">
            <h2 className="text-[16px] font-semibold">Profile and password</h2>
            <p className="mt-0.5 text-[13px] text-fg-muted">You're the only administrator of this FlowForge install.</p>
            <form className="mt-5 grid max-w-xl gap-4 sm:grid-cols-2" onSubmit={(e) => {
                e.preventDefault();
                form.put('/settings/account', { preserveScroll: true, onSuccess: () => form.reset('current_password', 'password', 'password_confirmation') });
            }}>
                <Field label="Name" required error={form.errors.name}>{(id) => <Input id={id} autoComplete="name" {...bind('name')} />}</Field>
                <Field label="Email" required error={form.errors.email}>{(id) => <Input id={id} type="email" autoComplete="email" {...bind('email')} />}</Field>
                <Field label="New password" error={form.errors.password} help="Leave blank to keep the current one.">
                    {(id) => <Input id={id} type="password" autoComplete="new-password" {...bind('password')} />}
                </Field>
                <Field label="Confirm new password">{(id) => <Input id={id} type="password" autoComplete="new-password" {...bind('password_confirmation')} />}</Field>
                <Field label="Current password" required error={form.errors.current_password} help="Needed to save any change." className="sm:col-span-2 sm:max-w-[calc(50%-0.5rem)]">
                    {(id) => <Input id={id} type="password" autoComplete="current-password" {...bind('current_password')} />}
                </Field>
                <div className="sm:col-span-2"><Button type="submit" variant="primary" loading={form.processing}>Save changes</Button></div>
            </form>
        </Card>
    );
}

function Result({ result }) {
    if (!result) return null;
    return (
        <p className={cn('mt-3 flex items-start gap-2 text-[13px]', result.ok ? 'text-ok' : 'text-fail')}>
            {result.ok ? <CheckCircle2 className="mt-px size-4 shrink-0" /> : <XCircle className="mt-px size-4 shrink-0" />}{result.message}
        </p>
    );
}

function EmailSettings({ mail, notifications, adminEmail }) {
    const form = useForm({
        host: mail?.host ?? '', port: mail?.port ?? 587, encryption: mail?.encryption ?? 'tls', username: mail?.username ?? '', password: '',
        from_address: mail?.from_address ?? '', from_name: mail?.from_name ?? 'FlowForge', notify_email: notifications.recipients?.[0] ?? '',
    });
    const alerts = useForm({ ...notifications, recipients: notifications.recipients ?? [] });
    const [test, setTest] = useState(null);
    const bind = (key) => ({ value: form.data[key] ?? '', onChange: (e) => form.setData(key, e.target.value), 'aria-invalid': !!form.errors[key] });

    const sendTest = async () => {
        setTest({ pending: true });
        try {
            const { data } = await window.axios.post('/settings/mail/test', form.data);
            setTest(data);
        } catch (e) {
            setTest({ ok: false, message: errorMessage(e) });
        }
    };

    return (
        <div className="space-y-6">
            <Card className="p-6">
                <h2 className="text-[16px] font-semibold">Outgoing email (SMTP)</h2>
                <p className="mt-0.5 text-[13px] text-fg-muted">Used for alerts to you. Workflow "Email (SMTP)" steps use their own credentials.</p>
                <form className="mt-5 grid gap-4 sm:grid-cols-6" onSubmit={(e) => { e.preventDefault(); form.put('/settings/mail', { preserveScroll: true, onSuccess: () => form.setData('password', '') }); }}>
                    <Field label="SMTP host" required error={form.errors.host} className="sm:col-span-3">{(id) => <Input id={id} placeholder="smtp.gmail.com or mail.yourdomain.com" {...bind('host')} />}</Field>
                    <Field label="Port" required error={form.errors.port} className="sm:col-span-1">{(id) => <Input id={id} inputMode="numeric" {...bind('port')} />}</Field>
                    <Field label="Security" className="sm:col-span-2">{(id) => (
                        <Select id={id} {...bind('encryption')}><option value="tls">STARTTLS (587)</option><option value="ssl">SSL/TLS (465)</option><option value="none">None</option></Select>
                    )}</Field>
                    <Field label="Username" error={form.errors.username} className="sm:col-span-3">{(id) => <Input id={id} autoComplete="off" {...bind('username')} />}</Field>
                    <Field label="Password" error={form.errors.password} className="sm:col-span-3">{(id) => <Input id={id} type="password" autoComplete="new-password" placeholder={mail?.has_password ? 'Unchanged' : ''} {...bind('password')} />}</Field>
                    <Field label="From address" required error={form.errors.from_address} className="sm:col-span-3">{(id) => <Input id={id} type="email" placeholder="flowforge@yourdomain.com" {...bind('from_address')} />}</Field>
                    <Field label="From name" className="sm:col-span-3">{(id) => <Input id={id} {...bind('from_name')} />}</Field>
                    <Field label="Send alerts to" error={form.errors.notify_email} help={`Leave empty to use the admin email (${adminEmail}).`} className="sm:col-span-6 sm:max-w-md">{(id) => <Input id={id} type="email" placeholder={adminEmail} {...bind('notify_email')} />}</Field>
                    <div className="flex gap-2 sm:col-span-6">
                        <Button onClick={sendTest} loading={test?.pending}>Send test email</Button>
                        <Button type="submit" variant="primary" loading={form.processing}>Save</Button>
                    </div>
                </form>
                {test && !test.pending && <Result result={test} />}
            </Card>

            <Card className="p-6">
                <h2 className="flex items-center gap-2 text-[16px] font-semibold"><BellRing className="size-4 text-primary" />Alerts</h2>
                <p className="mt-0.5 text-[13px] text-fg-muted">For workflows that run on their own. Test runs from the editor never send alerts.</p>
                <form className="mt-5 space-y-4" onSubmit={(e) => { e.preventDefault(); alerts.put('/settings/notifications', { preserveScroll: true }); }}>
                    <label className="flex items-start gap-3">
                        <Switch checked={!!alerts.data.on_failure} onChange={(v) => alerts.setData('on_failure', v)} label="Email when a workflow fails" />
                        <span><span className="block text-[13.5px] font-medium">Email me when a workflow fails</span><span className="block text-[12.5px] text-fg-muted">Includes the failing step, its error and a link to the run. Polling triggers that can't reach their app are reported too.</span></span>
                    </label>
                    <label className="flex items-start gap-3">
                        <Switch checked={!!alerts.data.on_scheduler_stall} onChange={(v) => alerts.setData('on_scheduler_stall', v)} label="Email when the scheduler stopped" />
                        <span><span className="block text-[13.5px] font-medium">Email me when the scheduler was down</span><span className="block text-[12.5px] text-fg-muted">If the cron job didn't run for 10+ minutes, you'll hear about it as soon as it runs again.</span></span>
                    </label>
                    <Field label="At most one email per workflow every" className="max-w-xs" error={alerts.errors.throttle_minutes}>{(id) => (
                        <Select id={id} value={alerts.data.throttle_minutes} onChange={(e) => alerts.setData('throttle_minutes', Number(e.target.value))}>
                            {[5, 15, 30, 60, 240, 1440].map((m) => <option key={m} value={m}>{m < 60 ? `${m} minutes` : m === 1440 ? '24 hours' : `${m / 60} hour${m > 60 ? 's' : ''}`}</option>)}
                        </Select>
                    )}</Field>
                    <Button type="submit" variant="primary" loading={alerts.processing}>Save alerts</Button>
                </form>
            </Card>
        </div>
    );
}

function AiSettings({ ai, providers }) {
    const initial = providers.find((p) => p.key === (ai?.provider ?? 'deepseek')) ?? providers[0];
    const form = useForm({ provider: initial.key, api_key: '', model: ai?.model ?? initial.model, base_url: ai?.base_url ?? initial.base_url, effort: ai?.effort ?? 'medium', format: ai?.format ?? 'openai', label: ai?.label ?? '' });
    const [test, setTest] = useState(null);
    const provider = providers.find((p) => p.key === form.data.provider) ?? providers[0];
    const keyStored = ai?.has_key && ai?.provider === form.data.provider;

    const isCustom = !!provider.custom;
    const keyOptional = isCustom && form.data.format === 'openai';
    const choose = (p) => form.setData((d) => {
        const same = p.key === ai?.provider;
        const effort = same ? ai.effort : p.custom ? 'none' : (d.effort === 'none' ? 'medium' : d.effort);
        return { ...d, provider: p.key, model: same ? ai.model : p.model, base_url: same ? ai.base_url : p.base_url, effort, api_key: '' };
    });

    const runTest = async () => {
        setTest({ pending: true });
        try {
            const { data } = await window.axios.post('/settings/ai/test', form.data);
            setTest(data);
        } catch (e) {
            setTest({ ok: false, message: errorMessage(e) });
        }
    };

    return (
        <Card className="p-6">
            <h2 className="text-[16px] font-semibold">AI provider</h2>
            <p className="mt-0.5 text-[13px] text-fg-muted">The assistant builds, edits and tests workflows from plain-language requests. It searches apps on demand instead of sending the whole catalog, and its instructions are cached, so requests stay small.</p>

            <div className="mt-5 grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
                {providers.map((p) => (
                    <button key={p.key} type="button" onClick={() => choose(p)} aria-pressed={form.data.provider === p.key}
                        className={cn('flex flex-col items-center gap-2 rounded-xl border-2 px-3 py-4 text-center transition-colors', form.data.provider === p.key ? 'border-primary bg-primary-soft/40' : 'border-line hover:border-line-strong')}>
                        <IntegrationIcon icon={p.icon} name={p.name} size="lg" />
                        <span className="text-[13px] font-medium leading-tight">{p.custom && ai?.provider === 'custom' && ai?.label ? ai.label : p.name}</span>
                        {p.custom && ai?.provider !== 'custom' && <span className="text-[11px] text-fg-muted">Any compatible API</span>}
                        {ai?.provider === p.key && <span className="text-[11px] text-ok">In use</span>}
                    </button>
                ))}
            </div>

            <form className="mt-6 grid max-w-2xl gap-4 sm:grid-cols-2" onSubmit={(e) => { e.preventDefault(); form.put('/settings/ai', { preserveScroll: true, onSuccess: () => form.setData('api_key', '') }); }}>
                {isCustom && (
                    <>
                        <Field label="Name" help="Shown in the AI Builder, for example OpenRouter or My gateway.">
                            {(id) => <Input id={id} value={form.data.label} maxLength={60} placeholder="Custom provider" onChange={(e) => form.setData('label', e.target.value)} />}
                        </Field>
                        <Field label="API format" help="Most providers and local servers use the OpenAI format.">
                            {(id) => (
                                <Select id={id} value={form.data.format} onChange={(e) => form.setData('format', e.target.value)}>
                                    <option value="openai">OpenAI-compatible (chat completions)</option>
                                    <option value="anthropic">Anthropic-compatible (messages)</option>
                                </Select>
                            )}
                        </Field>
                        <Field label="API base URL" required error={form.errors.base_url} className="sm:col-span-2"
                            help={form.data.format === 'openai'
                                ? 'The part before /chat/completions. OpenRouter: https://openrouter.ai/api/v1, Groq: https://api.groq.com/openai/v1, Ollama: http://localhost:11434/v1'
                                : 'The part before /v1/messages, for example https://your-gateway.example.com'}>
                            {(id) => <Input id={id} value={form.data.base_url} placeholder={form.data.format === 'openai' ? 'https://openrouter.ai/api/v1' : 'https://your-gateway.example.com'} onChange={(e) => form.setData('base_url', e.target.value)} />}
                        </Field>
                    </>
                )}
                <Field label="API key" required={!keyStored && !keyOptional} error={form.errors.api_key} className="sm:col-span-2"
                    help={keyOptional ? 'Leave empty for servers that need no key, such as Ollama or LM Studio on your own machine.' : null}
                    aside={provider.key_url && <a href={provider.key_url} target="_blank" rel="noreferrer" className="inline-flex items-center gap-1 text-[12.5px] font-medium text-primary hover:underline">Get a key<ExternalLink className="size-3" /></a>}>
                    {(id) => <Input id={id} type="password" autoComplete="off" placeholder={keyStored ? 'Unchanged (stored encrypted)' : keyOptional ? 'Optional' : 'Paste your API key'} value={form.data.api_key} onChange={(e) => form.setData('api_key', e.target.value)} />}
                </Field>
                <Field label="Model" required error={form.errors.model} help={isCustom ? 'The exact model id your provider uses, for example meta-llama/llama-3.3-70b-instruct.' : 'Pick a suggestion or type any model id your account can use.'}>
                    {(id) => (
                        <>
                            <Input id={id} list="ai-models" value={form.data.model} onChange={(e) => form.setData('model', e.target.value)} />
                            <datalist id="ai-models">{provider.models.map((m) => <option key={m} value={m} />)}</datalist>
                        </>
                    )}
                </Field>
                <Field label="Thinking effort" help={isCustom ? 'Sent as reasoning effort only when you pick a level. Use "Don\'t send" if your model or server doesn\'t support it.' : 'Lower is faster and uses fewer tokens. Applies to Claude and OpenAI reasoning models.'}>
                    {(id) => (
                        <Select id={id} value={form.data.effort} onChange={(e) => form.setData('effort', e.target.value)}>
                            {isCustom && <option value="none">Don't send</option>}
                            <option value="low">Low (fewest tokens)</option><option value="medium">Medium{isCustom ? '' : ' (default)'}</option><option value="high">High (hardest workflows)</option>
                        </Select>
                    )}
                </Field>
                {isCustom ? null : provider.base_urls ? (
                    <Field label="Region" className="sm:col-span-2">{(id) => (
                        <Select id={id} value={form.data.base_url} onChange={(e) => form.setData('base_url', e.target.value)}>
                            {Object.entries(provider.base_urls).map(([url, label]) => <option key={url} value={url}>{label}</option>)}
                        </Select>
                    )}</Field>
                ) : (
                    <Field label="API base URL" error={form.errors.base_url} help="Only change this for a proxy or compatible gateway." className="sm:col-span-2">
                        {(id) => <Input id={id} value={form.data.base_url} placeholder={provider.base_url} onChange={(e) => form.setData('base_url', e.target.value)} />}
                    </Field>
                )}
                <div className="flex gap-2 sm:col-span-2">
                    <Button onClick={runTest} loading={test?.pending}>Test connection</Button>
                    <Button type="submit" variant="primary" loading={form.processing}>Save</Button>
                </div>
            </form>
            {test && !test.pending && <Result result={test} />}
        </Card>
    );
}
