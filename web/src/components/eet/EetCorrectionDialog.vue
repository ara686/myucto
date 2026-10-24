<script setup lang="ts">
import { computed, onBeforeUnmount, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import Modal from '@/components/ui/Modal.vue'
import { ICONS, btnFilled, btnOutline } from '@/components/ui/buttonStyles'
import { formInput, formLabel } from '@/components/ui/formStyles'
import {
  eetApi,
  type EetCorrection,
  type EetCorrectionPreview,
  type EetCorrectionReason,
  type EetCorrectionRequest,
  type EetRecord,
} from '@/api/eet'
import { apiErrorCode, apiErrorMessage } from '@/api/errors'
import { formatMoney } from '@/composables/useFormat'
import { newIdempotencyKey } from '@/composables/useEetCapabilities'
import EetPaymentStates from './EetPaymentStates.vue'

/**
 * Záporná oprava evidované tržby (vratka, nepodařená platba, chybné údaje).
 * Oprava dostane vlastní číslo a aktuální čas, nikdy nepřepisuje původní záznam
 * a netvrdí, že se peníze vrátily; to je věc účetnictví a banky.
 */
const props = defineProps<{ record: EetRecord }>()
const emit = defineEmits<{
  (e: 'close'): void
  (e: 'done', correction: EetCorrection): void
}>()

const REASONS: EetCorrectionReason[] = ['refund', 'failed_payment', 'wrong_data', 'duplicate', 'other']

const { t } = useI18n()
const form = reactive({ amount: '', reason: 'refund' as EetCorrectionReason, note: '' })
const preview = ref<EetCorrectionPreview | null>(null)
const previewError = ref('')
const previewBusy = ref(false)
const submitting = ref(false)
const submitError = ref('')
const networkUncertain = ref(false)
const result = ref<EetCorrection | null>(null)

let key = newIdempotencyKey()
let lastJson = ''
let timer: ReturnType<typeof setTimeout> | null = null
let seq = 0

const normalizedAmount = computed(() => {
  const raw = form.amount.replace(/\s/g, '').replace(',', '.')
  if (raw === '') return null
  if (!/^\d+(\.\d{1,2})?$/.test(raw)) return undefined
  return Number(raw) > 0 ? Number(raw).toFixed(2) : undefined
})
const amountInvalid = computed(() => normalizedAmount.value === undefined)

function body(): EetCorrectionRequest {
  const b: EetCorrectionRequest = { reason: form.reason }
  if (typeof normalizedAmount.value === 'string') b.amount = normalizedAmount.value
  if (form.note.trim()) b.note = form.note.trim().slice(0, 255)
  return b
}

async function runPreview() {
  if (amountInvalid.value) { preview.value = null; return }
  const my = ++seq
  previewBusy.value = true
  try {
    const p = await eetApi.previewCorrection(props.record.record_id, body())
    if (my !== seq) return
    preview.value = p
    previewError.value = ''
  } catch (e) {
    if (my !== seq) return
    preview.value = null
    previewError.value = apiErrorMessage(e, t('eet.correction.preview_failed'))
  } finally {
    if (my === seq) previewBusy.value = false
  }
}
watch(() => [form.amount, form.reason], () => {
  if (timer) clearTimeout(timer)
  timer = setTimeout(() => { void runPreview() }, 350)
}, { immediate: true })
onBeforeUnmount(() => { if (timer) clearTimeout(timer); seq++ })

const canSubmit = computed(() =>
  !submitting.value && !result.value && !previewBusy.value && !!preview.value && preview.value.allowed && !amountInvalid.value)

async function submit() {
  if (!canSubmit.value) return
  const b = body()
  const json = JSON.stringify(b)
  if (json !== lastJson) { key = newIdempotencyKey(); lastJson = json }
  submitting.value = true
  submitError.value = ''
  networkUncertain.value = false
  try {
    const r = await eetApi.createCorrection(props.record.record_id, b, key)
    result.value = r
    emit('done', r)
  } catch (e) {
    if (apiErrorCode(e) === 'idempotency_conflict') { key = newIdempotencyKey(); lastJson = '' }
    const noResponse = !(e as { response?: unknown })?.response
    networkUncertain.value = noResponse
    submitError.value = noResponse ? t('eet.pay.network_uncertain') : apiErrorMessage(e, t('eet.correction.submit_failed'))
  } finally {
    submitting.value = false
  }
}

const currency = computed(() => props.record.currency || 'CZK')
</script>

<template>
  <Modal :title="t('eet.correction.title')" width-class="max-w-xl" @close="emit('close')">
    <div v-if="result" class="space-y-3" data-test="eet-correction-result">
      <div class="rounded-md border border-success-600/30 bg-success-50 px-3 py-2 text-sm text-success-700">{{ t('eet.correction.saved') }}</div>
      <EetPaymentStates :event="result" />
      <p class="rounded-md bg-neutral-50 px-3 py-2 text-xs text-neutral-700">{{ t('eet.correction.no_money_back') }}</p>
    </div>

    <form v-else class="space-y-3" @submit.prevent="submit">
      <p class="text-sm text-neutral-600">
        {{ t('eet.correction.intro', { number: record.sequence_number ?? '', amount: formatMoney(Number(record.total_amount), currency) }) }}
      </p>
      <div class="grid gap-3 sm:grid-cols-2">
        <label :class="formLabel">
          <span>{{ t('eet.correction.amount') }} ({{ currency }})</span>
          <input v-model="form.amount" type="text" inputmode="decimal" autocomplete="off" :placeholder="preview?.remaining ?? ''"
            :class="[formInput, amountInvalid ? 'border-danger-500' : '']" data-test="eet-correction-amount" />
          <span v-if="amountInvalid" class="text-xs text-danger-600">{{ t('eet.pay.amount_invalid') }}</span>
          <span v-else class="text-xs text-neutral-500">{{ t('eet.correction.amount_hint') }}</span>
        </label>
        <label :class="formLabel">
          <span>{{ t('eet.correction.reason') }}</span>
          <select v-model="form.reason" :class="formInput" data-test="eet-correction-reason">
            <option v-for="r in REASONS" :key="r" :value="r">{{ t(`eet.correction.reasons.${r}`) }}</option>
          </select>
        </label>
      </div>
      <label :class="formLabel">
        <span>{{ t('eet.pay.note') }}</span>
        <input v-model="form.note" type="text" maxlength="255" :class="formInput" />
      </label>

      <div v-if="preview" class="space-y-1 rounded-md border px-3 py-2 text-sm"
        :class="preview.allowed ? 'border-neutral-200 bg-neutral-50' : 'border-danger-500/40 bg-danger-50 text-danger-700'" data-test="eet-correction-preview">
        <div class="flex flex-wrap justify-between gap-2"><span>{{ t('eet.correction.proposed') }}</span><strong>{{ formatMoney(Number(preview.proposed.total_amount), currency) }}</strong></div>
        <div class="flex flex-wrap justify-between gap-2"><span>{{ t('eet.correction.already_corrected') }}</span><span>{{ formatMoney(Number(preview.corrected_total), currency) }}</span></div>
        <div class="flex flex-wrap justify-between gap-2"><span>{{ t('eet.correction.remaining') }}</span><span>{{ formatMoney(Number(preview.remaining), currency) }}</span></div>
        <p v-if="!preview.allowed" class="text-xs">{{ t('eet.correction.not_allowed') }}</p>
        <p v-if="preview.accounting" class="text-xs text-neutral-600">{{ preview.accounting }}</p>
      </div>
      <p v-else-if="previewError" class="rounded-md border border-danger-500/40 bg-danger-50 px-3 py-2 text-sm text-danger-700">{{ previewError }}</p>

      <p v-if="submitError" class="rounded-md border px-3 py-2 text-sm"
        :class="networkUncertain ? 'border-warning-500/40 bg-warning-50 text-warning-700' : 'border-danger-500/40 bg-danger-50 text-danger-700'"
        data-test="eet-correction-error">{{ submitError }}</p>
    </form>

    <template #footer>
      <div class="flex flex-wrap justify-end gap-2">
        <button type="button" :class="btnOutline('neutral')" class="whitespace-nowrap" @click="emit('close')">
          {{ result ? t('common.close') : t('common.cancel') }}
        </button>
        <button v-if="!result" type="button" :class="btnFilled('danger')" class="whitespace-nowrap" :disabled="!canSubmit" data-test="eet-correction-submit" @click="submit">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.uturn" /></svg>
          {{ submitting ? t('eet.pay.submitting') : networkUncertain ? t('eet.pay.submit_retry') : t('eet.correction.submit') }}
        </button>
      </div>
    </template>
  </Modal>
</template>
