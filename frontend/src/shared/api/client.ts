import { env } from '../config/env'
import { ApiError, normalizeApiError, parseErrorBody } from './errors'

export interface ApiRequestOptions {
  method?: 'GET' | 'POST' | 'PUT' | 'PATCH' | 'DELETE'
  body?: unknown
  headers?: Record<string, string>
  signal?: AbortSignal
}

function buildUrl(path: string): string {
  return `${env.apiBaseUrl}/${path.replace(/^\/+/, '')}`
}

async function readJson(response: Response): Promise<unknown> {
  const text = await response.text()
  if (text === '') {
    return null
  }
  try {
    return JSON.parse(text)
  } catch (cause) {
    throw new ApiError({
      kind: 'parse',
      message: 'The server returned an invalid response.',
      status: response.status,
      cause,
    })
  }
}

/**
 * The only place that talks to the backend. Feature code passes paths relative to /api/v1
 * and always receives either parsed JSON or an ApiError.
 */
export async function apiRequest<T>(path: string, options: ApiRequestOptions = {}): Promise<T> {
  const { method = 'GET', body, headers, signal } = options
  const timeout = AbortSignal.timeout(env.requestTimeoutMs)

  let response: Response
  try {
    response = await fetch(buildUrl(path), {
      method,
      headers: {
        Accept: 'application/json',
        ...(body === undefined ? {} : { 'Content-Type': 'application/json' }),
        ...headers,
      },
      body: body === undefined ? undefined : JSON.stringify(body),
      signal: signal ? AbortSignal.any([signal, timeout]) : timeout,
    })
  } catch (error) {
    throw normalizeApiError(error)
  }

  const payload = await readJson(response)

  if (!response.ok) {
    const { message, fieldErrors } = parseErrorBody(payload)
    throw new ApiError({
      kind: 'http',
      message: message || `Request failed with status ${response.status}.`,
      status: response.status,
      fieldErrors,
    })
  }

  return payload as T
}
