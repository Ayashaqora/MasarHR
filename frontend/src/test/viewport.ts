/** Desktop by default; tests that need the mobile layout call setViewportWidth(500) before rendering. */
export function setViewportWidth(width: number) {
  Object.defineProperty(window, 'innerWidth', { configurable: true, writable: true, value: width })
  window.matchMedia = ((query: string) => {
    const max = /max-width:\s*(\d+)px/.exec(query)
    return {
      matches: max ? width <= Number(max[1]) : false,
      media: query,
      onchange: null,
      addEventListener: () => {},
      removeEventListener: () => {},
      addListener: () => {},
      removeListener: () => {},
      dispatchEvent: () => false,
    }
  }) as typeof window.matchMedia
}
