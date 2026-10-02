import { useI18n } from '../../i18n/context'
import type { Percentage } from './api'

/** The exact canonical figure from the response — never recomputed on the client. */
export function usePercentText() {
  const { messages } = useI18n()

  return (value: Percentage): string =>
    value.percent === null ? messages.dashboard.notCalculable : `${value.percent}%`
}
