import { useState } from 'react';
import { router, useForm } from '@inertiajs/react';
import { BadgeCheck, RefreshCw, TriangleAlert } from 'lucide-react';
import { Button, Card, Dialog, Field, Input } from '@/Components/ui';
import { cn, errorMessage, timeAgo, toast } from '@/lib/utils';

/** Settings → License (admins): status, a manual re-check and freeing this domain to move the license. */
function DemoSection({ demo }) {
    const form = useForm({ email: demo.login.email, password: demo.login.password });
    return (
        <div className="mt-6 border-t border-line pt-5">
            <h3 className="text-[14px] font-semibold">Live demo</h3>
            <p className="mt-0.5 max-w-xl text-[13px] text-fg-muted">This copy runs your demo license. Visitors sign in with the login below (it's shown on the sign-in page) and can build and explore, but not delete, add credentials, run workflows or change settings.</p>
            <form className="mt-4 grid max-w-xl gap-3 sm:grid-cols-[1fr_1fr_auto] sm:items-end" onSubmit={(e) => { e.preventDefault(); form.put('/settings/demo', { preserveScroll: true }); }}>
                <Field label="Demo email" error={form.errors.email}>{(id) => <Input id={id} type="email" value={form.data.email} onChange={(e) => form.setData('email', e.target.value)} />}</Field>
                <Field label="Demo password" error={form.errors.password}>{(id) => <Input id={id} value={form.data.password} onChange={(e) => form.setData('password', e.target.value)} />}</Field>
                <Button type="submit" loading={form.processing}>Save</Button>
            </form>
            <div className="mt-4 flex flex-wrap items-center gap-3">
                <Button onClick={() => router.post('/settings/demo/snapshot', {}, { preserveScroll: true })}>Save current workflows as the starting point</Button>
                <span className="text-[12.5px] text-fg-subtle">{demo.snapshot ? `Starting point: ${demo.snapshot.workflows} workflows, saved ${timeAgo(demo.snapshot.saved_at)}. Resets hourly.` : 'No starting point yet: visitors\' changes stay until you save one.'}</span>
            </div>
        </div>
    );
}

export default function LicensePanel({ license: initial, demo }) {
    const [license, setLicense] = useState(initial);
    const [checking, setChecking] = useState(false);
    const [confirm, setConfirm] = useState(false);

    const check = async () => {
        setChecking(true);
        try {
            const { data } = await window.axios.post('/settings/license/refresh');
            setLicense(data.license);
            toast(data.license.valid ? 'License is active.' : data.license.problem, data.license.valid ? 'success' : 'error');
        } catch (error) {
            toast(errorMessage(error), 'error');
        } finally {
            setChecking(false);
        }
    };

    const rows = [
        ['Domain', license.domain], ['License', license.type], ['Buyer', license.buyer], ['Purchase code', license.code],
        ['Support until', license.supported_until && new Date(license.supported_until).toLocaleDateString(undefined, { dateStyle: 'medium' })],
        ['Activated', license.activated_at && timeAgo(license.activated_at)], ['Last checked', license.checked_at && timeAgo(license.checked_at)],
    ].filter(([, v]) => v);

    return (
        <Card className="p-6">
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h2 className="text-[16px] font-semibold">License</h2>
                    <p className="mt-0.5 max-w-xl text-[13px] text-fg-muted">One CodeCanyon license runs FlowForge on one domain. Moving to another domain? Deactivate here first, then activate there.</p>
                </div>
                <span className={cn('inline-flex items-center gap-1.5 rounded-lg px-2.5 py-1 text-[13px] font-medium', license.valid ? 'bg-ok-soft text-ok' : 'bg-fail-soft text-fail')}>
                    {license.valid ? <BadgeCheck className="size-4" /> : <TriangleAlert className="size-4" />}{license.valid ? (license.demo ? 'Active (demo)' : 'Active') : 'Not active'}
                </span>
            </div>
            {license.problem && <p className="mt-4 rounded-lg bg-fail-soft px-3.5 py-3 text-[13px] text-fail">{license.problem}</p>}
            <dl className="mt-5 grid gap-x-6 gap-y-3 sm:grid-cols-2">
                {rows.map(([label, value]) => (
                    <div key={label}><dt className="text-[12px] text-fg-subtle">{label}</dt><dd className="truncate text-[13.5px] font-medium">{value}</dd></div>
                ))}
            </dl>
            <div className="mt-6 flex flex-wrap gap-2">
                <Button onClick={check} loading={checking}>{!checking && <RefreshCw className="size-4" />}Check now</Button>
                <Button variant="danger" onClick={() => setConfirm(true)}>Deactivate this domain</Button>
            </div>
            {demo && <DemoSection demo={demo} />}
            <p className="mt-4 text-[12.5px] text-fg-subtle">Questions about your license or a free installation on your hPanel or cPanel hosting: <a href="mailto:support@sarimtools.com" className="font-medium text-primary hover:underline">support@sarimtools.com</a></p>

            <Dialog open={confirm} onClose={() => setConfirm(false)} title="Deactivate this domain?" description="FlowForge stops working here until a license is activated again. Your workflows and data stay.">
                <div className="flex justify-end gap-2">
                    <Button onClick={() => setConfirm(false)}>Cancel</Button>
                    <Button variant="danger" onClick={() => router.post('/settings/license/deactivate')}>Deactivate</Button>
                </div>
            </Dialog>
        </Card>
    );
}
