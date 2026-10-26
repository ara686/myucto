<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { accountingApi, type SaldoSettlementSuggestion } from '@/api/accounting'
import { useAuthStore } from '@/stores/auth'
import { useToast } from '@/composables/useToast'
import { formatDate, formatMoney } from '@/composables/useFormat'
import { ICONS, btnFilled, btnOutline } from '@/components/ui/buttonStyles'
import Modal from '@/components/ui/Modal.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import { apiErrorMessage, withPeriodForce } from './saldoSettle'

/**
 * Návrh párování: jisté shody otevřených dokladů saldokonta se zápisy v deníku
 * k hromadnému potvrzení. Každý potvrzený návrh je samostatná skupina: když jednu
 * server odmítne (doklad mezitím uhrazený), ostatní projdou.
 */
const props = defineProps<{
  periodId: number
  asOf: string
  account: string
}>()

const emit = defineEmits<{
  (e: 'close'): void
  (e: 'done'): void
}>()

const { t } = useI18n()
const toast = useToast()
const auth = useAuthStore()

const items = ref<SaldoSettlementSuggestion[]>([])
const truncated = ref(false)
const loading = ref(true)
const saving = ref(false)
const checked = ref<Set<string>>(new Set())
const failures = ref<{ key: string; message: string }[]>([])

const allChecked = computed(() => items.value.length > 0 && checked.value.size === items.value.length)
const selectedTotal = computed(() => Math.round(items.value
  .filter(i => checked.value.has(i.key))
  .reduce((s, i) => s + i.allocations.reduce((a, x) => a + x.amount, 0), 0) * 100) / 100)

async function load() {
  loading.value = true
  try {
    const r = await accountingApi.getSaldoSettlementSuggestions({ period_id: props.periodId, as_of: props.asOf, account: props.account })
    items.value = r.items
    truncated.value = r.truncated
    checked.value = new Set(r.items.map(i => i.key))
  } catch (e) {
    toast.error(apiErrorMessage(e, t('common.error')))
    items.value = []
  } finally {
    loading.value = false
  }
}

function toggle(key: string) {
  const next = new Set(checked.value)
  if (next.has(key)) next.delete(key)
  else next.add(key)
  checked.value = next
}

function toggleAll() {
  checked.value = allChecked.value ? new Set() : new Set(items.value.map(i => i.key))
}

async function confirmSelected() {
  const chosen = items.value.filter(i => checked.value.has(i.key))
  if (chosen.length === 0 || saving.value) return
  saving.value = true
  failures.value = []
  try {
    const groups = chosen.map(i => ({
      allocations: i.allocations.map(a => ({ line_id: i.line.line_id, doc_type: a.doc_type, doc_id: a.doc_id, amount: a.amount })),
    }))
    const result = await withPeriodForce(
      force => accountingApi.createSaldoAllocations({ groups }, force),
      auth.isCompanyAdminRole,
      () => window.confirm(t('accounting.saldo_settle.period_closed_confirm')),
    )
    if (result === null) return
    failures.value = result.failed.map(f => ({ key: chosen[f.index]?.key ?? String(f.index), message: f.message }))
    const confirmed = chosen.length - result.failed.length
    if (confirmed > 0) toast.success(t('accounting.saldo_settle.suggestions_saved', { count: confirmed }))
    if (result.failed.length > 0) {
      toast.error(t('accounting.saldo_settle.suggestions_failed', { count: result.failed.length }))
      const failedKeys = new Set(failures.value.map(f => f.key))
      items.value = items.value.filter(i => !checked.value.has(i.key) || failedKeys.has(i.key))
      checked.value = new Set()
      if (confirmed > 0) emit('done')
      return
    }
    emit('done')
  } catch (e) {
    toast.error(apiErrorMessage(e, t('common.error')))
  } finally {
    saving.value = false
  }
}

function failureOf(key: string): string | undefined {
  return failures.value.find(f => f.key === key)?.message
}

onMounted(load)
</script>

<template>
  <Modal :title="t('accounting.saldo_settle.suggestions_title')" width-class="max-w-5xl" @close="emit('close')">
    <div class="space-y-3" data-testid="saldo-suggestions-dialog">
      <p class="text-sm text-neutral-600">{{ t('accounting.saldo_settle.suggestions_intro') }}</p>
      <p v-if="truncated" class="text-xs px-3 py-2 rounded-md bg-warning-50 text-warning-700 border border-warning-500/30">{{ t('accounting.saldo_settle.suggestions_truncated') }}</p>

      <div v-if="loading" class="text-center text-neutral-500 py-8 text-sm">{{ t('common.loading') }}</div>
      <EmptyState v-else-if="items.length === 0" dense accent="success" icon="checkCircle" :title="t('accounting.saldo_settle.suggestions_empty')" />
      <div v-else class="overflow-x-auto border border-neutral-200 rounded-md">
        <table class="w-full text-sm">
          <thead class="bg-neutral-50 text-xs text-neutral-500 uppercase tracking-wide">
            <tr>
              <th class="px-3 py-2 w-8">
                <input type="checkbox" class="rounded border-neutral-300" :checked="allChecked" :aria-label="t('accounting.saldo_settle.suggestions_all')" @change="toggleAll" />
              </th>
              <th class="px-3 py-2 text-left font-medium">{{ t('accounting.saldo.col_account') }}</th>
              <th class="px-3 py-2 text-left font-medium">{{ t('accounting.saldo_settle.col_entry') }}</th>
              <th class="px-3 py-2 text-left font-medium">{{ t('accounting.saldo.col_doc') }}</th>
              <th class="px-3 py-2 text-left font-medium">{{ t('accounting.saldo.col_partner') }}</th>
              <th class="px-3 py-2 text-right font-medium">{{ t('accounting.saldo_settle.col_amount') }}</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-neutral-100">
            <tr v-for="s in items" :key="s.key" class="hover:bg-neutral-50 align-top">
              <td class="px-3 py-2">
                <input type="checkbox" class="rounded border-neutral-300" :checked="checked.has(s.key)"
                  :aria-label="t('accounting.saldo_settle.pick_aria', { entry: s.line.document_no || s.line.entry_id })" @change="toggle(s.key)" />
              </td>
              <td class="px-3 py-2 font-mono text-xs whitespace-nowrap">{{ s.line.account_code }}</td>
              <td class="px-3 py-2 min-w-[14rem]">
                <span class="block"><span class="whitespace-nowrap">{{ formatDate(s.line.entry_date) }}</span> · {{ [s.line.document_no, s.line.description].filter(Boolean).join(' - ') }}</span>
                <span class="text-[11px] text-neutral-500">{{ t(`accounting.saldo_settle.reason_${s.reason}`) }}</span>
                <span v-if="failureOf(s.key)" class="block text-xs text-danger-600">{{ failureOf(s.key) }}</span>
              </td>
              <td class="px-3 py-2">
                <span v-for="a in s.allocations" :key="`${a.doc_type}-${a.doc_id}`" class="block font-mono whitespace-nowrap">{{ a.doc_no }}</span>
              </td>
              <td class="px-3 py-2">{{ s.partner_name }}</td>
              <td class="px-3 py-2 text-right font-mono whitespace-nowrap">{{ formatMoney(s.line.free_amount) }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <template #footer>
      <div class="flex flex-wrap items-center justify-end gap-2">
        <span v-if="items.length > 0" class="text-sm mr-auto">
          {{ t('accounting.saldo_settle.suggestions_selected', { count: checked.size }) }}
          <strong class="font-mono">{{ formatMoney(selectedTotal) }}</strong>
        </span>
        <button type="button" :class="btnOutline('neutral')" @click="emit('close')">{{ t('common.cancel') }}</button>
        <button type="button" :class="btnFilled('success')" :disabled="checked.size === 0 || saving" data-testid="saldo-suggestions-submit" @click="confirmSelected">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.check" /></svg>
          {{ t('accounting.saldo_settle.suggestions_confirm') }}
        </button>
      </div>
    </template>
  </Modal>
</template>
