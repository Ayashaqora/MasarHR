import { screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { describe, expect, it, vi } from 'vitest'
import { ar } from '../i18n/messages/ar'
import { install360 } from '../test/employee360Fixtures'
import { renderApp } from '../test/render'

const classesOf = (element: Element) => element.className.split(/\s+/)

/**
 * S46-BF02 RC2 structure guard. jsdom does no layout, so this pins the class contract — it does NOT prove physical layout,
 * which is the Owner's real-browser 390px gate. The contract: one non-wrapping row inside a horizontally scrolling wrapper,
 * with the shared list's fixed height left alone. It also guards against regressing to the rejected multi-row (RC1) wrapping.
 */
describe('Employee360 tab navigation (BF02 RC2)', () => {
  it('keeps one non-wrapping row that scrolls horizontally inside its own wrapper and holds all six tabs', async () => {
    install360()
    renderApp('/employees/person-1/relationships/rel-1')
    const list = await screen.findByRole('tablist', { name: ar.employee360.tabsLabel }, { timeout: 4000 })

    const listClasses = classesOf(list)
    expect(listClasses).toContain('flex-nowrap')
    expect(listClasses).toContain('w-max')
    expect(listClasses).toContain('min-w-full')
    expect(listClasses).toContain('justify-start')

    // The rejected RC1 solution: a wrapping, auto-height list.
    expect(listClasses).not.toContain('flex-wrap')
    expect(listClasses).not.toContain('h-auto!')
    expect(listClasses).not.toContain('h-auto')

    const wrapper = list.parentElement
    expect(wrapper).not.toBeNull()
    const wrapperClasses = classesOf(wrapper as Element)
    expect(wrapperClasses).toContain('overflow-x-auto')
    expect(wrapperClasses).toContain('overflow-y-hidden')
    expect(wrapperClasses).toContain('max-w-full')

    const tabs = screen.getAllByRole('tab')
    expect(tabs).toHaveLength(6)
    for (const tab of tabs) {
      expect(list).toContainElement(tab)
      expect(classesOf(tab)).toContain('flex-none')
    }
  })

  it('scrolls a focused tab fully into view so a half-clipped tab is never left half-clipped', async () => {
    install360()
    renderApp('/employees/person-1/relationships/rel-1')
    await screen.findByRole('tablist', { name: ar.employee360.tabsLabel }, { timeout: 4000 })
    const scrollIntoView = vi.spyOn(Element.prototype, 'scrollIntoView').mockImplementation(() => {})
    const user = userEvent.setup()
    await user.tab()
    const [target] = screen.getAllByRole('tab', { name: ar.employee360.tabMovementTimeline })
    target?.focus()
    expect(scrollIntoView).toHaveBeenCalledWith({ block: 'nearest', inline: 'nearest' })
  })
})
