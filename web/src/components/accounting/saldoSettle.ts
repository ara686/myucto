/**
 * Sdílené pomůcky dialogů vyrovnání saldokonta (SaldoSettleDialog, SaldoResidualDialog,
 * SaldoSuggestionsDialog).
 */

/** Účty, jejichž doklady jde vyrovnat rozpadem zápisu (shodně s JournalSettlementService::SETTLE_ACCOUNTS). */
export const SETTLE_ACCOUNTS = ['311', '321', '315', '325']

export interface SaldoAllocationResultLike {
  created: unknown[]
  failed: { index: number; code: string; message: string }[]
}

export interface SaldoSettleTarget {
  doc_type: 'invoice' | 'purchase_invoice'
  doc_id: number
  doc_no: string
  partner_id: number
  partner_name: string
  account_code: string
  currency_code: string
  remaining_czk: number
}

/** Server odmítl zápis do uzavřeného období (správce ho může přebít `force=1`). */
export function isPeriodClosedError(e: unknown): boolean {
  const err = e as { response?: { status?: number; data?: { error?: { code?: string } } } }
  return err?.response?.status === 409 && err.response.data?.error?.code === 'period_closed'
}

export function apiErrorMessage(e: unknown, fallback: string): string {
  const err = e as { response?: { data?: { error?: { message?: string } } } }
  return err?.response?.data?.error?.message || fallback
}

/**
 * Spustí zápis; když ho server odmítne kvůli uzavřenému období a uživatel je správce,
 * zeptá se a zopakuje ho s `force`. Vrací null, když uživatel přebití odmítl.
 */
export async function withPeriodForce<T>(
  run: (force: boolean) => Promise<T>,
  canForce: boolean,
  confirmForce: () => boolean,
): Promise<T | null> {
  try {
    return await run(false)
  } catch (e) {
    if (isPeriodClosedError(e) && canForce) {
      if (!confirmForce()) return null
      return await run(true)
    }
    throw e
  }
}

/** Strop skupin v jednom požadavku (JournalSettlementService::MAX_GROUPS). */
export const MAX_GROUPS_PER_REQUEST = 100

/** Rozdělí volnou částku řádku na doklady v pořadí, každému nejvýš jeho zbytek. */
export function distribute(free: number, remainings: number[]): number[] {
  let left = Math.round(free * 100)
  return remainings.map((r) => {
    const take = Math.max(0, Math.min(left, Math.round(r * 100)))
    left -= take
    return take / 100
  })
}

/** Lze položku saldokonta vyrovnat rozpadem zápisu? */
export function isSettleable(item: { doc_type: string; kind?: string; settleable?: boolean; currency_code: string; remaining_czk: number }, accountCode: string): boolean {
  // `settleable` = doklad má na účtu vlastní předpis (ne jen počáteční stav), jen ten
  // rozpad zápisu snižuje.
  return (item.doc_type === 'invoice' || item.doc_type === 'purchase_invoice')
    && item.settleable === true
    && (item.kind ?? 'document') === 'document'
    && item.currency_code === 'CZK'
    && SETTLE_ACCOUNTS.includes(accountCode)
    && Math.round(item.remaining_czk * 100) > 0
}
