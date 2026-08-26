import { Badge } from '@/components/ui/badge';

export default function StatusBadge({ status }: { status: string | null }) {
    const danger = ['FAILED', 'BLOCKED', 'CONFLICT', 'UNAVAILABLE'].includes(status ?? '');
    const success = ['SUCCEEDED', 'AVAILABLE', 'WILL_SKIP', 'SKIPPED'].includes(status ?? '');
    return <Badge variant={danger ? 'destructive' : success ? 'default' : 'secondary'}>{(status ?? 'DRAFT').replaceAll('_', ' ')}</Badge>;
}
