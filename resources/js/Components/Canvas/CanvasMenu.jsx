import { useEffect, useLayoutEffect, useRef, useState } from 'react';
import { cn } from '@/lib/utils';
import { usePresence } from '@/Components/ui';

/** "⌘" on Apple devices, "Ctrl+" elsewhere, for the shortcut hints. */
export const MOD = typeof navigator !== 'undefined' && /Mac|iPhone|iPad/.test(navigator.platform) ? '⌘' : 'Ctrl+';

/**
 * The canvas's right-click menu. It opens at the pointer (kept inside the window, growing from the corner nearest the
 * pointer), moves with the arrow keys, and closes on a pick, Escape, a click elsewhere, a scroll or zoom.
 * menu = { x, y, items: [{ label, icon, shortcut, onClick, danger, disabled } | 'divider'] } or null.
 */
export default function CanvasMenu({ menu, onClose }) {
    const { mounted, closing } = usePresence(!!menu, 120);
    const last = useRef(menu);
    if (menu) last.current = menu;
    const shown = menu ?? last.current;
    const panel = useRef(null);
    const [place, setPlace] = useState(null);

    useLayoutEffect(() => {
        if (!menu || !panel.current) return;
        const { width, height } = panel.current.getBoundingClientRect();
        const flipX = menu.x + width > window.innerWidth - 8;
        const flipY = menu.y + height > window.innerHeight - 8;
        setPlace({
            left: Math.max(8, flipX ? menu.x - width : menu.x),
            top: Math.max(8, flipY ? menu.y - height : menu.y),
            transformOrigin: `${flipX ? 'right' : 'left'} ${flipY ? 'bottom' : 'top'}`,
        });
        panel.current.querySelector('button:not(:disabled)')?.focus({ preventScroll: true });
    }, [menu]);

    useEffect(() => {
        if (!menu) return undefined;
        const outside = (e) => !panel.current?.contains(e.target) && onClose();
        const key = (e) => {
            if (e.key === 'Escape') { e.preventDefault(); onClose(); }
            if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
                e.preventDefault();
                const buttons = [...panel.current.querySelectorAll('button:not(:disabled)')];
                const at = buttons.indexOf(document.activeElement);
                buttons[(at + (e.key === 'ArrowDown' ? 1 : buttons.length - 1)) % buttons.length]?.focus();
            }
        };
        window.addEventListener('pointerdown', outside, true);
        window.addEventListener('keydown', key, true);
        window.addEventListener('wheel', onClose, { passive: true });
        window.addEventListener('resize', onClose);
        return () => {
            window.removeEventListener('pointerdown', outside, true);
            window.removeEventListener('keydown', key, true);
            window.removeEventListener('wheel', onClose);
            window.removeEventListener('resize', onClose);
        };
    }, [menu, onClose]);

    if (!mounted || !shown) return null;
    return (
        <div ref={panel} role="menu" data-closing={closing ? '' : undefined} onContextMenu={(e) => e.preventDefault()}
            style={place ?? { left: shown.x, top: shown.y, visibility: 'hidden' }}
            className="ff-popover fixed z-[90] min-w-56 rounded-xl border border-line bg-surface p-1 shadow-pop">
            {shown.items.filter(Boolean).map((item, i) => (item === 'divider'
                ? <div key={`d${i}`} className="my-1 h-px bg-line" role="separator" />
                : (
                    <button key={item.label} type="button" role="menuitem" disabled={item.disabled}
                        onClick={() => { onClose(); item.onClick(); }}
                        className={cn('flex w-full items-center gap-2.5 rounded-lg px-2.5 py-1.5 text-left text-13 outline-none disabled:opacity-40',
                            item.danger ? 'text-fail hover:bg-fail-soft focus-visible:bg-fail-soft active:bg-fail-soft' : 'text-fg hover:bg-surface-2 focus-visible:bg-surface-2 active:bg-surface-3')}>
                        {item.icon && <item.icon className="size-4 shrink-0 opacity-70" />}
                        <span className="flex-1">{item.label}</span>
                        {item.shortcut && <span className="text-11h text-fg-subtle">{item.shortcut}</span>}
                    </button>
                )))}
        </div>
    );
}
