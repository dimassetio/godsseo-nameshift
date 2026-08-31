import { LucideIcon } from 'lucide-react';

export interface Auth {
    user: User;
}

export interface BreadcrumbItem {
    title: string;
    href: string;
}

export interface NavGroup {
    title: string;
    items: NavItem[];
}

export interface NavItem {
    title: string;
    url: string;
    icon?: LucideIcon | null;
    isActive?: boolean;
}

export interface SharedData {
    name: string;
    quote: { message: string; author: string };
    auth: Auth;
    flash: { success?: string };
    [key: string]: unknown;
}

export interface PaginationLink {
    url: string | null;
    label: string;
    active: boolean;
}
export interface Paginated<T> {
    data: T[];
    links: PaginationLink[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    from: number | null;
    to: number | null;
}

export interface RegistrarAccount {
    id: number;
    provider: 'NAMECHEAP' | 'NAMECOM' | 'ZCOM';
    environment: 'SANDBOX' | 'PRODUCTION';
    label: string;
    username: string;
    api_user: string | null;
    client_ipv4: string | null;
    has_credentials?: boolean;
    is_active: boolean;
    last_test_status: string | null;
    last_test_message: string | null;
    last_tested_at: string | null;
    last_synced_at: string | null;
    domains_count?: number;
    sync_runs?: SyncRun[];
}
export interface SyncRun {
    id: number;
    status: string;
    created_count: number;
    updated_count: number;
    unchanged_count: number;
    failed_count: number;
    error_message: string | null;
    created_at: string;
    account?: RegistrarAccount;
}
export interface ManagedDomain {
    id: number;
    name: string;
    tld: string | null;
    renewal_price: string | null;
    registered_at: string | null;
    expires_at: string | null;
    remaining_days: number | null;
    is_locked: boolean | null;
    privacy_enabled: boolean | null;
    auto_renew: boolean | null;
    nameservers: string[];
    inventory_status: string;
    remote_status: string | null;
    last_synced_at: string | null;
    account: RegistrarAccount;
    latest_bulk_item?: DomainMutation | null;
}
export interface DomainMutation {
    id: number;
    status: string | null;
    target_nameservers: string[];
    error_category: string | null;
    error_message: string | null;
    attempt_count: number;
    created_at: string;
    bulk_change?: Pick<BulkChange, 'id' | 'status'>;
}
export interface NameserverPreset {
    id: number;
    name: string;
    nameservers: string[];
}
export interface BulkChange {
    id: number;
    type: 'CHANGE' | 'IMPORT' | 'RETRY' | 'ROLLBACK';
    status: string;
    target_nameservers: string[] | null;
    total_count: number;
    pending_count: number;
    processing_count: number;
    succeeded_count: number;
    failed_count: number;
    skipped_count: number;
    conflict_count: number;
    cancelled_count: number;
    confirmed_at: string | null;
    started_at: string | null;
    completed_at: string | null;
    created_at: string;
    user?: User;
    parent?: Pick<BulkChange, 'id' | 'type' | 'status'>;
}
export interface BulkChangeItem {
    id: number;
    preview_disposition: 'CHANGE' | 'WILL_SKIP' | 'BLOCKED';
    status: string | null;
    preview_nameservers: string[];
    old_nameservers: string[] | null;
    target_nameservers: string[];
    attempt_count: number;
    error_category: string | null;
    error_message: string | null;
    excluded_at: string | null;
    domain: ManagedDomain;
}
export interface AuditEvent {
    id: number;
    event: string;
    metadata: Record<string, unknown> | null;
    subject_type: string | null;
    subject_id: number | null;
    created_at: string;
    user?: Pick<User, 'id' | 'name' | 'email'>;
}

export interface User {
    id: number;
    name: string;
    email: string;
    avatar?: string;
    email_verified_at: string | null;
    created_at: string;
    updated_at: string;
    [key: string]: unknown; // This allows for additional properties...
}
