import { useEffect, useState } from 'react';
import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { ArrowLeft, MailCheck, MailWarning } from 'lucide-react';
import { Button, Field, Input } from '@/Components/ui';
import { Logo } from '@/Layouts/AppLayout';

/** Two steps on one page: email → 6-digit code + new password. */
export default function ForgotPassword({ mailConfigured }) {
    const { flash } = usePage().props;
    const [step, setStep] = useState('email');
    const [cooldown, setCooldown] = useState(0);
    const request = useForm({ email: '' });
    const reset = useForm({ email: '', code: '', password: '', password_confirmation: '' });

    useEffect(() => {
        if (cooldown <= 0) return undefined;
        const timer = setTimeout(() => setCooldown((s) => s - 1), 1000);
        return () => clearTimeout(timer);
    }, [cooldown]);

    const sendCode = (e) => {
        e?.preventDefault();
        request.post('/forgot-password', {
            preserveScroll: true,
            onSuccess: () => { reset.setData('email', request.data.email); setStep('code'); setCooldown(60); },
        });
    };

    return (
        <div className="flex min-h-screen items-center justify-center bg-bg px-6 py-12">
            <Head title="Reset password" />
            <div className="w-full max-w-sm">
                <Logo className="size-10" />
                {!mailConfigured ? (
                    <>
                        <h1 className="mt-6 text-[24px] font-semibold tracking-tight">Reset your password</h1>
                        <div className="mt-5 rounded-xl border border-line bg-surface p-4 text-[13.5px] leading-relaxed text-fg-muted">
                            <p className="flex items-center gap-2 font-medium text-fg"><MailWarning className="size-4 text-warn" />Email isn't set up on this server</p>
                            <p className="mt-2">Reset codes are sent by email, so an administrator needs to add SMTP details under Settings, Email &amp; alerts first.</p>
                            <p className="mt-2">With terminal access you can also run:</p>
                            <code className="mt-1.5 block rounded-lg bg-surface-2 px-3 py-2 font-mono text-[12px] text-fg">php artisan flowforge:reset-password you@example.com</code>
                        </div>
                    </>
                ) : step === 'email' ? (
                    <>
                        <h1 className="mt-6 text-[24px] font-semibold tracking-tight">Reset your password</h1>
                        <p className="mt-1 text-[14px] text-fg-muted">Enter your account email. We'll send a 6-digit code to it.</p>
                        <form onSubmit={sendCode} className="mt-7 space-y-4">
                            <Field label="Email" error={request.errors.email}>
                                {(id) => <Input id={id} type="email" autoComplete="username" autoFocus required className="h-10" value={request.data.email} onChange={(e) => request.setData('email', e.target.value)} aria-invalid={!!request.errors.email} />}
                            </Field>
                            <Button type="submit" variant="primary" size="lg" className="w-full justify-center" loading={request.processing}>Send code</Button>
                        </form>
                    </>
                ) : (
                    <>
                        <span className="mt-6 flex size-11 items-center justify-center rounded-2xl bg-primary-soft text-primary"><MailCheck className="size-5" /></span>
                        <h1 className="mt-4 text-[24px] font-semibold tracking-tight">Check your email</h1>
                        <p className="mt-1 text-[14px] text-fg-muted">{flash?.success ?? 'A code is on its way.'} It went to <strong className="text-fg">{reset.data.email}</strong> and works for 15 minutes.</p>
                        <form onSubmit={(e) => { e.preventDefault(); reset.post('/reset-password'); }} className="mt-7 space-y-4">
                            <Field label="6-digit code" error={reset.errors.code}>
                                {(id) => (
                                    <Input id={id} inputMode="numeric" autoComplete="one-time-code" autoFocus required maxLength={6} pattern="\d{6}"
                                        value={reset.data.code} onChange={(e) => reset.setData('code', e.target.value.replace(/\D/g, '').slice(0, 6))}
                                        className="h-12 text-center font-mono text-[22px] tracking-[0.5em]" aria-invalid={!!reset.errors.code} placeholder="••••••" />
                                )}
                            </Field>
                            <Field label="New password" error={reset.errors.password} help="At least 10 characters.">
                                {(id) => <Input id={id} type="password" autoComplete="new-password" required className="h-10" value={reset.data.password} onChange={(e) => reset.setData('password', e.target.value)} aria-invalid={!!reset.errors.password} />}
                            </Field>
                            <Field label="Repeat new password">
                                {(id) => <Input id={id} type="password" autoComplete="new-password" required className="h-10" value={reset.data.password_confirmation} onChange={(e) => reset.setData('password_confirmation', e.target.value)} />}
                            </Field>
                            <Button type="submit" variant="primary" size="lg" className="w-full justify-center" loading={reset.processing} disabled={reset.data.code.length !== 6}>Change password</Button>
                            <div className="flex items-center justify-between text-[13px]">
                                <button type="button" className="text-fg-muted hover:text-fg" onClick={() => { setStep('email'); reset.reset(); }}>Use a different email</button>
                                <button type="button" className="font-medium text-primary hover:underline disabled:text-fg-subtle disabled:no-underline" disabled={cooldown > 0 || request.processing} onClick={sendCode}>
                                    {cooldown > 0 ? `Resend in ${cooldown}s` : 'Resend code'}
                                </button>
                            </div>
                            {request.errors.email && <p className="text-[12.5px] text-fail">{request.errors.email}</p>}
                        </form>
                    </>
                )}
                <Link href="/login" className="mt-8 inline-flex items-center gap-1.5 text-[13px] text-fg-muted hover:text-fg"><ArrowLeft className="size-3.5" />Back to sign in</Link>
            </div>
        </div>
    );
}
