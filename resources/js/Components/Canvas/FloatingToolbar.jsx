import { useReactFlow } from '@xyflow/react';
import { Maximize, Minus, Plus, ScanSearch } from 'lucide-react';
import { reducedMotion } from '@/lib/utils';

function ToolButton({ label, onClick, children }) {
    return (
        <button type="button" onClick={onClick} aria-label={label} title={label}
            className="flex size-8 items-center justify-center rounded-md text-fg-muted transition-colors hover:bg-surface-2 hover:text-fg active:bg-surface-3">
            {children}
        </button>
    );
}

/** Zoom controls, bottom-left of the canvas. */
export default function FloatingToolbar() {
    const { zoomIn, zoomOut, fitView, zoomTo } = useReactFlow();
    const ms = (duration) => (reducedMotion() ? 0 : duration); // reduced motion: jump straight to the new zoom
    return (
        <div className="absolute bottom-5 left-5 z-10 flex items-center gap-0.5 rounded-xl border border-line bg-surface p-1 shadow-card">
            <ToolButton label="Fit to screen" onClick={() => fitView({ duration: ms(250), padding: 0.3, maxZoom: 1.1 })}><Maximize className="size-4" /></ToolButton>
            <ToolButton label="Zoom in" onClick={() => zoomIn({ duration: ms(150) })}><Plus className="size-4" /></ToolButton>
            <ToolButton label="Zoom out" onClick={() => zoomOut({ duration: ms(150) })}><Minus className="size-4" /></ToolButton>
            <ToolButton label="Reset zoom" onClick={() => zoomTo(1, { duration: ms(200) })}><ScanSearch className="size-4" /></ToolButton>
        </div>
    );
}
