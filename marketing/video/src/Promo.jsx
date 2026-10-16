import { Audio, staticFile } from 'remotion';
import { TransitionSeries, linearTiming } from '@remotion/transitions';
import { AiBuilder, Apps, Builder, Cron, Hook, Intro, Mcp, Meet, Mobile, Outro, Problem, Reliability, Security, Templates, Voice, VoiceHook } from './scenes';
import { zoom } from './transitions';
import timeline from './timeline.json';

// timeline.json is shared with audio/make_soundtrack.py, so every sound lands on its frame.
const COMPONENTS = { intro: Intro, problem: Problem, hook: Hook, meet: Meet, builder: Builder, apps: Apps, ai: AiBuilder, voiceHook: VoiceHook, voice: Voice,
    templates: Templates, reliability: Reliability, security: Security, cron: Cron, mcp: Mcp, mobile: Mobile, outro: Outro };
const T = timeline.transition;

export const PROMO_FRAMES = timeline.scenes.reduce((sum, s) => sum + s.frames, 0) - (timeline.scenes.length - 1) * T;

export const Promo = () => (
    <>
        <TransitionSeries>
            {timeline.scenes.flatMap(({ id, frames, into }) => {
                const Scene = COMPONENTS[id];
                return [
                    <TransitionSeries.Sequence key={id} durationInFrames={frames}><Scene /></TransitionSeries.Sequence>,
                    ...(into ? [<TransitionSeries.Transition key={`${id}-t`} presentation={zoom(into)} timing={linearTiming({ durationInFrames: T })} />] : []),
                ];
            })}
        </TransitionSeries>
        <Audio src={staticFile('audio/soundtrack.wav')} />
    </>
);
