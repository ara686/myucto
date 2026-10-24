<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import Modal from '@/components/ui/Modal.vue'
import { ICONS, btnFilled, btnOutline } from '@/components/ui/buttonStyles'
import { formInput, formLabel } from '@/components/ui/formStyles'
import {
  eetApi,
  type EetCapabilityDevice,
  type EetEnvironment,
  type EetPaymentEvent,
  type EetPaymentIntent,
  type EetPaymentIntentRequest,
} from '@/api/eet'
import { apiErrorCode, apiErrorMessage } from '@/api/errors'
import { formatMoney } from '@/composables/useFormat'
import { loadEetDevices, newIdempotencyKey, rememberEetDevice, rememberedEetDevice } from '@/composables/useEetCapabilities'
import { useSupplierStore } from '@/stores/supplier'
import { useToast } from '@/composables/useToast'
import EetPaymentStates from './EetPaymentStates.vue'
import { eetIsOpen, useEetPaymentPolling } from './useEetPaymentPolling'

/**
 * Platba QR na místě: záměr + QR, potom výslovné potvrzení obsluhy „Zákazník zadal
 * platbu". Zobrazení QR není inkaso ani tržba; přijetí peněz bankou ověří až
 * bankovní párování, proto se EET a peníze ukazují jako dva oddělené stavy.
 */
const props = defineProps<{
  invoiceId: number
  currency: string
  remaining: number | string
  defaultDeviceId?: number | null
  environment?: EetEnvironment
}>()

const emit = defineEmits<{
  (e: 'close'): void
  (e: 'done', event: EetPaymentEvent): void
}>()

const foreign = computed(() => props.currency.toUpperCase() !== 'CZK')

type Ctx = 'in_person' | 'on_premises' | 'in_vehicle'
const CONTEXTS: Ctx[] = ['in_person', 'on_premises', 'in_vehicle']

const { t } = useI18n()
const toast = useToast()
const supplierStore = useSupplierStore()
const supplierId = computed(() => supplierStore.currentSupplier?.id ?? null)

const form = reactive({
  amount: (() => {
    const n = Number(String(props.remaining).replace(',', '.'))
    return Number.isFinite(n) && n > 0 ? n.toFixed(2) : ''
  })(),
  context: 'in_person' as Ctx,
  deviceId: null as number | null,
})
const devices = ref<EetCapabilityDevice[]>([])
onMounted(async () => {
  devices.value = await loadEetDevices()
  const ids = new Set(devices.value.map(d => d.id))
  const remembered = rememberedEetDevice(supplierId.value)
  const fallback = props.defaultDeviceId ?? null
  form.deviceId = remembered && ids.has(remembered) ? remembered
    : fallback && (ids.size === 0 || ids.has(fallback)) ? fallback
      : devices.value[0]?.id ?? null
})

const normalizedAmount = computed(() => {
  const raw = form.amount.replace(/\s/g, '').replace(',', '.')
  if (!/^\d+(\.\d{1,2})?$/.test(raw)) return null
  return Number(raw) > 0 ? Number(raw).toFixed(2) : null
})
const amountInvalid = computed(() => form.amount.trim() !== '' && normalizedAmount.value === null)

const intent = ref<EetPaymentIntent | null>(null)
const confirmed = ref<EetPaymentEvent | null>(null)
const busy = ref<'' | 'create' | 'confirm' | 'cancel'>('')
const error = ref('')
const networkUncertain = ref(false)
let createKey = newIdempotencyKey()
let createBodyJson = ''
// Jeden klíč na potvrzení; po ztracené odpovědi ho opakování znovu použije.
const confirmKey = newIdempotencyKey()

const { start: startPolling, timedOut: pollTimedOut } = useEetPaymentPolling((fresh) => {
  confirmed.value = fresh
  emit('done', fresh)
})

function failure(e: unknown, fallback: string) {
  const noResponse = !(e as { response?: unknown })?.response
  networkUncertain.value = noResponse
  error.value = noResponse ? t('eet.pay.network_uncertain') : apiErrorMessage(e, fallback)
}

async function createIntent() {
  if (!normalizedAmount.value || busy.value) return
  const body: EetPaymentIntentRequest = {
    invoice_id: props.invoiceId,
    amount: normalizedAmount.value,
    contact_context: form.context,
  }
  if (form.deviceId) body.device_id = form.deviceId
  const json = JSON.stringify(body)
  if (json !== createBodyJson) { createKey = newIdempotencyKey(); createBodyJson = json }
  busy.value = 'create'
  error.value = ''
  networkUncertain.value = false
  try {
    intent.value = await eetApi.createPaymentIntent(body, createKey)
    if (body.device_id) rememberEetDevice(supplierId.value, body.device_id)
  } catch (e) {
    if (apiErrorCode(e) === 'idempotency_conflict') { createKey = newIdempotencyKey(); createBodyJson = '' }
    failure(e, t('eet.qr.create_failed'))
  } finally {
    busy.value = ''
  }
}

async function confirmIntent() {
  if (!intent.value || busy.value || confirmed.value) return
  busy.value = 'confirm'
  error.value = ''
  networkUncertain.value = false
  try {
    const ev = await eetApi.confirmPaymentIntent(intent.value.payment_event_id, confirmKey)
    confirmed.value = ev
    emit('done', ev)
    startPolling(ev)
  } catch (e) {
    failure(e, t('eet.qr.confirm_failed'))
  } finally {
    busy.value = ''
  }
}

async function cancelIntent() {
  if (!intent.value || busy.value || confirmed.value) { emit('close'); return }
  busy.value = 'cancel'
  error.value = ''
  try {
    await eetApi.cancelPaymentIntent(intent.value.payment_event_id)
    toast.success(t('eet.qr.cancelled'))
    emit('close')
  } catch (e) {
    failure(e, t('eet.qr.cancel_failed'))
  } finally {
    busy.value = ''
  }
}

async function copySpd() {
  const spd = intent.value?.qr?.spd
  if (!spd) return
  try {
    await navigator.clipboard.writeText(spd)
    toast.success(t('eet.qr.copied'))
  } catch {
    toast.error(t('eet.qr.copy_failed'))
  }
}

const eetUnconfirmed = computed(() => eetIsOpen(confirmed.value))
</script>

<template>
  <Modal :title="t('eet.qr.title')" width-class="max-w-lg" @close="emit('close')">
    <div v-if="environment === 'playground'" class="mb-3 rounded-md border border-warning-500/40 bg-warning-50 px-3 py-2 text-xs text-warning-700">
      {{ t('eet.pay.playground') }}
    </div>

    <!-- 3. Potvrzeno obsluhou -->
    <div v-if="confirmed" class="space-y-3" data-test="eet-qr-confirmed">
      <div class="rounded-md border border-success-600/30 bg-success-50 px-3 py-2 text-sm text-success-700">{{ t('eet.qr.confirmed') }}</div>
      <EetPaymentStates :event="confirmed" />
      <p class="rounded-md bg-neutral-50 px-3 py-2 text-xs text-neutral-700" data-test="eet-qr-bank-unverified">
        {{ t('eet.qr.bank_unverified') }}
      </p>
      <p v-if="eetUnconfirmed" class="rounded-md border border-warning-500/40 bg-warning-50 px-3 py-2 text-sm text-warning-700">
        {{ t('eet.pay.eet_stored_waiting') }}
        <span v-if="pollTimedOut" class="block text-xs mt-1">{{ t('eet.pay.poll_stopped') }}</span>
      </p>
    </div>

    <!-- 2. QR zobrazen -->
    <div v-else-if="intent" class="space-y-3" data-test="eet-qr-shown">
      <div class="flex flex-col items-center gap-2">
        <img v-if="intent.qr?.image_data_uri" :src="intent.qr.image_data_uri" :alt="t('eet.qr.alt')"
          class="h-56 w-56 rounded-md border border-neutral-200 bg-white p-2" />
        <p v-else class="text-sm text-warning-700">{{ t('eet.qr.no_image') }}</p>
        <p class="text-xl font-semibold">{{ formatMoney(Number(intent.amount), intent.currency) }}</p>
        <button v-if="intent.qr?.spd" type="button" class="inline-flex items-center gap-1 text-xs text-primary-700 hover:underline cursor-pointer" @click="copySpd">
          <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.copy" /></svg>
          {{ t('eet.qr.copy_spd') }}
        </button>
      </div>
      <p class="rounded-md bg-neutral-50 px-3 py-2 text-xs text-neutral-700">{{ t('eet.qr.not_paid_yet') }}</p>
    </div>

    <!-- Česká QR platba (SPAYD) je jen v Kč; fakturu v cizí měně přijměte hotově nebo kartou. -->
    <p v-else-if="foreign" class="rounded-md border border-warning-500/40 bg-warning-50 px-3 py-2 text-sm text-warning-700" data-test="eet-qr-foreign">
      {{ t('eet.qr.czk_only', { currency }) }}
    </p>

    <!-- 1. Příprava -->
    <form v-else class="space-y-3" @submit.prevent="createIntent">
      <p class="text-sm text-neutral-600">{{ t('eet.qr.intro') }}</p>
      <label :class="formLabel">
        <span>{{ t('eet.pay.amount') }} ({{ currency }})</span>
        <input v-model="form.amount" type="text" inputmode="decimal" autocomplete="off"
          :class="[formInput, amountInvalid ? 'border-danger-500' : '']" data-test="eet-qr-amount" />
        <span v-if="amountInvalid" class="text-xs text-danger-600">{{ t('eet.pay.amount_invalid') }}</span>
        <span v-else class="text-xs text-neutral-500">{{ t('eet.pay.remaining', { amount: formatMoney(Number(remaining), currency) }) }}</span>
      </label>
      <div class="grid gap-3 sm:grid-cols-2">
        <label :class="formLabel">
          <span>{{ t('eet.pay.context') }}</span>
          <select v-model="form.context" :class="formInput">
            <option v-for="c in CONTEXTS" :key="c" :value="c">{{ t(`eet.pay.contexts.${c}`) }}</option>
          </select>
        </label>
        <label :class="formLabel">
          <span>{{ t('eet.pay.device') }}</span>
          <select v-if="devices.length > 0" v-model="form.deviceId" :class="formInput">
            <option v-for="d in devices" :key="d.id" :value="d.id">{{ d.code }} - {{ d.unit_name }}</option>
          </select>
          <span v-else class="flex h-9 items-center text-sm text-neutral-600">{{ t('eet.pay.device_default') }}</span>
        </label>
      </div>
    </form>

    <p v-if="error" class="mt-3 rounded-md border px-3 py-2 text-sm"
      :class="networkUncertain ? 'border-warning-500/40 bg-warning-50 text-warning-700' : 'border-danger-500/40 bg-danger-50 text-danger-700'"
      data-test="eet-qr-error">{{ error }}</p>

    <template #footer>
      <div class="flex flex-wrap justify-end gap-2">
        <template v-if="confirmed">
          <button type="button" :class="btnOutline('neutral')" class="whitespace-nowrap" @click="emit('close')">{{ t('common.close') }}</button>
        </template>
        <template v-else-if="intent">
          <button type="button" :class="btnOutline('danger')" class="whitespace-nowrap" :disabled="busy !== ''" data-test="eet-qr-cancel" @click="cancelIntent">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.x" /></svg>
            {{ t('eet.qr.cancel') }}
          </button>
          <button type="button" :class="btnFilled('success')" class="whitespace-nowrap" :disabled="busy !== ''" data-test="eet-qr-confirm" @click="confirmIntent">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.checkCircle" /></svg>
            {{ busy === 'confirm' ? t('eet.pay.submitting') : networkUncertain ? t('eet.qr.confirm_retry') : t('eet.qr.confirm') }}
          </button>
        </template>
        <template v-else>
          <button type="button" :class="btnOutline('neutral')" class="whitespace-nowrap" @click="emit('close')">{{ t('common.cancel') }}</button>
          <button v-if="!foreign" type="button" :class="btnFilled('primary')" class="whitespace-nowrap" :disabled="!normalizedAmount || busy !== ''" data-test="eet-qr-create" @click="createIntent">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.qr" /></svg>
            {{ busy === 'create' ? t('eet.pay.submitting') : t('eet.qr.show') }}
          </button>
        </template>
      </div>
    </template>
  </Modal>
</template>
