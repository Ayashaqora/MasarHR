import { useEffect } from 'react'
import { useI18n } from '../../i18n/context'

/** Sets the document title to "<page> — <app>" so route changes are announced and bookmarkable. */
export function usePageTitle(title: string): void {
  const { messages } = useI18n()

  useEffect(() => {
    document.title = `${title} — ${messages.app.name}`
  }, [title, messages.app.name])
}
