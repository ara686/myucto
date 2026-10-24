<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import ActionBar, { type ActionItem } from '@/components/ui/ActionBar.vue'
import DateInput from '@/components/ui/DateInput.vue'
import { formDateInput as dateInput, formInput as field, formLabel, formTextarea as textarea } from '@/components/ui/formStyles'
import type { AccountingPeriod } from '@/api/accounting'
import { membershipApi, type ClaimRow, type MembershipConfirmBody, type MembershipPreview } from '@/api/nonprofitMembership'
import { appIsoDate } from '@/utils/date'
import { formatCents, isIsoDate } from './membershipFormat'
import MembershipConfirmBar from './MembershipConfirmBar.vue'
import MembershipSettlement from './MembershipSettlement.vue'
import { useGuardedCommit } from './useGuardedCommit'

const props = defineProps<{
  supplier: number; canManage: boolean
  member: { id: number; member_number: string; label: string }
  claim: ClaimRow; periods: AccountingPeriod[]
}>()
const emit = defineEmits<{ done: []; close: []; lock: [locked: boolean] }>()
const { t, locale } = useI18n()

const inputForm = ref<HTMLFormElement | null>(null)

const form = reactive({ periodId: 0, accountingDate: '', reason: '' })
const commit = useGuardedCommit<MembershipConfirmBody, MembershipPreview>({
  supplier: () => props.supplier,
  canManage: () => props.canManage,
  send: (supplier, body) => membershipApi.confirm(supplier, body),
  onDone: () => emit('done'),
})

const openPeriods = computed(() => props.periods.filter(p => p.status === 'open'))
const chosenPeriod = computed(() => props.periods.find(p => p.id === form.periodId))
const active = computed(() => props.claim.reversal_claim_id === null)

const problem = computed(() => {
  const period = chosenPeriod.value
  if (!active.value) return t('nonprofitMembership.reverseNotActive')
  if (!period || period.status !== 'open') return t('nonprofitMembership.issueNeedPeriod')
  if (!isIsoDate(form.accountingDate)) return t('nonprofitMembership.dateInvalid')
  if (form.accountingDate < period.starts_on || form.accountingDate > period.ends_on) return t('nonprofitMembership.issueDateOutsidePeriod')
  if (!form.reason.trim() || form.reason.trim().length > 2000) return t('nonprofitMembership.reverseReasonRequired')
  return ''
})

async function runPreview() {
  if (!props.canManage || problem.value || !inputForm.value?.reportValidity()) return
  const op = commit.begin()
  if (!op) return
  const request = { case_id: props.claim.case_id, accounting_date: form.accountingDate, reason: form.reason.trim() }
  try {
    const result = await membershipApi.preview(op.supplier, { operation: 'reverse', request })
    if (!op.current()) return
    commit.arm(result, { operation: 'reverse', request, expected_case_version: result.expected_case_version, preview_hash: result.preview_hash, idempotency_key: crypto.randomUUID() }, op.supplier)
  } catch (e) {
    if (op.current()) commit.fail(e)
  } finally {
    op.end()
  }
}

function clampDate() {
  const period = chosenPeriod.value
  if (!period) return
  if (!isIsoDate(form.accountingDate) || form.accountingDate < period.starts_on || form.accountingDate > period.ends_on) {
    const today = appIsoDate()
    form.accountingDate = today >= period.starts_on && today <= period.ends_on ? today : period.starts_on
  }
}

watch(() => form.periodId, clampDate)
watch([form, () => props.claim.claim_id], () => commit.touch(), { deep: true, flush: 'sync' })
watch(() => props.supplier, () => commit.reset())
watch(commit.locked, locked => emit('lock', locked))
onMounted(() => {
  const today = appIsoDate()
  form.periodId = (openPeriods.value.find(p => p.starts_on <= today && p.ends_on >= today) ?? openPeriods.value[0])?.id ?? 0
})

const frozenRequest = computed(() => {
  const payload = commit.frozen.value?.payload
  return payload && payload.operation === 'reverse' ? payload.request : null
})
const accounts = computed(() => commit.review.value?.original?.proof?.accounts)

const actions = computed<ActionItem[]>(() => [
  { key: 'reverse-preview', label: t('nonprofitMembership.preview'), icon: 'eye', tier: 'primary', variant: 'danger', disabled: !props.canManage || !!problem.value || commit.busy.value || commit.locked.value, disabledReason: problem.value || undefined, run: () => { void runPreview() } },
  { key: 'reverse-close', label: t('nonprofitMembership.close'), icon: 'x', disabled: commit.locked.value, run: () => emit('close') },
])
</script>

<template>
  <section class="space-y-4 rounded-lg border bg-surface p-4" data-section="reverse-flow">
    <h3 class="font-semibold">{{ t('nonprofitMembership.reverseTitle') }} · {{ member.member_number }} · {{ member.label }}</h3>
    <p class="text-sm text-neutral-500">{{ t('nonprofitMembership.reverseHelp') }}</p>
    <dl class="grid gap-3 sm:grid-cols-4" data-section="reverse-claim">
      <div><dt class="text-sm text-neutral-500">{{ t('nonprofitMembership.titleKey') }}</dt><dd>{{ claim.title_key }}</dd></div>
      <div><dt class="text-sm text-neutral-500">{{ t('nonprofitMembership.periodFrom') }} - {{ t('nonprofitMembership.periodTo') }}</dt><dd>{{ claim.period_from }} - {{ claim.period_to }}</dd></div>
      <div><dt class="text-sm text-neutral-500">{{ t('nonprofitMembership.amount') }}</dt><dd>{{ formatCents(claim.amount_cents, locale) }}</dd></div>
      <div><dt class="text-sm text-neutral-500">{{ t('nonprofitMembership.accountingDate') }}</dt><dd>{{ claim.accounting_date }}</dd></div>
    </dl>
    <MembershipSettlement :settlement="claim.settlement" :amount-cents="claim.amount_cents" />
    <p v-if="commit.error.value" role="alert" class="rounded border border-danger-300 p-3 text-sm" data-section="reverse-error">{{ commit.error.value }}</p>
    <form ref="inputForm" @submit.prevent="runPreview" @input="commit.touch">
    <fieldset :disabled="commit.locked.value" class="grid gap-3 sm:grid-cols-2">
      <label :class="formLabel">{{ t('nonprofitMembership.accountingPeriod') }}
        <select v-model.number="form.periodId" :class="field" data-field="reverse-period">
          <option :value="0">{{ t('nonprofitMembership.choose') }}</option>
          <option v-for="p in openPeriods" :key="p.id" :value="p.id">{{ p.starts_on }} - {{ p.ends_on }}</option>
        </select>
      </label>
      <label :class="formLabel">{{ t('nonprofitMembership.reverseDate') }}<DateInput :class="dateInput" v-model="form.accountingDate" required :min="chosenPeriod?.starts_on" :max="chosenPeriod?.ends_on" data-field="reverse-date" /></label>
      <label :class="[formLabel, 'sm:col-span-2']">{{ t('nonprofitMembership.reverseReason') }}<textarea v-model="form.reason" :class="textarea" maxlength="2000" data-field="reverse-reason" /></label>
    </fieldset>
    </form>
    <ActionBar v-if="canManage && !commit.review.value" :actions="actions" />
    <div v-if="commit.review.value && frozenRequest" class="space-y-3 rounded border p-3" data-section="reverse-preview">
      <h4 class="font-semibold">{{ t('nonprofitMembership.reviewTitle') }}</h4>
      <p class="text-sm text-neutral-500">{{ t('nonprofitMembership.advisory') }}</p>
      <dl class="grid gap-3 sm:grid-cols-3">
        <div><dt class="text-sm text-neutral-500">{{ t('nonprofitMembership.reverseDate') }}</dt><dd>{{ frozenRequest.accounting_date }}</dd></div>
        <div v-if="commit.review.value.target_period"><dt class="text-sm text-neutral-500">{{ t('nonprofitMembership.accountingPeriod') }}</dt><dd>{{ commit.review.value.target_period.starts_on }} - {{ commit.review.value.target_period.ends_on }}</dd></div>
        <div><dt class="text-sm text-neutral-500">{{ t('nonprofitMembership.caseVersion') }}</dt><dd>{{ t('nonprofitMembership.caseExisting', { version: commit.review.value.expected_case_version }) }}</dd></div>
      </dl>
      <table v-if="accounts?.receivable && accounts?.revenue" class="w-full text-sm" data-section="reverse-plan">
        <thead><tr class="text-left text-neutral-500"><th>{{ t('nonprofitMembership.side') }}</th><th>{{ t('nonprofitMembership.account') }}</th><th class="text-right">{{ t('nonprofitMembership.amount') }}</th></tr></thead>
        <tbody>
          <tr><td>{{ t('nonprofitMembership.debit') }}</td><td>{{ accounts.revenue.account_code }} {{ accounts.revenue.name }}</td><td class="text-right">{{ formatCents(claim.amount_cents, locale) }}</td></tr>
          <tr><td>{{ t('nonprofitMembership.credit') }}</td><td>{{ accounts.receivable.account_code }} {{ accounts.receivable.name }}</td><td class="text-right">{{ formatCents(claim.amount_cents, locale) }}</td></tr>
        </tbody>
      </table>
      <p class="text-sm">{{ frozenRequest.reason }}</p>
      <MembershipConfirmBar
        v-model:acknowledged="commit.acknowledged.value"
        :busy="commit.busy.value" :uncertain="commit.uncertain.value" :can-manage="canManage"
        :ack-label="t('nonprofitMembership.reverseAck')"
        @confirm="commit.send()" @cancel="commit.touch()" @discard="commit.discard()"
      />
    </div>
  </section>
</template>
