import { useMemo, useState } from 'react';
import { Link, router } from '@inertiajs/react';
import { CalendarCheck, ListChecks, Loader2, PhoneCall, PhoneIncoming, PhoneOutgoing, Plus, Sparkles, TriangleAlert, Wand2 } from 'lucide-react';
import AppLayout from '@/Layouts/AppLayout';
import { Button, Card, Dialog, EmptyState, Field, Input, Textarea } from '@/Components/ui';
import { AgentAvatar, SampleChat, TEMPLATE_ICONS, VoiceNav, hueOf, tint } from '@/Components/Voice';
import { cn, compact, errorMessage, timeAgo } from '@/lib/utils';
import { useCan } from '@/lib/can';

const IDEAS = [
    'A receptionist for my dental clinic in Lahore. It answers in Urdu and English and books check-ups Monday to Saturday, 10am to 8pm.',
    'Call people who fill in the contact form on my agency website, find out what they need and their budget, and book a call with me.',
    'Take table bookings for my restaurant and answer questions about the menu, timings and parking.',
];

export default function Agents({ agents, templates, stats, hasAi }) {
    const can = useCan();
    const [creating, setCreating] = useState(false);
    const [preview, setPreview] = useState(null);
    const [category, setCategory] = useState('All');
    const categories = useMemo(() => ['All', ...new Set(templates.map((t) => t.category).filter(Boolean))], [templates]);
    const shown = category === 'All' ? templates : templates.filter((t) => t.category === category);
    const startFrom = (key, business) => router.post('/voice/agents', { template: key, config: { business_name: business } });

    return (
        <AppLayout title="Voice agents" description="AI agents that answer and make phone calls, book appointments and pass every call to your workflows."
            actions={can['workflows.edit'] && <Button variant="primary" onClick={() => setCreating(true)}><Plus className="size-4" />New agent</Button>}>
            <VoiceNav />

            <dl className="mb-7 flex flex-wrap gap-x-8 gap-y-2 text-13 text-fg-muted">
                {[['Calls today', stats.calls_today], ['Minutes this week', stats.minutes_week], ['Upcoming bookings', stats.upcoming]].map(([label, value]) => (
                    <div key={label} className="flex items-baseline gap-2"><dd className="text-20 font-semibold tracking-tight text-fg tabular-nums">{compact(value)}</dd><dt>{label}</dt></div>
                ))}
            </dl>

            {agents.length === 0 ? (
                <Card className="mb-10">
                    <EmptyState icon={PhoneCall} title="Your first agent is a minute away"
                        action={can['workflows.edit'] && <Button variant="primary" onClick={() => setCreating(true)}><Sparkles className="size-4" />Create an agent</Button>}>
                        Pick a template below or describe the agent you want. Talk to it in your browser for free, then connect Vapi or ElevenLabs when you want real calls.
                    </EmptyState>
                </Card>
            ) : (
                <section className="mb-10">
                    <h2 className="mb-3 text-15 font-semibold">Your agents</h2>
                    <div className="grid grid-cols-[minmax(0,1fr)] gap-3 sm:grid-cols-2 xl:grid-cols-3">
                        {agents.map((agent) => <AgentCard key={agent.id} agent={agent} />)}
                    </div>
                </section>
            )}

            <section>
                <div className="mb-4 flex flex-wrap items-end justify-between gap-3">
                    <div>
                        <h2 className="text-15 font-semibold">Templates</h2>
                        <p className="mt-0.5 text-13 text-fg-muted">Each one comes with a persona, instructions, booking rules and the details to capture.</p>
                    </div>
                    <div className="no-scrollbar -mx-4 flex max-w-full gap-1.5 overflow-x-auto px-4 sm:mx-0 sm:px-0" role="tablist" aria-label="Template categories">
                        {categories.map((c) => (
                            <button key={c} type="button" role="tab" aria-selected={category === c} onClick={() => setCategory(c)}
                                className={cn('shrink-0 rounded-full border px-3 py-1 text-12h font-medium transition-colors',
                                    category === c ? 'border-fg bg-fg text-surface' : 'border-line text-fg-muted hover:border-line-strong hover:text-fg')}>
                                {c}
                            </button>
                        ))}
                    </div>
                </div>
                <div className="grid grid-cols-[minmax(0,1fr)] gap-3 sm:grid-cols-2 xl:grid-cols-3">
                    {shown.map((t) => <TemplateCard key={t.key} template={t} onPreview={() => setPreview(t)} onUse={can['workflows.edit'] ? () => setPreview(t) : null} />)}
                </div>
            </section>

            <TemplatePreview template={preview} onClose={() => setPreview(null)} onUse={can['workflows.edit'] ? startFrom : null} />
            <NewAgentDialog open={creating} onClose={() => setCreating(false)} templates={templates} hasAi={hasAi} onPreview={setPreview} />
        </AppLayout>
    );
}

function Status({ agent }) {
    if (agent.problem) return <span className="flex items-center gap-1.5 text-12 font-medium text-fail"><TriangleAlert className="size-3.5" />Needs attention</span>;
    if (agent.provider === 'browser') return <span className="flex items-center gap-1.5 text-12 font-medium text-fg-muted"><span className="size-1.5 rounded-full bg-fg-subtle" />Browser test</span>;
    return agent.live
        ? <span className="flex items-center gap-1.5 text-12 font-medium text-ok"><span className="size-1.5 rounded-full bg-ok" />Live on {agent.provider === 'vapi' ? 'Vapi' : 'ElevenLabs'}</span>
        : <span className="flex items-center gap-1.5 text-12 font-medium text-warn"><span className="size-1.5 rounded-full bg-warn" />Not published</span>;
}

function AgentCard({ agent }) {
    return (
        <Link href={`/voice/agents/${agent.id}`} className="group flex flex-col rounded-2xl border border-line bg-surface p-4 shadow-card transition-colors hover:border-line-strong">
            <div className="flex items-center gap-3">
                <AgentAvatar name={agent.persona || agent.name} size={44} />
                <div className="min-w-0 flex-1">
                    <p className="truncate text-15 font-semibold group-hover:text-primary">{agent.name}</p>
                    <p className="truncate text-12h text-fg-muted">{[agent.persona, agent.business].filter(Boolean).join(' at ') || 'No persona yet'}</p>
                </div>
            </div>
            <div className="mt-3 flex flex-wrap items-center gap-x-3 gap-y-1">
                <Status agent={agent} />
                {agent.phone && <span className="text-12 text-fg-subtle tabular-nums">{agent.phone}</span>}
            </div>
            <div className="mt-4 flex items-center gap-4 border-t border-line pt-3 text-12h text-fg-muted">
                <span className="flex items-center gap-1"><PhoneCall className="size-3.5" /><strong className="font-semibold text-fg tabular-nums">{agent.calls}</strong></span>
                {agent.booking && <span className="flex items-center gap-1"><CalendarCheck className="size-3.5" /><strong className="font-semibold text-fg tabular-nums">{agent.bookings}</strong></span>}
                <span className="ml-auto">{agent.last_call ? `Last call ${timeAgo(agent.last_call)}` : 'No calls yet'}</span>
            </div>
        </Link>
    );
}

function TemplateIcon({ template, size = 36 }) {
    const Icon = TEMPLATE_ICONS[template.icon] ?? PhoneCall;
    return (
        <span className="flex shrink-0 items-center justify-center rounded-xl" style={{ ...tint(hueOf(template.category)), width: size, height: size }}>
            <Icon style={{ width: size * 0.5, height: size * 0.5 }} />
        </span>
    );
}

function Direction({ direction }) {
    return direction === 'outbound'
        ? <span className="flex items-center gap-1"><PhoneOutgoing className="size-3" />Makes calls</span>
        : <span className="flex items-center gap-1"><PhoneIncoming className="size-3" />Answers calls</span>;
}

function TemplateCard({ template, onPreview, onUse }) {
    return (
        <article className="flex flex-col rounded-2xl border border-line bg-surface shadow-card">
            <button type="button" onClick={onPreview} className="flex flex-1 flex-col p-4 text-left">
                <div className="flex items-center gap-3">
                    <TemplateIcon template={template} />
                    <div className="min-w-0">
                        <h3 className="truncate text-14h font-semibold">{template.name}</h3>
                        <p className="flex items-center gap-2 text-12 text-fg-subtle">
                            <span style={{ color: hueOf(template.category) }} className="font-medium">{template.category}</span>
                            <Direction direction={template.direction} />
                        </p>
                    </div>
                </div>
                <div className="mt-3.5 rounded-xl bg-surface-2/60 p-2.5">
                    <SampleChat turns={template.sample} limit={3} compact />
                </div>
                <p className="mt-3 text-12h leading-snug text-fg-muted">{template.description}</p>
            </button>
            <div className="flex items-center gap-2 border-t border-line px-4 py-2.5">
                <span className="mr-auto flex items-center gap-3 text-12 text-fg-subtle">
                    {template.booking?.enabled && <span className="flex items-center gap-1"><CalendarCheck className="size-3.5" />Books</span>}
                    {template.collect?.length > 0 && <span className="flex items-center gap-1"><ListChecks className="size-3.5" />{template.collect.length} details</span>}
                </span>
                <Button size="sm" variant="ghost" onClick={onPreview}>Preview</Button>
                {onUse && <Button size="sm" onClick={onUse}>Use</Button>}
            </div>
        </article>
    );
}

function TemplatePreview({ template, onClose, onUse }) {
    const [business, setBusiness] = useState('');
    if (!template) return null;
    return (
        <Dialog open={!!template} onClose={onClose} title={template.name} description={template.description} wide>
            <div className="grid grid-cols-[minmax(0,1fr)] gap-5 md:grid-cols-[minmax(0,3fr)_minmax(0,2fr)]">
                <div className="rounded-2xl border border-line p-4">
                    <div className="mb-3 flex items-center gap-2.5 border-b border-line pb-3">
                        <AgentAvatar name={template.persona_name} size={34} />
                        <div className="text-13">
                            <p className="font-semibold">{template.persona_name}</p>
                            <p className="text-fg-subtle"><Direction direction={template.direction} /></p>
                        </div>
                    </div>
                    <SampleChat turns={template.sample} limit={12} />
                </div>
                <div className="space-y-4 text-13">
                    <div className="flex items-center gap-2.5"><TemplateIcon template={template} size={32} /><span className="font-medium" style={{ color: hueOf(template.category) }}>{template.category}</span></div>
                    {template.booking?.enabled ? (
                        <div>
                            <p className="font-semibold">Books appointments</p>
                            <p className="mt-0.5 text-fg-muted">{template.booking.services || 'Appointments'}{template.booking.hours && `, ${template.booking.hours}`}</p>
                        </div>
                    ) : <div><p className="font-semibold">No bookings</p><p className="mt-0.5 text-fg-muted">Turn bookings on in the editor if you need them.</p></div>}
                    {template.collect?.length > 0 && (
                        <div>
                            <p className="font-semibold">Captures from each call</p>
                            <ul className="mt-1 space-y-1 text-fg-muted">{template.collect.map((c) => <li key={c.key} className="flex items-start gap-1.5"><ListChecks className="mt-0.5 size-3.5 shrink-0" />{c.description}</li>)}</ul>
                        </div>
                    )}
                    <p className="text-fg-subtle">The names in the sample are examples. You can change the persona and voice after.</p>
                    {onUse && (
                        <form className="space-y-2 border-t border-line pt-4" onSubmit={(e) => { e.preventDefault(); onUse(template.key, business.trim()); }}>
                            <Field label="Your business name">{(id) => <Input id={id} value={business} onChange={(e) => setBusiness(e.target.value)} placeholder="Smile Dental Clinic" autoFocus />}</Field>
                            <Button type="submit" variant="primary" className="w-full justify-center">Use this template</Button>
                        </form>
                    )}
                </div>
            </div>
        </Dialog>
    );
}

function NewAgentDialog({ open, onClose, templates, hasAi, onPreview }) {
    const [mode, setMode] = useState(hasAi ? 'ai' : 'template');
    const [description, setDescription] = useState('');
    const [name, setName] = useState('');
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState(null);

    const generate = async () => {
        setBusy(true);
        setError(null);
        try {
            const { data } = await window.axios.post('/voice/generate', { description });
            router.post('/voice/agents', { name: data.agent.name, config: data.agent });
        } catch (e) {
            setError(errorMessage(e));
            setBusy(false);
        }
    };

    return (
        <Dialog open={open} onClose={onClose} title="New voice agent" description="Everything can be changed afterwards." wide>
            <div className="mb-5 grid grid-cols-3 gap-2" role="tablist">
                {[['ai', 'Describe it', Wand2], ['template', 'From a template', PhoneCall], ['blank', 'Blank', Plus]].map(([key, label, Icon]) => (
                    <button key={key} type="button" role="tab" onClick={() => setMode(key)} aria-selected={mode === key}
                        className={cn('flex flex-col items-center gap-1.5 rounded-xl border-2 px-3 py-3 text-13 font-medium transition-colors',
                            mode === key ? 'border-primary bg-primary-soft/60 text-primary' : 'border-line text-fg-muted hover:border-line-strong')}>
                        <Icon className="size-5" />{label}
                    </button>
                ))}
            </div>

            {mode === 'ai' && (hasAi ? (
                <div className="space-y-3">
                    <Field label="What should the agent do?" help="Name the business, say whether it answers or makes calls, and whether it books. Any language works.">
                        {(id) => <Textarea id={id} rows={5} value={description} onChange={(e) => setDescription(e.target.value)} placeholder={IDEAS[0]} />}
                    </Field>
                    <div className="flex flex-wrap gap-1.5">
                        {IDEAS.map((idea) => (
                            <button key={idea} type="button" onClick={() => setDescription(idea)} className="max-w-full truncate rounded-full border border-line px-3 py-1 text-12 text-fg-muted hover:border-line-strong hover:text-fg">
                                {idea.split('.')[0]}
                            </button>
                        ))}
                    </div>
                    {error && <p className="rounded-lg bg-fail-soft px-3 py-2 text-13 text-fail">{error}</p>}
                    <Button variant="primary" onClick={generate} disabled={busy || description.trim().length < 10}>
                        {busy ? <Loader2 className="size-4 animate-spin" /> : <Sparkles className="size-4" />}{busy ? 'Writing your agent…' : 'Write my agent'}
                    </Button>
                </div>
            ) : (
                <p className="rounded-lg bg-surface-2 px-4 py-3 text-13 text-fg-muted">Writing agents uses the AI model from <Link href="/settings/ai" className="font-medium text-primary hover:underline">Settings → AI builder</Link>. Add one there (Google Gemini and DeepSeek are free or very cheap), or start from a template.</p>
            ))}

            {mode === 'template' && (
                <div className="grid grid-cols-[minmax(0,1fr)] gap-2 sm:grid-cols-2">
                    {templates.map((t) => (
                        <button key={t.key} type="button" onClick={() => router.post('/voice/agents', { template: t.key })}
                            className="flex items-center gap-3 rounded-xl border border-line p-3 text-left hover:border-line-strong hover:bg-surface-2/50">
                            <TemplateIcon template={t} size={34} />
                            <span className="min-w-0">
                                <span className="block truncate text-13h font-semibold">{t.name}</span>
                                <span className="block truncate text-12 text-fg-subtle">{t.description}</span>
                            </span>
                        </button>
                    ))}
                </div>
            )}

            {mode === 'blank' && (
                <form className="space-y-3" onSubmit={(e) => { e.preventDefault(); router.post('/voice/agents', { name }); }}>
                    <Field label="Name">{(id) => <Input id={id} value={name} onChange={(e) => setName(e.target.value)} placeholder="Front desk" autoFocus />}</Field>
                    <Button type="submit" variant="primary">Create agent</Button>
                </form>
            )}
        </Dialog>
    );
}
