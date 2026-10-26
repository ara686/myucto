<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Invoice;

use MyInvoice\Action\Settings\SettingsAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Service\Pdf\InvoicePdfRenderer;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

/**
 * Kompaktní řádky položek na PDF faktury (`supplier.invoice_pdf_compact_items`, migrace 2194).
 *
 * 1. Volba je ve výchozím stavu vypnutá, ukládá se přes nastavení firmy a týká se jen dané firmy.
 * 2. Bez volby má tabulka položek dnešní třídu a sekundární texty (sériová čísla,
 *    rozpis balení) dnešní podobu na vlastním řádku.
 * 3. S volbou dostane tabulka třídu `items-compact` (menší odsazení a písmo), rozpis
 *    balení a sériová čísla pokračují drobným písmem na řádku popisu.
 *
 * Render běží nad syntetickým polem faktury jako InvoicePdfMonochromeTest.
 */
#[Group('integration')]
final class InvoicePdfCompactItemsTest extends TestCase
{
    use IsolatedSupplierTrait;

    private InvoicePdfRenderer $renderer;
    private string $rootDir;

    protected function setUp(): void
    {
        $this->rootDir = dirname(__DIR__, 4);
        if (!is_file($this->rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje, test vyžaduje DB.');
        }
        try {
            $this->renderer = Bootstrap::buildContainer()->get(InvoicePdfRenderer::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI/DB nedostupné: ' . $e->getMessage());
        }
    }

    public function testOptionIsOffByDefaultAndRoundTripsPerSupplier(): void
    {
        $container = Bootstrap::buildContainer();
        $pdo = $container->get(Connection::class)->pdo();
        $settings = $container->get(SettingsAction::class);
        $pdo->beginTransaction();
        try {
            $supplierId = $this->createIsolatedSupplier($pdo, 1);
            $otherId = $this->createIsolatedSupplier($pdo, 1);
            $request = (new ServerRequestFactory())->createServerRequest('PUT', '/api/settings/supplier')
                ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $supplierId)
                ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => 1, 'role' => 'admin']);

            self::assertFalse($this->read($settings->getSupplier($request, new Response()))['invoice_pdf_compact_items']);

            $response = $settings->updateSupplier($request->withParsedBody(['invoice_pdf_compact_items' => true]), new Response());
            self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
            self::assertTrue($this->read($settings->getSupplier($request, new Response()))['invoice_pdf_compact_items']);
            $stored = $pdo->prepare('SELECT invoice_pdf_compact_items FROM supplier WHERE id = ?');
            $stored->execute([$supplierId]);
            self::assertSame(1, (int) $stored->fetchColumn());

            $other = $request->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $otherId);
            self::assertFalse($this->read($settings->getSupplier($other, new Response()))['invoice_pdf_compact_items']);
        } finally {
            if ($pdo->inTransaction()) $pdo->rollBack();
        }
    }

    public function testWithoutOptionItemsTableKeepsTodaysLayout(): void
    {
        $html = $this->renderer->renderHtml($this->invoice(), includeCss: false);

        self::assertStringContainsString('<table class="items">', $html);
        self::assertStringNotContainsString('items-compact', $html);
        self::assertStringContainsString('<br><span style="font-size:85%;color:#666666">Výr. č.: SN0001</span>', $html);
        self::assertStringContainsString('<br><span style="font-size:85%;color:#666666;white-space:nowrap">(celkem 20&nbsp;ks)</span>', $html);
    }

    public function testCompactOptionTightensRowsAndMovesSecondaryTextsOntoTheDescriptionLine(): void
    {
        $html = $this->renderer->renderHtml($this->invoice(['invoice_pdf_compact_items' => 1]), includeCss: false);

        self::assertStringContainsString('<table class="items items-compact">', $html);
        self::assertMatchesRegularExpression('~Řetězová sada\s*<span style="font-size:85%;color:#666666"> · Výr\. č\.: SN0001</span>~u', $html);
        self::assertMatchesRegularExpression('~Brzdové destičky\s*<span style="font-size:85%;color:#666666"> · celkem 20&nbsp;ks</span>~u', $html);
        self::assertStringContainsString('<td>bal</td>', $html);
    }

    public function testCompactStylesheetRulesOnlyShrinkSpacingAndType(): void
    {
        $css = (string) file_get_contents($this->rootDir . '/styles/invoice.css');
        preg_match_all('/table\.items-compact[^{]*\{([^}]*)\}/', $css, $m);

        self::assertNotEmpty($m[1], 'invoice.css nemá pravidla pro table.items-compact');
        foreach ($m[1] as $body) {
            // Barvy a plochy zůstávají ze základních pravidel (černobílá varianta je pokrývá).
            self::assertDoesNotMatchRegularExpression('/(^|;)\s*(color|background)/i', $body);
        }
    }

    /** @return array<string,mixed> */
    private function read(\Psr\Http\Message\ResponseInterface $response): array
    {
        $data = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
        return (array) ($data['data'] ?? $data);
    }

    /**
     * Syntetický doklad se sériovým číslem a rozpisem balení. Snapshot dodavatele
     * je vyplněný, render tedy nesahá na živou firmu (supplier_id 0).
     *
     * @param array<string,mixed> $supplier
     * @return array<string,mixed>
     */
    private function invoice(array $supplier = []): array
    {
        $line = [
            'vat_rate_snapshot' => 21.0, 'item_kind' => 'standard',
            'oss_applicable' => false, 'oss_consumer_country' => null,
        ];
        return [
            'id'                  => 0,
            'invoice_type'        => 'invoice',
            'status'              => 'issued',
            'language'            => 'cs',
            'currency'            => 'CZK',
            'currency_id'         => 0,
            'client_id'           => 0,
            'supplier_id'         => 0,
            'varsymbol'           => 'KOMP0001',
            'issue_date'          => '2026-07-01',
            'tax_date'            => '2026-07-01',
            'due_date'            => '2026-07-15',
            'paid_at'             => null,
            'amount_to_pay'       => 242.0,
            'paid_total'          => 0.0,
            'parent_invoice_id'   => null,
            'payment_method'      => 'bank_transfer',
            'prices_include_vat'  => false,
            'reverse_charge'      => false,
            'branding_profile_id' => null,
            'advance_paid_amount' => 0.0,
            'czk_recap'           => null,
            'supplier_snapshot'   => json_encode($supplier + [
                'company_name' => 'Testovací dodavatel',
                'street'       => 'Zkušební 1',
                'city'         => 'Praha',
                'zip'          => '11000',
                'is_vat_payer' => true,
            ], JSON_UNESCAPED_UNICODE),
            'client_snapshot'     => json_encode([
                'company_name' => 'Testovací odběratel',
                'street'       => 'Zkušební 2',
                'city'         => 'Brno',
                'zip'          => '60200',
                'country_iso2' => 'CZ',
            ], JSON_UNESCAPED_UNICODE),
            'bank_snapshot'       => null,
            'items'               => [
                $line + [
                    'description' => 'Řetězová sada', 'quantity' => 1.0, 'unit' => 'ks',
                    'unit_price_without_vat' => 100.0, 'total_without_vat' => 100.0,
                    'total_vat' => 21.0, 'total_with_vat' => 121.0,
                    'stock_item_id' => 1,
                    'tracking_allocations' => [['serial_number' => 'SN0001']],
                ],
                $line + [
                    'description' => 'Brzdové destičky', 'quantity' => 2.0, 'unit' => 'bal',
                    'unit_price_without_vat' => 50.0, 'total_without_vat' => 100.0,
                    'total_vat' => 21.0, 'total_with_vat' => 121.0,
                    'base_qty' => 20.0, 'base_unit' => 'ks',
                ],
            ],
            'totals'              => ['without_vat' => 200.0, 'vat' => 42.0, 'with_vat' => 242.0],
            'vat_breakdown'       => [],
        ];
    }
}
