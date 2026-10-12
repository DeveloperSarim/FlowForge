import { Head, useForm } from '@inertiajs/react';
import { KeyRound, Mail, TriangleAlert } from 'lucide-react';
import { Button, Field, Input } from '@/Components/ui';
import { Logo } from '@/Layouts/AppLayout';

export const PURCHASE_CODE_HELP = 'https://help.market.envato.com/hc/en-us/articles/202822600-Where-Is-My-Purchase-Code';

/** The purchase-code form, shared by the installer step and the activation page. */
export function LicenseForm({ action, submitLabel = 'Activate' }) {
    const form = useForm({ purchase_code: '' });
    return (
        <form onSubmit={(e) => { e.preventDefault(); form.post(action); }} className="space-y-4">
            <Field label="Purchase code" error={form.errors.purchase_code}
                help={<>The 36-character code from your CodeCanyon download (Downloads → License certificate). <a href={PURCHASE_CODE_HELP} target="_blank" rel="noreferrer" className="font-medium text-primary hover:underline">Where is it?</a></>}>
                {(id) => <Input id={id} required autoFocus autoComplete="off" spellCheck={false} placeholder="xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx" className="h-10 font-mono"
                    value={form.data.purchase_code} onChange={(e) => form.setData('purchase_code', e.target.value.trim())} aria-invalid={!!form.errors.purchase_code} />}
            </Field>
            <Button type="submit" variant="primary" size="lg" className="w-full justify-center" loading={form.processing}><KeyRound className="size-4" />{submitLabel}</Button>
        </form>
    );
}

/** Shown when an installed copy has no valid license for this domain. */
export default function Activate({ problem, domain }) {
    return (
        <div className="flex min-h-screen items-center justify-center bg-bg px-6 py-12">
            <Head title="Activate FlowForge" />
            <div className="w-full max-w-md">
                <Logo className="size-10" />
                <h1 className="mt-6 text-[24px] font-semibold tracking-tight">Activate FlowForge</h1>
                <p className="mt-1 text-[14px] text-fg-muted">One license runs FlowForge on one domain. This one is <strong className="text-fg">{domain}</strong>.</p>
                {problem && <p className="mt-5 flex gap-2.5 rounded-lg bg-warn-soft px-3.5 py-3 text-[13px] text-fg"><TriangleAlert className="mt-px size-4 shrink-0 text-warn" />{problem}</p>}
                <div className="mt-6"><LicenseForm action="/license" /></div>
                <p className="mt-6 flex items-center gap-2 text-[13px] text-fg-muted"><Mail className="size-4" />Need help? <a href="mailto:support@sarimtools.com" className="font-medium text-primary hover:underline">support@sarimtools.com</a></p>
            </div>
        </div>
    );
}
