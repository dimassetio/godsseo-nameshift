---
paths:
  - app/Registrars/InfomaniakRegistrar.php
---

# Registrars

## Infomaniak nameserver writes are unsupported
Infomaniak's public API supports reading domain inventory and zone nameservers, but does not document a registrar-level delegation nameserver update endpoint. Keep setNameservers() as an explicit ProviderPermanent failure until an official endpoint is available; do not substitute DNS-record or zone-skeleton APIs.
