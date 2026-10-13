import { ApiError, getJson } from './client';

export async function getLiveness(
  signal?: AbortSignal,
): Promise<{ status: 'ok' }> {
  const payload = await getJson('/health/live', { signal });

  if (
    typeof payload !== 'object' ||
    payload === null ||
    !('status' in payload) ||
    payload.status !== 'ok'
  ) {
    throw new ApiError('invalid_response', 'API вернул некорректный ответ.');
  }

  return { status: 'ok' };
}
