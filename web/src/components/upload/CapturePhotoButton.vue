<script setup lang="ts">
import { ref, onMounted, onBeforeUnmount } from 'vue'
import { useI18n } from 'vue-i18n'
import Modal from '@/components/ui/Modal.vue'
import { btnFilled, btnOutline } from '@/components/ui/buttonStyles'
import { formatBytes } from '@/components/documents/docFormat'
import { isCaptureDevice, processPhotoFile } from '@/composables/usePhotoCapture'

/**
 * Tlačítko „Vyfotit“: otevře fotoaparát (input capture), fotku v prohlížeči zmenší
 * a zkomprimuje na JPEG (max 1200 px, ~150 kB), ukáže náhled a pak ji předá
 * stávající upload funkci stránky přes událost `captured`. Na zařízeních bez
 * dotykového ovládání se neukazuje.
 */
withDefaults(defineProps<{
  disabled?: boolean
  /** `filled` pro hlavní akci, jinak outline. */
  filled?: boolean
}>(), { disabled: false, filled: false })

const emit = defineEmits<{ captured: [file: File] }>()

const { t } = useI18n()
const input = ref<HTMLInputElement | null>(null)
const visible = ref(false)
const open = ref(false)
const busy = ref(false)
const error = ref('')
const original = ref<File | null>(null)
const result = ref<File | null>(null)
const previewUrl = ref('')

let mql: MediaQueryList | null = null
const refreshVisible = () => { visible.value = isCaptureDevice() }

onMounted(() => {
  refreshVisible()
  mql = window.matchMedia?.('(pointer: coarse)') ?? null
  mql?.addEventListener?.('change', refreshVisible)
})
onBeforeUnmount(() => {
  mql?.removeEventListener?.('change', refreshVisible)
  revoke()
})

function revoke() {
  if (previewUrl.value) URL.revokeObjectURL(previewUrl.value)
  previewUrl.value = ''
}

function reset() {
  revoke()
  open.value = false
  busy.value = false
  error.value = ''
  original.value = null
  result.value = null
}

function trigger() {
  input.value?.click()
}

async function onPick(e: Event) {
  const el = e.target as HTMLInputElement
  const file = el.files?.[0] ?? null
  el.value = ''
  if (!file) return
  revoke()
  original.value = file
  result.value = null
  error.value = ''
  busy.value = true
  open.value = true
  try {
    const out = await processPhotoFile(file)
    result.value = out
    previewUrl.value = URL.createObjectURL(out)
  } catch {
    error.value = t('capture_photo.decode_failed')
  } finally {
    busy.value = false
  }
}

function confirm() {
  if (!result.value) return
  const file = result.value
  reset()
  emit('captured', file)
}

function uploadOriginal() {
  if (!original.value) return
  const file = original.value
  reset()
  emit('captured', file)
}

function retake() {
  reset()
  trigger()
}
</script>

<template>
  <span v-if="visible" class="contents">
    <button type="button" :class="filled ? btnFilled('primary') : btnOutline('neutral')" :disabled="disabled"
      data-testid="capture-photo" @click="trigger">
      <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" d="M3 9a2 2 0 012-2h.93a2 2 0 001.664-.89l.812-1.22A2 2 0 0110.07 4h3.86a2 2 0 011.664.89l.812 1.22A2 2 0 0018.07 7H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9z" />
        <path stroke-linecap="round" stroke-linejoin="round" d="M15 13a3 3 0 11-6 0 3 3 0 016 0z" />
      </svg>
      {{ t('capture_photo.button') }}
    </button>
    <input ref="input" type="file" accept="image/*" capture="environment" class="hidden" @change="onPick" />

    <Modal v-if="open" :title="t('capture_photo.title')" width-class="max-w-md" @close="reset">
      <div class="space-y-3">
        <p v-if="busy" class="text-sm text-neutral-600">{{ t('capture_photo.processing') }}</p>
        <template v-else-if="error">
          <p class="text-sm text-danger-700" role="alert">{{ error }}</p>
          <p class="text-xs text-neutral-500">{{ t('capture_photo.fallback_hint') }}</p>
        </template>
        <template v-else-if="result">
          <img :src="previewUrl" :alt="t('capture_photo.title')" class="w-full max-h-[60vh] object-contain rounded-md border border-neutral-200 bg-neutral-50" />
          <p class="text-xs text-neutral-500">{{ result.name }} · {{ formatBytes(result.size) }}</p>
        </template>
      </div>
      <template #footer>
        <div class="flex flex-wrap items-center justify-end gap-2">
          <button type="button" :class="btnOutline('neutral')" @click="reset">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" /></svg>
            {{ t('capture_photo.cancel') }}
          </button>
          <button type="button" :class="btnOutline('warning')" :disabled="busy" @click="retake">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h5M20 20v-5h-5M5.6 15A8 8 0 0018.4 9M18.4 9A8 8 0 005.6 15" /></svg>
            {{ t('capture_photo.retake') }}
          </button>
          <button v-if="error && original" type="button" :class="btnFilled('primary')" @click="uploadOriginal">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 16V4m0 0L7 9m5-5 5 5M5 14v5a1 1 0 001 1h12a1 1 0 001-1v-5" /></svg>
            {{ t('capture_photo.upload_original') }}
          </button>
          <button v-else type="button" :class="btnFilled('success')" :disabled="busy || !result" data-testid="capture-photo-confirm" @click="confirm">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" /></svg>
            {{ t('capture_photo.upload') }}
          </button>
        </div>
      </template>
    </Modal>
  </span>
</template>
