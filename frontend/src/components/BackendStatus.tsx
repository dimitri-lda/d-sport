import { useQuery } from '@tanstack/react-query';
import { ApiError } from '../api/client';
import { getLiveness } from '../api/health';

export function BackendStatus() {
  const health = useQuery({
    queryKey: ['health', 'live'],
    queryFn: ({ signal }) => getLiveness(signal),
  });

  let status = 'API доступен';
  if (health.isFetching) {
    status = 'Проверяем состояние API…';
  } else if (health.isError) {
    status =
      health.error instanceof ApiError
        ? health.error.message
        : 'Не удалось проверить состояние API.';
  }

  return (
    <section
      aria-labelledby="backend-status-heading"
      className="rounded-lg border border-slate-200 bg-white p-6"
    >
      <h2 id="backend-status-heading" className="text-lg font-semibold">
        Состояние backend
      </h2>
      <p role="status" aria-live="polite" className="mt-3 text-slate-700">
        {status}
      </p>
      <p className="mt-2 text-sm text-slate-500">
        Liveness подтверждает работу HTTP-приложения. Доступность PostgreSQL и
        Redis проверяется отдельным readiness endpoint.
      </p>
      <button
        type="button"
        disabled={health.isFetching}
        onClick={() => void health.refetch()}
        className="mt-5 rounded-md border border-slate-300 px-4 py-2 text-sm font-medium hover:bg-slate-50 disabled:cursor-wait disabled:opacity-50"
      >
        {health.isError ? 'Повторить проверку' : 'Проверить снова'}
      </button>
    </section>
  );
}
