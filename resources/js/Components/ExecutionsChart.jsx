import { useEffect, useMemo, useRef, useState } from 'react';
import { cn } from '@/lib/utils';

const HEIGHT = 220;
const PAD = { top: 12, right: 8, bottom: 26, left: 34 };

function niceMax(value) {
    if (value <= 4) return 4;
    const magnitude = 10 ** Math.floor(Math.log10(value));
    const step = [1, 2, 2.5, 5, 10].find((s) => s * magnitude * 4 >= value) * magnitude;
    return step * 4;
}

/** Column with square baseline and 4px rounded data-end on top. */
function columnPath(x, y, w, h, r) {
    if (h <= 0) return '';
    const rr = Math.min(r, h, w / 2);
    return `M${x},${y + h} V${y + rr} Q${x},${y} ${x + rr},${y} H${x + w - rr} Q${x + w},${y} ${x + w},${y + rr} V${y + h} Z`;
}

const dayLabel = (iso, opts) => new Date(`${iso}T00:00:00`).toLocaleDateString(undefined, opts);

/**
 * 14-day production runs, stacked succeeded (bottom) + failed (top). Colours are the validated
 * --chart-ok / --chart-fail pair; identity also carried by the legend, tooltip and table view.
 */
export default function ExecutionsChart({ series }) {
    const ref = useRef(null);
    const [width, setWidth] = useState(640);
    const [hover, setHover] = useState(null);
    const [table, setTable] = useState(false);

    useEffect(() => {
        const observer = new ResizeObserver(([entry]) => setWidth(Math.max(280, entry.contentRect.width)));
        observer.observe(ref.current);
        return () => observer.disconnect();
    }, []);

    const max = useMemo(() => niceMax(Math.max(0, ...series.map((d) => d.succeeded + d.failed))), [series]);
    const plotW = width - PAD.left - PAD.right;
    const plotH = HEIGHT - PAD.top - PAD.bottom;
    const band = plotW / series.length;
    const barW = Math.min(24, band * 0.62);
    const y = (v) => PAD.top + plotH - (v / max) * plotH;
    const ticks = [0, max / 4, max / 2, (max * 3) / 4, max];
    const total = series.reduce((sum, d) => sum + d.succeeded + d.failed, 0);

    return (
        <div>
            <div className="mb-3 flex flex-wrap items-center justify-between gap-3">
                <div className="flex items-center gap-4 text-12h text-fg-muted">
                    <span className="flex items-center gap-1.5"><span className="size-2.5 rounded-sm bg-chart-ok" />Succeeded</span>
                    <span className="flex items-center gap-1.5"><span className="size-2.5 rounded-sm bg-chart-fail" />Failed</span>
                </div>
                <button type="button" onClick={() => setTable((t) => !t)} className="text-12h font-medium text-fg-muted hover:text-fg">
                    {table ? 'Show chart' : 'Show as table'}
                </button>
            </div>

            <div ref={ref} className="relative min-w-0 overflow-hidden">
                {table ? (
                    <div className="max-h-[220px] overflow-auto rounded-lg border border-line">
                        <table className="w-full text-12h">
                            <thead className="sticky top-0 bg-surface-2 text-fg-muted"><tr><th className="px-3 py-1.5 text-left font-medium">Day</th><th className="px-3 py-1.5 text-right font-medium">Succeeded</th><th className="px-3 py-1.5 text-right font-medium">Failed</th></tr></thead>
                            <tbody className="divide-y divide-line">
                                {[...series].reverse().map((d) => (
                                    <tr key={d.day}><td className="px-3 py-1.5">{dayLabel(d.day, { weekday: 'short', month: 'short', day: 'numeric' })}</td><td className="px-3 py-1.5 text-right tabular-nums">{d.succeeded}</td><td className="px-3 py-1.5 text-right tabular-nums">{d.failed}</td></tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                ) : (
                    <svg width={width} height={HEIGHT} role="img" aria-label={`Executions per day for the last 14 days, ${total} in total`} className="block overflow-visible">
                        {ticks.map((t) => (
                            <g key={t}>
                                <line x1={PAD.left} x2={width - PAD.right} y1={y(t)} y2={y(t)} stroke="var(--line)" strokeWidth="1" />
                                <text x={PAD.left - 8} y={y(t)} dy="0.32em" textAnchor="end" className="fill-fg-subtle text-11 tabular-nums">{Math.round(t)}</text>
                            </g>
                        ))}
                        {series.map((d, i) => {
                            const cx = PAD.left + band * i + band / 2;
                            const x = cx - barW / 2;
                            const okH = (d.succeeded / max) * plotH;
                            const failH = (d.failed / max) * plotH;
                            const gap = d.succeeded && d.failed ? 2 : 0;
                            const baseY = PAD.top + plotH;
                            return (
                                <g key={d.day} onMouseEnter={() => setHover(i)} onMouseLeave={() => setHover(null)}>
                                    <rect x={PAD.left + band * i} y={PAD.top} width={band} height={plotH} fill={hover === i ? 'var(--surface-2)' : 'transparent'} />
                                    {d.failed === 0
                                        ? <path d={columnPath(x, baseY - okH, barW, okH, 4)} fill="var(--chart-ok)" />
                                        : <rect x={x} y={baseY - okH} width={barW} height={okH} fill="var(--chart-ok)" />}
                                    {d.failed > 0 && <path d={columnPath(x, baseY - okH - gap - failH, barW, failH, 4)} fill="var(--chart-fail)" />}
                                    {(i % 2 === series.length % 2 || series.length <= 7) && (
                                        <text x={cx} y={HEIGHT - 6} textAnchor="middle" className="fill-fg-subtle text-11">{dayLabel(d.day, { month: 'short', day: 'numeric' })}</text>
                                    )}
                                </g>
                            );
                        })}
                    </svg>
                )}

                {!table && hover !== null && (() => {
                    const d = series[hover];
                    const left = Math.min(Math.max(PAD.left + band * hover + band / 2, 80), width - 80);
                    return (
                        <div className="pointer-events-none absolute top-0 z-10 -translate-x-1/2 rounded-lg border border-line bg-surface px-3 py-2 text-12 shadow-pop" style={{ left }}>
                            <p className="mb-1 font-medium">{dayLabel(d.day, { weekday: 'long', month: 'short', day: 'numeric' })}</p>
                            <p className="flex items-center justify-between gap-6 text-fg-muted"><span className="flex items-center gap-1.5"><span className="size-2 rounded-sm bg-chart-ok" />Succeeded</span><span className="font-medium text-fg tabular-nums">{d.succeeded}</span></p>
                            <p className="flex items-center justify-between gap-6 text-fg-muted"><span className="flex items-center gap-1.5"><span className="size-2 rounded-sm bg-chart-fail" />Failed</span><span className="font-medium text-fg tabular-nums">{d.failed}</span></p>
                        </div>
                    );
                })()}

                {!table && total === 0 && (
                    <p className={cn('absolute inset-x-0 top-[38%] text-center text-13 text-fg-subtle')}>No production runs yet. Activate a workflow to see its runs here.</p>
                )}
            </div>
        </div>
    );
}
