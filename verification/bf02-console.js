// MASARHR S46-BF02 - paste into the browser DevTools Console on the Employee 360 page (any width / language).
// Measurement is read-only. It does NOT click, focus or change the active tab. Run bf02Reach() for the scroll-reach measurement.
// DOM numbers are supporting evidence only: they do NOT replace swiping/scrolling to every one of the six tabs by hand.
(() => {
  const list = document.querySelector('[role="tablist"]')
  if (!list) return 'No [role=tablist] found - open an Employee 360 page first.'
  const wrap = list.parentElement
  const tabs = [...list.querySelectorAll('[role="tab"]')]
  const rects = tabs.map((t) => t.getBoundingClientRect())
  const ys = [...new Set(rects.map((r) => Math.round(r.top)))]
  const panel = [...document.querySelectorAll('[role="tabpanel"]')].find((p) => !p.hidden)
  const firstBlock = panel && panel.firstElementChild ? panel.firstElementChild.getBoundingClientRect() : null
  const lowestTabBottom = Math.max(...rects.map((r) => r.bottom))
  const cs = (el, props) => Object.fromEntries(props.map((p) => [p, getComputedStyle(el)[p]]))
  const out = {
    documentDir: document.documentElement.dir, lang: document.documentElement.lang,
    viewport: `${innerWidth}x${innerHeight}`, devicePixelRatio: devicePixelRatio,
    tabCount: tabs.length, activeTab: (tabs.find((t) => t.getAttribute('aria-selected') === 'true') || {}).textContent,
    wrapper: { ...cs(wrap, ['display', 'overflowX', 'overflowY', 'direction']), clientWidth: wrap.clientWidth, scrollWidth: wrap.scrollWidth, scrollable: wrap.scrollWidth > wrap.clientWidth, scrollLeft: Math.round(wrap.scrollLeft) },
    list: { ...cs(list, ['display', 'flexWrap', 'justifyContent', 'width', 'minWidth', 'height']), clientWidth: list.clientWidth },
    tabY: ys, distinctTabYPositions: ys.length,
    tabWhiteSpace: [...new Set(tabs.map((t) => getComputedStyle(t).whiteSpace))].join(','),
    lowestTabBottom: Math.round(lowestTabBottom), listBottom: Math.round(list.getBoundingClientRect().bottom), wrapperBottom: Math.round(wrap.getBoundingClientRect().bottom),
    contentTop: firstBlock ? Math.round(firstBlock.top) : null,
    tabsOverlapContent: firstBlock ? lowestTabBottom > firstBlock.top + 0.5 : 'n/a',
    pageOverflowX: document.documentElement.scrollWidth > document.documentElement.clientWidth,
  }
  window.bf02Reach = () => {
    // For each tab: scroll ONLY the tab strip (never focus/click) until the tab is wholly inside it, then record the result; strip position is restored.
    const original = wrap.scrollLeft
    const result = tabs.map((t) => {
      const r = t.getBoundingClientRect(), w = wrap.getBoundingClientRect()
      const delta = r.left < w.left ? r.left - w.left : r.right > w.right ? r.right - w.right : 0
      wrap.scrollBy({ left: delta, behavior: 'instant' })
      const r2 = t.getBoundingClientRect(), w2 = wrap.getBoundingClientRect()
      return { tab: t.textContent, whollyVisibleAfterScroll: r2.left >= w2.left - 1 && r2.right <= w2.right + 1 }
    })
    wrap.scrollLeft = original
    return result
  }
  console.log('Now call bf02Reach() for the per-tab scroll-reach measurement (it scrolls only the strip).')
  return JSON.stringify(out, null, 1)
})()
