<script setup lang="ts">
import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { payrollImportsApi } from '@/api/payrollImports'
import { apiErrorMessage } from '@/api/errors'
import { useToast } from '@/composables/useToast'
import { btnOutline, ICONS } from '@/components/ui/buttonStyles'
import { ATTENDANCE_TEMPLATE_FILENAME } from './importHelpers'

defineProps<{ disabled?: boolean }>()

const { t } = useI18n()
const toast = useToast()
const busy = ref(false)

// Šablona XLSX pro Vzor MyÚčto; server ji skládá z týchž sloupců jako pravidla vzoru.
async function download() {
  if (busy.value) return
  busy.value = true
  try {
    await payrollImportsApi.downloadAttendanceTemplate(ATTENDANCE_TEMPLATE_FILENAME)
  } catch (err) {
    toast.error(apiErrorMessage(err, t('payroll_imports.template.failed')))
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <button type="button" data-testid="attendance-template-download" class="whitespace-nowrap" :class="btnOutline('neutral')"
    :disabled="disabled || busy" :title="t('payroll_imports.template.hint')" @click="download">
    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.download" /></svg>
    {{ busy ? t('payroll_imports.common.working') : t('payroll_imports.template.download') }}
  </button>
</template>
