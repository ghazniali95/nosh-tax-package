<?php

/**
 * DEMO — Nosh Fiscal Invoicing (no token, no network, no framework needed)
 *
 *   php demo.php
 *
 * Walks a team lead through a real restaurant bill: what we build, the EXACT
 * JSON we send FBR, and the official fiscal number + QR that comes back — all
 * against the in-package mock authority so it runs anywhere, instantly.
 */

$src = __DIR__.'/src';
foreach ([
    'Data/Seller', 'Data/Buyer', 'Data/LineItem', 'Data/Invoice', 'Data/Credentials',
    'Builders/LineItemBuilder', 'Builders/InvoiceBuilder',
    'Responses/ItemStatus', 'Support/Qr/QrCode', 'Responses/FiscalResponse',
    'Contracts/Transport', 'Contracts/FiscalAuthorityDriver',
    'Drivers/AbstractDriver', 'Drivers/FbrDriver', 'Transport/MockTransport',
] as $class) {
    require $src.'/'.$class.'.php';
}

use Nosh\FiscalInvoicing\Builders\InvoiceBuilder;
use Nosh\FiscalInvoicing\Builders\LineItemBuilder;
use Nosh\FiscalInvoicing\Data\Credentials;
use Nosh\FiscalInvoicing\Data\Seller;
use Nosh\FiscalInvoicing\Drivers\FbrDriver;
use Nosh\FiscalInvoicing\Transport\MockTransport;

function line(string $c = '─'): void { echo str_repeat($c, 64)."\n"; }
function title(string $t): void { echo "\n"; line('═'); echo "  $t\n"; line('═'); }

title('NOSH FISCAL INVOICING — LIVE DEMO (sandbox / mock)');
echo "  Restaurant: Karachi Grill House   |   Authority: FBR\n";
echo "  Mode: sandbox mock (no token needed — real FBR is a 1-line switch)\n";

// ── 1. The waiter's bill, built in plain code ────────────────────────────
title('STEP 1 — The bill the POS builds');

$invoice = (new InvoiceBuilder())
    ->type('Sale Invoice')->date(date('Y-m-d'))
    ->seller('0786909', 'Karachi Grill House', 'Sindh', 'Karachi')
    ->walkInCustomer('Sindh')
    ->scenario('SN019') // FBR sandbox scenario: Sale of Services
    ->addItem((new LineItemBuilder())
        ->description('Chicken Karahi (Full)')->quantity(1)->unitPrice(1800.00)
        ->taxRate('16%')->saleType('Services')->uom('Numbers, pieces, units'))
    ->addItem((new LineItemBuilder())
        ->description('Garlic Naan')->quantity(4)->unitPrice(80.00)
        ->taxRate('16%')->saleType('Services')->uom('Numbers, pieces, units'))
    ->build();

foreach ($invoice->items as $i) {
    printf("  %-24s x%-3d  Rs %8s  (+16%% tax Rs %s)\n",
        $i->description, $i->quantity,
        number_format($i->valueExcludingTax(), 2),
        number_format($i->computedTaxAmount(), 2));
}
line();
printf("  Subtotal (excl tax) : Rs %s\n", number_format($invoice->subtotalExcludingTax(), 2));
printf("  Sales tax           : Rs %s\n", number_format($invoice->totalTax(), 2));
printf("  GRAND TOTAL         : Rs %s\n", number_format($invoice->grandTotal(), 2));

// ── 2. Exactly what we send FBR ──────────────────────────────────────────
title('STEP 2 — The exact JSON we send FBR (postinvoicedata)');

$creds = new Credentials('fbr', token: 'DEMO-SANDBOX-TOKEN', sandbox: true,
    seller: new Seller('0786909', 'Karachi Grill House', 'Sindh', 'Karachi'));
$driver = new FbrDriver($creds, [
    'urls' => ['submit_sb' => 'https://gw.fbr.gov.pk/di_data/v1/di/postinvoicedata_sb'],
], new MockTransport());

echo json_encode($driver->mapInvoice($invoice), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";

// ── 3. FBR's answer ──────────────────────────────────────────────────────
title('STEP 3 — FBR accepts it → official fiscal invoice number');

$response = $driver->submit($invoice);

echo '  Accepted?          : '.($response->isValid() ? 'YES ✓' : 'NO ✗')."\n";
echo '  Fiscal invoice no. : '.$response->invoiceNumber()."\n";
echo '  Status             : '.$response->status().' ('.$response->statusCode().")\n";
echo '  Dated              : '.$response->dated()."\n";
echo '  QR encodes         : '.$response->qr()->payload()."\n";
echo "\n  → The receipt prints this number + a QR of it + the FBR logo.\n";

// ── 4. Prove the guard rails ─────────────────────────────────────────────
title('STEP 4 — It also catches bad invoices (a line with no tax rate)');

$bad = (new InvoiceBuilder())
    ->seller('0786909', 'Karachi Grill House', 'Sindh', 'Karachi')->walkInCustomer('Sindh')
    ->addItem((new LineItemBuilder())->description('Untaxed item')->quantity(1)->unitPrice(500)->saleType('Services'))
    ->build();
$badResp = $driver->submit($bad);
echo '  Accepted?          : '.($badResp->isValid() ? 'YES' : 'NO ✗ (correctly rejected)')."\n";
echo '  FBR error          : '.implode('; ', $badResp->errors())."\n";

title('DEMO COMPLETE');
echo "  Same code talks to the REAL FBR sandbox the moment a PRAL token exists.\n";
echo "  Provinces (PRA/SRB/KPRA/BRA) plug in as new drivers — nothing above changes.\n\n";
