import { SidebarInset } from '@/components/ui/sidebar';
import { cn } from '@/lib/utils';
import * as React from 'react';

interface AppContentProps extends React.ComponentProps<'div'> {
    variant?: 'header' | 'sidebar';
}

export function AppContent({ variant = 'header', children, className, ...props }: AppContentProps) {
    if (variant === 'sidebar') {
        return (
            <SidebarInset className={cn('w-full max-w-full min-w-0 overflow-x-hidden [&>*]:min-w-0', className)} {...props}>
                {children}
            </SidebarInset>
        );
    }

    return (
        <main
            className={cn(
                'mx-auto flex h-full w-full max-w-full min-w-0 flex-1 flex-col gap-4 overflow-x-hidden rounded-xl [&>*]:min-w-0',
                className,
            )}
            {...props}
        >
            {children}
        </main>
    );
}
