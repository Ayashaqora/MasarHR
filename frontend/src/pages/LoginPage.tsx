import { Navigate } from 'react-router'
import { useAuth } from '../features/auth/context'
import { LoginForm } from '../features/auth/LoginForm'
import { useI18n } from '../i18n/context'
import { PageHeader } from '../shared/ui/PageHeader'

export function LoginPage() {
  const { messages } = useI18n()
  const { status } = useAuth()

  if (status === 'authenticated') {
    return <Navigate to="/" replace />
  }

  return (
    <div className="mx-auto w-full max-w-md pt-6 sm:pt-12">
      <PageHeader title={messages.auth.pageTitle} description={messages.app.tagline} />
      <LoginForm />
    </div>
  )
}
