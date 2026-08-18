<?php

/**
 * Standalone verification — proves the FBR driver + mock authority work
 * end-to-end WITHOUT Laravel, Composer autoload, or a token.
 *
 *   php tests/verify.php
 *
 * It requires the pure-PHP core directly (no framework), builds a restaurant
 * invoice, maps it to FBR's payload, runs it through the in-package mock
 * authority, and asserts the parsed response.
 */

$src = dirname(__DIR__).'/src';

require $src.'/Data/Seller.php';
require $src.'/Data/Buyer.php';
require $src.'/Data/LineItem.php';
require $src.'/Data/Invoice.php';
require $src.'/Data/Credentials.php';
require $src.'/Builders/LineItemBuilder.php';
require $src.'/Builders/InvoiceBuilder.php';
require $src.'/Responses/ItemStatus.php';
require $src.'/Support/Qr/QrCode.php';
require $src.'/Responses/FiscalResponse.php';
require $src.'/Contracts/Transport.php';
require $src.'/Contracts/FiscalAuthorityDriver.php';
require $src.'/Drivers/AbstractDriver.php';
require $src.'/Drivers/FbrDriver.php';
require $src.'/Transport/MockTransport.php';

use Nosh\OmniTax\Builders\InvoiceBuilder;
use Nosh\OmniTax\Builders\LineItemBuilder;
use Nosh\OmniTax\Data\Credentials;
use Nosh\OmniTax\Data\Seller;
use Nosh\OmniTax\Drivers\FbrDriver;
use Nosh\OmniTax\Transport\MockTransport;

$pass = 0;
$fail = 0;
function check(string $label, bool $cond): void
{
    global $pass, $fail;
    if ($cond) { $pass++; echo "  ✓ {$label}\n"; }
    else       { $fail++; echo "  ✗ {$label}\n"; }
}

$fbrConfig = [
    'urls' => [
        'submit_sb'   => 'https://gw.fbr.gov.pk/di_data/v1/di/postinvoicedata_sb',
        'validate_sb' => 'https://gw.fbr.gov.pk/di_data/v1/di/validateinvoicedata_sb',
    ],
];

echo "\n== 1. Build a restaurant dine-in bill ==\n";

$invoice = (new InvoiceBuilder())
    ->type('Sale Invoice')
    ->date('2026-08-18')
    ->seller('0786909', 'Karachi Grill House', 'Sindh', 'Karachi')
    ->walkInCustomer('Sindh')
    ->scenario('SN019')
    ->addItem((new LineItemBuilder())
        ->description('Chicken Karahi (Full)')->quantity(1)->unitPrice(1800.00)
        ->taxRate('16%')->saleType('Services')->uom('Numbers, pieces, units'))
    ->addItem((new LineItemBuilder())
        ->description('Garlic Naan')->quantity(4)->unitPrice(80.00)
        ->taxRate('16%')->saleType('Services')->uom('Numbers, pieces, units'))
    ->build();

check('two line items', count($invoice->items) === 2);
check('subtotal excl tax = 1800 + 320 = 2120', $invoice->subtotalExcludingTax() === 2120.00);
check('tax @16% = 288 + 51.2 = 339.2', $invoice->totalTax() === 339.20);
check('grand total = 2459.2', $invoice->grandTotal() === 2459.20);
check('stable idempotency key', strlen($invoice->key()) === 64);

echo "\n== 2. Map canonical invoice -> FBR payload ==\n";

$creds = new Credentials('fbr', token: 'mock-token', sandbox: true, seller: new Seller('0786909','Karachi Grill House','Sindh','Karachi'));
$driver = new FbrDriver($creds, $fbrConfig, new MockTransport());
$payload = $driver->mapInvoice($invoice);

check('sellerNTNCNIC mapped', $payload['sellerNTNCNIC'] === '0786909');
check('buyerRegistrationType Unregistered', $payload['buyerRegistrationType'] === 'Unregistered');
check('scenarioId present in sandbox', ($payload['scenarioId'] ?? null) === 'SN019');
check('item uses FBR key valueSalesExcludingST', $payload['items'][0]['valueSalesExcludingST'] === 1800.00);
check('item rate mapped to "16%"', $payload['items'][0]['rate'] === '16%');
check('item salesTaxApplicable = 288', $payload['items'][0]['salesTaxApplicable'] === 288.00);
check('item productDescription mapped', $payload['items'][0]['productDescription'] === 'Chicken Karahi (Full)');

echo "\n== 3. Submit through the mock authority (valid) ==\n";

$response = $driver->submit($invoice);
check('response isValid', $response->isValid());
check('got a fiscal invoice number', $response->invoiceNumber() !== null);
check('fiscal number shaped like FBR (…DI…)', str_contains((string) $response->invoiceNumber(), 'DI'));
check('statusCode 00', $response->statusCode() === '00');
check('two per-item statuses', count($response->itemStatuses()) === 2);
check('QR payload equals fiscal number', $response->qr()?->payload() === $response->invoiceNumber());

echo "\n== 4. Rejection path: a line missing its rate ==\n";

$bad = (new InvoiceBuilder())
    ->seller('0786909','Karachi Grill House','Sindh','Karachi')->walkInCustomer('Sindh')
    ->addItem((new LineItemBuilder())->description('Mystery Item')->quantity(1)->unitPrice(500)->saleType('Services'))
    ->build();
$badResp = $driver->submit($bad);
check('invalid invoice rejected', ! $badResp->isValid());
check('error mentions rate (0046)', str_contains(implode(' ', $badResp->errors()), '0046'));
check('no fiscal number on rejection', $badResp->invoiceNumber() === null);

echo "\n== 5. Unauthorized: no token -> 401 ==\n";

$noTokenDriver = new FbrDriver(new Credentials('fbr', token: '', sandbox: true), $fbrConfig, new MockTransport());
$unauth = $noTokenDriver->submit($invoice);
check('401 surfaced as invalid', ! $unauth->isValid());
check('http status 401', $unauth->httpStatus() === 401);
check('unauthorized error present', str_contains(implode(' ', $unauth->errors()), '0401'));

echo "\n== 6. Validate() dry-run returns no fiscal number ==\n";

$check = $driver->validate($invoice);
check('validate is valid', $check->isValid());
check('validate assigns no fiscal number', $check->invoiceNumber() === null);

echo "\n----------------------------------------\n";
echo "PASSED: {$pass}   FAILED: {$fail}\n";
exit($fail === 0 ? 0 : 1);
