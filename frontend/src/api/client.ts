const API_BASE_URL = '/api';
const DEFAULT_TIMEOUT_MS = 3_000;

export class ApiError extends Error {
  readonly code: string;
  readonly status: number | undefined;
  readonly retryable: boolean;

  constructor(
    code: string,
    message: string,
    options: { status?: number; retryable?: boolean } = {},
  ) {
    super(message);
    this.name = 'ApiError';
    this.code = code;
    this.status = options.status;
    this.retryable = options.retryable ?? false;
  }
}

type RequestOptions = {
  signal?: AbortSignal;
  timeoutMs?: number;
};

function errorCode(payload: unknown): string {
  if (
    typeof payload !== 'object' ||
    payload === null ||
    !('error' in payload)
  ) {
    return 'http_error';
  }

  const error = payload.error;
  if (typeof error !== 'object' || error === null || !('code' in error)) {
    return 'http_error';
  }

  return typeof error.code === 'string' && /^[a-z0-9_]{1,80}$/.test(error.code)
    ? error.code
    : 'http_error';
}

/** Same-origin API requests; server messages and response bodies never enter the UI. */
export async function getJson(
  path: `/${string}`,
  { signal, timeoutMs = DEFAULT_TIMEOUT_MS }: RequestOptions = {},
): Promise<unknown> {
  const timeoutController = new AbortController();
  const timeout = setTimeout(() => timeoutController.abort(), timeoutMs);
  const requestSignal = signal
    ? AbortSignal.any([signal, timeoutController.signal])
    : timeoutController.signal;

  try {
    const response = await fetch(`${API_BASE_URL}${path}`, {
      method: 'GET',
      headers: { Accept: 'application/json' },
      credentials: 'same-origin',
      cache: 'no-store',
      signal: requestSignal,
    });
    const payload: unknown = await response.json().catch((error: unknown) => {
      // A cancelled response body is still a cancelled request, not invalid JSON.
      if (requestSignal.aborted) {
        throw error;
      }
      return null;
    });

    if (!response.ok) {
      throw new ApiError(
        errorCode(payload),
        response.status >= 500
          ? 'Сервис временно недоступен.'
          : 'Не удалось выполнить запрос к API.',
        {
          status: response.status,
          retryable: response.status >= 500 || response.status === 429,
        },
      );
    }

    if (payload === null) {
      throw new ApiError('invalid_response', 'API вернул некорректный ответ.');
    }

    return payload;
  } catch (error) {
    if (error instanceof ApiError) {
      throw error;
    }

    if (signal?.aborted) {
      throw new ApiError('request_cancelled', 'Запрос отменён.');
    }

    if (timeoutController.signal.aborted) {
      throw new ApiError('request_timeout', 'Превышено время ожидания API.', {
        retryable: true,
      });
    }

    throw new ApiError('network_error', 'Нет соединения с API.', {
      retryable: true,
    });
  } finally {
    clearTimeout(timeout);
  }
}
