<script setup lang="ts">
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import type { PropertyState, PropertyKind, UnitState, HouseState, ServiceState, PropertyObject, Ownership } from '@/api/propertyRegistry'
import PropertyButton from './PropertyButton.vue'
import PropertyFractionInput from './PropertyFractionInput.vue'
import { propertyInput as input } from './propertyFormStyles'
import PropertyPartyPicker from './PropertyPartyPicker.vue'
const model = defineModel<PropertyState>({ required: true })
defineProps<{ kind: PropertyKind; supplierId: number; services: PropertyObject[]; partyLabels: Record<number, string>; readonly?: boolean }>()
const emit = defineEmits<{ party: [value: { id: number; name: string }] }>()
const { t } = useI18n()
const unit = computed(() => model.value as UnitState)
const house = computed(() => model.value as HouseState)
const service = computed(() => model.value as ServiceState)
const attribute = ref('')
const scopes = ['communication', 'delivery', 'voting', 'payment_instruction', 'setoff', 'refund_instruction']
function addOwner() { unit.value.ownerships.push({ group_key: crypto.randomUUID(), kind: 'single', share: { num: '1', den: '1' }, members: [0], title_ref: '' }) }
function ownerKind(group: Ownership) { group.members = group.kind === 'marital_community' ? [group.members[0] ?? 0, group.members[1] ?? 0] : [group.members[0] ?? 0] }
function addAttribute() { const key = attribute.value.trim(); if (/^[a-zA-Z0-9_.-]{1,80}$/.test(key) && !unit.value.attributes[key]) { unit.value.attributes[key] = { num: '0', den: '1' }; attribute.value = '' } }
function serviceName(object: PropertyObject) { return (object.revision?.state as ServiceState | undefined)?.name ?? object.code }
</script>
<template>
  <fieldset :disabled="readonly" class="space-y-4 min-w-0">
    <label class="flex items-center gap-2 text-sm"><input v-model="model.active" type="checkbox">{{ t('propertyRegistry.active') }}</label>
    <template v-if="kind === 'house'">
      <div class="grid gap-3 sm:grid-cols-2">
        <label class="flex min-w-0 flex-col gap-1 text-sm">{{ t('propertyRegistry.name') }}<input v-model="house.name" :class="input" maxlength="255"></label>
        <label class="flex min-w-0 flex-col gap-1 text-sm">{{ t('propertyRegistry.shareScope') }}<input v-model="house.share_scope" :class="input" maxlength="80"></label>
        <label class="flex min-w-0 flex-col gap-1 text-sm" v-for="field in (['street', 'city', 'postal_code', 'country'] as const)" :key="field">{{ t(`propertyRegistry.address.${field}`) }}<input v-model="house.address[field]" :class="input"></label>
      </div>
      <label class="flex min-w-0 flex-col gap-1 text-sm">{{ t('propertyRegistry.declaration') }}<textarea v-model="house.declaration_ref" class="w-full rounded border border-neutral-300 bg-surface p-2" /></label>
      <label class="flex items-center gap-2 text-sm"><input v-model="house.registry_complete" type="checkbox">{{ t('propertyRegistry.declaredComplete') }}</label>
      <p class="text-sm text-neutral-500">{{ t('propertyRegistry.shareScopeHelp') }}</p>
    </template>
    <template v-else-if="kind === 'service'">
      <label class="flex min-w-0 flex-col gap-1 text-sm">{{ t('propertyRegistry.name') }}<input v-model="service.name" :class="input"></label>
      <div class="grid gap-3 sm:grid-cols-2"><label class="flex min-w-0 flex-col gap-1 text-sm">{{ t('propertyRegistry.measure') }}<select v-model="service.measure" :class="input"><option v-for="value in ['person', 'unit', 'm2', 'm3', 'kwh', 'other']" :key="value" :value="value">{{ t(`propertyRegistry.measures.${value}`) }}</option></select></label>
        <label class="flex min-w-0 flex-col gap-1 text-sm">{{ t('propertyRegistry.measureLabel') }}<input v-model="service.measure_label" :class="input"></label></div>
    </template>
    <template v-else>
      <div class="grid gap-3 sm:grid-cols-2"><label class="flex min-w-0 flex-col gap-1 text-sm">{{ t('propertyRegistry.unitNumber') }}<input v-model="unit.unit_no" :class="input"></label>
        <label class="flex min-w-0 flex-col gap-1 text-sm">{{ t('propertyRegistry.unitKind') }}<select v-model="unit.kind" :class="input"><option v-for="value in ['apartment', 'non_residential', 'garage', 'studio', 'other']" :key="value" :value="value">{{ t(`propertyRegistry.unitKinds.${value}`) }}</option></select></label></div>
      <div class="space-y-2"><h3 class="font-medium">{{ t('propertyRegistry.attributes') }}</h3>
        <div v-for="(fraction, key) in unit.attributes" :key="key" class="flex flex-wrap items-end gap-2"><PropertyFractionInput :model-value="fraction" :label="key === 'share' ? t('propertyRegistry.propertyShare') : String(key)" @update:model-value="unit.attributes[key] = $event" /><PropertyButton v-if="key !== 'share' && !readonly" icon="trash" variant="danger" @click="delete unit.attributes[key]">{{ t('propertyRegistry.remove') }}</PropertyButton></div>
        <div v-if="!readonly" class="flex flex-wrap gap-2"><input v-model="attribute" :class="input + ' max-w-xs'" :aria-label="t('propertyRegistry.attributeCode')" :placeholder="t('propertyRegistry.attributeCode')"><PropertyButton icon="plus" @click="addAttribute">{{ t('propertyRegistry.addAttribute') }}</PropertyButton></div>
      </div>
      <section class="space-y-3"><h3 class="font-medium">{{ t('propertyRegistry.ownerships') }}</h3><p class="text-sm text-neutral-500">{{ t('propertyRegistry.ownershipHelp') }}</p>
        <p v-if="!unit.ownerships.length" class="text-sm text-warning-600">{{ t('propertyRegistry.ownershipMissing') }}</p>
        <div v-for="(group, index) in unit.ownerships" :key="group.group_key" class="space-y-3 rounded-lg border border-neutral-200 p-3">
          <div class="flex flex-wrap items-end gap-3"><label class="min-w-48 flex min-w-0 flex-col gap-1 text-sm">{{ t('propertyRegistry.ownershipKind') }}<select v-model="group.kind" :class="input" @change="ownerKind(group)"><option v-for="value in ['single', 'coownership_share', 'marital_community']" :key="value" :value="value">{{ t(`propertyRegistry.ownershipKinds.${value}`) }}</option></select></label><PropertyFractionInput v-model="group.share" :label="t('propertyRegistry.ownershipShare')" /></div>
          <div v-for="(_, member) in group.members" :key="member"><label class="block text-sm">{{ t('propertyRegistry.member', { n: member + 1 }) }}</label><PropertyPartyPicker :supplier-id="supplierId" :model-value="group.members[member] || null" :selected-label="partyLabels[group.members[member] ?? 0]" @update:model-value="group.members[member] = $event ?? 0" @selected="emit('party', $event)" /></div>
          <label class="flex min-w-0 flex-col gap-1 text-sm">{{ t('propertyRegistry.titleRef') }}<input v-model="group.title_ref" :class="input"></label>
          <PropertyButton v-if="!readonly" icon="trash" variant="danger" @click="unit.ownerships.splice(index, 1)">{{ t('propertyRegistry.remove') }}</PropertyButton>
        </div>
        <PropertyButton v-if="!readonly" icon="plus" @click="addOwner">{{ t('propertyRegistry.addOwner') }}</PropertyButton>
      </section>
      <section class="space-y-3"><h3 class="font-medium">{{ t('propertyRegistry.roles') }}</h3><p class="text-sm text-neutral-500">{{ t('propertyRegistry.rolesHelp') }}</p>
        <div v-for="(role, index) in unit.roles" :key="index" class="space-y-2 rounded-lg border border-neutral-200 p-3">
          <label class="flex min-w-0 flex-col gap-1 text-sm">{{ t('propertyRegistry.role') }}<select v-model="role.role" :class="input" @change="role.scopes = []"><option v-for="value in ['payer', 'representative', 'delivery']" :key="value" :value="value">{{ t(`propertyRegistry.roleNames.${value}`) }}</option></select></label>
          <PropertyPartyPicker :supplier-id="supplierId" :model-value="role.client_id || null" :selected-label="partyLabels[role.client_id]" @update:model-value="role.client_id = $event ?? 0" @selected="emit('party', $event)" />
          <div v-if="role.role === 'representative'" class="flex flex-wrap gap-3"><label v-for="scope in scopes" :key="scope" class="flex items-center gap-1 text-sm"><input v-model="role.scopes" type="checkbox" :value="scope">{{ t(`propertyRegistry.scopes.${scope}`) }}</label></div>
          <label class="flex min-w-0 flex-col gap-1 text-sm">{{ t('propertyRegistry.titleRef') }}<input v-model="role.title_ref" :class="input"></label>
          <PropertyButton v-if="!readonly" icon="trash" variant="danger" @click="unit.roles.splice(index, 1)">{{ t('propertyRegistry.remove') }}</PropertyButton>
        </div>
        <PropertyButton v-if="!readonly" icon="plus" @click="unit.roles.push({ client_id: 0, role: 'payer', scopes: [], title_ref: '' })">{{ t('propertyRegistry.addRole') }}</PropertyButton>
      </section>
      <section class="space-y-3"><h3 class="font-medium">{{ t('propertyRegistry.identifiers') }}</h3><p class="text-sm text-neutral-500">{{ t('propertyRegistry.identifiersHelp') }}</p>
        <div v-for="(identifier, index) in unit.identifiers" :key="index" class="space-y-2 rounded-lg border border-neutral-200 p-3">
          <div class="grid gap-2 sm:grid-cols-2"><label class="flex min-w-0 flex-col gap-1 text-sm">{{ t('propertyRegistry.identifierKind') }}<select v-model="identifier.kind" :class="input"><option v-for="value in ['vs', 'ss', 'account', 'text', 'sipo_link']" :key="value" :value="value">{{ t(`propertyRegistry.identifierKinds.${value}`) }}</option></select></label><label class="flex min-w-0 flex-col gap-1 text-sm">{{ t('propertyRegistry.value') }}<input v-model="identifier.value" :class="input"></label><label class="flex min-w-0 flex-col gap-1 text-sm">{{ t('propertyRegistry.paymentScope') }}<input v-model="identifier.payment_scope" :class="input"></label><label class="flex min-w-0 flex-col gap-1 text-sm">{{ t('propertyRegistry.titleRef') }}<input v-model="identifier.title_ref" :class="input"></label></div>
          <PropertyButton v-if="!readonly" icon="trash" variant="danger" @click="unit.identifiers.splice(index, 1)">{{ t('propertyRegistry.remove') }}</PropertyButton>
        </div>
        <PropertyButton v-if="!readonly" icon="plus" @click="unit.identifiers.push({ kind: 'vs', value: '', payment_scope: 'supplier', source: 'manual', title_ref: '' })">{{ t('propertyRegistry.addIdentifier') }}</PropertyButton>
      </section>
      <section class="space-y-2"><h3 class="font-medium">{{ t('propertyRegistry.kinds.service') }}</h3><div class="flex flex-wrap gap-3"><label v-for="item in services" :key="item.id" class="flex items-center gap-1"><input v-model="unit.services" type="checkbox" :value="item.id">{{ serviceName(item) }}</label></div></section>
    </template>
  </fieldset>
</template>
