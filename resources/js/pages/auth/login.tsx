import { Head, useForm } from '@inertiajs/react';
import { LoaderCircle, Sparkles } from 'lucide-react';
import { FormEventHandler } from 'react';

import InputError from '@/components/input-error';
import SocialButtons from '@/components/social-buttons';
import TextLink from '@/components/text-link';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AuthLayout from '@/layouts/auth-layout';
import { useT } from '@/lib/i18n';

interface LoginForm {
    email: string;
    password: string;
    remember: boolean;
}

interface LoginProps {
    status?: string;
    canResetPassword: boolean;
    canDemo: boolean;
    // Set when a Google/Apple sign-in found an existing account: they confirm it with its password.
    email?: string | null;
}

export default function Login({ status, canResetPassword, canDemo, email }: LoginProps) {
    const t = useT();
    const { data, setData, post, processing, errors, reset } = useForm<LoginForm>({
        email: email ?? '',
        password: '',
        remember: false,
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('login'), {
            onFinish: () => reset('password'),
        });
    };

    return (
        <AuthLayout title={t('auth.welcome_back')} description={t('auth.signin_desc')}>
            <Head title={t('auth.log_in')} />

            <SocialButtons />

            <form className="flex flex-col gap-6" onSubmit={submit}>
                <div className="grid gap-6">
                    <div className="grid gap-2">
                        <Label htmlFor="email">{t('auth.email_address')}</Label>
                        <Input
                            id="email"
                            type="email"
                            required
                            autoFocus
                            tabIndex={1}
                            autoComplete="email"
                            value={data.email}
                            onChange={(e) => setData('email', e.target.value)}
                            placeholder={t('auth.email_placeholder')}
                        />
                        <InputError message={errors.email} />
                    </div>

                    <div className="grid gap-2">
                        <div className="flex items-center">
                            <Label htmlFor="password">{t('auth.password')}</Label>
                            {canResetPassword && (
                                <TextLink href={route('password.request')} className="ml-auto text-sm" tabIndex={5}>
                                    {t('auth.forgot_password')}
                                </TextLink>
                            )}
                        </div>
                        <Input
                            id="password"
                            type="password"
                            required
                            tabIndex={2}
                            autoComplete="current-password"
                            value={data.password}
                            onChange={(e) => setData('password', e.target.value)}
                            placeholder={t('auth.password')}
                        />
                        <InputError message={errors.password} />
                    </div>

                    <div className="flex items-center space-x-3">
                        <Checkbox id="remember" name="remember" tabIndex={3} />
                        <Label htmlFor="remember">{t('auth.remember_me')}</Label>
                    </div>

                    <Button type="submit" className="mt-4 w-full" tabIndex={4} disabled={processing}>
                        {processing && <LoaderCircle className="h-4 w-4 animate-spin" />}
                        {t('auth.log_in')}
                    </Button>
                </div>

                <div className="text-muted-foreground text-center text-sm">
                    {t('auth.new_to_onsplan')}{' '}
                    <TextLink href={route('register')} tabIndex={5}>
                        {t('auth.create_an_account')}
                    </TextLink>
                </div>
            </form>

            {/* Public demo: a plain link (full page load) so the server can establish the session. */}
            {canDemo && (
                <div className="mt-6 flex flex-col items-center gap-2 border-t pt-6">
                    <Button asChild variant="outline" className="w-full">
                        <a href={route('demo.enter')} tabIndex={6}>
                            <Sparkles className="h-4 w-4" />
                            {t('auth.try_demo')}
                        </a>
                    </Button>
                    <p className="text-muted-foreground text-center text-xs">{t('auth.try_demo_hint')}</p>
                </div>
            )}

            {status && <div className="mb-4 text-center text-sm font-medium text-green-600">{status}</div>}
        </AuthLayout>
    );
}
