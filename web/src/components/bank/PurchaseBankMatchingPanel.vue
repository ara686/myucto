<script setup lang="ts">
import { onActivated, onBeforeUnmount, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { api } from '@/api/client'
import { apiErrorMessage } from '@/api/errors'
import { bankApi, type MatchSuggestionCandidate } from '@/api/bank'
import { useSupplierStore } from '@/stores/supplier'
import { useWindowRefresh } from '@/composables/useWindowRefresh'
import { formatDate, formatMoney } from '@/composables/useFormat'
import { btnOutline, ICONS } from '@/components/ui/buttonStyles'
import MatchSuggestionCandidateCard from './MatchSuggestionCandidateCard.vue'

const props = defineProps<{ invoiceId: number; canReview: boolean; canManual?: boolean; revision?: string }>()
const emit = defineEmits<{ changed: []; manual: [] }>()
const { t } = useI18n()
const supplier = useSupplierStore()
interface Payment { id: number; statement_id: number; posted_at: string; amount: number; currency: string; description: string }
interface Suggestion extends Payment { bank_transaction_id: number; candidate_index: number; candidate: MatchSuggestionCandidate }
interface Result { matches: Payment[]; suggestions: Suggestion[]; last_attempt: { outcome: string; checked_at: string } | null }
const result = ref<Result | null>(null)
const busy = ref(false)
const error = ref('')
let generation = 0
let disposed = false
async function load(retry = false) {
  const version = ++generation
  const id = props.invoiceId
  error.value = ''
  busy.value = true
  try {
    const path = `/purchase-invoices/${id}/bank-matching`
    const response = retry ? await api.post(`${path}/retry`) : await api.get(path)
    if (version !== generation || disposed) return
    result.value = response.data
    if (retry) emit('changed')
  } catch (e) {
    if (version === generation && !disposed) error.value = apiErrorMessage(e, t('purchase_invoice.bank_matching.failed'))
  } finally {
    if (version === generation) busy.value = false
  }
}
async function review(row: Suggestion, accept: boolean) {
  if (busy.value || !props.canReview) return
  const version = generation
  busy.value = true
  error.value = ''
  try {
    if (accept) await bankApi.acceptMatchSuggestion(Number(row.id), row.candidate_index)
    else await bankApi.rejectMatchSuggestion(Number(row.id))
    if (version !== generation || disposed) return
    await load()
    emit('changed')
  } catch (e) {
    if (version === generation && !disposed) error.value = apiErrorMessage(e, t('bank.match_failed'))
  } finally {
    if (version === generation && !disposed) busy.value = false
  }
}
watch(() => [props.invoiceId, props.revision, supplier.currentSupplierId], () => { result.value = null; void load() }, { immediate: true })
onActivated(() => { void load() })
useWindowRefresh(() => load(), () => !busy.value, { partial: true })
onBeforeUnmount(() => { disposed = true; ++generation })
</script>

<template>
  <section class="bg-surface border border-neutral-200 rounded-lg p-5 shadow-sm space-y-3">
    <h3 class="text-sm font-medium text-neutral-700">{{ t('purchase_invoice.bank_matching.title') }}</h3>
    <p v-if="error" role="alert" class="text-sm text-danger-700">{{ error }}</p>
    <p v-if="result?.last_attempt?.outcome === 'failed'" role="alert" class="text-sm text-warning-700">{{ t('purchase_invoice.bank_matching.failed') }}</p>
    <p v-else-if="result?.last_attempt?.outcome === 'limited'" role="alert" class="text-sm text-warning-700">{{ t('purchase_invoice.bank_matching.limited') }}</p>
    <p v-else-if="result?.last_attempt?.outcome === 'locked'" class="text-sm text-warning-700">{{ t('purchase_invoice.bank_matching.locked') }}</p>
    <div v-for="payment in result?.matches" :key="payment.id" class="text-sm">
      <span class="text-success-700">{{ t('purchase_invoice.bank_matching.matched') }}</span>
      · {{ formatDate(payment.posted_at) }} · {{ formatMoney(Math.abs(Number(payment.amount)), payment.currency) }}
      <RouterLink :to="`/bank/${payment.statement_id}?tx=${payment.id}&detail=1`" class="text-primary-600 underline ml-2">{{ t('purchase_invoice.bank_matching.open') }}</RouterLink>
    </div>
    <div v-for="row in result?.suggestions" :key="row.id" class="border border-warning-500/30 rounded-lg p-3 space-y-2">
      <p class="text-sm font-medium">{{ t('purchase_invoice.bank_matching.suggested') }}</p>
      <p class="text-sm">{{ formatDate(row.posted_at) }} · {{ formatMoney(Math.abs(Number(row.amount)), row.currency) }} · {{ row.description }}</p>
      <p class="text-sm text-neutral-500">{{ t('purchase_invoice.bank_matching.reason') }}</p>
      <MatchSuggestionCandidateCard :candidate="row.candidate" :reviewing="busy" :can-accept="canReview" dense @accept="review(row, true)" />
      <button v-if="canReview" type="button" :class="btnOutline('danger')" :disabled="busy" @click="review(row, false)">
        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path :d="ICONS.x" /></svg>{{ t('bank.match_v2.reject') }}
      </button>
    </div>
    <p v-if="result && !result.matches.length && !result.suggestions.length && !busy && !error && !['failed', 'limited', 'locked'].includes(result.last_attempt?.outcome ?? '')" class="text-sm text-neutral-500">{{ t('purchase_invoice.bank_matching.none') }}</p>
    <div v-if="canReview" class="flex flex-wrap gap-2">
      <button type="button" :class="btnOutline('neutral')" :disabled="busy" @click="load(true)">
        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path :d="ICONS.cycle" /></svg>{{ t('purchase_invoice.bank_matching.retry') }}
      </button>
      <button v-if="canManual" type="button" :class="btnOutline('neutral')" :disabled="busy" @click="emit('manual')">
        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path :d="ICONS.search" /></svg>{{ t('purchase_invoice.bank_matching.manual') }}
      </button>
    </div>
  </section>
</template>
