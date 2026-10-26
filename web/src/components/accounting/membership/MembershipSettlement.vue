<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import type { ClaimSettlement } from '@/api/nonprofitMembership'
import { formatCents } from './membershipFormat'

const props = defineProps<{ settlement: ClaimSettlement; amountCents: number }>()
const { t, locale } = useI18n()

const known = ['settled', 'partially_settled', 'unreconciled', 'ambiguous', 'reversed']
const evidences = ['no_native_pairing', 'journal_receipt_mismatch', 'pairing_not_exact_native_payment', 'journal_line_pairing_bank_or_cash', 'membership_reversal_claim']
const status = computed(() => (known.includes(props.settlement.status) ? props.settlement.status : 'ambiguous'))
const evidence = computed(() => (evidences.includes(props.settlement.evidence) ? props.settlement.evidence : 'unknown'))
const tone = computed(() => ({
  settled: 'bg-success-50 text-success-700',
  partially_settled: 'bg-warning-50 text-warning-700',
  unreconciled: 'bg-warning-50 text-warning-700',
  ambiguous: 'bg-warning-50 text-warning-700',
  reversed: 'bg-neutral-100 text-neutral-700',
}[status.value]))
const proven = computed(() => props.settlement.paid_cents !== null && props.settlement.open_cents !== null)
</script>

<template>
  <div class="space-y-1 text-sm" data-section="settlement">
    <span class="inline-flex rounded px-2 py-1" :class="tone" data-field="settlement-status">{{ t(`nonprofitMembership.settlement_${status}`) }}</span>
    <p v-if="proven" data-field="settlement-amounts">
      {{ t('nonprofitMembership.paid') }}: {{ formatCents(settlement.paid_cents!, locale) }} · {{ t('nonprofitMembership.openAmount') }}:{{ formatCents(settlement.open_cents!, locale) }}
    </p>
    <p v-else-if="status !== 'reversed'" class="text-warning-700" data-field="settlement-unknown">{{ t('nonprofitMembership.paymentUnknown') }}</p>
    <p class="text-neutral-500">{{ t(`nonprofitMembership.evidence_${evidence}`) }}</p>
  </div>
</template>
