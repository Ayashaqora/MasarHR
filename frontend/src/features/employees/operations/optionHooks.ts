import { useMemo } from 'react'
import { useI18n } from '../../../i18n/context'
import { useApiResource } from '../../../shared/hooks/useApiResource'
import { fetchDecisionTypes, fetchOrganizationalUnitOptions, type ReferenceOption } from './api'

export function useUnitOptions() {
  const state = useApiResource((signal) => fetchOrganizationalUnitOptions(signal), [])
  return state
}

/** Decision types are chosen by their stable code (the backend validates `code`, never the display text). */
export function useDecisionTypeOptions(requiredCode: 'TRANSFER' | 'ASSIGNMENT' | null) {
  const state = useApiResource(requiredCode ? (signal) => fetchDecisionTypes(signal) : null, [requiredCode])
  return useMemo(() => {
    if (state.status !== 'success') {
      return state
    }
    return {
      ...state,
      data: state.data.filter((row) => row.code === requiredCode && row.is_active !== false),
    }
  }, [state, requiredCode])
}

export function unitOptionLabel(option: ReferenceOption): string {
  return option.name ?? option.id
}

export function useLocalizedOptionLabel(): (option: ReferenceOption) => string {
  const { locale } = useI18n()
  return (option) => (locale === 'ar' ? option.name_ar : option.name_en) || option.name_ar || option.name_en || option.code || option.id
}
