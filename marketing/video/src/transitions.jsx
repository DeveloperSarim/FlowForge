import { AbsoluteFill, interpolate } from 'remotion';

const clamp = { extrapolateLeft: 'clamp', extrapolateRight: 'clamp' };

/** Camera-style transitions: the old scene flies past or away while the new one settles into place, with a touch of motion blur. */
const Zoom = ({ children, presentationDirection, presentationProgress: p, passedProps: { exitTo, enterFrom } }) => {
    const entering = presentationDirection === 'entering';
    const scale = entering ? interpolate(p, [0, 1], [enterFrom, 1]) : interpolate(p, [0, 1], [1, exitTo]);
    const opacity = entering ? interpolate(p, [0, 0.55], [0, 1], clamp) : 1;
    const blur = entering ? interpolate(p, [0, 0.7], [14, 0], clamp) : interpolate(p, [0, 1], [0, 10], clamp);
    return <AbsoluteFill style={{ transform: `scale(${scale})`, opacity, filter: blur > 0.3 ? `blur(${blur}px)` : undefined }}>{children}</AbsoluteFill>;
};

const STYLES = {
    push: { exitTo: 1.35, enterFrom: 0.86 }, // camera moves forward through the scene
    pull: { exitTo: 0.82, enterFrom: 1.22 }, // camera pulls back
    slam: { exitTo: 1.1, enterFrom: 1.8 },   // the next scene lands hard
};

export const zoom = (kind) => ({ component: Zoom, props: STYLES[kind] });
