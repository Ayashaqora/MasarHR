import '@testing-library/jest-dom/vitest'
import { cleanup } from '@testing-library/react'
import { afterEach, beforeEach, vi } from 'vitest'
import { setViewportWidth } from './viewport'

// jsdom lacks these browser APIs, which the shadcn/Radix primitives (sidebar, menus, tooltips, scroll areas) rely on.
class ResizeObserverStub {
  observe() {}
  unobserve() {}
  disconnect() {}
}
Element.prototype.hasPointerCapture ??= () => false
Element.prototype.setPointerCapture ??= () => {}
Element.prototype.releasePointerCapture ??= () => {}
Element.prototype.scrollIntoView ??= () => {}

beforeEach(() => {
  vi.stubGlobal('ResizeObserver', ResizeObserverStub)
  setViewportWidth(1280)
})

afterEach(() => {
  cleanup()
  vi.unstubAllGlobals()
  vi.restoreAllMocks()
})
