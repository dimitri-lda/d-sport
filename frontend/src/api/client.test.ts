import { describe, expect, it, vi } from 'vitest';
import { ApiError, getJson, request } from './client';
import { getLiveness } from './health';

describe('API client', () => {
  it('requests health from the same origin under /api', async () => {
    const fetch = vi.fn().mockResolvedValue(
      new Response(JSON.stringify({ status: 'ok' }), {
        headers: { 'Content-Type': 'application/json' },
      }),
    );
    vi.stubGlobal('fetch', fetch);

    await expect(getLiveness()).resolves.toEqual({ status: 'ok' });
    expect(fetch).toHaveBeenCalledWith(
      '/api/health/live',
      expect.objectContaining({
        headers: { Accept: 'application/json' },
        credentials: 'same-origin',
      }),
    );
  });

  it('does not expose a server error message or response body', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn().mockResolvedValue(
        new Response(
          JSON.stringify({
            error: { code: 'internal_error', message: 'postgres://secret' },
          }),
          { status: 503 },
        ),
      ),
    );

    await expect(getJson('/health/live')).rejects.toMatchObject({
      code: 'internal_error',
      message: 'The service is temporarily unavailable.',
      status: 503,
      retryable: true,
    });
  });

  it('does not retry a missing endpoint', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn().mockResolvedValue(new Response('Not found', { status: 404 })),
    );

    await expect(getJson('/missing')).rejects.toMatchObject({
      status: 404,
      retryable: false,
    });
  });

  it('rejects an unexpected health response instead of reporting success', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn().mockResolvedValue(new Response('{"status":"unavailable"}')),
    );

    await expect(getLiveness()).rejects.toMatchObject({
      code: 'invalid_response',
      retryable: false,
    });
  });

  it('turns transport failures into a safe network error', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn().mockRejectedValue(new TypeError('private host')),
    );

    await expect(getJson('/health/live')).rejects.toMatchObject({
      code: 'network_error',
      message: 'Could not reach the API.',
      retryable: true,
    });
  });

  it('aborts a stalled request after the configured timeout', async () => {
    vi.useFakeTimers();
    vi.stubGlobal(
      'fetch',
      vi.fn((_url: string, options: RequestInit) => {
        return new Promise<Response>((_resolve, reject) => {
          options.signal?.addEventListener('abort', () => {
            reject(new DOMException('Aborted', 'AbortError'));
          });
        });
      }),
    );

    const request = getJson('/health/live', { timeoutMs: 100 });
    const assertion = expect(request).rejects.toMatchObject({
      code: 'request_timeout',
      retryable: true,
    });
    await vi.advanceTimersByTimeAsync(100);
    await assertion;
  });

  it('applies the timeout while waiting for the response body', async () => {
    vi.useFakeTimers();
    vi.stubGlobal(
      'fetch',
      vi.fn((_url: string, options: RequestInit) =>
        Promise.resolve({
          ok: true,
          status: 200,
          json: () =>
            new Promise((_resolve, reject) => {
              options.signal?.addEventListener('abort', () => {
                reject(new DOMException('Aborted', 'AbortError'));
              });
            }),
        }),
      ),
    );

    const request = getJson('/health/live', { timeoutMs: 100 });
    const assertion = expect(request).rejects.toMatchObject({
      code: 'request_timeout',
      retryable: true,
    });
    await vi.advanceTimersByTimeAsync(100);
    await assertion;
  });

  it('does not retry a request cancelled by its caller', async () => {
    const controller = new AbortController();
    controller.abort();
    vi.stubGlobal(
      'fetch',
      vi.fn().mockRejectedValue(new DOMException('Aborted', 'AbortError')),
    );

    await expect(
      getJson('/health/live', { signal: controller.signal }),
    ).rejects.toEqual(expect.any(ApiError));
    await expect(
      getJson('/health/live', { signal: controller.signal }),
    ).rejects.toMatchObject({ code: 'request_cancelled', retryable: false });
  });
});

describe('API client mutations', () => {
  it('sends JSON body and CSRF token on mutating requests', async () => {
    const fetch = vi.fn().mockResolvedValue(
      new Response(JSON.stringify({ data: { ok: true } }), {
        headers: { 'Content-Type': 'application/json' },
      }),
    );
    vi.stubGlobal('fetch', fetch);

    await request('/v1/preferences', {
      method: 'PUT',
      body: { country: 'PL', locale: 'pl' },
      csrfToken: 'csrf-123',
    });

    expect(fetch).toHaveBeenCalledWith(
      '/api/v1/preferences',
      expect.objectContaining({
        method: 'PUT',
        credentials: 'same-origin',
        body: JSON.stringify({ country: 'PL', locale: 'pl' }),
      }),
    );
    const [, init] = fetch.mock.calls[0] as [string, RequestInit];
    expect(init.headers).toMatchObject({
      'Content-Type': 'application/json',
      'X-CSRF-Token': 'csrf-123',
    });
  });

  it('does not attach CSRF headers to GET requests', async () => {
    const fetch = vi.fn().mockResolvedValue(
      new Response(JSON.stringify({ data: {} }), {
        headers: { 'Content-Type': 'application/json' },
      }),
    );
    vi.stubGlobal('fetch', fetch);

    await request('/v1/context', { csrfToken: 'csrf-123' });
    const [, init] = fetch.mock.calls[0] as [string, RequestInit];
    expect(init.headers).not.toMatchObject({ 'X-CSRF-Token': 'csrf-123' });
  });

  it('parses a 204 response as undefined', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn().mockResolvedValue(new Response(null, { status: 204 })),
    );

    await expect(
      request<undefined>('/v1/cart/items/1', {
        method: 'DELETE',
        csrfToken: 'x',
      }),
    ).resolves.toBeUndefined();
  });

  it('maps validation error fields into ApiError.fields', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn().mockResolvedValue(
        new Response(
          JSON.stringify({
            error: {
              code: 'validation_failed',
              message: 'Invalid.',
              fields: { country: ['The selected country is not supported.'] },
            },
          }),
          { status: 422 },
        ),
      ),
    );

    await expect(
      request('/v1/preferences', { method: 'PUT', body: {} }),
    ).rejects.toMatchObject({
      code: 'validation_failed',
      status: 422,
      fields: { country: ['The selected country is not supported.'] },
    });
  });

  it('ignores malformed field payloads', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn().mockResolvedValue(
        new Response(
          JSON.stringify({
            error: { code: 'validation_failed', fields: ['nope'] },
          }),
          { status: 422 },
        ),
      ),
    );

    await expect(
      request('/v1/preferences', { method: 'PUT', body: {} }),
    ).rejects.toMatchObject({ code: 'validation_failed', fields: undefined });
  });
});
