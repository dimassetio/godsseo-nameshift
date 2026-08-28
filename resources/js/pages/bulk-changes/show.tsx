import Pagination from '@/components/pagination';
import StatusBadge from '@/components/status-badge';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import AppLayout from '@/layouts/app-layout';
import { type BulkChange, type BulkChangeItem, type Paginated } from '@/types';
import { Head, router } from '@inertiajs/react';
import { useEffect, useState } from 'react';

export default function BulkDetail({
    bulkChange,
    items,
    previewCounts,
    isTerminal,
    statusFilter,
}: {
    bulkChange: BulkChange;
    items: Paginated<BulkChangeItem>;
    previewCounts: { changeable: number; skipped: number; blocked: number; excluded: number };
    isTerminal: boolean;
    statusFilter: string;
}) {
    const [confirming, setConfirming] = useState(false);
    const [rollbackIds, setRollbackIds] = useState<number[]>([]);
    useEffect(() => {
        if (bulkChange.status === 'DRAFT' || isTerminal) return;
        const timer = window.setInterval(() => router.reload({ only: ['bulkChange', 'items', 'isTerminal'] }), 5000);
        return () => window.clearInterval(timer);
    }, [bulkChange.status, isTerminal]);
    const confirm = () =>
        router.post(
            `/bulk-changes/${bulkChange.id}/confirm`,
            {},
            {
                onStart: () => setConfirming(true),
                onFinish: () => setConfirming(false),
            },
        );
    const done =
        bulkChange.succeeded_count + bulkChange.failed_count + bulkChange.skipped_count + bulkChange.conflict_count + bulkChange.cancelled_count;
    const progress = bulkChange.total_count ? Math.round((done / bulkChange.total_count) * 100) : 0;
    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Bulk changes', href: '/bulk-changes' },
                { title: `#${bulkChange.id}`, href: `/bulk-changes/${bulkChange.id}` },
            ]}
        >
            <Head title={`Bulk change #${bulkChange.id}`} />
            <div className="space-y-6 p-4 md:p-6">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <div className="flex flex-wrap items-center gap-3">
                            <h1 className="text-2xl font-semibold">Bulk change #{bulkChange.id}</h1>
                            <StatusBadge status={bulkChange.status} />
                        </div>
                        <p className="text-muted-foreground">
                            {bulkChange.type}
                            {bulkChange.parent ? ` from #${bulkChange.parent.id}` : ''} · created {new Date(bulkChange.created_at).toLocaleString()}
                        </p>
                    </div>
                    <div className="flex w-full flex-wrap gap-2 sm:w-auto">
                        {bulkChange.status !== 'DRAFT' && !isTerminal && (
                            <Button variant="destructive" onClick={() => router.post(`/bulk-changes/${bulkChange.id}/cancel`)}>
                                Cancel pending work
                            </Button>
                        )}
                        {isTerminal && bulkChange.failed_count > 0 && (
                            <Button onClick={() => router.post(`/bulk-changes/${bulkChange.id}/retry`)}>Prepare retry</Button>
                        )}
                    </div>
                </div>
                {bulkChange.status === 'DRAFT' ? (
                    <PreviewSummary counts={previewCounts} />
                ) : (
                    <>
                        <div className="bg-muted h-2 overflow-hidden rounded-full">
                            <div className="bg-primary h-full transition-all" style={{ width: `${progress}%` }} />
                        </div>
                        <div className="grid grid-cols-2 gap-3 md:grid-cols-4 xl:grid-cols-8">
                            <Count label="Total" value={bulkChange.total_count} />
                            <Count label="Pending" value={bulkChange.pending_count} />
                            <Count label="Processing" value={bulkChange.processing_count} />
                            <Count label="Succeeded" value={bulkChange.succeeded_count} />
                            <Count label="Failed" value={bulkChange.failed_count} />
                            <Count label="Skipped" value={bulkChange.skipped_count} />
                            <Count label="Conflicts" value={bulkChange.conflict_count} />
                            <Count label="Cancelled" value={bulkChange.cancelled_count} />
                        </div>
                    </>
                )}
                <Card>
                    <CardHeader className="gap-3 sm:flex-row sm:items-center sm:justify-between">
                        <CardTitle>Domains</CardTitle>
                        {bulkChange.status !== 'DRAFT' && (
                            <select
                                className="bg-background h-9 w-full min-w-0 rounded border px-2 text-sm sm:w-auto"
                                value={statusFilter}
                                onChange={(e) =>
                                    router.get(`/bulk-changes/${bulkChange.id}`, { status: e.target.value || undefined }, { preserveState: true })
                                }
                            >
                                <option value="">All statuses</option>
                                {['PENDING', 'PROCESSING', 'RETRYING', 'SUCCEEDED', 'SKIPPED', 'CONFLICT', 'FAILED', 'CANCELLED'].map((status) => (
                                    <option key={status}>{status}</option>
                                ))}
                            </select>
                        )}
                    </CardHeader>
                    <CardContent className="space-y-4">
                        <div className="relative w-full max-w-full overflow-x-auto overscroll-x-contain">
                            <table className="w-full min-w-[1100px] text-sm">
                                <thead>
                                    <tr className="border-b text-left">
                                        <th className="p-3"></th>
                                        <th>Domain</th>
                                        <th>Provider</th>
                                        <th>Before</th>
                                        <th>Target</th>
                                        <th>Result</th>
                                        <th>Attempts / error</th>
                                        <th></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {items.data.map((item) => (
                                        <tr key={item.id} className={`border-b ${item.excluded_at ? 'opacity-40' : ''}`}>
                                            <td className="p-3">
                                                {item.status === 'SUCCEEDED' && item.old_nameservers && (
                                                    <Checkbox
                                                        checked={rollbackIds.includes(item.id)}
                                                        onCheckedChange={() =>
                                                            setRollbackIds((ids) =>
                                                                ids.includes(item.id) ? ids.filter((id) => id !== item.id) : [...ids, item.id],
                                                            )
                                                        }
                                                    />
                                                )}
                                            </td>
                                            <td className="font-medium">{item.domain.name}</td>
                                            <td>
                                                {item.domain.account.provider}
                                                <div className="text-muted-foreground text-xs">{item.domain.account.label}</div>
                                            </td>
                                            <td className="max-w-xs py-3 text-xs">{item.preview_nameservers.join(', ') || 'Unknown'}</td>
                                            <td className="max-w-xs text-xs">{item.target_nameservers.join(', ')}</td>
                                            <td>
                                                <StatusBadge status={item.status ?? item.preview_disposition} />
                                            </td>
                                            <td className="max-w-xs text-xs">
                                                {item.attempt_count}
                                                {item.error_message && (
                                                    <div className="text-destructive mt-1">
                                                        {item.error_category}: {item.error_message}
                                                    </div>
                                                )}
                                            </td>
                                            <td>
                                                {bulkChange.status === 'DRAFT' && item.preview_disposition === 'BLOCKED' && !item.excluded_at && (
                                                    <Button
                                                        size="sm"
                                                        variant="outline"
                                                        onClick={() => router.delete(`/bulk-changes/${bulkChange.id}/items/${item.id}`)}
                                                    >
                                                        Exclude
                                                    </Button>
                                                )}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                        <Pagination links={items.links} />
                        {rollbackIds.length > 0 && (
                            <Button
                                variant="outline"
                                onClick={() => router.post(`/bulk-changes/${bulkChange.id}/rollback`, { item_ids: rollbackIds })}
                            >
                                Prepare rollback for {rollbackIds.length}
                            </Button>
                        )}
                    </CardContent>
                </Card>
                {bulkChange.status === 'DRAFT' && (
                    <Card>
                        <CardHeader>
                            <CardTitle>Confirm bulk change</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <Alert className="mb-4">
                                <AlertTitle>Review before continuing</AlertTitle>
                                <AlertDescription>
                                    This operation changes authoritative nameservers. Provider acceptance does not guarantee DNS propagation.
                                </AlertDescription>
                            </Alert>
                            <Button onClick={confirm} disabled={confirming || previewCounts.blocked > 0 || previewCounts.changeable === 0}>
                                Confirm and queue {previewCounts.changeable} changes
                            </Button>
                        </CardContent>
                    </Card>
                )}
            </div>
        </AppLayout>
    );
}

function PreviewSummary({ counts }: { counts: { changeable: number; skipped: number; blocked: number; excluded: number } }) {
    return (
        <div className="grid grid-cols-2 gap-3 md:grid-cols-4">
            <Count label="Will change" value={counts.changeable} />
            <Count label="Will skip" value={counts.skipped} />
            <Count label="Blocked" value={counts.blocked} />
            <Count label="Excluded" value={counts.excluded} />
        </div>
    );
}
function Count({ label, value }: { label: string; value: number }) {
    return (
        <Card>
            <CardContent className="p-4">
                <div className="text-2xl font-semibold">{value}</div>
                <div className="text-muted-foreground text-xs">{label}</div>
            </CardContent>
        </Card>
    );
}
