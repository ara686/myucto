<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting\Bank;

use MyInvoice\Action\Bank\BankPaymentCandidatesAction;
use MyInvoice\Action\Bank\BankStatementAction;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Service\Bank\BankMatchSearch;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

final class BankMatchSearchTest extends BankPostingTestCase
{
    private function request(array $query = [], string $role = 'admin')
    {
        return (new ServerRequestFactory())->createServerRequest('GET', '/api/bank-transactions/payment-candidates')
            ->withQueryParams($query)
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => $role]);
    }

    public function testExplicitSearchFindsOldPurchaseBySupplierAndCzechAmount(): void
    {
        $vendor = $this->client('Syntetický dodavatel hledání');
        $purchase = $this->purchaseInvoice('SYN-SEARCH-OLD', $vendor, 1234.50);
        $this->db->pdo()->prepare("UPDATE purchase_invoices SET issue_date = '2098-01-01', due_date = '2098-01-15' WHERE id = ?")->execute([$purchase]);
        $tx = $this->transaction($this->statement(), -500.00);
        $action = $this->container->get(BankStatementAction::class);
        foreach (['Syntetický dodavatel hledání', '1 234,50', 'SYN-SEARCH-OLD'] as $search) {
            $response = $action->matchCandidates($this->request(['search' => $search]), new Response(), ['id' => $tx]);
            self::assertSame(200, $response->getStatusCode());
            $body = json_decode((string) $response->getBody(), true);
            self::assertSame([$purchase], array_column($body['candidates'], 'id'));
            self::assertSame('purchase_invoice', $body['candidates'][0]['type']);
        }
        $response = $action->matchCandidates($this->request(), new Response(), ['id' => $tx]);
        self::assertSame([], json_decode((string) $response->getBody(), true)['candidates']);
    }

    public function testSearchRespectsDirectionDocumentStateAndTenant(): void
    {
        $party = $this->client('Syntetický společný partner');
        $sale = $this->saleInvoice('209987001', $party, 1200);
        $refund = $this->saleInvoice('209987002', $party, -1200, 'credit_note');
        $purchase = $this->purchaseInvoice('SYN-SEARCH-IN', $party, 1200);
        $purchaseRefund = $this->purchaseInvoice('SYN-SEARCH-REF', $party, -1200, 'credit_note');
        $draft = $this->saleInvoice('209987003', $party, 1200, 'invoice', 'draft');
        $foreign = $this->saleInvoice('209987004', $party, 1200);
        $this->db->pdo()->prepare('UPDATE invoices SET supplier_id = ? WHERE id = ?')->execute([$this->otherSupplierId(), $foreign]);
        $search = new BankMatchSearch($this->db);
        // Vydané a přijaté doklady mají vlastní řadu id, v čisté DB se čísla potkají.
        $incoming = self::documentKeys($search->searchDocuments($this->supplierId, 1200, 'CZK', 'Syntetický společný partner', ['invoice', 'purchase_invoice']));
        self::assertEqualsCanonicalizing(["invoice:$sale", "purchase_invoice:$purchaseRefund"], $incoming);
        $outgoing = self::documentKeys($search->searchDocuments($this->supplierId, -1200, 'CZK', '1 200,00', ['invoice', 'purchase_invoice']));
        self::assertEqualsCanonicalizing(["invoice:$refund", "purchase_invoice:$purchase"], $outgoing);
        self::assertNotContains("invoice:$draft", $incoming);
        self::assertNotContains("invoice:$foreign", $incoming);
    }

    /** @return list<string> */
    private static function documentKeys(array $rows): array
    {
        return array_map(static fn (array $row): string => $row['type'] . ':' . $row['id'], $rows);
    }

    public function testPaymentCandidatesExcludeUsedForeignAndWrongDirectionMovements(): void
    {
        $sale = $this->saleInvoice('209987005', $this->client('Syntetický klient výběru'), 1234.50);
        $statement = $this->statement();
        $wanted = $this->transaction($statement, 1234.50, ['counterparty_name' => 'Syntetický plátce']);
        $this->transaction($statement, -1234.50);
        $this->transaction($statement, 1234.50, ['match_status' => 'ignored']);
        $this->transaction($statement, 1234.50, ['match_status' => 'manual', 'matched_invoice_id' => $sale]);
        $this->transaction($this->statement('1000000005', '0100', $this->otherSupplierId()), 1234.50);
        $used = $this->transaction($statement, 1234.50);
        $this->db->pdo()->prepare("INSERT INTO payment_matches (supplier_id, bank_transaction_id, invoice_id, amount, match_type) VALUES (?, ?, ?, 1234.50, 'manual')")->execute([$this->supplierId, $used, $sale]);
        $action = $this->container->get(BankPaymentCandidatesAction::class);
        $response = $action->list($this->request(['invoice_id' => $sale, 'search' => '1 234,50']), new Response());
        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        self::assertSame([$wanted], array_column($body['items'], 'id'));
        self::assertSame(1, $body['total']);
        self::assertSame(1234.50, $body['items'][0]['amount']);
    }

    public function testPreferredPaymentMovesToFirstPageWithoutChangingEligibilityOrTotals(): void
    {
        $purchase = $this->purchaseInvoice('SYN-PREFERRED', $this->client('Syntetický příjemce'), 100);
        $statement = $this->statement();
        $preferred = $this->transaction($statement, -400);
        for ($i = 0; $i < 30; ++$i) $this->transaction($statement, -100);
        $action = $this->container->get(BankPaymentCandidatesAction::class);
        $read = function (array $extra = []) use ($action, $purchase): array {
            $response = $action->list($this->request(['purchase_invoice_id'=>$purchase] + $extra), new Response());
            self::assertSame(200, $response->getStatusCode());
            return json_decode((string) $response->getBody(), true);
        };
        $original = $read();
        self::assertNotContains($preferred, array_column($original['items'], 'id'));
        $ranked = $read(['preferred_transaction_id'=>$preferred]);
        self::assertSame($preferred, $ranked['items'][0]['id']);
        self::assertSame($original['total'], $ranked['total']);
        self::assertSame($original['pages'], $ranked['pages']);
        foreach (['ignored','manual'] as $status) {
            $this->db->pdo()->prepare('UPDATE bank_transactions SET match_status=? WHERE id=?')->execute([$status,$preferred]);
            self::assertNotContains($preferred, array_column($read(['preferred_transaction_id'=>$preferred])['items'],'id'));
        }
        $foreign = $this->transaction($this->statement('1000000005','0100',$this->otherSupplierId()), -100);
        self::assertNotContains($foreign, array_column($read(['preferred_transaction_id'=>$foreign])['items'],'id'));
        self::assertSame(0, (int)$this->db->pdo()->query('SELECT COUNT(*) FROM payment_matches WHERE purchase_invoice_id='.$purchase)->fetchColumn());
    }

    public function testPreferredPaymentParameterMustBePositiveInteger(): void
    {
        $purchase = $this->purchaseInvoice('SYN-PREFERRED-INVALID', $this->client('Syntetický příjemce'), 100);
        $action = $this->container->get(BankPaymentCandidatesAction::class);
        foreach (['0','-1','invalid',['1']] as $value) {
            self::assertSame(400, $action->list($this->request(['purchase_invoice_id'=>$purchase,'preferred_transaction_id'=>$value]), new Response())->getStatusCode());
        }
    }

    public function testReverseMatchReusesPurchaseAllocationAndPreservesOuterTransaction(): void
    {
        $purchase = $this->purchaseInvoice('SYN-REVERSE-PARTIAL', $this->client('Syntetický dodavatel úhrady'), 1200);
        $tx = $this->transaction($this->statement(), -500);
        $this->db->pdo()->prepare("INSERT INTO bank_match_suggestions (supplier_id,bank_transaction_id,kind,reason,candidates_json,top_score,status) VALUES (?,?,'single','amount_date_requires_review','[]',0.35,'pending')")
            ->execute([$this->supplierId,$tx]);
        $action = $this->container->get(BankPaymentCandidatesAction::class);
        $result = $this->callAction($action, 'match', 'POST', 'admin', ['purchase_invoice_id' => $purchase], ['id' => $tx]);
        self::assertSame(200, $result['status'], json_encode($result['body']));
        self::assertTrue($this->db->pdo()->inTransaction());
        self::assertTrue($result['body']['partial_payment']);
        self::assertSame('superseded', $this->db->pdo()->query('SELECT status FROM bank_match_suggestions WHERE bank_transaction_id='.$tx)->fetchColumn());
        self::assertSame(700.0, (float) $result['body']['remaining']);
        $query = $this->db->pdo()->prepare('SELECT amount FROM payment_matches WHERE bank_transaction_id = ? AND purchase_invoice_id = ?');
        $query->execute([$tx, $purchase]);
        self::assertSame(500.0, (float) $query->fetchColumn());
        $again = $this->callAction($action, 'match', 'POST', 'admin', ['purchase_invoice_id' => $purchase], ['id' => $tx]);
        self::assertSame(409, $again['status']);
        self::assertTrue($this->db->pdo()->inTransaction());
    }

    public function testReverseMatchAndCandidateListingEnforcePermissions(): void
    {
        $sale = $this->saleInvoice('209987006', $this->client('Syntetický klient oprávnění'), 1200);
        $tx = $this->transaction($this->statement(), 1200);
        $action = $this->container->get(BankPaymentCandidatesAction::class);
        self::assertSame(403, $action->list($this->request(['invoice_id' => $sale], 'client'), new Response())->getStatusCode());
        self::assertSame(403, $this->callAction($action, 'match', 'POST', 'readonly', ['invoice_id' => $sale], ['id' => $tx])['status']);
        $query = $this->db->pdo()->prepare('SELECT match_status FROM bank_transactions WHERE id = ?');
        $query->execute([$tx]);
        self::assertSame('unmatched', $query->fetchColumn());
    }

    public function testReverseMatchRecordsIssuedPaymentAndRejectsForeignMovement(): void
    {
        $sale = $this->saleInvoice('209987007', $this->client('Syntetický klient částečné úhrady'), 1200);
        $tx = $this->transaction($this->statement(), 500);
        $action = $this->container->get(BankPaymentCandidatesAction::class);
        $foreign = $this->transaction($this->statement('1000000005', '0100', $this->otherSupplierId()), 500);
        self::assertSame(404, $this->callAction($action, 'match', 'POST', 'admin', ['invoice_id' => $sale], ['id' => $foreign])['status']);
        $result = $this->callAction($action, 'match', 'POST', 'admin', ['invoice_id' => $sale], ['id' => $tx]);
        self::assertSame(200, $result['status'], json_encode($result['body']));
        self::assertTrue($result['body']['partial_payment']);
        $query = $this->db->pdo()->prepare('SELECT amount FROM invoice_payments WHERE bank_transaction_id = ? AND invoice_id = ?');
        $query->execute([$tx, $sale]);
        self::assertSame(500.0, (float) $query->fetchColumn());
        self::assertTrue($this->db->pdo()->inTransaction());
    }
}
