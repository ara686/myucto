<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Infrastructure\Cache\RedisFactory;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Service\Auth\ApiTokenService;
use MyInvoice\Service\Auth\DatabaseSecurityClock;
use MyInvoice\Service\Auth\SessionManager;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Slim\App;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\StreamFactory;

/**
 * Epic F6 — DocumentLockService + enforcement (§8.2 specu, všech 8 scénářů):
 *
 *   1. posted zápis → client PUT 403 document_locked; accountant PUT OK
 *   2. ruční POST /book (tax_evidence) → client PUT/cancel/DELETE payment 403;
 *      payments + mark-paid OK (+ audit „payment on locked document"); DELETE /book odemkne
 *   3. PF status='booked' zamyká; client transition na booked = 403F i u draftu;
 *      PF 'paid' bez booked NEzamčeno — client paid → received OK (M2)
 *   4. zavřené období → client 403 (i s ?force=1 — M6); accountant 409 period_closed;
 *      admin ?force=1 200 + activity log
 *   5. H1 — create/issue/přesun data do zavřeného období: client 403, accountant 409,
 *      admin ?force=1 200
 *   6. reverse zápisu → booked_at smazán (+ audit R9), client PUT zase 200
 *   7. DELETE /book při aktivním posted zápisu → 409 still_posted
 *   8. optimistický zámek L1 — zaúčtování mezi guardem a UPDATE → false → 409
 *
 * Review follow-upy:
 *   9.  PF transition na received odemyká booked_at (unbook pro PF neexistuje);
 *       s aktivním posted zápisem booked_at zůstává (still_posted sémantika)
 *   10. staff PF transition na booked/cancelled je period-gated (409P, admin force + audit)
 *   11. H1 na clone — draft s datem v zavřeném období nevznikne (client 403, staff 409)
 *   12. L1 na cancel a PF transition (stale memoizace zámku = TOCTOU simulace) → 409
 */
#[Group('integration')]
final class DocumentLockTest extends TestCase
{
    private Connection $db;
    private Config $config;
    private ApiTokenService $svc;
    private SessionManager $sessions;
    private ?App $app = null;

    private int $sourceSupplier = 0;
    /** @var list<int> */
    private array $supplierIds = [];
    /** @var array<int,int> supplier_id => currency_id */
    private array $currencyIds = [];
    /** @var array<int,int> supplier_id => client_id */
    private array $clientIds = [];
    /** @var array<int,int> supplier_id => user_id (created_by fixture) */
    private array $ownerIds = [];
    /** @var list<int> */
    private array $userIds = [];
    /** @var list<string> */
    private array $sessionTokens = [];
    /** @var list<int> */
    private array $roleIds = [];
    private array $ossVatRateIds = [];

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 3);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php missing');
        }
        try {
            $this->config = Config::load($rootDir);
            $this->db = new Connection($this->config);
            $redis = new RedisFactory($this->config);
            $this->svc = new ApiTokenService($this->db, $redis);
            $this->sessions = new SessionManager($this->db, $this->config, new DatabaseSecurityClock());
            $this->db->pdo()->query('SELECT 1');
        } catch (\Exception $e) {
            $this->markTestSkipped('DB unavailable: ' . $e->getMessage());
        }

        if ($this->db->pdo()->query("SHOW TABLES LIKE 'roles'")->fetchColumn() === false) {
            $this->markTestSkipped('Dynamické role chybí — spusť api/bin/migrate.php.');
        }
        $bookedCol = $this->db->pdo()->query("SHOW COLUMNS FROM invoices LIKE 'booked_at'")->fetch(\PDO::FETCH_ASSOC);
        if ($bookedCol === false) {
            $this->markTestSkipped('invoices.booked_at chybí — spusť api/bin/migrate.php (1021).');
        }

        $this->sourceSupplier = (int) $this->db->pdo()->query('SELECT MIN(id) FROM supplier')->fetchColumn();
        if ($this->sourceSupplier <= 0) {
            $this->markTestSkipped('Žádný supplier v DB.');
        }
    }

    protected function tearDown(): void
    {
        if (!isset($this->db)) return;
        $pdo = $this->db->pdo();

        foreach ($this->sessionTokens as $token) {
            try {
                $this->sessions->destroy($token);
            } catch (\Throwable) {
                // best-effort úklid
            }
        }

        foreach ($this->supplierIds as $sid) {
            // FK fk_sup_currency: před smazáním test currencies vrať default na zdrojovou firmu
            $pdo->prepare('UPDATE supplier s JOIN supplier src ON src.id = ? SET s.default_currency_id = src.default_currency_id WHERE s.id = ?')
                ->execute([$this->sourceSupplier, $sid]);
            $pdo->prepare('DELETE dl FROM document_links dl JOIN documents d ON d.id = dl.document_id WHERE d.supplier_id = ?')
                ->execute([$sid]);
            $pdo->prepare('DELETE FROM document_requests WHERE supplier_id = ?')->execute([$sid]);
            $pdo->prepare('DELETE FROM purchase_invoice_submissions WHERE supplier_id = ?')->execute([$sid]);
            $pdo->prepare('DELETE df FROM document_files df JOIN documents d ON d.id = df.document_id WHERE d.supplier_id = ?')
                ->execute([$sid]);
            $pdo->prepare('DELETE FROM documents WHERE supplier_id = ?')->execute([$sid]);
            $pdo->prepare('DELETE FROM journal_entry_lines WHERE supplier_id = ?')->execute([$sid]);
            $pdo->prepare('DELETE FROM journal_entries WHERE supplier_id = ?')->execute([$sid]);
            $pdo->prepare('DELETE FROM accounting_periods WHERE supplier_id = ?')->execute([$sid]);
            $pdo->prepare('DELETE FROM tax_submissions WHERE supplier_id = ?')->execute([$sid]);
            $pdo->prepare('DELETE FROM chart_of_accounts WHERE supplier_id = ?')->execute([$sid]);
            $pdo->prepare('DELETE FROM invoice_payments WHERE supplier_id = ?')->execute([$sid]);
            $pdo->prepare('DELETE FROM invoices WHERE supplier_id = ?')->execute([$sid]);
            $pdo->prepare('DELETE FROM purchase_invoices WHERE supplier_id = ?')->execute([$sid]);
            $pdo->prepare('DELETE FROM activity_log WHERE supplier_id = ?')->execute([$sid]);
            $pdo->prepare('DELETE FROM clients WHERE supplier_id = ?')->execute([$sid]);
            $pdo->prepare('DELETE FROM currencies WHERE supplier_id = ?')->execute([$sid]);
            $pdo->prepare('DELETE FROM user_suppliers WHERE supplier_id = ?')->execute([$sid]);
            // Vystavení (schválení výkazu) založí počítadlo číselné řady.
            $pdo->prepare('DELETE FROM invoice_counters WHERE supplier_id = ?')->execute([$sid]);
            $pdo->prepare('DELETE FROM supplier WHERE id = ?')->execute([$sid]);
        }
        if ($this->userIds !== []) {
            $place = implode(',', array_fill(0, count($this->userIds), '?'));
            $pdo->prepare("DELETE FROM activity_log WHERE user_id IN ($place)")->execute($this->userIds);
            $pdo->prepare("DELETE FROM user_suppliers WHERE user_id IN ($place)")->execute($this->userIds);
            $pdo->prepare("DELETE FROM api_tokens WHERE user_id IN ($place)")->execute($this->userIds);
            $pdo->prepare("DELETE FROM users WHERE id IN ($place)")->execute($this->userIds);
        }
        foreach ($this->roleIds as $roleId) {
            $pdo->prepare('DELETE FROM role_permissions WHERE role_id = ?')->execute([$roleId]);
            $pdo->prepare('DELETE FROM roles WHERE id = ?')->execute([$roleId]);
        }

        foreach ($this->ossVatRateIds as $rateId) {
            $pdo->prepare('DELETE FROM vat_rates WHERE id = ?')->execute([$rateId]);
        }
        $this->ossVatRateIds = [];

        $this->supplierIds = $this->currencyIds = $this->clientIds = $this->ownerIds = [];
        $this->userIds = $this->sessionTokens = $this->roleIds = [];
        $this->db->close();
        $this->app = null;
    }

    // ---------------------------------------------------------------- tests

    /** Scénář 1: aktivní posted zápis zamyká klienta; účetní v otevřeném období pracuje. */
    public function testPostedEntryLocksClientOnly(): void
    {
        $sid = $this->mkSupplier('double_entry');
        $periodId = $this->mkPeriod($sid, 2026, 'open');
        $invoiceId = $this->mkInvoice($sid, 'draft', '2026-05-10');
        $this->mkPostedEntry($sid, 'invoice', $invoiceId, $periodId, '2026-05-10');

        [$client, $accountant] = [$this->mkUserWithToken('client', $sid), $this->mkUserWithToken('accountant', $sid)];

        $res = $this->request('PUT', "/api/invoices/$invoiceId", $client, $sid, $this->invoiceBody($sid, '2026-05-10'));
        self::assertSame(403, $res->getStatusCode());
        self::assertSame('document_locked', $this->errorCode($res));

        $res = $this->request('PUT', "/api/invoices/$invoiceId", $accountant, $sid, $this->invoiceBody($sid, '2026-05-10'));
        self::assertSame(200, $res->getStatusCode(), 'accountant v otevřeném období edituje: ' . (string) $res->getBody());

        // Kontrakt §4.5: detail nese locked blok s reason 'posted'
        $res = $this->request('GET', "/api/invoices/$invoiceId", $client, $sid);
        self::assertSame(200, $res->getStatusCode());
        $locked = $this->json($res)['locked'] ?? null;
        self::assertIsArray($locked);
        self::assertTrue($locked['is_locked']);
        self::assertContains('posted', $locked['reasons']);
    }

    /** Scénář 2 + 7: ruční book/unbook u tax_evidence firmy + still_posted. */
    public function testManualBookLocksClientAndUnbookReleases(): void
    {
        $sid = $this->mkSupplier('tax_evidence');
        $invoiceId = $this->mkInvoice($sid, 'issued', '2026-06-01', varsymbol: '2026001', totalWithVat: 1000.0);

        $client = $this->mkUserWithToken('client', $sid);
        $accountantSession = $this->mkSessionUser('accountant', $sid);
        $clientUserId = $this->userIds[count($this->userIds) - 2];

        // Účetní mutace je přes API token vždy zakázaná; provádí se jen v aplikaci.
        $res = $this->request('POST', "/api/invoices/$invoiceId/book", $client, $sid);
        self::assertSame(403, $res->getStatusCode(), 'client nesmí book endpoint');

        // Účetní zaúčtuje ručně přes webovou session (tax_evidence — žádný journal)
        $res = $this->sessionRequest('POST', "/api/invoices/$invoiceId/book", $accountantSession, $sid);
        self::assertSame(200, $res->getStatusCode(), 'book: ' . (string) $res->getBody());
        $locked = $this->json($res)['locked'] ?? [];
        self::assertTrue($locked['is_locked'] ?? false);
        self::assertContains('booked', $locked['reasons'] ?? []);

        // Idempotence POST /book
        $bookedAt = $this->db->pdo()->query("SELECT booked_at FROM invoices WHERE id = $invoiceId")->fetchColumn();
        $res = $this->sessionRequest('POST', "/api/invoices/$invoiceId/book", $accountantSession, $sid);
        self::assertSame(200, $res->getStatusCode());
        self::assertSame($bookedAt, $this->db->pdo()->query("SELECT booked_at FROM invoices WHERE id = $invoiceId")->fetchColumn());

        // Client: mutace 403 document_locked
        $res = $this->request('PUT', "/api/invoices/$invoiceId", $client, $sid, $this->invoiceBody($sid, '2026-06-01'));
        self::assertSame(403, $res->getStatusCode());
        self::assertSame('document_locked', $this->errorCode($res));

        $res = $this->request('POST', "/api/invoices/$invoiceId/cancel", $client, $sid, ['mode' => 'internal']);
        self::assertSame(403, $res->getStatusCode());
        self::assertSame('document_locked', $this->errorCode($res));

        $res = $this->request('POST', "/api/invoices/$invoiceId/unmark-paid", $client, $sid);
        self::assertSame(403, $res->getStatusCode(), 'unmark-paid pro klienta 403 (admin-only)');

        // Platba je fakt (R8): POST payments + mark-paid projdou + povinný audit (M5)
        $res = $this->request('POST', "/api/invoices/$invoiceId/payments", $client, $sid,
            ['amount' => 400, 'paid_on' => '2026-06-10']);
        self::assertSame(200, $res->getStatusCode(), 'payments na zamčeném: ' . (string) $res->getBody());
        $paymentId = (int) ($this->json($res)['payment']['id'] ?? 0);
        self::assertGreaterThan(0, $paymentId);

        $res = $this->request('POST', "/api/invoices/$invoiceId/mark-paid", $client, $sid, ['paid_at' => '2026-06-11']);
        self::assertSame(200, $res->getStatusCode(), 'mark-paid na zamčeném: ' . (string) $res->getBody());

        $cnt = $this->db->pdo()->prepare(
            "SELECT COUNT(*) FROM activity_log WHERE action = 'invoice.payment_on_locked_document' AND entity_id = ? AND user_id = ?"
        );
        $cnt->execute([$invoiceId, $clientUserId]);
        self::assertSame(2, (int) $cnt->fetchColumn(), 'payment i mark-paid na zamčeném se audituje');

        // Smazání platby mění účetní fakt → 403
        $res = $this->request('DELETE', "/api/invoices/$invoiceId/payments/$paymentId", $client, $sid);
        self::assertSame(403, $res->getStatusCode());
        self::assertSame('document_locked', $this->errorCode($res));

        // Scénář 7: DELETE /book s aktivním posted zápisem → 409 still_posted
        $periodId = $this->mkPeriod($sid, 2026, 'open');
        $entryId = $this->mkPostedEntry($sid, 'invoice', $invoiceId, $periodId, '2026-06-01');
        $res = $this->sessionRequest('DELETE', "/api/invoices/$invoiceId/book", $accountantSession, $sid);
        self::assertSame(409, $res->getStatusCode());
        self::assertSame('still_posted', $this->errorCode($res));
        $this->db->pdo()->prepare('DELETE FROM journal_entries WHERE id = ?')->execute([$entryId]);

        // Unbook → zámek pryč, client zase pracuje (cancel na odemčeném = 200).
        // resetApp: v produkci (FPM) je DI container per-request; test ho recykluje,
        // takže per-request memoizace DocumentLockService by tu byla stale.
        $this->resetApp();
        $res = $this->sessionRequest('DELETE', "/api/invoices/$invoiceId/book", $accountantSession, $sid);
        self::assertSame(200, $res->getStatusCode());
        self::assertFalse($this->json($res)['locked']['is_locked'] ?? true);

        $res = $this->request('POST', "/api/invoices/$invoiceId/cancel", $client, $sid, ['mode' => 'internal']);
        self::assertSame(200, $res->getStatusCode(), 'po unbooku client zase pracuje: ' . (string) $res->getBody());
    }

    /** Scénář 3: PF zamyká jen status='booked' (M2); transition whitelist klienta. */
    public function testPurchaseInvoiceBookedStatusAndClientTransitionWhitelist(): void
    {
        $sid = $this->mkSupplier('tax_evidence');
        $client = $this->mkUserWithToken('client', $sid);

        // status='booked' (i bez booked_at) zamyká
        $booked = $this->mkPurchaseInvoice($sid, 'booked');
        $res = $this->request('PUT', "/api/purchase-invoices/$booked/items", $client, $sid, ['items' => []]);
        self::assertSame(403, $res->getStatusCode());
        self::assertSame('document_locked', $this->errorCode($res));

        // transition na booked = 403 forbidden_transition VŽDY — i u nezamčeného draftu
        $draft = $this->mkPurchaseInvoice($sid, 'draft');
        $res = $this->request('POST', "/api/purchase-invoices/$draft/transition", $client, $sid, ['target' => 'booked']);
        self::assertSame(403, $res->getStatusCode());
        self::assertSame('forbidden_transition', $this->errorCode($res));
        $res = $this->request('POST', "/api/purchase-invoices/$draft/transition", $client, $sid, ['target' => 'cancelled']);
        self::assertSame(403, $res->getStatusCode());
        self::assertSame('forbidden_transition', $this->errorCode($res));

        // PF 'paid' bez booked → NEzamčeno, client paid → received OK (M2)
        $paid = $this->mkPurchaseInvoice($sid, 'paid');
        $res = $this->request('POST', "/api/purchase-invoices/$paid/transition", $client, $sid, ['target' => 'received']);
        self::assertSame(200, $res->getStatusCode(), 'paid bez booked není zamčené (M2): ' . (string) $res->getBody());
        self::assertSame('received', $this->json($res)['status'] ?? null);
    }

    /** Výsledek klientského podání smí měnit účetní, ale klient už ne. */
    public function testAccountantManagedPurchaseInvoiceIsLockedOnlyForClient(): void
    {
        $sid = $this->mkSupplier('tax_evidence');
        $invoiceId = $this->mkPurchaseInvoice($sid, 'draft');
        $pdo = $this->db->pdo();
        $sha = hash('sha256', 'synthetic-accountant-managed-' . $sid . '-' . $invoiceId);
        $pdo->prepare(
            "INSERT INTO documents
                (supplier_id, title, original_name, filename, sha256, mime_type, size_bytes,
                 doc_type, source, uploaded_by)
             VALUES (?, 'syntetický doklad', 'synteticky-doklad.pdf', ?, ?,
                     'application/pdf', 32, 'pdf', 'manual', ?)"
        )->execute([$sid, $sha, $sha, $this->ownerIds[$sid]]);
        $documentId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            "INSERT INTO purchase_invoice_submissions
                (supplier_id, document_id, submitted_by, submitted_via, document_sha256,
                 status, extraction_status, extraction_source, purchase_invoice_id,
                 processed_at, processed_by)
             VALUES (?, ?, ?, 'portal', ?, 'processed', 'succeeded', 'manual', ?, NOW(), ?)"
        )->execute([
            $sid,
            $documentId,
            $this->ownerIds[$sid],
            $sha,
            $invoiceId,
            $this->ownerIds[$sid],
        ]);
        $submissionId = (int) $pdo->lastInsertId();

        $client = $this->mkUserWithToken('client', $sid);
        $accountant = $this->mkUserWithToken('accountant', $sid);

        $detail = $this->request('GET', "/api/purchase-invoices/$invoiceId", $client, $sid);
        self::assertSame(200, $detail->getStatusCode(), (string) $detail->getBody());
        self::assertTrue($this->json($detail)['locked']['is_locked'] ?? false);
        self::assertContains('accountant_managed', $this->json($detail)['locked']['reasons'] ?? []);

        $clientEdit = $this->request(
            'PUT',
            "/api/purchase-invoices/$invoiceId/items",
            $client,
            $sid,
            ['items' => []],
        );
        self::assertSame(403, $clientEdit->getStatusCode(), (string) $clientEdit->getBody());
        self::assertSame('document_locked', $this->errorCode($clientEdit));

        $staffEdit = $this->request(
            'PUT',
            "/api/purchase-invoices/$invoiceId/items",
            $accountant,
            $sid,
            ['items' => []],
        );
        self::assertSame(200, $staffEdit->getStatusCode(), (string) $staffEdit->getBody());

        $staffDelete = $this->request(
            'DELETE',
            "/api/purchase-invoices/$invoiceId",
            $accountant,
            $sid,
        );
        self::assertSame(200, $staffDelete->getStatusCode(), (string) $staffDelete->getBody());
        $reopened = $pdo->prepare(
            'SELECT status, purchase_invoice_id FROM purchase_invoice_submissions WHERE id = ? AND supplier_id = ?'
        );
        $reopened->execute([$submissionId, $sid]);
        $reopenedRow = $reopened->fetch(\PDO::FETCH_ASSOC);
        self::assertSame('submitted', $reopenedRow['status'] ?? null);
        self::assertNull($reopenedRow['purchase_invoice_id'] ?? null,
            'Smazání výsledného konceptu musí podání vrátit do fronty, ne je osiřelit.');
    }

    /** Scénář 4: zavřené období — client 403 (force ignorován, M6), accountant 409, admin force 200 + audit. */
    public function testClosedPeriodMatrix(): void
    {
        $sid = $this->mkSupplier('double_entry');
        $this->mkPeriod($sid, 2024, 'closed');
        $this->mkPeriod($sid, 2026, 'open');
        $invoiceId = $this->mkInvoice($sid, 'draft', '2024-06-15');

        $client = $this->mkUserWithToken('client', $sid);
        $accountant = $this->mkUserWithToken('accountant', $sid);
        $adminSession = $this->mkSessionUser('admin', $sid);
        $body = $this->invoiceBody($sid, '2024-06-15');

        $res = $this->request('PUT', "/api/invoices/$invoiceId", $client, $sid, $body);
        self::assertSame(403, $res->getStatusCode());
        self::assertSame('document_locked', $this->errorCode($res));

        // M6: client + ?force=1 → force IGNOROVÁN, stále 403
        $res = $this->request('PUT', "/api/invoices/$invoiceId?force=1", $client, $sid, $body);
        self::assertSame(403, $res->getStatusCode(), 'client ?force=1 je ignorován (M6)');
        self::assertSame('document_locked', $this->errorCode($res));

        $res = $this->request('PUT', "/api/invoices/$invoiceId", $accountant, $sid, $body);
        self::assertSame(409, $res->getStatusCode());
        self::assertSame('period_closed', $this->errorCode($res));

        $res = $this->sessionRequest('PUT', "/api/invoices/$invoiceId?force=1", $adminSession, $sid, $body);
        self::assertSame(200, $res->getStatusCode(), 'admin ?force=1 projde: ' . (string) $res->getBody());

        $cnt = $this->db->pdo()->prepare(
            "SELECT COUNT(*) FROM activity_log WHERE action = 'document_lock.force_override' AND entity_id = ?"
        );
        $cnt->execute([$invoiceId]);
        self::assertGreaterThan(0, (int) $cnt->fetchColumn(), 'force edit se audituje');

        // period_closing zamyká JEN klienta (resetApp — viz pozn. o per-request memoizaci)
        $this->db->pdo()->prepare("UPDATE accounting_periods SET status = 'closing' WHERE supplier_id = ? AND fiscal_year = 2024")
            ->execute([$sid]);
        $this->resetApp();
        $res = $this->request('PUT', "/api/invoices/$invoiceId", $client, $sid, $body);
        self::assertSame(403, $res->getStatusCode(), 'closing zamyká klienta');
        $res = $this->request('PUT', "/api/invoices/$invoiceId", $accountant, $sid, $body);
        self::assertSame(200, $res->getStatusCode(), 'closing účetní nebrzdí: ' . (string) $res->getBody());
    }

    /**
     * Úpravu vystavené faktury (?force=1) smí kromě správce i interní role s právem
     * `invoices.edit_issued`. Uzavřené období dál přebíjí jen správce a výchozí účetní
     * právo nemá.
     */
    public function testEditIssuedPermissionAllowsForceEditOnlyInOpenPeriod(): void
    {
        $sid = $this->mkSupplier('double_entry');
        $this->mkPeriod($sid, 2024, 'closed');
        $this->mkPeriod($sid, 2026, 'open');
        $openInvoice = $this->mkInvoice($sid, 'issued', '2026-05-01', 'P13-OPEN-1');
        $closedInvoice = $this->mkInvoice($sid, 'issued', '2024-06-15', 'P13-CLOSED-1');

        $editorRole = $this->mkCustomRole('__TEST úprava vystavených', [
            'invoices' => 2,
            'invoices.edit_issued' => 2,
        ]);
        $editor = $this->mkSessionUserWithRole($editorRole, $sid);
        $accountant = $this->mkSessionUser('accountant', $sid);
        $openBody = $this->invoiceBody($sid, '2026-05-01');

        $res = $this->sessionRequest('PUT', "/api/invoices/$openInvoice", $editor, $sid, $openBody);
        self::assertSame(409, $res->getStatusCode(), 'Bez ?force=1 zůstává vystavená faktura needitovatelná.');
        self::assertSame('not_editable', $this->errorCode($res));

        $res = $this->sessionRequest('PUT', "/api/invoices/$openInvoice?force=1", $accountant, $sid, $openBody);
        self::assertSame(409, $res->getStatusCode(), 'Výchozí účetní právo úpravy vystavené faktury nemá.');
        self::assertSame('not_editable', $this->errorCode($res));

        $res = $this->sessionRequest('PUT', "/api/invoices/$openInvoice?force=1", $editor, $sid, $openBody);
        self::assertSame(200, $res->getStatusCode(), 'Role s právem upraví vystavenou fakturu: ' . (string) $res->getBody());

        $res = $this->sessionRequest('PUT', "/api/invoices/$closedInvoice?force=1", $editor, $sid, $this->invoiceBody($sid, '2024-06-15'));
        self::assertSame(409, $res->getStatusCode(), 'Uzavřené období přebíjí jen správce.');
        self::assertSame('period_closed', $this->errorCode($res));
    }

    /**
     * Podané přiznání k DPH (nebo KH) uzavírá období jen pro delegovanou roli: částky,
     * data ani položky vystavené faktury v něm role s `invoices.edit_issued` nezmění,
     * a to ani přesunem data do podaného období. Neúčetní úprava projde a správce
     * zůstává beze změny.
     */
    public function testEditIssuedPermissionCannotChangeFinancialFieldsInFiledVatPeriod(): void
    {
        $sid = $this->mkSupplier('double_entry');
        $this->mkPeriod($sid, 2026, 'open');
        $filedInvoice = $this->mkInvoice($sid, 'issued', '2026-05-01', 'P13-FILED-1');
        $openInvoice = $this->mkInvoice($sid, 'issued', '2026-06-01', 'P13-FILED-2');
        $this->fileVatReturn($sid, 2026, 5);

        $editorRole = $this->mkCustomRole('__TEST úprava vystavených v podaném období', [
            'invoices' => 2,
            'invoices.edit_issued' => 2,
        ]);
        $editor = $this->mkSessionUserWithRole($editorRole, $sid);
        $admin = $this->mkSessionUser('admin', $sid);

        $res = $this->sessionRequest('PUT', "/api/invoices/$filedInvoice?force=1", $editor, $sid, ['due_date' => '2026-07-31']);
        self::assertSame(409, $res->getStatusCode(), 'Finanční změna v podaném období: ' . (string) $res->getBody());
        self::assertSame('period_filed', $this->errorCode($res));
        self::assertStringContainsString('přiznání k DPH za 5/2026', (string) $res->getBody());

        $res = $this->sessionRequest('PUT', "/api/invoices/$openInvoice?force=1", $editor, $sid, ['tax_date' => '2026-05-20']);
        self::assertSame(409, $res->getStatusCode(), 'Přesun data do podaného období: ' . (string) $res->getBody());
        self::assertSame('period_filed', $this->errorCode($res));

        // effective_tax_date je generovaný sloupec, z těla se neukládá a kontrolu nesmí přebít.
        $res = $this->sessionRequest('PUT', "/api/invoices/$openInvoice?force=1", $editor, $sid,
            ['tax_date' => '2026-05-20', 'effective_tax_date' => '2026-06-15']);
        self::assertSame(409, $res->getStatusCode(), 'Podvržené effective_tax_date: ' . (string) $res->getBody());
        self::assertSame('period_filed', $this->errorCode($res));

        $res = $this->sessionRequest('PUT', "/api/invoices/$filedInvoice?force=1", $editor, $sid, ['note_below_items' => 'Jen poznámka']);
        self::assertSame(200, $res->getStatusCode(), 'Neúčetní úprava projde: ' . (string) $res->getBody());

        $res = $this->sessionRequest('PUT', "/api/invoices/$filedInvoice?force=1", $admin, $sid, ['due_date' => '2026-07-31']);
        self::assertSame(200, $res->getStatusCode(), 'Správce zůstává beze změny: ' . (string) $res->getBody());
    }

    public function testDerivedOssCannotEnterFiledQuarterThroughUnchangedReplacementItems(): void
    {
        [$sid, $invoiceId, $item] = $this->ossGuardFixture();
        $this->fileOssReturn($sid);
        $editor = $this->ossGuardEditor($sid);
        $before = $this->db->pdo()->query("SELECT * FROM invoice_items WHERE invoice_id = $invoiceId")->fetchAll(\PDO::FETCH_ASSOC);
        $res = $this->sessionRequest('PUT', "/api/invoices/$invoiceId?force=1", $editor, $sid, ['items' => [$item]]);
        self::assertSame(409, $res->getStatusCode(), (string) $res->getBody());
        self::assertSame('period_filed', $this->errorCode($res));
        self::assertSame($before, $this->db->pdo()->query("SELECT * FROM invoice_items WHERE invoice_id = $invoiceId")->fetchAll(\PDO::FETCH_ASSOC));
    }

    public function testDerivedOssCannotMoveIntoFiledQuarter(): void
    {
        [$sid, $invoiceId, $item] = $this->ossGuardFixture('2026-07-15');
        $this->fileOssReturn($sid);
        $editor = $this->ossGuardEditor($sid);
        $res = $this->sessionRequest('PUT', "/api/invoices/$invoiceId?force=1", $editor, $sid,
            ['tax_date' => '2026-05-15', 'items' => [$item]]);
        self::assertSame(409, $res->getStatusCode(), (string) $res->getBody());
        self::assertSame('period_filed', $this->errorCode($res));
        self::assertSame('2026-07-15', $this->db->pdo()->query("SELECT tax_date FROM invoices WHERE id = $invoiceId")->fetchColumn());
    }

    public function testFiledOssAllowsExplicitNonOssAndHeaderOnlyNote(): void
    {
        [$sid, $invoiceId, $item] = $this->ossGuardFixture();
        $this->fileOssReturn($sid);
        $editor = $this->ossGuardEditor($sid);
        $res = $this->sessionRequest('PUT', "/api/invoices/$invoiceId?force=1", $editor, $sid, ['note_below_items' => 'Poznámka']);
        self::assertSame(200, $res->getStatusCode(), (string) $res->getBody());
        $item['oss_applicable'] = false;
        $item['unit_price_without_vat'] = 200;
        $res = $this->sessionRequest('PUT', "/api/invoices/$invoiceId?force=1", $editor, $sid, ['items' => [$item]]);
        self::assertSame(200, $res->getStatusCode(), (string) $res->getBody());
        $row = $this->db->pdo()->query("SELECT oss_applicable, unit_price_without_vat FROM invoice_items WHERE invoice_id = $invoiceId")->fetch(\PDO::FETCH_ASSOC);
        self::assertSame(0, (int) $row['oss_applicable']);
        self::assertSame(200.0, (float) $row['unit_price_without_vat']);
    }

    public function testAdminCanPersistDerivedOssInFiledQuarter(): void
    {
        [$sid, $invoiceId, $item] = $this->ossGuardFixture();
        $this->fileOssReturn($sid);
        $admin = $this->mkSessionUser('admin', $sid);
        $res = $this->sessionRequest('PUT', "/api/invoices/$invoiceId?force=1", $admin, $sid, ['items' => [$item]]);
        self::assertSame(200, $res->getStatusCode(), (string) $res->getBody());
        $row = $this->db->pdo()->query("SELECT oss_applicable, oss_consumer_country, vat_rate_id FROM invoice_items WHERE invoice_id = $invoiceId")->fetch(\PDO::FETCH_ASSOC);
        self::assertSame(1, (int) $row['oss_applicable']);
        self::assertSame('PL', $row['oss_consumer_country']);
        self::assertNotSame($item['vat_rate_id'], (int) $row['vat_rate_id']);
        $rate = $this->db->pdo()->prepare('SELECT country, rate_percent FROM vat_rates WHERE id = ?');
        $rate->execute([(int) $row['vat_rate_id']]);
        $resolved = $rate->fetch(\PDO::FETCH_ASSOC);
        self::assertSame('PL', $resolved['country']);
        self::assertSame(23.0, (float) $resolved['rate_percent']);
    }

    public function testDerivedOssRequiresRepostPermissionForUnchangedRawItems(): void
    {
        [$sid, $invoiceId, $item] = $this->ossGuardFixture();
        $period = $this->mkPeriod($sid, 2026, 'open');
        $entryId = $this->mkPostedEntry($sid, 'invoice', $invoiceId, $period, '2026-05-15', true);
        $editor = $this->ossGuardEditor($sid);
        $res = $this->sessionRequest('PUT', "/api/invoices/$invoiceId?force=1", $editor, $sid, ['items' => [$item]]);
        self::assertSame(403, $res->getStatusCode(), (string) $res->getBody());
        self::assertSame('journal_post_forbidden', $this->errorCode($res));
        self::assertSame(0, (int) $this->db->pdo()->query("SELECT oss_applicable FROM invoice_items WHERE invoice_id = $invoiceId")->fetchColumn());
        self::assertNotNull($this->db->pdo()->query("SELECT posted_at FROM journal_entries WHERE id = $entryId")->fetchColumn());
    }

    public function testNotesOnlyRejectsDerivedOssForUnchangedRawItems(): void
    {
        [$sid, $invoiceId, $item] = $this->ossGuardFixture();
        $period = $this->mkPeriod($sid, 2026, 'closed');
        $this->mkPostedEntry($sid, 'invoice', $invoiceId, $period, '2026-05-15', true);
        $admin = $this->mkSessionUser('admin', $sid);
        $res = $this->sessionRequest('PUT', "/api/invoices/$invoiceId?force=1&force_mode=notes_only", $admin, $sid, ['items' => [$item]]);
        self::assertSame(422, $res->getStatusCode(), (string) $res->getBody());
        self::assertSame('financial_change_not_allowed', $this->errorCode($res));
        self::assertSame(0, (int) $this->db->pdo()->query("SELECT oss_applicable FROM invoice_items WHERE invoice_id = $invoiceId")->fetchColumn());
    }

    public function testDerivedOssUsesDefaultedTaxDateForFiledQuarter(): void
    {
        [$sid, $invoiceId, $item] = $this->ossGuardFixture('2025-05-15');
        $this->fileOssReturn($sid, (int) date('Y'), (int) ceil((int) date('n') / 3));
        $editor = $this->ossGuardEditor($sid);
        $res = $this->sessionRequest('PUT', "/api/invoices/$invoiceId?force=1", $editor, $sid,
            ['tax_date' => null, 'items' => [$item]]);
        self::assertSame(409, $res->getStatusCode(), (string) $res->getBody());
        self::assertSame('period_filed', $this->errorCode($res));
        self::assertSame('2025-05-15', $this->db->pdo()->query("SELECT tax_date FROM invoices WHERE id = $invoiceId")->fetchColumn());
    }

    public function testWalkInOssGuardRejectsBeforeCreatingCustomer(): void
    {
        [$sid, $invoiceId, $item] = $this->ossGuardFixture();
        $pdo = $this->db->pdo();
        $pdo->prepare("UPDATE supplier SET country_id = (SELECT id FROM countries WHERE iso2 = 'PL'), oss_identification_country = 'PL' WHERE id = ?")->execute([$sid]);
        $item['vat_rate_id'] = (int) $pdo->query("SELECT id FROM vat_rates WHERE country = 'CZ' AND rate_percent = 21 AND is_reverse_charge = 0 ORDER BY id LIMIT 1")->fetchColumn();
        self::assertGreaterThan(0, $item['vat_rate_id']);
        $this->fileOssReturn($sid);
        $editor = $this->ossGuardEditor($sid);
        $before = (int) $pdo->query("SELECT COUNT(*) FROM clients WHERE supplier_id = $sid")->fetchColumn();
        $res = $this->sessionRequest('PUT', "/api/invoices/$invoiceId?force=1", $editor, $sid,
            ['client_id' => null, 'is_simplified' => true, 'items' => [$item]]);
        self::assertSame(409, $res->getStatusCode(), (string) $res->getBody());
        self::assertSame('period_filed', $this->errorCode($res));
        self::assertSame($before, (int) $pdo->query("SELECT COUNT(*) FROM clients WHERE supplier_id = $sid")->fetchColumn());
        self::assertSame($this->clientIds[$sid], (int) $pdo->query("SELECT client_id FROM invoices WHERE id = $invoiceId")->fetchColumn());
    }

    public function testWalkInNonOssForceEditStillCreatesOneCustomer(): void
    {
        [$sid, $invoiceId, $item] = $this->ossGuardFixture();
        $this->fileOssReturn($sid);
        $editor = $this->ossGuardEditor($sid);
        $item['oss_applicable'] = false;
        $item['vat_rate_id'] = (int) $this->db->pdo()->query("SELECT id FROM vat_rates WHERE country = 'CZ' AND rate_percent = 21 AND is_reverse_charge = 0 ORDER BY id LIMIT 1")->fetchColumn();
        $body = ['client_id' => null, 'is_simplified' => true, 'items' => [$item]];
        foreach ([1, 2] as $attempt) {
            $res = $this->sessionRequest('PUT', "/api/invoices/$invoiceId?force=1", $editor, $sid, $body);
            self::assertSame(200, $res->getStatusCode(), (string) $res->getBody());
            self::assertSame(1, (int) $this->db->pdo()->query("SELECT COUNT(*) FROM clients WHERE supplier_id = $sid AND is_walk_in = 1")->fetchColumn());
            self::assertSame(0, (int) $this->db->pdo()->query("SELECT oss_applicable FROM invoice_items WHERE invoice_id = $invoiceId")->fetchColumn());
        }
    }

    public function testFiledOssCannotBeRemovedByExplicitFalse(): void
    {
        [$sid, $invoiceId, $item, $plRate] = $this->ossGuardFixture();
        $this->db->pdo()->prepare("UPDATE invoice_items SET oss_applicable = 1, oss_consumer_country = 'PL', oss_rate_type = 'standard', oss_supply_type = 'goods', vat_rate_id = ? WHERE invoice_id = ?")->execute([$plRate, $invoiceId]);
        $this->fileOssReturn($sid);
        $editor = $this->ossGuardEditor($sid);
        $item['oss_applicable'] = false;
        $res = $this->sessionRequest('PUT', "/api/invoices/$invoiceId?force=1", $editor, $sid, ['items' => [$item]]);
        self::assertSame(409, $res->getStatusCode(), (string) $res->getBody());
        self::assertSame('period_filed', $this->errorCode($res));
        self::assertSame(1, (int) $this->db->pdo()->query("SELECT oss_applicable FROM invoice_items WHERE invoice_id = $invoiceId")->fetchColumn());
    }

    public function testNotesOnlyAllowsUnchangedExplicitNonOssItems(): void
    {
        [$sid, $invoiceId, $item] = $this->ossGuardFixture();
        $period = $this->mkPeriod($sid, 2026, 'closed');
        $entryId = $this->mkPostedEntry($sid, 'invoice', $invoiceId, $period, '2026-05-15', true);
        $admin = $this->mkSessionUser('admin', $sid);
        $item['oss_applicable'] = false;
        $res = $this->sessionRequest('PUT', "/api/invoices/$invoiceId?force=1&force_mode=notes_only", $admin, $sid,
            ['items' => [$item], 'note_below_items' => 'Poznámka']);
        self::assertSame(200, $res->getStatusCode(), (string) $res->getBody());
        self::assertSame(0, (int) $this->db->pdo()->query("SELECT oss_applicable FROM invoice_items WHERE invoice_id = $invoiceId")->fetchColumn());
        self::assertNotNull($this->db->pdo()->query("SELECT posted_at FROM journal_entries WHERE id = $entryId")->fetchColumn());
    }

    public function testFiledDerivedOssRejectionDoesNotRenumberInvoice(): void
    {
        [$sid, $invoiceId, $item] = $this->ossGuardFixture();
        $this->fileOssReturn($sid);
        $editor = $this->ossGuardEditor($sid);
        $pdo = $this->db->pdo();
        $before = $pdo->query("SELECT invoice_type, varsymbol FROM invoices WHERE id = $invoiceId")->fetch(\PDO::FETCH_ASSOC);
        $counters = $pdo->query("SELECT * FROM invoice_counters WHERE supplier_id = $sid ORDER BY invoice_type, period, client_id, revenue_category_id")->fetchAll(\PDO::FETCH_ASSOC);
        $res = $this->sessionRequest('PUT', "/api/invoices/$invoiceId?force=1", $editor, $sid,
            ['invoice_type' => 'proforma', 'items' => [$item]]);
        self::assertSame(409, $res->getStatusCode(), (string) $res->getBody());
        self::assertSame('period_filed', $this->errorCode($res));
        self::assertSame($before, $pdo->query("SELECT invoice_type, varsymbol FROM invoices WHERE id = $invoiceId")->fetch(\PDO::FETCH_ASSOC));
        self::assertSame($counters, $pdo->query("SELECT * FROM invoice_counters WHERE supplier_id = $sid ORDER BY invoice_type, period, client_id, revenue_category_id")->fetchAll(\PDO::FETCH_ASSOC));
    }

    private function ossGuardFixture(string $date = '2026-05-15'): array
    {
        $sid = $this->mkSupplier('double_entry');
        $pdo = $this->db->pdo();
        $pdo->prepare("UPDATE supplier SET country_id = (SELECT id FROM countries WHERE iso2 = 'CZ'), oss_enabled = 1, oss_valid_from = '2020-01-01', oss_valid_to = NULL, oss_identification_country = 'CZ' WHERE id = ?")->execute([$sid]);
        $pdo->prepare("UPDATE clients SET country_id = (SELECT id FROM countries WHERE iso2 = 'PL'), dic = NULL, is_vat_payer = 0 WHERE id = ?")->execute([$this->clientIds[$sid]]);
        $rates = [];
        foreach (['CZ', 'PL'] as $country) {
            $code = 'LOCK-OSS-' . $country . '-' . bin2hex(random_bytes(4));
            $pdo->prepare("INSERT INTO vat_rates (code, rate_percent, country, label_cs, label_en, is_default, is_reverse_charge, valid_from, display_order) VALUES (?, 23, ?, ?, ?, 0, 0, '2026-01-01', 10000)")
                ->execute([$code, $country, $code, $code]);
            $rates[$country] = (int) $pdo->lastInsertId();
            $this->ossVatRateIds[] = $rates[$country];
        }
        $invoiceId = $this->mkInvoice($sid, 'issued', $date, 'TEST-OSS-' . bin2hex(random_bytes(4)), 123);
        $pdo->prepare("INSERT INTO invoice_items (invoice_id, description, quantity, unit, unit_price_without_vat, vat_rate_id, vat_rate_snapshot, total_without_vat, total_vat, total_with_vat, order_index) VALUES (?, 'Položka OSS', 1, 'ks', 100, ?, 23, 100, 23, 123, 0)")->execute([$invoiceId, $rates['CZ']]);
        $item = ['description' => 'Položka OSS', 'quantity' => 1, 'unit' => 'ks', 'unit_price_without_vat' => 100, 'vat_rate_id' => $rates['CZ']];
        return [$sid, $invoiceId, $item, $rates['PL']];
    }

    private function ossGuardEditor(int $sid): array
    {
        return $this->mkSessionUserWithRole($this->mkCustomRole('__TEST OSS edit', ['invoices' => 2, 'invoices.edit_issued' => 2]), $sid);
    }

    private function fileOssReturn(int $sid, int $year = 2026, int $quarter = 2): void
    {
        $this->db->pdo()->prepare("INSERT INTO tax_submissions (supplier_id, form_code, period_year, period_month, period_quarter, form_variant, xml_content, xml_size_bytes, xml_sha256, validation_status, status, submitted_at, summary_json, generated_by) VALUES (?, 'ossei1', ?, NULL, ?, 'B', '<test/>', 7, ?, 'passed', 'submitted', NOW(), '{}', NULL)")
            ->execute([$sid, $year, $quarter, hash('sha256', 'test')]);
    }

    /** Scénář 5 (H1): create/issue/přesun data do zavřeného období. */
    public static function closedStatuses(): iterable
    {
        yield 'closed' => ['closed'];
        yield 'reviewed' => ['reviewed'];
        yield 'approved' => ['approved'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('closedStatuses')]
    public function testH1CreateIssueAndDateMoveIntoClosedPeriod(string $status): void
    {
        $sid = $this->mkSupplier('double_entry');
        $this->mkPeriod($sid, 2024, $status);
        $this->mkPeriod($sid, 2026, 'open');

        $client = $this->mkUserWithToken('client', $sid);
        $accountant = $this->mkUserWithToken('accountant', $sid);
        $adminSession = $this->mkSessionUser('admin', $sid);

        // create s datem v zavřeném období
        $res = $this->request('POST', '/api/invoices', $client, $sid, $this->invoiceBody($sid, '2024-06-15'));
        self::assertSame(403, $res->getStatusCode());
        self::assertSame('document_locked', $this->errorCode($res));
        $res = $this->request('POST', '/api/invoices', $accountant, $sid, $this->invoiceBody($sid, '2024-06-15'));
        self::assertSame(409, $res->getStatusCode());
        self::assertSame('period_closed', $this->errorCode($res));

        // issue draftu s datem v zavřeném období
        $draft2024 = $this->mkInvoice($sid, 'draft', '2024-06-15');
        $this->addInvoiceItem($sid, $draft2024);
        $res = $this->request('POST', "/api/invoices/$draft2024/issue", $client, $sid);
        self::assertSame(403, $res->getStatusCode());
        self::assertSame('document_locked', $this->errorCode($res));
        $res = $this->request('POST', "/api/invoices/$draft2024/issue", $accountant, $sid);
        self::assertSame(409, $res->getStatusCode());
        self::assertSame('period_closed', $this->errorCode($res));

        // přesun data odemčeného draftu DO zavřeného období
        $draft2026 = $this->mkInvoice($sid, 'draft', '2026-05-01');
        $moveBody = $this->invoiceBody($sid, '2024-06-15');
        $res = $this->request('PUT', "/api/invoices/$draft2026", $client, $sid, $moveBody);
        self::assertSame(403, $res->getStatusCode(), 'klient nesmí datum přesunout do zavřeného období (H1)');
        self::assertSame('document_locked', $this->errorCode($res));
        $res = $this->request('PUT', "/api/invoices/$draft2026", $accountant, $sid, $moveBody);
        self::assertSame(409, $res->getStatusCode());
        self::assertSame('period_closed', $this->errorCode($res));
        $res = $this->sessionRequest('PUT', "/api/invoices/$draft2026?force=1", $adminSession, $sid, $moveBody);
        self::assertSame(200, $res->getStatusCode(), 'admin force přesun projde: ' . (string) $res->getBody());
    }

    /**
     * Schválení výkazu koncept rovnou vystaví, proto musí respektovat týž zámek období
     * jako ruční vystavení: klient 403, účetní 409, správce jen s ?force=1 z relace.
     * Odmítnutí nesmí zanechat zapsané schválení. V otevřeném období schválení
     * vystaví fakturu jako dřív. Schválení zákazníkem přes veřejný odkaz se zapíše,
     * koncept v uzavřeném období ale nevystaví.
     */
    public function testApprovalIssueRespectsClosedPeriod(): void
    {
        $sid = $this->mkSupplier('double_entry');
        $this->mkPeriod($sid, 2024, 'closed');
        $this->mkPeriod($sid, 2026, 'open');
        // Bez e-mailu odběratele se vystavená faktura nikam neodesílá.
        $this->db->pdo()->prepare('UPDATE clients SET main_email = NULL WHERE id = ?')->execute([$this->clientIds[$sid]]);

        $client = $this->mkUserWithToken('client', $sid);
        $accountant = $this->mkUserWithToken('accountant', $sid);
        $adminSession = $this->mkSessionUser('admin', $sid);
        $approve = ['status' => 'approved'];
        $state = function (int $id): array {
            $stmt = $this->db->pdo()->prepare('SELECT status, approval_status, varsymbol FROM invoices WHERE id = ?');
            $stmt->execute([$id]);
            return $stmt->fetch(\PDO::FETCH_ASSOC);
        };

        $mkDraft = function (string $date) use ($sid): int {
            $id = $this->mkInvoice($sid, 'draft', $date);
            $this->addInvoiceItem($sid, $id);
            $this->db->pdo()->prepare(
                'UPDATE invoices SET total_without_vat = 100, total_vat = 21, total_with_vat = 121 WHERE id = ?'
            )->execute([$id]);
            return $id;
        };

        $draft2024 = $mkDraft('2024-06-15');
        $res = $this->request('PUT', "/api/invoices/$draft2024/approval-status", $client, $sid, $approve);
        self::assertSame(403, $res->getStatusCode(), (string) $res->getBody());
        self::assertSame('document_locked', $this->errorCode($res));
        $res = $this->request('PUT', "/api/invoices/$draft2024/approval-status", $accountant, $sid, $approve);
        self::assertSame(409, $res->getStatusCode(), (string) $res->getBody());
        self::assertSame('period_closed', $this->errorCode($res));
        self::assertSame('draft', $state($draft2024)['status']);
        self::assertNotSame('approved', $state($draft2024)['approval_status'], 'odmítnutí nesmí zapsat schválení');
        self::assertNull($state($draft2024)['varsymbol'], 'odmítnutí nesmí přidělit číslo');

        $res = $this->sessionRequest('PUT', "/api/invoices/$draft2024/approval-status?force=1", $adminSession, $sid, $approve);
        self::assertSame(200, $res->getStatusCode(), 'správce s force schválí: ' . (string) $res->getBody());
        self::assertSame('issued', $state($draft2024)['status'], (string) $res->getBody());

        $draft2026 = $mkDraft('2026-05-15');

        $res = $this->request('PUT', "/api/invoices/$draft2026/approval-status", $accountant, $sid, $approve);
        self::assertSame(200, $res->getStatusCode(), 'otevřené období: ' . (string) $res->getBody());
        self::assertSame('issued', $state($draft2026)['status'], (string) $res->getBody());

        // Veřejný odkaz zákazníka: schválení se zapíše, koncept v uzavřeném období zůstane.
        $public2024 = $mkDraft('2024-07-15');

        $token = bin2hex(random_bytes(24));
        $this->db->pdo()->prepare(
            "UPDATE invoices SET approval_status = 'requested', approval_token = ?,
                    approval_token_expires_at = DATE_ADD(NOW(), INTERVAL 7 DAY) WHERE id = ?"
        )->execute([$token, $public2024]);
        $container = \MyInvoice\Bootstrap::buildContainer();
        $captcha = $this->createStub(\MyInvoice\Service\Captcha\TurnstileVerifier::class);
        $captcha->method('verify')->willReturn(true);
        $tenantGuard = $this->createStub(\MyInvoice\Service\Tenant\PublicTenantGuard::class);
        $tenantGuard->method('allows')->willReturn(true);
        $autoIssue = $this->createMock(\MyInvoice\Service\Invoice\AutoIssueAndSendService::class);
        $autoIssue->expects(self::never())->method('run');
        $decide = new \MyInvoice\Action\Approval\PublicApprovalDecideAction(
            $container->get(\MyInvoice\Repository\InvoiceRepository::class),
            $captcha,
            $container->get(\MyInvoice\Service\ActivityLogger::class),
            new \MyInvoice\Service\IpMatcher(),
            $autoIssue,
            $tenantGuard,
            $container->get(\MyInvoice\Service\Accounting\DocumentLockService::class),
        );
        $req = (new ServerRequestFactory())
            ->createServerRequest('POST', "/api/public/approval/$token/decide", ['REMOTE_ADDR' => '127.0.0.1'])
            ->withParsedBody(['decision' => 'approve', 'decided_by_email' => 'zakaznik@example.com']);
        $res = $decide($req, new \Slim\Psr7\Response(), ['token' => $token]);
        self::assertSame(200, $res->getStatusCode(), (string) $res->getBody());
        self::assertSame('draft', $state($public2024)['status']);
        self::assertSame('approved', $state($public2024)['approval_status']);
    }

    /** Scénář 6: reverse zápisu odemkne doklad (booked_at NULL + audit R9), client PUT zase 200. */
    public function testReverseUnlocksDocument(): void
    {
        $sid = $this->mkSupplier('double_entry');
        $periodId = $this->mkPeriod($sid, 2026, 'open');
        $invoiceId = $this->mkInvoice($sid, 'draft', '2026-05-10');
        $entryId = $this->mkPostedEntry($sid, 'invoice', $invoiceId, $periodId, '2026-05-10', withLines: true);
        $this->db->pdo()->prepare('UPDATE invoices SET booked_at = NOW(), booked_by = NULL WHERE id = ?')
            ->execute([$invoiceId]);

        $client = $this->mkUserWithToken('client', $sid);
        $accountantSession = $this->mkSessionUser('accountant', $sid);

        $res = $this->request('PUT', "/api/invoices/$invoiceId", $client, $sid, $this->invoiceBody($sid, '2026-05-10'));
        self::assertSame(403, $res->getStatusCode());
        self::assertSame('document_locked', $this->errorCode($res));

        // Reverse přes reálný endpoint (session — /api/accounting není v bearer allowlistu)
        $res = $this->sessionRequest('POST', "/api/accounting/journal/$entryId/reverse", $accountantSession, $sid, []);
        self::assertSame(201, $res->getStatusCode(), 'reverse: ' . (string) $res->getBody());

        $bookedAt = $this->db->pdo()->query("SELECT booked_at FROM invoices WHERE id = $invoiceId")->fetchColumn();
        self::assertNull($bookedAt === false ? null : $bookedAt, 'reverse maže booked_at');

        $cnt = $this->db->pdo()->prepare(
            "SELECT COUNT(*) FROM activity_log WHERE action = 'document_lock.unlocked_by_reverse' AND entity_id = ?"
        );
        $cnt->execute([$invoiceId]);
        self::assertSame(1, (int) $cnt->fetchColumn(), 'odemknutí reversem se audituje (R9)');

        // resetApp — viz pozn. o per-request memoizaci DocumentLockService
        $this->resetApp();
        $res = $this->request('PUT', "/api/invoices/$invoiceId", $client, $sid, $this->invoiceBody($sid, '2026-05-10'));
        self::assertSame(200, $res->getStatusCode(), 'po reversu client zase edituje: ' . (string) $res->getBody());
    }

    /** Scénář 8 (L1): zaúčtování mezi guard-checkem a UPDATE → updateDraft vrací false. */
    public function testOptimisticLockOnUpdate(): void
    {
        $sid = $this->mkSupplier('tax_evidence');
        $invoiceId = $this->mkInvoice($sid, 'draft', '2026-05-10');

        $repo = new InvoiceRepository(
            $this->db,
            new \MyInvoice\Repository\TaxConstantsRepository($this->db),
            new \MyInvoice\Service\Invoice\OverduePolicy(new \MyInvoice\Infrastructure\Config\Config([])),
        );
        $data = [
            'client_id'   => $this->clientIds[$sid],
            'issue_date'  => '2026-05-10',
            'tax_date'    => '2026-05-10',
            'due_date'    => '2026-05-24',
            'currency_id' => $this->currencyIds[$sid],
            'language'    => 'cs',
        ];

        self::assertTrue($repo->updateDraft($invoiceId, $data, true), 'nezamčený doklad projde');

        // Simulace TOCTOU: účetní zaúčtovala mezi guardem a zápisem
        $this->db->pdo()->prepare('UPDATE invoices SET booked_at = NOW() WHERE id = ?')->execute([$invoiceId]);
        self::assertFalse($repo->updateDraft($invoiceId, $data, true), 'booked_at IS NULL podmínka → 0 řádek → 409');

        // Bez requireUnbooked (staff cesta) se chování nemění
        self::assertTrue($repo->updateDraft($invoiceId, $data));
    }

    /** PF transition zpět na received odemyká omylem zabookovaný doklad (unbook pro PF neexistuje). */
    public function testTransitionBackToReceivedUnbooksPurchaseInvoice(): void
    {
        $sid = $this->mkSupplier('tax_evidence');
        $accountant = $this->mkUserWithToken('accountant', $sid);
        $client = $this->mkUserWithToken('client', $sid);

        $pi = $this->mkPurchaseInvoice($sid, 'received');

        // Účetní omylem zabookuje…
        $accountantSession = $this->mkSessionUser('accountant', $sid);
        $res = $this->sessionRequest('POST', "/api/purchase-invoices/$pi/transition", $accountantSession, $sid, ['target' => 'booked']);
        self::assertSame(200, $res->getStatusCode(), 'booked: ' . (string) $res->getBody());
        $row = $this->db->pdo()->query("SELECT booked_at, booked_by FROM purchase_invoices WHERE id = $pi")->fetch(\PDO::FETCH_ASSOC);
        self::assertNotNull($row['booked_at']);
        self::assertNotNull($row['booked_by']);

        // …a vrátí zpět: booked → paid → received maže booked_at/booked_by (žádný posted zápis)
        $res = $this->request('POST', "/api/purchase-invoices/$pi/transition", $accountant, $sid, ['target' => 'paid']);
        self::assertSame(200, $res->getStatusCode(), (string) $res->getBody());
        $res = $this->request('POST', "/api/purchase-invoices/$pi/transition", $accountant, $sid, ['target' => 'received']);
        self::assertSame(200, $res->getStatusCode(), (string) $res->getBody());
        $row = $this->db->pdo()->query("SELECT booked_at, booked_by FROM purchase_invoices WHERE id = $pi")->fetch(\PDO::FETCH_ASSOC);
        self::assertNull($row['booked_at'], 'transition na received odemyká omylem zabookovanou PF');
        self::assertNull($row['booked_by']);

        // Klient zase pracuje (resetApp — stale memoizace zámku z booked fáze)
        $this->resetApp();
        $res = $this->request('POST', "/api/purchase-invoices/$pi/transition", $client, $sid, ['target' => 'paid']);
        self::assertSame(200, $res->getStatusCode(), 'po odemknutí client zase pracuje: ' . (string) $res->getBody());
    }

    /** PF transition na received s aktivním posted zápisem booked_at NEmaže (still_posted sémantika). */
    public function testTransitionToReceivedKeepsBookedWhenStillPosted(): void
    {
        $sid = $this->mkSupplier('double_entry');
        $periodId = $this->mkPeriod($sid, 2026, 'open');
        $accountant = $this->mkUserWithToken('accountant', $sid);

        $pi = $this->mkPurchaseInvoice($sid, 'paid');
        $this->db->pdo()->prepare('UPDATE purchase_invoices SET booked_at = NOW() WHERE id = ?')->execute([$pi]);
        $this->mkPostedEntry($sid, 'purchase_invoice', $pi, $periodId, '2026-05-05');

        $res = $this->request('POST', "/api/purchase-invoices/$pi/transition", $accountant, $sid, ['target' => 'received']);
        self::assertSame(200, $res->getStatusCode(), (string) $res->getBody());
        $bookedAt = $this->db->pdo()->query("SELECT booked_at FROM purchase_invoices WHERE id = $pi")->fetchColumn();
        self::assertNotEmpty($bookedAt, 'aktivní posted zápis drží zámek — booked_at zůstává (nejdřív reverse)');
    }

    /** Matice §4.3: staff PF transition na booked/cancelled je period-gated (409P / admin force). */
    public function testStaffPurchaseTransitionToBookedIsPeriodGated(): void
    {
        $sid = $this->mkSupplier('double_entry');
        $this->mkPeriod($sid, 2024, 'closed');
        $this->mkPeriod($sid, 2026, 'open');
        $accountant = $this->mkUserWithToken('accountant', $sid);
        $accountantSession = $this->mkSessionUser('accountant', $sid);
        $adminSession = $this->mkSessionUser('admin', $sid);

        $pi = $this->mkPurchaseInvoice($sid, 'received', '2024-06-15');

        // booked/cancelled = účetní akt → v zavřeném období 409 period_closed
        $res = $this->sessionRequest('POST', "/api/purchase-invoices/$pi/transition", $accountantSession, $sid, ['target' => 'booked']);
        self::assertSame(409, $res->getStatusCode(), (string) $res->getBody());
        self::assertSame('period_closed', $this->errorCode($res));
        $res = $this->request('POST', "/api/purchase-invoices/$pi/transition", $accountant, $sid, ['target' => 'cancelled']);
        self::assertSame(409, $res->getStatusCode());
        self::assertSame('period_closed', $this->errorCode($res));

        // received ⇄ paid period-gated NENÍ (matice: ✓ i v zavřeném období)
        $res = $this->request('POST', "/api/purchase-invoices/$pi/transition", $accountant, $sid, ['target' => 'paid']);
        self::assertSame(200, $res->getStatusCode(), 'received→paid staff nebrzdí: ' . (string) $res->getBody());
        $res = $this->request('POST', "/api/purchase-invoices/$pi/transition", $accountant, $sid, ['target' => 'received']);
        self::assertSame(200, $res->getStatusCode(), (string) $res->getBody());

        // admin ?force=1 projde + audit
        $res = $this->sessionRequest('POST', "/api/purchase-invoices/$pi/transition?force=1", $adminSession, $sid, ['target' => 'booked']);
        self::assertSame(200, $res->getStatusCode(), 'admin force booked: ' . (string) $res->getBody());
        $cnt = $this->db->pdo()->prepare(
            "SELECT COUNT(*) FROM activity_log WHERE action = 'document_lock.force_override' AND entity_id = ?"
        );
        $cnt->execute([$pi]);
        self::assertGreaterThan(0, (int) $cnt->fetchColumn(), 'force transition se audituje');
        self::assertNotEmpty($this->db->pdo()->query("SELECT booked_at FROM purchase_invoices WHERE id = $pi")->fetchColumn());
    }

    /** H1: clone nesmí založit draft s issue_date v zavřeném období (mrtvý draft pro klienta). */
    public function testH1CloneIntoClosedPeriod(): void
    {
        $sid = $this->mkSupplier('double_entry');
        $this->mkPeriod($sid, 2024, 'closed');
        $this->mkPeriod($sid, 2026, 'open');
        $client = $this->mkUserWithToken('client', $sid);
        $accountant = $this->mkUserWithToken('accountant', $sid);

        $src = $this->mkInvoice($sid, 'issued', '2026-05-01', varsymbol: '2026101');

        $res = $this->request('POST', "/api/invoices/$src/clone", $client, $sid, ['issue_date' => '2024-06-15']);
        self::assertSame(403, $res->getStatusCode(), (string) $res->getBody());
        self::assertSame('document_locked', $this->errorCode($res));

        $res = $this->request('POST', "/api/invoices/$src/clone", $accountant, $sid, ['issue_date' => '2024-06-15']);
        self::assertSame(409, $res->getStatusCode());
        self::assertSame('period_closed', $this->errorCode($res));

        // Datum v otevřeném období projde i klientovi
        $res = $this->request('POST', "/api/invoices/$src/clone", $client, $sid, ['issue_date' => '2026-06-01']);
        self::assertSame(201, $res->getStatusCode(), 'clone do otevřeného období: ' . (string) $res->getBody());
        self::assertGreaterThan(0, (int) ($this->json($res)['draft_id'] ?? 0));
    }

    /** L1: zaúčtování mezi guard-checkem a UPDATE u storna → 409, doklad nezměněn. */
    public function testOptimisticLockOnCancel(): void
    {
        $sid = $this->mkSupplier('tax_evidence');
        $client = $this->mkUserWithToken('client', $sid);
        $invoiceId = $this->mkInvoice($sid, 'issued', '2026-05-10', varsymbol: '2026201');

        // Prime memoizace zámku (odemčeno) — GET detail = guard-check před TOCTOU oknem
        $res = $this->request('GET', "/api/invoices/$invoiceId", $client, $sid);
        self::assertSame(200, $res->getStatusCode());
        self::assertFalse($this->json($res)['locked']['is_locked'] ?? true);

        // Účetní zaúčtuje „mezi guardem a zápisem" (stejná app instance = stale memoizace)
        $this->db->pdo()->prepare('UPDATE invoices SET booked_at = NOW() WHERE id = ?')->execute([$invoiceId]);

        $res = $this->request('POST', "/api/invoices/$invoiceId/cancel", $client, $sid, ['mode' => 'internal']);
        self::assertSame(409, $res->getStatusCode(), (string) $res->getBody());
        self::assertSame('document_locked', $this->errorCode($res));
        self::assertSame(
            'issued',
            $this->db->pdo()->query("SELECT status FROM invoices WHERE id = $invoiceId")->fetchColumn(),
            'L1 storno neprošlo — status nezměněn',
        );
    }

    /** L1: zaúčtování mezi guard-checkem a UPDATE u PF transition received→paid → 409. */
    public function testOptimisticLockOnPurchaseTransition(): void
    {
        $sid = $this->mkSupplier('tax_evidence');
        $client = $this->mkUserWithToken('client', $sid);
        $pi = $this->mkPurchaseInvoice($sid, 'received');

        // Prime memoizace zámku (odemčeno)
        $res = $this->request('GET', "/api/purchase-invoices/$pi", $client, $sid);
        self::assertSame(200, $res->getStatusCode());
        self::assertFalse($this->json($res)['locked']['is_locked'] ?? true);

        // Účetní zaúčtuje „mezi guardem a zápisem"
        $this->db->pdo()->prepare('UPDATE purchase_invoices SET booked_at = NOW() WHERE id = ?')->execute([$pi]);

        $res = $this->request('POST', "/api/purchase-invoices/$pi/transition", $client, $sid, ['target' => 'paid']);
        self::assertSame(409, $res->getStatusCode(), (string) $res->getBody());
        self::assertSame('document_locked', $this->errorCode($res));
        self::assertSame(
            'received',
            $this->db->pdo()->query("SELECT status FROM purchase_invoices WHERE id = $pi")->fetchColumn(),
            'L1 transition neprošla — status nezměněn',
        );
    }

    // ------------------------------------------------------------- fixtures

    private function mkSupplier(string $accountingMode): int
    {
        $pdo = $this->db->pdo();
        $stmt = $pdo->prepare(
            "INSERT INTO supplier (company_name, display_name, street, city, zip, country_id,
                                   is_vat_payer, email, default_currency_id, default_vat_rate_id,
                                   default_payment_due_days, default_hourly_rate, accounting_mode)
             SELECT '__TEST F6 lock', '__TEST F6 lock', street, city, zip, country_id,
                    0, email, default_currency_id, default_vat_rate_id,
                    default_payment_due_days, default_hourly_rate, ?
               FROM supplier WHERE id = ?"
        );
        $stmt->execute([$accountingMode, $this->sourceSupplier]);
        $sid = (int) $pdo->lastInsertId();
        $this->supplierIds[] = $sid;

        $pdo->prepare(
            "INSERT INTO currencies (supplier_id, code, label, symbol, name_cs, name_en, decimals, is_active, is_default)
             VALUES (?, 'CZK', 'CZK — test', 'Kč', 'Česká koruna', 'Czech Koruna', 2, 1, 1)"
        )->execute([$sid]);
        $currencyId = (int) $pdo->lastInsertId();
        $this->currencyIds[$sid] = $currencyId;
        $pdo->prepare('UPDATE supplier SET default_currency_id = ? WHERE id = ?')->execute([$currencyId, $sid]);

        $pdo->prepare(
            "INSERT INTO clients (supplier_id, company_name, street, city, zip, country_id,
                                  main_email, currency_default_id, is_customer, is_vendor)
             SELECT ?, '__TEST F6 odběratel', street, city, zip, country_id,
                    'lock-test@example.com', ?, 1, 1
               FROM supplier WHERE id = ?"
        )->execute([$sid, $currencyId, $sid]);
        $this->clientIds[$sid] = (int) $pdo->lastInsertId();

        $this->ownerIds[$sid] = $this->mkUser('accountant');

        return $sid;
    }

    private function mkPeriod(int $sid, int $year, string $status): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO accounting_periods (supplier_id, fiscal_year, starts_on, ends_on, status)
             VALUES (?, ?, ?, ?, ?)'
        )->execute([$sid, $year, sprintf('%04d-01-01', $year), sprintf('%04d-12-31', $year), $status]);
        return (int) $pdo->lastInsertId();
    }

    private function mkInvoice(
        int $sid,
        string $status,
        string $issueDate,
        ?string $varsymbol = null,
        float $totalWithVat = 0.0,
    ): int {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO invoices (supplier_id, invoice_type, client_id, varsymbol, issue_date, tax_date,
                                   due_date, currency_id, status, total_with_vat, created_by)
             VALUES (?, "invoice", ?, ?, ?, ?, DATE_ADD(?, INTERVAL 14 DAY), ?, ?, ?, ?)'
        )->execute([
            $sid, $this->clientIds[$sid], $varsymbol, $issueDate, $issueDate,
            $issueDate, $this->currencyIds[$sid], $status, $totalWithVat, $this->ownerIds[$sid],
        ]);
        return (int) $pdo->lastInsertId();
    }

    private function fileVatReturn(int $sid, int $year, int $month): void
    {
        $this->db->pdo()->prepare(
            "INSERT INTO tax_submissions
                (supplier_id, form_code, period_year, period_month, period_quarter, form_variant,
                 xml_content, xml_size_bytes, xml_sha256, validation_status, status, submitted_at,
                 summary_json, generated_by)
             VALUES (?, 'dphdp3', ?, ?, NULL, 'B', '<test/>', 7, ?, 'passed', 'submitted', NOW(), '{}', NULL)"
        )->execute([$sid, $year, $month, hash('sha256', 'test')]);
    }

    private function addInvoiceItem(int $sid, int $invoiceId): void
    {
        $vatRateId = (int) $this->db->pdo()->query('SELECT id FROM vat_rates ORDER BY id LIMIT 1')->fetchColumn();
        $this->db->pdo()->prepare(
            'INSERT INTO invoice_items (invoice_id, description, quantity, unit, unit_price_without_vat,
                                        vat_rate_id, vat_rate_snapshot, total_without_vat, total_vat, total_with_vat, order_index)
             VALUES (?, "položka", 1, "ks", 100, ?, 21, 100, 21, 121, 0)'
        )->execute([$invoiceId, $vatRateId]);
    }

    private function mkPurchaseInvoice(int $sid, string $status, string $issueDate = '2026-05-05'): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO purchase_invoices (supplier_id, vendor_id, vendor_invoice_number, issue_date, tax_date,
                                            due_date, received_at, currency_id, vendor_snapshot, status, paid_at, created_by)
             VALUES (?, ?, ?, ?, ?, DATE_ADD(?, INTERVAL 14 DAY), ?, ?, "{}", ?, ?, ?)'
        )->execute([
            $sid, $this->clientIds[$sid], 'VF-' . bin2hex(random_bytes(5)), $issueDate, $issueDate, $issueDate, $issueDate,
            $this->currencyIds[$sid], $status,
            $status === 'paid' ? $issueDate : null, $this->ownerIds[$sid],
        ]);
        return (int) $pdo->lastInsertId();
    }

    private function mkPostedEntry(
        int $sid,
        string $sourceType,
        int $sourceId,
        int $periodId,
        string $entryDate,
        bool $withLines = false,
    ): int {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO journal_entries (supplier_id, period_id, entry_date, source_type, source_id, posted_at)
             VALUES (?, ?, ?, ?, ?, NOW())'
        )->execute([$sid, $periodId, $entryDate, $sourceType, $sourceId]);
        $entryId = (int) $pdo->lastInsertId();

        if ($withLines) {
            $acc = $pdo->prepare(
                "INSERT INTO chart_of_accounts (supplier_id, account_code, name, account_type, is_synthetic)
                 VALUES (?, ?, ?, ?, 1)"
            );
            $acc->execute([$sid, '311000', 'Pohledávky — test', 'asset']);
            $debitAcc = (int) $pdo->lastInsertId();
            $acc->execute([$sid, '602000', 'Tržby — test', 'revenue']);
            $creditAcc = (int) $pdo->lastInsertId();

            $line = $pdo->prepare(
                'INSERT INTO journal_entry_lines (entry_id, supplier_id, account_id, side, amount, line_no)
                 VALUES (?, ?, ?, ?, 121.00, ?)'
            );
            $line->execute([$entryId, $sid, $debitAcc, 'debit', 0]);
            $line->execute([$entryId, $sid, $creditAcc, 'credit', 1]);
        }
        return $entryId;
    }

    private function invoiceBody(int $sid, string $date): array
    {
        return [
            'client_id'   => $this->clientIds[$sid],
            'invoice_type' => 'invoice',
            'issue_date'  => $date,
            'tax_date'    => $date,
            'due_date'    => $date,
            'currency_id' => $this->currencyIds[$sid],
            'items'       => [],
        ];
    }

    private function mkUserWithToken(string $role, int $sid): string
    {
        $userId = $this->mkUser($role);
        if ($role !== 'admin') {
            $this->db->pdo()->prepare('INSERT INTO user_suppliers (user_id, supplier_id, role_id) VALUES (?, ?, NULL)')
                ->execute([$userId, $sid]);
        }
        $out = $this->svc->generate($userId, null, '__test_f6_lock_' . bin2hex(random_bytes(4)), 'read_write', null);
        return $out['plaintext'];
    }

    /** @return array{token:string, csrf_token:string} */
    private function mkSessionUser(string $role, int $sid): array
    {
        $userId = $this->mkUser($role);
        $this->db->pdo()->prepare('INSERT INTO user_suppliers (user_id, supplier_id, role_id) VALUES (?, ?, NULL)')
            ->execute([$userId, $sid]);
        $out = $this->sessions->create($userId, '127.0.0.1', '__test_f6_lock');
        $this->sessionTokens[] = (string) $out['token'];
        return ['token' => $out['token'], 'csrf_token' => $out['csrf_token']];
    }

    /** @param array<string,int> $permissions */
    private function mkCustomRole(string $name, array $permissions): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare("INSERT INTO roles (system_key, name, role_type, is_active) VALUES (NULL, ?, 'staff', 1)")
            ->execute([$name . ' ' . bin2hex(random_bytes(3))]);
        $roleId = (int) $pdo->lastInsertId();
        $this->roleIds[] = $roleId;
        $stmt = $pdo->prepare('INSERT INTO role_permissions (role_id, permission_key, access_level) VALUES (?, ?, ?)');
        foreach ($permissions as $key => $level) {
            $stmt->execute([$roleId, $key, $level]);
        }
        return $roleId;
    }

    /** @return array{token:string, csrf_token:string} */
    private function mkSessionUserWithRole(int $roleId, int $sid): array
    {
        $email = '__test_f6_lock_' . bin2hex(random_bytes(6)) . '@example.com';
        $this->db->pdo()->prepare(
            "INSERT INTO users (email, password_hash, name, role_id, locale, is_active)
             VALUES (?, '\$2y\$10\$abcdefghijklmnopqrstuvABCDEFGHIJKLMNOPQRSTUVWXYZ01234', '__TEST F6 lock', ?, 'cs', 1)"
        )->execute([$email, $roleId]);
        $userId = (int) $this->db->pdo()->lastInsertId();
        $this->userIds[] = $userId;
        $this->db->pdo()->prepare('INSERT INTO user_suppliers (user_id, supplier_id, role_id) VALUES (?, ?, NULL)')
            ->execute([$userId, $sid]);
        $out = $this->sessions->create($userId, '127.0.0.1', '__test_f6_lock');
        $this->sessionTokens[] = (string) $out['token'];
        return ['token' => $out['token'], 'csrf_token' => $out['csrf_token']];
    }

    private function mkUser(string $role): int
    {
        $email = '__test_f6_lock_' . bin2hex(random_bytes(6)) . '@example.com';
        $stmt = $this->db->pdo()->prepare(
            "INSERT INTO users (email, password_hash, name, role_id, locale, is_active)
             VALUES (?, '\$2y\$10\$abcdefghijklmnopqrstuvABCDEFGHIJKLMNOPQRSTUVWXYZ01234', '__TEST F6 lock', ?, 'cs', 1)"
        );
        $stmt->execute([$email, $this->roleId($role)]);
        $id = (int) $this->db->pdo()->lastInsertId();
        $this->userIds[] = $id;
        return $id;
    }

    private function roleId(string $legacy): int
    {
        $stmt = $this->db->pdo()->prepare('SELECT id FROM roles WHERE system_key = ?');
        $stmt->execute([$legacy === 'admin' ? 'superadmin' : $legacy]);
        return (int) $stmt->fetchColumn();
    }

    // -------------------------------------------------------------- helpers

    private function app(): App
    {
        return $this->app ??= Bootstrap::buildApp();
    }

    private function resetApp(): void
    {
        $this->app = null;
    }

    private function request(
        string $method,
        string $path,
        string $bearer,
        ?int $supplierId,
        ?array $body = null,
    ): ResponseInterface {
        $req = (new ServerRequestFactory())
            ->createServerRequest($method, $path, ['REMOTE_ADDR' => '127.0.0.1'])
            ->withHeader('Accept', 'application/json')
            ->withHeader('Authorization', 'Bearer ' . $bearer);
        if ($supplierId !== null) {
            $req = $req->withHeader('X-Supplier-Id', (string) $supplierId);
        }
        if ($body !== null) {
            $stream = (new StreamFactory())->createStream(
                json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)
            );
            $req = $req->withHeader('Content-Type', 'application/json')->withBody($stream);
        }
        return $this->app()->handle($req);
    }

    /** @param array{token:string, csrf_token:string} $session */
    private function sessionRequest(
        string $method,
        string $path,
        array $session,
        ?int $supplierId = null,
        ?array $body = null,
    ): ResponseInterface {
        $cookieName = (string) $this->config->get('session.cookie_name', '__Host-myinvoice_session');
        $appUrl = rtrim((string) $this->config->get('app.url', ''), '/');
        $req = (new ServerRequestFactory())
            ->createServerRequest($method, $path, ['REMOTE_ADDR' => '127.0.0.1'])
            ->withCookieParams([$cookieName => $session['token']])
            ->withHeader('Accept', 'application/json')
            ->withHeader('Origin', $appUrl)
            ->withHeader('X-CSRF-Token', $session['csrf_token']);
        if ($supplierId !== null) {
            $req = $req->withHeader('X-Supplier-Id', (string) $supplierId);
        }
        if ($body !== null) {
            $stream = (new StreamFactory())->createStream(
                json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)
            );
            $req = $req->withHeader('Content-Type', 'application/json')->withBody($stream);
        }
        return $this->app()->handle($req);
    }

    private function json(ResponseInterface $res): array
    {
        $decoded = json_decode((string) $res->getBody(), true);
        return is_array($decoded) ? $decoded : [];
    }

    private function errorCode(ResponseInterface $res): ?string
    {
        return $this->json($res)['error']['code'] ?? null;
    }
}
