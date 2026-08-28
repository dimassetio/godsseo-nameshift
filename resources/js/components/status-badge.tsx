import { Badge } from '@/components/ui/badge';

export default function StatusBadge({ status }: { status: string | null }) {
    const danger = ['FAILED', 'BLOCKED', 'CONFLICT', 'UNAVAILABLE', 'ACTION_REQUIRED', 'EXPIRED', 'CANCELLED'].includes(status ?? '');
    const success = ['SUCCEEDED', 'AVAILABLE', 'WILL_SKIP', 'SKIPPED', 'ACTIVE'].includes(status ?? '');
    return <Badge variant={danger ? 'destructive' : success ? 'default' : 'secondary'}>{(status ?? 'DRAFT').replaceAll('_', ' ')}</Badge>;
}
