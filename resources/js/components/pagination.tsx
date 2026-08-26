import { type PaginationLink } from '@/types';
import { Link } from '@inertiajs/react';

export default function Pagination({ links }: { links: PaginationLink[] }) {
    return (
        <nav className="flex flex-wrap gap-1">
            {links.map((link, index) =>
                link.url ? (
                    <Link
                        key={index}
                        href={link.url}
                        preserveScroll
                        className={`rounded border px-3 py-1.5 text-sm ${link.active ? 'bg-primary text-primary-foreground' : 'hover:bg-muted'}`}
                        dangerouslySetInnerHTML={{ __html: link.label }}
                    />
                ) : (
                    <span key={index} className="rounded border px-3 py-1.5 text-sm opacity-40" dangerouslySetInnerHTML={{ __html: link.label }} />
                ),
            )}
        </nav>
    );
}
