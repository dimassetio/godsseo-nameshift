# Product Requirements Document

## Bulk Nameserver Manager

**Document status:** Draft v0.1  
**Product type:** Internal web application  
**Primary user:** Domain administrator  
**Target implementation:** Laravel full-stack  
**Date:** 26 August 2026

---

## 1. Executive Summary

### Approved simplified change flow (August 2026)

This revision supersedes the earlier select-domains and typed-confirmation interaction:

- A single domain is edited inline using `nameserver1` and `nameserver2` fields. Clicking Save opens a before/after confirmation dialog; confirmation requires only a button click.
- Bulk changes are created only by uploading the provided `.xlsx` template with columns `domain`, `nameserver1`, and `nameserver2`.
- Only exact domain names present in the uploaded workbook are included. The maximum remains 100 domains per operation.
- Each Excel row may provide its own target nameserver pair.
- Excel upload still creates a non-mutating preview. Execution begins only after the administrator reviews it and clicks Confirm.
- All existing safety behavior remains: encrypted credentials, durable items, reservation, live read-before-write, drift conflict protection, retry, audit history, cancellation, and rollback snapshots.

Bulk Nameserver Manager is an internal web application for viewing domains held in Namecheap and Name.com accounts and changing their authoritative nameservers in bulk from one interface.

The MVP is intended to replace the repetitive process of signing in to multiple registrars, locating domains individually, entering the same nameservers repeatedly, and manually tracking which changes succeeded. It prioritizes speed of implementation, operational safety, visibility of partial failures, and the ability to retry failed domains.

The MVP will be delivered as a single Laravel application. It will use server-rendered pages, a relational database, and Laravel's database-backed queue. Registrar credentials and all registrar API calls remain server-side.

## 2. Background and Problem

The domain administrator owns or manages domains across Namecheap and Name.com. Both registrars provide API access for listing domains and changing nameservers, but their authentication, request formats, responses, and error behavior differ.

The current manual workflow has several problems:

1. Changing the same nameservers across many domains is slow and repetitive.
2. Domains are distributed across more than one registrar.
3. It is easy to omit a domain or enter an incorrect nameserver.
4. A bulk operation may partially succeed, with no unified record of the result.
5. There is no application-level history of the previous nameservers.
6. Retrying failures manually requires revisiting each domain.

## 3. Product Vision

Provide one safe and simple control panel where an administrator can select domains from multiple registrars, preview a nameserver change, execute it in bulk, and see an auditable result for every domain.

## 4. Goals

### 4.1 MVP goals

- Connect one or more supported registrar accounts.
- Synchronize domains from Namecheap and Name.com.
- Show each domain's registrar and current nameservers.
- Select multiple domains across registrars.
- Apply one target nameserver set to the selected domains.
- Require preview and explicit confirmation before execution.
- Process changes asynchronously, one domain per queue job.
- Record success, failure, skip, and error details per domain.
- Retry only failed items without repeating successful items.
- Preserve previous nameservers for audit and manual rollback.

### 4.2 Success criteria

The MVP is considered successful when an administrator can:

1. Connect both registrar accounts.
2. Synchronize and view all manageable domains.
3. Select at least 50 domains in one operation.
4. preview the exact before-and-after nameservers for every selected domain.
5. Start the operation without keeping the browser request open.
6. See a final result for every selected domain.
7. Retry failed domains without modifying successful domains again.
8. Identify the old nameservers used by every changed domain.

## 5. Non-Goals

The following are explicitly excluded from the MVP:

- Domain registration, renewal, transfer, or deletion.
- DNS record management such as A, AAAA, CNAME, MX, or TXT records.
- Nameserver hosting or DNS zone provisioning.
- Automated DNS record migration between providers.
- Automated rollback on partial failure.
- Public customer access or multi-tenant billing.
- Complex approval workflows.
- Mobile application.
- WebSocket-based real-time updates.
- Registrar support beyond Namecheap and Name.com.
- Guaranteed global DNS propagation monitoring.
- Advanced analytics or reporting dashboards.

## 6. Assumptions and Constraints

### 6.1 Product assumptions

- The application is used internally by a trusted administrator.
- The administrator already has valid API access and permission to modify the relevant domains.
- One bulk operation applies the same ordered nameserver list to every selected domain.
- The typical batch contains tens or hundreds of domains, not hundreds of thousands.
- An API-accepted update may take time to become observable through public DNS.

### 6.2 Technical constraints

- The application will be implemented in a single Laravel codebase.
- UI will use Blade with Alpine.js or Livewire where interaction materially benefits from it.
- Laravel's database queue will be used for MVP background processing.
- Registrar credentials must never be exposed to the browser.
- Namecheap production API access requires the server's public IPv4 address to be allowlisted.
- Namecheap returns XML while Name.com Core API returns JSON.
- Name.com rate limits and `Retry-After` responses must be respected.
- A bulk change is not atomic across domains or registrars.

## 7. Users and Roles

### 7.1 Primary persona: Domain Administrator

The Domain Administrator manages registrar accounts and domain configuration. The user is technically capable, understands the effect of changing authoritative nameservers, and is responsible for reviewing the target domains before execution.

### 7.2 MVP authorization model

The MVP has one authenticated role:

- **Administrator:** Can manage registrar connections, synchronize domains, create and execute bulk changes, retry failures, and view history.

Granular roles and approval permissions are deferred.

## 8. Primary User Journey

1. Administrator signs in.
2. Administrator adds or verifies Namecheap and Name.com credentials.
3. Administrator runs domain synchronization.
4. Application displays the unified domain inventory.
5. Administrator searches or filters and selects domains.
6. Administrator enters or selects the target nameservers.
7. Application validates the target and creates a preview.
8. Administrator reviews each domain's current and target nameservers.
9. Administrator provides explicit confirmation.
10. Application creates a bulk change and queues one item per domain.
11. Worker processes items while the UI polls for progress.
12. Administrator reviews succeeded, failed, skipped, and conflicted items.
13. Administrator optionally retries failed items.

## 9. Functional Requirements

### FR-01 Authentication

The system must require authentication before any page or operation is accessible.

**Acceptance criteria**

- Unauthenticated requests are redirected to the login page.
- An authenticated administrator can sign out.
- Passwords are stored using Laravel's standard password hashing.
- The MVP does not provide public self-registration.

### FR-02 Registrar account management

The administrator must be able to configure a Namecheap or Name.com account.

**Required fields**

- Provider.
- User-defined account label.
- Provider username or API username.
- API key or token.
- Namecheap-specific API user and client IPv4 where required.
- Active/inactive state.

**Acceptance criteria**

- Credentials are encrypted before being stored.
- Stored secrets are never displayed in full after saving.
- The administrator can test a connection before using it.
- A failed test displays a safe, actionable error without exposing secrets.
- Deactivating an account prevents new synchronization and mutation requests for it.

### FR-03 Domain synchronization

The system must retrieve domains from every active registrar account and maintain a local inventory.

**Acceptance criteria**

- Synchronization can be started manually.
- Pagination is followed until all available domains are retrieved.
- Existing domains are updated instead of duplicated.
- The application records the registrar account and last synchronization time.
- A domain no longer returned by a registrar is marked unavailable or stale rather than immediately deleted.
- A synchronization failure for one registrar does not delete previously synchronized domains.
- The result reports created, updated, unchanged, and failed counts.

### FR-04 Unified domain list

The administrator must be able to inspect domains across all connected accounts.

**Displayed fields**

- Domain name.
- Registrar provider.
- Registrar account label.
- Current nameservers.
- Last synchronized time.
- Local availability/status.

**Acceptance criteria**

- The list supports pagination.
- The list supports search by full or partial domain name.
- The list supports filtering by registrar account.
- The administrator can select one, many, or all domains on the current filtered result.
- Selection count remains clearly visible.

### FR-05 Nameserver input and presets

The administrator must be able to enter an ordered list of target nameservers.

**Acceptance criteria**

- At least two nameservers are required by the application default.
- Every entry is normalized to lowercase and has surrounding whitespace removed.
- Duplicate entries are rejected.
- Each entry must be a syntactically valid hostname.
- IP addresses and URL values are rejected.
- The administrator can save a nameserver list as a reusable preset.
- A preset has a unique name and an ordered nameserver list.

### FR-06 Change preview

The system must show a non-mutating preview before a bulk change can be confirmed.

**Acceptance criteria**

- Preview lists every selected domain.
- Preview shows registrar, current nameservers, and target nameservers.
- Domains already using the exact normalized target set are marked `WILL_SKIP`.
- Unsupported, unavailable, or inactive-account domains are marked `BLOCKED`.
- Preview displays totals for changeable, skipped, and blocked domains.
- No registrar mutation occurs during preview.
- The bulk operation cannot start while any selected domain has unresolved validation errors, unless the blocked domains are explicitly excluded.

### FR-07 Explicit confirmation

The system must require confirmation before creating mutation jobs.

**Acceptance criteria**

- Confirmation displays the number of domains that will be changed.
- The administrator must type a generated phrase such as `CHANGE 27 DOMAINS`.
- The operation cannot start if the phrase does not match.
- Once confirmed, the target nameservers and item list are immutable.

### FR-08 Bulk change execution

The system must process each domain as an independent background job.

**Acceptance criteria**

- The browser request returns after the bulk operation and its items are created.
- Each domain has its own execution status and attempt count.
- Before mutation, the worker records the locally known nameservers as the rollback snapshot.
- The worker retrieves the latest registrar state before writing when the provider supports it.
- If the remote state differs from the preview snapshot, the item is marked `CONFLICT` and is not overwritten automatically.
- A domain already using the target nameservers is marked `SKIPPED`.
- Successful provider responses are recorded without storing credentials or full sensitive request headers.
- Processing continues when another domain fails.

### FR-09 Retry behavior

The system must distinguish retryable failures from permanent failures.

**Automatically retryable**

- Network timeout.
- Connection failure.
- HTTP 429 or provider rate limiting.
- Provider 5xx or temporary service unavailability.

**Not automatically retryable**

- Invalid credentials.
- Permission denied.
- Domain not associated with the account.
- Invalid nameserver format.
- Unsupported domain or registry restriction.
- Conflict between preview state and current remote state.

**Acceptance criteria**

- Automatic retry uses increasing delays and a maximum attempt count.
- `Retry-After` is respected when provided.
- The administrator can create a retry run containing failed retryable items only.
- Previously successful and skipped items are not repeated.

### FR-10 Progress and result visibility

The administrator must be able to monitor and review each bulk operation.

**Acceptance criteria**

- The detail page displays total, pending, processing, succeeded, failed, skipped, and conflict counts.
- The page refreshes progress through polling without a full page reload.
- Each item displays domain, provider, status, attempts, and sanitized error message.
- The operation displays started and completed timestamps.
- Final bulk status is derived from item results.

### FR-11 History and audit

The system must retain enough information to explain every mutation initiated through the application.

**Acceptance criteria**

- Each bulk operation records its creator, target nameservers, timestamps, and final counts.
- Each item records old nameservers, target nameservers, final status, and error details.
- Audit history is read-only from the UI.
- API keys, tokens, Authorization headers, and full raw credential-bearing URLs are excluded from logs.

### FR-12 Manual rollback preparation

The system must allow an administrator to prepare a new operation using recorded previous nameservers.

**Acceptance criteria**

- A successful item exposes a `Prepare rollback` action.
- Rollback is represented as a new change, never as a database-only reversal.
- Rollback requires a new preview and explicit confirmation.
- The MVP does not automatically roll back an entire batch after partial failure.
- Because different domains may have different old nameservers, group rollback is only allowed for items sharing the same rollback target; otherwise items are prepared separately.

## 10. Business Rules

1. Only domains belonging to an active registrar account may be changed.
2. A domain may have only one active mutation item at a time.
3. Nameserver comparison is case-insensitive and ignores a trailing dot.
4. The order entered by the administrator is preserved when sent to the registrar.
5. No item is considered successful solely because it was queued.
6. An API success means the registrar accepted or reflected the requested change; it does not guarantee worldwide DNS propagation.
7. A failed item does not reverse successful sibling items.
8. Credentials are accessed only within server-side registrar services.
9. A synchronization operation must never overwrite bulk history.
10. Changes made outside the application may create preview conflicts and must not be overwritten silently.

## 11. Status Model

### 11.1 Bulk change status

- `DRAFT`: Preview created but not confirmed.
- `QUEUED`: Confirmed and waiting for processing.
- `RUNNING`: At least one item is processing and work remains.
- `SUCCEEDED`: Every actionable item succeeded or was skipped.
- `PARTIALLY_SUCCEEDED`: At least one item succeeded and at least one failed or conflicted.
- `FAILED`: No actionable item succeeded and at least one failed.
- `CANCELLED`: Pending work was cancelled before execution; completed items remain unchanged.

### 11.2 Item status

- `PENDING`
- `PROCESSING`
- `RETRYING`
- `SUCCEEDED`
- `SKIPPED`
- `CONFLICT`
- `FAILED`
- `CANCELLED`

## 12. Information Architecture

### 12.1 Login

- Email.
- Password.
- Sign in action.

### 12.2 Dashboard

- Total synchronized domains.
- Domains by registrar.
- Last synchronization status.
- Recent bulk changes.
- Failed item count requiring attention.

### 12.3 Domains

- Search and registrar filters.
- Paginated domain table.
- Multi-selection.
- `Change nameservers` action.
- `Synchronize domains` action.

### 12.4 Bulk change wizard

1. Selected domains.
2. Target nameserver input or preset selection.
3. Validation and preview.
4. Confirmation.
5. Redirect to progress page.

### 12.5 Bulk change detail

- Summary and progress counts.
- Per-domain results.
- Error filter.
- Retry eligible failures.
- Prepare rollback.

### 12.6 Settings

- Registrar accounts.
- Connection test.
- Nameserver presets.

## 13. Suggested Data Model

### 13.1 `users`

Use Laravel's standard user model with public registration disabled.

### 13.2 `registrar_accounts`

- `id`
- `provider`
- `label`
- `username`
- `credentials` encrypted text/JSON
- `is_active`
- `last_synced_at`
- timestamps

### 13.3 `domains`

- `id`
- `registrar_account_id`
- `name`
- `nameservers` JSON
- `remote_status` nullable
- `inventory_status`
- `last_synced_at`
- timestamps

Unique constraint: registrar account plus normalized domain name.

### 13.4 `nameserver_presets`

- `id`
- `name`
- `nameservers` JSON
- timestamps

### 13.5 `bulk_changes`

- `id`
- `user_id`
- `parent_bulk_change_id` nullable for retry/rollback lineage
- `type`: `CHANGE`, `RETRY`, or `ROLLBACK`
- `target_nameservers` JSON nullable when rollback items differ
- `status`
- result counters
- `confirmed_at`
- `started_at`
- `completed_at`
- timestamps

### 13.6 `bulk_change_items`

- `id`
- `bulk_change_id`
- `domain_id`
- `preview_nameservers` JSON
- `old_nameservers` JSON nullable until execution
- `target_nameservers` JSON
- `status`
- `attempt_count`
- `error_category` nullable
- `provider_error_code` nullable
- `error_message` nullable
- `started_at`
- `completed_at`
- timestamps

### 13.7 Framework queue tables

- `jobs`
- `job_batches` if Laravel batching is used
- `failed_jobs`

## 14. Registrar Integration Requirements

Registrar-specific behavior must be isolated behind a shared contract.

```php
interface Registrar
{
    public function testConnection(): ConnectionResult;
    public function listDomains(?string $cursor = null): DomainPage;
    public function getNameservers(string $domain): array;
    public function setNameservers(string $domain, array $nameservers): ChangeResult;
}
```

Implementations:

- `NamecheapRegistrar`
- `NameComRegistrar`

The rest of the application must operate on normalized internal value objects rather than provider XML or JSON responses.

### 14.1 Namecheap

- Use `namecheap.domains.dns.getList` to retrieve nameservers.
- Use `namecheap.domains.dns.setCustom` to set custom nameservers.
- Parse XML safely and treat top-level API status plus provider error elements as authoritative.
- Never log the full query string because it may contain the API key.

### 14.2 Name.com

- Use the current Core API rather than legacy v4 for new development.
- Use the domains list endpoint for inventory.
- Use the Set Nameservers endpoint for mutation.
- Handle pagination and HTTP 429.
- Respect `Retry-After` when returned.

## 15. Security Requirements

- All application pages require authentication.
- HTTPS is mandatory outside local development.
- Registrar credentials are encrypted at rest using application-level encryption.
- The application encryption key is not stored in source control.
- Credential values are redacted from logs and error reports.
- Validation errors and registrar errors shown in the UI must be sanitized.
- CSRF protection is enabled for browser mutations.
- Login is rate-limited.
- Session cookies use secure and HTTP-only settings in production.
- The application database user follows least privilege.
- Full registrar response bodies are not stored by default.
- Credential changes and bulk confirmations produce audit records.

## 16. Non-Functional Requirements

### 16.1 Reliability

- Partial registrar outages must not corrupt local inventory or job history.
- Jobs must be safe to retry after worker restart.
- A unique execution lock prevents concurrent mutations for the same domain.
- Failed jobs retain a readable error category.

### 16.2 Performance

- Domain list pages should respond within two seconds for up to 10,000 locally stored domains under normal VPS load.
- Creating a bulk operation should return within three seconds because provider mutations are queued.
- Progress polling should default to every five seconds and stop after completion.

### 16.3 Maintainability

- Registrar-specific code is isolated in adapters/services.
- Provider responses are normalized before use by controllers and views.
- Mutation logic is tested independently from live provider APIs using fakes.
- Environment selection for sandbox and production is configuration-driven.

### 16.4 Observability

- Log synchronization start, completion, provider, duration, and counts.
- Log mutation item status transitions without secrets.
- Log job ID, bulk change ID, domain ID, provider, attempt, and error category.
- Provide a simple failed-item filter in the UI.

## 17. Error Categories

All provider errors should be normalized into the following application categories:

- `AUTHENTICATION`
- `PERMISSION`
- `VALIDATION`
- `DOMAIN_NOT_FOUND`
- `DOMAIN_NOT_OWNED`
- `CONFLICT`
- `RATE_LIMIT`
- `NETWORK`
- `PROVIDER_TEMPORARY`
- `PROVIDER_PERMANENT`
- `UNKNOWN`

The raw provider code may be stored separately when it does not include sensitive data.

## 18. Analytics and MVP Metrics

The MVP should record operational metrics using application data rather than a third-party analytics service:

- Number of domains synchronized per registrar.
- Number of bulk operations.
- Domains attempted per operation.
- Success, failure, conflict, and skip rates.
- Average attempts per successful item.
- Average operation completion duration.
- Most common normalized error categories.

These metrics are for operational evaluation and do not require a dedicated analytics dashboard in MVP.

## 19. Test and Acceptance Strategy

### 19.1 Unit tests

- Nameserver normalization and validation.
- Provider error normalization.
- Bulk status calculation.
- Retry eligibility.
- XML and JSON response parsing.
- Secret redaction.

### 19.2 Feature tests

- Authentication and disabled registration.
- Registrar credential save and encryption.
- Domain synchronization with mocked provider responses.
- Domain search, filters, and selection.
- Preview generation.
- Confirmation phrase validation.
- Job creation without synchronous provider mutation.
- Retry run containing eligible failures only.

### 19.3 Job tests

- Successful update.
- Already-correct nameservers produce `SKIPPED`.
- Remote state change produces `CONFLICT`.
- Rate limit produces delayed retry.
- Permanent provider failure produces `FAILED`.
- One failed item does not stop sibling jobs.

### 19.4 Provider verification

- Use provider sandbox environments where available.
- Start production verification with one non-critical domain.
- Confirm remote read-back before increasing batch size.

## 20. MVP Release Criteria

The MVP may be released for internal use when:

- Both registrar connection tests work.
- Domain synchronization works with pagination.
- At least one test domain per registrar can be changed successfully.
- Preview makes no provider mutations.
- Credentials are encrypted and absent from logs.
- Bulk execution continues after an individual failure.
- Failed items can be retried independently.
- Old and target nameservers are preserved in history.
- Required unit, feature, and job tests pass.
- The administrator understands that DNS propagation is outside the operation's immediate success state.

## 21. Delivery Phases

### Phase 1: Foundation and inventory

- Laravel project and authentication.
- Registrar account management.
- Namecheap and Name.com client services.
- Connection tests.
- Domain synchronization.
- Unified domain list.

**Exit condition:** The administrator can connect both accounts and view an accurate local domain inventory.

### Phase 2: Safe bulk change

- Nameserver validation and presets.
- Domain selection.
- Preview and explicit confirmation.
- Bulk changes and item records.
- Database queue worker.
- Registrar mutation jobs.

**Exit condition:** A confirmed batch is processed independently per domain and produces a durable result.

### Phase 3: Operational completion

- Progress polling.
- Failure filters and sanitized errors.
- Retry eligible failures.
- Rollback preparation.
- Audit and operational metrics.
- Automated test coverage.

**Exit condition:** The administrator can diagnose, retry, and audit bulk operations without returning to each registrar dashboard.

## 22. Risks and Mitigations

### Incorrect bulk target

**Risk:** An administrator selects the wrong domains or target nameservers.  
**Mitigation:** Preview, normalization, counts, typed confirmation, and immutable confirmed plan.

### Partial success

**Risk:** Some domains change while others fail.  
**Mitigation:** Independent item states, continuation after failure, retry-only-failed behavior, and preserved old values.

### External state drift

**Risk:** Nameservers are changed outside the application after preview.  
**Mitigation:** Read-before-write and `CONFLICT` state.

### Credential leakage

**Risk:** API keys appear in browser responses or logs.  
**Mitigation:** Server-only adapters, encrypted storage, redaction, and no raw Namecheap credential-bearing URLs in logs.

### Provider throttling or outage

**Risk:** Bulk processing triggers rate limits or temporary failures.  
**Mitigation:** Controlled concurrency, delayed retries, and `Retry-After` handling.

### Nameserver zone not ready

**Risk:** Target nameservers are syntactically valid but not prepared to serve the domain.  
**Mitigation:** MVP warning and explicit confirmation. Preflight SOA/NS validation is a post-MVP enhancement.

## 23. Post-MVP Opportunities

- DNS preflight checks before registrar mutation.
- Public DNS propagation observation.
- Protected-domain labels and secondary approval.
- CSV import/export.
- Scheduled bulk changes.
- Notifications through email, Slack, or Discord.
- More registrars through additional adapters.
- Multi-user roles and organization tenancy.
- Secret manager integration.
- Redis/Horizon for greater throughput and monitoring.
- Nameserver drift detection.

## 24. Open Product Decisions

These decisions do not block the initial PRD but should be settled before implementation begins:

1. Will the MVP connect exactly one account per registrar or support multiple accounts from day one?
2. Is SQLite sufficient initially, or should the application use the existing PostgreSQL/MySQL service?
3. Should Livewire be used, or is Blade plus Alpine.js sufficient?
4. What is the initial maximum allowed batch size: 50, 100, or unlimited with a warning?
5. Should current nameservers be fetched live for every preview or only refreshed during execution?
6. Is a saved nameserver preset required for MVP launch or can free-form entry ship first?
7. Should the first release include a single administrator account seeded from the command line?

## 25. Recommended Initial Decisions

To optimize for development speed:

- Support multiple registrar accounts in the schema, even if only two are configured initially.
- Use the relational database already available on the VPS.
- Use Blade and Alpine.js; add Livewire only to the domain selection and progress screens if needed.
- Set an initial batch limit of 100 domains.
- Use cached nameservers for preview and mandatory live read-before-write during execution.
- Include simple reusable nameserver presets.
- Disable public registration and create the initial administrator through an Artisan command or seeder.

## 26. References

- Namecheap `setCustom`: https://www.namecheap.com/support/api/methods/domains-dns/set-custom/
- Namecheap `getList`: https://www.namecheap.com/support/api/methods/domains-dns/get-list/
- Namecheap API access and IP allowlisting: https://www.namecheap.com/support/api/intro/
- Name.com Core API Set Nameservers: https://docs.name.com/api/v1/reference/domains/set-nameservers
- Name.com Core API List Domains: https://docs.name.com/api/v1/reference/domains/list-domains
- Name.com Core API overview and rate limits: https://docs.name.com/api/v1/overview
