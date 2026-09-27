import { describe, expect, it, vi } from 'vitest'
import { apiRequest } from './client'
import { ApiError, normalizeApiError, parseErrorBody } from './errors'
import { describeApiError } from './errorMessage'
import { ar } from '../../i18n/messages/ar'
import { jsonResponse, stubFetch } from '../../test/render'

describe('apiRequest', () => {
  it('calls the /api/v1 base URL and returns parsed JSON', async () => {
    const fetchMock = stubFetch(() => jsonResponse({ status: 'ok' }))

    await expect(apiRequest('/health')).resolves.toEqual({ status: 'ok' })

    const [url, init] = fetchMock.mock.calls[0] as unknown as [string, RequestInit]
    expect(url).toBe('/api/v1/health')
    expect(init.method).toBe('GET')
    expect((init.headers as Record<string, string>).Accept).toBe('application/json')
  })

  it('sends JSON bodies with a content type', async () => {
    const fetchMock = stubFetch(() => jsonResponse({}))

    await apiRequest('/things', { method: 'POST', body: { a: 1 } })

    const [, init] = fetchMock.mock.calls[0] as unknown as [string, RequestInit]
    expect(init.body).toBe('{"a":1}')
    expect((init.headers as Record<string, string>)['Content-Type']).toBe('application/json')
  })

  it('normalizes Laravel validation errors', async () => {
    stubFetch(() =>
      jsonResponse({ message: 'The given data was invalid.', errors: { name: ['required'] } }, 422),
    )

    const error = await apiRequest('/things').catch((e: unknown) => e)

    expect(error).toBeInstanceOf(ApiError)
    expect(error).toMatchObject({
      kind: 'http',
      status: 422,
      message: 'The given data was invalid.',
      fieldErrors: { name: ['required'] },
    })
  })

  it('always sends credentials so the session cookie is included', async () => {
    const fetchMock = stubFetch(() => jsonResponse({ status: 'ok' }))

    await apiRequest('/health')

    const [, init] = fetchMock.mock.calls[0] as unknown as [string, RequestInit]
    expect(init.credentials).toBe('include')
  })

  it('attaches the XSRF-TOKEN cookie as a header on mutating requests', async () => {
    document.cookie = 'XSRF-TOKEN=abc%20123'
    const fetchMock = stubFetch(() => jsonResponse({}))

    await apiRequest('/things', { method: 'POST', body: { a: 1 } })

    const [, init] = fetchMock.mock.calls[0] as unknown as [string, RequestInit]
    expect((init.headers as Record<string, string>)['X-XSRF-TOKEN']).toBe('abc 123')

    document.cookie = 'XSRF-TOKEN=; Max-Age=0'
  })

  it('never sends the XSRF-TOKEN header on a GET request', async () => {
    document.cookie = 'XSRF-TOKEN=abc123'
    const fetchMock = stubFetch(() => jsonResponse({}))

    await apiRequest('/things')

    const [, init] = fetchMock.mock.calls[0] as unknown as [string, RequestInit]
    expect((init.headers as Record<string, string>)['X-XSRF-TOKEN']).toBeUndefined()

    document.cookie = 'XSRF-TOKEN=; Max-Age=0'
  })

  it('normalizes a network failure', async () => {
    vi.stubGlobal('fetch', () => Promise.reject(new TypeError('Failed to fetch')))

    await expect(apiRequest('/health')).rejects.toMatchObject({ kind: 'network' })
  })

  it('normalizes a non-JSON response as a parse error', async () => {
    stubFetch(() => new Response('<html>oops</html>', { status: 200 }))

    await expect(apiRequest('/health')).rejects.toMatchObject({ kind: 'parse' })
  })

  it('falls back to a generic message when an error body has none', async () => {
    stubFetch(() => new Response('', { status: 503 }))

    await expect(apiRequest('/health')).rejects.toMatchObject({
      kind: 'http',
      status: 503,
      message: 'Request failed with status 503.',
    })
  })
})

describe('error normalization', () => {
  it('parseErrorBody tolerates unexpected shapes', () => {
    expect(parseErrorBody(null)).toEqual({ message: '' })
    expect(parseErrorBody('text')).toEqual({ message: '' })
    expect(parseErrorBody({ message: 5, errors: { a: 'x' } })).toEqual({ message: '', fieldErrors: {} })
  })

  it('normalizeApiError maps timeouts and unknown values', () => {
    expect(normalizeApiError(new DOMException('t', 'TimeoutError')).kind).toBe('timeout')
    expect(normalizeApiError('boom').kind).toBe('network')
  })

  it('describeApiError returns localized, user-safe text', () => {
    const server = new ApiError({ kind: 'http', status: 500, message: 'SQLSTATE[08006] leak' })
    const message = describeApiError(server, ar)

    expect(message).toBe(ar.errors.server)
    expect(message).not.toContain('SQLSTATE')
    expect(describeApiError(new ApiError({ kind: 'network', message: 'x' }), ar)).toBe(ar.errors.network)
  })
})
