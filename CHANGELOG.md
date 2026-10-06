# Changelog

All notable changes to `nosh/omnitax`. Versions follow [Semantic Versioning](https://semver.org).

## v1.2.0 — 2026-10-06

Real-time reporting with a queued fallback, plus fixes that matter in production.

### Added
- **`OmniTax::report($invoice, $reference = null, $realtime = null)`** — tries the authority inline for at most `omnitax.realtime.timeout` seconds (`FISCAL_REALTIME_TIMEOUT`, default 3) so the fiscal number can go on the receipt being printed; if the authority is slow, down or 5xx, the sale is queued (after the surrounding DB transaction commits) and the caller carries on. A rejection is recorded as `failed` and not queued. `FISCAL_REALTIME=false` always queues. Returns the `FiscalInvoice` record.
- `OmniTax::timeout(float $seconds)` — cap any single call. New optional `Contracts\TimeoutAware` for transports (the `Transport` contract is unchanged).
- **Credit notes:** `InvoiceBuilder::creditNoteFor($originalNumber, $originalFiscalNumber = null)` and `Invoice::isCreditNote()`. PRA (InvoiceType 3 + RefUSIN) and SRB (sales return) accept them; a credit note to **FBR** (which has none) is rejected locally with a clear message instead of being sent as an ordinary sale.
- `OmniTax::supports(Feature::…)` / `AbstractDriver::supports()` with `Support\Feature::CREDIT_NOTE`, `OFFLINE_MODE`, `REMOTE_VALIDATION`.
- **`OmniTax::check()`** → `Responses\HealthCheck` for a "Test connection" button: credentials → seller → authority → sample validate. `reachedAuthority()` says whether the authority itself was asked (FBR) or only the configuration was checked (PRA/SRB have no test endpoint).
- `OmniTax::isConfigured()`.
- `FiscalResponse::isRetryable()` (unreachable or 5xx).
- `fiscal_invoices` columns (new migration, all nullable/defaulted): `reference`, `attempts`, `last_error`, `qr_payload`. Scopes `pending()`, `failed()`, `reported()`, `forReference()`; `FiscalInvoice::isReported()`; `fromInvoice(..., $reference)`.
- Config: `realtime.enabled`, `realtime.timeout`, `http.force_ipv4` (`FISCAL_FORCE_IPV4` — for PRA cloud's IP whitelisting on dual-stack servers), `http.connect_timeout`.

### Fixed
- **Idempotency key ignored the invoice number.** Two identical sales on the same day (same items, same seller) hashed to one key, so the second was merged into the first and never reported. The key now uses `->number()` when present (and the timestamp, buyer and meta otherwise). Always give each sale its number.
- **A reported invoice could be reported again.** `FiscalInvoice::fromInvoice()` reset an accepted record to `pending` when the same sale was recorded twice, and the job then resubmitted it. Accepted records are now returned untouched.
- **SRB/PRA QR from a saved record pointed nowhere** — `FiscalInvoice::qr()` encoded the fiscal number; it now encodes the authority's QR payload (the verification URL for SRB/PRA).
- **Network failures threw** (`ConnectionException`) instead of being retried — the HTTP transport now returns them as status 0, which every caller treats as "retry later".
- `fiscal:submit-pending` re-sent **rejected** invoices on every run. It now takes `pending` records only, older than `--older-than` minutes (default 5) so a sale whose job is still queued is not sent twice; `fiscal:retry-failed` remains the way to resend a fixed rejection.
- Concurrent submission of one sale: `SubmitFiscalInvoice` is now `ShouldBeUnique` per record and takes a per-record cache lock while sending.
- Timeouts are passed as Guzzle options, so fractional seconds are honoured on Laravel 10.

### Changed
- `failed` now means **rejected by the authority** only. A record that could not reach the authority stays `pending` (with `last_error`) and is retried.

### Upgrading
- `php artisan migrate` (adds the four `fiscal_invoices` columns).
- Pass `->number()` on every invoice if you were not already.
- If you scheduled `fiscal:submit-pending` to also retry rejections, schedule `fiscal:retry-failed` for that instead.

## v1.1.0 — 2026-10-06
- SRB (Sindh) and PRA (Punjab) authorities, cloud + offline.

## v1.0.0 — 2026-08-18
- Initial release: FBR (federal / PRAL) digital invoicing.
