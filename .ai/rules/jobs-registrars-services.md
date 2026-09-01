---
paths:
  - 'app/{Jobs,Registrars,Services}/**/*.php'
---

# Jobs Registrars Services

## Preserve inventory during staged registrar enrichment
Registrars with per-domain detail lookups must implement the staged enrichment contracts. SyncRegistrarAccount saves complete list/inventory data first and must preserve existing nameservers and renewal prices until the corresponding enrichment succeeds. Enrichment failures are isolated in sync_run_enrichments and must not roll back saved inventory or mark unseen domains unavailable unless inventory pagination completed.
