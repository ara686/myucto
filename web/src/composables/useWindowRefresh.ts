import { inject, onActivated, onBeforeUnmount, onDeactivated, onMounted, ref, watch } from 'vue'
import { windowContextKey } from '@/workspace/windowContext'
import { getWindowSupplierScope } from '@/workspace/supplierContext'

/**
 * Prostředky (první segment API cesty), jejichž změna má okno obnovit.
 * `'*'` = jakákoli zapisující operace v jiném okně téže firmy. Hodí se pro
 * přehledy, které skládají data z více agend (datová schránka, mzdová
 * podání): odeslání JMHZ jde přes `/payroll`, ale nová zpráva se objeví
 * v odchozích podáních datové schránky.
 */
export type WindowRefreshResources = readonly string[] | '*'

const DIALOG_SELECTOR = '[role="dialog"], [aria-modal="true"]'

/**
 * Obnova dat v zapamatovaném okně pracovní plochy.
 *
 * Okno zůstává po odchodu jinam zaparkované i s načtenými daty. Zapisující
 * požadavek v jiném okně (`api/client.ts` → `myucto-window-mutation`) rozešle
 * správce oken ostatním oknům téže firmy jako `myucto:window-data-changed`.
 * Tady se změna jen poznamená a `refresh` se zavolá, až je okno znovu aktivní
 * (a v `<KeepAlive>` aktivované), neběží jiná obnova, není otevřený modál
 * a `canRefresh` nehlásí rozepsaný formulář. Víc změn mezitím se slije do
 * jedné obnovy.
 *
 * `refresh` má data obnovit potichu, bez celostránkového skeletonu: obsah
 * zůstane na obrazovce a jen se přepíše. Chyba obnovy nechá změnu čekat na
 * další příležitost.
 *
 * Stránky bez composable nic nestojí: posluchač je jen tady a pozorovatel
 * DOMu běží výhradně po dobu, kdy obnova čeká na zavření modálu.
 */
export function useWindowRefresh(
  resources: WindowRefreshResources,
  refresh: () => void | Promise<unknown>,
  canRefresh: () => boolean = () => true,
) {
  const context = inject(windowContextKey, null)
  const supplierId = getWindowSupplierScope()?.supplierId
  const pending = ref(false)
  const kept = ref(true)
  let running = false
  let disposed = false
  let observer: MutationObserver | undefined

  function dialogOpen() {
    return document.querySelector(DIALOG_SELECTOR) !== null
  }

  function ready() {
    return context?.active.value === true && kept.value && canRefresh()
  }

  function stopWaiting() {
    observer?.disconnect()
    observer = undefined
  }

  function waitForDialog() {
    if (observer || disposed) return
    observer = new MutationObserver(() => {
      if (dialogOpen()) return
      stopWaiting()
      void flush()
    })
    observer.observe(document.body, { childList: true, subtree: true, attributes: true, attributeFilter: ['role', 'aria-modal'] })
  }

  async function flush() {
    if (disposed || running || !pending.value || !ready()) return
    if (dialogOpen()) {
      waitForDialog()
      return
    }
    stopWaiting()
    pending.value = false
    running = true
    let succeeded = false
    try {
      await refresh()
      succeeded = true
    } catch {
      pending.value = true
    } finally {
      running = false
      if (succeeded) void flush()
    }
  }

  function changed(event: Event) {
    const detail = (event as CustomEvent<unknown>).detail
    if (!detail || typeof detail !== 'object') return
    const { supplierId: changedSupplier, resource } = detail as Record<string, unknown>
    if (changedSupplier !== supplierId || typeof resource !== 'string') return
    if (resources !== '*' && !resources.includes(resource)) return
    pending.value = true
    void flush()
  }

  if (context && supplierId) {
    watch([context.active, canRefresh], () => { void flush() }, { flush: 'post' })
    onMounted(() => {
      window.addEventListener('myucto:window-data-changed', changed)
    })
    onActivated(() => {
      kept.value = true
      void flush()
    })
    onDeactivated(() => {
      kept.value = false
    })
    onBeforeUnmount(() => {
      disposed = true
      window.removeEventListener('myucto:window-data-changed', changed)
      stopWaiting()
    })
  }

  return { pending }
}
