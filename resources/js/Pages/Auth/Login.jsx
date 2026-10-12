import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { CheckCircle2 } from 'lucide-react';
import IntegrationIcon from '@/Components/IntegrationIcon';
import { Button, Field, Input } from '@/Components/ui';
import { Logo } from '@/Layouts/AppLayout';

// A few of the bundled app logos, laid out like nodes on the canvas (px inside a 580×360 stage).
const NODES = { webhook: [0, 140], slack: [170, 40], openai: [170, 240], hubspot: [340, 140], google_sheets: [508, 40], gmail: [508, 240] };
const LINKS = [['webhook', 'slack'], ['webhook', 'openai'], ['slack', 'hubspot'], ['openai', 'hubspot'], ['hubspot', 'google_sheets'], ['hubspot', 'gmail']];

export default function Login({ icons = {}, appCount = 0 }) {
    const { flash, demo } = usePage().props;
    const form = useForm({ email: '', password: '', remember: true });
    const demoLogin = () => form.transform(() => ({ email: demo.email, password: demo.password, remember: false })).post('/login');

    const submit = (event) => {
        event.preventDefault();
        form.post('/login', { onFinish: () => form.reset('password') });
    };

    return (
        <div className="grid min-h-screen lg:grid-cols-2">
            <Head title="Sign in" />
            <div className="flex items-center justify-center px-6 py-12">
                <div className="w-full max-w-sm">
                    <Logo className="size-10" />
                    <h1 className="mt-6 text-[26px] font-semibold tracking-tight">Welcome back</h1>
                    <p className="mt-1 text-[14px] text-fg-muted">Sign in to your FlowForge workspace.</p>
                    {demo && (
                        <div className="mt-6 rounded-xl border border-primary/30 bg-primary-soft/50 p-4">
                            <p className="text-[13.5px] font-semibold">Try the live demo</p>
                            <dl className="mt-2 grid grid-cols-[auto_1fr] gap-x-3 gap-y-1 text-[13px]">
                                <dt className="text-fg-muted">Email</dt><dd className="font-mono">{demo.email}</dd>
                                <dt className="text-fg-muted">Password</dt><dd className="font-mono">{demo.password}</dd>
                            </dl>
                            <Button variant="primary" className="mt-3 w-full justify-center" loading={form.processing} onClick={demoLogin}>Sign in to the demo</Button>
                        </div>
                    )}
                    {flash?.success && <p className="mt-5 flex items-start gap-2 rounded-lg bg-ok-soft px-3 py-2.5 text-[13px] text-ok"><CheckCircle2 className="mt-px size-4 shrink-0" />{flash.success}</p>}
                    <form onSubmit={submit} className="mt-8 space-y-4">
                        <Field label="Email" error={form.errors.email}>
                            {(id) => <Input id={id} type="email" autoComplete="username" autoFocus className="h-10" value={form.data.email} onChange={(e) => form.setData('email', e.target.value)} aria-invalid={!!form.errors.email} />}
                        </Field>
                        <Field label="Password" error={form.errors.password} aside={<Link href="/forgot-password" className="text-[12.5px] font-medium text-primary hover:underline">Forgot password?</Link>}>
                            {(id) => <Input id={id} type="password" autoComplete="current-password" className="h-10" value={form.data.password} onChange={(e) => form.setData('password', e.target.value)} />}
                        </Field>
                        <label className="flex items-center gap-2 text-[13px] text-fg-muted">
                            <input type="checkbox" className="size-4 rounded accent-[var(--primary)]" checked={form.data.remember} onChange={(e) => form.setData('remember', e.target.checked)} />
                            Keep me signed in
                        </label>
                        <Button type="submit" variant="primary" size="lg" className="w-full justify-center" loading={form.processing}>Sign in</Button>
                    </form>
                </div>
            </div>
            <div className="relative hidden overflow-hidden border-l border-line bg-canvas lg:block" aria-hidden
                style={{ backgroundImage: 'radial-gradient(var(--canvas-dot) 1.2px, transparent 1.2px)', backgroundSize: '22px 22px' }}>
                <div className="relative mx-auto mt-[16vh] h-[360px] w-[580px]">
                    <svg className="absolute inset-0 overflow-visible" width="580" height="360">
                        {LINKS.map(([a, b]) => {
                            const [x1, y1] = NODES[a];
                            const [x2, y2] = NODES[b];
                            const sx = x1 + 72, sy = y1 + 36, tx = x2, ty = y2 + 36, mid = (sx + tx) / 2;
                            return <path key={a + b} d={`M${sx},${sy} C${mid},${sy} ${mid},${ty} ${tx},${ty}`} stroke="var(--edge)" strokeWidth="2" fill="none" />;
                        })}
                    </svg>
                    {Object.entries(NODES).map(([key, [x, y]]) => (
                        <div key={key} className="absolute flex size-[72px] items-center justify-center rounded-2xl border border-line bg-surface shadow-pop" style={{ left: x, top: y }}>
                            <IntegrationIcon icon={icons[key]} name={key} size="lg" bare />
                        </div>
                    ))}
                </div>
                <div className="absolute inset-x-12 bottom-12">
                    <p className="text-[22px] font-semibold tracking-tight">Automate the busywork.</p>
                    <p className="mt-1 max-w-md text-[14px] text-fg-muted">Connect {appCount > 100 ? `${Math.floor(appCount / 100) * 100}+` : 'hundreds of'} apps on a visual canvas, and run it all on your own hosting with a single cron job.</p>
                </div>
            </div>
        </div>
    );
}
