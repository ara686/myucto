<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

final class DocumentVisibility
{
    /** @return array{0:string,1:list<int>} */
    public static function clause(DocumentViewerContext $viewer, string $alias = ''): array
    {
        $col = $alias !== '' ? $alias . '.' : '';
        $sql = '';
        $params = [];
        if (!$viewer->isAdmin) {
            if ($viewer->userId === null) {
                $sql = " AND {$col}scope = 'company'";
            } else {
                $sql = " AND ({$col}scope = 'company' OR ({$col}scope = 'user' AND {$col}owner_user_id = ?))";
                $params[] = $viewer->userId;
            }
        }
        $table = $alias !== '' ? $alias : 'documents';
        if (!$viewer->canViewPayrollEnforcementEvidence) {
            $sql .= " AND NOT EXISTS (
                SELECT 1
                  FROM payroll_enforcement_case_documents payroll_evidence
                  " . self::linkedJoin($table, 'payroll_evidence.dms_document_id', 'payroll_evidence_doc') . "
                 WHERE payroll_evidence.supplier_id = {$table}.supplier_id
                   AND " . self::family($table, 'payroll_evidence.dms_document_id', 'payroll_evidence_doc') . "
            )";
            $sql .= " AND NOT EXISTS (
                SELECT 1
                  FROM payroll_enforcement_case_parties enforcement_party
                  " . self::linkedJoin($table, 'enforcement_party.source_document_id', 'enforcement_party_doc') . "
                 WHERE enforcement_party.supplier_id = {$table}.supplier_id
                   AND " . self::family($table, 'enforcement_party.source_document_id', 'enforcement_party_doc') . "
            )";
            $sql .= " AND NOT EXISTS (
                SELECT 1
                  FROM payroll_enforcement_claim_breakdowns enforcement_breakdown
                  " . self::linkedJoin($table, 'enforcement_breakdown.source_document_id', 'enforcement_breakdown_doc') . "
                 WHERE enforcement_breakdown.supplier_id = {$table}.supplier_id
                   AND " . self::family($table, 'enforcement_breakdown.source_document_id', 'enforcement_breakdown_doc') . "
            )";
            $sql .= " AND NOT EXISTS (
                SELECT 1
                  FROM payroll_enforcement_recipient_instructions recipient_instruction
                  " . self::linkedJoin($table, 'recipient_instruction.source_document_id', 'recipient_instruction_doc') . "
                 WHERE recipient_instruction.supplier_id = {$table}.supplier_id
                   AND " . self::family($table, 'recipient_instruction.source_document_id', 'recipient_instruction_doc') . "
            )";
            $sql .= " AND NOT EXISTS (
                SELECT 1
                  FROM payroll_enforcement_xmlzam_requests xmlzam_request
                  " . self::linkedJoin($table, 'xmlzam_request.source_document_id', 'xmlzam_source') . "
                 WHERE xmlzam_request.supplier_id = {$table}.supplier_id
                   AND " . self::family($table, 'xmlzam_request.source_document_id', 'xmlzam_source') . "
            )";
        }
        if (!$viewer->canViewHiddenSubmissionInboxDocuments) {
            $sql .= " AND NOT EXISTS (
                SELECT 1
                  FROM submission_inbox_messages hidden_inbox
                  " . self::linkedJoin($table, 'hidden_inbox.document_id', 'hidden_inbox_doc') . "
                 WHERE hidden_inbox.supplier_id = {$table}.supplier_id
                   AND hidden_inbox.hidden_at IS NOT NULL
                   AND hidden_inbox.document_id IS NOT NULL
                   AND " . self::family($table, 'hidden_inbox.document_id', 'hidden_inbox_doc') . "
            )";
        }
        if (!$viewer->canViewPayrollInsolvencyEvidence) {
            $sql .= " AND NOT EXISTS (
                SELECT 1
                  FROM payroll_insolvency_payment_instructions insolvency_evidence
                  " . self::linkedJoin($table, 'insolvency_evidence.decision_document_id', 'insolvency_evidence_doc') . "
                 WHERE insolvency_evidence.supplier_id = {$table}.supplier_id
                   AND " . self::family($table, 'insolvency_evidence.decision_document_id', 'insolvency_evidence_doc') . "
            )";
        }
        if (!$viewer->canViewPayrollSubmissionEvidence) {
            $sql .= " AND NOT EXISTS (
                SELECT 1
                  FROM submission_inbox_messages classified_payroll_inbox
                  " . self::linkedJoin($table, 'classified_payroll_inbox.document_id', 'classified_payroll_inbox_doc') . "
                 WHERE classified_payroll_inbox.supplier_id = {$table}.supplier_id
                   AND classified_payroll_inbox.classification IN ('cssz_protocol','health_insurer_response')
                   AND classified_payroll_inbox.document_id IS NOT NULL
                   AND " . self::family($table, 'classified_payroll_inbox.document_id', 'classified_payroll_inbox_doc') . "
            )";
            $sql .= " AND NOT EXISTS (
                SELECT 1
                  FROM payroll_statutory_obligation_evidence submission_evidence
                  " . self::linkedJoin($table, 'submission_evidence.document_id', 'submission_evidence_doc') . "
                 WHERE submission_evidence.supplier_id = {$table}.supplier_id
                   AND " . self::family($table, 'submission_evidence.document_id', 'submission_evidence_doc') . "
            )";
            $sql .= " AND NOT EXISTS (
                SELECT 1
                  FROM payroll_eldp_manual_completions eldp_evidence
                  " . self::linkedJoin($table, 'eldp_evidence.confirmation_document_id', 'eldp_evidence_doc') . "
                 WHERE eldp_evidence.confirmation_document_supplier_id = {$table}.supplier_id
                   AND " . self::family($table, 'eldp_evidence.confirmation_document_id', 'eldp_evidence_doc') . "
            )";
            $sql .= " AND NOT EXISTS (
                SELECT 1
                  FROM payroll_production_qualification_documents qualification_evidence
                  " . self::linkedJoin($table, 'qualification_evidence.document_id', 'qualification_evidence_doc') . "
                 WHERE qualification_evidence.supplier_id = {$table}.supplier_id
                   AND " . self::family($table, 'qualification_evidence.document_id', 'qualification_evidence_doc') . "
            )";
            $sql .= " AND NOT EXISTS (
                SELECT 1
                  FROM submission_outbox payroll_receipt
                  " . self::linkedJoin($table, 'payroll_receipt.receipt_document_id', 'payroll_receipt_doc') . "
                 WHERE payroll_receipt.supplier_id = {$table}.supplier_id
                   AND payroll_receipt.artifact_kind = 'payroll_submission'
                   AND " . self::family($table, 'payroll_receipt.receipt_document_id', 'payroll_receipt_doc') . "
            )";
            $sql .= " AND NOT EXISTS (
                SELECT 1
                  FROM submission_outbox payroll_receipt
                  JOIN submission_inbox_messages receipt_inbox
                    ON receipt_inbox.supplier_id = payroll_receipt.supplier_id
                   AND receipt_inbox.id = payroll_receipt.receipt_inbox_message_id
                  " . self::linkedJoin($table, 'receipt_inbox.document_id', 'receipt_inbox_doc') . "
                 WHERE payroll_receipt.supplier_id = {$table}.supplier_id
                   AND payroll_receipt.artifact_kind = 'payroll_submission'
                   AND " . self::family($table, 'receipt_inbox.document_id', 'receipt_inbox_doc') . "
            )";
            $sql .= " AND NOT EXISTS (
                SELECT 1
                  FROM submission_inbox_messages payroll_response
                  JOIN submission_outbox payroll_outbox
                    ON payroll_outbox.supplier_id = payroll_response.supplier_id
                   AND payroll_outbox.id = payroll_response.matched_outbox_id
                  " . self::linkedJoin($table, 'payroll_response.document_id', 'payroll_response_doc') . "
                 WHERE payroll_response.supplier_id = {$table}.supplier_id
                   AND payroll_outbox.artifact_kind = 'payroll_submission'
                   AND " . self::family($table, 'payroll_response.document_id', 'payroll_response_doc') . "
            )";
            $sql .= " AND NOT EXISTS (
                SELECT 1
                  FROM submission_defect_notices defect_notice
                  JOIN submission_outbox payroll_outbox
                    ON payroll_outbox.supplier_id = defect_notice.supplier_id
                   AND payroll_outbox.id = defect_notice.outbox_id
                  JOIN submission_inbox_messages defect_inbox
                    ON defect_inbox.supplier_id = defect_notice.supplier_id
                   AND defect_inbox.id = defect_notice.inbox_message_id
                  " . self::linkedJoin($table, 'defect_inbox.document_id', 'defect_inbox_doc') . "
                 WHERE defect_notice.supplier_id = {$table}.supplier_id
                   AND payroll_outbox.artifact_kind = 'payroll_submission'
                   AND " . self::family($table, 'defect_inbox.document_id', 'defect_inbox_doc') . "
            )";
        }
        if (!$viewer->canViewPayrollForeignPermitEvidence) {
            $sql .= " AND NOT EXISTS (
                SELECT 1
                  FROM payroll_person_foreign_permits foreign_permit
                  " . self::linkedJoin($table, 'foreign_permit.document_id', 'foreign_permit_doc') . "
                 WHERE foreign_permit.supplier_id = {$table}.supplier_id
                   AND " . self::family($table, 'foreign_permit.document_id', 'foreign_permit_doc') . "
            )";
        }
        if (!$viewer->canViewPayrollHealthEvidence) {
            $sql .= " AND NOT EXISTS (
                SELECT 1
                  FROM payroll_person_health_coverage_history health_evidence
                  " . self::linkedJoin($table, 'health_evidence.health_evidence_document_id', 'health_evidence_doc') . "
                 WHERE health_evidence.supplier_id = {$table}.supplier_id
                   AND health_evidence.health_evidence_document_id IS NOT NULL
                   AND " . self::family($table, 'health_evidence.health_evidence_document_id', 'health_evidence_doc') . "
            )";
        }
        if (!$viewer->canViewPayrollDocuments) {
            $sql .= " AND NOT EXISTS (
                SELECT 1
                  FROM payroll_document_dms_links payroll_document_link
                  JOIN documents linked_payroll_document
                    ON linked_payroll_document.supplier_id = payroll_document_link.supplier_id
                   AND linked_payroll_document.id = payroll_document_link.dms_document_id
                 WHERE payroll_document_link.supplier_id = {$table}.supplier_id
                   AND (payroll_document_link.dms_document_id = {$table}.id
                     OR payroll_document_link.dms_document_id = {$table}.parent_document_id
                     OR linked_payroll_document.parent_document_id = {$table}.id)
            )";
        }
        return [$sql, $params];
    }

    /**
     * Připojí doklad, na který důkaz ukazuje, aby šlo dohledat jeho rodinu.
     * LEFT JOIN: chybějící doklad nesmí shodit podmínku na samotné ID.
     */
    private static function linkedJoin(string $table, string $linkedId, string $linkedAlias): string
    {
        return "LEFT JOIN documents {$linkedAlias}
                    ON {$linkedAlias}.supplier_id = {$table}.supplier_id
                   AND {$linkedAlias}.id = {$linkedId}";
    }

    /**
     * Datová zpráva se ukládá jako ZFO kontejner a přílohy jako jeho potomci
     * (`parent_document_id`). Kontejner nese všechny přílohy uvnitř souboru,
     * takže skrýt jen navázanou přílohu nestačí: přes kontejner (detail
     * i stažení) by se k ní dostal kdokoli. Skrývá se proto celá zpráva,
     * tedy navázaný doklad, jeho potomci, jeho kontejner i ostatní přílohy
     * téže zprávy.
     */
    private static function family(string $table, string $linkedId, string $linkedAlias): string
    {
        return "({$linkedId} = {$table}.id
                     OR {$linkedId} = {$table}.parent_document_id
                     OR {$linkedAlias}.parent_document_id = {$table}.id
                     OR ({$table}.parent_document_id IS NOT NULL
                         AND {$linkedAlias}.parent_document_id = {$table}.parent_document_id))";
    }
}
