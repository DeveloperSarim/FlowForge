import { Composition } from 'remotion';
import { Promo, PROMO_FRAMES } from './Promo';

export const Root = () => (
    <Composition id="FlowForgePromo" component={Promo} durationInFrames={PROMO_FRAMES} fps={30} width={1920} height={1080} />
);
