import { computed, ref, shallowRef } from 'vue'

interface BeforeInstallPromptEvent extends Event {
  prompt: () => Promise<void>
  userChoice: Promise<{ outcome: 'accepted' | 'dismissed'; platform?: string }>
}

/** Typ položky v „Akcích pro vás" — shodný s whitelistem odložení na serveru. */
export const PWA_INSTALL_ITEM_TYPE = 'pwa_install'

export type PwaInstallMode = 'prompt' | 'ios'

const deferredPrompt = shallowRef<BeforeInstallPromptEvent | null>(null)
const installed = ref(false)
let initialized = false

/**
 * Zachytí `beforeinstallprompt` a `appinstalled`. Volá se jednou při startu aplikace
 * (main.ts), protože prohlížeč událost vyšle dřív, než se přehled stihne připojit.
 * Jde o dva pasivní listenery, žádné měření ani pozorovatele.
 */
export function initPwaInstall(): void {
  if (initialized || typeof window === 'undefined') return
  initialized = true
  window.addEventListener('beforeinstallprompt', (event) => {
    event.preventDefault()
    deferredPrompt.value = event as BeforeInstallPromptEvent
  })
  window.addEventListener('appinstalled', () => {
    installed.value = true
    deferredPrompt.value = null
  })
}

export function isStandaloneDisplay(): boolean {
  if (typeof window === 'undefined') return false
  if ((navigator as Navigator & { standalone?: boolean }).standalone === true) return true
  return typeof window.matchMedia === 'function' && window.matchMedia('(display-mode: standalone)').matches
}

export function isMobileDevice(): boolean {
  if (typeof window === 'undefined' || typeof window.matchMedia !== 'function') return false
  return window.matchMedia('(pointer: coarse)').matches || window.innerWidth <= 767
}

/** iPhone/iPad, včetně iPadOS, který se hlásí jako Mac s dotykovou obrazovkou. */
export function isIosDevice(): boolean {
  if (typeof navigator === 'undefined') return false
  if (/iPad|iPhone|iPod/.test(navigator.userAgent)) return true
  return navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1
}

export function usePwaInstall() {
  /** `null` = nic nenabízet (běží z plochy, není mobil, nebo prohlížeč instalaci neumí). */
  const mode = computed<PwaInstallMode | null>(() => {
    if (installed.value || isStandaloneDisplay() || !isMobileDevice()) return null
    if (deferredPrompt.value) return 'prompt'
    return isIosDevice() ? 'ios' : null
  })

  async function install(): Promise<boolean> {
    const event = deferredPrompt.value
    if (!event) return false
    // Událost jde použít jen jednou; po dotazu ji zahodíme, ať už uživatel přijal, nebo ne.
    deferredPrompt.value = null
    try {
      await event.prompt()
      const choice = await event.userChoice
      if (choice.outcome === 'accepted') installed.value = true
      return choice.outcome === 'accepted'
    } catch {
      return false
    }
  }

  return { mode, install }
}
