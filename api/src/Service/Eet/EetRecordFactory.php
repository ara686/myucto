<?php

declare(strict_types=1);

namespace MyInvoice\Service\Eet;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/**
 * Vznik neměnného EET záznamu v transakci platebního příkazu.
 *
 * Snapshot nese vše, co tvoří identitu tržby (EIČ, číslo jednotky z DIS+, kód
 * zařízení, pořadové číslo, čas, částka) i prostředí. Pozdější změna nastavení,
 * jednotky nebo zařízení už záznam nezmění, retry odešle stejná Data.
 *
 * Pořadové číslo: `{rok}/{pořadí:06d}` z čítače zařízení zamčeného FOR UPDATE.
 * Rok se bere z času tržby, ale čítač se nikdy nevrací do staršího roku: tržba
 * s časem z 31. 12. zapsaná po přechodu čítače na nový rok dostane číslo v řadě
 * nového roku (jinak by se opakovalo už použité číslo). Použité číslo se nevrací
 * ani při rollbacku jiné části příkazu — rollback vrátí i čítač, takže číslo
 * nevznikne vůbec.
 */
final class EetRecordFactory
{
    public const DEADLINE_HOURS = 48;

    /** Krátké odložení pro záložní worker: první pokus po commitu dělá dispatchNow. */
    public const INTERACTIVE_GRACE_SECONDS = 15;

    public function __construct(private readonly Connection $db) {}

    /**
     * @param array<string,mixed> $settings řádek eet_settings
     * @param array{device_id?:?int, sale_at:\DateTimeImmutable, total_amount:string, kind?:string,
     *              corrects_record_id?:?int, correction_reason?:?string,
     *              policy_decision?:?string, policy_reason?:?string, created_by?:?int,
     *              now?:?\DateTimeImmutable} $input
     * @return array{id:int, public_id:string, sequence_number:string, unit_id:int, device_id:int}
     * @throws EetCommandException
     */
    public function create(int $supplierId, int $paymentEventId, array $settings, array $input): array
    {
        $pdo = $this->db->pdo();
        if (!$pdo->inTransaction()) {
            throw new \LogicException('EET záznam vzniká jen v transakci platebního příkazu.');
        }

        $eic = strtoupper(trim((string) ($settings['eic'] ?? '')));
        if (preg_match('/^CZ\d{8,10}$/D', $eic) !== 1) {
            throw EetCommandException::notConfigured('V nastavení EET chybí platné EIČ.');
        }
        $environment = (string) ($settings['environment'] ?? '');
        if (!in_array($environment, ['playground', 'production'], true)) {
            throw EetCommandException::notConfigured('V nastavení EET chybí prostředí.');
        }

        $saleAt = EetValues::local($input['sale_at']);
        $deviceId = (int) ($input['device_id'] ?? 0) ?: (int) ($settings['default_device_id'] ?? 0);
        if ($deviceId <= 0) {
            throw EetCommandException::notConfigured('Není zvolené pokladní zařízení EET a firma nemá výchozí zařízení.');
        }
        $device = $this->lockDevice($supplierId, $deviceId);
        $day = $saleAt->format('Y-m-d');
        if ((int) $device['active'] !== 1) {
            throw EetCommandException::validation('Pokladní zařízení EET není aktivní.', 'device_id');
        }
        if ((string) $device['valid_from'] > $day || ($device['valid_to'] !== null && (string) $device['valid_to'] < $day)) {
            throw EetCommandException::validation('Provozovna (evidenční jednotka) zařízení není k času tržby platná.', 'device_id');
        }

        $saleYear = (int) $saleAt->format('Y');
        $counterYear = $device['sequence_year'] !== null ? (int) $device['sequence_year'] : null;
        $year = $counterYear !== null ? max($saleYear, $counterYear) : $saleYear;
        $sequence = $counterYear === $year ? max(1, (int) $device['next_sequence']) : 1;
        $sequenceNumber = sprintf('%d/%06d', $year, $sequence);
        $pdo->prepare('UPDATE eet_devices SET sequence_year = ?, next_sequence = ? WHERE id = ? AND supplier_id = ?')
            ->execute([$year, $sequence + 1, $deviceId, $supplierId]);

        $total = EetValues::amount($input['total_amount'], 'amount');
        $cents = EetValues::toCents($total);
        if ($cents === 0 || abs($cents) > EetValues::MAX_ABS_CENTS) {
            throw EetCommandException::validation('Částka tržby musí být nenulová a v absolutní hodnotě pod 100 milionů Kč.', 'amount');
        }

        $now = EetValues::local($input['now'] ?? new \DateTimeImmutable());
        $deadline = $saleAt->modify('+' . self::DEADLINE_HOURS . ' hours');
        $publicId = EetValues::uuid4();
        $pdo->prepare(
            'INSERT INTO eet_records
               (public_id, supplier_id, payment_event_id, kind, corrects_record_id, correction_reason, environment,
                eic, unit_id, unit_number, device_id, device_code, sequence_number, sale_at, sale_at_offset,
                total_amount, policy_decision, policy_reason, policy_version, status, deadline_at, overdue, next_attempt_at,
                created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, \'pending\', ?, ?, ?, ?)'
        )->execute([
            $publicId, $supplierId, $paymentEventId,
            (string) ($input['kind'] ?? 'sale'),
            $input['corrects_record_id'] ?? null,
            $input['correction_reason'] ?? null,
            $environment, $eic,
            (int) $device['unit_id'], (int) $device['unit_number'],
            $deviceId, (string) $device['code'],
            $sequenceNumber,
            EetValues::dbSecond($saleAt), $saleAt->format('P'),
            $total,
            $input['policy_decision'] ?? null, $input['policy_reason'] ?? null, EetRecordingPolicy::VERSION,
            EetValues::dbSecond($deadline),
            // Pozdě pořízená tržba se eviduje, jen hned s příznakem po lhůtě.
            $deadline < $now ? 1 : 0,
            EetValues::dbTime($now->modify('+' . self::INTERACTIVE_GRACE_SECONDS . ' seconds')),
            $input['created_by'] ?? null,
        ]);

        return [
            'id' => (int) $pdo->lastInsertId(),
            'public_id' => $publicId,
            'sequence_number' => $sequenceNumber,
            'unit_id' => (int) $device['unit_id'],
            'device_id' => $deviceId,
        ];
    }

    /**
     * Zařízení s jednotkou firmy, zamčené pro alokaci čísla.
     *
     * @return array<string,mixed>
     * @throws EetCommandException
     */
    private function lockDevice(int $supplierId, int $deviceId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT d.id, d.unit_id, d.code, d.active, d.sequence_year, d.next_sequence,
                    u.unit_number, u.valid_from, u.valid_to
               FROM eet_devices d
               JOIN eet_units u ON u.id = d.unit_id AND u.supplier_id = d.supplier_id
              WHERE d.id = ? AND d.supplier_id = ?
              FOR UPDATE'
        );
        $stmt->execute([$deviceId, $supplierId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            throw EetCommandException::validation('Pokladní zařízení EET nenalezeno.', 'device_id');
        }
        return $row;
    }
}
