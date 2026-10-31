import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { reactive } from 'vue'
import PurchaseBankMatchingPanel from '../PurchaseBankMatchingPanel.vue'

const m = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn(), accept: vi.fn(), reject: vi.fn(), supplier: { currentSupplierId: 1 } }))
vi.mock('@/api/client', () => ({ api: { get: m.get, post: m.post } }))
vi.mock('@/api/bank', () => ({ bankApi: { acceptMatchSuggestion: m.accept, rejectMatchSuggestion: m.reject } }))
vi.mock('@/api/errors', () => ({ apiErrorMessage: (_e: unknown, fallback: string) => fallback }))
vi.mock('@/stores/supplier', () => ({ useSupplierStore: () => m.supplier }))
vi.mock('vue-i18n', () => ({ useI18n: () => ({ t: (key: string) => key }) }))
vi.mock('@/composables/useFormat', () => ({ formatDate: (v: string) => v, formatMoney: (v: number, c: string) => `${v} ${c}` }))
vi.mock('../MatchSuggestionCandidateCard.vue', () => ({ default: { props: ['canAccept'], emits: ['accept'], template: '<button v-if="canAccept" data-test="accept" @click="$emit(\'accept\')">accept</button>' } }))
const empty = { matches: [], suggestions: [], last_attempt: { outcome: 'searched' } }
const suggestion = { id: 7, bank_transaction_id: 9, candidate_index: 2, posted_at: '2099-06-15', amount: -812.34, currency: 'CZK', description: 'TEST merchant', candidate: {} }
let wrapper: ReturnType<typeof mount> | undefined
beforeEach(() => { vi.clearAllMocks(); m.supplier = reactive({ currentSupplierId: 1 }); m.get.mockResolvedValue({ data: empty }); m.post.mockResolvedValue({ data: empty }) })
afterEach(() => wrapper?.unmount())
async function open(canReview = true) {
  wrapper = mount(PurchaseBankMatchingPanel, { props: { invoiceId: 42, canReview }, global: { stubs: { RouterLink: { props: ['to'], template: '<a :href="to"><slot /></a>' } } } })
  await flushPromises()
  return wrapper
}
describe('Párování v detailu přijatého dokladu', () => {
  it('čtení nespouští opakované hledání a zobrazí prázdný výsledek', async () => {
    const w = await open()
    expect(w.text()).toContain('purchase_invoice.bank_matching.none')
    expect(m.post).not.toHaveBeenCalled()
  })
  it.each(['failed', 'limited', 'locked'])('ukáže stav %s a možnost opakování', async outcome => {
    m.get.mockResolvedValue({ data: { ...empty, last_attempt: { outcome } } })
    const w = await open()
    expect(w.text()).toContain(`purchase_invoice.bank_matching.${outcome}`)
    expect(w.text()).not.toContain('purchase_invoice.bank_matching.none')
    expect(w.text()).toContain('purchase_invoice.bank_matching.retry')
  })
  it('ukáže spárovaný pohyb s odkazem na jeho detail', async () => {
    m.get.mockResolvedValue({ data: { ...empty, matches: [{ ...suggestion, id: 9, statement_id: 3 }] } })
    const w = await open()
    expect(w.text()).toContain('812.34 CZK')
    expect(w.get('a').attributes('href')).toBe('/bank/3?tx=9&detail=1')
  })
  it('potvrdí původní index návrhu a obnoví doklad i úhrady', async () => {
    m.get.mockResolvedValue({ data: { ...empty, suggestions: [suggestion] } })
    const w = await open()
    await w.get('[data-test="accept"]').trigger('click')
    await flushPromises()
    expect(m.accept).toHaveBeenCalledWith(7, 2)
    expect(m.get).toHaveBeenCalledTimes(2)
    expect(w.emitted('changed')).toHaveLength(1)
  })
  it('odmítne návrh podporovanou bankovní operací', async () => {
    m.get.mockResolvedValue({ data: { ...empty, suggestions: [suggestion] } })
    const w = await open()
    const reject = w.findAll('button').find(b => b.text().includes('bank.match_v2.reject'))!
    await reject.trigger('click'); await flushPromises()
    expect(m.reject).toHaveBeenCalledWith(7)
  })
  it('bez práva zápisu nezobrazí potvrzení ani opakování', async () => {
    m.get.mockResolvedValue({ data: { ...empty, suggestions: [suggestion] } })
    const w = await open(false)
    expect(w.findAll('button')).toHaveLength(0)
  })
  it('po změně dokladu zahodí starý výsledek', async () => {
    const w = await open()
    await w.setProps({ invoiceId: 43 }); await flushPromises()
    expect(m.get).toHaveBeenLastCalledWith('/purchase-invoices/43/bank-matching')
  })
})
