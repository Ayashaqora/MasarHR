import { useApiResource, type ApiResourceState } from '../../shared/hooks/useApiResource'
import { fetchWorkforceAnalytics, type WorkforceAnalytics } from './api'

/**
 * The Dashboard's single data hook: ONE request per selected reporting month. Widgets receive slices of the resolved
 * response as props and never call this (or the API) themselves (DB-D49).
 */
export function useWorkforceAnalytics(month: string): ApiResourceState<WorkforceAnalytics> & { retry: () => void } {
  return useApiResource((signal) => fetchWorkforceAnalytics(month, signal), [month])
}
