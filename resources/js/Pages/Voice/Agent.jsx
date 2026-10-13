import { useEffect, useRef, useState } from 'react';
import { Link, router, useForm } from '@inertiajs/react';
import { ArrowLeft, CalendarCheck, CheckCircle2, ExternalLink, Globe2, Keyboard, Loader2, Mic, PhoneCall, PhoneOff, Plus, Save, Send, Sparkles, Trash2, TriangleAlert } from 'lucide-react';
import AppLayout from '@/Layouts/AppLayout';
import { Badge, Button, Card, ConfirmButton, Dialog, Field, Input, Select, Switch, Textarea } from '@/Components/ui';
import { CallSheet, CallStatus, DirectionIcon, PROVIDERS, Transcript, VoiceNav, seconds, when } from '@/Components/Voice';
import { cn, errorMessage, timeAgo, toast } from '@/lib/utils';
import { useCan } from '@/lib/can';

const LANGUAGES = [['en', 'English', 'en-US'], ['ur', 'Urdu', 'ur-PK'], ['hi', 'Hindi', 'hi-IN'], ['ar', 'Arabic', 'ar-SA'], ['es', 'Spanish', 'es-ES'], ['fr', 'French', 'fr-FR'],
    ['de', 'German', 'de-DE'], ['pt', 'Portuguese', 'pt-BR'], ['tr', 'Turkish', 'tr-TR'], ['it', 'Italian', 'it-IT'], ['nl', 'Dutch', 'nl-NL'], ['id', 'Indonesian', 'id-ID']];
const locale = (code) => LANGUAGES.find(([c]) => c === code)?.[2] ?? 'en-US';

const PROVIDER_INFO = {
    browser: { title: 'Browser (free)', text: 'Talk to the agent in your browser. No phone number or extra account, and it uses the AI model from Settings → AI builder. Great for trying agents out.' },
    vapi: {
        title: 'Vapi', text: 'Real phone calls. New accounts get free credit and can create free US numbers for testing.', app: 'vapi', link: 'https://dashboard.vapi.ai',
        steps: ['Sign up at dashboard.vapi.ai.', 'Copy your private API key from the dashboard into a Vapi credential.', 'Create a free number under Phone Numbers, or import a Twilio number.', 'Pick it below and save.'],
        voice: 'Vapi voice name, e.g. Elliot, Kylie or Rohan (or an ElevenLabs voice ID)', model: 'gpt-4o-mini',
    },
    elevenlabs: {
        title: 'ElevenLabs', text: 'Real phone calls with ElevenLabs voices. The free plan includes some agent minutes; phone numbers come from Twilio.', app: 'elevenlabs', link: 'https://elevenlabs.io/app/agents',
        steps: ['Create an ElevenLabs account and an API key (Profile → API keys).', 'Save the key as an ElevenLabs credential.', 'In ElevenLabs → Agents → Phone numbers, import your Twilio number.', 'Pick it below and save.'],
        voice: 'Voice ID from the ElevenLabs voice library (empty = Rachel)', model: 'gemini-2.0-flash',
    },
};

export default function Agent({ agent, credentials, variables, recentCalls, hasAi, timezone }) {
    const can = useCan();
    const form = useForm({
        name: agent.name, provider: agent.provider, credential_id: agent.credential_id, phone_number_id: agent.phone_number_id, phone_label: agent.phone_label,
        config: { business_name: '', first_message: '', instructions: '', language: 'en', voice: '', llm: '', booking: { enabled: false, ask_email: false, hours: '' }, collect: [], ...agent.config },
    });
    const config = form.data.config;
    const setConfig = (patch) => form.setData('config', { ...config, ...patch });
    const setBooking = (patch) => setConfig({ booking: { ...config.booking, ...patch } });
    const [openCall, setOpenCall] = useState(() => Number(new URLSearchParams(window.location.search).get('call')) || null);
    const [rewriting, setRewriting] = useState(false);
    const save = () => form.put(`/voice/agents/${agent.id}`, { preserveScroll: true });

    // Phone calls in progress: refresh the list until they finish.
    const live = recentCalls.some((c) => ['queued', 'ringing', 'in-progress'].includes(c.status) && c.direction !== 'web');
    useEffect(() => {
        if (!live) return;
        const timer = setInterval(() => router.reload({ only: ['recentCalls'] }), 8000);
        return () => clearInterval(timer);
    }, [live]);

    const provider = PROVIDERS[agent.provider] ?? PROVIDERS.browser;
    const editable = can['workflows.edit'];

    return (
        <AppLayout title={agent.name} description="Set what the agent says and does, try it in your browser, then connect a phone provider for real calls."
            actions={editable && <Button variant="primary" onClick={save} loading={form.processing} disabled={!form.isDirty}>{!form.processing && <Save className="size-4" />}Save</Button>}>
            <VoiceNav />
            <div className="mb-4 flex flex-wrap items-center gap-2 text-[13px]">
                <Link href="/voice" className="flex items-center gap-1 text-fg-muted hover:text-fg"><ArrowLeft className="size-3.5" />All agents</Link>
                <span className="text-fg-subtle">·</span>
                <Badge tone={provider.tone}>{provider.label}{provider.hint && ` · ${provider.hint}`}</Badge>
                {agent.provider !== 'browser' && (agent.problem
                    ? <span className="flex items-center gap-1 text-fail"><TriangleAlert className="size-3.5" />Not live: {agent.problem}</span>
                    : agent.live ? <span className="flex items-center gap-1 text-ok"><CheckCircle2 className="size-3.5" />Live{agent.phone_label && ` on ${agent.phone_label}`}{agent.synced_at && ` · saved ${timeAgo(agent.synced_at)}`}</span>
                    : <span className="text-fg-muted">Not on {provider.label} yet: save to publish it.</span>)}
                {form.isDirty && <span className="ml-auto text-[12.5px] text-warn">Unsaved changes</span>}
            </div>

            <div className="grid grid-cols-[minmax(0,1fr)] gap-5 lg:grid-cols-[minmax(0,1fr)_380px]">
                <fieldset disabled={!editable} className="min-w-0 space-y-5">
                    <Card className="space-y-4 p-5">
                        <h2 className="text-[15px] font-semibold">Basics</h2>
                        <div className="grid grid-cols-[minmax(0,1fr)] gap-4 sm:grid-cols-2">
                            <Field label="Agent name" required error={form.errors.name}>{(id) => <Input id={id} value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} />}</Field>
                            <Field label="Business name" help="Used wherever the texts say {{business_name}}.">{(id) => <Input id={id} value={config.business_name ?? ''} onChange={(e) => setConfig({ business_name: e.target.value })} placeholder="Smile Dental Clinic" />}</Field>
                        </div>
                        <Field label="Language" help="The language it listens and speaks in. It can still switch if the caller does.">
                            {(id) => (
                                <Select id={id} value={config.language ?? 'en'} onChange={(e) => setConfig({ language: e.target.value })} className="sm:w-64">
                                    {LANGUAGES.map(([code, label]) => <option key={code} value={code}>{label}</option>)}
                                </Select>
                            )}
                        </Field>
                    </Card>

                    <Card className="space-y-4 p-5">
                        <div className="flex flex-wrap items-center justify-between gap-2">
                            <h2 className="text-[15px] font-semibold">What it says</h2>
                            {editable && <Button size="sm" onClick={() => setRewriting(true)}><Sparkles className="size-3.5" />Rewrite with AI</Button>}
                        </div>
                        <Field label="First message" help="Spoken when the call connects. Leave empty to let the caller speak first. Per-call details like {{customer_name}} are filled in when you start a call.">
                            {(id) => <Textarea id={id} rows={2} value={config.first_message ?? ''} onChange={(e) => setConfig({ first_message: e.target.value })} />}
                        </Field>
                        <Field label="Instructions" required error={form.errors['config.instructions']} help="Who it is, what it should achieve, how it talks and what it must never do. FlowForge adds the phone-manners and booking rules.">
                            {(id) => <Textarea id={id} rows={14} value={config.instructions ?? ''} onChange={(e) => setConfig({ instructions: e.target.value })} className="text-[13.5px] leading-relaxed" />}
                        </Field>
                    </Card>

                    <Card className="space-y-4 p-5">
                        <div className="flex items-start justify-between gap-4">
                            <div>
                                <h2 className="text-[15px] font-semibold">Bookings</h2>
                                <p className="mt-0.5 text-[13px] text-fg-muted">The agent books appointments during the call. They appear under Bookings and can start workflows, e.g. add them to Google Calendar.</p>
                            </div>
                            <Switch checked={!!config.booking?.enabled} onChange={(v) => setBooking({ enabled: v })} label="Take bookings" />
                        </div>
                        {config.booking?.enabled && (
                            <div className="grid grid-cols-[minmax(0,1fr)] gap-4 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-end">
                                <Field label="Opening hours" help="Told to the agent so it offers suitable times.">{(id) => <Input id={id} value={config.booking.hours ?? ''} onChange={(e) => setBooking({ hours: e.target.value })} placeholder="Monday to Friday, 9:00 to 17:00" />}</Field>
                                <label className="flex items-center gap-2 pb-2 text-[13.5px]"><Switch checked={!!config.booking.ask_email} onChange={(v) => setBooking({ ask_email: v })} label="Ask for email" size="sm" />Ask for an email address</label>
                            </div>
                        )}
                    </Card>

                    <CollectCard collect={config.collect ?? []} onChange={(collect) => setConfig({ collect })} />

                    <PhoneCard agent={agent} form={form} config={config} setConfig={setConfig} credentials={credentials} />

                    {can['workflows.delete'] && (
                        <div className="flex justify-end">
                            <ConfirmButton onConfirm={() => router.delete(`/voice/agents/${agent.id}`)} confirmLabel="Click again to delete it and its call history">
                                <Trash2 className="size-3.5" />Delete agent
                            </ConfirmButton>
                        </div>
                    )}
                </fieldset>

                <div className="min-w-0 space-y-5 lg:sticky lg:top-6 lg:self-start">
                    <TalkPanel agent={agent} variables={variables} dirty={form.isDirty} hasAi={hasAi} onSave={save} />
                    {agent.provider !== 'browser' && editable && <CallCard agent={agent} variables={variables} dirty={form.isDirty} />}
                    <Card className="p-5">
                        <div className="mb-3 flex items-center justify-between">
                            <h2 className="text-[14px] font-semibold">Recent calls</h2>
                            <Link href={`/voice/calls?agent=${agent.id}`} className="text-[12.5px] font-medium text-primary hover:underline">All calls</Link>
                        </div>
                        {recentCalls.length === 0 ? <p className="text-[13px] text-fg-subtle">No calls yet.</p> : (
                            <ul className="-mx-2 space-y-0.5">
                                {recentCalls.map((c) => (
                                    <li key={c.id}>
                                        <button type="button" onClick={() => setOpenCall(c.id)} className="flex w-full items-center gap-2.5 rounded-lg px-2 py-2 text-left text-[13px] hover:bg-surface-2">
                                            <DirectionIcon direction={c.direction} />
                                            <span className="min-w-0 flex-1 truncate">{c.direction === 'web' ? 'Browser' : (c.direction === 'inbound' ? c.from : c.to) ?? 'Call'}<span className="ml-2 text-fg-subtle">{when(c.started_at, timezone)}</span></span>
                                            <span className="text-fg-subtle tabular-nums">{seconds(c.duration)}</span>
                                            <CallStatus status={c.status} />
                                        </button>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </Card>
                </div>
            </div>

            <RewriteDialog open={rewriting} onClose={() => setRewriting(false)} hasAi={hasAi} onDone={(a) => { setConfig({ ...a, collect: a.collect, booking: a.booking }); setRewriting(false); toast('Rewritten. Review it, then save.', 'success'); }} />
            <CallSheet callId={openCall} onClose={() => setOpenCall(null)} />
        </AppLayout>
    );
}

function CollectCard({ collect, onChange }) {
    const set = (i, patch) => onChange(collect.map((f, j) => (j === i ? { ...f, ...patch } : f)));
    return (
        <Card className="space-y-4 p-5">
            <div>
                <h2 className="text-[15px] font-semibold">Details to capture</h2>
                <p className="mt-0.5 text-[13px] text-fg-muted">After each call, these are pulled from the conversation and shown with the call. Workflows get them too.</p>
            </div>
            {collect.length > 0 && (
                <div className="space-y-2">
                    {collect.map((f, i) => (
                        <div key={i} className="grid grid-cols-[minmax(0,2fr)_minmax(0,3fr)_auto] gap-2">
                            <Input value={f.key} onChange={(e) => set(i, { key: e.target.value })} placeholder="budget" aria-label={`Detail ${i + 1} name`} />
                            <Input value={f.description} onChange={(e) => set(i, { description: e.target.value })} placeholder="The budget the caller mentions" aria-label={`Detail ${i + 1} description`} />
                            <Button variant="ghost" size="icon" onClick={() => onChange(collect.filter((_, j) => j !== i))} aria-label={`Remove detail ${i + 1}`}><Trash2 className="size-3.5" /></Button>
                        </div>
                    ))}
                </div>
            )}
            <Button size="sm" variant="ghost" onClick={() => onChange([...collect, { key: '', description: '' }])}><Plus className="size-3.5" />Add a detail</Button>
        </Card>
    );
}

function PhoneCard({ agent, form, config, setConfig, credentials }) {
    const info = PROVIDER_INFO[form.data.provider];
    const options = credentials.filter((c) => c.integration === info.app);
    const [numbers, setNumbers] = useState(null);
    const [loading, setLoading] = useState(false);
    const loadNumbers = async () => {
        setLoading(true);
        try {
            const { data } = await window.axios.get(`/voice/agents/${agent.id}/numbers`, { params: { provider: form.data.provider, credential_id: form.data.credential_id } });
            setNumbers(data.numbers);
            if (data.numbers.length === 0) toast('No phone numbers on this account yet. Create or import one with the provider first.', 'error');
        } catch (e) {
            toast(errorMessage(e), 'error');
        } finally {
            setLoading(false);
        }
    };
    const choose = (provider) => { form.setData({ ...form.data, provider, credential_id: null, phone_number_id: null, phone_label: null }); setNumbers(null); };

    return (
        <Card className="space-y-4 p-5">
            <div>
                <h2 className="text-[15px] font-semibold">Phone</h2>
                <p className="mt-0.5 text-[13px] text-fg-muted">Where calls happen. Start free in the browser; switch to a phone provider when you're ready for real calls.</p>
            </div>
            <div className="grid grid-cols-[minmax(0,1fr)] gap-2 sm:grid-cols-3">
                {Object.entries(PROVIDER_INFO).map(([key, p]) => (
                    <button key={key} type="button" onClick={() => choose(key)} aria-pressed={form.data.provider === key}
                        className={cn('rounded-xl border-2 px-3.5 py-3 text-left transition-colors', form.data.provider === key ? 'border-primary bg-primary-soft/50' : 'border-line hover:border-line-strong')}>
                        <span className="flex items-center gap-2 text-[13.5px] font-semibold">{key === 'browser' ? <Globe2 className="size-4" /> : <PhoneCall className="size-4" />}{p.title}</span>
                        <span className="mt-1 block text-[12px] leading-snug text-fg-muted">{p.text}</span>
                    </button>
                ))}
            </div>

            {info.steps && (
                <>
                    <ol className="list-decimal space-y-1 rounded-lg bg-surface-2 py-3 pr-3 pl-8 text-[12.5px] text-fg-muted">
                        {info.steps.map((s) => <li key={s}>{s}</li>)}
                        <li className="list-none -ml-5 pt-1"><a href={info.link} target="_blank" rel="noreferrer" className="inline-flex items-center gap-1 font-medium text-primary hover:underline">Open {info.title}<ExternalLink className="size-3" /></a></li>
                    </ol>
                    <div className="grid grid-cols-[minmax(0,1fr)] gap-4 sm:grid-cols-2">
                        <Field label={`${info.title} account`} help={options.length ? null : <>No {info.title} credential yet. <a href={`/credentials?new=${info.app}`} className="font-medium text-primary hover:underline">Add your API key</a>.</>}>
                            {(id) => (
                                <Select id={id} value={form.data.credential_id ?? ''} onChange={(e) => { form.setData({ ...form.data, credential_id: e.target.value ? Number(e.target.value) : null, phone_number_id: null, phone_label: null }); setNumbers(null); }}>
                                    <option value="">Choose…</option>
                                    {options.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
                                </Select>
                            )}
                        </Field>
                        <Field label="Phone number" help="Calls to this number reach the agent, and its outgoing calls come from it.">
                            {(id) => numbers ? (
                                <Select id={id} value={form.data.phone_number_id ?? ''} onChange={(e) => form.setData({ ...form.data, phone_number_id: e.target.value || null, phone_label: numbers.find((n) => n.id === e.target.value)?.label ?? null })}>
                                    <option value="">Choose…</option>
                                    {numbers.map((n) => <option key={n.id} value={n.id}>{n.label}</option>)}
                                </Select>
                            ) : (
                                <div className="flex gap-2">
                                    <Input id={id} readOnly value={form.data.phone_label ?? ''} placeholder="No number yet" />
                                    <Button onClick={loadNumbers} disabled={!form.data.credential_id || loading}>{loading ? <Loader2 className="size-4 animate-spin" /> : null}Load</Button>
                                </div>
                            )}
                        </Field>
                        <Field label="Voice" help={info.voice}>{(id) => <Input id={id} value={config.voice ?? ''} onChange={(e) => setConfig({ voice: e.target.value })} placeholder={form.data.provider === 'vapi' ? 'Elliot' : '21m00Tcm4TlvDq8ikWAM'} />}</Field>
                        <Field label="AI model" help={`Optional. Empty uses ${info.model}.`}>{(id) => <Input id={id} value={config.llm ?? ''} onChange={(e) => setConfig({ llm: e.target.value })} placeholder={info.model} />}</Field>
                    </div>
                </>
            )}
        </Card>
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
        <Dialog open={open} onClose={onClose} title="Rewrite with AI" description="Describe the agent; the AI rewrites its first message, instructions, booking and details to capture.">
            {hasAi ? (
                <div className="space-y-3">
                    <Textarea rows={5} value={text} onChange={(e) => setText(e.target.value)} placeholder="Outbound calls to people who filled in our website form. Find out what they need and their budget, then book a call with our sales team." aria-label="Describe the agent" />
                    {error && <p className="rounded-lg bg-fail-soft px-3 py-2 text-[13px] text-fail">{error}</p>}
                    <Button variant="primary" onClick={run} disabled={busy || text.trim().length < 10}>{busy ? <Loader2 className="size-4 animate-spin" /> : <Sparkles className="size-4" />}{busy ? 'Writing…' : 'Rewrite'}</Button>
                </div>
            ) : <p className="text-[13px] text-fg-muted">Add an AI provider under <Link href="/settings?tab=ai" className="font-medium text-primary hover:underline">Settings → AI builder</Link> first.</p>}
        </Dialog>
    );
}

function VariableInputs({ variables, values, onChange }) {
    if (!variables.length) return null;
    return (
        <div className="grid grid-cols-[minmax(0,1fr)] gap-2 sm:grid-cols-2 lg:grid-cols-1">
            {variables.map((v) => (
                <Field key={v} label={v.replaceAll('_', ' ').replace(/^\w/, (c) => c.toUpperCase())}>
                    {(id) => <Input id={id} value={values[v] ?? ''} onChange={(e) => onChange({ ...values, [v]: e.target.value })} />}
                </Field>
            ))}
        </div>
    );
}

/** The free browser conversation: the browser listens and speaks, FlowForge's AI model answers. */
function TalkPanel({ agent, variables, dirty, hasAi, onSave }) {
    const Recognition = window.SpeechRecognition || window.webkitSpeechRecognition;
    const canSpeak = 'speechSynthesis' in window;
    const lang = locale(agent.config?.language);
    const [state, setState] = useState('idle'); // idle | starting | listening | thinking | speaking | ending | ended
    const [turns, setTurns] = useState([]);
    const [values, setValues] = useState({});
    const [typed, setTyped] = useState('');
    const [error, setError] = useState(null);
    const [result, setResult] = useState(null);
    const [booking, setBooking] = useState(null);
    const [voiceOn, setVoiceOn] = useState(canSpeak);
    const call = useRef(null);
    const active = useRef(false);
    const busy = useRef(false);
    const recognition = useRef(null);
    const scroller = useRef(null);
    const voiceRef = useRef(voiceOn);
    voiceRef.current = voiceOn;

    useEffect(() => () => { active.current = false; recognition.current?.abort(); if (canSpeak) window.speechSynthesis.cancel(); }, []);
    useEffect(() => { scroller.current?.scrollTo({ top: scroller.current.scrollHeight, behavior: 'smooth' }); }, [turns]);

    const speak = (text) => new Promise((resolve) => {
        if (!canSpeak || !voiceRef.current || !text) return resolve();
        window.speechSynthesis.cancel();
        const utterance = new SpeechSynthesisUtterance(text);
        utterance.lang = lang;
        const voice = window.speechSynthesis.getVoices().find((v) => v.lang?.toLowerCase().startsWith(lang.slice(0, 2)));
        if (voice) utterance.voice = voice;
        utterance.onend = resolve;
        utterance.onerror = resolve;
        window.speechSynthesis.speak(utterance);
    });

    const listen = () => {
        if (!active.current) return;
        if (!Recognition) { setState('listening'); return; } // this browser can't listen: the caller types
        const rec = new Recognition();
        rec.lang = lang;
        rec.interimResults = false;
        rec.maxAlternatives = 1;
        let heard = false;
        rec.onresult = (e) => { heard = true; send(e.results[0][0].transcript); };
        rec.onerror = (e) => {
            if (e.error === 'not-allowed' || e.error === 'service-not-allowed') { setError('Allow the microphone to talk, or type below.'); active.current && setState('listening'); }
        };
        // Silence ends a recognition round: keep listening until the conversation ends.
        rec.onend = () => { if (!heard && active.current && !busy.current) setTimeout(listen, 250); };
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
        call.current = null;
        setError(null); setTurns([]); setResult(null); setBooking(null);
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

    const inCall = ['starting', 'listening', 'thinking', 'speaking'].includes(state);
    const status = { starting: 'Connecting…', listening: Recognition ? 'Listening… speak now' : 'Type your reply below', thinking: 'Thinking…', speaking: 'Speaking…', ending: 'Wrapping up the call…' }[state];

    return (
        <Card className="p-5">
            <div className="mb-1 flex items-center justify-between gap-2">
                <h2 className="text-[14px] font-semibold">Talk to it</h2>
                <Badge tone="ok">Free</Badge>
            </div>
            <p className="mb-4 text-[12.5px] text-fg-muted">A test conversation in your browser: it listens through your microphone and answers out loud. Bookings and summaries are saved like real calls.</p>

            {!hasAi ? (
                <p className="rounded-lg bg-surface-2 px-3.5 py-3 text-[13px] text-fg-muted">This uses the AI model from <Link href="/settings?tab=ai" className="font-medium text-primary hover:underline">Settings → AI builder</Link>. Add one there (Gemini and DeepSeek are free or very cheap).</p>
            ) : dirty && !inCall ? (
                <div className="rounded-lg bg-warn-soft px-3.5 py-3 text-[13px] text-warn">Save your changes to test them. <button type="button" onClick={onSave} className="font-semibold underline-offset-2 hover:underline">Save now</button></div>
            ) : (
                <>
                    {!inCall && state !== 'ending' && <div className="mb-3"><VariableInputs variables={variables} values={values} onChange={setValues} /></div>}

                    <div className="flex flex-col items-center py-2">
                        <button type="button" onClick={inCall ? end : start} disabled={state === 'ending'} aria-label={inCall ? 'End the conversation' : 'Start talking'}
                            className={cn('relative flex size-20 items-center justify-center rounded-full text-white shadow-pop transition-transform active:scale-95 disabled:opacity-60',
                                inCall ? 'bg-fail' : 'bg-primary')}>
                            {state === 'listening' && <span className="absolute inset-0 animate-ping rounded-full bg-primary/30" aria-hidden />}
                            {state === 'starting' || state === 'thinking' || state === 'ending' ? <Loader2 className="size-8 animate-spin" /> : inCall ? <PhoneOff className="size-8" /> : <Mic className="size-8" />}
                        </button>
                        <p className="mt-3 h-5 text-[13px] font-medium text-fg-muted" aria-live="polite">{status ?? (state === 'ended' ? 'Conversation ended' : 'Press to start')}</p>
                        {canSpeak && <label className="mt-2 flex items-center gap-2 text-[12.5px] text-fg-muted"><Switch size="sm" checked={voiceOn} onChange={setVoiceOn} label="Speak replies" />Speak replies out loud</label>}
                    </div>

                    {(turns.length > 0 || inCall) && (
                        <div ref={scroller} className="mt-3 max-h-80 overflow-y-auto rounded-xl border border-line bg-surface-2/40 p-3">
                            <Transcript turns={turns} live />
                        </div>
                    )}

                    {inCall && (
                        <form className="mt-3 flex gap-2" onSubmit={(e) => { e.preventDefault(); const t = typed; setTyped(''); send(t); }}>
                            <Input value={typed} onChange={(e) => setTyped(e.target.value)} placeholder={Recognition ? 'Or type a reply…' : 'Type a reply…'} aria-label="Type a reply" />
                            <Button type="submit" size="icon" disabled={!typed.trim()} aria-label="Send"><Send className="size-4" /></Button>
                        </form>
                    )}
                    {!Recognition && !inCall && state === 'idle' && <p className="mt-2 flex items-center gap-1.5 text-[12px] text-fg-subtle"><Keyboard className="size-3.5" />This browser can't listen; you'll type your side. Chrome and Edge can listen.</p>}

                    {booking && (
                        <div className="mt-3 flex items-start gap-2 rounded-lg border border-ok/30 bg-ok-soft/60 px-3 py-2 text-[13px]">
                            <CalendarCheck className="mt-0.5 size-4 shrink-0 text-ok" /><span><strong>Booked:</strong> {booking.name}, {booking.when}{booking.service && ` (${booking.service})`}</span>
                        </div>
                    )}
                    {error && <p className="mt-3 rounded-lg bg-fail-soft px-3 py-2 text-[13px] text-fail">{error}</p>}
                    {result && (
                        <div className="mt-3 space-y-1.5 rounded-lg border border-line px-3.5 py-3 text-[13px]">
                            <p className="font-medium">Call saved{result.duration != null && ` · ${seconds(result.duration)}`}</p>
                            {result.summary && <p className="text-fg-muted">{result.summary}</p>}
                            {Object.entries(result.data ?? {}).filter(([k, v]) => k !== 'variables' && typeof v !== 'object').map(([k, v]) => <p key={k} className="text-[12.5px]"><span className="text-fg-subtle">{k.replaceAll('_', ' ')}:</span> {String(v)}</p>)}
                        </div>
                    )}
                </>
            )}
        </Card>
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
        <Card className="space-y-3 p-5">
            <div>
                <h2 className="text-[14px] font-semibold">Call a phone number</h2>
                <p className="mt-0.5 text-[12.5px] text-fg-muted">The agent calls this number now. To call people automatically, use the "Call a phone number" step in a workflow.</p>
            </div>
            {!ready && <p className="rounded-lg bg-surface-2 px-3 py-2 text-[12.5px] text-fg-muted">{dirty ? 'Save your changes first.' : 'Connect an account and a phone number on the Phone card, then save.'}</p>}
            <Field label="Number" help="International format, e.g. +923001234567.">{(id) => <Input id={id} value={to} onChange={(e) => setTo(e.target.value)} placeholder="+14155550123" inputMode="tel" disabled={!ready} />}</Field>
            <VariableInputs variables={variables} values={values} onChange={setValues} />
            <Button variant="primary" onClick={call} disabled={!ready || busy || to.trim().length < 8}>{busy ? <Loader2 className="size-4 animate-spin" /> : <PhoneCall className="size-4" />}Call now</Button>
        </Card>
    );
}
