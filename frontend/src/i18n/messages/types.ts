import type { ar } from './ar'

/** Arabic is the source of truth; every other locale must provide the same keys. */
export type Messages = {
  [Section in keyof typeof ar]: { [Key in keyof (typeof ar)[Section]]: string }
}
