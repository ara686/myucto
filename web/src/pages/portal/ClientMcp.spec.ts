import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { defineComponent, h, KeepAlive, nextTick, ref } from 'vue'

const m = vi.hoisted(() => ({
  canRead: true, locked: true, client: true, tokenAccess: false, get: vi.fn(), remove: vi.fn(), success: vi.fn(), error: vi.fn(),
}))
vi.mock('@/stores/auth', () => ({ useAuthStore: () => ({
  canRead: (key: string) => key === 'profile.tokens' ? m.tokenAccess : m.canRead,
  get isClientRole() { return m.client }, get domainContext() { return { locked: m.locked } },
}) }))
vi.mock('@/composables/useFormat', () => ({ formatDateTime: (value: string) => value }))
vi.mock('@/api/client', () => ({ api: { get: m.get, delete: m.remove } }))
vi.mock('@/composables/useToast', () => ({ useToast: () => ({ success: m.success, error: m.error }) }))
vi.mock('vue-i18n', () => ({ useI18n: () => ({ t: (key: string) => key }) }))
import ClientMcp from './ClientMcp.vue'

describe('client domain MCP connections', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    m.canRead = true
    m.client = true
    m.tokenAccess = false
    m.locked = true
    m.remove.mockResolvedValue({ data: { revoked: true } })
    m.get.mockImplementation(async (path: string) => ({ data: path === '/mcp/settings'
      ? { enabled: true, node_available: true, endpoint: 'https://portal.example.test/mcp' }
      : { grants: [{ id: 7, client_name: 'Synthetic assistant', resource: 'https://portal.example.test/mcp', scope: 'read', is_active: true, last_used_at: null }] },
    }))
    vi.spyOn(window, 'confirm').mockReturnValue(true)
  })

  it('shows the domain endpoint when the user can connect', async () => {
    const wrapper = mount(ClientMcp)
    await flushPromises()
    expect(wrapper.get('code').text()).toBe('https://portal.example.test/mcp')
    wrapper.unmount()
  })

  it('keeps revocation available after the connect permission is removed', async () => {
    m.canRead = false
    const wrapper = mount(ClientMcp)
    await flushPromises()
    expect(wrapper.find('code').exists()).toBe(false)
    await wrapper.findAll('button').find(b => b.text() === 'client_mcp.revoke')!.trigger('click')
    await flushPromises()
    expect(m.remove).toHaveBeenCalledWith('/mcp/grants/7')
    expect(m.success).toHaveBeenCalledWith('client_mcp.revoked')
    wrapper.unmount()
  })

  it('does not offer the canonical address as a client connection', async () => {
    m.locked = false
    const wrapper = mount(ClientMcp)
    await flushPromises()
    expect(wrapper.find('code').exists()).toBe(false)
    expect(wrapper.text()).toContain('client_mcp.permission_required')
    wrapper.unmount()
  })

  it('preserves the existing staff token permission on a client domain', async () => {
    m.client = false
    m.canRead = false
    m.tokenAccess = true
    const wrapper = mount(ClientMcp)
    await flushPromises()
    expect(wrapper.find('code').exists()).toBe(true)
    wrapper.unmount()
  })

  it('does not use the staff fallback for a client role', async () => {
    m.canRead = false
    m.tokenAccess = true
    const wrapper = mount(ClientMcp)
    await flushPromises()
    expect(wrapper.find('code').exists()).toBe(false)
    wrapper.unmount()
  })

  it('reloads connections after returning to a kept-alive panel', async () => {
    const shown = ref(true)
    const root = defineComponent({ setup: () => () => h(KeepAlive, null, {
      default: () => shown.value ? h(ClientMcp) : h('div'),
    }) })
    const wrapper = mount(root)
    await flushPromises()
    const initial = m.get.mock.calls.length
    shown.value = false
    await nextTick()
    shown.value = true
    await flushPromises()
    expect(m.get.mock.calls.length).toBe(initial + 2)
    wrapper.unmount()
  })
})
