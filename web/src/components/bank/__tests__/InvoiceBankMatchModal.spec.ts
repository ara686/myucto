import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { nextTick, reactive, type Ref } from 'vue'
import InvoiceBankMatchModal from '../InvoiceBankMatchModal.vue'

const m = vi.hoisted(() => ({
  paymentCandidates: vi.fn(), matchDocument: vi.fn(), bankMatchingSummary: vi.fn(),
  supplier: null as null | { currentSupplierId: number },
  permissions: null as null | { read: boolean; write: boolean },
  actions: null as null | { purchaseShortfall: Ref<unknown>; matchError: Ref<string> },
  reload: null as null | (() => void),
}))
vi.mock('@/api/purchaseInvoices', () => ({ purchaseInvoicesApi: { bankMatchingSummary: m.bankMatchingSummary } }))
vi.mock('@/api/bank', () => ({ bankApi: { paymentCandidates: m.paymentCandidates } }))
vi.mock('vue-i18n', () => ({ useI18n: () => ({ t: (key: string, params?: Record<string, unknown>) => params ? `${key}:${JSON.stringify(params)}` : key }) }))
vi.mock('@/stores/auth', () => ({ useAuthStore: () => ({ canRead: () => m.permissions!.read, canWrite: () => m.permissions!.write }) }))
vi.mock('@/stores/supplier', () => ({ useSupplierStore: () => m.supplier }))
vi.mock('@/composables/useHotkey', () => ({ useHotkey: () => undefined }))
vi.mock('@/composables/useFormat', () => ({ formatDate: (v: string) => v, formatMoney: (v: number, currency: string) => `${v} ${currency}` }))
vi.mock('@/api/errors', () => ({ apiErrorMessage: (e: { message?: string }, fallback: string) => e.message || fallback }))
vi.mock('@/composables/useBankTransactionActions', async () => {
  const { ref } = await import('vue')
  return {
    useBankTransactionActions: (opts: { reload: () => void }) => {
      m.reload = opts.reload
      m.actions = { purchaseShortfall: ref(null), matchError: ref('') }
      return { ...m.actions, matchDocument: m.matchDocument }
    },
  }
})
vi.mock('../BankMatchModal.vue', () => ({
  default: { props: ['actions'], template: '<div v-if="actions.purchaseShortfall.value" data-testid="shortfall">shortfall</div>' },
}))

const candidate = {
  id: 31, statement_id: 8, posted_at: '2097-06-01', amount: -121, currency: 'CZK',
  counterparty_name: 'Synthetic supplier', variable_symbol: '100', description: 'Synthetic payment',
  bank_ref: 'TEST-31', account_number: '1000000005', bank_code: '0100',
}
const result = (items = [candidate], page = 1, pages = 1) => ({ items, total: items.length, page, pages, limit: 50 })
let wrapper: ReturnType<typeof mount> | undefined

beforeEach(() => {
  vi.clearAllMocks()
  m.supplier = reactive({ currentSupplierId: 1 })
  m.permissions = reactive({ read: true, write: true })
  m.paymentCandidates.mockResolvedValue(result())
  m.bankMatchingSummary.mockResolvedValue({ suggestions: [] })
  m.matchDocument.mockResolvedValue(false)
})
afterEach(() => {
  wrapper?.unmount()
  wrapper = undefined
  vi.useRealTimers()
})

async function open(docType: 'invoice' | 'purchase_invoice' = 'invoice', allowSuggestedPreselection = false) {
  wrapper = mount(InvoiceBankMatchModal, { props: { docType, docId: 19, docRef: 'TEST-DOC-19', allowSuggestedPreselection } })
  await flushPromises()
  return wrapper
}

describe('InvoiceBankMatchModal', () => {
  it('loads candidates for the document and requires explicit selection and confirmation', async () => {
    const w = await open('purchase_invoice')
    expect(m.paymentCandidates).toHaveBeenCalledWith({ invoiceId: undefined, purchaseInvoiceId: 19, search: undefined, page: 1 })
    expect(w.get('[data-testid="confirm-match"]').attributes('disabled')).toBeDefined()
    expect(w.text()).toContain('TEST-DOC-19')
    expect(w.text()).toContain('-121 CZK')
    expect(w.text()).toContain('1000000005/0100')
    await w.get('input[type="radio"]').setValue()
    expect(m.matchDocument).not.toHaveBeenCalled()
    await w.get('[data-testid="confirm-match"]').trigger('click')
    await flushPromises()
    expect(m.matchDocument).toHaveBeenCalledWith(31, { id: 19, type: 'purchase_invoice', ref: 'TEST-DOC-19' })
    expect(w.find('[role="dialog"]').exists()).toBe(true)
  })

  it('preselects one fresh purchase proposal but confirms only when requested', async () => {
    m.bankMatchingSummary.mockResolvedValue({ suggestions: [{bank_transaction_id:31}] })
    const w = await open('purchase_invoice', true)
    expect(m.bankMatchingSummary).toHaveBeenCalledWith(19)
    expect(m.paymentCandidates).toHaveBeenCalledWith(expect.objectContaining({preferredTransactionId:31}))
    expect((w.get('input[type="radio"]').element as HTMLInputElement).checked).toBe(true)
    expect(m.matchDocument).not.toHaveBeenCalled()
    await w.get('[data-testid="confirm-match"]').trigger('click'); await flushPromises()
    expect(m.matchDocument).toHaveBeenCalledOnce()
  })
  it.each([{suggestions: []}, {suggestions: [{bank_transaction_id:31},{bank_transaction_id:32}]}])('does not preselect without a unique proposal', async ({suggestions}) => {
    m.bankMatchingSummary.mockResolvedValue({suggestions})
    const w = await open('purchase_invoice', true)
    expect(m.paymentCandidates).toHaveBeenCalledWith(expect.not.objectContaining({preferredTransactionId: expect.anything()}))
    expect((w.get('input[type="radio"]').element as HTMLInputElement).checked).toBe(false)
    expect(w.get('[data-testid="confirm-match"]').attributes('disabled')).toBeDefined()
  })
  it('keeps original manual search when recommendations fail or are no longer eligible', async () => {
    m.bankMatchingSummary.mockRejectedValueOnce(new Error('recommendations unavailable'))
    let w = await open('purchase_invoice', true)
    expect(w.find('input[type="radio"]').exists()).toBe(true)
    expect(w.get('[data-testid="confirm-match"]').attributes('disabled')).toBeDefined()
    w.unmount()
    m.bankMatchingSummary.mockResolvedValueOnce({suggestions:[{bank_transaction_id:99}]})
    w = await open('purchase_invoice', true)
    expect(w.get('[data-testid="confirm-match"]').attributes('disabled')).toBeDefined()
  })
  it('does not override a manual selection after searching', async () => {
    vi.useFakeTimers()
    m.bankMatchingSummary.mockResolvedValue({suggestions:[{bank_transaction_id:31}]})
    const w = await open('purchase_invoice', true)
    await w.get('input[type="search"]').setValue('another')
    await vi.advanceTimersByTimeAsync(300); await flushPromises()
    expect(m.bankMatchingSummary).toHaveBeenCalledTimes(1)
    expect(m.paymentCandidates).toHaveBeenLastCalledWith(expect.objectContaining({preferredTransactionId:31}))
    expect(w.get('[data-testid="confirm-match"]').attributes('disabled')).toBeDefined()
  })
  it('lets the user replace the recommended selection', async () => {
    m.bankMatchingSummary.mockResolvedValue({suggestions:[{bank_transaction_id:31}]})
    m.paymentCandidates.mockResolvedValue(result([candidate, {...candidate, id:32}]))
    const w = await open('purchase_invoice', true)
    await w.findAll('input[type="radio"]')[1]!.setValue()
    await w.get('[data-testid="confirm-match"]').trigger('click'); await flushPromises()
    expect(m.matchDocument).toHaveBeenCalledWith(32, expect.objectContaining({id:19}))
  })
  it('keeps preferred ordering across pages without selecting again', async () => {
    m.bankMatchingSummary.mockResolvedValue({suggestions:[{bank_transaction_id:31}]})
    m.paymentCandidates.mockResolvedValueOnce(result([candidate], 1, 2))
    const w = await open('purchase_invoice', true)
    m.paymentCandidates.mockResolvedValueOnce(result([candidate], 2, 2))
    await w.findAll('button').find(button => button.text() === 'bank.invoice_match.next')!.trigger('click')
    await flushPromises()
    expect(m.paymentCandidates).toHaveBeenLastCalledWith(expect.objectContaining({page:2, preferredTransactionId:31}))
    expect((w.get('input[type="radio"]').element as HTMLInputElement).checked).toBe(false)
    expect(m.bankMatchingSummary).toHaveBeenCalledTimes(1)
  })
  it('discards a recommendation returned after switching supplier', async () => {
    let resolveSummary!: (v: {suggestions:{bank_transaction_id:number}[]}) => void
    m.bankMatchingSummary.mockImplementationOnce(() => new Promise(resolve => {resolveSummary=resolve}))
    const w = await open('purchase_invoice', true)
    m.supplier!.currentSupplierId=2; await nextTick()
    resolveSummary({suggestions:[{bank_transaction_id:31}]}); await flushPromises()
    expect(m.paymentCandidates).not.toHaveBeenCalled()
    expect(w.emitted('close')).toHaveLength(1)
  })
  it('discards a recommendation returned for the previous document', async () => {
    let resolveSummary!: (v: {suggestions:{bank_transaction_id:number}[]}) => void
    m.bankMatchingSummary.mockImplementationOnce(() => new Promise(resolve => {resolveSummary=resolve}))
    const w = await open('purchase_invoice', true)
    await w.setProps({docId:20}); await flushPromises()
    resolveSummary({suggestions:[{bank_transaction_id:31}]}); await flushPromises()
    expect(m.paymentCandidates).toHaveBeenCalledTimes(1)
    expect(m.paymentCandidates).toHaveBeenCalledWith(expect.objectContaining({purchaseInvoiceId:20}))
    expect(w.get('[data-testid="confirm-match"]').attributes('disabled')).toBeDefined()
  })
  it('does not read purchase recommendations for issued invoices', async () => {
    await open('invoice', true)
    expect(m.bankMatchingSummary).not.toHaveBeenCalled()
  })

  it('ignores obsolete search responses and debounces a new search', async () => {
    vi.useFakeTimers()
    let initialResolve!: (value: ReturnType<typeof result>) => void
    m.paymentCandidates.mockImplementationOnce(() => new Promise(resolve => { initialResolve = resolve }))
    const w = await open()
    await w.get('input[type="search"]').setValue('new payment')
    m.paymentCandidates.mockResolvedValueOnce(result([{ ...candidate, id: 42, counterparty_name: 'New result' }]))
    await vi.advanceTimersByTimeAsync(300)
    await flushPromises()
    initialResolve(result())
    await flushPromises()
    expect(w.text()).toContain('New result')
    expect(w.text()).not.toContain('Synthetic supplier')
    expect(m.paymentCandidates).toHaveBeenLastCalledWith({ invoiceId: 19, purchaseInvoiceId: undefined, search: 'new payment', page: 1 })
  })

  it('resets selection when changing server pages', async () => {
    m.paymentCandidates.mockResolvedValueOnce(result([candidate], 1, 2))
    const w = await open()
    await w.get('input[type="radio"]').setValue()
    m.paymentCandidates.mockResolvedValueOnce(result([{ ...candidate, id: 32 }], 2, 2))
    await w.findAll('button').find(button => button.text() === 'bank.invoice_match.next')!.trigger('click')
    await flushPromises()
    expect(m.paymentCandidates).toHaveBeenLastCalledWith(expect.objectContaining({ page: 2 }))
    expect(w.get('[data-testid="confirm-match"]').attributes('disabled')).toBeDefined()
  })

  it('shows candidate loading errors and offers retry', async () => {
    m.paymentCandidates.mockRejectedValueOnce(new Error('Synthetic candidate failure'))
    const w = await open()
    expect(w.get('[role="alert"]').text()).toContain('Synthetic candidate failure')
    await w.findAll('button').find(button => button.text() === 'bank.invoice_match.retry')!.trigger('click')
    await flushPromises()
    expect(w.find('[role="alert"]').exists()).toBe(false)
    expect(w.find('input[type="radio"]').exists()).toBe(true)
  })

  it('prevents duplicate confirms and closes after a successful match', async () => {
    let resolveMatch!: (value: boolean) => void
    m.matchDocument.mockImplementationOnce(() => new Promise(resolve => { resolveMatch = resolve }))
    const w = await open()
    await w.get('input[type="radio"]').setValue()
    await w.get('[data-testid="confirm-match"]').trigger('click')
    await w.get('[data-testid="confirm-match"]').trigger('click')
    expect(m.matchDocument).toHaveBeenCalledTimes(1)
    m.reload!()
    expect(w.emitted('done')).toHaveLength(1)
    resolveMatch(true)
    await flushPromises()
    expect(w.find('[role="dialog"]').exists()).toBe(false)
    expect(w.emitted('close')).toHaveLength(1)
  })

  it('keeps the shortfall modal alive after matching until it is dismissed', async () => {
    m.matchDocument.mockImplementationOnce(async () => {
      m.actions!.purchaseShortfall.value = { purchaseInvoiceId: 19, remaining: 21, currency: 'CZK', docNumber: 'TEST-DOC-19' }
      m.reload!()
      return true
    })
    const w = await open('purchase_invoice')
    await w.get('input[type="radio"]').setValue()
    await w.get('[data-testid="confirm-match"]').trigger('click')
    await flushPromises()
    expect(w.find('[role="dialog"]').exists()).toBe(false)
    expect(w.find('[data-testid="shortfall"]').exists()).toBe(true)
    expect(w.emitted('close')).toBeUndefined()
    m.actions!.purchaseShortfall.value = null
    await nextTick()
    expect(w.emitted('close')).toHaveLength(1)
  })

  it('rejects missing permissions and closes when permissions are revoked', async () => {
    m.permissions!.write = false
    const denied = await open()
    expect(m.paymentCandidates).not.toHaveBeenCalled()
    expect(denied.emitted('close')).toHaveLength(1)
    denied.unmount()
    m.permissions!.write = true
    const allowed = await open()
    m.permissions!.read = false
    await nextTick()
    expect(allowed.emitted('close')).toHaveLength(1)
  })

  it('closes on supplier changes and ignores pending candidate responses', async () => {
    let resolveCandidates!: (value: ReturnType<typeof result>) => void
    m.paymentCandidates.mockImplementationOnce(() => new Promise(resolve => { resolveCandidates = resolve }))
    const w = await open()
    m.supplier!.currentSupplierId = 2
    await nextTick()
    expect(w.emitted('close')).toHaveLength(1)
    resolveCandidates(result())
    await flushPromises()
    expect(w.find('input[type="radio"]').exists()).toBe(false)
    expect(m.matchDocument).not.toHaveBeenCalled()
  })
})
