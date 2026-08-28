import HeadingSmall from '@/components/heading-small';
import StatusBadge from '@/components/status-badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { type RegistrarAccount } from '@/types';
import { Head, router, useForm } from '@inertiajs/react';
import { Eye, EyeOff, Plus } from 'lucide-react';
import { FormEvent, useEffect, useState } from 'react';

type AccountFormData = {
    provider: 'NAMECHEAP' | 'NAMECOM' | 'ZCOM';
    environment: 'SANDBOX' | 'PRODUCTION';
    label: string;
    username: string;
    api_user: string;
    client_ipv4: string;
    secret: string;
    is_active: boolean;
};
const empty: AccountFormData = {
    provider: 'NAMECHEAP',
    environment: 'SANDBOX',
    label: '',
    username: '',
    api_user: '',
    client_ipv4: '',
    secret: '',
    is_active: true,
};

export default function RegistrarAccounts({ accounts, zcomEnabled }: { accounts: RegistrarAccount[]; zcomEnabled: boolean }) {
    const [visibleAccounts, setVisibleAccounts] = useState(accounts);
    const [pollMessage, setPollMessage] = useState<string | null>(null);
    const [creating, setCreating] = useState(false);
    const hasActiveWork = visibleAccounts.some(
        (account) =>
            ['QUEUED', 'RUNNING'].includes(account.last_test_status ?? '') ||
            account.sync_runs?.some((run) => ['QUEUED', 'RUNNING'].includes(run.status)),
    );

    useEffect(() => setVisibleAccounts(accounts), [accounts]);

    useEffect(() => {
        if (!hasActiveWork) return;

        let cancelled = false;
        let timer: number | undefined;
        let attempts = 0;
        const controller = new AbortController();

        const poll = async () => {
            attempts++;
            try {
                const response = await fetch('/settings/registrar-accounts/sync-status', {
                    headers: { Accept: 'application/json' },
                    signal: controller.signal,
                });
                if (!response.ok) throw new Error('Unable to read synchronization status.');

                const payload = (await response.json()) as { accounts: RegistrarAccount[] };
                if (cancelled) return;
                setVisibleAccounts(payload.accounts);
                setPollMessage(null);

                const stillActive = payload.accounts.some(
                    (account) =>
                        ['QUEUED', 'RUNNING'].includes(account.last_test_status ?? '') ||
                        account.sync_runs?.some((run) => ['QUEUED', 'RUNNING'].includes(run.status)),
                );
                if (stillActive && attempts < 420) {
                    timer = window.setTimeout(poll, 5000);
                } else if (stillActive) {
                    setPollMessage('Automatic status updates stopped after 35 minutes. Refresh the page to check this run.');
                }
            } catch (error) {
                if (!cancelled && !(error instanceof DOMException && error.name === 'AbortError')) {
                    setPollMessage(error instanceof Error ? error.message : 'Unable to read synchronization status.');
                }
            }
        };

        timer = window.setTimeout(poll, 1000);

        return () => {
            cancelled = true;
            controller.abort();
            if (timer) window.clearTimeout(timer);
        };
    }, [hasActiveWork]);

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Settings', href: '/settings' },
                { title: 'Registrar accounts', href: '/settings/registrar-accounts' },
            ]}
        >
            <Head title="Registrar accounts" />
            <SettingsLayout>
                <div className="space-y-6">
                    <div className="flex flex-wrap items-start justify-between gap-4">
                        <HeadingSmall
                            title="Registrar accounts"
                            description="Credentials and Z.com browser sessions are encrypted at rest and are never shown again."
                        />
                        <Button onClick={() => setCreating(true)}>
                            <Plus className="size-4" />
                            Add account
                        </Button>
                    </div>
                    {pollMessage && <p className="text-sm text-amber-600 dark:text-amber-400">{pollMessage}</p>}
                    <AccountDialog
                        open={creating}
                        onOpenChange={setCreating}
                        title="Add registrar account"
                        initial={empty}
                        zcomEnabled={zcomEnabled}
                    />
                    <div className="space-y-4">
                        {visibleAccounts.map((account) => (
                            <AccountCard account={account} zcomEnabled={zcomEnabled} key={account.id} />
                        ))}
                    </div>
                </div>
            </SettingsLayout>
        </AppLayout>
    );
}

function AccountCard({ account, zcomEnabled }: { account: RegistrarAccount; zcomEnabled: boolean }) {
    const [editing, setEditing] = useState(false);
    const lastRun = account.sync_runs?.[0];
    const syncActive = lastRun && ['QUEUED', 'RUNNING'].includes(lastRun.status);
    const testActive = ['QUEUED', 'RUNNING'].includes(account.last_test_status ?? '');
    return (
        <Card>
            <CardHeader className="gap-3 sm:flex-row sm:items-start sm:justify-between">
                <div className="min-w-0">
                    <CardTitle>{account.label}</CardTitle>
                    <p className="text-muted-foreground mt-1 text-sm">
                        {account.provider} · {account.environment} · {account.domains_count ?? 0} domains
                    </p>
                </div>
                <div className="flex flex-wrap gap-2">
                    <StatusBadge status={account.is_active ? 'AVAILABLE' : 'UNAVAILABLE'} />
                    {account.last_test_status && <StatusBadge status={account.last_test_status} />}
                </div>
            </CardHeader>
            <CardContent className="space-y-4">
                <div className="grid gap-2 text-sm md:grid-cols-2">
                    <div>
                        <span className="text-muted-foreground">Username:</span> {account.username}
                    </div>
                    <div>
                        <span className="text-muted-foreground">Last sync:</span>{' '}
                        {account.last_synced_at ? new Date(account.last_synced_at).toLocaleString() : 'Never'}
                    </div>
                    <div>
                        <span className="text-muted-foreground">Credential:</span> {account.has_credentials ? 'Saved securely' : 'Missing'}
                    </div>
                    {lastRun && (
                        <div className="md:col-span-2">
                            <span className="text-muted-foreground">Latest run:</span> <StatusBadge status={lastRun.status} /> {lastRun.created_count}{' '}
                            / {lastRun.updated_count} updated / {lastRun.failed_count} failed
                        </div>
                    )}
                    {lastRun?.error_message && <p className="text-destructive md:col-span-2">{lastRun.error_message}</p>}
                    {account.last_test_message && (
                        <p
                            className={
                                account.last_test_status === 'ACTION_REQUIRED'
                                    ? 'text-amber-600 md:col-span-2 dark:text-amber-400'
                                    : 'text-muted-foreground md:col-span-2'
                            }
                        >
                            {account.last_test_message}
                        </p>
                    )}
                </div>
                <div className="flex flex-wrap gap-2">
                    <Button
                        size="sm"
                        disabled={testActive || Boolean(syncActive)}
                        onClick={() => router.post(`/settings/registrar-accounts/${account.id}/test`)}
                    >
                        {testActive ? 'Testing…' : 'Test connection'}
                    </Button>
                    <Button
                        size="sm"
                        variant="outline"
                        disabled={!account.is_active || Boolean(syncActive)}
                        onClick={() => router.post(`/settings/registrar-accounts/${account.id}/sync`)}
                    >
                        Synchronize
                    </Button>
                    <Button size="sm" variant="outline" onClick={() => setEditing(!editing)}>
                        Edit
                    </Button>
                </div>
                <AccountDialog
                    open={editing}
                    onOpenChange={setEditing}
                    title="Edit registrar account"
                    accountId={account.id}
                    hasCredential={account.has_credentials}
                    zcomEnabled={zcomEnabled}
                    initial={{
                        provider: account.provider,
                        environment: account.environment,
                        label: account.label,
                        username: account.username,
                        api_user: account.api_user ?? '',
                        client_ipv4: account.client_ipv4 ?? '',
                        secret: '',
                        is_active: account.is_active,
                    }}
                />
            </CardContent>
        </Card>
    );
}

function AccountDialog({
    open,
    onOpenChange,
    title,
    initial,
    accountId,
    hasCredential,
    zcomEnabled,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    title: string;
    initial: AccountFormData;
    accountId?: number;
    hasCredential?: boolean;
    zcomEnabled: boolean;
}) {
    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-2xl">
                <DialogHeader>
                    <DialogTitle>{title}</DialogTitle>
                    <DialogDescription>Configure the registrar account and its encrypted credential.</DialogDescription>
                </DialogHeader>
                <AccountEditor
                    initial={initial}
                    accountId={accountId}
                    hasCredential={hasCredential}
                    zcomEnabled={zcomEnabled}
                    onSaved={() => onOpenChange(false)}
                />
            </DialogContent>
        </Dialog>
    );
}

function AccountEditor({
    initial,
    accountId,
    hasCredential = false,
    zcomEnabled,
    onSaved,
}: {
    initial: AccountFormData;
    accountId?: number;
    hasCredential?: boolean;
    zcomEnabled: boolean;
    onSaved: () => void;
}) {
    const form = useForm<AccountFormData>(initial);
    const submit = (e: FormEvent) => {
        e.preventDefault();
        if (accountId) {
            form.put(`/settings/registrar-accounts/${accountId}`, {
                preserveScroll: true,
                onSuccess: onSaved,
            });
        } else {
            form.post('/settings/registrar-accounts', { preserveScroll: true, onSuccess: onSaved });
        }
    };
    return (
        <form onSubmit={submit} className="min-w-0 space-y-4">
            <div className="grid gap-4 md:grid-cols-2">
                <Field label="Label">
                    <Input value={form.data.label} onChange={(e) => form.setData('label', e.target.value)} required />
                </Field>
                <Field label="Provider">
                    <select
                        className="bg-background h-10 w-full min-w-0 rounded-md border px-3"
                        value={form.data.provider}
                        disabled={Boolean(accountId)}
                        onChange={(e) => {
                            const provider = e.target.value as AccountFormData['provider'];
                            form.setData('provider', provider);
                            if (provider === 'ZCOM') form.setData('environment', 'PRODUCTION');
                        }}
                    >
                        <option value="NAMECHEAP">Namecheap</option>
                        <option value="NAMECOM">Name.com</option>
                        {(zcomEnabled || form.data.provider === 'ZCOM') && <option value="ZCOM">Z.com</option>}
                    </select>
                </Field>
                <Field label="Environment">
                    <select
                        className="bg-background h-10 w-full min-w-0 rounded-md border px-3"
                        value={form.data.environment}
                        disabled={form.data.provider === 'ZCOM'}
                        onChange={(e) => form.setData('environment', e.target.value as AccountFormData['environment'])}
                    >
                        <option value="SANDBOX">Sandbox</option>
                        <option value="PRODUCTION">Production</option>
                    </select>
                </Field>
                <Field label={form.data.provider === 'ZCOM' ? 'Account email' : 'Account username'}>
                    <Input
                        type={form.data.provider === 'ZCOM' ? 'email' : 'text'}
                        value={form.data.username}
                        onChange={(e) => form.setData('username', e.target.value)}
                        required
                    />
                </Field>
                {form.data.provider === 'NAMECHEAP' && (
                    <>
                        <Field label="API user">
                            <Input
                                value={form.data.api_user}
                                onChange={(e) => form.setData('api_user', e.target.value)}
                                placeholder="Defaults to username"
                            />
                        </Field>
                        <Field label="Allowlisted client IPv4">
                            <Input value={form.data.client_ipv4} onChange={(e) => form.setData('client_ipv4', e.target.value)} required />
                        </Field>
                    </>
                )}
                <Field label={form.data.provider === 'NAMECHEAP' ? 'API key' : form.data.provider === 'NAMECOM' ? 'API token' : 'Password'}>
                    <SecretInput
                        name={`registrar-secret-${accountId ?? 'new'}`}
                        autoComplete="new-password"
                        value={form.data.secret}
                        onChange={(value) => form.setData('secret', value)}
                        required={!accountId}
                        placeholder={accountId && hasCredential ? 'Leave blank to keep the saved credential' : undefined}
                    />
                </Field>
                <label className="flex items-center gap-2 self-end pb-2">
                    <Checkbox checked={form.data.is_active} onCheckedChange={(checked) => form.setData('is_active', Boolean(checked))} />
                    Active
                </label>
            </div>
            {Object.values(form.errors).map((error) => (
                <p key={error} className="text-destructive text-sm">
                    {error}
                </p>
            ))}
            <Button disabled={form.processing}>{accountId ? 'Save changes' : 'Add account'}</Button>
        </form>
    );
}

function SecretInput({
    value,
    onChange,
    readOnly = false,
    required = false,
    name,
    autoComplete,
    placeholder,
}: {
    value: string;
    onChange?: (value: string) => void;
    readOnly?: boolean;
    required?: boolean;
    name?: string;
    autoComplete?: string;
    placeholder?: string;
}) {
    const [visible, setVisible] = useState(false);

    return (
        <div className="relative">
            <Input
                type={visible ? 'text' : 'password'}
                className="pr-10"
                name={name}
                autoComplete={autoComplete}
                value={value}
                onChange={(event) => onChange?.(event.target.value)}
                readOnly={readOnly}
                required={required}
                placeholder={placeholder}
            />
            <Button
                type="button"
                variant="ghost"
                size="icon"
                className="absolute top-0 right-0 h-10 w-10"
                onClick={() => setVisible((current) => !current)}
                aria-label={visible ? 'Hide credential' : 'Show credential'}
            >
                {visible ? <EyeOff className="size-4" /> : <Eye className="size-4" />}
            </Button>
        </div>
    );
}

function Field({ label, children }: { label: string; children: React.ReactNode }) {
    return (
        <div className="grid min-w-0 gap-2">
            <Label>{label}</Label>
            {children}
        </div>
    );
}
