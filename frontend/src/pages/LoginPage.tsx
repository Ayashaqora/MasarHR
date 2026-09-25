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
    <>
      <PageHeader title={messages.auth.pageTitle} />
      <LoginForm />
    </>
  )
}
