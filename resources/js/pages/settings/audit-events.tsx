import Pagination from '@/components/pagination';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { type AuditEvent, type Paginated } from '@/types';
import { Head, router } from '@inertiajs/react';

export default function AuditEvents({
    events,
    eventFilter,
    eventNames,
}: {
    events: Paginated<AuditEvent>;
    eventFilter: string;
    eventNames: string[];
}) {
    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Settings', href: '/settings' },
                { title: 'Audit history', href: '/settings/audit-events' },
            ]}
        >
            <Head title="Audit history" />
            <SettingsLayout>
                <div className="space-y-6">
                    <div>
                        <h2 className="text-xl font-semibold">Audit history</h2>
                        <p className="text-muted-foreground text-sm">
                            Read-only security and operations events. Secrets are redacted before storage.
                        </p>
                    </div>
                    <Card>
                        <CardHeader className="flex-row items-center justify-between">
                            <CardTitle>{events.total} events</CardTitle>
                            <select
                                className="bg-background h-9 rounded border px-2 text-sm"
                                value={eventFilter}
                                onChange={(e) =>
                                    router.get('/settings/audit-events', { event: e.target.value || undefined }, { preserveState: true })
                                }
                            >
                                <option value="">All events</option>
                                {eventNames.map((event) => (
                                    <option key={event}>{event}</option>
                                ))}
                            </select>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            <div className="overflow-x-auto">
                                <table className="w-full text-sm">
                                    <thead>
                                        <tr className="border-b text-left">
                                            <th className="p-3">Time</th>
                                            <th>Event</th>
                                            <th>Actor</th>
                                            <th>Subject</th>
                                            <th>Safe metadata</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {events.data.map((event) => (
                                            <tr className="border-b align-top" key={event.id}>
                                                <td className="p-3 whitespace-nowrap">{new Date(event.created_at).toLocaleString()}</td>
                                                <td className="font-medium">{event.event}</td>
                                                <td>{event.user?.name ?? 'System'}</td>
                                                <td className="text-xs">
                                                    {event.subject_type ? `${event.subject_type.split('\\').pop()} #${event.subject_id}` : '—'}
                                                </td>
                                                <td>
                                                    <pre className="max-w-lg overflow-auto text-xs whitespace-pre-wrap">
                                                        {event.metadata ? JSON.stringify(event.metadata, null, 2) : '—'}
                                                    </pre>
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                            <Pagination links={events.links} />
                        </CardContent>
                    </Card>
                </div>
            </SettingsLayout>
        </AppLayout>
    );
}
