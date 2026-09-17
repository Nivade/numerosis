# Custom domain ownership verification and TLS

**Status: executed 2026-09-17 on `feat/custom-domain-verification`.** Wave 5 of
`saas-readiness-roadmap.md`. Fixed the mode the package documents as supported;
"What shipped" records the two places the state model was simplified.

## The defect

`custom_domain` is one of three identification modes
(`src/Enums/Tenancy/IdentificationMode.php`), documented and shipped. Its
entire validation is a well-formed-FQDN check plus uniqueness across tenants
(`src/Services/Tenancy/DefaultTenantDomainPolicy.php:51`).

Nothing proves the tenant controls the domain. A tenant can claim any hostname
that is not already claimed here. There is also no certificate story at all,
so even a legitimately claimed domain serves nothing over HTTPS unless an
operator wires it by hand.

Decision 2026-09-16: **fix it.** Marking the mode unsupported was the
alternative and was rejected.

## Decision: the package issues no certificates

The package proves ownership and publishes the verified set. Certificates
belong to the deployment, and there are four reasonable deployments:

| Deployment | How it gets certs | What the package gives it |
|---|---|---|
| Caddy (shipped default) | On-demand TLS | `GET /numerosis/tls/ask?domain=` answering 200 or 404 |
| Traefik | ACME per router | `GET /numerosis/tls/routers` returning dynamic config for its HTTP provider |
| Cloudflare for SaaS | Their API | `DomainVerified` event; the host calls Cloudflare |
| Anything else | Host's own | The same event, and the verified-domain query |

One verified-domain query underneath, several thin presenters over it. Nothing
TLS-shaped leaks into the domain logic, and a development environment on
Traefik with a local wildcard certificate needs none of it — dev hostnames are
known ahead of time, so on-demand issuance never comes up.

## Ownership proof

A `TXT` record at `_numerosis-challenge.<domain>` containing a per-domain
token, plus a required `CNAME` (or `A`) pointing the hostname at the platform.
`TXT` proves control of the zone; the `CNAME` is what makes traffic arrive.
Check both — a domain verified but not pointed produces a tenant that
"verified fine" and still 404s.

## Domain states

`domains` gains `verification_token`, `verified_at`, `verification_failed_at`,
`last_checked_at` and a status:

```
pending → verifying → verified → active
             ↓
          failed (retryable)
```

`verified` means ownership proven. `active` means traffic resolves and a
certificate exists. They are separate because the gap between them is where
every support ticket lives.

## Phases

### 1. Schema, token minting, state machine

Token is per domain, stable across retries, and regenerated only on explicit
request.

### 2. DNS checker

`Services\Tenancy\DnsVerifier` over PHP's resolver behind a contract, so tests
fake it and a host can swap in a resolver that bypasses local caching. Checks
`TXT` and the `CNAME`/`A` target independently and reports which half failed.

### 3. Verification job and schedule

Verify on demand when the tenant clicks, and re-verify on a schedule with
backoff — DNS propagates on its own timetable, so a failed check is "not yet",
not "no". Give up after a configurable window and mark failed, retryable.

Re-verify verified domains periodically too: a domain whose DNS is pulled
should eventually stop being served.

### 4. Tenant-facing screen

Setup instructions with the exact records to add, live status per record, a
re-check button, and the distinction between verified and active stated in
words. This screen is the product; the rest is plumbing.

### 5. Presenters

The Caddy ask endpoint and the Traefik routers endpoint, both reading the one
verified-domain query, both cached briefly — Caddy calls the ask endpoint per
new SNI and an uncached database query there is a denial-of-service vector.

The ask endpoint answers on the apex, unauthenticated by necessity, and must
leak nothing beyond "yes this hostname is ours".

### 6. Event seam and documentation

`DomainVerified` and `DomainRevoked`, carrying scalars. `docs/host-requirements.md`
gains the deployment matrix above, since choosing a proxy is a host decision
the package now has an opinion about.

## Tests

- Verification fails when only the `TXT` exists, when only the `CNAME` exists,
  and succeeds with both, with a distinct reported reason for each failure.
- A domain already claimed by another tenant cannot be verified, and the
  uniqueness check happens before the token is minted.
- Ask endpoint answers 200 for verified, non-200 for pending, failed, revoked,
  and for a domain belonging to a suspended or closed tenant.
- Ask endpoint responses are cached and the cache is invalidated on
  verification and revocation.
- Routers endpoint output is valid Traefik dynamic configuration and contains
  every verified domain exactly once.
- Re-verification of a domain whose DNS was removed transitions it out of
  active.
- `DomainVerified` fires once per transition, not per check.

## Risks

- **The ask endpoint is a public, unauthenticated, per-request database hit.**
  Cache it, rate-limit it, and never make it do work proportional to the
  number of tenants.
- **Suspension and closure must reach TLS.** A closed tenant whose domain
  still answers is a data-exposure bug, not a cosmetic one. The verified-domain
  query filters on tenant state, and that is the assertion worth writing
  first.
- **Identification mode is a deploy-time choice.** Switching modes does not
  migrate tenants already provisioned; this plan does not change that.

## What shipped

- **Five states, not six.** `pending → verifying → verified → active` plus
  `failed` and `revoked`, on `domains.status`. `active` means "proven *and*
  pointed here" rather than "a certificate exists", because nothing in this
  package can observe a certificate — the proxy issues it. The tenant screen
  states that difference in words, which is what the plan actually wanted from
  the distinction.
- **`Active` rows stay checkable.** The plan's own phase 3 asks for it and the
  first draft of `Domain::dueForCheck()` excluded them, which would have left a
  domain serving after its DNS was pulled.
- **The verified-domain query filters tenant state, and that is the first
  assertion in the suite.** A suspended or closed tenant's hostname stops
  answering, per the plan's risk section.
- **Both TLS endpoints are off by default and env-switched**
  (`NUMEROSIS_TLS_ASK_ENDPOINT`, `NUMEROSIS_TLS_ROUTERS_ENDPOINT`), because they
  are registered at boot and public. Their answers are cached per hostname —
  never one entry holding the whole fleet — and invalidated by
  `RecordDomainVerification` on every transition.
- **The screen is gated on the identification mode, not on a `Feature` class.**
  There is nothing to claim under subdomain or path mode, so a feature flag would
  have been a second switch saying the same thing as
  `numerosis.tenancy.identification.mode`.

Three changes from the readiness remediation, 2026-09-18:

- **`verified_at` and `verification_failed_at` are gone, and stay gone.** Both
  were written as pure functions of `status`, and `verified_at` was nulled on
  any transient failure, so it could not serve as a first-verified record
  anyway. `refactor/saas-readiness-simplify` deleted them; the remediation's
  phase 3 confirmed the deletion rather than restoring the columns. The history
  lives in the activity log, since `DomainVerified` and `DomainRevoked` are
  audited events.
- **The give-up window measures the current failing streak** (P26, `88a3841`).
  It measured from `created_at`, so a domain that had served for months and then
  lost its DNS was marked `Failed` on its first bad check. A never-verified claim
  behaves exactly as before.
- **Rechecks back off** (P20, `88a3841`). One flat `recheck_minutes` polled a
  domain nobody was going to fix at the same rate as one mid-setup. The interval
  grows with how long the failure has lasted, and caps.

Both needed a streak the table did not record: `last_checked_at` and `status`
are overwritten on every check, so `domains.failing_since` is a new column,
written by the same `forceFill()` that stamps the other two.

`Contracts\Tenancy\DnsResolver` is the one seam to the network;
`Services\Tenancy\SystemDnsResolver` is the default and
`tests/Support/FakeDnsResolver` is what the suite binds, so no test resolves a
real name.
