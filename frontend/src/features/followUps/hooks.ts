import { useApiResource, type ApiResourceState } from '../../shared/hooks/useApiResource'
import {
  fetchEmploymentStatusExpiryFollowUps,
  fetchMovementExpiryFollowUps,
  type EmploymentStatusExpiryFollowUp,
  type FollowUpPage,
  type FollowUpStateFilter,
  type MovementExpiryFollowUp,
} from './api'

export interface FollowUpQuery {
  state: FollowUpStateFilter
  page: number
  /**
   * False when this tab is not the active one, or the viewer lacks its permission. `useApiResource`
   * is given a null fetcher in that case, so no request is ever made for a non-permitted or inactive
   * tab (spec §5.2) — this is not a side effect of hiding the content, it is the only thing that
   * decides whether a request happens at all.
   */
  enabled: boolean
}

export function useMovementExpiryFollowUps(query: FollowUpQuery): ApiResourceState<FollowUpPage<MovementExpiryFollowUp>> & { retry: () => void } {
  return useApiResource(
    query.enabled ? (signal) => fetchMovementExpiryFollowUps({ state: query.state, page: query.page }, signal) : null,
    [query.state, query.page, query.enabled],
  )
}

export function useEmploymentStatusExpiryFollowUps(
  query: FollowUpQuery,
): ApiResourceState<FollowUpPage<EmploymentStatusExpiryFollowUp>> & { retry: () => void } {
  return useApiResource(
    query.enabled ? (signal) => fetchEmploymentStatusExpiryFollowUps({ state: query.state, page: query.page }, signal) : null,
    [query.state, query.page, query.enabled],
  )
}
