# OmniTax

**A Laravel package for real‑time fiscal (digital) invoicing with Pakistan's tax system — built for FBR, and extensible to every provincial authority and beyond.**

`nosh/omnitax` lets any restaurant, café, or retail business issue **government‑compliant invoices** — the kind that carry an official fiscal invoice number and a QR code — directly from their own system. You build one plain invoice; the package reports it to the correct tax authority in real time, gets back the official invoice number, and hands you a print‑ready QR code and logo for the customer's receipt.

**What's included today, and what's coming:** OmniTax is made first and foremost for **Pakistan's FBR and tax system**. Right now it ships a complete, live **FBR (federal / PRAL) integration**. The **provincial authorities** that most restaurants report to — **PRA (Punjab), SRB (Sindh), KPRA (KP), BRA (Balochistan)** — are on the roadmap (§2), and because the package is authority‑driven, adding each one (and, by design, tax systems in other countries later) is a new driver, not a rewrite of your billing code.

It is **authority‑driven** and **multi‑tenant**: one installation can serve many businesses, each connected to its own tax authority with its own credentials.

---

## Table of contents

1. [What this package does](#1-what-this-package-does)
2. [Supported authorities & roadmap](#2-supported-authorities--roadmap)
3. [Requirements](#3-requirements)
4. [How it works](#4-how-it-works)
5. [Installation](#5-installation)
6. [Configuration](#6-configuration)
7. [Before you can go live: registering with your authority](#7-before-you-can-go-live-registering-with-your-authority)
8. [Quick start — your first invoice](#8-quick-start--your-first-invoice)
9. [The canonical invoice](#9-the-canonical-invoice)
10. [Reading the response](#10-reading-the-response)
11. [Printing the receipt: QR code & logo](#11-printing-the-receipt-qr-code--logo)
12. [Submitting in the background (recommended)](#12-submitting-in-the-background-recommended)
13. [Multi‑tenant: one install, many restaurants](#13-multi-tenant-one-install-many-restaurants)
14. [Reference data (provinces, units, HS codes…)](#14-reference-data-provinces-units-hs-codes)
15. [Sandbox testing & scenarios](#15-sandbox-testing--scenarios)
16. [Events](#16-events)
17. [Artisan commands](#17-artisan-commands)
18. [Error handling](#18-error-handling)
19. [Going to production — checklist](#19-going-to-production--checklist)
20. [Extending: add a new authority (future‑global)](#20-extending-add-a-new-authority-future-global)
21. [FAQ](#21-faq)
22. [Support & license](#22-support--license)

---

## 1. What this package does

In Pakistan, tax‑registered restaurants and businesses must report each sale to their tax authority and print an **official fiscal invoice** — one bearing a government‑issued invoice number and a QR code — for the customer. Different authorities run different systems:

- **Dine‑in / services** are taxed by the **province** (Punjab → PRA, Sindh → SRB, KP → KPRA, Balochistan → BRA).
- **Islamabad (ICT) services and goods** are handled by the **federal** system, **FBR** (operated by PRAL).

This package hides all of that behind **one clean API**. You describe a sale once, in a neutral format, and the package:

1. Picks the right **authority driver** for that business.
2. Translates your invoice into that authority's exact payload.
3. **Validates** it (dry run) and/or **submits** it in real time.
4. Returns the **official fiscal invoice number** and the data for the **QR code**.
5. Stores the invoice, its status, and the authority's response for your records.
6. Retries safely on failure, and never double‑reports the same sale.

You never touch a government API directly, and you never rewrite your billing code when you expand to a new province or country.

---

## 2. Supported authorities & roadmap

| Authority | Region | Applies to | Status |
|---|---|---|---|
| **FBR** (Federal, via PRAL) | Islamabad / ICT + goods | Services (ICT), goods | ✅ Available |
| **PRA** — Punjab Revenue Authority | Punjab | Restaurant / services | 🚧 Rolling out |
| **SRB** — Sindh Revenue Board | Sindh | Restaurant / services | 🚧 Rolling out |
| **KPRA** — KP Revenue Authority | Khyber Pakhtunkhwa | Restaurant / services | 🚧 Rolling out |
| **BRA** — Balochistan Revenue Authority | Balochistan | Restaurant / services | 🗓️ Planned |
| **International** (ZATCA, MyInvois, …) | Global | Any | 🧭 By design — see §20 |

> The public API in this document is **identical for every authority**. Enabling a new one is a configuration change, not a rewrite of your billing code.

---

## 3. Requirements

- **PHP** 8.1+
- **Laravel** 10, 11, 12, or 13 (supported: `^10.0 || ^11.0 || ^12.0 || ^13.0`)
- A **queue** worker (recommended, for background submission)
- **Credentials** from your tax authority (a security token — see §7)
- `ext-gd` or `imagick` if you want the package to render QR images for you

---

## 4. How it works

```
  Your POS / billing app
          │  (build one neutral invoice)
          ▼
  ┌───────────────────────────────┐
  │          OmniTax core          │
  │  • resolves the business's     │
  │    authority + credentials     │
  │  • validates the invoice       │
  └───────────────┬───────────────┘
                  │ maps to the authority's format
                  ▼
        ┌───────────────────┐
        │  Authority driver  │  ← FBR │ PRA │ SRB │ KPRA │ BRA │ …
        └─────────┬─────────┘
                  │ real‑time HTTPS
                  ▼
        ┌───────────────────┐
        │  Tax authority API │  → official invoice no. + status
        └─────────┬─────────┘
                  │
                  ▼
  ┌───────────────────────────────┐
  │  Stored: invoice, status,      │
  │  fiscal number, QR payload     │  → print receipt with QR + logo
  └───────────────────────────────┘
```

The invoice you build is **authority‑neutral**. The driver is the only thing that knows the specific field names, URLs, and rules of a given authority — so your code stays the same everywhere.

---

## 5. Installation

Install via Composer:

```bash
composer require nosh/omnitax
```

Publish the configuration file:

```bash
php artisan vendor:publish --provider="Nosh\OmniTax\OmniTaxServiceProvider" --tag="omnitax-config"
```

Publish and run the migrations (they create the invoice, line‑item, credential, and reference‑data tables):

```bash
php artisan vendor:publish --provider="Nosh\OmniTax\OmniTaxServiceProvider" --tag="omnitax-migrations"
php artisan migrate
```

That's it — the package is wired into Laravel.

---

## 6. Configuration

### 6a. Single business (simplest)

If your app serves **one** restaurant, put its details in `.env`:

```dotenv
# Which authority this business reports to: fbr | pra | srb | kpra | bra
FISCAL_AUTHORITY=fbr

# Sandbox (testing) or live production
FISCAL_SANDBOX=true

# Transport: how the package reaches the authority.
#   mock = an in-package fake authority that returns spec-shaped responses,
#          so you can build & test the whole flow with NO token and NO network.
#   http = real HTTPS calls to the authority (needs a real token).
# Develop against 'mock', then flip to 'http' when your token arrives — no code changes.
FISCAL_TRANSPORT=http

# The security token issued to you by the authority (see §7)
FISCAL_TOKEN=your_authority_token_here

# The registered business ("seller") identity printed on every invoice
FISCAL_SELLER_NTNCNIC=0786909
FISCAL_SELLER_NAME="Karachi Grill House"
FISCAL_SELLER_PROVINCE="Sindh"
FISCAL_SELLER_ADDRESS="Shahrah-e-Faisal, Karachi"

# Reliability
FISCAL_TIMEOUT=30
FISCAL_RETRY_ATTEMPTS=3

# Logging
FISCAL_LOGGING_ENABLED=true
FISCAL_LOG_CHANNEL=daily
```

### 6b. The config file

`config/omnitax.php` exposes everything, with one block per authority:

```php
return [

    // Default authority when a business hasn't specified one.
    'default' => env('FISCAL_AUTHORITY', 'fbr'),

    // Global sandbox switch (per‑tenant overrides are possible — see §13).
    'sandbox' => env('FISCAL_SANDBOX', true),

    // Transport: 'http' = real HTTPS to the authority; 'mock' = in‑package fake
    // authority (spec‑shaped responses, no token/network) for local dev & tests.
    'transport' => env('FISCAL_TRANSPORT', 'http'),

    // The "seller" identity for single‑business installs (see §6a).
    'seller' => [
        'ntncnic'  => env('FISCAL_SELLER_NTNCNIC'),
        'name'     => env('FISCAL_SELLER_NAME'),
        'province' => env('FISCAL_SELLER_PROVINCE'),
        'address'  => env('FISCAL_SELLER_ADDRESS'),
    ],

    // How per‑business credentials are resolved (§13).
    // 'env'      → use the .env values above (single business)
    // 'database' → look them up per tenant from the fiscal_credentials table
    // 'callback' → resolve from your own store via OmniTax::resolveCredentialsUsing()
    'credentials' => [
        'driver' => env('FISCAL_CREDENTIALS_DRIVER', 'env'),
        'encrypt' => true, // tokens are encrypted at rest
    ],

    // Reliability & QR
    'timeout'        => env('FISCAL_TIMEOUT', 30),
    'retry_attempts' => env('FISCAL_RETRY_ATTEMPTS', 3),
    'queue'          => env('FISCAL_QUEUE', 'fiscal-invoices'),

    'qr_code' => [
        'version'    => '2.0',
        'size'       => '25x25',
        'dimensions' => '1.0x1.0 Inch',
        'render'     => true, // let the package produce a PNG/SVG for you
    ],

    'logging' => [
        'enabled' => env('FISCAL_LOGGING_ENABLED', true),
        'channel' => env('FISCAL_LOG_CHANNEL', 'daily'),
        'level'   => env('FISCAL_LOG_LEVEL', 'info'),
    ],

    // One block per authority. Endpoints are pre‑filled from each authority's
    // official spec; you normally only supply the token (via env or database).
    'authorities' => [

        'fbr' => [
            'label' => 'FBR (Federal / PRAL)',
            'token' => env('FISCAL_TOKEN'),
            'urls'  => [
                // Same host for sandbox & production — routing is by token.
                'submit'    => 'https://gw.fbr.gov.pk/di_data/v1/di/postinvoicedata',
                'submit_sb' => 'https://gw.fbr.gov.pk/di_data/v1/di/postinvoicedata_sb',
                'validate'    => 'https://gw.fbr.gov.pk/di_data/v1/di/validateinvoicedata',
                'validate_sb' => 'https://gw.fbr.gov.pk/di_data/v1/di/validateinvoicedata_sb',
                'reference' => 'https://gw.fbr.gov.pk/pdi/v1/',
                'statl'     => 'https://gw.fbr.gov.pk/dist/v1/',
            ],
        ],

        // Provincial authorities share the same driver contract; their
        // endpoints are filled in as each one is enabled.
        'pra'  => ['label' => 'Punjab Revenue Authority',        'token' => env('FISCAL_PRA_TOKEN')],
        'srb'  => ['label' => 'Sindh Revenue Board',             'token' => env('FISCAL_SRB_TOKEN')],
        'kpra' => ['label' => 'KP Revenue Authority',            'token' => env('FISCAL_KPRA_TOKEN')],
        'bra'  => ['label' => 'Balochistan Revenue Authority',   'token' => env('FISCAL_BRA_TOKEN')],
    ],
];
```

You typically **only ever set a token**. Everything else has sensible defaults.

---

## 7. Before you can go live: registering with your authority

Fiscal invoicing is a regulated service — you need an account and a **security token** from your authority before real submissions work:

1. **Register your business** on your authority's digital‑invoicing portal (for FBR this is the IRIS / PRAL Digital Invoicing portal).
2. Request a **Digital Invoicing security token**. For FBR this token is **valid for 5 years** and is sent in the `Authorization: Bearer …` header of every request. You renew it when it expires.
3. Put the token in `.env` (`FISCAL_TOKEN=…`) or, for multi‑tenant, in the credentials table (§13).
4. Start in **sandbox** (`FISCAL_SANDBOX=true`) and run the built‑in scenarios (§15). When you're satisfied, flip to production.

> **You do not need a token to build and test your integration.** The package ships a full mocked mode — set `FISCAL_TRANSPORT=mock` (see §6) and every `submit()`/`validate()` returns spec‑shaped responses with no token and no network, so you can develop the whole flow first. Set `FISCAL_TRANSPORT=http` and drop in the real token when it arrives — no code changes.

---

## 8. Quick start — your first invoice

Here's a complete **restaurant dine‑in** bill: one table, two line items, reported and printed. (Notice a restaurant sale is a **service** — `saleType: "Services"`.)

```php
use Nosh\OmniTax\Facades\OmniTax;
use Nosh\OmniTax\Builders\InvoiceBuilder;
use Nosh\OmniTax\Builders\LineItemBuilder;

// 1. Build the two things the customer ordered.
$karahi = (new LineItemBuilder())
    ->description('Chicken Karahi (Full)')
    ->quantity(1)
    ->unitPrice(1800.00)      // price excluding tax
    ->taxRate('16%')          // provincial services rate (example)
    ->saleType('Services')
    ->build();

$naan = (new LineItemBuilder())
    ->description('Garlic Naan')
    ->quantity(4)
    ->unitPrice(80.00)
    ->taxRate('16%')
    ->saleType('Services')
    ->build();

// 2. Build the invoice for this table.
$invoice = (new InvoiceBuilder())
    ->type('Sale Invoice')
    ->date(now())
    // Seller can be omitted if set in config (§6a); shown here for clarity.
    ->seller('0786909', 'Karachi Grill House', 'Sindh', 'Karachi')
    // Walk‑in customer → unregistered buyer, no NTN needed.
    ->walkInCustomer()
    ->addItem($karahi)
    ->addItem($naan)
    ->build();

// 3. Submit to the authority in real time.
$response = OmniTax::submit($invoice);

if ($response->isValid()) {
    $fiscalNumber = $response->invoiceNumber();   // e.g. "7000007DI1747119701593"
    $qr           = $response->qr();              // ready to print (see §11)
    // → print the receipt with $fiscalNumber, $qr, and the authority logo
} else {
    foreach ($response->errors() as $error) {
        report("Fiscal rejection: {$error}");     // e.g. "0052 – Invalid HS Code"
    }
}
```

**Validate first (optional but recommended)** — a dry run that checks the invoice without committing it:

```php
$check = OmniTax::validate($invoice);

if ($check->isValid()) {
    $response = OmniTax::submit($invoice);
}
```

**Choosing the authority explicitly** (otherwise the default/tenant authority is used):

```php
$response = OmniTax::authority('pra')->submit($invoice);   // report to Punjab
```

---

## 9. The canonical invoice

You always build the same neutral structure; the driver maps it to the authority's exact field names.

**Invoice (header):**

| Field | Meaning | Notes |
|---|---|---|
| `type` | `Sale Invoice` or `Debit Note` | |
| `date` | Date of issuance | |
| `seller` | Your registered identity | NTN/CNIC, name, province, address — usually from config |
| `buyer` | The customer | Optional for walk‑ins (`walkInCustomer()`) |
| `buyerRegistrationType` | `Registered` / `Unregistered` | Set automatically by the builder |
| `invoiceRefNo` | Original invoice ref | Required only for debit notes |
| `items[]` | The lines | See below |

**Line item:**

| Field | Meaning |
|---|---|
| `description` | The dish / product / service |
| `quantity` | How many |
| `unitPrice` / `valueExcludingTax` | Value before tax |
| `taxRate` | e.g. `"16%"` |
| `taxAmount` | Computed for you if you don't pass it |
| `saleType` | `"Services"` for restaurants; other types for goods |
| `hsCode` | Product/service code (required by FBR for goods) |
| `uom` | Unit of measure |
| *plus* | `furtherTax`, `extraTax`, `discount`, `fedPayable`, `sroScheduleNo`, `sroItemSerialNo` — all optional |

The builder computes totals and fills authority‑specific defaults so you don't have to remember every field for every authority.

---

## 10. Reading the response

Every `submit()` / `validate()` returns a `FiscalResponse`:

```php
$response->isValid();          // true when the authority accepted it
$response->invoiceNumber();    // the official fiscal number (null if rejected)
$response->dated();            // authority timestamp
$response->qr();               // QR payload / image (see §11)
$response->errors();           // array of human‑readable errors
$response->itemStatuses();     // per‑line accept/reject detail
$response->raw();              // the authority's raw JSON, for your audit log
```

A rejection tells you exactly which line and why (e.g. `itemSNo 1 → "0046 – Provide rate."`), so you can fix and resubmit.

---

## 11. Printing the receipt: QR code & logo

Authorities require **two marks** on the printed invoice: the official **QR code** and the **digital‑invoicing logo**.

```php
// After a successful submit:
$qr = $response->qr();

$qr->payload();   // the string encoded in the QR (the fiscal invoice number)
$qr->png();       // binary PNG at the required size
$qr->svg();       // scalable SVG
$qr->dataUri();   // inline <img src="…"> for HTML/thermal receipts

// The authority logo to place beside it:
OmniTax::logo('fbr')->png();
```

The package renders the QR to the official spec automatically (**Version 2.0, 25×25, 1.0″×1.0″** for FBR). Drop `$qr->dataUri()` and the logo into your receipt template and you're compliant.

---

## 12. Submitting in the background (recommended)

At a busy restaurant you don't want billing to wait on a government server. Persist the invoice and submit it on a queue — the package tracks status and retries safely.

```php
use Nosh\OmniTax\Models\FiscalInvoice;
use Nosh\OmniTax\Jobs\SubmitFiscalInvoice;

// Save first (status: pending), then submit asynchronously.
$record = FiscalInvoice::fromInvoice($invoice);   // your canonical invoice
SubmitFiscalInvoice::dispatch($record);           // onto the 'fiscal-invoices' queue

// Later / elsewhere:
$record->refresh();
$record->status();          // pending | submitted | valid | failed
$record->fiscalNumber();    // once accepted
$record->qr();
```

Run a worker for the dedicated queue:

```bash
php artisan queue:work --queue=fiscal-invoices
```

**Idempotency:** each invoice carries a stable key, so a retry (or a double‑click at the till) **never reports the same sale twice**. Failed jobs back off and retry up to `FISCAL_RETRY_ATTEMPTS`; server (5xx) errors are retried, client (4xx) errors are not.

---

## 13. Multi‑tenant: one install, many restaurants

If your platform hosts **many** restaurants, each has its own authority, token, and seller identity. Switch the credentials driver to `database`:

```dotenv
FISCAL_CREDENTIALS_DRIVER=database
```

Store each restaurant's connection (tokens are **encrypted at rest**):

```php
use Nosh\OmniTax\Models\FiscalCredential;

FiscalCredential::updateOrCreate(
    ['tenant_id' => $restaurant->id],
    [
        'authority'      => 'pra',              // Punjab restaurant
        'sandbox'        => false,
        'token'          => $restaurant->pra_token,   // encrypted automatically
        'seller_ntncnic' => $restaurant->ntn,
        'seller_name'    => $restaurant->legal_name,
        'seller_province'=> 'Punjab',
        'seller_address' => $restaurant->address,
    ],
);
```

Then scope every call to that restaurant — the package loads the right authority, token, and seller for you:

```php
$response = OmniTax::for($restaurant)->submit($invoice);
```

This is the **"enable it per restaurant"** model: a business that hasn't opted in simply has no `FiscalCredential`, and fiscal invoicing stays dormant for them. Turning it on is one row.

You can also resolve credentials from your own store with a closure (e.g. if they live in another service):

```php
// In a service provider
OmniTax::resolveCredentialsUsing(function ($tenantId) {
    return MyBillingService::fiscalCredentialsFor($tenantId); // returns a FiscalCredential
});
```

### 13a. Where each merchant enters their credentials

The package owns **storage, encryption, and lookup** of credentials — it does **not** ship a screen for typing them in. That UI is yours to build, because it belongs in your app next to the rest of a merchant's account settings. The division of labour is:

| Concern | Owned by |
|---|---|
| A **settings form** where a merchant enters their token + business details | **Your app** (you build one small page) |
| Encrypting the token, storing the row, resolving it at submit time | **The package** |

So a production rollout is just: build a **"Fiscal Invoicing Settings"** page in your app with these fields, and on save write one `FiscalCredential` row:

- **Authority** — `fbr` / `pra` / `srb` / `kpra` / `bra` (usually derived from the merchant's province)
- **Security token** — the PRAL/authority token they were issued (§7)
- **Sandbox?** — on while they test, off when they go live
- **Seller identity** — NTN/CNIC, business name, province, address (printed on every invoice)

```php
// Your settings controller, on save:
FiscalCredential::updateOrCreate(
    ['tenant_id' => $merchant->auth_uuid, 'authority' => $request->authority],
    [
        'sandbox'         => $request->boolean('sandbox'),
        'token'           => $request->token,           // encrypted automatically
        'seller_ntncnic'  => $request->ntn,
        'seller_name'     => $request->business_name,
        'seller_province' => $request->province,
        'seller_address'  => $request->address,
    ],
);
```

Key it on the merchant's **stable tenant id** (in Nosh, the Auth tenant UUID) — the same id you later pass to `OmniTax::for($tenantId)`. A merchant who hasn't filled the form yet has no row, so fiscal invoicing stays dormant for them until they onboard. No secret ever goes in `.env` or config in this model — only in the encrypted `fiscal_credentials` table (or your own store, via the closure above).

---

## 14. Reference data (provinces, units, HS codes…)

Authorities publish lookup lists (province codes, units of measure, HS codes, tax rates, SRO items). The package fetches, caches, and exposes them:

```php
OmniTax::provinces();     // [['code' => 7, 'name' => 'PUNJAB'], ['code' => 8, 'name' => 'SINDH'], …]
OmniTax::units();         // units of measure
OmniTax::hsCodes();       // harmonised system codes
OmniTax::taxRates();      // rate list
OmniTax::checkStatl($ntn, $date);   // is a party on the STATL blacklist?
```

Keep them fresh with a scheduled sync (cached 30 days by default):

```bash
php artisan fiscal:sync                 # all reference data, default authority
php artisan fiscal:sync --type=provinces
php artisan fiscal:sync --authority=pra --force
```

---

## 15. Sandbox testing & scenarios

Before going live you validate against your authority's **sandbox** using official **test scenarios**. For FBR these are `SN001`–`SN028`. The one that matters for a **restaurant** is:

- **`SN019` — Sale of Services** → `saleType: "Services"` (normal dine‑in)
- **`SN018` — Services where FED is charged in ST mode** (when applicable)

```php
use Nosh\OmniTax\Testing\Scenario;

// Build a ready‑made, valid sandbox invoice for a scenario:
$invoice = Scenario::make('SN019');       // a services (restaurant) sale
$response = OmniTax::sandbox()->submit($invoice);

// Discover which scenarios apply to a business type:
Scenario::forBusinessActivity('services');   // ['SN018', 'SN019', …]
```

In sandbox the package automatically attaches the required `scenarioId`; in production it's omitted.

---

## 16. Events

Hook into the lifecycle without touching the package:

```php
use Nosh\OmniTax\Events\{InvoiceSubmitting, InvoiceAccepted, InvoiceRejected};

Event::listen(InvoiceAccepted::class, function (InvoiceAccepted $e) {
    // $e->invoice, $e->response->invoiceNumber() — e.g. email the customer, update the KDS
});
```

| Event | Fires when |
|---|---|
| `InvoiceSubmitting` | just before a call to the authority |
| `InvoiceAccepted` | the authority accepted the invoice |
| `InvoiceRejected` | the authority rejected it (with errors) |
| `CredentialsMissing` | a tenant tried to submit with no fiscal credentials |

---

## 17. Artisan commands

```bash
php artisan fiscal:sync [--type=] [--authority=] [--force]   # refresh reference data
php artisan fiscal:submit-pending [--authority=]             # push any pending invoices
php artisan fiscal:retry-failed                              # retry failed submissions
php artisan fiscal:status {invoice}                          # inspect one invoice
php artisan fiscal:token:check                               # verify a token is valid/among us
```

---

## 18. Error handling

```php
use Nosh\OmniTax\Exceptions\FiscalException;

try {
    $response = OmniTax::for($restaurant)->submit($invoice);
} catch (FiscalException $e) {
    // Transport/auth problems (network, 401, 500)
    Log::error("Fiscal transport error: {$e->getMessage()}", ['status' => $e->getCode()]);
}

// Business rejections don't throw — they come back on the response:
if (! $response->isValid()) {
    foreach ($response->errors() as $error) { /* show/fix */ }
}
```

**Common authority error codes** (surfaced verbatim):

| Code | Meaning |
|---|---|
| `0001` | Seller not registered for sales tax |
| `0002` | Invalid buyer registration / NTN |
| `0005` | Invalid date format |
| `0046` | Missing rate |
| `0052` | Invalid HS code |
| `0401` | Unauthorized seller access |
| `0402` | Unauthorized buyer access |

HTTP: `200` OK · `401` Unauthorized (bad/expired token) · `500` authority‑side error (retried automatically).

---

## 19. Going to production — checklist

- [ ] Real **token** in place (`.env` or `fiscal_credentials`)
- [ ] `FISCAL_SANDBOX=false` (or per‑tenant `sandbox = false`)
- [ ] Sandbox scenarios pass for your business activity (§15)
- [ ] A **supervised queue worker** runs for `fiscal-invoices`
- [ ] **Failed‑job** monitoring in place (`fiscal:retry-failed` scheduled)
- [ ] Reference data sync scheduled (`fiscal:sync`)
- [ ] Receipt template prints the **QR + authority logo** (§11)
- [ ] Log channel reviewed; tokens are encrypted at rest

---

## 20. Extending: add a new authority (future‑global)

Every authority is just a driver implementing one interface:

```php
interface FiscalAuthorityDriver
{
    public function validate(Invoice $invoice): FiscalResponse;
    public function submit(Invoice $invoice): FiscalResponse;
    public function reference(string $type): array;
}
```

To add one (a new province, or a new country such as **KSA ZATCA** or **Malaysia MyInvois**):

1. Create a driver that maps the **canonical invoice** to that authority's payload and parses its response into a `FiscalResponse`.
2. Register it and add a config block with its endpoints.
3. Done — **every calling application uses it through the exact same API** (`OmniTax::authority('zatca')->submit($invoice)`).

Because the invoice your app builds is authority‑neutral, going international never changes your billing code — only a driver is added. This is the seam that makes the package global‑ready from day one.

---

## 21. FAQ

**Do I need a token to start building?**
No. Develop and test the whole flow in sandbox/mock mode, then drop the real token in when it arrives.

**My restaurant is in Punjab — do I use FBR?**
No. Dine‑in is a provincial service → use **PRA**. Set `FISCAL_AUTHORITY=pra` (or the tenant's `authority`). FBR is for ICT/Islamabad and goods.

**Can one platform serve restaurants in different provinces?**
Yes — that's the multi‑tenant model (§13). Each restaurant's `FiscalCredential` points at its own authority.

**Will this work outside Pakistan later?**
Yes by design — a new country is a new driver (§20); your integration code doesn't change.

**What happens if the government API is down mid‑service?**
Invoices queue as `pending`, retry with backoff, and are never double‑reported. Billing at the till isn't blocked.

---

## 22. Support & license

- **Package:** `nosh/omnitax`
- **Namespace:** `Nosh\OmniTax`
- **Maintained by:** Nosh (a RapidFlow product)
- **License:** MIT — see the `LICENSE` file. Free to use, modify, and distribute.
- **Install:** `composer require nosh/omnitax`

For integration help, sandbox onboarding, or enabling a new authority, contact the Nosh team.
