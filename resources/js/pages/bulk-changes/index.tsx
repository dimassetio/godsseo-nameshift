import Pagination from '@/components/pagination';
import StatusBadge from '@/components/status-badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { type BulkChange, type Paginated } from '@/types';
import { Head, Link } from '@inertiajs/react';

export default function BulkHistory({ bulkChanges }: { bulkChanges: Paginated<BulkChange> }) {
    return (
        <AppLayout breadcrumbs={[{ title: 'Bulk changes', href: '/bulk-changes' }]}>
            <Head title="Bulk changes" />
            <div className="space-y-6 p-4 md:p-6">
                <div>
                    <h1 className="text-2xl font-semibold">Bulk change history</h1>
                    <p className="text-muted-foreground">Read-only history of previews, changes, retries, and rollbacks.</p>
                </div>
                <Card>
                    <CardHeader>
                        <CardTitle>{bulkChanges.total} operations</CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-4">
                        <div className="overflow-x-auto">
                            <table className="w-full text-sm">
                                <thead>
                                    <tr className="border-b text-left">
                                        <th className="p-3">Operation</th>
                                        <th>Target</th>
                                        <th>Progress</th>
                                        <th>Status</th>
                                        <th>Created</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {bulkChanges.data.map((bulk) => (
                                        <tr className="border-b" key={bulk.id}>
                                            <td className="p-3">
                                                <Link className="font-medium underline" href={`/bulk-changes/${bulk.id}`}>
                                                    #{bulk.id} · {bulk.type}
                                                </Link>
                                                <div className="text-muted-foreground text-xs">by {bulk.user?.name ?? 'Deleted user'}</div>
                                            </td>
                                            <td className="max-w-xs text-xs">{bulk.target_nameservers?.join(', ') ?? 'Per-item targets'}</td>
                                            <td>
                                                {bulk.succeeded_count +
                                                    bulk.skipped_count +
                                                    bulk.failed_count +
                                                    bulk.conflict_count +
                                                    bulk.cancelled_count}
                                                /{bulk.total_count}
                                            </td>
                                            <td>
                                                <StatusBadge status={bulk.status} />
                                            </td>
                                            <td>{new Date(bulk.created_at).toLocaleString()}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                        <Pagination links={bulkChanges.links} />
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
