import { Link } from 'react-router'
import { useI18n } from '../i18n/context'
import { PageHeader } from '../shared/ui/PageHeader'

export function NotFoundPage() {
  const { messages } = useI18n()

  return (
    <>
      <PageHeader
        title={messages.errors.notFoundTitle}
        description={messages.errors.notFoundDescription}
      />
      <Link className="button" to="/">
        {messages.errors.backHome}
      </Link>
    </>
  )
}
