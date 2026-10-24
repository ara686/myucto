import { describe, expect, it } from 'vitest'
import type { MemberRevision } from '@/api/nonprofitMembership'
import { amountDecimal, isIsoDate, lastRevision, revisionAt } from '../membershipFormat'

const revision = (id: number, no: number, effective_from: string, status: MemberRevision['status']): MemberRevision => ({
  id, supplier_id: 5, member_id: 9, revision_no: no, previous_id: no > 1 ? id - 1 : null, effective_from, status,
  legal_basis: { organisation_form: 'association', relationship: 'nonreciprocal_membership', reference: 'r', evidence: 'e' },
  idempotency_key: `k${id}`, request_hash: 'x', parent_hash: 'y', record_hash: 'z', created_by: 1,
})

describe('membership amount', () => {
  it.each([['1200,50', '1200.50'], ['1200', '1200.00'], ['0,5', '0.50'], ['007.1', '7.10'], [' 15.25 ', '15.25']])('normalises %s', (value, expected) => {
    expect(amountDecimal(value)).toBe(expected)
  })
  it.each(['0', '0,00', '-1', '1.001', '1e3', 'NaN', '10 000', '12345678901234', ''])('rejects %s', value => {
    expect(amountDecimal(value)).toBeNull()
  })
  it('accepts the journal maximum and rejects one cent over', () => {
    expect(amountDecimal('9999999999999.99')).toBe('9999999999999.99')
    expect(amountDecimal('10000000000000')).toBeNull()
  })
})

describe('membership dates', () => {
  it('rejects non-existent calendar days', () => {
    expect(isIsoDate('2026-02-29')).toBe(false)
    expect(isIsoDate('2028-02-29')).toBe(true)
    expect(isIsoDate('2026-13-01')).toBe(false)
  })
  it('does not let a future revision change the status of an earlier date', () => {
    const history = [revision(1, 1, '2020-01-01', 'active'), revision(2, 2, '2999-01-01', 'ended')]
    expect(revisionAt(history, '2026-10-19')?.status).toBe('active')
    expect(revisionAt(history, '2999-01-01')?.status).toBe('ended')
    expect(revisionAt(history, '2019-12-31')).toBeNull()
    expect(lastRevision(history)?.revision_no).toBe(2)
  })
})
