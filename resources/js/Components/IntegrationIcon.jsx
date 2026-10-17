import { useState } from 'react';
import {
    Clock, Database, FlaskConical, GitBranch, Globe, Hash, Mail, MousePointerClick, Plug, TriangleAlert, WandSparkles, Webhook,
} from 'lucide-react';
import { cn } from '@/lib/utils';

// Built-in nodes use Lucide names ("icon": "webhook"); apps use their logo file (/integrations/icons/<key>.svg).
const ICONS = {
    clock: Clock, database: Database, 'flask-conical': FlaskConical, 'git-branch': GitBranch, globe: Globe, hash: Hash,
    mail: Mail, 'mouse-pointer-click': MousePointerClick, plug: Plug, 'triangle-alert': TriangleAlert, 'wand-sparkles': WandSparkles, webhook: Webhook,
};

const SIZES = {
    xs: { box: 'size-5 rounded', img: 'size-3.5', glyph: 'size-3' },
    sm: { box: 'size-7 rounded-md', img: 'size-[18px]', glyph: 'size-4' },
    md: { box: 'size-9 rounded-lg', img: 'size-6', glyph: 'size-[18px]' },
    lg: { box: 'size-12 rounded-xl', img: 'size-8', glyph: 'size-6' },
    xl: { box: 'size-16 rounded-2xl', img: 'size-11', glyph: 'size-8' },
};

/** App logo on a neutral tile. `bare` drops the tile (used inside canvas nodes). */
export default function IntegrationIcon({ icon, name, size = 'md', bare = false, className }) {
    const [broken, setBroken] = useState(false);
    const s = SIZES[size];
    const isUrl = typeof icon === 'string' && /^(https?:)?\/\/|^\//.test(icon);
    const Glyph = ICONS[icon] ?? Plug;

    const inner = isUrl && !broken
        ? <img src={icon} alt="" className={cn(s.img, 'object-contain', /\.mono\.svg$/.test(icon) && 'dark:invert')} onError={() => setBroken(true)} loading="lazy" draggable={false} />
        : <Glyph className={cn(s.glyph, 'text-fg-muted')} strokeWidth={1.75} aria-hidden />;

    if (bare) return <span className={cn('inline-flex items-center justify-center', className)} title={name}>{inner}</span>;

    return (
        <span title={name} className={cn('inline-flex shrink-0 items-center justify-center border border-line bg-surface', s.box, className)}>
            {inner}
            <span className="sr-only">{name}</span>
        </span>
    );
}

/** Up to two app logos and a "+N" chip, in a fixed width so the text next to it lines up. */
export function AppStack({ apps, size = 'sm' }) {
    const box = size === 'md' ? 'size-9 rounded-lg text-12' : 'size-7 rounded-md text-11';
    return (
        <div className={cn('flex shrink-0 -space-x-2', size === 'md' ? 'w-[92px]' : 'w-[68px]')}>
            {apps.slice(0, 2).map((app) => <IntegrationIcon key={app.key} icon={app.icon} name={app.name} size={size} className="ring-2 ring-surface" />)}
            {apps.length > 2 && (
                <span className={cn('relative inline-flex items-center justify-center bg-surface-2 font-semibold text-fg-muted ring-2 ring-surface', box)}
                    title={apps.slice(2).map((a) => a.name).join(', ')}>+{apps.length - 2}</span>
            )}
        </div>
    );
}

