import { Head, Link, useForm } from '@inertiajs/react';
import { LinkIcon } from 'lucide-react';
import { Button, Field, Input } from '@/Components/ui';
import { Logo } from '@/Layouts/AppLayout';

/** Joining a team: the invitee picks their name and password, then lands signed in. */
export default function AcceptInvite({ token, member }) {
    const form = useForm({ name: member?.name ?? '', password: '', password_confirmation: '' });

    return (
        <div className="flex min-h-screen items-center justify-center bg-bg px-6 py-12">
            <Head title="Join FlowForge" />
            <div className="w-full max-w-sm">
                <Logo className="size-10" />
                {!member ? (
                    <>
                        <h1 className="mt-6 text-[24px] font-semibold tracking-tight">This invite link doesn't work</h1>
                        <p className="mt-2 flex gap-2 text-[14px] text-fg-muted"><LinkIcon className="mt-0.5 size-4 shrink-0" />It was already used, or it's older than 7 days. Ask whoever invited you for a new link.</p>
                        <Link href="/login" className="mt-6 inline-block text-[13.5px] font-medium text-primary hover:underline">Go to sign in</Link>
                    </>
                ) : (
                    <>
                        <h1 className="mt-6 text-[24px] font-semibold tracking-tight">Join FlowForge</h1>
                        <p className="mt-1 text-[14px] text-fg-muted">You'll sign in as <strong className="text-fg">{member.email}</strong>. Choose a password to finish.</p>
                        <form onSubmit={(e) => { e.preventDefault(); form.post(`/invite/${token}`); }} className="mt-7 space-y-4">
                            <Field label="Your name" error={form.errors.name}>
                                {(id) => <Input id={id} autoComplete="name" required className="h-10" value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} />}
                            </Field>
                            <Field label="Password" error={form.errors.password} help="At least 10 characters.">
                                {(id) => <Input id={id} type="password" autoComplete="new-password" required autoFocus className="h-10" value={form.data.password} onChange={(e) => form.setData('password', e.target.value)} aria-invalid={!!form.errors.password} />}
                            </Field>
                            <Field label="Repeat password">
                                {(id) => <Input id={id} type="password" autoComplete="new-password" required className="h-10" value={form.data.password_confirmation} onChange={(e) => form.setData('password_confirmation', e.target.value)} />}
                            </Field>
                            <Button type="submit" variant="primary" size="lg" className="w-full justify-center" loading={form.processing}>Join and sign in</Button>
                        </form>
                    </>
                )}
            </div>
        </div>
    );
}
