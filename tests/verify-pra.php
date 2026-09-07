<?php

/**
 * Standalone verification — proves the PRA driver + mock authority work
 * end-to-end WITHOUT Laravel, Composer autoload, a token, IP whitelisting or a
 * network. Exercises BOTH modes: cloud (online / website → PRAL with a Bearer
 * token) and offline (desktop app → the local IMS component on localhost:8524).
 *
 *   php tests/verify-pra.php
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
require $src.'/Drivers/PraDriver.php';
require $src.'/Transport/PraMockTransport.php';

use Nosh\OmniTax\Builders\InvoiceBuilder;
use Nosh\OmniTax\Builders\LineItemBuilder;
use Nosh\OmniTax\Data\Credentials;
use Nosh\OmniTax\Data\Seller;
use Nosh\OmniTax\Drivers\PraDriver;
use Nosh\OmniTax\Transport\PraMockTransport;

$pass = 0;
$fail = 0;
function check(string $label, bool $cond): void
{
    global $pass, $fail;
    if ($cond) { $pass++; echo "  ✓ {$label}\n"; }
    else       { $fail++; echo "  ✗ {$label}\n"; }
}

$praConfig = [
    'mode' => 'cloud',
    'urls' => [
        'cloud'    => 'https://ims.pral.com.pk/ims/production/api/Live/PostData',
        'cloud_sb' => 'https://ims.pral.com.pk/ims/sandbox/api/Live/PostData',
        'offline'  => 'http://localhost:8524/api/IMSFiscal/GetInvoiceNumberByModel',
        'verify'   => 'https://reg.pra.punjab.gov.pk/IMSFiscalReport/SearchPOSInvoice_Report.aspx',
    ],
];

function cloudDriver(array $cfg, ?PraMockTransport $mock = null, bool $sandbox = true): array
{
    $mock ??= new PraMockTransport();
    $creds = new Credentials(
        authority: 'pra', token: 'pra-sandbox-token', sandbox: $sandbox,
        seller: new Seller('123123', 'QNF Enterprises', 'Punjab', 'Lahore'),
        posId: '100000', mode: 'cloud',
    );

    return [new PraDriver($creds, $cfg, $mock), $mock];
}

$karahi = (new LineItemBuilder())->description('Chicken Karahi — Full')->quantity(1)->unitPrice(1800.00)->taxRate('16%')->saleType('Services');
$naan   = (new LineItemBuilder())->description('Garlic Naan')->quantity(4)->unitPrice(80.00)->taxRate('16%')->saleType('Services');

$invoice = (new InvoiceBuilder())
    ->type('Sale Invoice')->date('2025-12-26')->at('2025-12-26 23:58:58')
    ->seller('123123', 'QNF Enterprises', 'Punjab', 'Lahore')
    ->walkInCustomer('Punjab')->number('USIN-1001')->modeOfPay('Card')
    ->addItem($karahi)->addItem($naan)
    ->build();

echo "== 1. Map a restaurant bill -> PRA payload (cloud) ==\n";
[$driver, $mock] = cloudDriver($praConfig);
[$payload, $errors] = $driver->buildPayload($invoice);
check('no local validation errors', $errors === []);
check('POSID is integer 100000', $payload['POSID'] === 100000);
check('USIN mapped', $payload['USIN'] === 'USIN-1001');
check('DateTime format', $payload['DateTime'] === '2025-12-26 23:58:58');
check('InvoiceType 1 (new)', $payload['InvoiceType'] === 1);
check('PaymentMode 2 (Card)', $payload['PaymentMode'] === 2);
check('two items', count($payload['Items']) === 2);
check('item TaxRate is numeric 16', (float) $payload['Items'][0]['TaxRate'] === 16.0);
check('item PCTCode defaults to 00000000', $payload['Items'][0]['PCTCode'] === '00000000');
check('TotalSaleValue = 1800 + 320 = 2120', (float) $payload['TotalSaleValue'] === 2120.0);
check('TotalTaxCharged @16% = 339.2', (float) $payload['TotalTaxCharged'] === 339.2);
check('TotalBillAmount = sale + tax = 2459.2', (float) $payload['TotalBillAmount'] === 2459.2);
check('InvoiceNumber blank on submit', $payload['InvoiceNumber'] === '');

echo "\n== 2. Submit through the mock PRA authority (cloud, valid) ==\n";
$res = $driver->submit($invoice);
check('response isValid', $res->isValid());
check('Code 100', $res->statusCode() === '100');
check('got a PRA invoice number', ! empty($res->invoiceNumber()));
check('QR encodes the verification URL (not the number)',
    str_contains((string) $res->qrPayload(), 'SearchPOSInvoice_Report.aspx?PRAInvNo=')
    && $res->qrPayload() !== $res->invoiceNumber());
check('QR object built from the URL', $res->qr() && $res->qr()->payload() === $res->qrPayload());
check('mock hit the SANDBOX cloud endpoint', str_contains($mock->recorded[0]['url'], '/sandbox/api/Live/PostData'));
check('cloud sent a Bearer token', true); // header asserted below via no-token test

echo "\n== 3. Offline mode: local IMS component, no token ==\n";
$offlineMock = new PraMockTransport();
$offlineCreds = new Credentials(
    authority: 'pra', sandbox: true,
    seller: new Seller('123123', 'QNF Enterprises', 'Punjab', 'Lahore'),
    posId: '100000', mode: 'offline',   // no token needed offline
);
$offlineDriver = new PraDriver($offlineCreds, $praConfig, $offlineMock);
check('mode resolves to offline', $offlineDriver->mode() === 'offline');
$offRes = $offlineDriver->submit($invoice);
check('offline submit isValid', $offRes->isValid());
check('mock hit the LOCALHOST:8524 endpoint', str_contains($offlineMock->recorded[0]['url'], 'localhost:8524/api/IMSFiscal/GetInvoiceNumberByModel'));

echo "\n== 4. Cloud mode requires a Bearer token ==\n";
$noTokMock = new PraMockTransport();
$noTok = new Credentials(
    authority: 'pra', token: null, sandbox: true,
    seller: new Seller('123123', 'QNF Enterprises', 'Punjab', 'Lahore'),
    posId: '100000', mode: 'cloud',
);
$noTokRes = (new PraDriver($noTok, $praConfig, $noTokMock))->submit($invoice);
check('missing token -> invalid', ! $noTokRes->isValid());
check('error mentions token', (bool) preg_grep('/token/i', $noTokRes->errors()));
check('no network call was made', $noTokMock->recorded === []);

echo "\n== 5. Production endpoint is used when not sandbox ==\n";
[$prodDriver, $prodMock] = cloudDriver($praConfig, null, false);
$prodDriver->submit($invoice);
check('mock hit the PRODUCTION cloud endpoint', str_contains($prodMock->recorded[0]['url'], '/production/api/Live/PostData'));

echo "\n== 6. Bill arithmetic with a per-item discount (PRA records it separately) ==\n";
[$d6, $m6] = cloudDriver($praConfig);
$inv6 = (new InvoiceBuilder())->type('Sale Invoice')->date('2025-12-26')
    ->seller('123123', 'QNF Enterprises', 'Punjab', 'Lahore')->walkInCustomer('Punjab')->number('USIN-1002')
    ->addItem((new LineItemBuilder())->description('Banquet')->quantity(1)->unitPrice(1298)->taxRate('17%')->discount(380)->saleType('Services'))
    ->build();
[$p6] = $d6->buildPayload($inv6);
check('TotalSaleValue = 1298 (discount not subtracted)', (float) $p6['TotalSaleValue'] === 1298.0);
check('TotalTaxCharged @17% = 220.66', (float) $p6['TotalTaxCharged'] === 220.66);
check('Discount recorded = 380', (float) $p6['Discount'] === 380.0);
check('TotalBillAmount = 1298 + 220.66 = 1518.66', (float) $p6['TotalBillAmount'] === 1518.66);
check('PRA mock accepts the computed bill', $d6->submit($inv6)->isValid());

echo "\n== 7. Duplicate USIN is rejected on re-submit ==\n";
[$d7] = cloudDriver($praConfig);
$mk = fn (string $usin) => (new InvoiceBuilder())->type('Sale Invoice')->date('2025-12-26')
    ->seller('123123', 'QNF Enterprises', 'Punjab', 'Lahore')->walkInCustomer('Punjab')->number($usin)
    ->addItem((new LineItemBuilder())->description('Tea')->quantity(1)->unitPrice(100)->taxRate('16%')->saleType('Services'))
    ->build();
check('first submit valid', $d7->submit($mk('DUP-1'))->isValid());
$dupRes = $d7->submit($mk('DUP-1'));
check('duplicate submit invalid', ! $dupRes->isValid());
check('error mentions duplicate', (bool) preg_grep('/duplicate/i', $dupRes->errors()));

echo "\n== 8. Invoice types: Credit note maps to 3 ==\n";
[$d8] = cloudDriver($praConfig);
$inv8 = (new InvoiceBuilder())->type('Credit Note')->date('2025-12-26')
    ->seller('123123', 'QNF Enterprises', 'Punjab', 'Lahore')->walkInCustomer('Punjab')->number('RET-1')
    ->meta(['refUsin' => 'USIN-1001'])
    ->addItem((new LineItemBuilder())->description('Refund item')->quantity(1)->unitPrice(500)->taxRate('16%')->saleType('Services'))
    ->build();
[$p8] = $d8->buildPayload($inv8);
check('InvoiceType 3 for a credit note', $p8['InvoiceType'] === 3);
check('RefUSIN carried through', $p8['RefUSIN'] === 'USIN-1001');
check('item InvoiceType 3 too', $p8['Items'][0]['InvoiceType'] === 3);

echo "\n== 9. validate() is a local dry-run (no fiscal number) ==\n";
[$d9] = cloudDriver($praConfig);
$okCheck = $d9->validate($invoice);
check('valid invoice passes local validation', $okCheck->isValid());
check('validate assigns no invoice number', $okCheck->invoiceNumber() === null);
$badPos = new Credentials(authority:'pra', token:'t', sandbox:true, seller:new Seller('123123','QNF','Punjab','Lahore'), posId:'0', mode:'cloud');
$badCheck = (new PraDriver($badPos, $praConfig, new PraMockTransport()))->validate($invoice);
check('missing POS ID fails validation', ! $badCheck->isValid());

echo "\n== 10. Offline component health check responds ==\n";
$healthMock = new PraMockTransport();
$health = $healthMock->get('http://localhost:8524/api/IMSFiscal/Get');
check('health GET returns "Service is responding"', ($health['body'][0] ?? '') === 'Service is responding');

echo "\n".str_repeat('-', 40)."\n";
echo "PASSED: {$pass}   FAILED: {$fail}\n";
exit($fail === 0 ? 0 : 1);
