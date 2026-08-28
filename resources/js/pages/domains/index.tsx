import InputError from '@/components/input-error';
import Pagination from '@/components/pagination';
import StatusBadge from '@/components/status-badge';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Dialog, DialogClose, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { type DomainMutation, type ManagedDomain, type Paginated, type RegistrarAccount } from '@/types';
import { Head, router, useForm } from '@inertiajs/react';
import { Download, Save, SlidersHorizontal, Upload } from 'lucide-react';
import { FormEvent, useEffect, useRef, useState } from 'react';

const domainColumns = [
    { id: 'domain', label: 'Domain' },
    { id: 'tld', label: 'TLD' },
    { id: 'registrar', label: 'Registrar' },
    { id: 'renewal_price', label: 'Renewal $' },
    { id: 'registered_at', label: 'Created' },
    { id: 'expires_at', label: 'Expired date' },
    { id: 'remaining_days', label: 'Sisa Hari' },
    { id: 'status', label: 'Status Registrar' },
    { id: 'is_locked', label: 'Locked' },
    { id: 'privacy_enabled', label: 'Privacy' },
    { id: 'auto_renew', label: 'Auto Renew' },
    { id: 'nameserver_1', label: 'Nameserver 1' },
    { id: 'nameserver_2', label: 'Nameserver 2' },
    { id: 'actions', label: 'Actions' },
] as const;

type DomainColumn = (typeof domainColumns)[number]['id'];
type DomainFilters = { search?: string; account?: number; status?: string };

const allDomainColumns = domainColumns.map((column) => column.id);
const domainColumnStorageKey = 'domains:visible-columns';

export default function Domains({
    domains,
    filters,
    accounts,
    registrarStatuses,
}: {
    domains: Paginated<ManagedDomain>;
    filters: DomainFilters;
    accounts: RegistrarAccount[];
    registrarStatuses: string[];
}) {
    const [search, setSearch] = useState(filters.search ?? '');
    const [visibleColumns, setVisibleColumns] = useState<DomainColumn[]>(() => {
        if (typeof window === 'undefined') return allDomainColumns;

        try {
            const stored = JSON.parse(localStorage.getItem(domainColumnStorageKey) ?? '') as unknown;
            if (!Array.isArray(stored)) return allDomainColumns;

            return allDomainColumns.filter((column) => stored.includes(column));
        } catch {
            return allDomainColumns;
        }
    });
    const [columnDialogOpen, setColumnDialogOpen] = useState(false);
    const [draftColumns, setDraftColumns] = useState<DomainColumn[]>(visibleColumns);
    const visible = (column: DomainColumn) => visibleColumns.includes(column);
    const openColumnDialog = () => {
        setDraftColumns(visibleColumns);
        setColumnDialogOpen(true);
    };
    const saveColumns = () => {
        const orderedColumns = allDomainColumns.filter((column) => draftColumns.includes(column));
        setVisibleColumns(orderedColumns);
        localStorage.setItem(domainColumnStorageKey, JSON.stringify(orderedColumns));
        setColumnDialogOpen(false);
    };
    const applyFilters = (event: FormEvent) => {
        event.preventDefault();
        router.get('/domains', { ...filters, search }, { preserveState: true });
    };

    return (
        <AppLayout breadcrumbs={[{ title: 'Domains', href: '/domains' }]}>
            <Head title="Domains" />
            <div className="space-y-6 p-4 md:p-6">
                <div className="flex flex-wrap items-end justify-between gap-4">
                    <div>
                        <h1 className="text-2xl font-semibold">Domain inventory</h1>
                        <p className="text-muted-foreground">Edit one domain inline or upload Excel for a bulk update.</p>
                    </div>
                    <div className="flex w-full flex-col gap-2 sm:w-auto sm:flex-row sm:flex-wrap">
                        <Button variant="outline" onClick={openColumnDialog}>
                            <SlidersHorizontal className="mr-2 size-4" /> Configure columns
                        </Button>
                        <Button onClick={() => router.post('/settings/registrar-accounts/sync-all')}>Synchronize all</Button>
                    </div>
                </div>

                <BulkUpload filters={filters} />

                <Card>
                    <CardContent className="pt-6">
                        <form onSubmit={applyFilters} className="grid gap-3 md:grid-cols-[1fr_220px_180px_auto]">
                            <Input value={search} onChange={(event) => setSearch(event.target.value)} placeholder="Search domain name" />
                            <select
                                className="bg-background h-10 w-full min-w-0 rounded-md border px-3 text-sm"
                                value={filters.account ?? ''}
                                onChange={(event) => router.get('/domains', { ...filters, account: event.target.value || undefined, search })}
                            >
                                <option value="">All accounts</option>
                                {accounts.map((account) => (
                                    <option key={account.id} value={account.id}>
                                        {account.label}
                                    </option>
                                ))}
                            </select>
                            <select
                                className="bg-background h-10 w-full min-w-0 rounded-md border px-3 text-sm"
                                value={filters.status ?? ''}
                                onChange={(event) => router.get('/domains', { ...filters, status: event.target.value || undefined, search })}
                            >
                                <option value="">All registrar statuses</option>
                                {registrarStatuses.map((status) => (
                                    <option key={status} value={status}>
                                        {status.replaceAll('_', ' ')}
                                    </option>
                                ))}
                            </select>
                            <Button type="submit" variant="outline">
                                Search
                            </Button>
                        </form>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>{domains.total.toLocaleString()} domains</CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-4">
                        <div className="relative w-full max-w-full overflow-x-auto overscroll-x-contain">
                            <table
                                className={
                                    visibleColumns.length > 10
                                        ? 'w-full min-w-[1900px] text-sm'
                                        : visibleColumns.length > 5
                                          ? 'w-full min-w-[1100px] text-sm'
                                          : 'w-full min-w-[640px] text-sm'
                                }
                            >
                                <thead>
                                    <tr className="border-b text-left">
                                        {domainColumns
                                            .filter((column) => visible(column.id))
                                            .map((column) => (
                                                <th
                                                    key={column.id}
                                                    className={
                                                        column.id === 'domain' ? 'p-3' : column.id.startsWith('nameserver') ? 'w-[230px]' : 'pr-4'
                                                    }
                                                >
                                                    {column.label}
                                                </th>
                                            ))}
                                    </tr>
                                </thead>
                                <tbody>
                                    {domains.data.map((domain) => (
                                        <DomainRow domain={domain} visibleColumns={visibleColumns} key={domain.id} />
                                    ))}
                                </tbody>
                            </table>
                        </div>
                        <Pagination links={domains.links} />
                    </CardContent>
                </Card>
                <Dialog open={columnDialogOpen} onOpenChange={setColumnDialogOpen}>
                    <DialogContent>
                        <DialogHeader>
                            <DialogTitle>Configure domain columns</DialogTitle>
                            <DialogDescription>
                                Select the columns shown in the domain inventory. Your selection is saved in this browser.
                            </DialogDescription>
                        </DialogHeader>
                        <div className="grid gap-3 sm:grid-cols-2">
                            {domainColumns.map((column) => (
                                <label key={column.id} className="flex cursor-pointer items-center gap-3 rounded-md border p-3 text-sm">
                                    <Checkbox
                                        checked={draftColumns.includes(column.id)}
                                        onCheckedChange={(checked) =>
                                            setDraftColumns((current) =>
                                                checked ? [...current, column.id] : current.filter((item) => item !== column.id),
                                            )
                                        }
                                    />
                                    {column.label}
                                </label>
                            ))}
                        </div>
                        <DialogFooter>
                            <Button variant="ghost" onClick={() => setDraftColumns(allDomainColumns)}>
                                Show all
                            </Button>
                            <DialogClose asChild>
                                <Button variant="outline">Cancel</Button>
                            </DialogClose>
                            <Button onClick={saveColumns}>Save columns</Button>
                        </DialogFooter>
                    </DialogContent>
                </Dialog>
            </div>
        </AppLayout>
    );
}

function BulkUpload({ filters }: { filters: DomainFilters }) {
    const form = useForm<{ file: File | null }>({ file: null });
    const inputRef = useRef<HTMLInputElement>(null);
    const templateParameters = new URLSearchParams();

    if (filters.search) templateParameters.set('search', filters.search);
    if (filters.account) templateParameters.set('account', String(filters.account));
    if (filters.status) templateParameters.set('status', filters.status);

    const templateUrl = `/bulk-changes/template${templateParameters.size ? `?${templateParameters.toString()}` : ''}`;
    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post('/bulk-changes/import', {
            forceFormData: true,
            onSuccess: () => {
                form.reset();
                if (inputRef.current) inputRef.current.value = '';
            },
        });
    };

    return (
        <Card>
            <CardHeader>
                <CardTitle>Bulk update from Excel</CardTitle>
            </CardHeader>
            <CardContent className="space-y-4">
                <p className="text-muted-foreground text-sm">
                    The template includes up to 100 domains matching the active filters, with their current nameservers.
                </p>
                <Button asChild variant="outline">
                    <a href={templateUrl}>
                        <Download className="mr-2 size-4" /> Download Excel template
                    </a>
                </Button>
                <form onSubmit={submit} className="flex flex-col gap-3 sm:flex-row sm:items-start">
                    <div className="min-w-0 flex-1">
                        <Input
                            ref={inputRef}
                            type="file"
                            accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"
                            onChange={(event) => form.setData('file', event.target.files?.[0] ?? null)}
                        />
                        <InputError message={form.errors.file} className="mt-2" />
                    </div>
                    <Button disabled={!form.data.file || form.processing}>
                        <Upload className="mr-2 size-4" /> Upload and review
                    </Button>
                </form>
            </CardContent>
        </Card>
    );
}

function DomainRow({ domain, visibleColumns }: { domain: ManagedDomain; visibleColumns: DomainColumn[] }) {
    const initialNameservers = [domain.nameservers[0] ?? '', domain.nameservers[1] ?? ''];
    const form = useForm<{ nameservers: string[] }>({ nameservers: initialNameservers });
    const [confirmOpen, setConfirmOpen] = useState(false);
    const [mutation, setMutation] = useState<DomainMutation | null>(domain.latest_bulk_item ?? null);
    const [queueWarning, setQueueWarning] = useState<string | null>(null);
    const available = domain.inventory_status === 'AVAILABLE' && domain.account.is_active;
    const mutationActive = Boolean(mutation?.status && ['PENDING', 'PROCESSING', 'RETRYING'].includes(mutation.status));
    const changed = form.data.nameservers.map(clean).join('|') !== initialNameservers.map(clean).join('|');
    const visible = (column: DomainColumn) => visibleColumns.includes(column);

    useEffect(() => {
        setMutation(domain.latest_bulk_item ?? null);
    }, [domain.latest_bulk_item]);

    useEffect(() => {
        if (mutationActive) return;
        form.setData('nameservers', initialNameservers);
        // The domain cache is refreshed after a terminal mutation result.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [domain.nameservers.join('|'), mutationActive]);

    useEffect(() => {
        if (!mutationActive) return;

        let cancelled = false;
        let attempts = 0;
        let timer: number | undefined;
        const poll = async () => {
            attempts++;
            try {
                const response = await fetch(`/domains/${domain.id}/mutation-status`, { headers: { Accept: 'application/json' } });
                if (!response.ok) throw new Error('Unable to read the nameserver update status.');
                const payload = (await response.json()) as { mutation: DomainMutation | null };
                if (cancelled) return;
                setMutation(payload.mutation);
                const stillActive = Boolean(payload.mutation?.status && ['PENDING', 'PROCESSING', 'RETRYING'].includes(payload.mutation.status));
                if (!stillActive) {
                    setQueueWarning(null);
                    router.reload({ only: ['domains'] });
                    return;
                }
                if (attempts >= 5 && payload.mutation?.status === 'PENDING') {
                    setQueueWarning('Still waiting for the queue worker. Make sure composer run dev or queue:work is running.');
                }
                if (attempts < 120) timer = window.setTimeout(poll, 3000);
                else setQueueWarning('Status polling stopped after 6 minutes. Refresh the page to check the result.');
            } catch (error) {
                if (!cancelled) setQueueWarning(error instanceof Error ? error.message : 'Unable to read update status.');
            }
        };
        timer = window.setTimeout(poll, 1000);

        return () => {
            cancelled = true;
            if (timer) window.clearTimeout(timer);
        };
    }, [domain.id, mutationActive]);

    const setNameserver = (index: number, value: string) => {
        if (mutation && !mutationActive) setMutation(null);
        form.setData(
            'nameservers',
            form.data.nameservers.map((current, currentIndex) => (currentIndex === index ? value : current)),
        );
    };
    const requestConfirmation = (event: FormEvent) => {
        event.preventDefault();
        if (available && changed && form.data.nameservers.every((value) => value.trim() !== '')) setConfirmOpen(true);
    };
    const confirm = () =>
        form.post(`/domains/${domain.id}/nameservers`, {
            preserveScroll: true,
            onSuccess: () => {
                setConfirmOpen(false);
                setQueueWarning(null);
            },
        });

    return (
        <>
            <tr className="border-b align-top">
                {visible('domain') && (
                    <td className="p-3">
                        <div className="font-medium">{domain.name}</div>
                        <div className="text-muted-foreground mt-1 text-xs">
                            Synced {domain.last_synced_at ? new Date(domain.last_synced_at).toLocaleString() : 'never'}
                        </div>
                    </td>
                )}
                {visible('tld') && <td className="py-3 pr-4 font-medium">{domain.tld ? `.${domain.tld}` : '—'}</td>}
                {visible('registrar') && (
                    <td className="py-3 pr-4">
                        {domain.account.label}
                        <div className="text-muted-foreground text-xs">{domain.account.provider}</div>
                    </td>
                )}
                {visible('renewal_price') && <td className="py-3 pr-4 tabular-nums">{formatRenewalPrice(domain.renewal_price)}</td>}
                {visible('registered_at') && <td className="py-3 pr-4 whitespace-nowrap">{formatDate(domain.registered_at)}</td>}
                {visible('expires_at') && <td className="py-3 pr-4 whitespace-nowrap">{formatDate(domain.expires_at)}</td>}
                {visible('remaining_days') && (
                    <td className="py-3 pr-4">
                        <RemainingDays remainingDays={domain.remaining_days} />
                    </td>
                )}
                {visible('status') && (
                    <td className="py-3 pr-4">
                        {domain.remote_status ? <StatusBadge status={domain.remote_status} /> : <span className="text-muted-foreground">—</span>}
                    </td>
                )}
                {visible('is_locked') && (
                    <td className="py-3 pr-4">
                        <BooleanBadge value={domain.is_locked} />
                    </td>
                )}
                {visible('privacy_enabled') && (
                    <td className="py-3 pr-4">
                        <BooleanBadge value={domain.privacy_enabled} />
                    </td>
                )}
                {visible('auto_renew') && (
                    <td className="py-3 pr-4">
                        <BooleanBadge value={domain.auto_renew} />
                    </td>
                )}
                {visible('nameserver_1') && (
                    <td className="py-3 pr-2">
                        <Input
                            aria-label={`${domain.name} nameserver 1`}
                            value={form.data.nameservers[0]}
                            disabled={!available || mutationActive || form.processing}
                            onChange={(event) => setNameserver(0, event.target.value)}
                        />
                    </td>
                )}
                {visible('nameserver_2') && (
                    <td className="py-3 pr-2">
                        <Input
                            aria-label={`${domain.name} nameserver 2`}
                            value={form.data.nameservers[1]}
                            disabled={!available || mutationActive || form.processing}
                            onChange={(event) => setNameserver(1, event.target.value)}
                        />
                        <InputError message={form.errors.nameservers} className="mt-1" />
                    </td>
                )}
                {visible('actions') && (
                    <td className="py-3">
                        {mutationActive && mutation?.status ? (
                            <StatusBadge status={mutation.status} />
                        ) : (
                            <form onSubmit={requestConfirmation}>
                                <Button size="sm" disabled={!available || !changed || form.processing}>
                                    <Save className="mr-2 size-4" /> Save
                                </Button>
                            </form>
                        )}
                        <Dialog open={confirmOpen} onOpenChange={setConfirmOpen}>
                            <DialogContent>
                                <DialogHeader>
                                    <DialogTitle>Confirm nameserver change</DialogTitle>
                                    <DialogDescription>Review the current and new nameservers for {domain.name}.</DialogDescription>
                                </DialogHeader>
                                <div className="grid gap-4 text-sm sm:grid-cols-2">
                                    <NameserverPanel label="Before" nameservers={initialNameservers} />
                                    <NameserverPanel label="After" nameservers={form.data.nameservers.map(clean)} highlighted />
                                </div>
                                <InputError message={form.errors.nameservers} />
                                <DialogFooter>
                                    <DialogClose asChild>
                                        <Button variant="outline">Cancel</Button>
                                    </DialogClose>
                                    <Button onClick={confirm} disabled={form.processing}>
                                        Confirm change
                                    </Button>
                                </DialogFooter>
                            </DialogContent>
                        </Dialog>
                    </td>
                )}
            </tr>
            {(mutation?.error_message || queueWarning) && (
                <tr className="border-b">
                    <td colSpan={Math.max(visibleColumns.length, 1)} className="px-3 pb-3">
                        <div
                            className={
                                mutation?.error_message
                                    ? 'border-destructive/30 bg-destructive/10 text-destructive rounded-md border p-3 text-sm'
                                    : 'rounded-md border border-amber-500/30 bg-amber-500/10 p-3 text-sm text-amber-700 dark:text-amber-300'
                            }
                        >
                            {mutation?.error_message ? (
                                <>
                                    <strong>{mutation.error_category ?? mutation.status}:</strong> {mutation.error_message}
                                    {mutation.error_category === 'CONFLICT' && (
                                        <span> The fields were refreshed with the live nameservers from the registrar.</span>
                                    )}
                                    {mutation.bulk_change && (
                                        <a className="ml-2 underline" href={`/bulk-changes/${mutation.bulk_change.id}`}>
                                            View details
                                        </a>
                                    )}
                                </>
                            ) : (
                                queueWarning
                            )}
                        </div>
                    </td>
                </tr>
            )}
        </>
    );
}

function BooleanBadge({ value }: { value: boolean | null }) {
    if (value === null) return <span className="text-muted-foreground">—</span>;

    return <Badge variant={value ? 'default' : 'secondary'}>{value ? 'Yes' : 'No'}</Badge>;
}

function RemainingDays({ remainingDays }: { remainingDays: number | null }) {
    if (remainingDays === null) return <span className="text-muted-foreground">—</span>;

    return (
        <span
            className={
                remainingDays < 0
                    ? 'text-destructive font-medium'
                    : remainingDays <= 30
                      ? 'font-medium text-amber-600 dark:text-amber-400'
                      : 'tabular-nums'
            }
        >
            {remainingDays < 0 ? `${Math.abs(remainingDays)} hari lewat` : `${remainingDays} hari`}
        </span>
    );
}

function formatDate(value: string | null): string {
    if (!value) return '—';

    const date = new Date(value);

    return Number.isNaN(date.getTime()) ? '—' : date.toLocaleDateString();
}

function formatRenewalPrice(value: string | null): string {
    if (value === null) return '—';

    const price = Number(value);

    return Number.isNaN(price) ? '—' : new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD' }).format(price);
}

function NameserverPanel({ label, nameservers, highlighted = false }: { label: string; nameservers: string[]; highlighted?: boolean }) {
    return (
        <div className={highlighted ? 'border-primary/40 bg-primary/5 rounded-lg border p-4' : 'bg-muted/40 rounded-lg border p-4'}>
            <div className="text-muted-foreground mb-2 text-xs font-medium tracking-wide uppercase">{label}</div>
            <div className="space-y-1 font-mono text-xs">
                {nameservers.map((nameserver, index) => (
                    <div key={index}>{nameserver || '—'}</div>
                ))}
            </div>
        </div>
    );
}

function clean(value: string): string {
    return value.trim().toLowerCase().replace(/\.$/, '');
}
