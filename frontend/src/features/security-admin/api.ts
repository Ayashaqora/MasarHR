import { apiRequest } from '../../shared/api'

export type PrincipalStatus = 'ACTIVE' | 'DISABLED'

export interface PrincipalSummary {
  id: string
  username: string
  display_name: string
  status: PrincipalStatus
  version: number
  created_at: string | null
  updated_at: string | null
}

export interface RoleSummary {
  id: string
  code: string
  name_ar: string
  name_en: string
  description: string | null
  is_system: boolean
  is_active: boolean
  version: number
  created_at: string | null
  updated_at: string | null
}

export interface PermissionSummary {
  id: string
  code: string
  module: string
  description: string | null
  created_at: string | null
}

/** List endpoints paginate (see PrincipalController::index et al.) and always wrap in "data". */
interface PaginatedResponse<T> {
  data: T[]
}

export async function fetchPrincipals(signal?: AbortSignal): Promise<PrincipalSummary[]> {
  const page = await apiRequest<PaginatedResponse<PrincipalSummary>>(
    '/security/principals',
    signal ? { signal } : {},
  )
  return page.data
}

export async function fetchRoles(signal?: AbortSignal): Promise<RoleSummary[]> {
  const page = await apiRequest<PaginatedResponse<RoleSummary>>('/security/roles', signal ? { signal } : {})
  return page.data
}

export async function fetchPermissions(signal?: AbortSignal): Promise<PermissionSummary[]> {
  const page = await apiRequest<PaginatedResponse<PermissionSummary>>(
    '/security/permissions',
    signal ? { signal } : {},
  )
  return page.data
}

/** A single-resource write response is never wrapped (see AppServiceProvider::boot() on the backend). */
export function updatePrincipalStatus(
  principalId: string,
  status: PrincipalStatus,
  expectedVersion: number,
): Promise<PrincipalSummary> {
  return apiRequest<PrincipalSummary>(`/security/principals/${principalId}/status`, {
    method: 'PATCH',
    body: { status, expected_version: expectedVersion },
  })
}
