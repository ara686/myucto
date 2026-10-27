<script setup lang="ts">
import { computed, onActivated, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { api } from '@/api/client'
import { useAuthStore } from '@/stores/auth'
import { useToast } from '@/composables/useToast'
import { formatDateTime } from '@/composables/useFormat'
import { btnOutline } from '@/components/ui/buttonStyles'
import McpConnectionGuide from '@/components/mcp/McpConnectionGuide.vue'
import McpServerAddress from '@/components/mcp/McpServerAddress.vue'

interface Grant {
  id: number
  client_name: string
  resource: string
  scope: string
  is_active: boolean
  last_used_at: string | null
}

const { t } = useI18n()
const auth = useAuthStore()
const toast = useToast()
const endpoint = ref('')
const enabled = ref(false)
const grants = ref<Grant[]>([])
const loading = ref(false)
const error = ref('')
const revoking = ref<number | null>(null)
const canConnect = computed(() => auth.domainContext?.locked === true
  && (auth.canRead('profile.mcp') || (!auth.isClientRole && auth.canRead('profile.tokens'))))
let mounted = false

async function load() {
  if (loading.value) return
  loading.value = true
  error.value = ''
  try {
    const [settings, connections] = await Promise.all([
      api.get<{ enabled: boolean; node_available: boolean; endpoint: string }>('/mcp/settings'),
      api.get<{ grants: Grant[] }>('/mcp/grants'),
    ])
    endpoint.value = settings.data.endpoint
    enabled.value = settings.data.enabled && settings.data.node_available
    grants.value = connections.data.grants
  } catch {
    error.value = t('client_mcp.load_error')
  } finally {
    loading.value = false
  }
}

async function copyEndpoint(address: string) {
  try {
    await navigator.clipboard.writeText(address)
    toast.success(t('client_mcp.copied'))
  } catch {
    toast.error(t('client_mcp.copy_error'))
  }
}

async function revoke(grant: Grant) {
  if (revoking.value !== null || !window.confirm(t('client_mcp.revoke_confirm', { name: grant.client_name }))) return
  revoking.value = grant.id
  try {
    await api.delete(`/mcp/grants/${grant.id}`)
    toast.success(t('client_mcp.revoked'))
    await load()
  } catch {
    toast.error(t('client_mcp.revoke_error'))
  } finally {
    revoking.value = null
  }
}

onMounted(async () => { await load(); mounted = true })
onActivated(() => { if (mounted) void load() })
</script>

<template>
  <div class="space-y-6">
    <h1 class="text-2xl font-semibold">{{ t('client_mcp.title') }}</h1>
    <p class="text-neutral-600">{{ t('client_mcp.description') }}</p>
    <p v-if="error" role="alert" class="rounded-xl bg-danger-50 p-4 text-neutral-900">{{ error }}</p>
    <section class="rounded-xl border border-neutral-200 bg-surface p-5">
      <h2 class="mb-3 text-lg font-semibold">{{ t('client_mcp.connect') }}</h2>
      <p v-if="!enabled">{{ t('client_mcp.disabled') }}</p>
      <p v-else-if="!canConnect">{{ t('client_mcp.permission_required') }}</p>
      <p v-else>{{ t('client_mcp.instructions') }}</p>
    </section>
    <McpServerAddress v-if="endpoint" :endpoint="endpoint"
      :hint="auth.domainContext?.locked !== true ? t('client_mcp.canonical_endpoint_hint') : undefined"
      @copy="copyEndpoint" />
    <McpConnectionGuide client-domain />
    <section class="rounded-xl border border-neutral-200 bg-surface p-5">
      <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
        <h2 class="text-lg font-semibold">{{ t('client_mcp.connections') }}</h2>
        <button type="button" :disabled="loading" :class="btnOutline('neutral')" @click="load">
          <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><path d="M20 7v5h-5M4 17v-5h5M6 7a7 7 0 0 1 12-1l2 2M4 16l2 2a7 7 0 0 0 12-1" /></svg>
          {{ t('client_mcp.refresh') }}
        </button>
      </div>
      <p v-if="loading" role="status">{{ t('client_mcp.loading') }}</p>
      <p v-else-if="grants.length === 0">{{ t('client_mcp.empty') }}</p>
      <ul v-else class="divide-y divide-neutral-200">
        <li v-for="grant in grants" :key="grant.id" class="flex flex-wrap items-center justify-between gap-3 py-4">
          <div class="min-w-0 space-y-1">
            <p class="font-medium">{{ grant.client_name }}</p>
            <p class="break-all text-sm text-neutral-500">{{ grant.resource }}</p>
            <p class="text-sm">{{ t(grant.scope === 'read_write' ? 'client_mcp.read_write' : 'client_mcp.read') }} · {{ t(grant.is_active ? 'client_mcp.active' : 'client_mcp.inactive') }}</p>
            <p class="text-sm text-neutral-500">{{ t('client_mcp.last_used', { date: grant.last_used_at ? formatDateTime(grant.last_used_at) : t('client_mcp.never') }) }}</p>
          </div>
          <button v-if="grant.is_active" type="button" :disabled="revoking !== null" :class="btnOutline('danger')" @click="revoke(grant)">
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><path d="M6 6l12 12M18 6 6 18" /></svg>
            {{ t('client_mcp.revoke') }}
          </button>
        </li>
      </ul>
    </section>
  </div>
</template>
