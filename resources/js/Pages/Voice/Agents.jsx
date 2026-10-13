import { useState } from 'react';
import { Link, router } from '@inertiajs/react';
import { BellRing, CalendarCheck, Globe2, Headphones, House, Loader2, MessageSquareHeart, PhoneCall, PhoneIncoming, PhoneOutgoing, Plus, Scissors, Sparkles, Stethoscope, Target, TriangleAlert, Utensils } from 'lucide-react';
import AppLayout from '@/Layouts/AppLayout';
import { Badge, Button, Card, Dialog, EmptyState, Field, Input, Textarea } from '@/Components/ui';
import { PROVIDERS, VoiceNav } from '@/Components/Voice';
import { cn, compact, errorMessage, timeAgo } from '@/lib/utils';
import { useCan } from '@/lib/can';

const TEMPLATE_ICONS = { stethoscope: Stethoscope, scissors: Scissors, utensils: Utensils, target: Target, 'bell-ring': BellRing, headphones: Headphones, house: House, 'message-square-heart': MessageSquareHeart };

export default function Agents({ agents, templates, stats, hasAi }) {
    const can = useCan();
    const [creating, setCreating] = useState(false);
    const startFrom = (key) => router.post('/voice/agents', { template: key });

    return (
        <AppLayout title="Voice agents" description="AI agents that answer and make phone calls, book appointments and pass every call to your workflows."
            actions={can['workflows.edit'] && <Button variant="primary" onClick={() => setCreating(true)}><Plus className="size-4" />New agent</Button>}>
            <VoiceNav />

            <div className="mb-6 grid grid-cols-3 gap-3">
                {[['Calls today', stats.calls_today], ['Minutes this week', stats.minutes_week], ['Upcoming bookings', stats.upcoming]].map(([label, value]) => (
                    <Card key={label} className="px-4 py-3.5">
                        <p className="text-[12.5px] text-fg-muted">{label}</p>
                        <p className="mt-1 text-[22px] font-semibold tabular-nums tracking-tight">{compact(value)}</p>
                    </Card>
                ))}
            </div>

            {agents.length === 0 ? (
                <Card className="mb-8">
                    <EmptyState icon={PhoneCall} title="Create your first voice agent"
                        action={can['workflows.edit'] && <Button variant="primary" onClick={() => setCreating(true)}><Plus className="size-4" />New agent</Button>}>
                        Start from a template below or describe what you need. You can talk to it in your browser for free, then connect Vapi or ElevenLabs for real phone calls.
                    </EmptyState>
                </Card>
            ) : (
                <div className="mb-10 grid grid-cols-[minmax(0,1fr)] gap-3 sm:grid-cols-2 xl:grid-cols-3">
                    {agents.map((agent) => <AgentCard key={agent.id} agent={agent} />)}
                </div>
            )}

            <h2 className="mb-1 text-[15px] font-semibold">Start from a template</h2>
            <p className="mb-4 text-[13px] text-fg-muted">Ready-made agents with their instructions, booking and the details to capture. Change anything after.</p>
            <div className="grid grid-cols-[minmax(0,1fr)] gap-3 sm:grid-cols-2 xl:grid-cols-4">
                {templates.map((t) => <TemplateCard key={t.key} template={t} onUse={can['workflows.edit'] ? () => startFrom(t.key) : null} />)}
            </div>

            <NewAgentDialog open={creating} onClose={() => setCreating(false)} templates={templates} hasAi={hasAi} />
        </AppLayout>
    );
}

function AgentCard({ agent }) {
    const provider = PROVIDERS[agent.provider] ?? PROVIDERS.browser;
    return (
        <Link href={`/voice/agents/${agent.id}`} className="group rounded-xl border border-line bg-surface p-4 shadow-card transition-colors hover:border-line-strong">
            <div className="flex items-start gap-3">
                <span className="flex size-10 shrink-0 items-center justify-center rounded-xl bg-primary-soft text-primary">
                    {agent.provider === 'browser' ? <Globe2 className="size-5" /> : <PhoneCall className="size-5" />}
                </span>
                <div className="min-w-0 flex-1">
                    <p className="truncate text-[14.5px] font-semibold group-hover:text-primary">{agent.name}</p>
                    <div className="mt-1 flex flex-wrap items-center gap-1.5">
                        <Badge tone={provider.tone}>{provider.label}{provider.hint && ` · ${provider.hint}`}</Badge>
                        {agent.phone && <span className="truncate text-[12.5px] text-fg-muted">{agent.phone}</span>}
                        {agent.booking && <Badge tone="ok"><CalendarCheck className="size-3" />Books</Badge>}
                    </div>
                </div>
            </div>
            {agent.problem && <p className="mt-3 flex items-start gap-1.5 text-[12.5px] text-fail"><TriangleAlert className="mt-px size-3.5 shrink-0" /><span className="line-clamp-2">{agent.problem}</span></p>}
            <div className="mt-4 flex items-center gap-4 border-t border-line pt-3 text-[12.5px] text-fg-muted">
                <span><strong className="font-semibold text-fg tabular-nums">{agent.calls}</strong> calls</span>
                <span><strong className="font-semibold text-fg tabular-nums">{agent.bookings}</strong> bookings</span>
                <span className="ml-auto">{agent.last_call ? `Last call ${timeAgo(agent.last_call)}` : 'No calls yet'}</span>
            </div>
        </Link>
    );
}

function TemplateCard({ template, onUse, compactCard }) {
    const Icon = TEMPLATE_ICONS[template.icon] ?? PhoneCall;
    return (
        <div className={cn('flex flex-col rounded-xl border border-line bg-surface p-4', !compactCard && 'shadow-card')}>
            <div className="flex items-center gap-2.5">
                <span className="flex size-9 shrink-0 items-center justify-center rounded-lg bg-surface-2 text-fg-muted"><Icon className="size-[18px]" /></span>
                <div className="min-w-0">
                    <p className="truncate text-[14px] font-semibold">{template.name}</p>
                    <p className="flex items-center gap-1 text-[12px] text-fg-subtle">
                        {template.direction === 'outbound' ? <><PhoneOutgoing className="size-3" />Makes calls</> : <><PhoneIncoming className="size-3" />Answers calls</>}
                    </p>
                </div>
            </div>
            <p className="mt-2.5 flex-1 text-[13px] leading-snug text-fg-muted">{template.description}</p>
            {onUse && <Button size="sm" className="mt-3 self-start" onClick={onUse}>Use template</Button>}
        </div>
    );
}

function NewAgentDialog({ open, onClose, templates, hasAi }) {
    const [mode, setMode] = useState('ai');
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
        <Dialog open={open} onClose={onClose} title="New voice agent" description="Pick how you'd like to start. Everything can be changed afterwards." wide>
            <div className="mb-5 grid grid-cols-3 gap-2">
                {[['ai', 'Describe it', Sparkles], ['template', 'Template', PhoneCall], ['blank', 'Blank', Plus]].map(([key, label, Icon]) => (
                    <button key={key} type="button" onClick={() => setMode(key)} aria-pressed={mode === key}
                        className={cn('flex flex-col items-center gap-1.5 rounded-xl border-2 px-3 py-3 text-[13px] font-medium transition-colors',
                            mode === key ? 'border-primary bg-primary-soft/60 text-primary' : 'border-line text-fg-muted hover:border-line-strong')}>
                        <Icon className="size-5" />{label}
                    </button>
                ))}
            </div>

            {mode === 'ai' && (hasAi ? (
                <div className="space-y-3">
                    <Field label="What should the agent do?" help="Mention the business, whether it answers or makes calls, and if it books appointments. Any language is fine.">
                        {(id) => <Textarea id={id} rows={5} value={description} onChange={(e) => setDescription(e.target.value)}
                            placeholder="A receptionist for Smile Dental Clinic in Lahore. It answers calls in Urdu and English, books check-ups and cleanings Monday to Saturday 10am–8pm, and takes messages for the dentist." />}
                    </Field>
                    {error && <p className="rounded-lg bg-fail-soft px-3 py-2 text-[13px] text-fail">{error}</p>}
                    <Button variant="primary" onClick={generate} disabled={busy || description.trim().length < 10}>
                        {busy ? <Loader2 className="size-4 animate-spin" /> : <Sparkles className="size-4" />}{busy ? 'Writing your agent…' : 'Write it with AI'}
                    </Button>
                </div>
            ) : (
                <p className="rounded-lg bg-surface-2 px-4 py-3 text-[13px] text-fg-muted">To write agents with AI, add an AI provider under <Link href="/settings?tab=ai" className="font-medium text-primary hover:underline">Settings → AI builder</Link>. Google Gemini and DeepSeek are free or very cheap. Or start from a template.</p>
            ))}

            {mode === 'template' && (
                <div className="grid grid-cols-[minmax(0,1fr)] gap-2.5 sm:grid-cols-2">
                    {templates.map((t) => <TemplateCard key={t.key} template={t} compactCard onUse={() => router.post('/voice/agents', { template: t.key })} />)}
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
