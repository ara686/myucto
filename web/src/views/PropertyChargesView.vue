<script setup lang="ts">
import { computed, onBeforeUnmount, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useSupplierStore } from '@/stores/supplier'
import { propertyRegistryApi, type PropertyObject, type HouseState } from '@/api/propertyRegistry'
import { propertyChargesApi, chargeRounding, type ChargeComponent, type ChargeRequest, type ChargeRegistry, type ChargePreviewResponse, type ChargeVersion } from '@/api/propertyCharges'
import PropertyButton from '@/components/property/PropertyButton.vue'
import PropertyInstantInput from '@/components/property/PropertyInstantInput.vue'
import ChargeRateEditor from '@/components/property/charges/ChargeRateEditor.vue'
import ChargePreviewPanel from '@/components/property/charges/ChargePreviewPanel.vue'
import { propertyError } from '@/components/property/propertyError'
import { propertyInput as input, propertyTextarea as textarea } from '@/components/property/propertyFormStyles'
import { propertyNow } from '@/components/property/propertyInstant'
const { t } = useI18n()
const store = useSupplierStore()
const supplier = computed(() => store.currentSupplierId)
const asOf = ref(propertyNow())
const houseId = ref(0)
const houses = ref<PropertyObject[]>([])
const components = ref<ChargeComponent[]>([])
const versions = ref<ChargeVersion[]>([])
const registry = ref<ChargeRegistry | null>(null)
const capabilities = reactive({ manage: false, confirm: false })
const draft = ref<ChargeRequest | null>(null)
const preview = ref<ChargePreviewResponse | null>(null)
const historical = ref<ChargeVersion | null>(null)
const error = ref('')
const busy = ref(false)
const loading = ref(false)
const componentForm = ref(false)
const component = reactive({ code: '', name: '', kind: '', service_id: null as number | null, account_code: '', effective_at: '', idempotency_key: '' })
let epoch = 0
let detailEpoch = 0
let token = ''
function invalidate() { preview.value = null; token = '' }
function reset() { draft.value = null; preview.value = null; historical.value = null; registry.value = null; versions.value = []; componentForm.value = false; busy.value = false; detailEpoch++ }
async function load() {
  const id = supplier.value; const ticket = ++epoch; reset(); houses.value = []; components.value = []; capabilities.manage = capabilities.confirm = false; error.value = ''; houseId.value = 0
  if (!id) return
  loading.value = true
  try {
    const [h, c] = await Promise.all([propertyRegistryApi.list(id, 'house', asOf.value), propertyChargesApi.components(id)])
    if (ticket !== epoch || supplier.value !== id) return
    houses.value = h; components.value = c.items; Object.assign(capabilities, c.capabilities)
  } catch (e) { if (ticket === epoch) error.value = propertyError(e, t) } finally { if (ticket === epoch) loading.value = false }
}
async function loadHouse() {
  const id = supplier.value; const house = houseId.value; const ticket = ++detailEpoch; draft.value = null; preview.value = null; historical.value = null; registry.value = null; versions.value = []; error.value = ''
  if (!id || !house) return
  try {
    const history = await propertyChargesApi.versions(id, house, asOf.value)
    if (ticket !== detailEpoch || supplier.value !== id) return
    versions.value = history.items; historical.value = history.selected
    try { const context = await propertyChargesApi.context(id, house, asOf.value); if (ticket === detailEpoch && supplier.value === id) registry.value = context } catch (e) { if (ticket === detailEpoch) error.value = propertyError(e, t) }
  } catch (e) { if (ticket === detailEpoch) error.value = propertyError(e, t) }
}
watch(supplier, () => { busy.value = false; void load() }, { immediate: true })
watch(asOf, () => { epoch++; reset(); houses.value = []; houseId.value = 0; loading.value = false })
watch(houseId, () => void loadHouse())
watch(draft, invalidate, { deep: true })
watch(() => draft.value?.effective_from, async at => {
  if (!at || !supplier.value || !draft.value) return
  const id = supplier.value; const house = draft.value.house_id; const ticket = ++detailEpoch
  registry.value = null
  try { const value = await propertyChargesApi.context(id, house, at); if (ticket === detailEpoch && supplier.value === id) registry.value = value }
  catch (e) { if (ticket === detailEpoch) error.value = propertyError(e, t) }
})
function beginVersion() {
  if (!registry.value) return
  historical.value = null
  draft.value = { house_id: houseId.value, expected_revision: versions.value[0]?.revision_no ?? 0, effective_from: asOf.value, effective_to: null, basis_at: asOf.value, basis_policy: 'fixed_registry_snapshot', period_policy: 'no_automatic_proration', frequency: '', decision_ref: '', decided_at: '', reason: '', rates: [] }
}
function addRate() { draft.value?.rates.push({ component_id: 0, unit_ids: [], method: 'unit', mode: 'rate_per_basis', amount: '', rounding: chargeRounding, legal_basis: '', scope_ref: '', correction: false, correction_reason: '' }) }
async function makePreview() {
  const id = supplier.value; if (!id || !draft.value || busy.value) return
  const request = JSON.parse(JSON.stringify(draft.value)) as ChargeRequest; const ticket = epoch; const detailTicket = detailEpoch; const signature = JSON.stringify(draft.value)
  busy.value = true; error.value = ''; preview.value = null
  try {
    const result = await propertyChargesApi.preview(id, request)
    if (ticket === epoch && detailTicket === detailEpoch && supplier.value === id && JSON.stringify(draft.value) === signature) { preview.value = result; token = crypto.randomUUID() }
  } catch (e) { if (ticket === epoch && detailTicket === detailEpoch) error.value = propertyError(e, t) } finally { if (ticket === epoch) busy.value = false }
}
async function saveVersion() {
  const id = supplier.value; const p = preview.value; if (!id || !p || !token || busy.value || !capabilities.confirm) return
  const ticket = epoch; const detailTicket = detailEpoch; busy.value = true; error.value = ''
  try { await propertyChargesApi.save(id, p.preview.request, p.preview_hash, token); if (ticket === epoch && detailTicket === detailEpoch && supplier.value === id) await loadHouse() }
  catch (e) { if (ticket === epoch && detailTicket === detailEpoch) error.value = propertyError(e, t) } finally { if (ticket === epoch) busy.value = false }
}
function beginComponent() { Object.assign(component, { code: '', name: '', kind: '', service_id: null, account_code: '', effective_at: asOf.value, idempotency_key: crypto.randomUUID() }); componentForm.value = true }
async function saveComponent() {
  const id = supplier.value; if (!id || busy.value) return
  const ticket = epoch; busy.value = true; error.value = ''
  try { await propertyChargesApi.createComponent(id, { ...component, service_id: component.kind === 'service_advance' ? component.service_id : null }); if (ticket === epoch && supplier.value === id) { const c = await propertyChargesApi.components(id); if (ticket === epoch) { components.value = c.items; componentForm.value = false } } }
  catch (e) { if (ticket === epoch) error.value = propertyError(e, t) } finally { if (ticket === epoch) busy.value = false }
}
function houseName(h: PropertyObject) { return (h.revision?.state as HouseState | undefined)?.name ?? h.code }
onBeforeUnmount(() => { epoch++; detailEpoch++ })
</script>
<template>
  <div class="space-y-5">
    <div><h1 class="text-2xl font-semibold">{{ t('propertyCharges.title') }}</h1><p class="text-sm text-neutral-500 mt-0.5">{{ t('propertyCharges.introduction') }}</p></div>
    <div class="flex flex-wrap gap-3 items-end"><div><PropertyInstantInput :label="t('propertyCharges.asOf')" v-model="asOf" /></div><PropertyButton icon="eye" variant="primary" filled :disabled="loading" @click="load">{{ t('propertyCharges.load') }}</PropertyButton><label class="min-w-48 flex min-w-0 flex-col gap-1 text-sm">{{ t('propertyCharges.house') }}<select v-model.number="houseId" data-house :class="input"><option :value="0">{{ t('propertyCharges.choose') }}</option><option v-for="h in houses" :key="h.id" :value="h.id">{{ houseName(h) }}</option></select></label></div>
    <p v-if="error" role="alert" class="rounded bg-danger-50 text-danger-600 p-3">{{ error }}</p>
    <div class="flex flex-wrap gap-2"><PropertyButton v-if="capabilities.manage && registry" icon="plus" @click="beginComponent">{{ t('propertyCharges.newComponent') }}</PropertyButton><PropertyButton v-if="capabilities.manage && registry" icon="plus" variant="primary" @click="beginVersion">{{ t('propertyCharges.newVersion') }}</PropertyButton></div>
    <form v-if="componentForm" class="space-y-3 rounded-lg border border-neutral-200 p-4" @submit.prevent="saveComponent">
      <div class="grid gap-3 sm:grid-cols-3"><label class="flex min-w-0 flex-col gap-1 text-sm">{{ t('propertyCharges.code') }}<input v-model="component.code" :class="input" required /></label><label class="flex min-w-0 flex-col gap-1 text-sm">{{ t('propertyCharges.name') }}<input v-model="component.name" :class="input" required /></label><label class="flex min-w-0 flex-col gap-1 text-sm">{{ t('propertyCharges.kind') }}<select v-model="component.kind" :class="input" required><option value="">{{ t('propertyCharges.choose') }}</option><option v-for="kind in ['long_term_advance', 'admin_advance', 'service_advance', 'contribution', 'other']" :key="kind" :value="kind">{{ t(`propertyCharges.kinds.${kind}`) }}</option></select></label></div>
      <div><PropertyInstantInput :label="t('propertyCharges.effectiveFrom')" v-model="component.effective_at" /></div>
      <label v-if="component.kind === 'service_advance'" class="flex min-w-0 flex-col gap-1 text-sm">{{ t('propertyCharges.service') }}<select v-model.number="component.service_id" :class="input"><option :value="null">{{ t('propertyCharges.choose') }}</option><option v-for="s in registry?.services ?? []" :key="s.id" :value="s.id">{{ s.name }}</option></select></label>
      <label class="flex min-w-0 flex-col gap-1 text-sm">{{ t('propertyCharges.account') }}<input v-model="component.account_code" :class="input" /></label>
      <div class="flex flex-wrap gap-2"><PropertyButton icon="check" variant="success" :disabled="busy" @click="saveComponent">{{ t('propertyCharges.saveComponent') }}</PropertyButton><PropertyButton icon="x" @click="componentForm = false">{{ t('propertyCharges.cancel') }}</PropertyButton></div>
    </form>
    <section v-if="components.length" class="rounded-lg border border-neutral-200 p-4"><h2 class="font-semibold">{{ t('propertyCharges.components') }}</h2><div v-for="c in components" :key="c.id" class="flex flex-wrap justify-between gap-2 py-1 text-sm"><span>{{ c.name }}</span><span>{{ t(`propertyCharges.kinds.${c.kind}`) }}</span></div></section>
    <section v-if="draft" class="space-y-3 rounded-lg border border-neutral-200 p-4">
      <h2 class="text-lg font-semibold">{{ t('propertyCharges.newVersion') }}</h2><p class="text-sm text-warning-600">{{ t('propertyCharges.snapshotPolicy') }}</p>
      <div class="grid gap-3 md:grid-cols-2"><div><PropertyInstantInput :label="t('propertyCharges.effectiveFrom')" :model-value="draft.effective_from" @update:model-value="draft.effective_from = $event; draft.basis_at = $event" /></div><div><PropertyInstantInput :label="t('propertyCharges.effectiveTo')" :model-value="draft.effective_to ?? ''" @update:model-value="draft.effective_to = $event || null" /></div><div><PropertyInstantInput :label="t('propertyCharges.decidedAt')" v-model="draft.decided_at" /></div><label class="flex min-w-0 flex-col gap-1 text-sm">{{ t('propertyCharges.frequency') }}<select v-model="draft.frequency" :class="input"><option value="">{{ t('propertyCharges.choose') }}</option><option v-for="f in ['monthly', 'quarterly', 'annual', 'one_off']" :key="f" :value="f">{{ t(`propertyCharges.frequencies.${f}`) }}</option></select></label></div>
      <label class="flex min-w-0 flex-col gap-1 text-sm">{{ t('propertyCharges.decision') }}<input v-model="draft.decision_ref" :class="input" /></label><label class="flex min-w-0 flex-col gap-1 text-sm">{{ t('propertyCharges.reason') }}<textarea v-model="draft.reason" :class="textarea" rows="2" /></label>
      <ChargeRateEditor v-for="(_, index) in draft.rates" :key="index" v-model="draft.rates[index]!" :components="components" :units="registry?.units ?? []" @remove="draft.rates.splice(index, 1)" />
      <div class="flex flex-wrap gap-2"><PropertyButton icon="plus" @click="addRate">{{ t('propertyCharges.addRate') }}</PropertyButton><PropertyButton icon="eye" variant="primary" filled :disabled="busy || !registry || !draft.rates.length" @click="makePreview">{{ t('propertyCharges.preview') }}</PropertyButton><PropertyButton icon="x" @click="draft = null; invalidate()">{{ t('propertyCharges.cancel') }}</PropertyButton></div>
    </section>
    <ChargePreviewPanel v-if="preview" :preview="preview.preview" />
    <div v-if="preview && capabilities.confirm" class="flex flex-wrap gap-2"><PropertyButton icon="check" variant="success" filled :disabled="busy" @click="saveVersion">{{ t('propertyCharges.confirmVersion') }}</PropertyButton></div>
    <section v-if="versions.length" class="space-y-2 rounded-lg border border-neutral-200 p-4"><h2 class="font-semibold">{{ t('propertyCharges.history') }}</h2><div class="flex flex-wrap gap-2"><PropertyButton v-for="v in versions" :key="v.id" icon="eye" @click="historical = v; draft = null; invalidate()">{{ t('propertyCharges.version', { number: v.revision_no }) }}: {{ v.request.decision_ref }}</PropertyButton></div></section>
    <ChargePreviewPanel v-if="historical && !draft" :preview="historical.preview" />
  </div>
</template>
