import { LogIn } from 'lucide-react'
import { useState, type FormEvent } from 'react'
import { useLocation, useNavigate } from 'react-router'
import { Button } from '@/components/ui/button'
import { Card, CardContent } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { useI18n } from '../../i18n/context'
import { ApiError } from '../../shared/api'
import { FormField } from '../../shared/ui/FormField'
import { StatePanel } from '../../shared/ui/StatePanel'
import { useAuth } from './context'

export function LoginForm() {
  const { messages } = useI18n()
  const { login } = useAuth()
  const navigate = useNavigate()
  const location = useLocation()

  const [username, setUsername] = useState('')
  const [password, setPassword] = useState('')
  const [submitting, setSubmitting] = useState(false)
  const [error, setError] = useState<string | null>(null)

  const from = (location.state as { from?: string } | null)?.from ?? '/'

  async function handleSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    if (submitting) {
      return
    }

    if (username.trim() === '' || password === '') {
      setError(messages.auth.missingFields)
      return
    }

    setSubmitting(true)
    setError(null)

    try {
      await login(username, password)
      void navigate(from, { replace: true })
    } catch (caught: unknown) {
      // Never surface the raw server message here (§12/SEC-10 of the S03 authorization): a wrong
      // username and a wrong password must look identical to whoever is typing them in.
      setError(
        caught instanceof ApiError && caught.status === 429
          ? messages.auth.tooManyAttempts
          : messages.auth.invalidCredentials,
      )
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <Card>
      <CardContent>
        <form
          className="space-y-5"
          noValidate
          onSubmit={(event) => {
            void handleSubmit(event)
          }}
        >
          <FormField label={messages.auth.username} required>
            {(field) => (
              <Input
                {...field}
                name="username"
                type="text"
                autoComplete="username"
                dir="auto"
                value={username}
                disabled={submitting}
                onChange={(event) => setUsername(event.target.value)}
              />
            )}
          </FormField>

          <FormField label={messages.auth.password} required>
            {(field) => (
              <Input
                {...field}
                name="password"
                type="password"
                autoComplete="current-password"
                dir="auto"
                value={password}
                disabled={submitting}
                onChange={(event) => setPassword(event.target.value)}
              />
            )}
          </FormField>

          {error ? <StatePanel tone="error" title={error} className="my-0" /> : null}

          <Button type="submit" className="w-full" disabled={submitting}>
            <LogIn aria-hidden="true" className="rtl:-scale-x-100" />
            {submitting ? messages.auth.signingIn : messages.auth.signIn}
          </Button>
        </form>
      </CardContent>
    </Card>
  )
}
