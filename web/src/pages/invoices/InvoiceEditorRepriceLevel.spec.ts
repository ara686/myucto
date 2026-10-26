import { shallowMount } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import { createMemoryHistory, createRouter } from 'vue-router'
import { createPinia, setActivePinia } from 'pinia'
import { afterEach, describe, expect, it, vi } from 'vitest'
import type { SupplierBrief } from '@/api/auth'
import type { StockQuoteRequest } from '@/api/stock'
import { useSupplierStore } from '@/stores/supplier'

const pending = () => new Promise(() => {})
const quoteItems = vi.fn((payload: StockQuoteRequest) => Promise.resolve({
  lines: payload.lines.map(line => ({
    key: line.key, stock_item_id: line.stock_item_id, unit: 'ks', base_unit: 'ks', numerator: 1, denominator: 1,
    base_quantity: '1.000', unit_price: '80.00', base_unit_price: '80.00', list_unit_price: '100.00',
    price_source: 'price_level_discount', customer_price_id: null, discount_pct: '20.000', promo: null,
  })),
}))

vi.mock('@/api/codebooks', () => ({
  codebooksApi: { vatRates: () => new Promise(() => {}), currencies: vi.fn(), units: vi.fn() },
}))
vi.mock('@/api/vatClassifications', () => ({ vatClassificationsApi: { list: vi.fn() } }))
vi.mock('@/api/revenueCategories', () => ({ revenueCategoriesApi: { list: vi.fn() } }))
vi.mock('@/api/stock', () => ({
  stockApi: new Proxy({}, { get: (_target, key) => (key === 'quoteItems' ? quoteItems : pending) }),
}))
vi.mock('@/api/eshop', () => ({ eshopApi: new Proxy({}, { get: () => pending }) }))
vi.mock('@/api/invoices', () => ({ invoicesApi: new Proxy({}, { get: () => pending }) }))

import InvoiceEditor from './InvoiceEditor.vue'

type Row = { stock_item_id: number | null; unit: string; quantity: number; unit_price_without_vat: number; description?: string }
type Vm = {
  form: { currency: string; client_id: number | null; price_level_id: number | null; items: Row[] }
  loaded: boolean
  editedStatus: string
  clients: { id: number; price_level_id: number | null }[]
  priceLevels: { id: number; name: string; is_active: boolean }[]
  priceLevelsLoaded: boolean
}

const flush = () => new Promise(resolve => setTimeout(resolve, 0))

// Uložený koncept (nebo nový doklad) s načtenými řádky: dvě karty a jeden řádek bez karty.
async function mountEditor(path: string) {
  const pinia = createPinia()
  setActivePinia(pinia)
  useSupplierStore().setAvailable([{ id: 1, is_vat_payer: true, stock_enabled: true } as SupplierBrief], 1, true)
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [{ path: '/invoices/new', component: InvoiceEditor }, { path: '/invoices/:id', component: InvoiceEditor }],
  })
  await router.push(path)
  await router.isReady()
  const wrapper = shallowMount(InvoiceEditor, {
    global: {
      plugins: [router, pinia, createI18n({ legacy: false, locale: 'cs', messages: { cs: {} }, missingWarn: false, fallbackWarn: false })],
      directives: { math: {} },
    },
  })
  const vm = wrapper.vm as unknown as Vm
  vm.form.currency = 'CZK'
  vm.clients = [{ id: 1, price_level_id: null }, { id: 2, price_level_id: 3 }, { id: 4, price_level_id: 3 }]
  vm.form.client_id = 1
  vm.form.items = [
    { stock_item_id: 7, unit: 'ks', quantity: 1, unit_price_without_vat: 100 },
    { stock_item_id: 8, unit: 'ks', quantity: 1, unit_price_without_vat: 95 },
    { stock_item_id: null, unit: 'h', quantity: 1, unit_price_without_vat: 500, description: 'Montáž' },
  ]
  vm.priceLevels = [{ id: 3, name: 'Dealer', is_active: true }, { id: 5, name: 'Urgent', is_active: true }]
  vm.priceLevelsLoaded = true
  vm.loaded = true
  await flush()
  return { wrapper, vm }
}

describe('InvoiceEditor: přecenění uloženého dokladu po změně hladiny', () => {
  afterEach(() => {
    vi.restoreAllMocks()
    quoteItems.mockClear()
  })

  it('změna hladiny dokladu se zeptá a po potvrzení nacení všechny skladové řádky', async () => {
    const confirm = vi.spyOn(window, 'confirm').mockReturnValue(true)
    const { wrapper, vm } = await mountEditor('/invoices/42')

    vm.form.price_level_id = 5
    await flush()

    expect(confirm).toHaveBeenCalledTimes(1)
    expect(confirm).toHaveBeenCalledWith('invoice.price_level.reprice_confirm')
    expect(quoteItems).toHaveBeenCalledTimes(1)
    expect(quoteItems.mock.calls[0][0].price_level_id).toBe(5)
    expect(quoteItems.mock.calls[0][0].lines.map(line => line.stock_item_id)).toEqual([7, 8])
    expect(vm.form.items.map(row => row.unit_price_without_vat)).toEqual([80, 80, 500])
    wrapper.unmount()
  })

  it('Ponechat nechá ceny beze změny', async () => {
    const confirm = vi.spyOn(window, 'confirm').mockReturnValue(false)
    const { wrapper, vm } = await mountEditor('/invoices/42')

    vm.form.price_level_id = 5
    await flush()

    expect(confirm).toHaveBeenCalledTimes(1)
    expect(quoteItems).not.toHaveBeenCalled()
    expect(vm.form.items.map(row => row.unit_price_without_vat)).toEqual([100, 95, 500])
    wrapper.unmount()
  })

  it('odběratel s jinou hladinou se zeptá, se stejnou hladinou ne', async () => {
    const confirm = vi.spyOn(window, 'confirm').mockReturnValue(false)
    const { wrapper, vm } = await mountEditor('/invoices/42')

    vm.form.client_id = 2
    await flush()
    expect(confirm).toHaveBeenCalledTimes(1)

    vm.form.client_id = 4
    await flush()
    expect(confirm).toHaveBeenCalledTimes(1)
    wrapper.unmount()
  })

  it('vystavený doklad bez úpravy (force) ani nový doklad se neptají', async () => {
    const confirm = vi.spyOn(window, 'confirm').mockReturnValue(true)
    const issued = await mountEditor('/invoices/42')
    issued.vm.editedStatus = 'issued'
    issued.vm.form.price_level_id = 5
    await flush()
    issued.wrapper.unmount()

    const created = await mountEditor('/invoices/new')
    created.vm.form.price_level_id = 5
    await flush()
    created.wrapper.unmount()

    expect(confirm).not.toHaveBeenCalled()
    expect(quoteItems).not.toHaveBeenCalled()
  })
})
