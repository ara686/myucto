import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'

const m = vi.hoisted(() => ({
  replace: vi.fn(), handoff: '/profile/password?tab=totp',
  auth: {
    fetchSetupStatus: vi.fn(), refresh: vi.fn(), needsSetup: false, setupStatus: null,
    isAuthenticated: true, mustSetupMfa: false, mustSetupTotp: false, shouldOfferMfa: true,
    domainContext: { mode: 'canonical', locked: false },
  },
}))
vi.mock('@/components/layout/AppShell.vue', () => ({ default: { template: '<div><slot /></div>' } }))
vi.mock('@/stores/auth', () => ({ useAuthStore: () => m.auth }))
vi.mock('@/api/auth', () => ({ authApi: {} }))
vi.mock('@/router', () => ({ clearLoginBounces: vi.fn(), loginRedirectLoopDetected: () => false }))
vi.mock('@/composables/useTurnstile', () => ({ useTurnstile: () => ({}) }))
vi.mock('@/security/webauthn', () => ({ isWebAuthnAvailable: () => false }))
vi.mock('@/security/domainLogin', () => ({
  captureCanonicalDomainLogin: vi.fn(), hasPendingCanonicalDomainLogin: () => true,
  hasPendingCanonicalDomainLoginCleanup: () => false,
  canonicalDomainLoginSessionCsrf: () => 'fresh-flow-csrf',
  pendingCanonicalDomainLoginHandoff: () => m.handoff,
}))
vi.mock('vue-router', () => ({ useRoute: () => ({ query: {} }), useRouter: () => ({ replace: m.replace }) }))
vi.mock('vue-i18n', () => ({ useI18n: () => ({ t: (key: string) => key }) }))
import Login from '../Login.vue'

beforeEach(() => {
  vi.clearAllMocks()
  m.handoff = '/profile/password?tab=totp'
  m.auth.mustSetupMfa = false
  m.auth.shouldOfferMfa = true
})

describe('canonical enrollment handoff', () => {
  it('první TOTP pokračuje průvodcem, který převezme ověření z loginu', async () => {
    const wrapper = mount(Login)
    await flushPromises()
    expect(m.replace).toHaveBeenCalledWith('/setup-mfa?method=totp')
    wrapper.unmount()
  })

  it('správa již zřízených faktorů zachová původní cíl', async () => {
    m.auth.shouldOfferMfa = false
    const wrapper = mount(Login)
    await flushPromises()
    expect(m.replace).toHaveBeenCalledWith('/profile/password?tab=totp')
    wrapper.unmount()
  })
})
