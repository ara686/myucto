<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { useWorkspaceStore } from '@/stores/workspace'
import { getWindowManager } from '@/workspace/windowRegistry'
import { formatShortcut, useKeyboardShortcuts } from '@/composables/useKeyboardShortcuts'

const { t } = useI18n()
const workspace = useWorkspaceStore()
const shortcuts = useKeyboardShortcuts()
const available = computed(() => workspace.windows.some(window => window.paneId === workspace.activePaneId))
</script>

<template>
  <button
    type="button"
    class="inline-flex h-8 w-8 shrink-0 cursor-pointer items-center justify-center rounded-md text-neutral-600 transition-colors hover:bg-neutral-100 hover:text-primary-700 focus-visible:ring-2 focus-visible:ring-primary-400 disabled:cursor-default disabled:opacity-40 disabled:hover:bg-transparent"
    :title="[t('workspace.reload_window_hint'), formatShortcut(shortcuts.comboFor('windows.reload'))].filter(Boolean).join(' · ')"
    :aria-label="t('workspace.reload_window')"
    :disabled="!available || workspace.companySwitching"
    data-reload-window
    @click="getWindowManager()?.reloadPane(workspace.activePaneId)"
  >
    <svg class="h-[17px] w-[17px]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
      <path d="M20 11a8 8 0 1 0-2.34 5.66" />
      <path d="M20 4v7h-7" />
    </svg>
  </button>
</template>
