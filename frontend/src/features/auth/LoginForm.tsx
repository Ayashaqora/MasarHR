import { useId, useState, type FormEvent } from 'react'
import { useLocation, useNavigate } from 'react-router'
import { useI18n } from '../../i18n/context'
import { ApiError } from '../../shared/api'
import { StatePanel } from '../../shared/ui/StatePanel'
import { useAuth } from './context'

export function LoginForm() {
  const { messages } = useI18n()
  const { login } = useAuth()
  const navigate = useNavigate()
  const location = useLocation()
  const usernameId = useId()
  const passwordId = useId()

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
    <form
      className="card auth-form"
      noValidate
      onSubmit={(event) => {
        void handleSubmit(event)
      }}
    >
      <div className="form-field">
        <label htmlFor={usernameId}>{messages.auth.username}</label>
        <input
          id={usernameId}
          name="username"
          type="text"
          autoComplete="username"
          value={username}
          disabled={submitting}
          onChange={(event) => setUsername(event.target.value)}
        />
      </div>

      <div className="form-field">
        <label htmlFor={passwordId}>{messages.auth.password}</label>
        <input
          id={passwordId}
          name="password"
          type="password"
          autoComplete="current-password"
          value={password}
          disabled={submitting}
          onChange={(event) => setPassword(event.target.value)}
        />
      </div>

      {error ? <StatePanel tone="error" title={error} /> : null}

      <button type="submit" className="button" disabled={submitting}>
        {submitting ? messages.auth.signingIn : messages.auth.signIn}
      </button>
    </form>
  )
}
