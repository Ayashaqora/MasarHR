import { describe, expect, it } from 'vitest'
import { ar } from './ar'
import { en } from './en'

function keyPaths(value: object, prefix = ''): string[] {
  return Object.entries(value).flatMap(([key, child]) =>
    typeof child === 'object' && child !== null
      ? keyPaths(child, `${prefix}${key}.`)
      : [`${prefix}${key}`],
  )
}

describe('message catalogs', () => {
  it('English mirrors every Arabic key', () => {
    expect(keyPaths(en).sort()).toEqual(keyPaths(ar).sort())
  })

  it('has no empty strings', () => {
    for (const catalog of [ar, en]) {
      for (const section of Object.values(catalog)) {
        for (const text of Object.values(section)) {
          expect(text.trim()).not.toBe('')
        }
      }
    }
  })
})
