<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import { useWorkspaceNavigation } from '@/composables/useWorkspaceNavigation'
import { useWorkspaceStore, type PaneCount } from '@/stores/workspace'

const { t } = useI18n()
const workspace = useWorkspaceStore()
const navigation = useWorkspaceNavigation()

function available(count: PaneCount): boolean {
  return count <= workspace.maximumPaneCount
}
</script>

<template>
  <div class="hidden lg:flex h-8 items-center gap-0.5 rounded-lg bg-neutral-100/80 p-0.5 shadow-inner ring-1 ring-neutral-200/80"
       role="group" :aria-label="t('workspace.layout_label')">
    <button
      v-for="count in ([1, 2, 3] as PaneCount[])"
      :key="count"
      type="button"
      class="inline-flex h-7 w-8 cursor-pointer items-center justify-center rounded-md text-neutral-500 transition-all hover:bg-surface hover:text-neutral-800 disabled:cursor-not-allowed disabled:opacity-30"
      :class="workspace.paneCount === count ? 'bg-surface text-primary-700 shadow-sm ring-1 ring-primary-300/70' : ''"
      :aria-label="t(`workspace.layout_${count}`)"
      :title="`${t(`workspace.layout_${count}`)} - ${t('workspace.layout_preserves_windows')}\n${t('workspace.layout_shift_click_hint')}`"
      :aria-pressed="workspace.paneCount === count"
      :disabled="!available(count)"
      @click="navigation.setPaneCount(count)"
    >
      <span class="flex h-3.5 w-5 overflow-hidden rounded-[2px] border border-current" aria-hidden="true">
        <span v-for="part in count" :key="part" class="min-w-0 flex-1 border-current not-first:border-l" />
      </span>
    </button>
  </div>
</template>
