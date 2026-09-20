import { Component, type ErrorInfo, type ReactNode } from 'react'
import { useI18n } from '../i18n/context'
import { StatePanel } from '../shared/ui/StatePanel'

/** Fallback shared by the React error boundary and the router's errorElement. */
export function ErrorFallback({ onReset }: { onReset?: () => void }) {
  const { messages } = useI18n()

  return (
    <div className="fatal-error">
      <StatePanel
        tone="error"
        title={messages.errors.title}
        action={
          <button type="button" className="button" onClick={onReset ?? (() => window.location.reload())}>
            {messages.errors.reload}
          </button>
        }
      >
        {messages.errors.description}
      </StatePanel>
    </div>
  )
}

interface ErrorBoundaryState {
  failed: boolean
}

/** Last line of defence: a render error anywhere below shows the fallback instead of a blank page. */
export class ErrorBoundary extends Component<{ children: ReactNode }, ErrorBoundaryState> {
  override state: ErrorBoundaryState = { failed: false }

  static getDerivedStateFromError(): ErrorBoundaryState {
    return { failed: true }
  }

  override componentDidCatch(error: Error, info: ErrorInfo): void {
    // Central hook point for future error reporting; kept to the console in S01.
    console.error('Unhandled UI error', error, info.componentStack)
  }

  override render(): ReactNode {
    return this.state.failed ? <ErrorFallback /> : this.props.children
  }
}
