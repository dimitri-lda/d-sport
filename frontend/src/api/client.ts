const API_BASE_URL = '/api';
const DEFAULT_TIMEOUT_MS = 3_000;

export type ApiFields = Record<string, string[]>;

export type RequestOptions = {
  method?: 'GET' | 'POST' | 'PUT' | 'PATCH' | 'DELETE';
  body?: unknown;
  signal?: AbortSignal;
  timeoutMs?: number;
  csrfToken?: string;
};

export class ApiError extends Error {
  readonly code: string;
  readonly status: number | undefined;
  readonly retryable: boolean;
  readonly fields: ApiFields | undefined;

  constructor(
    code: string,
    message: string,
    options: { status?: number; retryable?: boolean; fields?: ApiFields } = {},
  ) {
    super(message);
    this.name = 'ApiError';
    this.code = code;
    this.status = options.status;
    this.retryable = options.retryable ?? false;
    this.fields = options.fields;
  }
}

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

function errorFields(payload: unknown): ApiFields | undefined {
  if (
    typeof payload !== 'object' ||
    payload === null ||
    !('error' in payload)
  ) {
    return undefined;
  }
  const error = payload.error;
  if (typeof error !== 'object' || error === null || !('fields' in error)) {
    return undefined;
  }
  const fields = error.fields;
  if (typeof fields !== 'object' || fields === null || Array.isArray(fields)) {
    return undefined;
  }

  const result: ApiFields = {};
  for (const [key, value] of Object.entries(fields)) {
    if (!Array.isArray(value)) {
      return undefined;
    }
    result[key] = value.filter(
      (entry): entry is string => typeof entry === 'string',
    );
  }

  return result;
}

/**
 * Same-origin API requests under /api. Server error messages and response
 * bodies never enter the UI — failures surface as safe ApiError codes.
 */
export async function request<T>(
  path: `/${string}`,
  options: RequestOptions = {},
): Promise<T> {
  const {
    method = 'GET',
    body,
    signal,
    timeoutMs = DEFAULT_TIMEOUT_MS,
    csrfToken,
  } = options;
  const isMutation = method !== 'GET';

  const timeoutController = new AbortController();
  const timeout = setTimeout(() => timeoutController.abort(), timeoutMs);
  const requestSignal = signal
    ? AbortSignal.any([signal, timeoutController.signal])
    : timeoutController.signal;

  const headers: Record<string, string> = { Accept: 'application/json' };
  if (isMutation) {
    if (body !== undefined) {
      headers['Content-Type'] = 'application/json';
    }
    if (csrfToken !== undefined) {
      headers['X-CSRF-Token'] = csrfToken;
    }
  }

  try {
    const response = await fetch(`${API_BASE_URL}${path}`, {
      method,
      headers,
      credentials: 'same-origin',
      cache: 'no-store',
      signal: requestSignal,
      body: isMutation && body !== undefined ? JSON.stringify(body) : null,
    });
    if (response.status === 204) {
      return undefined as T;
    }

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
          fields: errorFields(payload),
        },
      );
    }

    if (payload === null) {
      throw new ApiError('invalid_response', 'API вернул некорректный ответ.');
    }

    return payload as T;
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

/** GET compatibility wrapper used by health checks and legacy call sites. */
export async function getJson(
  path: `/${string}`,
  options: Pick<RequestOptions, 'signal' | 'timeoutMs'> = {},
): Promise<unknown> {
  return request<unknown>(path, { ...options, method: 'GET' });
}
