import { describe, expect, it } from 'vitest'
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'

const appLayout = readFileSync(resolve(process.cwd(), 'src/components/layout/AppLayout.vue'), 'utf8')

describe('Správa instance v menu hlavní aplikace', () => {
  it('vede superadmina na stránku v panelu a na spravované instalaci položku skryje', () => {
    expect(appLayout).toContain("...(isAdmin && !auth.isManagedInstallation ? [{ to: '/admin/instance', label: t('instance_admin.title'), icon: ICONS.cron }] : [])")
    expect(appLayout.match(/to: '\/admin\/instance'/g)).toHaveLength(1)
    expect(appLayout).not.toContain("to: '/instance-admin'")
  })
})
