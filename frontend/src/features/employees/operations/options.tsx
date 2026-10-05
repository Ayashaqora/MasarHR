import { useI18n } from '../../../i18n/context'
import { describeApiError } from '../../../shared/api/errorMessage'
import { FormField } from '../../../shared/ui/FormField'
import { NativeSelect } from '../../../shared/ui/NativeSelect'
import { RetryButton } from '../../../shared/ui/RetryButton'
import type { ReferenceOption } from './api'
import type { useUnitOptions } from './optionHooks'

/** A required reference <select> with its own loading / error / empty handling and a visible label. */
export function OptionSelect({
  label,
  helper,
  value,
  onChange,
  error,
  state,
  optionLabel,
}: {
  label: string
  helper?: string
  value: string
  onChange: (value: string) => void
  error: string | null
  state: ReturnType<typeof useUnitOptions>
  optionLabel: (option: ReferenceOption) => string
}) {
  const { messages } = useI18n()

  if (state.status === 'loading') {
    return (
      <FormField label={label} required helper={messages.operations.optionsLoading}>
        {(props) => (
          <NativeSelect {...props} disabled value="">
            <option value="">{messages.operations.optionsLoading}</option>
          </NativeSelect>
        )}
      </FormField>
    )
  }

  if (state.status === 'error') {
    return (
      <FormField
        label={label}
        required
        error={state.error.status === 403 ? messages.securityShared.unauthorizedDescription : `${messages.operations.optionsFailed} ${describeApiError(state.error, messages)}`}
      >
        {(props) => (
          <div className="flex items-center gap-2">
            <NativeSelect {...props} disabled value="">
              <option value="">{messages.operations.selectPlaceholder}</option>
            </NativeSelect>
            {state.error.status === 403 ? null : <RetryButton onClick={state.retry} />}
          </div>
        )}
      </FormField>
    )
  }

  const options = state.data

  return (
    <FormField label={label} required helper={options.length === 0 ? messages.operations.optionsEmpty : helper} error={error}>
      {(props) => (
        <NativeSelect {...props} value={value} onChange={(event) => onChange(event.target.value)}>
          <option value="">{messages.operations.selectPlaceholder}</option>
          {options.map((option) => (
            <option key={option.id} value={option.id}>
              {optionLabel(option)}
            </option>
          ))}
        </NativeSelect>
      )}
    </FormField>
  )
}
