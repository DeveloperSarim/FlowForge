import { router, useForm } from '@inertiajs/react';
import { Button, Card, Field, Input } from '@/Components/ui';
import { timeAgo } from '@/lib/utils';

/** Settings → Live demo (admins, only with FLOWFORGE_DEMO=true): the visitors' login and the hourly reset point. */
export default function DemoPanel({ demo }) {
    const form = useForm({ email: demo.login.email, password: demo.login.password });
    return (
        <Card className="p-6">
            <h2 className="text-16 font-semibold">Live demo</h2>
            <p className="mt-0.5 max-w-xl text-13 text-fg-muted">Demo mode is on for this copy. Visitors sign in with the login below (it's shown on the sign-in page) and can build and explore, but not delete, add credentials, run workflows or change settings.</p>
            <form className="mt-5 grid max-w-xl gap-3 sm:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_auto] sm:items-end" onSubmit={(e) => { e.preventDefault(); form.put('/settings/demo', { preserveScroll: true }); }}>
                <Field label="Demo email" error={form.errors.email}>{(id) => <Input id={id} type="email" value={form.data.email} onChange={(e) => form.setData('email', e.target.value)} />}</Field>
                <Field label="Demo password" error={form.errors.password}>{(id) => <Input id={id} value={form.data.password} onChange={(e) => form.setData('password', e.target.value)} />}</Field>
                <Button type="submit" loading={form.processing}>Save</Button>
            </form>
            <div className="mt-5 flex flex-wrap items-center gap-3 border-t border-line pt-5">
                <Button onClick={() => router.post('/settings/demo/snapshot', {}, { preserveScroll: true })}>Save current workflows as the starting point</Button>
                <span className="text-12h text-fg-subtle">{demo.snapshot ? `Starting point: ${demo.snapshot.workflows} workflows, saved ${timeAgo(demo.snapshot.saved_at)}. Resets hourly.` : 'No starting point yet: visitors\' changes stay until you save one.'}</span>
            </div>
        </Card>
    );
}
