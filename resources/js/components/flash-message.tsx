import { Alert, AlertDescription } from '@/components/ui/alert';
import { type SharedData } from '@/types';
import { usePage } from '@inertiajs/react';

export default function FlashMessage() {
    const { flash, errors } = usePage<SharedData & { errors: Record<string, string> }>().props;
    const message = flash?.success || Object.values(errors ?? {})[0];
    if (!message) return null;
    return (
        <Alert variant={flash?.success ? 'default' : 'destructive'}>
            <AlertDescription>{message}</AlertDescription>
        </Alert>
    );
}
