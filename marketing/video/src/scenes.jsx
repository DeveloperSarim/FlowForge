import { AbsoluteFill, Img, interpolate, staticFile, useCurrentFrame, useVideoConfig, Easing } from 'remotion';
import { ArrowUp, CalendarCheck, Check, KeyRound, Loader2, Network, Phone, RefreshCw, ShieldCheck, Sparkles, Star, TrendingUp, Users, Webhook, X, Zap } from 'lucide-react';
import { Browser, C, Chip, DotBg, LogoMark, MONO, Words, clamp, springAt, useIn } from './ui';

/* 1. Logo reveal */
export const Intro = () => {
    const frame = useCurrentFrame();
    const word = 'FlowForge';
    return (
        <DotBg camera={{ keys: [[0, 1], [90, 1.08]] }}>
            <AbsoluteFill style={{ alignItems: 'center', justifyContent: 'center', flexDirection: 'column', gap: 40 }}>
                <div style={{ display: 'flex', alignItems: 'center', gap: 36 }}>
                    <LogoMark size={150} />
                    <div style={{ fontSize: 132, fontWeight: 600, letterSpacing: '-0.045em', display: 'flex' }}>
                        {word.split('').map((ch, i) => {
                            const p = interpolate(frame, [18 + i * 2, 30 + i * 2], [0, 1], { ...clamp, easing: Easing.out(Easing.cubic) });
                            return <span key={i} style={{ opacity: p, transform: `translateY(${(1 - p) * 40}px)`, display: 'inline-block' }}>{ch}</span>;
                        })}
                    </div>
                </div>
                <div style={{ display: 'flex', gap: 18 }}>
                    <Words text="Automate the busywork." delay={40} size={56} weight={500} color={C.ink2} />
                    <Words text="Answer every call." delay={54} size={56} weight={600} color={C.brand} />
                </div>
            </AbsoluteFill>
        </DotBg>
    );
};

/* 2. The problem: a receipt of monthly bills prints, then gets cancelled */
const BILLS = [
    { item: 'Automation platform', note: 'pay for every task', amount: 'per task', strike: 64 },
    { item: 'Server to self-host it', note: 'VPS, Docker, upkeep', amount: 'per month', strike: 70 },
    { item: 'AI phone receptionist', note: 'billed for every call', amount: 'per minute', strike: 76 },
];
const PRINT = [8, 46]; // the receipt feeds out of the printer
const STAMP_AT = 90; // lands on the beat

const Dashed = () => <div style={{ margin: '26px 0', borderTop: `3px dashed ${C.line}` }} />;

export const Problem = () => {
    const frame = useCurrentFrame();
    const printed = interpolate(frame, PRINT, [0, 1], { ...clamp, easing: Easing.out(Easing.cubic) });
    const feed = Math.floor(printed * 36) / 36; // steps, like a receipt printer
    const stamp = interpolate(frame, [STAMP_AT, STAMP_AT + 7], [2.6, 1], { ...clamp, easing: Easing.out(Easing.back(1.4)) });
    const shake = frame >= STAMP_AT && frame < STAMP_AT + 8 ? Math.sin(frame * 9) * (STAMP_AT + 8 - frame) * 1.6 : 0;
    const total = useIn(48);
    return (
        <DotBg camera={{ keys: [[0, 1.03], [STAMP_AT, 1], [135, 1.05]], origin: [1400, 560] }}>
            <AbsoluteFill style={{ transform: `translate(${shake}px, ${shake * 0.6}px)` }}>
                <div style={{ position: 'absolute', left: 150, top: 370, width: 780 }}>
                    <Words text="Automation gets expensive." size={100} weight={700} />
                    <Words text="Per-task fees, a server to look after, and now a phone bill for AI." delay={14} size={36} weight={500} color={C.ink2} style={{ marginTop: 34, lineHeight: 1.4, width: 680 }} />
                </div>
                {/* The printer slot, and the receipt feeding out of it */}
                <div style={{ position: 'absolute', left: 1010, top: 196, width: 780, height: 38, borderRadius: 19, background: C.ink, boxShadow: '0 10px 30px rgba(16,24,40,.25)', zIndex: 2 }} />
                <div style={{ position: 'absolute', left: 1050, top: 215, width: 700, height: 820, overflow: 'hidden' }}>
                    <div style={{ transform: `translateY(${(feed - 1) * 820}px)`, filter: 'drop-shadow(0 24px 40px rgba(16,24,40,.14))' }}>
                        <div style={{ background: C.paper, padding: '66px 52px 38px' }}>
                            <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'baseline' }}>
                                <div style={{ fontSize: 40, fontWeight: 650, letterSpacing: '-0.02em' }}>Your monthly bills</div>
                                <div style={{ fontFamily: MONO, fontSize: 25, color: C.muted }}>October</div>
                            </div>
                            <Dashed />
                            {BILLS.map((b) => {
                                const cut = interpolate(frame, [b.strike, b.strike + 7], [0, 1], { ...clamp, easing: Easing.inOut(Easing.cubic) });
                                return (
                                    <div key={b.item} style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start', padding: '18px 0' }}>
                                        <div>
                                            <div style={{ fontSize: 35, fontWeight: 600 }}>{b.item}</div>
                                            <div style={{ fontFamily: MONO, fontSize: 23, color: C.muted, marginTop: 8 }}>{b.note}</div>
                                        </div>
                                        <div style={{ position: 'relative', fontFamily: MONO, fontSize: 31, fontWeight: 500, color: cut > 0.5 ? C.muted : C.ink, paddingTop: 3 }}>
                                            {b.amount}
                                            <div style={{ position: 'absolute', left: -6, top: '54%', height: 5, borderRadius: 3, background: C.brand, width: `calc(${cut * 100}% + ${cut * 12}px)` }} />
                                        </div>
                                    </div>
                                );
                            })}
                            <Dashed />
                            <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', opacity: total }}>
                                <div style={{ fontSize: 40, fontWeight: 700 }}>Total</div>
                                <div style={{ display: 'flex', alignItems: 'center', gap: 12, fontFamily: MONO, fontSize: 31, fontWeight: 600, color: C.fail }}>
                                    <TrendingUp size={36} strokeWidth={2.5} /> every month
                                </div>
                            </div>
                        </div>
                        <svg width="700" height="22" viewBox="0 0 700 22" style={{ display: 'block' }}>
                            <path d={`M0 0 H700 V4 ${Array.from({ length: 35 }, (_, i) => `L${700 - i * 20 - 10} 22 L${700 - (i + 1) * 20} 4`).join(' ')} Z`} fill={C.paper} />
                        </svg>
                    </div>
                </div>
                {/* The stamp */}
                {frame >= STAMP_AT && (
                    <div style={{ position: 'absolute', left: 1400, top: 560, transform: `translate(-50%, -50%) rotate(-11deg) scale(${stamp})`, opacity: interpolate(frame, [STAMP_AT, STAMP_AT + 2], [0, 0.94], clamp),
                        border: `8px solid ${C.brand}`, borderRadius: 22, padding: '16px 44px', color: C.brand, fontSize: 104, fontWeight: 800, letterSpacing: '0.06em', background: 'rgba(253,240,234,.55)' }}>
                        CANCELLED
                    </div>
                )}
            </AbsoluteFill>
        </DotBg>
    );
};

/* 3. The question */
export const Hook = () => (
    <DotBg dark camera={{ keys: [[0, 1], [90, 1.14]] }}>
        <AbsoluteFill style={{ alignItems: 'center', justifyContent: 'center', textAlign: 'center' }}>
            <Words text="What if it all ran on the hosting" delay={2} size={96} weight={600} />
            <Words text="you already have?" delay={14} size={96} weight={600} color={C.brand} />
        </AbsoluteFill>
    </DotBg>
);

/* 4. Meet FlowForge */
export const Meet = () => {
    const p = useIn(10);
    return (
        <DotBg camera={{ keys: [[0, 1], [50, 1], [115, 1.45]], origin: [870, 740] }}>
            <AbsoluteFill style={{ alignItems: 'center', paddingTop: 90 }}>
                <Words text="Meet FlowForge." delay={0} size={84} />
                <div style={{ perspective: 2200, marginTop: 56 }}>
                    <Browser width={1460} style={{ transform: `translateY(${(1 - p) * 420}px) rotateX(${(1 - p) * 32}deg) scale(${0.88 + p * 0.12})`, transformOrigin: '50% 0%' }}>
                        <Img src={staticFile('shots/overview.png')} style={{ width: '100%', display: 'block' }} />
                    </Browser>
                </div>
            </AbsoluteFill>
        </DotBg>
    );
};

/* 5. Building a workflow on the canvas */
const NODE_Y = 540;
const NS = 200; // node size

/** A step on the canvas: logo tile, status ring and badge, label underneath. */
const Node = ({ x, y = NODE_Y, logo, label, sub, trigger, appear, state, badge }) => {
    const ring = state === 'ok' ? '#7cc49a' : state === 'fail' ? '#f0a3a3' : C.line;
    return (
        <div style={{ position: 'absolute', left: x - NS / 2, top: y - NS / 2, width: NS, transform: `scale(${appear})`, opacity: Math.min(1, appear * 1.5) }}>
            <div style={{ width: NS, height: NS, borderRadius: trigger ? `${NS / 2}px 40px 40px ${NS / 2}px` : 40, background: C.paper, border: `3px solid ${ring}`, display: 'grid', placeItems: 'center', boxShadow: '0 16px 40px rgba(16,24,40,.12)', position: 'relative' }}>
                <Img src={staticFile(`logos/${logo}.svg`)} style={{ width: 96, height: 96 }} />
                {trigger && <div style={{ position: 'absolute', left: -20, top: NS / 2 - 22, width: 44, height: 44, borderRadius: 22, background: C.brand, display: 'grid', placeItems: 'center', border: '4px solid #fff' }}><Zap size={20} color="#fff" fill="#fff" /></div>}
                {badge}
            </div>
            <div style={{ marginTop: 28, textAlign: 'center', width: 400, marginLeft: -100, fontSize: 36, fontWeight: 600 }}>{label}</div>
            <div style={{ textAlign: 'center', width: 400, marginLeft: -100, fontSize: 28, color: C.muted }}>{sub}</div>
        </div>
    );
};

const StatusDot = ({ kind, scale }) => (
    <div style={{ position: 'absolute', right: -14, bottom: -14, width: 52, height: 52, borderRadius: 26, background: kind === 'fail' ? C.fail : C.ok, display: 'grid', placeItems: 'center', border: '4px solid #fff', transform: `scale(${scale})` }}>
        {kind === 'fail' ? <X size={26} color="#fff" strokeWidth={3.5} /> : <Check size={24} color="#fff" strokeWidth={3.5} />}
    </div>
);

const BUILD = [
    { x: 500, logo: 'typeform', label: 'New response', sub: 'Typeform', trigger: true },
    { x: 960, logo: 'hubspot', label: 'Create contact', sub: 'HubSpot' },
    { x: 1420, logo: 'slack', label: 'Post to #sales', sub: 'Slack' },
];
const DONE_AT = [98, 127, 156]; // frames when the test run reaches each node

export const Builder = () => {
    const frame = useCurrentFrame();
    const { fps } = useVideoConfig();
    const pulse = interpolate(frame, [DONE_AT[0], DONE_AT[2]], [0, 1], clamp);
    const pulseX = BUILD[0].x + pulse * (BUILD[2].x - BUILD[0].x);
    const press = interpolate(frame, [86, 90, 96], [1, 0.93, 1], clamp);
    const button = useIn(70, true);
    return (
        <DotBg zoom={false} camera={{ keys: [[0, 1.4], [60, 1], [100, 1], [180, 1.12]], origin: [520, 600] }}>
            <div style={{ position: 'absolute', left: 150, top: 120 }}>
                <Words text="Build workflows visually." delay={26} size={80} />
            </div>
            <svg width="1920" height="1080" style={{ position: 'absolute', inset: 0 }}>
                {[0, 1].map((i) => {
                    const a = BUILD[i].x + NS / 2, b = BUILD[i + 1].x - NS / 2;
                    const draw = interpolate(frame, [32 + i * 30, 54 + i * 30], [1, 0], clamp);
                    return <line key={i} x1={a} y1={NODE_Y} x2={b} y2={NODE_Y} stroke={frame >= DONE_AT[i + 1] ? C.ok : '#b9c0ca'} strokeWidth="5" strokeLinecap="round" pathLength="1" strokeDasharray="1" strokeDashoffset={draw} />;
                })}
                {pulse > 0 && pulse < 1 && <circle cx={pulseX} cy={NODE_Y} r="13" fill={C.brand} stroke="#fff" strokeWidth="4" />}
            </svg>
            {BUILD.map((n, i) => {
                const done = frame >= DONE_AT[i];
                return <Node key={i} {...n} appear={springAt(frame, fps, 6 + i * 30, true)} state={done ? 'ok' : null}
                    badge={<StatusDot kind="ok" scale={done ? springAt(frame, fps, DONE_AT[i], true) : 0} />} />;
            })}
            <div style={{ position: 'absolute', left: 0, right: 0, bottom: 120, display: 'flex', justifyContent: 'center' }}>
                <div style={{ transform: `scale(${button * press})`, background: C.brand, color: '#fff', fontSize: 32, fontWeight: 600, padding: '20px 40px', borderRadius: 16, display: 'flex', gap: 14, alignItems: 'center', boxShadow: '0 12px 30px rgba(238,90,36,.3)' }}>
                    <Zap size={30} /> Execute workflow
                </div>
            </div>
        </DotBg>
    );
};

/* 6. 1,690 apps, and custom apps for the rest */
const LOGOS = ['gmail', 'slack', 'google_sheets', 'hubspot', 'shopify', 'stripe', 'openai', 'notion', 'airtable', 'whatsapp_business', 'telegram', 'google_calendar',
    'linkedin', 'trello', 'jira', 'microsoft_teams', 'discord', 'zoom', 'mailchimp', 'calendly'];

export const Apps = () => {
    const frame = useCurrentFrame();
    const { fps } = useVideoConfig();
    const n = Math.round(interpolate(frame, [6, 62], [0, 1690], { ...clamp, easing: Easing.out(Easing.cubic) }));
    return (
        <DotBg camera={{ keys: [[0, 1.1], [150, 1]], origin: [1380, 540] }}>
            <div style={{ position: 'absolute', left: 150, top: 270 }}>
                <div style={{ fontSize: 200, fontWeight: 700, letterSpacing: '-0.05em', lineHeight: 1, fontVariantNumeric: 'tabular-nums' }}>{n.toLocaleString('en-US')}</div>
                <Words text="apps ready to connect." delay={12} size={64} weight={600} style={{ marginTop: 18 }} />
                <Words text="8,700 actions and 800 triggers." delay={24} size={38} weight={500} color={C.ink2} style={{ marginTop: 22 }} />
                <div style={{ marginTop: 44 }}>
                    <Chip delay={78}><Sparkles size={28} color={C.brand} />Missing one? The AI builds it from the app's API.</Chip>
                </div>
            </div>
            <div style={{ position: 'absolute', right: 150, top: 247, display: 'grid', gridTemplateColumns: 'repeat(5, 130px)', gap: 22 }}>
                {LOGOS.map((k, i) => {
                    const row = Math.floor(i / 5), col = i % 5;
                    const p = springAt(frame, fps, 8 + (row + col) * 4, true);
                    const float = Math.sin((frame + i * 13) / 22) * 5;
                    return (
                        <div key={k} style={{ width: 130, height: 130, borderRadius: 30, background: C.paper, border: `1.5px solid ${C.line}`, display: 'grid', placeItems: 'center',
                            boxShadow: '0 10px 26px rgba(16,24,40,.08)', transform: `scale(${p}) translateY(${float}px)`, opacity: Math.min(1, p * 1.5) }}>
                            <Img src={staticFile(`logos/${k}.svg`)} style={{ width: 62, height: 62 }} />
                        </div>
                    );
                })}
            </div>
        </DotBg>
    );
};

/* 7. AI Builder, with any model */
const PROMPT = 'When a Typeform is submitted, add the person to HubSpot and tell #sales on Slack.';
const STEPS = ['Searching 1,690 apps', 'Reading HubSpot and Slack actions', 'Building the workflow', 'Linked 3 steps. Ready to test.'];
const MODELS = ['openai', 'anthropic', 'google_gemini', 'deepseek', 'qwen'];

export const AiBuilder = () => {
    const frame = useCurrentFrame();
    const typed = PROMPT.slice(0, Math.floor(interpolate(frame, [14, 74], [0, PROMPT.length], clamp)));
    const { fps } = useVideoConfig();
    const sent = frame > 84;
    const card = useIn(0);
    const bubble = useIn(84, true);
    return (
        <DotBg camera={{ keys: [[0, 1], [85, 1], [195, 1.14]], origin: [960, 600] }}>
            <div style={{ position: 'absolute', left: 150, top: 120 }}>
                <Words text="Or just describe it." size={80} />
            </div>
            <div style={{ position: 'absolute', left: 150, right: 150, top: 290, bottom: 110, borderRadius: 28, background: C.paper, border: `1.5px solid ${C.line}`,
                boxShadow: '0 30px 70px rgba(16,24,40,.12)', padding: '40px 56px', transform: `translateY(${(1 - card) * 80}px)`, opacity: card, display: 'flex', flexDirection: 'column' }}>
                <div style={{ display: 'flex', alignItems: 'center', gap: 14, fontSize: 30, fontWeight: 600 }}>
                    <Sparkles size={32} color={C.brand} /> AI Builder
                    <div style={{ marginLeft: 'auto', display: 'flex', alignItems: 'center', gap: 14, fontSize: 24, fontWeight: 500, color: C.muted }}>
                        Works with
                        {MODELS.map((m, i) => {
                            const p = springAt(frame, fps, 20 + i * 5, true);
                            return <div key={m} style={{ width: 52, height: 52, borderRadius: 14, border: `1.5px solid ${C.line}`, display: 'grid', placeItems: 'center', transform: `scale(${p})` }}><Img src={staticFile(`logos/${m}.svg`)} style={{ width: 30, height: 30 }} /></div>;
                        })}
                        <span style={{ opacity: interpolate(frame, [48, 60], [0, 1], clamp) }}>or any model</span>
                    </div>
                </div>
                {sent && (
                    <div style={{ alignSelf: 'flex-end', marginTop: 26, maxWidth: 1100, background: C.brand, color: '#fff', fontSize: 32, lineHeight: 1.4, padding: '20px 28px', borderRadius: '24px 24px 6px 24px', transform: `scale(${bubble})`, transformOrigin: '100% 100%' }}>
                        {PROMPT}
                    </div>
                )}
                <div style={{ marginTop: 28, display: 'flex', flexDirection: 'column', gap: 18 }}>
                    {STEPS.map((s, i) => {
                        const start = 100 + i * 16;
                        if (frame < start) return null;
                        const done = frame > start + 12;
                        const p = springAt(frame, fps, start);
                        return (
                            <div key={s} style={{ display: 'flex', alignItems: 'center', gap: 16, fontSize: 30, color: done ? C.ink : C.ink2, opacity: p, transform: `translateX(${(1 - p) * -30}px)` }}>
                                <div style={{ width: 40, height: 40, borderRadius: 20, display: 'grid', placeItems: 'center', background: done ? '#e7f6ec' : C.brandSoft }}>
                                    {done ? <Check size={22} color={C.ok} strokeWidth={3} /> : <Loader2 size={22} color={C.brand} style={{ transform: `rotate(${frame * 14}deg)` }} />}
                                </div>
                                {s}
                            </div>
                        );
                    })}
                </div>
                <div style={{ marginTop: 'auto', display: 'flex', alignItems: 'center', gap: 16, border: `2px solid ${frame < 84 ? C.brand : C.line}`, borderRadius: 20, padding: '18px 22px', fontSize: 30, color: C.ink }}>
                    <span style={{ flex: 1, minHeight: 40 }}>{sent ? <span style={{ color: C.muted }}>Describe a workflow…</span> : <>{typed}<span style={{ opacity: frame % 20 < 10 ? 1 : 0, color: C.brand }}>|</span></>}</span>
                    <div style={{ width: 52, height: 52, borderRadius: 14, background: C.brand, display: 'grid', placeItems: 'center', transform: `scale(${interpolate(frame, [78, 82, 86], [1, 0.85, 1], clamp)})` }}><ArrowUp size={28} color="#fff" /></div>
                </div>
            </div>
        </DotBg>
    );
};

/* 8 + 9. Voice agents */

/** How loud someone is "speaking" at this frame: a lively 0..1 inside the segments, 0 outside. */
const loudness = (frame, segments) => {
    const on = segments.some(([a, b]) => frame >= a && frame <= b);
    return on ? 0.45 + 0.55 * Math.abs(Math.sin(frame * 0.83) * Math.sin(frame * 0.29 + 1)) : 0;
};

/** The voice orb: a glowing disc whose rings breathe with the voice level. */
const Orb = ({ size, level, idle = 0, dark = false }) => {
    const frame = useCurrentFrame();
    const breathe = 1 + Math.sin(frame / 14) * 0.02 + idle * 0.04 * Math.sin(frame / 7);
    return (
        <div style={{ position: 'relative', width: size, height: size }}>
            {[0, 1, 2].map((i) => {
                const grow = ((frame / 40 + i / 3) % 1);
                return <div key={i} style={{ position: 'absolute', inset: 0, borderRadius: '50%', border: `2px solid ${C.brand}`, opacity: (1 - grow) * (0.25 + level * 0.45),
                    transform: `scale(${1 + grow * (0.6 + level * 0.5)})` }} />;
            })}
            <div style={{ position: 'absolute', inset: 0, borderRadius: '50%', background: dark ? 'rgba(238,90,36,.16)' : C.brandSoft, transform: `scale(${(1.18 + level * 0.18) * breathe})` }} />
            <div style={{ position: 'absolute', inset: 0, borderRadius: '50%', background: C.brand, boxShadow: `0 0 ${60 + level * 80}px rgba(238,90,36,${0.35 + level * 0.3})`, transform: `scale(${(0.92 + level * 0.1) * breathe})`,
                display: 'grid', placeItems: 'center' }}>
                <div style={{ display: 'flex', alignItems: 'center', gap: size * 0.035, height: size * 0.36 }}>
                    {[0.5, 0.85, 1, 0.75, 0.45].map((h, i) => (
                        <div key={i} style={{ width: size * 0.05, borderRadius: 99, background: '#fff', height: `${Math.max(14, h * (0.25 + level * 0.75 * Math.abs(Math.sin(frame * 0.6 + i))) * 100)}%` }} />
                    ))}
                </div>
            </div>
        </div>
    );
};

export const VoiceHook = () => {
    const frame = useCurrentFrame();
    const orb = useIn(4, true);
    return (
        <DotBg dark camera={{ keys: [[0, 1.08], [90, 1]] }}>
            <AbsoluteFill style={{ alignItems: 'center', justifyContent: 'center', flexDirection: 'column', gap: 90 }}>
                <div style={{ transform: `scale(${orb})` }}><Orb size={190} level={loudness(frame, [[30, 80]]) * 0.7} idle={1} dark /></div>
                <div style={{ textAlign: 'center' }}>
                    <Words text="Now it answers" delay={14} size={104} weight={650} />
                    <Words text="your phone too." delay={24} size={104} weight={650} color={C.brand} />
                </div>
            </AbsoluteFill>
        </DotBg>
    );
};

const CALL = [
    { at: 50, who: 'agent', text: 'Hi, thanks for calling Smile Dental. This is Sara. How can I help?' },
    { at: 86, who: 'caller', text: "I'd like a cleaning on Monday morning." },
    { at: 112, who: 'agent', text: 'Monday at 11:00 is free. Can I have your name?' },
    { at: 140, who: 'caller', text: 'Ali Khan.' },
    { at: 158, who: 'agent', text: "You're booked, Ali: Monday 19 October at 11:00. See you then!" },
];
const AGENT_TALKS = [[50, 78], [112, 134], [158, 186]];
const BOOKED_AT = 192;

const CallBubble = ({ at, who, text }) => {
    const frame = useCurrentFrame();
    const { fps } = useVideoConfig();
    const p = springAt(frame, fps, at, true);
    const agent = who === 'agent';
    return (
        <div style={{ alignSelf: agent ? 'flex-start' : 'flex-end', maxWidth: '84%', fontSize: 23, lineHeight: 1.35, padding: '14px 18px', color: agent ? '#e9ebee' : '#fff',
            background: agent ? '#232832' : C.brand, borderRadius: agent ? '20px 20px 20px 6px' : '20px 20px 6px 20px', transform: `scale(${p}) translateY(${(1 - p) * 20}px)`,
            transformOrigin: agent ? '0% 100%' : '100% 100%', opacity: Math.min(1, p * 1.4) }}>
            {text}
        </div>
    );
};

export const Voice = () => {
    const frame = useCurrentFrame();
    const { fps } = useVideoConfig();
    const phone = useIn(0);
    const ringing = frame < 40;
    const secs = Math.max(0, Math.floor((frame - 40) / fps));
    const card = useIn(BOOKED_AT, true);
    const synced = (at) => springAt(frame, fps, at, true);
    return (
        <DotBg camera={{ keys: [[0, 1.04], [240, 1]], origin: [1440, 540] }}>
            <div style={{ position: 'absolute', left: 150, top: 150, width: 860 }}>
                <Words text="An AI receptionist that books appointments." size={76} />
                <Words text="It talks with callers, answers questions about your business and hangs up when it's done." delay={14} size={32} weight={500} color={C.ink2} style={{ marginTop: 26, lineHeight: 1.4 }} />
                <div style={{ marginTop: 40, display: 'flex', flexWrap: 'wrap', gap: 14 }}>
                    <Chip delay={26}><Img src={staticFile('logos/vapi.svg')} style={{ width: 28, height: 28 }} />Vapi</Chip>
                    <Chip delay={31}><Img src={staticFile('logos/elevenlabs.svg')} style={{ width: 28, height: 28 }} />ElevenLabs</Chip>
                    <Chip delay={36}>11,000+ voices</Chip>
                    <Chip delay={41}>Urdu, Hindi, Arabic + 30 more</Chip>
                </div>
            </div>
            {/* The booking lands in the apps */}
            <div style={{ position: 'absolute', left: 150, top: 690, width: 880, borderRadius: 26, background: C.paper, border: `1.5px solid ${C.line}`, boxShadow: '0 24px 60px rgba(16,24,40,.12)',
                padding: '28px 32px', transform: `translateY(${(1 - card) * 60}px) scale(${0.9 + card * 0.1})`, opacity: Math.min(1, card * 1.4) }}>
                <div style={{ display: 'flex', alignItems: 'center', gap: 18 }}>
                    <div style={{ width: 60, height: 60, borderRadius: 18, background: '#e7f6ec', display: 'grid', placeItems: 'center' }}><CalendarCheck size={32} color={C.ok} /></div>
                    <div>
                        <div style={{ fontSize: 22, color: C.muted, fontWeight: 500 }}>New booking</div>
                        <div style={{ fontSize: 32, fontWeight: 650, letterSpacing: '-0.02em' }}>Ali Khan, cleaning, Monday 11:00</div>
                    </div>
                </div>
                <div style={{ marginTop: 22, display: 'flex', gap: 36, fontSize: 24, color: C.ink2, whiteSpace: 'nowrap' }}>
                    {[['google_calendar', 'Added to Google Calendar', BOOKED_AT + 14], ['slack', 'Posted to #front-desk', BOOKED_AT + 24]].map(([logo, text, at]) => (
                        <div key={logo} style={{ display: 'flex', alignItems: 'center', gap: 10, opacity: Math.min(1, synced(at) * 1.5) }}>
                            <Img src={staticFile(`logos/${logo}.svg`)} style={{ width: 30, height: 30 }} />{text}
                            <Check size={24} color={C.ok} strokeWidth={3} style={{ transform: `scale(${synced(at + 4)})` }} />
                        </div>
                    ))}
                </div>
            </div>
            {/* The phone */}
            <div style={{ position: 'absolute', left: 1160, top: 70 + (1 - phone) * 900, width: 560, height: 940, borderRadius: 72, background: '#0d0f13', padding: 16,
                boxShadow: '0 50px 100px rgba(16,24,40,.3)', transform: `rotate(${(1 - phone) * 6}deg)` }}>
                <div style={{ width: '100%', height: '100%', borderRadius: 58, background: '#15181d', padding: '44px 30px 30px', display: 'flex', flexDirection: 'column', overflow: 'hidden', fontFamily: 'inherit' }}>
                    <div style={{ display: 'flex', justifyContent: 'space-between', color: '#8b93a0', fontSize: 22 }}>
                        <span style={{ display: 'flex', alignItems: 'center', gap: 8 }}><span style={{ width: 10, height: 10, borderRadius: 5, background: ringing ? C.brand : C.ok, opacity: ringing && frame % 16 < 8 ? 0.3 : 1 }} />{ringing ? 'Incoming call' : 'On a call'}</span>
                        <span style={{ fontFamily: MONO }}>{ringing ? '' : `00:${String(secs).padStart(2, '0')}`}</span>
                    </div>
                    <div style={{ textAlign: 'center', marginTop: 26, color: '#fff' }}>
                        <div style={{ fontSize: 40, fontWeight: 650 }}>Sara</div>
                        <div style={{ fontSize: 22, color: '#8b93a0' }}>Smile Dental receptionist</div>
                    </div>
                    <div style={{ display: 'grid', placeItems: 'center', marginTop: 40, height: 210 }}>
                        <Orb size={150} level={ringing ? 0 : loudness(frame, AGENT_TALKS)} idle={ringing ? 1 : 0} dark />
                    </div>
                    <div style={{ marginTop: 'auto', display: 'flex', flexDirection: 'column', justifyContent: 'flex-end', gap: 12, height: 400, overflow: 'hidden' }}>
                        {CALL.filter((m) => frame >= m.at).slice(-4).map((m) => <CallBubble key={m.at} {...m} />)}
                    </div>
                    <div style={{ display: 'flex', justifyContent: 'center', marginTop: 22 }}>
                        <div style={{ width: 76, height: 76, borderRadius: 38, background: ringing ? C.ok : '#e5484d', display: 'grid', placeItems: 'center',
                            transform: `rotate(${ringing ? Math.sin(frame * 1.4) * 14 : 135}deg)` }}><Phone size={34} color="#fff" fill="#fff" /></div>
                    </div>
                </div>
            </div>
        </DotBg>
    );
};

/* 10. Ready-made agents */
export const Templates = () => {
    const back = useIn(6);
    const front = useIn(20);
    return (
        <DotBg camera={{ keys: [[0, 1], [120, 1.07]], origin: [1200, 640] }}>
            <div style={{ position: 'absolute', left: 150, top: 110 }}>
                <Words text="12 ready-made agents. Or describe yours." size={76} />
                <Words text="Clinics, salons, restaurants, hotels, gyms, stores and more, each with a sample call." delay={12} size={34} weight={500} color={C.ink2} style={{ marginTop: 18 }} />
            </div>
            <div style={{ perspective: 2400 }}>
                <Browser width={1180} style={{ position: 'absolute', left: 120, top: 300, transform: `translateX(${(1 - back) * -500}px) rotateY(${(1 - back) * 18 + 6}deg)`, opacity: back, transformOrigin: '0% 50%' }}>
                    <Img src={staticFile('shots/voice-templates.png')} style={{ width: '100%', display: 'block' }} />
                </Browser>
                <Browser width={1060} style={{ position: 'absolute', left: 760, top: 350, transform: `translateX(${(1 - front) * 600}px) rotateY(${(1 - front) * -18 - 4}deg)`, opacity: front, transformOrigin: '100% 50%' }}>
                    <Img src={staticFile('shots/voice-agent.png')} style={{ width: '100%', display: 'block' }} />
                </Browser>
            </div>
        </DotBg>
    );
};

/* 11. Runs that recover on their own */
const RUN = [
    { x: 500, y: 520, logo: 'shopify', label: 'New order', sub: 'Shopify', trigger: true },
    { x: 960, y: 520, logo: 'stripe', label: 'Charge card', sub: 'Stripe' },
    { x: 1420, y: 520, logo: 'slack', label: 'Post to #orders', sub: 'Slack' },
];
const FAIL_AT = 40, RETRY_AT = 100, OK2_AT = 116, OK3_AT = 132;

export const Reliability = () => {
    const frame = useCurrentFrame();
    const { fps } = useVideoConfig();
    const first = interpolate(frame, [22, FAIL_AT], [0, 1], clamp); // first try: trigger → card
    const second = interpolate(frame, [RETRY_AT, OK3_AT], [0, 1], clamp); // the retry: card → slack
    const failed = frame >= FAIL_AT && frame < OK2_AT;
    const countdown = interpolate(frame, [54, RETRY_AT - 6], [0, 1], clamp);
    const wait = useIn(50, true);
    const reused = springAt(frame, fps, RETRY_AT - 4, true);
    const lineColor = (i) => (i === 0 ? (frame >= FAIL_AT ? C.ok : '#b9c0ca') : frame >= OK3_AT ? C.ok : '#b9c0ca');
    return (
        <DotBg zoom={false} camera={{ keys: [[0, 1.08], [165, 1]], origin: [960, 560] }}>
            <div style={{ position: 'absolute', left: 150, top: 110 }}>
                <Words text="Nothing lost. Nothing sent twice." size={80} />
            </div>
            <svg width="1920" height="1080" style={{ position: 'absolute', inset: 0 }}>
                {[0, 1].map((i) => <line key={i} x1={RUN[i].x + NS / 2} y1={520} x2={RUN[i + 1].x - NS / 2} y2={520} stroke={lineColor(i)} strokeWidth="5" strokeLinecap="round" />)}
                {first > 0 && first < 1 && <circle cx={RUN[0].x + first * (RUN[1].x - RUN[0].x)} cy={520} r="13" fill={C.brand} stroke="#fff" strokeWidth="4" />}
                {second > 0 && second < 1 && <circle cx={RUN[1].x + second * (RUN[2].x - RUN[1].x)} cy={520} r="13" fill={C.brand} stroke="#fff" strokeWidth="4" />}
            </svg>
            {RUN.map((n, i) => {
                const state = i === 0 ? 'ok' : i === 1 ? (frame >= OK2_AT ? 'ok' : failed ? 'fail' : null) : frame >= OK3_AT ? 'ok' : null;
                const at = i === 0 ? 22 : i === 1 ? (state === 'ok' ? OK2_AT : FAIL_AT) : OK3_AT;
                return <Node key={i} {...n} appear={springAt(frame, fps, 4 + i * 8, true)} state={state}
                    badge={state ? <StatusDot kind={state} scale={springAt(frame, fps, at, true)} /> : null} />;
            })}
            {/* The error, then the wait, then the retry */}
            <div style={{ position: 'absolute', left: 960, top: 770, transform: 'translateX(-50%)', display: 'flex', flexDirection: 'column', alignItems: 'center', gap: 18 }}>
                {failed && (
                    <div style={{ display: 'flex', alignItems: 'center', gap: 14, fontSize: 28, fontWeight: 600, color: C.fail, background: '#fdecec', border: '1.5px solid #f6c9c9', borderRadius: 16, padding: '12px 22px',
                        transform: `scale(${springAt(frame, fps, FAIL_AT + 2, true)})` }}>HTTP 503: Stripe is busy</div>
                )}
                {frame >= 50 && frame < OK3_AT + 6 && (
                    <div style={{ display: 'flex', alignItems: 'center', gap: 14, fontSize: 28, fontWeight: 600, background: C.paper, border: `1.5px solid ${C.line}`, borderRadius: 16, padding: '12px 22px', transform: `scale(${wait})` }}>
                        <svg width="34" height="34" viewBox="0 0 34 34"><circle cx="17" cy="17" r="14" fill="none" stroke={C.line} strokeWidth="4" /><circle cx="17" cy="17" r="14" fill="none" stroke={C.brand} strokeWidth="4" strokeLinecap="round" pathLength="1" strokeDasharray="1" strokeDashoffset={1 - countdown} transform="rotate(-90 17 17)" /></svg>
                        {frame < RETRY_AT ? 'Retrying in 2 minutes' : 'Try 2: resumed from the failed step'}
                    </div>
                )}
            </div>
            <div style={{ position: 'absolute', left: RUN[0].x - 150, top: 362, width: 300, display: 'flex', justifyContent: 'center' }}>
                <div style={{ fontSize: 22, fontWeight: 600, color: '#0f7a3a', background: '#e7f6ec', borderRadius: 99, padding: '8px 16px', transform: `scale(${reused})`, opacity: Math.min(1, reused * 1.5) }}>Done in an earlier try</div>
            </div>
            <div style={{ position: 'absolute', left: 0, right: 0, bottom: 70, display: 'flex', justifyContent: 'center' }}>
                <Words text="Payments carry an idempotency key, so a retry can't charge twice." delay={138} size={34} weight={500} color={C.ink2} />
            </div>
        </DotBg>
    );
};

/* 12. Secure by default */
const SECURITY = [
    [ShieldCheck, 'Signed updates', 'One-click updates install only releases signed with the FlowForge key'],
    [Webhook, 'Verified webhooks', 'Stripe, Slack, GitHub and Shopify signatures, with replay protection'],
    [Network, 'No internal requests', 'Steps can never reach your server, private network or cloud metadata'],
    [KeyRound, 'Encrypted credentials', 'Secrets never reach the browser or the AI, and are masked in logs'],
    [Users, 'Team permissions', 'View, edit, delete and AI access per person'],
    [RefreshCw, 'Backup and rollback', 'Every update backs up first and rolls back if anything fails'],
];

export const Security = () => {
    const frame = useCurrentFrame();
    const { fps } = useVideoConfig();
    return (
        <DotBg dark camera={{ keys: [[0, 1.06], [135, 1]] }}>
            <div style={{ position: 'absolute', left: 150, top: 120 }}>
                <Words text="Secure by default." size={84} />
            </div>
            <div style={{ position: 'absolute', left: 150, right: 150, top: 300, display: 'grid', gridTemplateColumns: 'repeat(3, 1fr)', gap: 28 }}>
                {SECURITY.map(([Icon, title, sub], i) => {
                    const p = springAt(frame, fps, 10 + i * 6, true);
                    return (
                        <div key={title} style={{ background: C.darkCard, border: `1.5px solid ${C.darkLine}`, borderRadius: 26, padding: '34px 34px', transform: `scale(${1.4 - p * 0.4})`, opacity: Math.min(1, p * 1.6) }}>
                            <div style={{ width: 68, height: 68, borderRadius: 18, background: 'rgba(238,90,36,.15)', display: 'grid', placeItems: 'center' }}><Icon size={34} color={C.brand} strokeWidth={1.9} /></div>
                            <div style={{ marginTop: 24, fontSize: 36, fontWeight: 600, letterSpacing: '-0.02em', color: '#fff' }}>{title}</div>
                            <div style={{ marginTop: 10, fontSize: 25, color: '#9aa2ae', lineHeight: 1.38 }}>{sub}</div>
                        </div>
                    );
                })}
            </div>
        </DotBg>
    );
};

/* 13. One cron job */
const CRON = '* * * * * php artisan schedule:run';

export const Cron = () => {
    const frame = useCurrentFrame();
    const typed = CRON.slice(0, Math.floor(interpolate(frame, [18, 58], [0, CRON.length], clamp)));
    const card = useIn(4);
    return (
        <DotBg dark camera={{ keys: [[0, 1], [15, 1], [60, 1.22], [100, 1], [135, 1.04]], origin: [960, 470] }}>
            <AbsoluteFill style={{ alignItems: 'center', paddingTop: 140 }}>
                <Words text="One cron job runs everything." size={84} />
                <div style={{ marginTop: 70, width: 1260, borderRadius: 22, background: C.darkCard, border: `1.5px solid ${C.darkLine}`, overflow: 'hidden', transform: `translateY(${(1 - card) * 60}px)`, opacity: card }}>
                    <div style={{ height: 52, display: 'flex', alignItems: 'center', gap: 10, padding: '0 22px', borderBottom: `1.5px solid ${C.darkLine}`, color: '#8b93a0', fontSize: 22, fontFamily: MONO }}>
                        {[0, 1, 2].map((i) => <div key={i} style={{ width: 13, height: 13, borderRadius: 7, background: '#3a404b' }} />)}
                        <span style={{ marginLeft: 14 }}>crontab</span>
                    </div>
                    <div style={{ padding: '44px 44px', fontFamily: MONO, fontSize: 46, color: '#e9ebee' }}>
                        <span style={{ color: C.brand }}>$ </span>{typed}<span style={{ opacity: frame % 20 < 10 ? 1 : 0, color: C.brand }}>▍</span>
                    </div>
                </div>
                <div style={{ marginTop: 54, display: 'flex', gap: 20 }}>
                    {['Hostinger', 'cPanel', 'DirectAdmin', 'Any VPS'].map((t, i) => <Chip key={t} dark delay={66 + i * 6}>{t}</Chip>)}
                </div>
                <div style={{ marginTop: 40 }}>
                    <Words text="No Docker. No Node.js. No Redis." delay={92} size={40} weight={500} color="#8b93a0" />
                </div>
            </AbsoluteFill>
        </DotBg>
    );
};

/* 14. MCP: AI agents build here too */
const MCP_CMD = 'claude mcp add --transport http flowforge https://your-site.com/api/mcp';
const MCP_LINES = [
    { at: 84, text: '✓ Connected to FlowForge', color: '#7ee2a0' },
    { at: 100, text: '> Post new Shopify orders to #sales on Slack', color: '#e9ebee' },
    { at: 118, text: '  Searching apps, building the workflow…', color: '#8b93a0' },
    { at: 132, text: '✓ Created "Shopify orders to Slack" and tested it', color: '#7ee2a0' },
];

export const Mcp = () => {
    const frame = useCurrentFrame();
    const typed = MCP_CMD.slice(0, Math.floor(interpolate(frame, [16, 72], [0, MCP_CMD.length], clamp)));
    const card = useIn(4);
    return (
        <DotBg camera={{ keys: [[0, 1.05], [165, 1]] }}>
            <div style={{ position: 'absolute', left: 150, top: 110 }}>
                <Words text="Your AI agents can build here too." size={80} />
            </div>
            <div style={{ position: 'absolute', left: 150, right: 150, top: 270, borderRadius: 24, background: C.darkCard, border: `1.5px solid ${C.darkLine}`, overflow: 'hidden',
                boxShadow: '0 40px 90px rgba(16,24,40,.22)', transform: `translateY(${(1 - card) * 70}px)`, opacity: card }}>
                <div style={{ height: 52, display: 'flex', alignItems: 'center', gap: 10, padding: '0 22px', borderBottom: `1.5px solid ${C.darkLine}`, color: '#8b93a0', fontSize: 22, fontFamily: MONO }}>
                    {[0, 1, 2].map((i) => <div key={i} style={{ width: 13, height: 13, borderRadius: 7, background: '#3a404b' }} />)}
                    <span style={{ marginLeft: 14 }}>terminal</span>
                </div>
                <div style={{ padding: '36px 40px', fontFamily: MONO, fontSize: 31, lineHeight: 1.65, color: '#e9ebee', minHeight: 360 }}>
                    <div><span style={{ color: C.brand }}>$ </span>{typed}{frame < 80 && <span style={{ opacity: frame % 20 < 10 ? 1 : 0, color: C.brand }}>▍</span>}</div>
                    {MCP_LINES.filter((l) => frame >= l.at).map((l) => <div key={l.at} style={{ color: l.color, whiteSpace: 'pre' }}>{l.text}</div>)}
                </div>
            </div>
            <div style={{ position: 'absolute', left: 150, top: 800, display: 'flex', gap: 18, alignItems: 'center' }}>
                {['Claude Code', 'Cursor', 'VS Code'].map((t, i) => <Chip key={t} delay={140 + i * 4}>{t}</Chip>)}
                <Chip delay={154}><Img src={staticFile('logos/n8n.svg')} style={{ width: 30, height: 30 }} />Import n8n workflows</Chip>
            </div>
        </DotBg>
    );
};

/* 15. Phones */
const PhoneShot = ({ src, delay, x, rotate }) => {
    const p = useIn(delay);
    const frame = useCurrentFrame();
    return (
        <div style={{ position: 'absolute', left: x, top: 160 + (1 - p) * 700 + Math.sin((frame + delay * 3) / 26) * 8, width: 340, borderRadius: 54, background: '#15181d', padding: 13,
            boxShadow: '0 40px 80px rgba(16,24,40,.25)', transform: `rotate(${rotate * p}deg) scale(${0.7 + p * 0.3})` }}>
            <Img src={staticFile(`shots/${src}.png`)} style={{ width: '100%', display: 'block', borderRadius: 42 }} />
        </div>
    );
};

export const Mobile = () => (
    <DotBg camera={{ keys: [[0, 1], [120, 1.1]], origin: [1300, 560] }}>
        <div style={{ position: 'absolute', left: 150, top: 380, width: 640 }}>
            <Words text="Works like an app on your phone." size={84} />
        </div>
        <PhoneShot src="mobile-workflows" delay={6} x={850} rotate={-5} />
        <PhoneShot src="mobile-overview" delay={14} x={1170} rotate={0} />
        <PhoneShot src="mobile-ai" delay={22} x={1490} rotate={5} />
    </DotBg>
);

/* 16. Call to action */
export const Outro = () => {
    const frame = useCurrentFrame();
    const url = useIn(64);
    const slam = interpolate(frame, [0, 12], [2.4, 1], { ...clamp, easing: Easing.out(Easing.back(1.6)) });
    return (
        <DotBg camera={{ keys: [[20, 1], [180, 1.05]] }}>
            <AbsoluteFill style={{ alignItems: 'center', justifyContent: 'center', flexDirection: 'column' }}>
                <div style={{ display: 'flex', alignItems: 'center', gap: 30, transform: `scale(${slam})` }}>
                    <LogoMark size={120} />
                    <Words text="FlowForge" delay={10} size={120} weight={600} stagger={0} />
                </div>
                <Words text="Free and open source." delay={26} size={64} weight={600} style={{ marginTop: 40 }} />
                <div style={{ marginTop: 40, display: 'flex', gap: 16 }}>
                    {['AGPL-3.0', 'Self-hosted', '1,690 apps', 'AI voice agents', 'PHP + MySQL'].map((t, i) => <Chip key={t} delay={38 + i * 4}>{t}</Chip>)}
                </div>
                <div style={{ marginTop: 60, display: 'flex', alignItems: 'center', gap: 18, background: C.ink, color: '#fff', padding: '24px 40px', borderRadius: 20,
                    fontFamily: MONO, fontSize: 40, transform: `scale(${0.9 + url * 0.1})`, opacity: url }}>
                    <Star size={36} color={C.brand} fill={C.brand} /> github.com/DeveloperSarim/FlowForge
                </div>
                <div style={{ marginTop: 26, fontSize: 30, color: C.ink2, opacity: interpolate(frame, [80, 96], [0, 1], clamp) }}>Guides: developersarim.github.io/FlowForge</div>
            </AbsoluteFill>
        </DotBg>
    );
};
