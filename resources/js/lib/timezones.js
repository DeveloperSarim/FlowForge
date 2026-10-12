import { useMemo } from 'react';

/** Timezone list with UTC offsets, from the browser (no server payload). */
export function useTimezones() {
    return useMemo(() => {
        const zones = typeof Intl.supportedValuesOf === 'function' ? Intl.supportedValuesOf('timeZone') : [];
        return [...new Set(['UTC', ...zones])].map((zone) => {
            let offset = '';
            try {
                offset = new Intl.DateTimeFormat('en', { timeZone: zone, timeZoneName: 'shortOffset' }).formatToParts().find((p) => p.type === 'timeZoneName')?.value ?? '';
            } catch { /* unknown to this browser */ }
            return { value: zone, label: zone.replace(/_/g, ' '), hint: offset, group: zone.includes('/') ? zone.split('/')[0] : 'Other', keywords: offset };
        });
    }, []);
}
