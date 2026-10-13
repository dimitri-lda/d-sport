import { describe, expect, it, vi } from 'vitest';
import { ApiError, getJson } from './client';
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
      message: 'Сервис временно недоступен.',
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
      message: 'Нет соединения с API.',
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
