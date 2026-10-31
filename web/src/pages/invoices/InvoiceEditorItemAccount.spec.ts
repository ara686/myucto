import { shallowMount } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import { createMemoryHistory, createRouter } from 'vue-router'
import { createPinia, setActivePinia } from 'pinia'
import { describe, expect, it, vi } from 'vitest'
import { computed, nextTick, ref } from 'vue'

vi.mock('@/api/codebooks', () => ({
  codebooksApi: {
    vatRates: () => new Promise(() => {}),
    currencies: vi.fn(),
    units: vi.fn(),
  },
}))
vi.mock('@/api/vatClassifications', () => ({ vatClassificationsApi: { list: vi.fn() } }))
vi.mock('@/api/revenueCategories', () => ({ revenueCategoriesApi: { list: vi.fn() } }))
vi.mock('@/composables/useResultAccounts', () => ({
  useResultAccounts: () => ({
    available: computed(() => true),
    issuedItemAccounts: ref([]),
    load: () => Promise.resolve(),
  }),
}))

import { useSupplierStore } from '@/stores/supplier'
import InvoiceEditor from './InvoiceEditor.vue'

async function mountEditor(accountingMode: string) {
  const pinia = createPinia()
  setActivePinia(pinia)
  const store = useSupplierStore()
  store.availableSuppliers = [{ id: 1, accounting_mode: accountingMode } as never]
  store.currentSupplierId = 1
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [{ path: '/invoices/new', component: InvoiceEditor }],
  })
  await router.push('/invoices/new')
  await router.isReady()
  const wrapper = shallowMount(InvoiceEditor, {
    global: {
      plugins: [router, pinia, createI18n({ legacy: false, locale: 'cs', messages: { cs: {} }, missingWarn: false, fallbackWarn: false })],
      directives: { math: {} },
    },
  })
  const vm = wrapper.vm as unknown as {
    loaded: boolean
    form: { items: Array<Record<string, unknown>> }
    hydrateAssetSelections: () => void
  }
  // Číselníky se v testu nedořeší, formulář se ukáže ručně.
  vm.loaded = true
  return { wrapper, vm }
}

const item = (extra: Record<string, unknown> = {}) => ({ description: 'Licence', quantity: 1, unit: 'ks', unit_price_without_vat: 100, vat_rate_id: 0, ...extra })

describe('InvoiceEditor účet položky', () => {
  it('šipka otevře a zavře účet jen u své položky', async () => {
    const { wrapper, vm } = await mountEditor('double_entry')
    vm.form.items = [item(), item({ description: 'Servis' })]
    await nextTick()
    expect(wrapper.findAll('[data-test="invoice-item-account"]')).toHaveLength(0)

    const toggles = wrapper.findAll('[data-test="item-account-toggle"]')
    expect(toggles).toHaveLength(2)
    await toggles[0].trigger('click')
    expect(wrapper.findAll('[data-test="invoice-item-account"]')).toHaveLength(1)
    expect(toggles[0].attributes('aria-expanded')).toBe('true')
    expect(toggles[1].attributes('aria-expanded')).toBe('false')

    await toggles[0].trigger('click')
    expect(wrapper.findAll('[data-test="invoice-item-account"]')).toHaveLength(0)
    wrapper.unmount()
  })

  it('položka s účtem se po načtení otevře sama, ostatní zůstanou zavřené', async () => {
    const { wrapper, vm } = await mountEditor('double_entry')
    vm.form.items = [item({ revenue_account_code: '604' }), item()]
    vm.hydrateAssetSelections()
    await nextTick()
    expect(wrapper.findAll('[data-test="invoice-item-account"]')).toHaveLength(1)
    wrapper.unmount()
  })

  it('prodej majetku ani daňová evidence šipku nenabízí', async () => {
    const { wrapper, vm } = await mountEditor('double_entry')
    vm.form.items = [item({ asset_id: 5 })]
    await nextTick()
    expect(wrapper.find('[data-test="item-account-toggle"]').exists()).toBe(false)
    wrapper.unmount()

    const taxRecords = await mountEditor('tax_records')
    taxRecords.vm.form.items = [item()]
    await nextTick()
    expect(taxRecords.wrapper.find('[data-test="item-account-toggle"]').exists()).toBe(false)
    taxRecords.wrapper.unmount()
  })
})
