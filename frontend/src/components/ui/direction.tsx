import * as React from "react"
import { Direction } from "radix-ui"

type DirectionProviderProps = {
  direction: "ltr" | "rtl"
  children: React.ReactNode
}

function DirectionProvider({ direction, children }: DirectionProviderProps) {
  return <Direction.DirectionProvider dir={direction}>{children}</Direction.DirectionProvider>
}

const useDirection = Direction.useDirection

export { DirectionProvider, useDirection }
