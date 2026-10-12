import { useEffect, useMemo, useState } from 'react';
import { Head, router, useForm } from '@inertiajs/react';
import { Check, CheckCircle2, Circle, Copy, Info, Loader2, XCircle } from 'lucide-react';
import { Button, Combobox, Field, Input, Tabs } from '@/Components/ui';
import { Logo } from '@/Layouts/AppLayout';
import CronAutoSetup from '@/Components/CronAutoSetup';
import { useTimezones } from '@/lib/timezones';
import { LicenseForm } from '@/Pages/License/Activate';
import { cn, copy, errorMessage, timeAgo } from '@/lib/utils';

const STEPS = [
    { key: 'requirements', title: 'Check the server', hint: 'PHP version, extensions, folder permissions' },
    { key: 'license', title: 'Activate license', hint: 'Your CodeCanyon purchase code' },
    { key: 'database', title: 'Connect MySQL', hint: 'Creates the tables FlowForge needs' },
    { key: 'admin', title: 'Create your account', hint: 'The single administrator login' },
    { key: 'mail', title: 'Email alerts', hint: 'Get told when a workflow fails' },
    { key: 'cron', title: 'Turn on the scheduler', hint: 'One cron line runs every workflow' },
];

export default function Wizard({ checks, state, defaults, cron, adminEmail }) {
    const initial = state.mail ? 'cron' : state.admin ? 'mail' : state.database ? 'admin' : state.license ? 'database' : 'requirements';
    const [step, setStep] = useState(initial);
    useEffect(() => setStep(initial), [initial]);

    const done = { requirements: state.license || step !== 'requirements', license: state.license, database: state.database, admin: state.admin, mail: state.mail, cron: false };
    const current = STEPS.findIndex((s) => s.key === step);

    return (
        <div className="min-h-screen px-4 py-10 sm:py-16">
            <Head title="Install" />
            <div className="mx-auto grid max-w-4xl gap-10 md:grid-cols-[230px_1fr]">
                <div>
                    <div className="mb-8 flex items-center gap-2.5">
                        <Logo />
                        <span className="text-[15px] font-semibold">FlowForge setup</span>
                    </div>
                    <ol className="space-y-1">
                        {STEPS.map((s, index) => (
                            <li key={s.key} className={cn('flex gap-3 rounded-lg px-2 py-2', index === current && 'bg-surface')}>
                                <span className={cn('mt-0.5 flex size-5 shrink-0 items-center justify-center rounded-full border text-[11px] font-semibold',
                                    done[s.key] && index !== current ? 'border-ok bg-ok-soft text-ok' : index === current ? 'border-primary text-primary' : 'border-line text-fg-subtle')}>
                                    {done[s.key] && index !== current ? <Check className="size-3" strokeWidth={3} /> : index + 1}
                                </span>
                                <span>
                                    <span className={cn('block text-[13.5px] font-medium', index > current && 'text-fg-muted')}>{s.title}</span>
                                    <span className="block text-[12px] text-fg-subtle">{s.hint}</span>
                                </span>
                            </li>
                        ))}
                    </ol>
                </div>

                <section className="rounded-xl border border-line bg-surface p-6 sm:p-8">
                    {step === 'requirements' && <Requirements checks={checks} onNext={() => setStep(state.license ? 'database' : 'license')} />}
                    {step === 'license' && (
                        <>
                            <Heading title="Activate your license">
                                Enter the purchase code from CodeCanyon. One license activates FlowForge on one domain; this one is {window.location.hostname}. Development hosts (localhost, *.test) don't use it up.
                            </Heading>
                            <div className="max-w-md"><LicenseForm action="/install/license" submitLabel="Activate and continue" /></div>
                        </>
                    )}
                    {step === 'database' && <Database defaults={defaults} />}
                    {step === 'admin' && <Admin />}
                    {step === 'mail' && <MailStep adminEmail={adminEmail} />}
                    {step === 'cron' && <Cron cron={cron} />}
                </section>
            </div>
        </div>
    );
}

function Heading({ title, children }) {
    return (
        <div className="mb-6">
            <h1 className="text-xl font-semibold">{title}</h1>
            {children && <p className="mt-1 max-w-prose text-[13.5px] text-fg-muted">{children}</p>}
        </div>
    );
}

function Requirements({ checks, onNext }) {
    const groups = useMemo(() => Object.groupBy?.(checks, (c) => c.group) ?? checks.reduce((acc, c) => ((acc[c.group] ??= []).push(c), acc), {}), [checks]);
    const failing = checks.filter((c) => !c.ok).length;

    return (
        <>
            <Heading title="Check the server">
                {failing === 0 ? 'Everything FlowForge needs is in place.' : `${failing} item${failing > 1 ? 's need' : ' needs'} attention. Fix them in your hosting panel, then re-check.`}
            </Heading>
            <div className="space-y-5">
                {Object.entries(groups).map(([group, items]) => (
                    <div key={group}>
                        <h2 className="mb-2 text-[12.5px] font-medium text-fg-muted">{group}</h2>
                        <ul className="divide-y divide-line rounded-lg border border-line">
                            {items.map((item) => (
                                <li key={item.label} className="flex items-center gap-3 px-3 py-2 text-[13px]">
                                    {item.ok ? <CheckCircle2 className="size-4 shrink-0 text-ok" /> : <XCircle className="size-4 shrink-0 text-fail" />}
                                    <span className="font-mono text-[12.5px]">{item.label}</span>
                                    <span className={cn('ml-auto text-right', item.ok ? 'text-fg-subtle' : 'text-fail')}>{item.detail}</span>
                                </li>
                            ))}
                        </ul>
                    </div>
                ))}
            </div>
            <div className="mt-7 flex justify-end gap-2">
                <Button onClick={() => router.reload()}>Re-check</Button>
                <Button variant="primary" disabled={failing > 0} onClick={onNext}>Continue</Button>
            </div>
        </>
    );
}

function Database({ defaults }) {
    const form = useForm({ host: defaults.host, port: defaults.port, database: defaults.database ?? '', username: defaults.username ?? '', password: '' });
    const [test, setTest] = useState(null);

    const runTest = async () => {
        setTest({ pending: true });
        try {
            const { data } = await window.axios.post('/install/database/test', form.data);
            setTest({ ok: true, message: data.message });
        } catch (error) {
            setTest({ ok: false, message: errorMessage(error) });
        }
    };

    const submit = (event) => {
        event.preventDefault();
        form.post('/install/database', { preserveScroll: true });
    };

    const bind = (key) => ({ value: form.data[key], onChange: (e) => form.setData(key, e.target.value), 'aria-invalid': !!form.errors[key] });

    return (
        <form onSubmit={submit}>
            <Heading title="Connect MySQL">
                Create an empty database and user in cPanel (MySQL Databases), then enter them here. FlowForge tests the connection, saves it to .env and creates its tables.
            </Heading>
            <div className="grid gap-4 sm:grid-cols-[1fr_110px]">
                <Field label="Host" required error={form.errors.host} help="Usually localhost on shared hosting.">{(id) => <Input id={id} {...bind('host')} />}</Field>
                <Field label="Port" required error={form.errors.port}>{(id) => <Input id={id} inputMode="numeric" {...bind('port')} />}</Field>
                <Field label="Database name" required error={form.errors.database} className="sm:col-span-2">{(id) => <Input id={id} placeholder="cpaneluser_flowforge" {...bind('database')} />}</Field>
                <Field label="Username" required error={form.errors.username}>{(id) => <Input id={id} autoComplete="off" {...bind('username')} />}</Field>
                <Field label="Password" error={form.errors.password} className="sm:col-span-2">{(id) => <Input id={id} type="password" autoComplete="new-password" {...bind('password')} />}</Field>
            </div>
            {form.errors.setup && <p className="mt-4 text-[13px] text-fail">{form.errors.setup}</p>}
            {test && !test.pending && (
                <p className={cn('mt-4 flex items-center gap-2 text-[13px]', test.ok ? 'text-ok' : 'text-fail')}>
                    {test.ok ? <CheckCircle2 className="size-4" /> : <XCircle className="size-4" />} {test.message}
                </p>
            )}
            <div className="mt-7 flex justify-end gap-2">
                <Button onClick={runTest} loading={test?.pending}>Test connection</Button>
                <Button type="submit" variant="primary" loading={form.processing}>
                    {form.processing ? 'Creating tables…' : 'Save and create tables'}
                </Button>
            </div>
        </form>
    );
}

function Admin() {
    const form = useForm({ name: '', email: '', password: '', password_confirmation: '', timezone: Intl.DateTimeFormat().resolvedOptions().timeZone ?? 'UTC' });
    const bind = (key) => ({ value: form.data[key], onChange: (e) => form.setData(key, e.target.value), 'aria-invalid': !!form.errors[key] });
    const zones = useTimezones();

    return (
        <form onSubmit={(e) => { e.preventDefault(); form.post('/install/admin'); }}>
            <Heading title="Create your account">This is the only login. You can change the email and password later under Account.</Heading>
            <div className="grid gap-4 sm:grid-cols-2">
                <Field label="Name" required error={form.errors.name}>{(id) => <Input id={id} autoComplete="name" {...bind('name')} />}</Field>
                <Field label="Email" required error={form.errors.email}>{(id) => <Input id={id} type="email" autoComplete="email" {...bind('email')} />}</Field>
                <Field label="Password" required error={form.errors.password} help="At least 10 characters.">{(id) => <Input id={id} type="password" autoComplete="new-password" {...bind('password')} />}</Field>
                <Field label="Confirm password" required>{(id) => <Input id={id} type="password" autoComplete="new-password" {...bind('password_confirmation')} />}</Field>
                <Field label="Timezone" error={form.errors.timezone} help="Schedules run in this timezone. Change it later in Settings, General." className="sm:col-span-2">
                    {(id) => <Combobox id={id} value={form.data.timezone} onChange={(v) => form.setData('timezone', v)} options={zones} searchPlaceholder="Search city or offset: Karachi, GMT+5…" />}
                </Field>
            </div>
            <div className="mt-7 flex justify-end">
                <Button type="submit" variant="primary" loading={form.processing}>Create account</Button>
            </div>
        </form>
    );
}

function MailStep({ adminEmail }) {
    const form = useForm({ host: '', port: 587, encryption: 'tls', username: '', password: '', from_address: '', from_name: 'FlowForge', notify_email: adminEmail ?? '' });
    const [test, setTest] = useState(null);
    const bind = (key) => ({ value: form.data[key], onChange: (e) => form.setData(key, e.target.value), 'aria-invalid': !!form.errors[key] });

    const runTest = async () => {
        setTest({ pending: true });
        try {
            const { data } = await window.axios.post('/install/mail/test', form.data);
            setTest({ ok: true, message: data.message });
        } catch (error) {
            setTest({ ok: false, message: errorMessage(error) });
        }
    };

    return (
        <form onSubmit={(e) => { e.preventDefault(); form.post('/install/mail'); }}>
            <Heading title="Email alerts">
                FlowForge emails you when a workflow fails or the scheduler stops. Use your hosting's mailbox (cPanel → Email Accounts → Connect Devices shows the SMTP details) or any SMTP service.
            </Heading>
            <div className="grid gap-4 sm:grid-cols-6">
                <Field label="SMTP host" required error={form.errors.host} className="sm:col-span-3">{(id) => <Input id={id} placeholder="mail.yourdomain.com" {...bind('host')} />}</Field>
                <Field label="Port" required error={form.errors.port} className="sm:col-span-1">{(id) => <Input id={id} inputMode="numeric" {...bind('port')} />}</Field>
                <Field label="Security" className="sm:col-span-2">{(id) => (
                    <select id={id} className="h-9 w-full rounded-lg border border-line bg-surface px-3 text-sm" {...bind('encryption')}>
                        <option value="tls">STARTTLS (587)</option><option value="ssl">SSL/TLS (465)</option><option value="none">None</option>
                    </select>
                )}</Field>
                <Field label="Username" error={form.errors.username} className="sm:col-span-3">{(id) => <Input id={id} autoComplete="off" placeholder="flowforge@yourdomain.com" {...bind('username')} />}</Field>
                <Field label="Password" error={form.errors.password} className="sm:col-span-3">{(id) => <Input id={id} type="password" autoComplete="new-password" {...bind('password')} />}</Field>
                <Field label="From address" required error={form.errors.from_address} className="sm:col-span-3">{(id) => <Input id={id} type="email" placeholder="flowforge@yourdomain.com" {...bind('from_address')} />}</Field>
                <Field label="Send alerts to" error={form.errors.notify_email} className="sm:col-span-3">{(id) => <Input id={id} type="email" {...bind('notify_email')} />}</Field>
            </div>
            {test && !test.pending && (
                <p className={cn('mt-4 flex items-center gap-2 text-[13px]', test.ok ? 'text-ok' : 'text-fail')}>
                    {test.ok ? <CheckCircle2 className="size-4" /> : <XCircle className="size-4" />} {test.message}
                </p>
            )}
            <div className="mt-7 flex flex-wrap items-center justify-between gap-2">
                <Button variant="ghost" onClick={() => router.post('/install/mail/skip')}>Skip for now</Button>
                <div className="flex gap-2">
                    <Button onClick={runTest} loading={test?.pending}>Send test email</Button>
                    <Button type="submit" variant="primary" loading={form.processing}>Save and continue</Button>
                </div>
            </div>
            <p className="mt-3 text-[12px] text-fg-subtle">You can change this later under Settings → Email & alerts.</p>
        </form>
    );
}

const PANELS = [
    { value: 'hostinger', label: 'Hostinger hPanel' },
    { value: 'cpanel', label: 'cPanel' },
    { value: 'directadmin', label: 'DirectAdmin' },
    { value: 'ssh', label: 'SSH / VPS' },
];

/** Click-by-click cron setup per hosting panel, with this server's PHP and artisan paths filled in. */
function guide(panel, cron) {
    const plain = `${cron.php} ${cron.artisan} schedule:run`;
    const quiet = `${plain} >/dev/null 2>&1`;
    const B = ({ children }) => <strong className="font-semibold text-fg">{children}</strong>;
    return {
        hostinger: {
            steps: [
                <>In hPanel go to <B>Websites</B>, click <B>Dashboard</B> next to this site, then open <B>Advanced</B> → <B>Cron Jobs</B>.</>,
                <>Under <B>Create a new cron job</B>, choose the type <B>Custom</B>.</>,
                <>Paste this into <B>Command to run</B>:</>,
                <>Leave <B>Minute, Hour, Day, Month and Weekday</B> all on <B>*</B> so it runs every minute, then click <B>Save</B>.</>,
            ],
            command: plain,
            note: `Hostinger runs cron with its default PHP. If the check stays grey, replace the first part with /opt/alt/php${cron.version}/usr/bin/php (your site's PHP version).`,
        },
        cpanel: {
            steps: [
                <>Log in to cPanel and open <B>Advanced</B> → <B>Cron Jobs</B>.</>,
                <>Under <B>Add New Cron Job</B>, set <B>Common Settings</B> to <B>Once Per Minute (* * * * *)</B>.</>,
                <>Paste this into <B>Command</B>:</>,
                <>Click <B>Add New Cron Job</B>.</>,
            ],
            command: quiet,
            note: `Cron must use PHP 8.2 or newer. If the check stays grey, try /opt/cpanel/ea-php${cron.version}/root/usr/bin/php, or /opt/alt/php${cron.version}/usr/bin/php on CloudLinux servers.`,
        },
        directadmin: {
            steps: [
                <>Open <B>Advanced Features</B> → <B>Cron Jobs</B> → <B>Create Cron Job</B>.</>,
                <>Set <B>Minute, Hour, Day of Month, Month and Day of Week</B> to <B>*</B>.</>,
                <>Paste this into <B>Command</B>:</>,
                <>Click <B>Create</B>.</>,
            ],
            command: quiet,
        },
        ssh: {
            steps: [<>Connect over SSH as the website's user and run <code className="font-mono text-[12.5px]">crontab -e</code>.</>, <>Add this line, then save and exit:</>],
            command: cron.command,
        },
    }[panel];
}

function Cron({ cron }) {
    const [panel, setPanel] = useState('hostinger');
    const [status, setStatus] = useState({ last_run: null, verified: false });
    const [waited, setWaited] = useState(0);
    const [finishing, setFinishing] = useState(false);
    const g = guide(panel, cron);

    useEffect(() => {
        let alive = true;
        const poll = async () => {
            try {
                const { data } = await window.axios.get('/install/heartbeat');
                if (alive) setStatus(data);
            } catch { /* keep polling */ }
        };
        poll();
        const timer = setInterval(() => { poll(); setWaited((s) => s + 5); }, 5000);
        return () => { alive = false; clearInterval(timer); };
    }, []);

    const finish = () => {
        setFinishing(true);
        router.post('/install/finish', {}, { onError: () => setFinishing(false) });
    };

    return (
        <>
            <Heading title="Turn on the scheduler">
                FlowForge runs workflows in the background through one cron job. Add it in your hosting panel; setup finishes once it has run.
            </Heading>

            <p className="mb-5 flex gap-2.5 rounded-lg bg-info-soft px-3.5 py-3 text-[13px] text-fg">
                <Info className="mt-px size-4 shrink-0 text-info" />
                <span>You only ever add this <strong>one</strong> cron job. It runs every workflow: schedules, webhooks and app triggers. You never add a cron per workflow.</span>
            </p>

            <CronAutoSetup endpoint="/install/cron/auto" className="mb-5" />
            <p className="mb-3 text-[13px] font-medium text-fg">Or add it yourself in your hosting panel:</p>
            <Tabs value={panel} onChange={setPanel} items={PANELS} className="mb-4 flex-wrap" />
            <ol className="space-y-3">
                {g.steps.map((text, i) => (
                    <li key={i} className="flex gap-3 text-[13.5px] leading-relaxed text-fg-muted">
                        <span className="mt-0.5 flex size-5 shrink-0 items-center justify-center rounded-full bg-surface-2 text-[11px] font-semibold text-fg">{i + 1}</span>
                        <div className="min-w-0 flex-1">
                            {text}
                            {i === g.steps.length - (panel === 'ssh' ? 1 : 2) && (
                                <div className="mt-2 flex items-start gap-2 rounded-lg border border-line bg-surface-2 p-2.5">
                                    <code className="flex-1 font-mono text-[12.5px] leading-relaxed break-all text-fg">{g.command}</code>
                                    <Button size="sm" variant="outline" onClick={() => copy(g.command, 'Command copied')}><Copy className="size-3.5" />Copy</Button>
                                </div>
                            )}
                        </div>
                    </li>
                ))}
            </ol>
            {g.note && <p className="mt-3 text-[12.5px] text-fg-subtle">{g.note}</p>}

            <div className={cn('mt-6 flex items-start gap-3 rounded-xl border px-4 py-3.5 text-[13px] transition-colors', status.verified ? 'border-ok/40 bg-ok-soft' : 'border-line')} aria-live="polite">
                {status.verified
                    ? <CheckCircle2 className="mt-px size-5 shrink-0 text-ok" />
                    : <span className="flex size-5 shrink-0 items-center justify-center"><span className="animate-running size-2.5 rounded-full bg-primary" /></span>}
                <div>
                    <p className="font-medium text-fg">{status.verified ? 'Cron is running' : status.last_run ? 'Cron has stopped running' : 'Waiting for the first cron run…'}</p>
                    <p className="text-fg-muted">
                        {status.verified
                            ? `Last run ${timeAgo(status.last_run)}. You can finish setup.`
                            : status.last_run
                                ? `The last run was ${timeAgo(status.last_run)}, which is too long ago. Check the cron entry.`
                                : 'Checking every 5 seconds. Cron starts at the top of each minute, so allow up to a minute after saving.'}
                    </p>
                </div>
            </div>

            {!status.verified && waited >= 120 && (
                <details className="mt-3 rounded-xl border border-warn/40 bg-warn-soft px-4 py-3 text-[13px]" open>
                    <summary className="cursor-pointer font-medium text-fg">Still waiting? Common fixes</summary>
                    <ul className="mt-2 list-disc space-y-1.5 pl-5 text-fg-muted">
                        <li>Paste the command exactly as shown, including the full path to <span className="font-mono">artisan</span>.</li>
                        <li>Cron's PHP must be 8.2 or newer: use the versioned PHP path in the note above.</li>
                        <li>The schedule must be every minute: all five time fields set to <span className="font-mono">*</span>.</li>
                        <li>If your panel has a Terminal, run <span className="font-mono">{cron.php} {cron.artisan} schedule:run</span> once. It should finish quietly; any error it prints is the problem.</li>
                    </ul>
                </details>
            )}

            <div className="mt-7 flex flex-wrap items-center justify-between gap-3">
                <p className="flex items-center gap-1.5 text-[12.5px] text-fg-subtle"><Circle className="size-2 fill-current" />{status.verified ? 'Finishing locks this installer.' : 'Finish unlocks when the cron check turns green.'}</p>
                <Button variant="primary" onClick={finish} loading={finishing} disabled={!status.verified}>Finish and sign in</Button>
            </div>
        </>
    );
}
