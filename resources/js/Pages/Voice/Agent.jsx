import { useEffect, useRef, useState } from 'react';
import { Link, router, useForm } from '@inertiajs/react';
import {
    ArrowLeft, AudioLines, BookOpen, CalendarCheck, Check, CheckCircle2, ExternalLink, Keyboard, Laptop, ListChecks, Loader2, Mic, MessageSquareText, PhoneCall,
    PhoneOff, Play, Plus, Save, Search, Send, Sparkles, Square, Trash2, TriangleAlert, UserRound, Volume2, VolumeX,
} from 'lucide-react';
import AppLayout from '@/Layouts/AppLayout';
import { Button, Card, ConfirmButton, Dialog, Field, Input, Select, Switch, Textarea } from '@/Components/ui';
import { AgentAvatar, CallSheet, CallStatus, DirectionIcon, VoiceNav, VoiceOrb, seconds, when } from '@/Components/Voice';
import { cn, errorMessage, timeAgo, toast } from '@/lib/utils';
import { useCan } from '@/lib/can';

const LANGUAGES = [['en', 'English', 'en-US'], ['ur', 'Urdu', 'ur-PK'], ['hi', 'Hindi', 'hi-IN'], ['ar', 'Arabic', 'ar-SA'], ['es', 'Spanish', 'es-ES'], ['fr', 'French', 'fr-FR'],
    ['de', 'German', 'de-DE'], ['pt', 'Portuguese', 'pt-BR'], ['tr', 'Turkish', 'tr-TR'], ['it', 'Italian', 'it-IT'], ['nl', 'Dutch', 'nl-NL'], ['id', 'Indonesian', 'id-ID']];
const locale = (code) => LANGUAGES.find(([c]) => c === code)?.[2] ?? 'en-US';

const TONES = [['warm', 'Warm', 'Friendly, likes helping'], ['professional', 'Professional', 'Calm and polite'], ['upbeat', 'Upbeat', 'Energetic, smiling'], ['calm', 'Calm', 'Slow and reassuring']];
const LENGTHS = [['short', 'Short'], ['balanced', 'Balanced'], ['detailed', 'Detailed']];
const DURATIONS = [15, 30, 45, 60, 90, 120];

const SECTIONS = [
    ['persona', 'Persona', UserRound], ['conversation', 'Conversation', MessageSquareText], ['knowledge', 'Knowledge', BookOpen],
    ['bookings', 'Bookings', CalendarCheck], ['capture', 'Capture', ListChecks], ['phone', 'Voice & phone', PhoneCall],
];

const PROVIDER_INFO = {
    browser: { title: 'Browser', badge: 'Free', text: 'Talk to it in your browser. No phone number or extra account.' },
    vapi: {
        title: 'Vapi', text: 'Real phone calls. Free credit and free US test numbers to start.', app: 'vapi', link: 'https://dashboard.vapi.ai',
        steps: ['Sign up at dashboard.vapi.ai.', 'Save your private API key as a Vapi credential.', 'Create a free number under Phone Numbers, or import a Twilio number.', 'Pick the account and number below, then save.'],
        model: 'gpt-4o-mini',
    },
    elevenlabs: {
        title: 'ElevenLabs', text: 'Real calls with natural ElevenLabs voices. Free plan includes some minutes.', app: 'elevenlabs', link: 'https://elevenlabs.io/app/agents',
        steps: ['Create an API key (Profile → API keys) and save it as an ElevenLabs credential.', 'Import your Twilio number under Agents → Phone numbers.', 'Pick the account and number below, then save.'],
        model: 'gemini-2.0-flash',
    },
};

export default function Agent({ agent, credentials, variables, questions, recentCalls, hasAi, timezone }) {
    const can = useCan();
    const editable = can['workflows.edit'];
    const form = useForm({
        name: agent.name, provider: agent.provider, credential_id: agent.credential_id, phone_number_id: agent.phone_number_id, phone_label: agent.phone_label,
        config: {
            business_name: '', persona_name: '', tone: 'warm', verbosity: 'balanced', first_message: '', instructions: '', knowledge: '', faqs: [], language: 'en',
            voice: '', browser_voice: '', llm: '', collect: [], ...agent.config,
            booking: { enabled: false, ask_email: false, hours: '', duration: 30, services: '', ...(agent.config?.booking ?? {}) },
        },
    });
    const config = form.data.config;
    const setConfig = (patch) => form.setData('config', { ...config, ...patch });
    const setBooking = (patch) => setConfig({ booking: { ...config.booking, ...patch } });
    const [openCall, setOpenCall] = useState(null);
    const [rewriting, setRewriting] = useState(false);
    const save = () => form.put(`/voice/agents/${agent.id}`, { preserveScroll: true });

    const live = recentCalls.some((c) => ['queued', 'ringing', 'in-progress'].includes(c.status) && c.direction !== 'web');
    useEffect(() => {
        if (!live) return undefined;
        const timer = setInterval(() => router.reload({ only: ['recentCalls'] }), 8000);
        return () => clearInterval(timer);
    }, [live]);

    // A section counts as done once it has what a real agent needs.
    const done = {
        persona: !!(config.persona_name && config.business_name),
        conversation: !!(config.first_message?.trim() && config.instructions?.trim().length > 40),
        knowledge: !!(config.knowledge?.trim() || config.faqs?.some((f) => f.q && f.a)),
        bookings: config.booking.enabled ? !!config.booking.hours : true,
        capture: (config.collect ?? []).some((f) => f.key),
        phone: form.data.provider === 'browser' || !!(form.data.credential_id && form.data.phone_number_id),
    };
    const status = agent.provider === 'browser' ? ['neutral', 'Browser test agent'] : agent.problem ? ['fail', 'Not live'] : agent.live ? ['ok', `Live on ${agent.provider === 'vapi' ? 'Vapi' : 'ElevenLabs'}`] : ['warn', 'Not published yet'];

    return (
        <AppLayout title="Voice agents" description="Shape the agent, hear it in a test call, then connect a phone number."
            actions={editable && <Button variant="primary" onClick={save} loading={form.processing} disabled={!form.isDirty}>{!form.processing && <Save className="size-4" />}{form.isDirty ? 'Save changes' : 'Saved'}</Button>}>
            <VoiceNav />

            <header className="mb-6 flex flex-wrap items-center gap-4">
                <Link href="/voice" className="flex size-9 items-center justify-center rounded-full border border-line text-fg-muted hover:text-fg" aria-label="All agents"><ArrowLeft className="size-4" /></Link>
                <AgentAvatar name={config.persona_name || form.data.name} size={52} />
                <div className="min-w-0 flex-1">
                    <input value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} disabled={!editable} aria-label="Agent name"
                        className="-mx-1 w-full rounded-md bg-transparent px-1 text-[22px] font-semibold tracking-tight outline-none hover:bg-surface-2 focus:bg-surface-2" />
                    <p className="mt-0.5 flex flex-wrap items-center gap-x-3 gap-y-1 text-[13px] text-fg-muted">
                        <span className={cn('flex items-center gap-1.5 font-medium', { ok: 'text-ok', warn: 'text-warn', fail: 'text-fail', neutral: 'text-fg-muted' }[status[0]])}>
                            <span className="size-1.5 rounded-full bg-current" />{status[1]}
                        </span>
                        {agent.phone_label && <span className="tabular-nums">{agent.phone_label}</span>}
                        {agent.synced_at && !agent.problem && <span>Published {timeAgo(agent.synced_at)}</span>}
                        {form.isDirty && <span className="font-medium text-warn">Unsaved changes</span>}
                    </p>
                    {agent.problem && <p className="mt-1 flex items-start gap-1.5 text-[12.5px] text-fail"><TriangleAlert className="mt-px size-3.5 shrink-0" />{agent.problem}</p>}
                </div>
                <a href="#test-call" className="flex items-center gap-1.5 rounded-full bg-ok px-3.5 py-2 text-[13px] font-semibold text-white lg:hidden"><PhoneCall className="size-4" />Test call</a>
            </header>

            <div className="grid grid-cols-[minmax(0,1fr)] gap-6 lg:grid-cols-[minmax(0,1fr)_360px] xl:grid-cols-[176px_minmax(0,1fr)_360px]">
                <nav className="hidden xl:block" aria-label="Agent sections">
                    <ul className="sticky top-6 space-y-0.5">
                        {SECTIONS.map(([id, label, Icon]) => (
                            <li key={id}>
                                <a href={`#${id}`} className="flex items-center gap-2.5 rounded-lg px-2.5 py-2 text-[13.5px] font-medium text-fg-muted hover:bg-surface-2 hover:text-fg">
                                    <Icon className="size-4" /><span className="flex-1">{label}</span>
                                    {done[id] && <Check className="size-3.5 text-ok" strokeWidth={3} aria-label="Done" />}
                                </a>
                            </li>
                        ))}
                    </ul>
                </nav>

                <fieldset disabled={!editable} className="min-w-0 space-y-5">
                    <Section id="persona" title="Persona" hint="Who callers are talking to.">
                        <div className="grid grid-cols-[minmax(0,1fr)] gap-4 sm:grid-cols-2">
                            <Field label="Agent's name" help="How it introduces itself.">{(id) => <Input id={id} value={config.persona_name ?? ''} onChange={(e) => setConfig({ persona_name: e.target.value })} placeholder="Sara" />}</Field>
                            <Field label="Business name" help="Fills {{business_name}} in the texts.">{(id) => <Input id={id} value={config.business_name ?? ''} onChange={(e) => setConfig({ business_name: e.target.value })} placeholder="Smile Dental Clinic" />}</Field>
                        </div>
                        <div>
                            <p className="mb-2 text-[13px] font-medium">Personality</p>
                            <div className="grid grid-cols-2 gap-2 sm:grid-cols-4">
                                {TONES.map(([value, label, hint]) => (
                                    <button key={value} type="button" onClick={() => setConfig({ tone: value })} aria-pressed={config.tone === value}
                                        className={cn('rounded-xl border-2 px-3 py-2.5 text-left transition-colors', config.tone === value ? 'border-primary bg-primary-soft/50' : 'border-line hover:border-line-strong')}>
                                        <span className="block text-[13.5px] font-semibold">{label}</span>
                                        <span className="block text-[12px] text-fg-muted">{hint}</span>
                                    </button>
                                ))}
                            </div>
                        </div>
                        <div className="flex flex-wrap items-end gap-4">
                            <div>
                                <p className="mb-2 text-[13px] font-medium">Reply length</p>
                                <div className="inline-flex rounded-lg bg-surface-2 p-0.5" role="radiogroup" aria-label="Reply length">
                                    {LENGTHS.map(([value, label]) => (
                                        <button key={value} type="button" role="radio" aria-checked={config.verbosity === value} onClick={() => setConfig({ verbosity: value })}
                                            className={cn('rounded-md px-3.5 py-1.5 text-[13px] font-medium', config.verbosity === value ? 'bg-surface text-fg shadow-card' : 'text-fg-muted hover:text-fg')}>{label}</button>
                                    ))}
                                </div>
                            </div>
                            <Field label="Language" className="min-w-48">
                                {(id) => <Select id={id} value={config.language ?? 'en'} onChange={(e) => setConfig({ language: e.target.value })}>{LANGUAGES.map(([code, label]) => <option key={code} value={code}>{label}</option>)}</Select>}
                            </Field>
                        </div>
                    </Section>

                    <Section id="conversation" title="Conversation" hint="What it says first, and how it handles the call."
                        aside={editable && <Button size="sm" onClick={() => setRewriting(true)}><Sparkles className="size-3.5" />Rewrite with AI</Button>}>
                        <Field label="First message" help="Spoken when the call connects. Leave empty to let the caller speak first.">
                            {(id) => (
                                <div className="space-y-2">
                                    <Textarea id={id} rows={2} value={config.first_message ?? ''} onChange={(e) => setConfig({ first_message: e.target.value })} />
                                    <div className="flex flex-wrap items-center gap-1.5 text-[12px] text-fg-subtle">
                                        Insert:
                                        {['business_name', 'customer_name', ...variables.filter((v) => v !== 'customer_name')].map((v) => (
                                            <button key={v} type="button" onClick={() => setConfig({ first_message: `${config.first_message ?? ''}{{${v}}}` })}
                                                className="rounded-md border border-line px-1.5 py-0.5 font-mono text-[11.5px] text-fg-muted hover:border-line-strong hover:text-fg">{`{{${v}}}`}</button>
                                        ))}
                                    </div>
                                </div>
                            )}
                        </Field>
                        <Field label="Instructions" error={form.errors['config.instructions']} help="Its job, what it should find out, and what it must never do. Phone manners and booking rules are added for you.">
                            {(id) => <Textarea id={id} rows={13} value={config.instructions ?? ''} onChange={(e) => setConfig({ instructions: e.target.value })} className="text-[13.5px] leading-relaxed" />}
                        </Field>
                    </Section>

                    <Section id="knowledge" title="Knowledge" hint="Facts it can answer from. Without them it offers to take a message instead of guessing.">
                        <Field label="About the business" help="Address, opening hours, services and prices, policies: anything callers ask about.">
                            {(id) => <Textarea id={id} rows={5} value={config.knowledge ?? ''} onChange={(e) => setConfig({ knowledge: e.target.value })}
                                placeholder={'Address: 12 Main Boulevard, Gulberg, Lahore\nOpen Monday to Saturday, 10am to 8pm\nCheck-up: Rs 2,000. Cleaning: Rs 4,500\nFree parking behind the building'} />}
                        </Field>
                        <FaqList faqs={config.faqs ?? []} questions={questions} onChange={(faqs) => setConfig({ faqs })} />
                    </Section>

                    <Section id="bookings" title="Bookings" hint="Appointments made during the call land on the Bookings page and can start workflows."
                        aside={<Switch checked={!!config.booking.enabled} onChange={(v) => setBooking({ enabled: v })} label="Take bookings" />}>
                        {config.booking.enabled ? (
                            <div className="grid grid-cols-[minmax(0,1fr)] gap-4 sm:grid-cols-2">
                                <Field label="What can be booked" className="sm:col-span-2">{(id) => <Input id={id} value={config.booking.services ?? ''} onChange={(e) => setBooking({ services: e.target.value })} placeholder="Check-up, cleaning, whitening" />}</Field>
                                <Field label="Opening hours">{(id) => <Input id={id} value={config.booking.hours ?? ''} onChange={(e) => setBooking({ hours: e.target.value })} placeholder="Monday to Saturday, 10:00 to 20:00" />}</Field>
                                <Field label="Each appointment lasts">
                                    {(id) => <Select id={id} value={config.booking.duration ?? 30} onChange={(e) => setBooking({ duration: Number(e.target.value) })}>{DURATIONS.map((d) => <option key={d} value={d}>{d < 60 ? `${d} minutes` : `${d / 60} hour${d > 60 ? 's' : ''}`}</option>)}</Select>}
                                </Field>
                                <label className="flex items-center gap-2.5 text-[13.5px] sm:col-span-2"><Switch size="sm" checked={!!config.booking.ask_email} onChange={(v) => setBooking({ ask_email: v })} label="Ask for email" />Also ask for an email address</label>
                            </div>
                        ) : <p className="text-[13px] text-fg-subtle">Off. The agent won't book anything.</p>}
                    </Section>

                    <Section id="capture" title="Capture" hint="Pulled from every call and shown with it. Workflows get them too.">
                        <CollectList collect={config.collect ?? []} onChange={(collect) => setConfig({ collect })} />
                    </Section>

                    <Section id="phone" title="Voice & phone" hint="Start free in the browser; move to a phone provider for real calls.">
                        <PhoneSetup agent={agent} form={form} config={config} setConfig={setConfig} credentials={credentials} />
                    </Section>

                    {can['workflows.delete'] && (
                        <div className="flex justify-end">
                            <ConfirmButton onConfirm={() => router.delete(`/voice/agents/${agent.id}`)} confirmLabel="Click again to delete it and its call history"><Trash2 className="size-3.5" />Delete agent</ConfirmButton>
                        </div>
                    )}
                </fieldset>

                <aside id="test-call" className="min-w-0 scroll-mt-20 space-y-5 lg:sticky lg:top-6 lg:self-start">
                    <CallSimulator agent={agent} config={config} variables={variables} dirty={form.isDirty} hasAi={hasAi} onSave={save} />
                    {agent.provider !== 'browser' && editable && <CallCard agent={agent} variables={variables} dirty={form.isDirty} />}
                    <Card className="p-4">
                        <div className="mb-2 flex items-center justify-between">
                            <h2 className="text-[14px] font-semibold">Recent calls</h2>
                            <Link href={`/voice/calls?agent=${agent.id}`} className="text-[12.5px] font-medium text-primary hover:underline">All calls</Link>
                        </div>
                        {recentCalls.length === 0 ? <p className="text-[13px] text-fg-subtle">Calls and test calls show up here.</p> : (
                            <ul className="-mx-2">
                                {recentCalls.map((c) => (
                                    <li key={c.id}>
                                        <button type="button" onClick={() => setOpenCall(c.id)} className="flex w-full items-center gap-2.5 rounded-lg px-2 py-2 text-left text-[13px] hover:bg-surface-2">
                                            <DirectionIcon direction={c.direction} />
                                            <span className="min-w-0 flex-1 truncate">{c.direction === 'web' ? 'Test call' : (c.direction === 'inbound' ? c.from : c.to) ?? 'Call'}<span className="ml-2 text-fg-subtle">{when(c.started_at, timezone)}</span></span>
                                            <CallStatus status={c.status} />
                                        </button>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </Card>
                </aside>
            </div>

            <RewriteDialog open={rewriting} onClose={() => setRewriting(false)} hasAi={hasAi}
                onDone={(a) => { setConfig({ ...a, booking: { ...config.booking, ...a.booking } }); setRewriting(false); toast('Rewritten. Read it through, then save.', 'success'); }} />
            <CallSheet callId={openCall} onClose={() => setOpenCall(null)} />
        </AppLayout>
    );
}

function Section({ id, title, hint, aside, children }) {
    return (
        <Card id={id} className="scroll-mt-6 space-y-4 p-5 sm:p-6">
            <div className="flex items-start justify-between gap-4">
                <div>
                    <h2 className="text-[16px] font-semibold tracking-tight">{title}</h2>
                    {hint && <p className="mt-0.5 text-[13px] text-fg-muted">{hint}</p>}
                </div>
                {aside}
            </div>
            {children}
        </Card>
    );
}

function FaqList({ faqs, questions, onChange }) {
    const set = (i, patch) => onChange(faqs.map((f, j) => (j === i ? { ...f, ...patch } : f)));
    const unused = (questions ?? []).filter((q) => !faqs.some((f) => f.q === q));
    return (
        <div className="space-y-3">
            <p className="text-[13px] font-medium">Questions callers ask</p>
            {faqs.map((f, i) => (
                <div key={i} className="space-y-2 rounded-xl border border-line p-3">
                    <div className="flex gap-2">
                        <Input value={f.q} onChange={(e) => set(i, { q: e.target.value })} placeholder="Do you accept insurance?" aria-label={`Question ${i + 1}`} />
                        <Button variant="ghost" size="icon" onClick={() => onChange(faqs.filter((_, j) => j !== i))} aria-label={`Remove question ${i + 1}`}><Trash2 className="size-3.5" /></Button>
                    </div>
                    <Textarea rows={2} value={f.a} onChange={(e) => set(i, { a: e.target.value })} placeholder="The answer the agent should give" aria-label={`Answer ${i + 1}`} />
                </div>
            ))}
            <div className="flex flex-wrap items-center gap-1.5">
                <Button size="sm" variant="ghost" onClick={() => onChange([...faqs, { q: '', a: '' }])}><Plus className="size-3.5" />Add a question</Button>
                {unused.map((q) => (
                    <button key={q} type="button" onClick={() => onChange([...faqs, { q, a: '' }])} className="rounded-full border border-dashed border-line px-2.5 py-1 text-[12px] text-fg-muted hover:border-primary hover:text-primary">+ {q}</button>
                ))}
            </div>
        </div>
    );
}

function CollectList({ collect, onChange }) {
    const set = (i, patch) => onChange(collect.map((f, j) => (j === i ? { ...f, ...patch } : f)));
    return (
        <div className="space-y-2">
            {collect.map((f, i) => (
                <div key={i} className="grid grid-cols-[minmax(0,2fr)_minmax(0,3fr)_auto] gap-2">
                    <Input value={f.key} onChange={(e) => set(i, { key: e.target.value })} placeholder="budget" aria-label={`Detail ${i + 1} name`} />
                    <Input value={f.description} onChange={(e) => set(i, { description: e.target.value })} placeholder="The budget the caller mentions" aria-label={`Detail ${i + 1} description`} />
                    <Button variant="ghost" size="icon" onClick={() => onChange(collect.filter((_, j) => j !== i))} aria-label={`Remove detail ${i + 1}`}><Trash2 className="size-3.5" /></Button>
                </div>
            ))}
            <Button size="sm" variant="ghost" onClick={() => onChange([...collect, { key: '', description: '' }])}><Plus className="size-3.5" />Add a detail</Button>
        </div>
    );
}

// macOS ships joke voices (Albert, Boing, Bad News…) that list first and sound broken on a call.
const NOVELTY = /^(Albert|Bad News|Bahh|Bells|Boing|Bubbles|Cellos|Good News|Jester|Organ|Superstar|Trinoids|Whisper|Wobble|Zarvox|Fred|Junior|Kathy|Ralph)\b/;

/** The browser's voices for a language, natural-sounding ones first. */
function rankedVoices(lang) {
    if (!('speechSynthesis' in window)) return [];
    const rank = (v) => (/google|natural|neural|online|premium|enhanced|siri/i.test(v.name) ? 0 : /^(Samantha|Daniel|Karen|Moira|Tessa|Rishi|Veena|Lekha|Alex|Aaron|Nicky|Ava|Zoe|Evan|Allison|Susan|Serena)\b/.test(v.name) ? 2 : 4)
        + (v.lang?.replace('_', '-') === lang ? 0 : 1);
    return window.speechSynthesis.getVoices()
        .filter((v) => v.lang?.toLowerCase().startsWith(lang.slice(0, 2).toLowerCase()) && !NOVELTY.test(v.name))
        .sort((a, b) => rank(a) - rank(b));
}

function browserVoice(lang, name) {
    return window.speechSynthesis.getVoices().find((v) => v.name === name) ?? rankedVoices(lang)[0];
}

function speakSample(text, { lang, voiceName }) {
    if (!('speechSynthesis' in window)) return;
    window.speechSynthesis.cancel();
    const u = new SpeechSynthesisUtterance(text);
    u.lang = lang;
    const voice = browserVoice(lang, voiceName);
    if (voice) u.voice = voice;
    window.speechSynthesis.speak(u);
}

function useBrowserVoices(lang) {
    const [voices, setVoices] = useState([]);
    useEffect(() => {
        if (!('speechSynthesis' in window)) return undefined;
        const load = () => setVoices(rankedVoices(lang));
        load();
        window.speechSynthesis.addEventListener('voiceschanged', load);
        return () => window.speechSynthesis.removeEventListener('voiceschanged', load);
    }, [lang]);
    return voices;
}

function PhoneSetup({ agent, form, config, setConfig, credentials }) {
    const provider = form.data.provider;
    const info = PROVIDER_INFO[provider];
    const options = credentials.filter((c) => c.integration === info.app);
    const [numbers, setNumbers] = useState(null);
    const [loading, setLoading] = useState(null);
    const [voices, setVoices] = useState(null);
    const [playing, setPlaying] = useState(null);
    const [picking, setPicking] = useState(false);
    const audio = useRef(null);
    const lang = locale(config.language);
    const browserVoices = useBrowserVoices(lang);
    const sample = `Hi, I'm ${config.persona_name || 'your assistant'}${config.business_name ? ` from ${config.business_name}` : ''}. How can I help you today?`;

    const fetchList = async (kind) => {
        setLoading(kind);
        try {
            const { data } = await window.axios.get(`/voice/agents/${agent.id}/${kind}`, { params: { provider, credential_id: form.data.credential_id } });
            if (kind === 'numbers') {
                setNumbers(data.numbers);
                if (!data.numbers.length) toast('No phone numbers on this account yet. Create or import one with the provider first.', 'error');
            } else setVoices(data.voices);
        } catch (e) {
            toast(errorMessage(e), 'error');
        } finally {
            setLoading(null);
        }
    };
    const choose = (p) => { form.setData({ ...form.data, provider: p, credential_id: null, phone_number_id: null, phone_label: null }); setNumbers(null); setVoices(null); };
    const play = (v) => {
        audio.current?.pause();
        if (playing === v.id) { setPlaying(null); return; }
        if (v.preview) { audio.current = new Audio(v.preview); audio.current.onended = () => setPlaying(null); audio.current.play(); setPlaying(v.id); }
    };

    return (
        <div className="space-y-5">
            <div className="grid grid-cols-[minmax(0,1fr)] gap-2 sm:grid-cols-3">
                {Object.entries(PROVIDER_INFO).map(([key, p]) => (
                    <button key={key} type="button" onClick={() => choose(key)} aria-pressed={provider === key}
                        className={cn('rounded-xl border-2 px-3.5 py-3 text-left transition-colors', provider === key ? 'border-primary bg-primary-soft/50' : 'border-line hover:border-line-strong')}>
                        <span className="flex items-center gap-2 text-[14px] font-semibold">
                            {key === 'browser' ? <Laptop className="size-4" /> : <PhoneCall className="size-4" />}{p.title}
                            {p.badge && <span className="ml-auto rounded-md bg-ok-soft px-1.5 py-0.5 text-[11px] font-semibold text-ok">{p.badge}</span>}
                        </span>
                        <span className="mt-1 block text-[12px] leading-snug text-fg-muted">{p.text}</span>
                    </button>
                ))}
            </div>

            {provider === 'browser' ? (
                <div>
                    <p className="mb-2 text-[13px] font-medium">Test-call voice</p>
                    {browserVoices.length === 0 ? <p className="text-[13px] text-fg-subtle">Your browser has no {LANGUAGES.find(([c]) => c === config.language)?.[1] ?? ''} voices, so replies show as text.</p> : (
                        <div className="grid grid-cols-[minmax(0,1fr)] gap-2 sm:grid-cols-2">
                            {browserVoices.slice(0, 8).map((v) => (
                                <VoiceOption key={v.name} name={v.name.replace(/\s*\(.*\)$/, '')} description={v.lang} selected={(config.browser_voice || browserVoices[0]?.name) === v.name}
                                    onSelect={() => setConfig({ browser_voice: v.name })} onPlay={() => speakSample(sample, { lang, voiceName: v.name })} />
                            ))}
                        </div>
                    )}
                    <p className="mt-2 text-[12px] text-fg-subtle">Only for test calls in this browser. Real calls use the provider's voice.</p>
                </div>
            ) : (
                <>
                    <ol className="space-y-1.5 rounded-xl bg-surface-2/70 p-4 text-[12.5px] text-fg-muted">
                        {info.steps.map((s, i) => <li key={s} className="flex gap-2.5"><span className="flex size-5 shrink-0 items-center justify-center rounded-full bg-surface text-[11px] font-semibold text-fg">{i + 1}</span>{s}</li>)}
                        <li className="pl-7"><a href={info.link} target="_blank" rel="noreferrer" className="inline-flex items-center gap-1 font-medium text-primary hover:underline">Open {info.title}<ExternalLink className="size-3" /></a></li>
                    </ol>
                    <div className="grid grid-cols-[minmax(0,1fr)] gap-4 sm:grid-cols-2">
                        <Field label={`${info.title} account`} help={options.length ? null : <>No {info.title} key saved yet. <a href={`/credentials?new=${info.app}`} className="font-medium text-primary hover:underline">Add it</a>.</>}>
                            {(id) => (
                                <Select id={id} value={form.data.credential_id ?? ''} onChange={(e) => { form.setData({ ...form.data, credential_id: e.target.value ? Number(e.target.value) : null, phone_number_id: null, phone_label: null }); setNumbers(null); setVoices(null); }}>
                                    <option value="">Choose…</option>
                                    {options.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
                                </Select>
                            )}
                        </Field>
                        <Field label="Phone number" help="Calls to it reach this agent; its calls come from it.">
                            {(id) => numbers ? (
                                <Select id={id} value={form.data.phone_number_id ?? ''} onChange={(e) => form.setData({ ...form.data, phone_number_id: e.target.value || null, phone_label: numbers.find((n) => n.id === e.target.value)?.label ?? null })}>
                                    <option value="">Choose…</option>
                                    {numbers.map((n) => <option key={n.id} value={n.id}>{n.label}</option>)}
                                </Select>
                            ) : (
                                <div className="flex gap-2">
                                    <Input id={id} readOnly value={form.data.phone_label ?? ''} placeholder="No number yet" />
                                    <Button onClick={() => fetchList('numbers')} disabled={!form.data.credential_id || loading === 'numbers'}>{loading === 'numbers' && <Loader2 className="size-4 animate-spin" />}Load</Button>
                                </div>
                            )}
                        </Field>
                    </div>
                    {provider === 'elevenlabs' ? (
                        <div>
                            <p className="mb-2 text-[13px] font-medium">Voice</p>
                            <div className="flex flex-wrap items-center gap-3 rounded-xl border border-line p-3">
                                <span className="flex size-10 shrink-0 items-center justify-center rounded-full bg-primary-soft text-primary"><AudioLines className="size-5" /></span>
                                <div className="min-w-0 flex-1">
                                    <p className="truncate text-[13.5px] font-semibold">{config.voice_name || (config.voice ? 'Your chosen voice' : 'Rachel')}</p>
                                    <p className="text-[12px] text-fg-muted">{config.voice ? 'ElevenLabs voice. Test calls use it too.' : 'Default ElevenLabs voice'}</p>
                                </div>
                                <Button size="sm" onClick={() => setPicking(true)} disabled={!form.data.credential_id}><Search className="size-3.5" />Choose a voice</Button>
                            </div>
                            {!form.data.credential_id && <p className="mt-1.5 text-[12px] text-fg-subtle">Choose your ElevenLabs account first.</p>}
                            <VoicePicker open={picking} onClose={() => setPicking(false)} agent={agent} credentialId={form.data.credential_id} language={config.language} current={config.voice}
                                onPick={(id, name) => { setConfig({ voice: id, voice_name: name }); setPicking(false); }} />
                        </div>
                    ) : (
                    <div>
                        <div className="mb-2 flex items-center justify-between gap-2">
                            <p className="text-[13px] font-medium">Voice{config.voice && <span className="ml-2 font-normal text-fg-muted">{voices?.find((v) => v.id === config.voice)?.name ?? config.voice}</span>}</p>
                            <Button size="sm" onClick={() => fetchList('voices')} disabled={loading === 'voices'}>
                                {loading === 'voices' ? <Loader2 className="size-3.5 animate-spin" /> : <Volume2 className="size-3.5" />}Browse voices
                            </Button>
                        </div>
                        {voices && (
                            <div className="grid max-h-80 grid-cols-[minmax(0,1fr)] gap-2 overflow-y-auto pr-1 sm:grid-cols-2">
                                {voices.map((v) => (
                                    <VoiceOption key={v.id} name={v.name} description={v.description} selected={config.voice === v.id} playing={playing === v.id}
                                        onSelect={() => setConfig({ voice: v.id, voice_name: v.name })} onPlay={v.preview ? () => play(v) : null} />
                                ))}
                            </div>
                        )}
                        {!voices && <p className="text-[12.5px] text-fg-subtle">{config.voice ? 'Browse to change it.' : 'Default: Elliot.'} Test calls in the browser use your browser's voice.</p>}
                    </div>
                    )}
                    <Field label="AI model" help={`Optional. Empty uses ${info.model}.`}>{(id) => <Input id={id} value={config.llm ?? ''} onChange={(e) => setConfig({ llm: e.target.value })} placeholder={info.model} className="sm:w-72" />}</Field>
                </>
            )}
        </div>
    );
}

function VoiceOption({ name, description, selected, playing, busy, badge, onSelect, onPlay }) {
    return (
        <div className={cn('flex items-center gap-2 rounded-xl border-2 px-2.5 py-2', selected ? 'border-primary bg-primary-soft/40' : 'border-line')}>
            {onPlay ? (
                <button type="button" onClick={onPlay} className="flex size-8 shrink-0 items-center justify-center rounded-full bg-surface-2 text-fg hover:bg-primary hover:text-primary-fg" aria-label={`Play ${name}`}>
                    {playing ? <Square className="size-3" fill="currentColor" /> : <Play className="ml-0.5 size-3.5" fill="currentColor" />}
                </button>
            ) : <span className="flex size-8 shrink-0 items-center justify-center rounded-full bg-surface-2 text-fg-subtle"><Volume2 className="size-3.5" /></span>}
            <button type="button" onClick={onSelect} disabled={busy} className="min-w-0 flex-1 text-left">
                <span className="block truncate text-[13px] font-semibold">{name}</span>
                {description && <span className="block truncate text-[11.5px] text-fg-muted">{description}</span>}
            </button>
            {badge && <span className="shrink-0 rounded-md bg-surface-2 px-1.5 py-0.5 text-[10.5px] font-medium text-fg-muted">{badge}</span>}
            {busy ? <Loader2 className="size-4 shrink-0 animate-spin text-primary" /> : selected && <CheckCircle2 className="size-4 shrink-0 text-primary" />}
        </div>
    );
}

// Languages in the ElevenLabs voice library; names come from the browser.
const LIBRARY_LANGUAGES = ['en', 'ur', 'hi', 'ar', 'bn', 'pa', 'fa', 'tr', 'es', 'fr', 'de', 'it', 'pt', 'nl', 'pl', 'ru', 'uk', 'sv', 'no', 'da', 'fi', 'cs', 'sk', 'hu', 'ro',
    'bg', 'hr', 'el', 'id', 'ms', 'fil', 'vi', 'th', 'ta', 'te', 'mr', 'gu', 'ja', 'ko', 'zh'];
const languageName = (code) => { try { return new Intl.DisplayNames(['en'], { type: 'language' }).of(code); } catch { return code; } };

/** ElevenLabs voices: the whole voice library (search, language, gender) or the account's own voices, each with a sample to play. */
function VoicePicker({ open, onClose, agent, credentialId, language, current, onPick }) {
    const [source, setSource] = useState('library');
    const [search, setSearch] = useState('');
    const [lang, setLang] = useState(language ?? 'en');
    const [gender, setGender] = useState('');
    const [voices, setVoices] = useState([]);
    const [page, setPage] = useState(0);
    const [more, setMore] = useState(false);
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState(null);
    const [playing, setPlaying] = useState(null);
    const [adding, setAdding] = useState(null);
    const audio = useRef(null);
    const latest = useRef(0);

    const load = async (next) => {
        const id = ++latest.current;
        setLoading(true);
        setError(null);
        try {
            const { data } = await window.axios.get(`/voice/agents/${agent.id}/voices`, {
                params: { provider: 'elevenlabs', credential_id: credentialId, source, search: search.trim() || undefined, language: lang || undefined, gender: gender || undefined, page: next },
            });
            if (id !== latest.current) return;
            setVoices((v) => (next ? [...v, ...data.voices] : data.voices));
            setMore(data.has_more);
            setPage(next);
        } catch (e) {
            if (id === latest.current) setError(errorMessage(e));
        } finally {
            if (id === latest.current) setLoading(false);
        }
    };
    useEffect(() => {
        if (!open) return undefined;
        const timer = setTimeout(() => load(0), search ? 350 : 0);
        return () => clearTimeout(timer);
    }, [open, source, search, lang, gender]); // eslint-disable-line react-hooks/exhaustive-deps

    const stop = () => { audio.current?.pause(); setPlaying(null); };
    const close = () => { stop(); onClose(); };
    const play = (v) => {
        audio.current?.pause();
        if (playing === v.id) { setPlaying(null); return; }
        audio.current = new Audio(v.preview);
        audio.current.onended = () => setPlaying(null);
        audio.current.play().catch(() => setPlaying(null));
        setPlaying(v.id);
    };
    const pick = async (v) => {
        stop();
        if (v.added || !v.owner) { onPick(v.id, v.name); return; }
        setAdding(v.id);
        try {
            const { data } = await window.axios.post(`/voice/agents/${agent.id}/voices/add`, { credential_id: credentialId, owner: v.owner, voice_id: v.id, name: v.name });
            onPick(data.voice_id, v.name);
            toast(`${v.name} added to your ElevenLabs voices. Save to use it.`, 'success');
        } catch (e) {
            toast(errorMessage(e), 'error');
        } finally {
            setAdding(null);
        }
    };
    const chip = (active) => cn('shrink-0 rounded-full border px-3 py-1 text-[12.5px] font-medium', active ? 'border-fg bg-fg text-bg' : 'border-line text-fg-muted hover:text-fg');

    return (
        <Dialog open={open} onClose={close} title="Choose a voice" description="Play a sample, then pick the voice your agent speaks with on calls and test calls." wide>
            <div className="space-y-3">
                <div className="flex flex-wrap items-center gap-2">
                    <div className="inline-flex rounded-lg bg-surface-2 p-0.5" role="radiogroup" aria-label="Where to look">
                        {[['library', 'Voice library'], ['mine', 'My voices']].map(([value, label]) => (
                            <button key={value} type="button" role="radio" aria-checked={source === value} onClick={() => setSource(value)}
                                className={cn('rounded-md px-3 py-1.5 text-[13px] font-medium', source === value ? 'bg-surface text-fg shadow-card' : 'text-fg-muted hover:text-fg')}>{label}</button>
                        ))}
                    </div>
                    {source === 'library' && (
                        <Select value={lang} onChange={(e) => setLang(e.target.value)} aria-label="Language" className="w-auto min-w-40">
                            <option value="">Any language</option>
                            {LIBRARY_LANGUAGES.map((code) => <option key={code} value={code}>{languageName(code)}</option>)}
                        </Select>
                    )}
                </div>
                <div className="relative">
                    <Search className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-fg-subtle" />
                    <Input value={search} onChange={(e) => setSearch(e.target.value)} placeholder={source === 'library' ? 'Search: calm, British, deep, customer support…' : 'Search your voices'} aria-label="Search voices" className="pl-9" />
                </div>
                {source === 'library' && (
                    <div className="no-scrollbar flex gap-1.5 overflow-x-auto">
                        {[['', 'Any voice'], ['female', 'Female'], ['male', 'Male']].map(([value, label]) => (
                            <button key={value} type="button" onClick={() => setGender(value)} aria-pressed={gender === value} className={chip(gender === value)}>{label}</button>
                        ))}
                    </div>
                )}

                {error && <p className="rounded-lg bg-fail-soft px-3 py-2 text-[13px] text-fail">{error}</p>}
                <div className="grid max-h-[52dvh] grid-cols-[minmax(0,1fr)] content-start gap-2 overflow-y-auto pr-1 sm:grid-cols-2">
                    {voices.map((v) => (
                        <VoiceOption key={`${v.owner ?? 'mine'}-${v.id}`} name={v.name} description={v.description || (v.language && languageName(v.language))} selected={current === v.id}
                            playing={playing === v.id} busy={adding === v.id} badge={v.paid ? 'Paid plans' : null} onSelect={() => pick(v)} onPlay={v.preview ? () => play(v) : null} />
                    ))}
                    {loading && Array.from({ length: voices.length ? 2 : 6 }, (_, i) => <div key={`s${i}`} className="h-[52px] animate-pulse rounded-xl bg-surface-2" />)}
                    {!loading && !error && voices.length === 0 && (
                        <div className="py-8 text-center text-[13px] text-fg-muted sm:col-span-2">
                            <p>{source === 'mine' ? 'No voices in your ElevenLabs account match.' : `No ${lang ? `${languageName(lang)} ` : ''}voices match.`}</p>
                            {source === 'library' && lang && <Button size="sm" className="mt-3" onClick={() => setLang('')}>Search all languages</Button>}
                        </div>
                    )}
                </div>
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <p className="text-[12px] text-fg-subtle">Any voice can speak the agent's language. Voices made for it sound the most natural.</p>
                    {more && !loading && <Button size="sm" onClick={() => load(page + 1)}>Show more voices</Button>}
                </div>
            </div>
        </Dialog>
    );
}

function RewriteDialog({ open, onClose, onDone, hasAi }) {
    const [text, setText] = useState('');
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState(null);
    const run = async () => {
        setBusy(true);
        setError(null);
        try {
            const { data } = await window.axios.post('/voice/generate', { description: text });
            onDone(data.agent);
            setText('');
        } catch (e) {
            setError(errorMessage(e));
        } finally {
            setBusy(false);
        }
    };
    return (
        <Dialog open={open} onClose={onClose} title="Rewrite with AI" description="Describe the agent. The AI rewrites its first message, instructions, bookings and details to capture.">
            {hasAi ? (
                <div className="space-y-3">
                    <Textarea rows={5} value={text} onChange={(e) => setText(e.target.value)} placeholder="Calls people who filled in our website form, finds out what they need and their budget, then books a call with our sales team." aria-label="Describe the agent" />
                    {error && <p className="rounded-lg bg-fail-soft px-3 py-2 text-[13px] text-fail">{error}</p>}
                    <Button variant="primary" onClick={run} disabled={busy || text.trim().length < 10}>{busy ? <Loader2 className="size-4 animate-spin" /> : <Sparkles className="size-4" />}{busy ? 'Writing…' : 'Rewrite'}</Button>
                </div>
            ) : <p className="text-[13px] text-fg-muted">Add an AI provider under <Link href="/settings?tab=ai" className="font-medium text-primary hover:underline">Settings → AI builder</Link> first.</p>}
        </Dialog>
    );
}

/** Microphone loudness (0–1) while a test call is listening, for the orb. */
function useMicLevel(active) {
    const [level, setLevel] = useState(0);
    useEffect(() => {
        if (!active || !navigator.mediaDevices?.getUserMedia) return undefined;
        let stream;
        let ctx;
        let raf;
        let cancelled = false;
        navigator.mediaDevices.getUserMedia({ audio: true }).then((s) => {
            if (cancelled) { s.getTracks().forEach((t) => t.stop()); return; }
            stream = s;
            ctx = new AudioContext();
            const analyser = ctx.createAnalyser();
            analyser.fftSize = 512;
            ctx.createMediaStreamSource(s).connect(analyser);
            const data = new Uint8Array(analyser.fftSize);
            const tick = () => {
                analyser.getByteTimeDomainData(data);
                let sum = 0;
                for (const v of data) sum += ((v - 128) / 128) ** 2;
                setLevel(Math.sqrt(sum / data.length));
                raf = requestAnimationFrame(tick);
            };
            tick();
        }).catch(() => {});
        return () => { cancelled = true; cancelAnimationFrame(raf); stream?.getTracks().forEach((t) => t.stop()); ctx?.close(); setLevel(0); };
    }, [active]);
    return level;
}

/** The test call: a phone-style screen. The browser listens and speaks; FlowForge's AI model answers. */
function CallSimulator({ agent, config, variables, dirty, hasAi, onSave }) {
    const Recognition = window.SpeechRecognition || window.webkitSpeechRecognition;
    const canSpeak = 'speechSynthesis' in window;
    const lang = locale(config.language);
    const [state, setState] = useState('idle'); // idle | starting | listening | thinking | speaking | ending | ended
    const [turns, setTurns] = useState([]);
    const [values, setValues] = useState({});
    const [typed, setTyped] = useState('');
    const [typing, setTyping] = useState(!Recognition);
    const [error, setError] = useState(null);
    const [result, setResult] = useState(null);
    const [booking, setBooking] = useState(null);
    const [speaker, setSpeaker] = useState(canSpeak);
    const [startedAt, setStartedAt] = useState(null);
    const [now, setNow] = useState(Date.now());
    const call = useRef(null);
    const active = useRef(false);
    const busy = useRef(false);
    const recognition = useRef(null);
    const scroller = useRef(null);
    const audioEl = useRef(null);
    const cloudFailed = useRef(false);
    const cloudVoice = agent.provider === 'elevenlabs' && !!agent.credential_id;
    const speakerRef = useRef(speaker);
    speakerRef.current = speaker;
    const typingRef = useRef(typing);
    typingRef.current = typing;
    const inCall = ['starting', 'listening', 'thinking', 'speaking'].includes(state);
    const level = useMicLevel(inCall && !!Recognition && !typing);

    useEffect(() => () => { active.current = false; recognition.current?.abort(); audioEl.current?.pause(); if (canSpeak) window.speechSynthesis.cancel(); }, []);
    useEffect(() => { scroller.current?.scrollTo({ top: scroller.current.scrollHeight, behavior: 'smooth' }); }, [turns]);
    useEffect(() => {
        if (!inCall) return undefined;
        const timer = setInterval(() => setNow(Date.now()), 1000);
        return () => clearInterval(timer);
    }, [inCall]);

    const browserSpeak = (text) => new Promise((resolve) => {
        if (!canSpeak) return resolve();
        window.speechSynthesis.cancel();
        const u = new SpeechSynthesisUtterance(text);
        u.lang = lang;
        const voice = browserVoice(lang, config.browser_voice);
        if (voice) u.voice = voice;
        u.onend = resolve;
        u.onerror = resolve;
        window.speechSynthesis.speak(u);
    });

    // ElevenLabs agents speak with their real voice; anything else (or a failure) uses the browser's voice.
    const speak = async (text) => {
        if (!speakerRef.current || !text) return;
        if (cloudVoice && !cloudFailed.current) {
            try {
                const { data } = await window.axios.post(`/voice/agents/${agent.id}/speak`, { text }, { responseType: 'blob' });
                const url = URL.createObjectURL(data);
                await new Promise((resolve) => {
                    const a = new Audio(url);
                    audioEl.current = a;
                    a.onended = resolve;
                    a.onpause = resolve;
                    a.onerror = resolve;
                    a.play().catch(resolve);
                });
                URL.revokeObjectURL(url);
                return;
            } catch (e) {
                cloudFailed.current = true;
                let message = "The ElevenLabs voice didn't load.";
                try { message = JSON.parse(await e.response.data.text()).message ?? message; } catch { /* not JSON */ }
                setError(`${message} This test call uses your browser's voice instead.`);
            }
        }
        await browserSpeak(text);
    };

    const listen = () => {
        if (!active.current) return;
        if (!Recognition || typingRef.current) { setState('listening'); return; }
        const rec = new Recognition();
        rec.lang = lang;
        rec.interimResults = false;
        let heard = false;
        rec.onresult = (e) => { heard = true; send(e.results[0][0].transcript); };
        rec.onerror = (e) => { if (['not-allowed', 'service-not-allowed'].includes(e.error)) { setError('Allow the microphone, or type instead.'); setTyping(true); } };
        rec.onend = () => { if (!heard && active.current && !busy.current && !typingRef.current) setTimeout(listen, 250); };
        recognition.current = rec;
        setState('listening');
        try { rec.start(); } catch { /* already listening */ }
    };

    const send = async (text) => {
        if (!text.trim() || busy.current) return;
        busy.current = true;
        recognition.current?.abort();
        setTurns((t) => [...t, { role: 'user', text }]);
        setState('thinking');
        try {
            const { data } = await window.axios.post(`/voice/agents/${agent.id}/talk`, { call_id: call.current, text, variables: values });
            call.current = data.call_id;
            if (data.booking) setBooking(data.booking);
            setTurns((t) => [...t, { role: 'agent', text: data.reply }]);
            setState('speaking');
            await speak(data.reply);
        } catch (e) {
            setError(errorMessage(e));
        } finally {
            busy.current = false;
            if (active.current) listen();
        }
    };

    const start = async () => {
        active.current = true;
        cloudFailed.current = false;
        call.current = null;
        setError(null); setTurns([]); setResult(null); setBooking(null);
        setStartedAt(Date.now());
        setNow(Date.now());
        setState('starting');
        try {
            const { data } = await window.axios.post(`/voice/agents/${agent.id}/talk`, { text: '', variables: values });
            call.current = data.call_id;
            if (data.reply) {
                setTurns([{ role: 'agent', text: data.reply }]);
                setState('speaking');
                await speak(data.reply);
            }
            listen();
        } catch (e) {
            active.current = false;
            setError(errorMessage(e));
            setState('idle');
        }
    };

    const end = async () => {
        active.current = false;
        recognition.current?.abort();
        audioEl.current?.pause();
        if (canSpeak) window.speechSynthesis.cancel();
        setState('ending');
        if (call.current) {
            try {
                const { data } = await window.axios.post(`/voice/agents/${agent.id}/talk/${call.current}/end`);
                setResult(data.call);
            } catch (e) {
                setError(errorMessage(e));
            }
        }
        setState('ended');
        router.reload({ only: ['recentCalls'] });
    };

    const toggleTyping = () => {
        const next = !typing;
        setTyping(next);
        typingRef.current = next;
        if (next) recognition.current?.abort();
        else if (active.current && state === 'listening') listen();
    };

    const elapsed = startedAt ? Math.max(0, Math.floor((now - startedAt) / 1000)) : 0;
    const clock = `${String(Math.floor(elapsed / 60)).padStart(2, '0')}:${String(elapsed % 60).padStart(2, '0')}`;
    const statusText = { starting: 'Connecting…', listening: typing ? 'Your turn: type what the caller says' : 'Listening…', thinking: 'Thinking…', speaking: 'Speaking…', ending: 'Saving the call…', ended: 'Call ended' }[state] ?? 'Press the green button to call';
    const blocked = !hasAi || (dirty && !inCall);

    return (
        <section className="overflow-hidden rounded-[28px] bg-[#16181d] text-white shadow-pop" aria-label="Test call">
            <div className="flex items-center justify-between px-5 pt-4 text-[12px] text-white/55">
                <span className="flex items-center gap-1.5"><span className={cn('size-1.5 rounded-full', inCall ? 'bg-ok' : 'bg-white/30')} />{inCall ? 'On a test call' : 'Test call'}</span>
                <span className="tabular-nums">{inCall || state === 'ending' ? clock : cloudVoice ? 'ElevenLabs voice' : 'Free'}</span>
            </div>

            <div className="flex flex-col items-center px-5 pt-3 pb-2 text-center">
                <p className="text-[17px] font-semibold tracking-tight">{config.persona_name || agent.name}</p>
                <p className="text-[12.5px] text-white/55">{config.business_name || 'Your business'}</p>
                <div className="my-2">
                    <VoiceOrb state={state === 'listening' ? 'listening' : state === 'thinking' || state === 'starting' ? 'thinking' : state === 'speaking' ? 'speaking' : 'idle'}
                        level={level} size={170} label={inCall ? null : (config.persona_name || agent.name || '?').charAt(0).toUpperCase()} />
                </div>
                <p className="h-5 text-[13px] font-medium text-white/80" aria-live="polite">{statusText}</p>
            </div>

            {blocked ? (
                <div className="mx-4 mb-5 rounded-2xl bg-white/[0.07] px-4 py-3 text-[13px] text-white/80">
                    {!hasAi
                        ? <>Test calls use the AI model from <Link href="/settings?tab=ai" className="font-semibold text-white underline-offset-2 hover:underline">Settings → AI builder</Link>. Gemini and DeepSeek are free or very cheap.</>
                        : <>Save your changes to hear them. <button type="button" onClick={onSave} className="font-semibold text-white underline-offset-2 hover:underline">Save now</button></>}
                </div>
            ) : (
                <>
                    {!inCall && state !== 'ending' && variables.length > 0 && (
                        <div className="mx-4 mb-3 grid grid-cols-[minmax(0,1fr)] gap-2">
                            {variables.map((v) => (
                                <input key={v} value={values[v] ?? ''} onChange={(e) => setValues({ ...values, [v]: e.target.value })} placeholder={v.replaceAll('_', ' ').replace(/^\w/, (c) => c.toUpperCase())}
                                    aria-label={v.replaceAll('_', ' ')} className="h-9 rounded-xl bg-white/[0.08] px-3 text-[13px] text-white placeholder:text-white/40 outline-none focus:ring-2 focus:ring-primary" />
                            ))}
                        </div>
                    )}

                    {(turns.length > 0 || inCall) && (
                        <div ref={scroller} className="mx-4 mb-3 max-h-64 space-y-2 overflow-y-auto rounded-2xl bg-white/[0.04] p-3">
                            {turns.length === 0 && <p className="text-center text-[12.5px] text-white/40">The conversation appears here.</p>}
                            {turns.map((t, i) => (
                                <div key={i} className={cn('flex', t.role === 'user' ? 'justify-end' : 'justify-start')}>
                                    <span className={cn('max-w-[85%] rounded-2xl px-3 py-1.5 text-[13px] leading-snug', t.role === 'user' ? 'rounded-br-md bg-primary text-primary-fg' : 'rounded-bl-md bg-white/10 text-white')}>{t.text}</span>
                                </div>
                            ))}
                        </div>
                    )}

                    {booking && (
                        <div className="mx-4 mb-3 flex items-start gap-2 rounded-2xl bg-ok/20 px-3 py-2 text-[12.5px] text-white">
                            <CalendarCheck className="mt-0.5 size-4 shrink-0 text-ok" /><span><strong>Booked</strong> {booking.name}, {booking.when}</span>
                        </div>
                    )}

                    {inCall && typing && (
                        <form className="mx-4 mb-3 flex gap-2" onSubmit={(e) => { e.preventDefault(); const t = typed; setTyped(''); send(t); }}>
                            <input value={typed} onChange={(e) => setTyped(e.target.value)} autoFocus placeholder="Type what the caller says…" aria-label="Your reply"
                                className="h-10 min-w-0 flex-1 rounded-xl bg-white/[0.08] px-3 text-[13px] text-white placeholder:text-white/40 outline-none focus:ring-2 focus:ring-primary" />
                            <button type="submit" disabled={!typed.trim()} className="flex size-10 items-center justify-center rounded-xl bg-primary disabled:opacity-40" aria-label="Send"><Send className="size-4" /></button>
                        </form>
                    )}

                    {error && <p className="mx-4 mb-3 rounded-xl bg-fail/25 px-3 py-2 text-[12.5px] text-white">{error}</p>}
                    {result && !inCall && (
                        <div className="mx-4 mb-3 rounded-2xl bg-white/[0.06] px-3.5 py-3 text-[12.5px]">
                            <p className="font-semibold">Saved to Calls{result.duration != null && `, ${seconds(result.duration)}`}</p>
                            {result.summary && <p className="mt-1 text-white/70">{result.summary}</p>}
                        </div>
                    )}

                    <div className="flex items-center justify-center gap-5 px-5 pt-1 pb-6">
                        <RoundButton label={typing ? 'Use the microphone' : 'Type instead'} onClick={toggleTyping} disabled={!Recognition}>
                            {typing ? <Mic className="size-5" /> : <Keyboard className="size-5" />}
                        </RoundButton>
                        <button type="button" onClick={inCall ? end : start} disabled={state === 'ending'} aria-label={inCall ? 'End the test call' : 'Start a test call'}
                            className={cn('flex size-16 items-center justify-center rounded-full text-white transition-transform active:scale-95 disabled:opacity-60', inCall ? 'bg-fail' : 'bg-ok')}>
                            {state === 'ending' ? <Loader2 className="size-7 animate-spin" /> : inCall ? <PhoneOff className="size-7" /> : <PhoneCall className="size-7" />}
                        </button>
                        <RoundButton label={speaker ? 'Mute the agent' : 'Unmute the agent'} onClick={() => { setSpeaker((s) => !s); if (speaker) { audioEl.current?.pause(); if (canSpeak) window.speechSynthesis.cancel(); } }} disabled={!canSpeak && !cloudVoice}>
                            {speaker ? <Volume2 className="size-5" /> : <VolumeX className="size-5" />}
                        </RoundButton>
                    </div>
                </>
            )}
        </section>
    );
}

function RoundButton({ label, onClick, disabled, children }) {
    return (
        <button type="button" onClick={onClick} disabled={disabled} aria-label={label} title={label}
            className="flex size-12 items-center justify-center rounded-full bg-white/10 text-white transition-colors hover:bg-white/20 disabled:opacity-30">{children}</button>
    );
}

function CallCard({ agent, variables, dirty }) {
    const [to, setTo] = useState('');
    const [values, setValues] = useState({});
    const [busy, setBusy] = useState(false);
    const call = async () => {
        setBusy(true);
        try {
            await window.axios.post(`/voice/agents/${agent.id}/call`, { to, variables: values });
            toast(`Calling ${to}…`, 'success');
            router.reload({ only: ['recentCalls'] });
        } catch (e) {
            toast(errorMessage(e), 'error');
        } finally {
            setBusy(false);
        }
    };
    const ready = agent.live && agent.phone_number_id && !dirty;
    return (
        <Card className="space-y-3 p-4">
            <div>
                <h2 className="text-[14px] font-semibold">Call a real number</h2>
                <p className="mt-0.5 text-[12.5px] text-fg-muted">The agent calls this number now. For automatic calls, use the "Call a phone number" step in a workflow.</p>
            </div>
            {!ready && <p className="rounded-lg bg-surface-2 px-3 py-2 text-[12.5px] text-fg-muted">{dirty ? 'Save your changes first.' : 'Pick an account and a phone number under Voice & phone, then save.'}</p>}
            <Input value={to} onChange={(e) => setTo(e.target.value)} placeholder="+923001234567" inputMode="tel" disabled={!ready} aria-label="Number to call" />
            {variables.map((v) => <Input key={v} value={values[v] ?? ''} onChange={(e) => setValues({ ...values, [v]: e.target.value })} placeholder={v.replaceAll('_', ' ')} disabled={!ready} aria-label={v.replaceAll('_', ' ')} />)}
            <Button variant="primary" onClick={call} disabled={!ready || busy || to.trim().length < 8} className="w-full justify-center">{busy ? <Loader2 className="size-4 animate-spin" /> : <PhoneCall className="size-4" />}Call now</Button>
        </Card>
    );
}
