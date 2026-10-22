import { mount, flushPromises } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import { nextTick, reactive } from 'vue'
import { describe, it, expect, vi, beforeEach } from 'vitest'
import PropertyInstantInput from '../PropertyInstantInput.vue'
import PropertyStateEditor from '../PropertyStateEditor.vue'
import PropertyRegistryView from '@/views/PropertyRegistryView.vue'
import type { UnitState } from '@/api/propertyRegistry'
import cs from '@/i18n/cs.json'

const calls = vi.hoisted(() => ({ profile: vi.fn(), list: vi.fn(), summary: vi.fn(), detail: vi.fn(), create: vi.fn(), profileSave: vi.fn() }))
vi.mock('@/api/propertyRegistry', () => ({ propertyRegistryApi: calls }))
const store = reactive({ currentSupplierId: 1 })
vi.mock('@/stores/supplier', () => ({ useSupplierStore: () => store }))
const plugins = () => [createI18n({ legacy: false, locale: 'cs', messages: { cs } })]
const dateStub = { props: ['modelValue'], emits: ['update:modelValue'], template: '<input data-date :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)" />' }
const partyStub = { props: ['modelValue'], emits: ['update:modelValue'], template: '<input data-party :value="modelValue" @input="$emit(\'update:modelValue\', Number($event.target.value))" />' }
function deferred<T>() { let resolve!: (value: T) => void; const promise = new Promise<T>(r => { resolve = r }); return { promise, resolve } }
const profile = { profile: null, history: [], capabilities: { manage: true, confirm: true, export: false }, import_columns: [] }

beforeEach(() => { vi.clearAllMocks(); store.currentSupplierId = 1; calls.profile.mockResolvedValue(profile); calls.list.mockResolvedValue([]); calls.summary.mockResolvedValue({ scopes: [] }) })

describe('property registry UI', () => {
  it('derives the Prague offset from the entered local date and time instead of asking for it', async () => {
    const wrapper = mount(PropertyInstantInput, { props: { modelValue: '', label: 'Účinnost', 'onUpdate:modelValue': value => { void wrapper.setProps({ modelValue: value }) } }, global: { plugins: plugins(), stubs: { DateInput: dateStub } } })
    expect(wrapper.find('input[placeholder="+02:00"]').exists()).toBe(false)
    await wrapper.get('[data-date]').setValue('2026-05-01')
    expect(wrapper.emitted('update:modelValue')?.at(-1)).toEqual([''])
    await wrapper.get('input[type="time"]').setValue('10:15')
    expect(wrapper.emitted('update:modelValue')?.at(-1)).toEqual(['2026-05-01T10:15:00+02:00'])
    expect(wrapper.get('[data-instant-zone]').text()).toBe(cs.propertyRegistry.localTimeSummer)
    await wrapper.get('[data-date]').setValue('2026-01-10')
    expect(wrapper.emitted('update:modelValue')?.at(-1)).toEqual(['2026-01-10T10:15:00+01:00'])
    expect(wrapper.get('[data-instant-zone]').text()).toBe(cs.propertyRegistry.localTimeWinter)
    await wrapper.setProps({ modelValue: '2026-06-02T13:20:30.123456Z' })
    expect((wrapper.get('[data-date]').element as HTMLInputElement).value).toBe('2026-06-02')
    expect((wrapper.get('input[type="time"]').element as HTMLInputElement).value).toBe('15:20')
    wrapper.unmount()
  })

  it('clears the whole instant instead of emitting a time-only remainder when the date is removed', async () => {
    const wrapper = mount(PropertyInstantInput, { props: { modelValue: '2026-06-02T13:20:30+02:00', label: 'Konec', 'onUpdate:modelValue': value => { void wrapper.setProps({ modelValue: value }) } }, global: { plugins: plugins(), stubs: { DateInput: dateStub } } })
    await wrapper.get('[data-date]').setValue('')
    await wrapper.get('input[type="time"]').setValue('')
    expect(wrapper.emitted('update:modelValue')?.at(-1)).toEqual([''])
    wrapper.unmount()
  })

  it('blocks submitting a profile change while the visible date text is not a date', async () => {
    calls.profile.mockResolvedValue({ ...profile, capabilities: { manage: true, confirm: true, export: false } })
    const wrapper = mount(PropertyRegistryView, { attachTo: document.body, global: { plugins: plugins(), stubs: { PropertyImportPanel: true } } })
    await flushPromises()
    await wrapper.findAll('button').find(b => b.text() === cs.propertyRegistry.profileChange)!.trigger('click')
    const form = wrapper.get('form')
    const [date] = form.findAll('input[inputmode="numeric"]')
    await date!.setValue('31.13.garbage')
    await form.find('label:nth-of-type(2) input').setValue('Důvod')
    await form.find('label:nth-of-type(3) input').setValue('Listina')
    expect((form.element as HTMLFormElement).checkValidity()).toBe(false)
    await form.findAll('button').find(b => b.text() === cs.propertyRegistry.saveProfile)!.trigger('click')
    await flushPromises()
    expect(calls.profileSave).not.toHaveBeenCalled()
    wrapper.unmount()
  })

  it('adds only attribute codes accepted by the registry backend', async () => {
    const state: UnitState = { unit_no: '12', kind: 'apartment', active: true, attributes: { share: { num: '1', den: '1' } }, ownerships: [], roles: [], identifiers: [], services: [] }
    const wrapper = mount(PropertyStateEditor, { props: { modelValue: state, kind: 'unit', supplierId: 1, services: [], partyLabels: {} }, global: { plugins: plugins() } })
    const code = wrapper.get(`input[aria-label="${cs.propertyRegistry.attributeCode}"]`)
    const add = wrapper.findAll('button').find(button => button.text() === cs.propertyRegistry.addAttribute)!
    await code.setValue('area:heated')
    await add.trigger('click')
    expect(state.attributes).not.toHaveProperty('area:heated')
    await code.setValue('area_heated')
    await add.trigger('click')
    expect(state.attributes.area_heated).toEqual({ num: '0', den: '1' })
    wrapper.unmount()
  })

  it('edits marital community as one interest and two members without duplicating or halving its share', async () => {
    const state: UnitState = { unit_no: '12', kind: 'apartment', active: true, attributes: { share: { num: '90', den: '1000' } }, ownerships: [], roles: [], identifiers: [], services: [] }
    const wrapper = mount(PropertyStateEditor, { props: { modelValue: state, kind: 'unit', supplierId: 1, services: [], partyLabels: {} }, global: { plugins: plugins(), stubs: { PropertyPartyPicker: partyStub } } })
    await wrapper.findAll('button').find(b => b.text() === cs.propertyRegistry.addOwner)!.trigger('click')
    const select = wrapper.findAll('select').find(s => s.find('option[value="marital_community"]').exists())!
    await select.setValue('marital_community')
    await wrapper.findAll('[data-party]')[0]!.setValue('11')
    await wrapper.findAll('[data-party]')[1]!.setValue('12')
    expect(state.ownerships).toHaveLength(1)
    expect(state.ownerships[0]?.members).toEqual([11, 12])
    expect(state.ownerships[0]?.share).toEqual({ num: '1', den: '1' })
    expect(state.roles).toEqual([])
    wrapper.unmount()
  })

  it('discards a previous company response and sends explicit company scope for all reads', async () => {
    const old = deferred<typeof profile>()
    calls.profile.mockImplementation((id: number) => id === 1 ? old.promise : Promise.resolve(profile))
    calls.list.mockImplementation((id: number, kind: string) => Promise.resolve(kind === 'house' ? [{ id: id * 10, kind: 'house', code: 'H', current_revision: 1, pending_revision_ids: [], revision: { state: { name: id === 1 ? 'Old company house' : 'New company house', active: true } } }] : []))
    const wrapper = mount(PropertyRegistryView, { global: { plugins: plugins(), stubs: { PropertyInstantInput: true, PropertyImportPanel: true } } })
    await nextTick(); store.currentSupplierId = 2; await flushPromises()
    expect(wrapper.text()).toContain('New company house')
    old.resolve(profile); await flushPromises()
    expect(wrapper.text()).not.toContain('Old company house')
    expect(wrapper.text()).toContain('New company house')
    expect(calls.profile.mock.calls.map(args => args[0])).toEqual([1, 2])
    expect(calls.list.mock.calls.filter(args => args[0] === 2).map(args => args[1])).toEqual(['house', 'unit', 'service'])
    wrapper.unmount()
  })
})
