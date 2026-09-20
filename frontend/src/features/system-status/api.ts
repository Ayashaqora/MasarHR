import { apiRequest } from '../../shared/api'

export interface HealthResponse {
  status: 'ok'
  service: string
  version: string
  timestamp: string
}

export function fetchHealth(signal?: AbortSignal): Promise<HealthResponse> {
  return apiRequest<HealthResponse>('/health', signal ? { signal } : {})
}
