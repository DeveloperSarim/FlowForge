import { TransitionSeries, linearTiming } from '@remotion/transitions';
import { fade } from '@remotion/transitions/fade';
import { slide } from '@remotion/transitions/slide';
import { AiBuilder, Apps, Builder, Cron, Features, Hook, Intro, Meet, Mobile, Outro, Problem } from './scenes';

// [scene, length in frames, transition into the next scene]
const SCENES = [
    [Intro, 90, fade()],
    [Problem, 120, slide({ direction: 'from-bottom' })],
    [Hook, 80, slide({ direction: 'from-bottom' })],
    [Meet, 130, slide({ direction: 'from-right' })],
    [Builder, 185, fade()],
    [Apps, 150, slide({ direction: 'from-bottom' })],
    [AiBuilder, 190, slide({ direction: 'from-bottom' })],
    [Cron, 150, slide({ direction: 'from-bottom' })],
    [Features, 150, slide({ direction: 'from-right' })],
    [Mobile, 125, fade()],
    [Outro, 165, null],
];
const T = 15; // transition length

export const PROMO_FRAMES = SCENES.reduce((sum, [, len]) => sum + len, 0) - (SCENES.length - 1) * T;

export const Promo = () => (
    <TransitionSeries>
        {SCENES.flatMap(([Scene, len, next], i) => [
            <TransitionSeries.Sequence key={`s${i}`} durationInFrames={len}><Scene /></TransitionSeries.Sequence>,
            ...(next ? [<TransitionSeries.Transition key={`t${i}`} presentation={next} timing={linearTiming({ durationInFrames: T })} />] : []),
        ])}
    </TransitionSeries>
);
