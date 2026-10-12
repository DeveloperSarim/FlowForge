import { AbsoluteFill, interpolate, spring, useCurrentFrame, useVideoConfig } from 'remotion';
import { loadFont } from '@remotion/google-fonts/Geist';
import { loadFont as loadMono } from '@remotion/google-fonts/GeistMono';

export const { fontFamily: SANS } = loadFont('normal', { weights: ['400', '500', '600', '700'], subsets: ['latin'] });
export const { fontFamily: MONO } = loadMono('normal', { weights: ['400', '500'], subsets: ['latin'] });

export const C = {
    bg: '#f6f7f9', paper: '#ffffff', ink: '#15181d', ink2: '#4b5462', muted: '#8a919c', line: '#e3e6ea',
    brand: '#ee5a24', brandSoft: '#fdf0ea', dark: '#0d0f13', darkCard: '#15181d', darkLine: '#2a2f38', ok: '#16a34a',
};

/** 0 → 1 with no bounce (smooth) or a little bounce (pop). */
export const useIn = (delay = 0, pop = false) => {
    const frame = useCurrentFrame();
    const { fps } = useVideoConfig();
    return spring({ frame: frame - delay, fps, config: pop ? { damping: 13, stiffness: 170, mass: 0.7 } : { damping: 200 } });
};

export const clamp = { extrapolateLeft: 'clamp', extrapolateRight: 'clamp' };

/** Same as useIn, for use inside loops (pass frame and fps from the component). */
export const springAt = (frame, fps, delay = 0, pop = false) =>
    spring({ frame: frame - delay, fps, config: pop ? { damping: 13, stiffness: 170, mass: 0.7 } : { damping: 200 } });

/** The brand's dotted canvas. */
export const DotBg = ({ dark = false, children, zoom = true }) => {
    const frame = useCurrentFrame();
    const scale = zoom ? interpolate(frame, [0, 200], [1.06, 1], clamp) : 1;
    return (
        <AbsoluteFill style={{ backgroundColor: dark ? C.dark : C.bg, fontFamily: SANS, color: dark ? '#fff' : C.ink, overflow: 'hidden' }}>
            <AbsoluteFill style={{ transform: `scale(${scale})`, backgroundImage: `radial-gradient(${dark ? '#262b33' : '#d3d8df'} 1.6px, transparent 1.6px)`, backgroundSize: '28px 28px' }} />
            {children}
        </AbsoluteFill>
    );
};

/** Words rise into place one after another. */
export const Words = ({ text, delay = 0, size = 72, weight = 700, color, stagger = 3, style }) => {
    const frame = useCurrentFrame();
    const { fps } = useVideoConfig();
    return (
        <div style={{ fontSize: size, fontWeight: weight, letterSpacing: size > 50 ? '-0.035em' : '-0.01em', lineHeight: 1.08, color, ...style }}>
            {text.split(' ').map((word, i) => {
                const p = spring({ frame: frame - delay - i * stagger, fps, config: { damping: 200 } });
                return (
                    <span key={i} style={{ display: 'inline-block', overflow: 'hidden', verticalAlign: 'top', paddingBottom: '0.08em' }}>
                        <span style={{ display: 'inline-block', transform: `translateY(${(1 - p) * 105}%)`, opacity: p }}>{word}&nbsp;</span>
                    </span>
                );
            })}
        </div>
    );
};

export const LogoMark = ({ size = 140, delay = 0 }) => {
    const frame = useCurrentFrame();
    const box = useIn(delay, true);
    const draw = interpolate(frame, [delay + 8, delay + 30], [1, 0], clamp);
    const dot = useIn(delay + 24, true);
    return (
        <svg width={size} height={size} viewBox="0 0 32 32" style={{ transform: `scale(${box}) rotate(${(1 - box) * -12}deg)`, overflow: 'visible' }}>
            <rect width="32" height="32" rx="9" fill={C.brand} />
            <path d="M9 22V10h10M9 16h7" stroke="#fff" strokeWidth="3.2" strokeLinecap="round" strokeLinejoin="round" fill="none" pathLength="1" strokeDasharray="1" strokeDashoffset={draw} />
            <circle cx="23" cy="21" r={3 * dot} fill="#fff" />
        </svg>
    );
};

/** A browser window around a screenshot. */
export const Browser = ({ children, width, dark = false, style }) => (
    <div style={{ width, borderRadius: 18, overflow: 'hidden', background: dark ? C.darkCard : C.paper, border: `1.5px solid ${dark ? C.darkLine : '#dfe3e8'}`, boxShadow: '0 40px 90px rgba(16,24,40,.18), 0 8px 24px rgba(16,24,40,.08)', ...style }}>
        <div style={{ height: 40, display: 'flex', alignItems: 'center', gap: 9, paddingLeft: 18, borderBottom: `1.5px solid ${dark ? C.darkLine : '#eceef1'}` }}>
            {[0, 1, 2].map((i) => <div key={i} style={{ width: 12, height: 12, borderRadius: 6, background: dark ? '#3a404b' : '#d6dae0' }} />)}
        </div>
        {children}
    </div>
);

export const Chip = ({ children, delay = 0, dark = false, style }) => {
    const p = useIn(delay, true);
    return (
        <div style={{ display: 'inline-flex', alignItems: 'center', gap: 10, padding: '14px 26px', borderRadius: 999, fontSize: 30, fontWeight: 500,
            background: dark ? C.darkCard : C.paper, border: `1.5px solid ${dark ? C.darkLine : C.line}`, color: dark ? '#e9ebee' : C.ink,
            transform: `scale(${p}) translateY(${(1 - p) * 20}px)`, opacity: Math.min(1, p * 1.4), ...style }}>
            {children}
        </div>
    );
};
