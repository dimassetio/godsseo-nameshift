---
paths:
  - app/Registrars/InfomaniakRegistrar.php
  - app/Registrars/NameSiloRegistrar.php
  - app/Registrars/SpaceshipRegistrar.php
---

# Registrars

## Infomaniak nameserver writes are unsupported
Infomaniak's public API supports reading domain inventory and zone nameservers, but does not document a registrar-level delegation nameserver update endpoint. Keep setNameservers() as an explicit ProviderPermanent failure until an official endpoint is available; do not substitute DNS-record or zone-skeleton APIs.

## Use Infomaniak registrar delegation API
Infomaniak now officially documents registrar delegation updates at PUT /2/domains/domains/{domain}/nameservers (domain:write). Use this endpoint for setNameservers(); keep /2/zones/{domain} only for reading the current zone nameservers. Renewal prices are not present in List Domains, so fetch the TLD renewal value from Infomaniak's own public /api-g/tldprice response and cache it per TLD during a sync.

## Infomaniak nameserver writes are unsupported
Superseded: this is no longer true. Infomaniak now officially supports registrar delegation updates via PUT /2/domains/domains/{domain}/nameservers with domain:write; follow the newer “Use Infomaniak registrar delegation API” rule below.

## Active Infomaniak integration rule
This rule supersedes every earlier statement that Infomaniak nameserver writes are unsupported. Use PUT /2/domains/domains/{domain}/nameservers for delegation updates, GET /2/zones/{domain} for nameserver reads, and Infomaniak's public /api-g/tldprice endpoint for cached per-TLD renewal prices.

## Use NameSilo batch pricing endpoints
Automated NameSilo inventory and pricing requests must use /apibatch. Fetch account-adjusted renewal prices once with getPrices and map reply.{tld}.renew through ProvidesRenewalPrices; do not clear an existing renewal_price when a TLD is absent or non-numeric.

## Spaceship renewal prices use staged public pricing enrichment
Spaceship's authenticated domain list/detail API does not expose standard renewal prices. Keep Spaceship on ProvidesRenewalPrices and resolve each unique TLD from the configurable public pricing reader after inventory is saved. Partial pricing failures must preserve existing renewal_price values and log the affected TLD plus the concrete failure.
