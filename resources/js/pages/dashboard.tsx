import StatusBadge from '@/components/status-badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { type BulkChange, type SyncRun } from '@/types';
import { Head, Link } from '@inertiajs/react';

export default function Dashboard({
    metrics,
    domainsByProvider,
    recentBulkChanges,
    recentSyncRuns,
}: {
    metrics: { domains: number; accounts: number; failedItems: number };
    domainsByProvider: Record<string, number>;
    recentBulkChanges: BulkChange[];
    recentSyncRuns: SyncRun[];
}) {
    return (
        <AppLayout breadcrumbs={[{ title: 'Dashboard', href: '/dashboard' }]}>
            <Head title="Dashboard" />
            <div className="space-y-6 p-4 md:p-6">
                <div>
                    <h1 className="text-2xl font-semibold">Operations dashboard</h1>
                    <p className="text-muted-foreground">Domain inventory and recent nameserver activity.</p>
                </div>
                <div className="grid gap-4 md:grid-cols-3">
                    <Metric
                        label="Synchronized domains"
                        value={metrics.domains}
                        detail={
                            Object.entries(domainsByProvider)
                                .map(([key, value]) => `${key}: ${value}`)
                                .join(' · ') || 'No inventory'
                        }
                    />
                    <Metric label="Active accounts" value={metrics.accounts} detail="Namecheap and Name.com connections" />
                    <Metric label="Failed items" value={metrics.failedItems} detail="Items requiring attention" danger={metrics.failedItems > 0} />
                </div>
                <div className="grid gap-6 xl:grid-cols-2">
                    <Card>
                        <CardHeader className="flex-row items-center justify-between">
                            <CardTitle>Recent bulk changes</CardTitle>
                            <Link className="text-sm underline" href="/bulk-changes">
                                View all
                            </Link>
                        </CardHeader>
                        <CardContent className="space-y-3">
                            {recentBulkChanges.length ? (
                                recentBulkChanges.map((bulk) => (
                                    <Link
                                        href={`/bulk-changes/${bulk.id}`}
                                        key={bulk.id}
                                        className="hover:bg-muted flex items-center justify-between rounded border p-3"
                                    >
                                        <div>
                                            <div className="font-medium">
                                                #{bulk.id} · {bulk.type}
                                            </div>
                                            <div className="text-muted-foreground text-xs">
                                                {bulk.total_count} domains · {new Date(bulk.created_at).toLocaleString()}
                                            </div>
                                        </div>
                                        <StatusBadge status={bulk.status} />
                                    </Link>
                                ))
                            ) : (
                                <Empty />
                            )}
                        </CardContent>
                    </Card>
                    <Card>
                        <CardHeader>
                            <CardTitle>Recent synchronizations</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-3">
                            {recentSyncRuns.length ? (
                                recentSyncRuns.map((run) => (
                                    <div key={run.id} className="flex items-center justify-between rounded border p-3">
                                        <div>
                                            <div className="font-medium">{run.account?.label}</div>
                                            <div className="text-muted-foreground text-xs">
                                                +{run.created_count} created · {run.updated_count} updated
                                            </div>
                                        </div>
                                        <StatusBadge status={run.status} />
                                    </div>
                                ))
                            ) : (
                                <Empty />
                            )}
                        </CardContent>
                    </Card>
                </div>
            </div>
        </AppLayout>
    );
}

function Metric({ label, value, detail, danger = false }: { label: string; value: number; detail: string; danger?: boolean }) {
    return (
        <Card>
            <CardHeader className="pb-2">
                <CardTitle className="text-muted-foreground text-sm font-medium">{label}</CardTitle>
            </CardHeader>
            <CardContent>
                <div className={`text-3xl font-bold ${danger ? 'text-destructive' : ''}`}>{value.toLocaleString()}</div>
                <p className="text-muted-foreground mt-1 text-xs">{detail}</p>
            </CardContent>
        </Card>
    );
}
function Empty() {
    return <p className="text-muted-foreground py-8 text-center text-sm">No activity yet.</p>;
}
