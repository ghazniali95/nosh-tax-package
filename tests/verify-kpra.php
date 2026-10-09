<?php

/**
 * Standalone verification — proves the KPRA (RIMS) driver + mock authority work
 * end-to-end WITHOUT Laravel, Composer autoload, a POS ID, or a network.
 *
 *   php tests/verify-kpra.php
 *
 * It exercises BOTH integration modes: cloud (online / website, hitting KPRA's
 * live RIMS API) and offline (desktop app, hitting the local KPRA RIMS utility
 * on localhost:3000). Same driver, same canonical invoice. It also covers the
 * dedicated credit-note endpoint.
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
require $src.'/Support/Feature.php';
require $src.'/Responses/FiscalResponse.php';
require $src.'/Contracts/Transport.php';
require $src.'/Contracts/FiscalAuthorityDriver.php';
require $src.'/Drivers/AbstractDriver.php';
require $src.'/Drivers/KpraDriver.php';
require $src.'/Transport/KpraMockTransport.php';

use Nosh\OmniTax\Builders\InvoiceBuilder;
use Nosh\OmniTax\Builders\LineItemBuilder;
use Nosh\OmniTax\Data\Credentials;
use Nosh\OmniTax\Data\Seller;
use Nosh\OmniTax\Drivers\KpraDriver;
use Nosh\OmniTax\Transport\KpraMockTransport;

$pass = 0;
$fail = 0;
function check(string $label, bool $cond): void
{
    global $pass, $fail;
    if ($cond) { $pass++; echo "  ✓ {$label}\n"; }
    else       { $fail++; echo "  ✗ {$label}\n"; }
}

$kpraConfig = [
    'mode' => 'cloud',
    'urls' => [
        'cloud'       => 'https://kpra.gov.pk/api/rims-integration',
        'offline'     => 'http://localhost:3000/api/invoice',
        'credit_note' => 'https://kpra.gov.pk/api/kpra-credit-note',
        'verify'      => 'https://kpra.gov.pk/api/',
    ],
];

/** Build a cloud-mode driver over a fresh (or shared) mock. */
function cloudDriver(array $cfg, ?KpraMockTransport $mock = null): array
{
    $mock ??= new KpraMockTransport();
    $creds = new Credentials(
        authority: 'kpra', sandbox: true,
        seller: new Seller('K123456', 'HEAD OFFICE', 'Khyber Pakhtunkhwa', 'Peshawar'),
        posId: 'K123456', mode: 'cloud', apiKey: 'KPRA@123a',
    );

    return [new KpraDriver($creds, $cfg, $mock), $mock];
}

$karahi = (new LineItemBuilder())->description('Chicken Karahi (Full)')->quantity(1)->unitPrice(1800.00)->taxRate('16%')->saleType('Services');
$naan   = (new LineItemBuilder())->description('Garlic Naan')->quantity(4)->unitPrice(80.00)->taxRate('16%')->saleType('Services');

$invoice = (new InvoiceBuilder())
    ->type('Sale Invoice')->date('2025-12-26')->at('2025-12-26 23:58:58')
    ->seller('K123456', 'HEAD OFFICE', 'Khyber Pakhtunkhwa', 'Peshawar')
    ->walkInCustomer('Khyber Pakhtunkhwa')->number('INV123')->modeOfPay('Cash')
    ->addItem($karahi)->addItem($naan)
    ->build();

echo "== 1. Map a restaurant dine-in bill -> KPRA payload (cloud) ==\n";
[$driver, $mock] = cloudDriver($kpraConfig);
[$payload, $errors] = $driver->buildPayload($invoice);
check('no local validation errors', $errors === []);
check('ntn mapped', $payload['ntn'] === 'K123456');
check('pos_id in body', $payload['pos_id'] === 'K123456');
check('key in body', $payload['key'] === 'KPRA@123a');
check('invoice_no = INV123', $payload['invoice_no'] === 'INV123');
check('amount = 1800 + 320 = 2120 (tax-exclusive)', (float) $payload['amount'] === 2120.0);
check('tax_rate 16', (float) $payload['tax_rate'] === 16.0);
check('tax_amount @16% = 339.2', (float) $payload['tax_amount'] === 339.2);
check('total_amount = 2459.2 (tax-inclusive)', (float) $payload['total_amount'] === 2459.2);
check('date_time format', $payload['date_time'] === '2025-12-26 23:58:58');
check('payment_mode 1 (Cash)', $payload['payment_mode'] === 1);

echo "\n== 2. Submit through the mock KPRA authority (cloud, valid) ==\n";
$res = $driver->submit($invoice);
check('response isValid', $res->isValid());
check('success status 201', $res->statusCode() === '201');
check('got a KPRA transaction id', ! empty($res->invoiceNumber()));
check('QR payload is the verification URL (not the number)',
    str_contains((string) $res->qrPayload(), 'pos_id=')
    && str_contains((string) $res->qrPayload(), 'invoice_no=')
    && $res->qrPayload() !== $res->invoiceNumber());
check('QR object built from the URL', $res->qr() && $res->qr()->payload() === $res->qrPayload());
check('mock was hit on the CLOUD endpoint', str_contains($mock->recorded[0]['url'], 'rims-integration'));

echo "\n== 3. Offline mode: same invoice, localhost utility, same body creds ==\n";
$offlineMock = new KpraMockTransport();
$offlineCreds = new Credentials(
    authority: 'kpra', sandbox: true,
    seller: new Seller('K123456', 'HEAD OFFICE', 'Khyber Pakhtunkhwa', 'Peshawar'),
    posId: 'K123456', mode: 'offline', apiKey: 'KPRA@123a',
);
$offlineDriver = new KpraDriver($offlineCreds, $kpraConfig, $offlineMock);
check('mode resolves to offline', $offlineDriver->mode() === 'offline');
$offRes = $offlineDriver->submit($invoice);
check('offline submit isValid', $offRes->isValid());
check('mock was hit on the LOCALHOST utility', str_contains($offlineMock->recorded[0]['url'], 'localhost:3000'));

echo "\n== 4. Missing key / pos_id is rejected locally (no network call) ==\n";
$noKeyMock = new KpraMockTransport();
$noKey = new Credentials(
    authority: 'kpra', sandbox: true,
    seller: new Seller('K123456', 'HEAD OFFICE', 'Khyber Pakhtunkhwa', 'Peshawar'),
    posId: 'K123456', mode: 'cloud', // no apiKey
);
$noKeyRes = (new KpraDriver($noKey, $kpraConfig, $noKeyMock))->submit($invoice);
check('missing key -> invalid', ! $noKeyRes->isValid());
check('error mentions key', (bool) preg_grep('/key/i', $noKeyRes->errors()));
check('no network call was made', $noKeyMock->recorded === []);

echo "\n== 5. A single tax rate per invoice is enforced ==\n";
[$d5] = cloudDriver($kpraConfig);
$mixed = (new InvoiceBuilder())->type('Sale Invoice')->date('2025-12-26')
    ->seller('K123456', 'HEAD OFFICE', 'Khyber Pakhtunkhwa', 'Peshawar')->walkInCustomer()->number('MIX-1')
    ->addItem((new LineItemBuilder())->description('A')->quantity(1)->unitPrice(500)->taxRate('16%')->saleType('Services'))
    ->addItem((new LineItemBuilder())->description('B')->quantity(1)->unitPrice(500)->taxRate('5%')->saleType('Services'))
    ->build();
[, $mixErrors] = $d5->buildPayload($mixed);
check('mixed rates produce an error', (bool) preg_grep('/single tax rate/i', $mixErrors));

echo "\n== 6. invoice_no is unique per pos_id (duplicate rejected) ==\n";
[$d6, $m6] = cloudDriver($kpraConfig);
$mk = fn (string $no) => (new InvoiceBuilder())->type('Sale Invoice')->date('2025-12-26')
    ->seller('K123456', 'HEAD OFFICE', 'Khyber Pakhtunkhwa', 'Peshawar')->walkInCustomer()->number($no)->modeOfPay('Cash')
    ->addItem((new LineItemBuilder())->description('Tea')->quantity(1)->unitPrice(100)->taxRate('15%')->saleType('Services'))
    ->build();
check('first submit valid', $d6->submit($mk('DUP-1'))->isValid());
$dupRes = $d6->submit($mk('DUP-1'));
check('duplicate submit invalid', ! $dupRes->isValid());
check('error mentions already exists', (bool) preg_grep('/already exists|duplicate/i', $dupRes->errors()));

echo "\n== 7. payment_mode maps Card -> 2 ==\n";
[$d7] = cloudDriver($kpraConfig);
$card = (new InvoiceBuilder())->type('Sale Invoice')->date('2025-12-26')
    ->seller('K123456', 'HEAD OFFICE', 'Khyber Pakhtunkhwa', 'Peshawar')->walkInCustomer()->number('CARD-1')->modeOfPay('Card')
    ->addItem((new LineItemBuilder())->description('Tea')->quantity(1)->unitPrice(100)->taxRate('15%')->saleType('Services'))
    ->build();
[$pc] = $d7->buildPayload($card);
check('payment_mode 2 for Card', $pc['payment_mode'] === 2);

echo "\n== 8. Credit note -> dedicated endpoint, references the original sale ==\n";
[$cnDriver, $cnMock] = cloudDriver($kpraConfig);
// First record the original sale so KPRA can find it.
$sale = (new InvoiceBuilder())->type('Sale Invoice')->date('2026-09-24')->at('2026-09-24 12:00:00')
    ->seller('K123456', 'HEAD OFFICE', 'Khyber Pakhtunkhwa', 'Peshawar')->walkInCustomer()->number('ORD-900')->modeOfPay('Cash')
    ->addItem((new LineItemBuilder())->description('Platter')->quantity(1)->unitPrice(1000)->taxRate('10%')->saleType('Services'))
    ->build();
check('original sale recorded', $cnDriver->submit($sale)->isValid());

$makeCn = fn () => (new InvoiceBuilder())->type('Credit Note')->date('2026-09-24')->at('2026-09-24 16:30:00')
    ->seller('K123456', 'HEAD OFFICE', 'Khyber Pakhtunkhwa', 'Peshawar')->walkInCustomer()
    ->meta(['creditNoteNo' => 'CN-1001', 'refInvoiceNo' => 'ORD-900', 'noteType' => 'full', 'reason' => 'Bill cancelled'])
    ->addItem((new LineItemBuilder())->description('Platter')->quantity(1)->unitPrice(1000)->taxRate('10%')->saleType('Services'))
    ->build();

$cn = $makeCn();
[$cnPayload] = $cnDriver->buildPayload($cn);
check('credit note carries credit_note_no', ($cnPayload['credit_note_no'] ?? null) === 'CN-1001');
check('credit note references original invoice_no', ($cnPayload['invoice_no'] ?? null) === 'ORD-900');
check('note_type = full', ($cnPayload['note_type'] ?? null) === 'full');
$cnRes = $cnDriver->submit($cn);
check('credit note recorded (valid)', $cnRes->isValid());
check('credit note hit the credit-note endpoint', str_contains($cnMock->recorded[1]['url'], 'kpra-credit-note'));
check('re-sending same credit_note_no still valid (idempotent 200)', $cnDriver->submit($makeCn())->isValid());

echo "\n== 9. Credit note for an unknown invoice -> 404 (not found) ==\n";
[$cn404] = cloudDriver($kpraConfig);
$orphan = (new InvoiceBuilder())->type('Credit Note')->date('2026-09-24')
    ->seller('K123456', 'HEAD OFFICE', 'Khyber Pakhtunkhwa', 'Peshawar')->walkInCustomer()
    ->meta(['creditNoteNo' => 'CN-X', 'refInvoiceNo' => 'DOES-NOT-EXIST'])
    ->addItem((new LineItemBuilder())->description('Platter')->quantity(1)->unitPrice(1000)->taxRate('10%')->saleType('Services'))
    ->build();
$orphanRes = $cn404->submit($orphan);
check('unknown original -> invalid', ! $orphanRes->isValid());
check('error mentions not found', (bool) preg_grep('/not found|original/i', $orphanRes->errors()));

echo "\n== 10. validate() is a local dry-run (no fiscal number) ==\n";
[$d10] = cloudDriver($kpraConfig);
$okCheck = $d10->validate($invoice);
check('valid invoice passes local validation', $okCheck->isValid());
check('validate assigns no invoice number', $okCheck->invoiceNumber() === null);
$badInvoice = (new InvoiceBuilder())->type('Sale Invoice')->date('2025-12-26')
    ->seller('K123456', 'HEAD OFFICE', 'Khyber Pakhtunkhwa', 'Peshawar')->walkInCustomer()->number('BAD-1')
    ->addItem((new LineItemBuilder())->description('No rate item')->quantity(1)->unitPrice(500)->saleType('Services'))
    ->build();
check('missing-rate invoice fails validation', ! $d10->validate($badInvoice)->isValid());

echo "\n".str_repeat('-', 40)."\n";
echo "PASSED: {$pass}   FAILED: {$fail}\n";
exit($fail === 0 ? 0 : 1);
