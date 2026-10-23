<script setup lang="ts">
import { computed, onBeforeUnmount, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useSupplierStore } from '@/stores/supplier'
import { propertyRegistryApi, type PropertyKind, type PropertyObject, type PropertyState, type PropertyRevision, type ProfileResponse, type RegistrySummary, type PropertyMutation } from '@/api/propertyRegistry'
import PropertyButton from '@/components/property/PropertyButton.vue'
import PropertyInstantInput from '@/components/property/PropertyInstantInput.vue'
import PropertyStateEditor from '@/components/property/PropertyStateEditor.vue'
import PropertyImportPanel from '@/components/property/PropertyImportPanel.vue'
import { propertyError } from '@/components/property/propertyError'
import { propertyInput as inputClass } from '@/components/property/propertyFormStyles'
import { propertyNow } from '@/components/property/propertyInstant'
const { t } = useI18n()
const supplierStore = useSupplierStore()
const supplier = computed(() => supplierStore.currentSupplierId)
const asOf = ref(propertyNow())
const knownAt = ref('')
const profile = ref<ProfileResponse | null>(null)
const summary = ref<RegistrySummary | null>(null)
const objects = reactive<Record<PropertyKind, PropertyObject[]>>({ house: [], unit: [], service: [] })
const kind = ref<PropertyKind>('house')
const selected = ref<PropertyObject | null>(null)
const revision = ref<PropertyRevision | null>(null)
const editor = ref<{ id: number | null; kind: PropertyKind; input: PropertyMutation } | null>(null)
const confirmRevision = ref<PropertyRevision | null>(null)
const confirmReason = ref('')
const confirmTitle = ref('')
let confirmToken = ''
const partyLabels = reactive<Record<number, string>>({})
const busy = ref(false)
const loading = ref(false)
const error = ref('')
const showImport = ref(false)
const showProfile = ref(false)
const profileForm = reactive({ effective_from: asOf.value, effective_to: '', enabled: true, reason: '', title_ref: '' })
let profileToken = crypto.randomUUID()
let epoch = 0
let detailEpoch = 0
onBeforeUnmount(() => { epoch++; detailEpoch++ })
const canSave = computed(() => !!editor.value && !!editor.value.input.reason.trim() && !!editor.value.input.title_ref.trim() && !busy.value)
function message(e: unknown) { return propertyError(e, t) }
function objectLabel(object: PropertyObject) { const state = object.revision?.state; return state ? ('unit_no' in state ? t('propertyRegistry.unitLabel', { number: state.unit_no }) : state.name) : object.code }
function reset() { selected.value = null; revision.value = null; editor.value = null; confirmRevision.value = null; showImport.value = false; showProfile.value = false; for (const id of Object.keys(partyLabels)) delete partyLabels[Number(id)]; detailEpoch++ }
async function load() {
  const id = supplier.value; const ticket = ++epoch; reset(); profile.value = null; summary.value = null; objects.house = []; objects.unit = []; objects.service = []; error.value = ''
  if (!id) return
  loading.value = true
  try {
    const [p, houses, units, services, totals] = await Promise.all([propertyRegistryApi.profile(id, asOf.value, knownAt.value), propertyRegistryApi.list(id, 'house', asOf.value, knownAt.value), propertyRegistryApi.list(id, 'unit', asOf.value, knownAt.value), propertyRegistryApi.list(id, 'service', asOf.value, knownAt.value), propertyRegistryApi.summary(id, asOf.value, knownAt.value)])
    if (ticket !== epoch || supplier.value !== id) return
    profile.value = p; objects.house = houses; objects.unit = units; objects.service = services; summary.value = totals
  } catch (e) { if (ticket === epoch) error.value = message(e) } finally { if (ticket === epoch) loading.value = false }
}
watch(supplier, () => { busy.value = false; void load() }, { immediate: true })
watch(kind, () => { detailEpoch++; selected.value = null; revision.value = null; editor.value = null; confirmRevision.value = null })
watch([asOf, knownAt], () => { epoch++; detailEpoch++; reset(); profile.value = null; summary.value = null; objects.house = []; objects.unit = []; objects.service = []; loading.value = false })
async function detail(object: PropertyObject) {
  const id = supplier.value; if (!id) return
  const ticket = ++detailEpoch; selected.value = null; revision.value = null; editor.value = null; confirmRevision.value = null; error.value = ''
  try { const response = await propertyRegistryApi.detail(id, object.id, asOf.value, knownAt.value); if (ticket !== detailEpoch || supplier.value !== id) return; selected.value = response; revision.value = response.revision; for (const party of response.parties ?? []) partyLabels[party.id] = party.company_name || [party.first_name, party.last_name].filter(Boolean).join(' ') }
  catch (e) { if (ticket === detailEpoch) error.value = message(e) }
}
function initialState(type: PropertyKind): PropertyState {
  if (type === 'house') return { name: '', active: true, address: { street: '', city: '', postal_code: '', country: 'CZ' }, declaration_ref: '', share_scope: '', registry_complete: false }
  if (type === 'service') return { name: '', active: true, measure: 'unit', measure_label: '' }
  return { unit_no: '', kind: 'apartment', active: true, attributes: { share: { num: '', den: '' } }, ownerships: [], roles: [], identifiers: [], services: [] }
}
function edit(create = false) {
  if (!profile.value?.capabilities.manage) return
  const type = create ? kind.value : selected.value!.kind
  const source = create ? initialState(type) : revision.value?.state ?? selected.value?.revision?.state
  if (!source) return
  const state = JSON.parse(JSON.stringify(source)) as PropertyState
  editor.value = { id: create ? null : selected.value!.id, kind: type, input: { ...(create ? { code: '', ...(type === 'unit' ? { parent_id: objects.house[0]?.id ?? 0 } : {}) } : {}), expected_revision: create ? 0 : selected.value!.current_revision, effective_from: asOf.value, effective_to: null, status: 'pending', state, reason: '', title_ref: '', idempotency_key: crypto.randomUUID() } }
  confirmRevision.value = null
}
async function save() {
  const id = supplier.value; const draft = editor.value; if (!id || !draft || !canSave.value) return
  const ticket = epoch; busy.value = true; error.value = ''
  try { if (draft.id === null) await propertyRegistryApi.create(id, draft.kind, draft.input); else await propertyRegistryApi.revise(id, draft.id, draft.input); if (ticket === epoch && supplier.value === id) await load() }
  catch (e) { if (ticket === epoch) error.value = message(e) } finally { if (supplier.value === id) busy.value = false }
}
function beginConfirm(row: PropertyRevision) { confirmRevision.value = row; confirmReason.value = ''; confirmTitle.value = ''; confirmToken = crypto.randomUUID(); editor.value = null; revision.value = row }
async function confirm() {
  const id = supplier.value; if (!id || !selected.value || !confirmRevision.value || busy.value || !confirmReason.value.trim() || !confirmTitle.value.trim()) return
  const ticket = epoch; busy.value = true; error.value = ''
  try { await propertyRegistryApi.confirm(id, selected.value.id, confirmRevision.value.id, { expected_revision: selected.value.current_revision, effective_from: confirmRevision.value.effective_from, reason: confirmReason.value, title_ref: confirmTitle.value, idempotency_key: confirmToken }); if (ticket === epoch && supplier.value === id) await load() }
  catch (e) { if (ticket === epoch) error.value = message(e) } finally { if (supplier.value === id) busy.value = false }
}
function beginProfile() { showProfile.value = !showProfile.value; profileForm.effective_from = asOf.value; profileForm.effective_to = ''; profileForm.enabled = true; profileForm.reason = ''; profileForm.title_ref = ''; profileToken = crypto.randomUUID() }
async function saveProfile() {
  const id = supplier.value; if (!id || busy.value || !profileForm.reason.trim() || !profileForm.title_ref.trim()) return
  const ticket = epoch; busy.value = true; error.value = ''
  try { await propertyRegistryApi.profileSave(id, { ...profileForm, effective_to: profileForm.effective_to || null, expected_revision: Math.max(0, ...profile.value!.history.map(p => p.revision_no)), idempotency_key: profileToken }); if (ticket === epoch && supplier.value === id) await load() }
  catch (e) { if (ticket === epoch) error.value = message(e) } finally { if (supplier.value === id) busy.value = false }
}
async function exportData() {
  const id = supplier.value; if (!id || busy.value) return
  const ticket = epoch; busy.value = true
  try { const data = await propertyRegistryApi.export(id, asOf.value, knownAt.value); if (ticket !== epoch || supplier.value !== id) return; const url = URL.createObjectURL(new Blob([JSON.stringify(data, null, 2)], { type: 'application/json' })); const link = document.createElement('a'); link.href = url; link.download = 'property-registry.json'; link.click(); URL.revokeObjectURL(url) }
  catch (e) { if (ticket === epoch) error.value = message(e) } finally { if (supplier.value === id) busy.value = false }
}
</script>
<template>
  <div class="space-y-5">
    <div><h1 class="text-2xl font-semibold">{{ t('propertyRegistry.title') }}</h1><p class="text-sm text-neutral-500 mt-0.5">{{ t('propertyRegistry.intro') }}</p></div>
    <p v-if="!supplier" class="text-warning-600">{{ t('propertyRegistry.chooseSupplier') }}</p>
    <div class="flex flex-wrap items-end gap-3"><PropertyInstantInput v-model="asOf" :label="t('propertyRegistry.asOf')" /><details><summary class="text-sm">{{ t('propertyRegistry.knownAt') }}</summary><PropertyInstantInput v-model="knownAt" :label="t('propertyRegistry.knownAt')" /><PropertyButton icon="x" @click="knownAt = ''">{{ t('propertyRegistry.currentKnowledge') }}</PropertyButton></details><PropertyButton icon="cycle" :disabled="loading || busy || !supplier" @click="load">{{ t('propertyRegistry.load') }}</PropertyButton></div>
    <p v-if="error" role="alert" class="rounded border border-danger-300 p-3 text-danger-600">{{ error }}</p><p v-if="loading || busy" role="status">{{ t('propertyRegistry.working') }}</p>
    <template v-if="profile && supplier">
      <section class="space-y-3 rounded-lg border border-neutral-200 bg-surface p-4"><h2 class="text-lg font-semibold">{{ t('propertyRegistry.profile') }}</h2><p>{{ t(profile.profile?.enabled ? 'propertyRegistry.profileEnabled' : 'propertyRegistry.profileDisabled') }}</p><p class="text-sm text-neutral-500">{{ t('propertyRegistry.profileHelp') }}</p>
        <div class="flex flex-wrap gap-2"><PropertyButton v-if="profile.capabilities.confirm" icon="edit" variant="warning" :disabled="busy" @click="beginProfile">{{ t('propertyRegistry.profileChange') }}</PropertyButton><PropertyButton v-if="profile.capabilities.export" icon="download" :disabled="busy" @click="exportData">{{ t('propertyRegistry.export') }}</PropertyButton></div>
        <form v-if="showProfile" class="space-y-3 border-t border-neutral-200 pt-3" @submit.prevent="saveProfile"><PropertyInstantInput v-model="profileForm.effective_from" required :label="t('propertyRegistry.effectiveFrom')" /><details><summary>{{ t('propertyRegistry.optionalEnd') }}</summary><PropertyInstantInput v-model="profileForm.effective_to" :label="t('propertyRegistry.effectiveTo')" /><PropertyButton icon="x" @click="profileForm.effective_to = ''">{{ t('propertyRegistry.noEnd') }}</PropertyButton></details><label class="flex gap-2"><input v-model="profileForm.enabled" type="checkbox">{{ t('propertyRegistry.confirmSvj') }}</label><label class="flex min-w-0 flex-col gap-1 text-sm">{{ t('propertyRegistry.reason') }}<input v-model="profileForm.reason" :class="inputClass"></label><label class="flex min-w-0 flex-col gap-1 text-sm">{{ t('propertyRegistry.titleRef') }}<input v-model="profileForm.title_ref" :class="inputClass"></label><p class="text-sm text-neutral-500">{{ t('propertyRegistry.requiredEvidence') }}</p><PropertyButton icon="check" variant="success" filled submit :disabled="busy || !profileForm.reason.trim() || !profileForm.title_ref.trim()">{{ t('propertyRegistry.saveProfile') }}</PropertyButton></form>
        <details v-if="profile.history.length"><summary>{{ t('propertyRegistry.history') }}</summary><ul class="space-y-2 pt-2 text-sm"><li v-for="row in profile.history" :key="row.id">{{ row.effective_from }} · {{ t(row.enabled ? 'propertyRegistry.profileEnabled' : 'propertyRegistry.profileDisabled') }} · {{ row.reason }} · {{ row.title_ref }}</li></ul></details>
      </section>
      <section v-if="summary?.scopes.length" class="grid gap-3 sm:grid-cols-2"><div v-for="scope in summary.scopes" :key="scope.scope" class="rounded-lg border border-neutral-200 bg-surface p-4"><h2 class="font-medium">{{ scope.houses.map(h => h.name).join(', ') }}</h2><p>{{ t('propertyRegistry.summaryUnits', { count: scope.units }) }} · {{ scope.total_share.num }}/{{ scope.total_share.den }}</p><p :class="scope.share_complete && scope.declared_complete ? 'text-success-600' : 'text-warning-600'">{{ t(scope.share_complete && scope.declared_complete ? 'propertyRegistry.coverageComplete' : 'propertyRegistry.coverageIncomplete') }}</p><p v-if="scope.ownership_review_required" class="text-warning-600">{{ t('propertyRegistry.reviewCount', { count: scope.ownership_review_required }) }}</p></div></section>
      <div class="flex flex-wrap gap-2"><PropertyButton v-for="tab in (['house', 'unit', 'service'] as const)" :key="tab" icon="table" :filled="kind === tab" :variant="kind === tab ? 'primary' : 'neutral'" @click="kind = tab; selected = null; editor = null; revision = null; confirmRevision = null">{{ t(`propertyRegistry.kinds.${tab}`) }}</PropertyButton><PropertyButton v-if="profile.capabilities.manage" icon="plus" variant="primary" :disabled="busy" @click="edit(true)">{{ t('propertyRegistry.create') }}</PropertyButton><PropertyButton v-if="profile.capabilities.manage" icon="upload" :disabled="busy" @click="showImport = !showImport">{{ t('propertyRegistry.importTitle') }}</PropertyButton></div>
      <PropertyImportPanel v-if="showImport && profile.capabilities.manage" :key="supplier" :supplier-id="supplier" :houses="objects.house" :services="objects.service" :columns="profile.import_columns" :as-of="asOf" :can-confirm="profile.capabilities.confirm" @applied="load" />
      <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3"><article v-for="object in objects[kind]" :key="object.id" class="space-y-2 rounded-lg border border-neutral-200 bg-surface p-3"><h2 class="font-medium">{{ objectLabel(object) }}</h2><p v-if="!object.revision" class="text-sm text-warning-600">{{ t('propertyRegistry.noConfirmedState') }}</p><p v-else-if="!object.revision.state.active" class="text-sm text-neutral-500">{{ t('propertyRegistry.inactive') }}</p><p v-if="object.pending_revision_ids.length || object.ownership_review_required" class="text-sm text-warning-600">{{ t('propertyRegistry.needsReview') }}</p><PropertyButton icon="eye" @click="detail(object)">{{ t('propertyRegistry.detail') }}</PropertyButton></article></div><p v-if="!objects[kind].length" class="text-neutral-500">{{ t('propertyRegistry.empty') }}</p>
      <section v-if="selected && !editor" class="space-y-4 rounded-lg border border-neutral-200 bg-surface p-4"><h2 class="text-lg font-semibold">{{ objectLabel(selected) }}</h2><div class="flex flex-wrap gap-2"><PropertyButton v-if="revision && profile.capabilities.manage" icon="edit" variant="primary" :disabled="busy" @click="edit()">{{ t('propertyRegistry.newRevision') }}</PropertyButton><PropertyButton icon="x" @click="selected = null; revision = null; confirmRevision = null">{{ t('propertyRegistry.close') }}</PropertyButton></div>
        <details open><summary>{{ t('propertyRegistry.history') }}</summary><div class="space-y-3 pt-3"><article v-for="row in selected.history" :key="row.id" class="space-y-2 border-b border-neutral-200 pb-3"><p class="text-sm">{{ t('propertyRegistry.revision', { n: row.revision }) }} · {{ t(`propertyRegistry.statuses.${row.status}`) }} · {{ row.effective_from }}<template v-if="row.effective_to"> → {{ row.effective_to }}</template></p><p class="text-sm">{{ row.reason }} · {{ row.title_ref }}</p><p class="text-xs text-neutral-500">{{ t('propertyRegistry.recordedAt') }}: {{ row.recorded_at }}</p><div class="flex flex-wrap gap-2"><PropertyButton icon="eye" @click="revision = row; confirmRevision = null">{{ t('propertyRegistry.showState') }}</PropertyButton><PropertyButton v-if="row.status === 'pending' && !selected.history?.some(r => r.resolves_revision_id === row.id) && profile.capabilities.confirm" icon="check" variant="success" :disabled="busy" @click="beginConfirm(row)">{{ t('propertyRegistry.confirm') }}</PropertyButton></div></article></div></details>
        <PropertyStateEditor v-if="revision" :key="revision.id" :model-value="revision.state" :kind="selected.kind" :supplier-id="supplier" :services="objects.service" :party-labels="partyLabels" readonly />
        <form v-if="confirmRevision" class="space-y-3 rounded border border-success-300 p-3" @submit.prevent="confirm"><p>{{ t('propertyRegistry.confirmHelp') }}</p><label class="flex min-w-0 flex-col gap-1 text-sm">{{ t('propertyRegistry.reason') }}<input v-model="confirmReason" :class="inputClass"></label><label class="flex min-w-0 flex-col gap-1 text-sm">{{ t('propertyRegistry.titleRef') }}<input v-model="confirmTitle" :class="inputClass"></label><PropertyButton icon="check" variant="success" filled submit :disabled="busy || !confirmReason.trim() || !confirmTitle.trim()">{{ t('propertyRegistry.confirm') }}</PropertyButton></form>
      </section>
      <section v-if="editor" class="space-y-4 rounded-lg border border-primary-300 bg-surface p-4"><h2 class="text-lg font-semibold">{{ t(editor.id === null ? 'propertyRegistry.newObject' : 'propertyRegistry.newRevision') }}</h2><form class="space-y-4" @submit.prevent="save"><fieldset :disabled="busy" class="space-y-4"><label v-if="editor.id === null" class="flex min-w-0 flex-col gap-1 text-sm">{{ t('propertyRegistry.code') }}<input v-model="editor.input.code" :class="inputClass" maxlength="80"></label><label v-if="editor.id === null && editor.kind === 'unit'" class="flex min-w-0 flex-col gap-1 text-sm">{{ t('propertyRegistry.kinds.house') }}<select v-model="editor.input.parent_id" :class="inputClass"><option :value="0">{{ t('propertyRegistry.chooseHouse') }}</option><option v-for="house in objects.house" :key="house.id" :value="house.id">{{ objectLabel(house) }}</option></select></label>
        <PropertyInstantInput v-model="editor.input.effective_from" required :label="t('propertyRegistry.effectiveFrom')" /><details><summary>{{ t('propertyRegistry.optionalEnd') }}</summary><PropertyInstantInput :model-value="editor.input.effective_to ?? ''" :label="t('propertyRegistry.effectiveTo')" @update:model-value="editor.input.effective_to = $event || null" /><PropertyButton icon="x" @click="editor.input.effective_to = null">{{ t('propertyRegistry.noEnd') }}</PropertyButton></details>
        <PropertyStateEditor v-model="editor.input.state" :kind="editor.kind" :supplier-id="supplier" :services="objects.service" :party-labels="partyLabels" @party="partyLabels[$event.id] = $event.name" />
        <label class="flex min-w-0 flex-col gap-1 text-sm">{{ t('propertyRegistry.status') }}<select v-model="editor.input.status" :class="inputClass"><option value="pending">{{ t('propertyRegistry.statuses.pending') }}</option><option v-if="profile.capabilities.confirm" value="confirmed">{{ t('propertyRegistry.statuses.confirmed') }}</option></select></label><label class="flex min-w-0 flex-col gap-1 text-sm">{{ t('propertyRegistry.reason') }}<textarea v-model="editor.input.reason" class="w-full rounded border border-neutral-300 bg-surface p-2" /></label><label class="flex min-w-0 flex-col gap-1 text-sm">{{ t('propertyRegistry.titleRef') }}<input v-model="editor.input.title_ref" :class="inputClass"></label></fieldset><p class="text-sm text-neutral-500">{{ t('propertyRegistry.requiredEvidence') }}</p><div class="flex flex-wrap gap-2"><PropertyButton icon="check" variant="success" filled submit :disabled="!canSave">{{ t('propertyRegistry.saveRevision') }}</PropertyButton><PropertyButton icon="x" :disabled="busy" @click="editor = null">{{ t('propertyRegistry.cancel') }}</PropertyButton></div></form>
      </section>
    </template>
  </div>
</template>
