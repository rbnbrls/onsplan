import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Head, useForm } from '@inertiajs/react';
import { Lock } from 'lucide-react';

// Password gate in front of a guest-shared entity. No account: whoever has the link enters the shared
// password to continue. On success the server remembers it in the session and redirects to the entity.
export default function GuestLocked({ token }: { token: string }) {
    const form = useForm({ password: '' });
    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        form.post(`/g/${token}/unlock`);
    };

    return (
        <div className="bg-background flex min-h-screen items-center justify-center p-4">
            <Head title="Enter password · onsplan" />
            <form onSubmit={submit} className="bg-surface-card w-full max-w-sm rounded-2xl border p-6 text-center">
                <div className="flex items-center justify-center gap-2 pb-2">
                    <span className="bg-tile text-tile-foreground flex size-7 items-center justify-center rounded-lg text-xs font-bold">FW</span>
                    <span className="text-muted-foreground text-sm">onsplan</span>
                </div>
                <span className="bg-accent-subtle text-primary mx-auto mt-2 flex size-14 items-center justify-center rounded-full">
                    <Lock className="size-6" />
                </span>
                <h1 className="font-heading text-primary mt-4 text-2xl">This link is protected</h1>
                <p className="text-muted-foreground mt-1 text-sm">Enter the password to continue.</p>

                <div className="mt-5 text-left">
                    <Input
                        type="password"
                        autoFocus
                        value={form.data.password}
                        onChange={(e) => form.setData('password', e.target.value)}
                        placeholder="Password"
                    />
                    <InputError message={form.errors.password} className="mt-1.5" />
                </div>

                <Button type="submit" disabled={form.processing || !form.data.password} className="mt-4 w-full">
                    {form.processing ? 'Checking…' : 'Continue'}
                </Button>
            </form>
        </div>
    );
}
