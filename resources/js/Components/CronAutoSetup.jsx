import { useState } from 'react';
import { CheckCircle2, TriangleAlert, Wand2 } from 'lucide-react';
import { Button } from '@/Components/ui';
import { cn, errorMessage } from '@/lib/utils';

/** "Set it up for me": asks the server to add the cron line itself (works when the host allows it). */
export default function CronAutoSetup({ endpoint, className }) {
    const [state, setState] = useState(null);

    const run = async () => {
        setState({ pending: true });
        try {
            const { data } = await window.axios.post(endpoint);
            setState(data);
        } catch (error) {
            setState({ ok: false, message: errorMessage(error) });
        }
    };

    return (
        <div className={cn('rounded-xl border border-primary/30 bg-primary-soft/50 px-4 py-3.5', className)}>
            <div className="flex flex-wrap items-center gap-3">
                <div className="min-w-0 flex-1">
                    <p className="text-13h font-medium text-fg">Let FlowForge add the cron job</p>
                    <p className="text-12h text-fg-muted">Works on hosts that allow it. If yours doesn't, follow the steps instead.</p>
                </div>
                <Button variant="primary" size="sm" onClick={run} loading={state?.pending}>{!state?.pending && <Wand2 className="size-3.5" />}Set it up for me</Button>
            </div>
            {state && !state.pending && (
                <p className={cn('mt-2.5 flex items-start gap-2 text-12h', state.ok ? 'text-ok' : 'text-warn')}>
                    {state.ok ? <CheckCircle2 className="mt-px size-4 shrink-0" /> : <TriangleAlert className="mt-px size-4 shrink-0" />}
                    {state.message}
                </p>
            )}
        </div>
    );
}
