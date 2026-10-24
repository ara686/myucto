import type { MemberRevision } from '@/api/nonprofitMembership'

/** Částka jako desetinný řetězec na dvě místa (to, co backend čte jako `amount`), jinak null. */
export function amountDecimal(value: string): string | null {
  const text = value.trim().replace(',', '.')
  const match = /^(\d{1,13})(?:\.(\d{1,2}))?$/.exec(text)
  if (!match) return null
  const fraction = (match[2] ?? '').padEnd(2, '0')
  const cents = BigInt(match[1]!) * 100n + BigInt(fraction)
  if (cents <= 0n || cents > 999999999999999n) return null
  return `${match[1]!.replace(/^0+(?=\d)/, '')}.${fraction}`
}

export function isIsoDate(value: string): boolean {
  const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(value)
  if (!match) return false
  const date = new Date(Date.UTC(Number(match[1]), Number(match[2]) - 1, Number(match[3])))
  return date.toISOString().slice(0, 10) === value
}

export const titleKeyPattern = /^[A-Za-z0-9][A-Za-z0-9_.:-]{0,79}$/

export function formatCents(cents: number, locale: string): string {
  return new Intl.NumberFormat(locale, { style: 'currency', currency: 'CZK' }).format(cents / 100)
}

function ordered(history: readonly MemberRevision[]): MemberRevision[] {
  return [...history].sort((a, b) => a.effective_from.localeCompare(b.effective_from) || a.id - b.id)
}

/** Revize platná k datu: poslední, která už začala. Budoucí revize dnešní stav nemění. */
export function revisionAt(history: readonly MemberRevision[], date: string): MemberRevision | null {
  let match: MemberRevision | null = null
  for (const revision of ordered(history)) if (revision.effective_from <= date) match = revision
  return match
}

export function lastRevision(history: readonly MemberRevision[]): MemberRevision | null {
  return history.reduce<MemberRevision | null>((last, row) => (last === null || row.revision_no > last.revision_no ? row : last), null)
}

export function errorStatus(e: unknown): number {
  return (e as { response?: { status?: number } })?.response?.status ?? 0
}

export function errorCode(e: unknown): string {
  return (e as { response?: { data?: { error?: { code?: string } } } })?.response?.data?.error?.code ?? ''
}

export function errorMessage(e: unknown): string {
  return (e as { response?: { data?: { error?: { message?: string } } } })?.response?.data?.error?.message ?? ''
}
