---
paths:
  - 'app/{Http/Controllers/BulkChangeController.php,Jobs/LoadBulkChangeBatch.php,Services/BulkNameserverSpreadsheet.php}'
---

# Http Controllers

## Large bulk imports use configurable limits and loader chunks
Bulk Excel imports accept up to `nameshift.bulk_changes.max_import_rows` (default 5,000). Confirmation must dispatch `LoadBulkChangeBatch` jobs using `dispatch_chunk_size`; loaders hydrate the same Laravel batch with per-domain mutation jobs so cancellation and progress remain unified.
