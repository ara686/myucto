import { describe, it, expect, beforeEach, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import type { SaldoSettlementSuggestion } from '@/api/accounting'

const m = vi.hoisted(() => ({
  suggestions: vi.fn(),
  create: vi.fn(),
  toastError: vi.fn(),
  toastSuccess: vi.fn(),
}))

vi.mock('@/api/accounting', () => ({
  accountingApi: { getSaldoSettlementSuggestions: m.suggestions, createSaldoAllocations: m.create },
}))
vi.mock('@/stores/auth', () => ({ useAuthStore: () => ({ isCompanyAdminRole: false }) }))
vi.mock('@/composables/useToast', () => ({ useToast: () => ({ error: m.toastError, success: m.toastSuccess }) }))
vi.mock('@/composables/useFormat', () => ({ formatMoney: (v: number) => String(v), formatDate: (v: string) => v }))
vi.mock('vue-i18n', () => ({
  useI18n: () => ({ t: (key: string, p?: Record<string, unknown>) => (p ? `${key}:${JSON.stringify(p)}` : key) }),
}))
vi.mock('@/components/ui/Modal.vue', () => ({
  __esModule: true,
  default: { name: 'Modal', props: ['title', 'widthClass'], template: '<div><slot /><slot name="footer" /></div>' },
}))

import SaldoSuggestionsDialog from '@/components/accounting/SaldoSuggestionsDialog.vue'

function suggestion(lineId: number, docs: number[]): SaldoSettlementSuggestion {
  return {
    key: `${lineId}:${docs.join(',')}`,
    reason: docs.length > 1 ? 'doc_no_sum' : 'doc_no_amount',
    account: { id: 1, code: '321' },
    partner_name: 'Dodavatel',
    line: { line_id: lineId, entry_id: lineId * 10, entry_date: '2099-07-01', document_no: `ID${lineId}`, description: 'zápočet', account_code: '321.200', free_amount: 100 * docs.length },
    allocations: docs.map(d => ({ doc_type: 'purchase_invoice' as const, doc_id: d, doc_no: `PF-${d}`, remaining: 100, amount: 100 })),
  }
}

describe('SaldoSuggestionsDialog', () => {
  beforeEach(() => {
    m.suggestions.mockReset()
    m.create.mockReset()
    m.toastError.mockReset()
  })

  it('potvrdí označené návrhy jako samostatné skupiny', async () => {
    m.suggestions.mockResolvedValue({ as_of: '2099-12-31', truncated: false, items: [suggestion(1, [11]), suggestion(2, [21, 22]), suggestion(3, [31])] })
    m.create.mockResolvedValue({ created: [{ id: 1 }, { id: 2 }, { id: 3 }], failed: [] })
    const wrapper = mount(SaldoSuggestionsDialog, { props: { periodId: 6, asOf: '2099-12-31', account: 'all' } })
    await flushPromises()

    expect(m.suggestions).toHaveBeenCalledWith({ period_id: 6, as_of: '2099-12-31', account: 'all' })
    const boxes = wrapper.findAll('tbody input[type="checkbox"]')
    expect(boxes).toHaveLength(3)
    await boxes[2].setValue(false)
    await wrapper.find('[data-testid="saldo-suggestions-submit"]').trigger('click')
    await flushPromises()

    expect(m.create).toHaveBeenCalledWith({
      groups: [
        { allocations: [{ line_id: 1, doc_type: 'purchase_invoice', doc_id: 11, amount: 100 }] },
        { allocations: [
          { line_id: 2, doc_type: 'purchase_invoice', doc_id: 21, amount: 100 },
          { line_id: 2, doc_type: 'purchase_invoice', doc_id: 22, amount: 100 },
        ] },
      ],
    }, false)
    expect(wrapper.emitted('done')).toHaveLength(1)
  })

  it('odmítnutý návrh nechá v seznamu s důvodem', async () => {
    m.suggestions.mockResolvedValue({ as_of: '2099-12-31', truncated: false, items: [suggestion(1, [11]), suggestion(2, [21])] })
    m.create.mockResolvedValue({ created: [{ id: 1 }], failed: [{ index: 1, code: 'document_not_open', message: 'Doklad PF-21 není otevřený.' }] })
    const wrapper = mount(SaldoSuggestionsDialog, { props: { periodId: 6, asOf: '2099-12-31', account: '321' } })
    await flushPromises()

    await wrapper.find('[data-testid="saldo-suggestions-submit"]').trigger('click')
    await flushPromises()

    expect(wrapper.findAll('tbody tr')).toHaveLength(1)
    expect(wrapper.text()).toContain('Doklad PF-21 není otevřený.')
    expect(m.toastError).toHaveBeenCalled()
    expect(wrapper.emitted('done')).toHaveLength(1)
  })
})
