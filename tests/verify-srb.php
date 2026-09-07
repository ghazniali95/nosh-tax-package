<?php

/**
 * Standalone verification — proves the SRB driver + mock authority work
 * end-to-end WITHOUT Laravel, Composer autoload, a POS ID, or a network.
 *
 *   php tests/verify-srb.php
 *
 * It exercises BOTH integration modes: cloud (online / website, hitting the SRB
 * gateway with posUser/posPass in the body) and offline (desktop app, hitting
 * the local SRB POS Connector). Same driver, same canonical invoice.
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
require $src.'/Drivers/SrbDriver.php';
require $src.'/Transport/SrbMockTransport.php';

use Nosh\OmniTax\Builders\InvoiceBuilder;
use Nosh\OmniTax\Builders\LineItemBuilder;
use Nosh\OmniTax\Data\Credentials;
use Nosh\OmniTax\Data\Seller;
use Nosh\OmniTax\Drivers\SrbDriver;
use Nosh\OmniTax\Transport\SrbMockTransport;

$pass = 0;
$fail = 0;
function check(string $label, bool $cond): void
{
    global $pass, $fail;
    if ($cond) { $pass++; echo "  ✓ {$label}\n"; }
    else       { $fail++; echo "  ✗ {$label}\n"; }
}

$srbConfig = [
    'mode' => 'cloud',
    'urls' => [
        'cloud'   => 'https://pos.srb.gos.pk/ePOSGateway/v1/SalesInvoiceService.api',
        'offline' => 'http://localhost:8282/pos/SalesInvoiceServices',
    ],
];

/** Build a cloud-mode driver over a fresh mock. */
function cloudDriver(array $cfg, ?SrbMockTransport $mock = null): array
{
    $mock ??= new SrbMockTransport();
    $creds = new Credentials(
        authority: 'srb', sandbox: true,
        seller: new Seller('8000001', 'HEAD OFFICE', 'Sindh', 'Karachi'),
        posId: '331', posUser: 'srbUser', posPass: 'srbPass', mode: 'cloud',
    );

    return [new SrbDriver($creds, $cfg, $mock), $mock];
}

$karahi = (new LineItemBuilder())->description('Chicken Karahi (Full)')->quantity(1)->unitPrice(1800.00)->taxRate('16%')->saleType('Services');
$naan   = (new LineItemBuilder())->description('Garlic Naan')->quantity(4)->unitPrice(80.00)->taxRate('16%')->saleType('Services');

$invoice = (new InvoiceBuilder())
    ->type('Sale Invoice')->date('2025-12-26')->at('2025-12-26 23:58:58')
    ->seller('8000001', 'HEAD OFFICE', 'Sindh', 'Karachi')
    ->walkInCustomer('Sindh')->number('PZ010')->modeOfPay('Cash')
    ->addItem($karahi)->addItem($naan)
    ->build();

echo "== 1. Map a restaurant dine-in bill -> SRB payload (cloud) ==\n";
[$driver, $mock] = cloudDriver($srbConfig);
[$payload, $errors] = $driver->buildPayload($invoice);
check('no local validation errors', $errors === []);
check('posId is integer 331', $payload['posId'] === 331);
check('ntn mapped', $payload['ntn'] === '8000001');
check('invoiceId = PZ010', $payload['invoiceId'] === 'PZ010');
check('invoiceType 1 (normal)', $payload['invoiceType'] === 1);
check('invoiceDateTime format', $payload['invoiceDateTime'] === '2025-12-26 23:58:58');
check('rateValue 16', (float) $payload['rateValue'] === 16.0);
check('saleValue = 1800 + 320 = 2120', (float) $payload['saleValue'] === 2120.0);
check('taxAmount @16% = 339.2', (float) $payload['taxAmount'] === 339.2);
check('netAmount = 2459.2', (float) $payload['netAmount'] === 2459.2);
check('transType Test in sandbox', $payload['transType'] === 'Test');
check('cloud payload carries posUser', ($payload['posUser'] ?? null) === 'srbUser');
check('cloud payload carries posPass', ($payload['posPass'] ?? null) === 'srbPass');
check('walk-in consumerName -> N/A', $payload['consumerName'] === 'N/A');

echo "\n== 2. Submit through the mock SRB authority (cloud, valid) ==\n";
$res = $driver->submit($invoice);
check('response isValid', $res->isValid());
check('resCode 00', $res->statusCode() === '00');
check('got an SRB invoice number', ! empty($res->invoiceNumber()));
check('QR payload is the verification URL (not the number)',
    str_contains((string) $res->qrPayload(), 'InvoiceVerification')
    && $res->qrPayload() !== $res->invoiceNumber());
check('QR object built from the URL', $res->qr() && $res->qr()->payload() === $res->qrPayload());
check('mock was hit on the CLOUD endpoint', str_contains($mock->recorded[0]['url'], 'ePOSGateway'));

echo "\n== 3. Offline mode: same invoice, localhost Connector, no body creds ==\n";
$offlineMock = new SrbMockTransport();
$offlineCreds = new Credentials(
    authority: 'srb', sandbox: true,
    seller: new Seller('8000001', 'HEAD OFFICE', 'Sindh', 'Karachi'),
    posId: '331', mode: 'offline',   // no posUser/posPass needed offline
);
$offlineDriver = new SrbDriver($offlineCreds, $srbConfig, $offlineMock);
[$offPayload] = $offlineDriver->buildPayload($invoice);
check('mode resolves to offline', $offlineDriver->mode() === 'offline');
check('offline payload omits posUser', ! array_key_exists('posUser', $offPayload));
check('offline payload omits posPass', ! array_key_exists('posPass', $offPayload));
$offRes = $offlineDriver->submit($invoice);
check('offline submit isValid', $offRes->isValid());
check('mock was hit on the LOCALHOST endpoint', str_contains($offlineMock->recorded[0]['url'], 'localhost:8282'));

echo "\n== 4. Cloud mode requires posUser/posPass ==\n";
$noCredMock = new SrbMockTransport();
$noCred = new Credentials(
    authority: 'srb', sandbox: true,
    seller: new Seller('8000001', 'HEAD OFFICE', 'Sindh', 'Karachi'),
    posId: '331', mode: 'cloud',   // missing posUser/posPass
);
$noCredRes = (new SrbDriver($noCred, $srbConfig, $noCredMock))->submit($invoice);
check('missing cloud creds -> invalid', ! $noCredRes->isValid());
check('error mentions credentials', (bool) preg_grep('/posUser|posPass|credential/i', $noCredRes->errors()));
check('no network call was made', $noCredMock->recorded === []);

echo "\n== 5. NTN is sanitised (leading S + hyphen check digit dropped) ==\n";
[$d5] = cloudDriver($srbConfig);
$inv5 = (new InvoiceBuilder())->type('Sale Invoice')->date('2025-12-26')
    ->seller('S1234567-8', 'HEAD OFFICE', 'Sindh', 'Karachi')->walkInCustomer()->number('PZ011')
    ->addItem((new LineItemBuilder())->description('Tea')->quantity(1)->unitPrice(100)->taxRate('15%')->saleType('Services'))
    ->build();
[$p5] = $d5->buildPayload($inv5);
check('"S1234567-8" -> "1234567"', $p5['ntn'] === '1234567');

echo "\n== 6. Tax/net formula with service + extra charges and a discount ==\n";
[$d6, $m6] = cloudDriver($srbConfig);
$inv6 = (new InvoiceBuilder())->type('Sale Invoice')->date('2025-12-26')
    ->seller('8000001', 'HEAD OFFICE', 'Sindh', 'Karachi')->walkInCustomer()->number('PZ012')
    ->serviceCharges(10)->extraCharges(10)->discountAmount(50)
    ->addItem((new LineItemBuilder())->description('Banquet')->quantity(1)->unitPrice(5000)->taxRate('15%')->saleType('Services'))
    ->build();
[$p6] = $d6->buildPayload($inv6);
check('taxAmount = (5000+10+10)*15% = 753', (float) $p6['taxAmount'] === 753.0);
check('netAmount = 5020+753-50 = 5723', (float) $p6['netAmount'] === 5723.0);
check('SRB mock accepts the computed amounts', $d6->submit($inv6)->isValid());

echo "\n== 7. Duplicate invoiceId is rejected on re-submit ==\n";
[$d7, $m7] = cloudDriver($srbConfig);
$mk = fn (string $no) => (new InvoiceBuilder())->type('Sale Invoice')->date('2025-12-26')
    ->seller('8000001', 'HEAD OFFICE', 'Sindh', 'Karachi')->walkInCustomer()->number($no)
    ->addItem((new LineItemBuilder())->description('Tea')->quantity(1)->unitPrice(100)->taxRate('15%')->saleType('Services'))
    ->build();
check('first submit valid', $d7->submit($mk('DUP-1'))->isValid());
$dupRes = $d7->submit($mk('DUP-1'));
check('duplicate submit invalid', ! $dupRes->isValid());
check('error mentions duplicate', (bool) preg_grep('/duplicate/i', $dupRes->errors()));

echo "\n== 8. Sales Return maps to invoiceType 2 ==\n";
[$d8] = cloudDriver($srbConfig);
$inv8 = (new InvoiceBuilder())->type('Sales Return')->date('2025-12-26')
    ->seller('8000001', 'HEAD OFFICE', 'Sindh', 'Karachi')->walkInCustomer()->number('RET-1')
    ->addItem((new LineItemBuilder())->description('Refund item')->quantity(1)->unitPrice(500)->taxRate('15%')->saleType('Services'))
    ->build();
[$p8] = $d8->buildPayload($inv8);
check('invoiceType 2 for a return', $p8['invoiceType'] === 2);

echo "\n== 9. validate() is a local dry-run (no fiscal number) ==\n";
[$d9] = cloudDriver($srbConfig);
$okCheck = $d9->validate($invoice);
check('valid invoice passes local validation', $okCheck->isValid());
check('validate assigns no invoice number', $okCheck->invoiceNumber() === null);
$badInvoice = (new InvoiceBuilder())->type('Sale Invoice')->date('2025-12-26')
    ->seller('8000001', 'HEAD OFFICE', 'Sindh', 'Karachi')->walkInCustomer()->number('BAD-1')
    ->addItem((new LineItemBuilder())->description('No rate item')->quantity(1)->unitPrice(500)->saleType('Services'))
    ->build();
$badCheck = $d9->validate($badInvoice);
check('missing-rate invoice fails validation', ! $badCheck->isValid());

echo "\n== 10. A single tax rate per invoice is enforced ==\n";
[$d10] = cloudDriver($srbConfig);
$mixed = (new InvoiceBuilder())->type('Sale Invoice')->date('2025-12-26')
    ->seller('8000001', 'HEAD OFFICE', 'Sindh', 'Karachi')->walkInCustomer()->number('MIX-1')
    ->addItem((new LineItemBuilder())->description('A')->quantity(1)->unitPrice(500)->taxRate('16%')->saleType('Services'))
    ->addItem((new LineItemBuilder())->description('B')->quantity(1)->unitPrice(500)->taxRate('5%')->saleType('Services'))
    ->build();
[, $mixErrors] = $d10->buildPayload($mixed);
check('mixed rates produce an error', (bool) preg_grep('/single tax rate/i', $mixErrors));

echo "\n== 11. Special characters are stripped from the business name ==\n";
[$d11] = cloudDriver($srbConfig);
$inv11 = (new InvoiceBuilder())->type('Sale Invoice')->date('2025-12-26')
    ->seller('8000001', "Karachi Grill & Co. (Pvt)", 'Sindh', 'Karachi')->walkInCustomer()->number('SC-1')
    ->addItem((new LineItemBuilder())->description('Tea')->quantity(1)->unitPrice(100)->taxRate('15%')->saleType('Services'))
    ->build();
[$p11] = $d11->buildPayload($inv11);
check('"Karachi Grill & Co. (Pvt)" -> "Karachi Grill Co Pvt"', $p11['name'] === 'Karachi Grill Co Pvt');

echo "\n".str_repeat('-', 40)."\n";
echo "PASSED: {$pass}   FAILED: {$fail}\n";
exit($fail === 0 ? 0 : 1);
