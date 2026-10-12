import { AbsoluteFill, Img, interpolate, staticFile, useCurrentFrame, useVideoConfig, Easing } from 'remotion';
import { Bot, Check, RefreshCw, ScanSearch, ShieldCheck, Sparkles, Star, Users, Zap, Loader2, ArrowUp } from 'lucide-react';
import { Browser, C, Chip, DotBg, LogoMark, MONO, Words, clamp, springAt, useIn } from './ui';

/* 1. Logo reveal */
export const Intro = () => {
    const frame = useCurrentFrame();
    const word = 'FlowForge';
    return (
        <DotBg>
            <AbsoluteFill style={{ alignItems: 'center', justifyContent: 'center', flexDirection: 'column', gap: 44 }}>
                <div style={{ display: 'flex', alignItems: 'center', gap: 36 }}>
                    <LogoMark size={150} />
                    <div style={{ fontSize: 132, fontWeight: 600, letterSpacing: '-0.045em', display: 'flex' }}>
                        {word.split('').map((ch, i) => {
                            const p = interpolate(frame, [18 + i * 2, 30 + i * 2], [0, 1], { ...clamp, easing: Easing.out(Easing.cubic) });
                            return <span key={i} style={{ opacity: p, transform: `translateY(${(1 - p) * 40}px)`, display: 'inline-block' }}>{ch}</span>;
                        })}
                    </div>
                </div>
                <Words text="Automate the busywork." delay={42} size={56} weight={500} color={C.ink2} />
            </AbsoluteFill>
        </DotBg>
    );
};

/* 2. The problem */
const Struck = ({ text, delay }) => {
    const frame = useCurrentFrame();
    const strike = interpolate(frame, [delay + 26, delay + 40], [0, 1], { ...clamp, easing: Easing.inOut(Easing.cubic) });
    const dim = interpolate(frame, [delay + 40, delay + 52], [1, 0.38], clamp);
    return (
        <div style={{ position: 'relative', opacity: dim, alignSelf: 'flex-start' }}>
            <Words text={text} delay={delay} size={72} weight={600} />
            <div style={{ position: 'absolute', left: 0, top: '50%', height: 9, borderRadius: 5, background: C.brand, width: `${strike * 100}%` }} />
        </div>
    );
};

export const Problem = () => (
    <DotBg>
        <AbsoluteFill style={{ justifyContent: 'center', padding: '0 190px', gap: 40, display: 'flex', flexDirection: 'column' }}>
            <Struck text="Automation tools bill you per task." delay={4} />
            <Struck text="Self-hosting them needs Docker and a VPS." delay={50} />
        </AbsoluteFill>
    </DotBg>
);

/* 3. The question */
export const Hook = () => (
    <DotBg dark>
        <AbsoluteFill style={{ alignItems: 'center', justifyContent: 'center', textAlign: 'center' }}>
            <Words text="What if it ran on the hosting" delay={2} size={96} weight={600} />
            <Words text="you already have?" delay={14} size={96} weight={600} color={C.brand} />
        </AbsoluteFill>
    </DotBg>
);

/* 4. Meet FlowForge */
export const Meet = () => {
    const p = useIn(10);
    return (
        <DotBg>
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
const NODES = [
    { x: 500, logo: 'typeform', label: 'New response', sub: 'Typeform', trigger: true },
    { x: 960, logo: 'hubspot', label: 'Create contact', sub: 'HubSpot' },
    { x: 1420, logo: 'slack', label: 'Post to #sales', sub: 'Slack' },
];
const NODE_Y = 540;
const NS = 200; // node size

const DONE_AT = [98, 127, 156]; // frames when the test run reaches each node

export const Builder = () => {
    const frame = useCurrentFrame();
    const { fps } = useVideoConfig();
    const pulse = interpolate(frame, [DONE_AT[0], DONE_AT[2]], [0, 1], clamp);
    const pulseX = NODES[0].x + pulse * (NODES[2].x - NODES[0].x);
    const press = interpolate(frame, [86, 90, 96], [1, 0.93, 1], clamp);
    const button = useIn(70, true);
    return (
        <DotBg zoom={false}>
            <div style={{ position: 'absolute', left: 150, top: 120 }}>
                <Words text="Build workflows visually." size={80} />
            </div>
            <svg width="1920" height="1080" style={{ position: 'absolute', inset: 0 }}>
                {[0, 1].map((i) => {
                    const a = NODES[i].x + NS / 2, b = NODES[i + 1].x - NS / 2;
                    const draw = interpolate(frame, [32 + i * 30, 54 + i * 30], [1, 0], clamp);
                    return <line key={i} x1={a} y1={NODE_Y} x2={b} y2={NODE_Y} stroke={frame >= DONE_AT[i + 1] ? C.ok : '#b9c0ca'} strokeWidth="5" strokeLinecap="round" pathLength="1" strokeDasharray="1" strokeDashoffset={draw} />;
                })}
                {pulse > 0 && pulse < 1 && <circle cx={pulseX} cy={NODE_Y} r="13" fill={C.brand} stroke="#fff" strokeWidth="4" />}
            </svg>
            {NODES.map((n, i) => {
                const p = springAt(frame, fps, 6 + i * 30, true);
                const check = frame >= DONE_AT[i] ? springAt(frame, fps, DONE_AT[i], true) : 0;
                return (
                    <div key={i} style={{ position: 'absolute', left: n.x - NS / 2, top: NODE_Y - NS / 2, width: NS, transform: `scale(${p})`, opacity: Math.min(1, p * 1.5) }}>
                        <div style={{ width: NS, height: NS, borderRadius: n.trigger ? `${NS / 2}px 40px 40px ${NS / 2}px` : 40, background: C.paper, border: `3px solid ${check ? '#7cc49a' : C.line}`, display: 'grid', placeItems: 'center', boxShadow: '0 16px 40px rgba(16,24,40,.12)', position: 'relative' }}>
                            <Img src={staticFile(`logos/${n.logo}.svg`)} style={{ width: 96, height: 96 }} />
                            {n.trigger && <div style={{ position: 'absolute', left: -20, top: NS / 2 - 22, width: 44, height: 44, borderRadius: 22, background: C.brand, display: 'grid', placeItems: 'center', border: '4px solid #fff' }}><Zap size={20} color="#fff" fill="#fff" /></div>}
                            <div style={{ position: 'absolute', right: -14, bottom: -14, width: 52, height: 52, borderRadius: 26, background: C.ok, display: 'grid', placeItems: 'center', border: '4px solid #fff', transform: `scale(${check})` }}><Check size={24} color="#fff" strokeWidth={3.5} /></div>
                        </div>
                        <div style={{ marginTop: 28, textAlign: 'center', width: 400, marginLeft: -100, fontSize: 36, fontWeight: 600 }}>{n.label}</div>
                        <div style={{ textAlign: 'center', width: 400, marginLeft: -100, fontSize: 28, color: C.muted }}>{n.sub}</div>
                    </div>
                );
            })}
            <div style={{ position: 'absolute', left: 0, right: 0, bottom: 120, display: 'flex', justifyContent: 'center' }}>
                <div style={{ transform: `scale(${button * press})`, background: C.brand, color: '#fff', fontSize: 32, fontWeight: 600, padding: '20px 40px', borderRadius: 16, display: 'flex', gap: 14, alignItems: 'center', boxShadow: '0 12px 30px rgba(238,90,36,.3)' }}>
                    <Zap size={30} /> Execute workflow
                </div>
            </div>
        </DotBg>
    );
};

/* 6. 1,190 apps */
const LOGOS = ['gmail', 'slack', 'google_sheets', 'hubspot', 'shopify', 'paypal', 'airtable', 'linkedin', 'trello', 'jira', 'asana', 'microsoft_teams',
    'telegram', 'whatsapp_business', 'discord', 'zoom', 'dropbox', 'mailchimp', 'clickup', 'calendly'];

export const Apps = () => {
    const frame = useCurrentFrame();
    const { fps } = useVideoConfig();
    const n = Math.round(interpolate(frame, [6, 62], [0, 1190], { ...clamp, easing: Easing.out(Easing.cubic) }));
    return (
        <DotBg>
            <div style={{ position: 'absolute', left: 150, top: 300 }}>
                <div style={{ fontSize: 200, fontWeight: 700, letterSpacing: '-0.05em', lineHeight: 1, fontVariantNumeric: 'tabular-nums' }}>{n.toLocaleString('en-US')}</div>
                <Words text="apps ready to connect." delay={12} size={64} weight={600} style={{ marginTop: 18 }} />
                <Words text="5,783 actions and 615 triggers." delay={24} size={38} weight={500} color={C.ink2} style={{ marginTop: 22 }} />
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

/* 7. AI Builder */
const PROMPT = 'When a Typeform is submitted, add the person to HubSpot and tell #sales on Slack.';
const STEPS = ['Searching 1,190 apps', 'Reading HubSpot and Slack actions', 'Building the workflow', 'Linked 3 steps. Ready to test.'];

export const AiBuilder = () => {
    const frame = useCurrentFrame();
    const typed = PROMPT.slice(0, Math.floor(interpolate(frame, [14, 74], [0, PROMPT.length], clamp)));
    const { fps } = useVideoConfig();
    const sent = frame > 84;
    const card = useIn(0);
    const bubble = useIn(84, true);
    return (
        <DotBg>
            <div style={{ position: 'absolute', left: 150, top: 120 }}>
                <Words text="Or just describe it." size={80} />
            </div>
            <div style={{ position: 'absolute', left: 150, right: 150, top: 290, bottom: 110, borderRadius: 28, background: C.paper, border: `1.5px solid ${C.line}`,
                boxShadow: '0 30px 70px rgba(16,24,40,.12)', padding: '40px 56px', transform: `translateY(${(1 - card) * 80}px)`, opacity: card, display: 'flex', flexDirection: 'column' }}>
                <div style={{ display: 'flex', alignItems: 'center', gap: 14, fontSize: 30, fontWeight: 600 }}>
                    <Sparkles size={32} color={C.brand} /> AI Builder
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

/* 8. One cron job */
const CRON = '* * * * * php artisan schedule:run';

export const Cron = () => {
    const frame = useCurrentFrame();
    const typed = CRON.slice(0, Math.floor(interpolate(frame, [18, 58], [0, CRON.length], clamp)));
    const card = useIn(4);
    return (
        <DotBg dark>
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
                    <Words text="No Docker. No Node.js. No Redis." delay={96} size={40} weight={500} color="#8b93a0" />
                </div>
            </AbsoluteFill>
        </DotBg>
    );
};

/* 9. Features */
const FEATURES = [
    [Zap, 'Webhooks and schedules', 'Plain-word schedules, app triggers, error triggers'],
    [ScanSearch, 'Step-by-step debugger', 'Every input, output and HTTP request'],
    [Users, 'Team permissions', 'View, edit, delete and AI access per person'],
    [Bot, 'MCP for AI agents', 'Claude Code, Cursor and VS Code build for you'],
    [RefreshCw, 'One-click updates', 'Backup and rollback built in'],
    [ShieldCheck, 'Encrypted credentials', 'Secrets never reach the browser'],
];

export const Features = () => {
    const frame = useCurrentFrame();
    const { fps } = useVideoConfig();
    return (
    <DotBg>
        <div style={{ position: 'absolute', left: 150, top: 120 }}>
            <Words text="Everything a team needs." size={80} />
        </div>
        <div style={{ position: 'absolute', left: 150, right: 150, top: 300, display: 'grid', gridTemplateColumns: 'repeat(3, 1fr)', gap: 30 }}>
            {FEATURES.map(([Icon, title, sub], i) => {
                const p = springAt(frame, fps, 10 + i * 7, true);
                return (
                    <div key={title} style={{ background: C.paper, border: `1.5px solid ${C.line}`, borderRadius: 26, padding: '36px 36px', boxShadow: '0 12px 30px rgba(16,24,40,.07)',
                        transform: `translateY(${(1 - p) * 50}px) scale(${0.94 + p * 0.06})`, opacity: Math.min(1, p * 1.4) }}>
                        <div style={{ width: 70, height: 70, borderRadius: 18, background: C.brandSoft, display: 'grid', placeItems: 'center' }}><Icon size={36} color={C.brand} strokeWidth={1.9} /></div>
                        <div style={{ marginTop: 26, fontSize: 36, fontWeight: 600, letterSpacing: '-0.02em' }}>{title}</div>
                        <div style={{ marginTop: 10, fontSize: 26, color: C.ink2, lineHeight: 1.35 }}>{sub}</div>
                    </div>
                );
            })}
        </div>
    </DotBg>
);
};

/* 10. Phones */
const Phone = ({ src, delay, x, rotate }) => {
    const p = useIn(delay);
    const frame = useCurrentFrame();
    return (
        <div style={{ position: 'absolute', left: x, top: 160 + (1 - p) * 700 + Math.sin((frame + delay * 3) / 26) * 8, width: 340, borderRadius: 54, background: '#15181d', padding: 13,
            boxShadow: '0 40px 80px rgba(16,24,40,.25)', transform: `rotate(${rotate * p}deg)` }}>
            <Img src={staticFile(`shots/${src}.png`)} style={{ width: '100%', display: 'block', borderRadius: 42 }} />
        </div>
    );
};

export const Mobile = () => (
    <DotBg>
        <div style={{ position: 'absolute', left: 150, top: 380, width: 640 }}>
            <Words text="Works like an app on your phone." size={84} />
        </div>
        <Phone src="mobile-workflows" delay={6} x={850} rotate={-5} />
        <Phone src="mobile-overview" delay={14} x={1170} rotate={0} />
        <Phone src="mobile-ai" delay={22} x={1490} rotate={5} />
    </DotBg>
);

/* 11. Call to action */
export const Outro = () => {
    const url = useIn(64);
    return (
        <DotBg>
            <AbsoluteFill style={{ alignItems: 'center', justifyContent: 'center', flexDirection: 'column' }}>
                <div style={{ display: 'flex', alignItems: 'center', gap: 30 }}>
                    <LogoMark size={120} />
                    <Words text="FlowForge" delay={10} size={120} weight={600} stagger={0} />
                </div>
                <Words text="Free and open source." delay={26} size={64} weight={600} style={{ marginTop: 40 }} />
                <div style={{ marginTop: 40, display: 'flex', gap: 18 }}>
                    {['AGPL-3.0', 'Self-hosted', 'PHP + MySQL', '1,190 apps'].map((t, i) => <Chip key={t} delay={40 + i * 5}>{t}</Chip>)}
                </div>
                <div style={{ marginTop: 64, display: 'flex', alignItems: 'center', gap: 18, background: C.ink, color: '#fff', padding: '24px 40px', borderRadius: 20,
                    fontFamily: MONO, fontSize: 40, transform: `scale(${0.9 + url * 0.1})`, opacity: url }}>
                    <Star size={36} color={C.brand} fill={C.brand} /> github.com/DeveloperSarim/FlowForge
                </div>
            </AbsoluteFill>
        </DotBg>
    );
};
