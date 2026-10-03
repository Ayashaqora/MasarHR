import type { ReactNode } from 'react'

/**
 * National IDs, employee numbers, codes, counts and dates must stay legible and directionally stable
 * inside Arabic text: this isolates the value and fixes its digit order (never reverses numerals).
 */
export function Ltr({ children }: { children: ReactNode }) {
  return <bdi dir="ltr">{children}</bdi>
}
