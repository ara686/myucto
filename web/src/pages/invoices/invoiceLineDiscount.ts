/**
 * Ceníková cena a sleva řádku faktury (migrace 2035).
 *
 * `list_unit_price` je cena za jednotku před slevou ve stejném základu jako
 * `unit_price_without_vat`. Editor ji ukládá při nacenění skladové karty a při
 * zadání slevy u řádku. Ruční změna ceny ji nechá být, procento se jen přepočítá. Rozhodné procento počítá
 * server (`LineDiscount::percent`), tady je jen náhled pro řádek editoru.
 */

function toNumber(value: number | string | null | undefined): number | null {
  if (value === null || value === undefined || value === '') return null
  const n = Number(value)
  return Number.isFinite(n) ? n : null
}

/** Sleva v % (záporná = přirážka) na tři desetinná místa; null bez ceníku, při shodě a u záporné ceny. */
export function lineDiscountPct(listPrice: number | string | null | undefined, unitPrice: number | string | null | undefined): number | null {
  const list = toNumber(listPrice)
  const unit = toNumber(unitPrice)
  if (list === null || unit === null || list <= 0 || unit < 0) return null
  const raw = (list - unit) * 100 / list
  const pct = Math.sign(raw) * Math.round(Math.abs(raw) * 1000 + 1e-7) / 1000
  return pct === 0 ? null : pct
}

/** Ceníková cena z odpovědi nacenění (`/stock/items/quote`). */
export function quotedListPrice(line: { list_unit_price?: string | number | null }): number | null {
  const list = toNumber(line.list_unit_price)
  return list !== null && list > 0 ? list : null
}

/** Ceníková cena karty vybrané bez nacenění: standardní cena bez akce. */
export function legacyListPrice(si: { sale_price_without_vat: string | null }): number | null {
  return quotedListPrice({ list_unit_price: si.sale_price_without_vat })
}

function roundTo(value: number, decimals: number): number {
  const factor = 10 ** decimals
  return Math.round((value + Number.EPSILON * Math.sign(value)) * factor) / factor
}

/** Hodnota pole „Sleva %" u řádku: kladná sleva z ceníku na dvě místa, jinak null (bez ceníku, shoda, přirážka). */
export function lineDiscountInputValue(listPrice: number | string | null | undefined, unitPrice: number | string | null | undefined): number | null {
  const pct = lineDiscountPct(listPrice, unitPrice)
  return pct !== null && pct > 0 ? roundTo(pct, 2) : null
}

/** Zadaná sleva: 0 až 100 (prázdné = 0), desetinná čárka i tečka, volitelně se znakem %, na dvě místa. null = neplatný vstup. */
export function parseLineDiscountInput(raw: string): number | null {
  const text = raw.trim().replace(/\s*%$/, '').replace(',', '.')
  if (text === '') return 0
  if (!/^\d+(\.\d+)?$/.test(text)) return null
  const pct = roundTo(Number(text), 2)
  return pct <= 100 ? pct : null
}

/**
 * Sleva v % zadaná u řádku: cena = ceníková × (1 − sleva / 100), zaokrouhlená na
 * `decimals` míst jako ruční cena řádku. Bez ceníkové ceny, nebo když je cena řádku
 * na ceníkové či nad ní (pole slevy je prázdné, uživatel cenu opravil), se ceníkovou
 * stane aktuální cena řádku. null = nic se nemění (bez kladné ceny, nebo nulová sleva
 * z aktuální ceny).
 */
export function applyLineDiscount(
  row: { list_unit_price?: number | string | null; unit_price_without_vat: number | string | null | undefined },
  pct: number,
  decimals = 2,
): { list_unit_price: number; unit_price_without_vat: number } | null {
  let list = toNumber(row.list_unit_price)
  const current = toNumber(row.unit_price_without_vat)
  if (list === null || list <= 0 || (current !== null && current > list)) {
    if (current === null || current <= 0 || pct === 0) return null
    list = current
  }
  return { list_unit_price: list, unit_price_without_vat: roundTo(list * (100 - pct) / 100, decimals) }
}
