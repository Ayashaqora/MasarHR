import { render, screen } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'
import { I18nProvider } from '../i18n/I18nProvider'
import { ErrorBoundary } from './ErrorBoundary'

function Boom(): never {
  throw new Error('render failure')
}

describe('ErrorBoundary', () => {
  it('renders children when nothing fails', () => {
    render(
      <I18nProvider>
        <ErrorBoundary>
          <p>ok</p>
        </ErrorBoundary>
      </I18nProvider>,
    )

    expect(screen.getByText('ok')).toBeInTheDocument()
  })

  it('shows the localized fallback instead of a blank page when a child throws', () => {
    vi.spyOn(console, 'error').mockImplementation(() => {})

    render(
      <I18nProvider>
        <ErrorBoundary>
          <Boom />
        </ErrorBoundary>
      </I18nProvider>,
    )

    expect(screen.getByRole('alert')).toHaveTextContent('حدث خطأ غير متوقع')
    expect(screen.getByRole('button', { name: 'إعادة تحميل الصفحة' })).toBeInTheDocument()
    expect(screen.queryByText('render failure')).not.toBeInTheDocument()
  })
})
