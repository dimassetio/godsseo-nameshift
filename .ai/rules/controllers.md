---
paths:
  - 'app/Http/Controllers/{DomainController,BulkChangeController}.php'
---

# Controllers

## Domain downloads must not inherit pagination limits
Domain asset exports and bulk Excel templates must apply the active inventory filters to the full matching query. Do not apply the domains page `per_page` value or a fixed row limit to download generation; regression tests must cover more than 100 matching domains.
