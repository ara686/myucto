<script setup lang="ts">
defineProps<{ tabs: Array<{ key: string; label: string }>; modelValue: string; disabled?: boolean }>()
const emit = defineEmits<{ 'update:modelValue': [value: string] }>()
</script>

<template>
  <div role="tablist" class="flex flex-wrap gap-2">
    <button v-for="tab in tabs" :key="tab.key" type="button" role="tab" :data-tab="tab.key" :aria-selected="modelValue === tab.key" :disabled="disabled"
      class="h-9 whitespace-nowrap rounded-md border px-3 text-sm font-medium disabled:opacity-50"
      :class="modelValue === tab.key ? 'border-primary-600 bg-primary-600 text-white' : 'border-neutral-300 text-neutral-700 hover:bg-neutral-50'"
      @click="emit('update:modelValue', tab.key)">{{ tab.label }}</button>
  </div>
</template>
