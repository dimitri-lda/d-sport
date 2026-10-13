import { useQuery } from '@tanstack/react-query';
import { ApiError } from '../api/client';
import { getLiveness } from '../api/health';
import { useApp } from '../app/useApp';

export function BackendStatus() {
  const { t } = useApp();
  const health = useQuery({
    queryKey: ['health', 'live'],
    queryFn: ({ signal }) => getLiveness(signal),
  });

  let status = t.backend_status.available;
  if (health.isFetching) {
    status = t.backend_status.checking;
  } else if (health.isError) {
    // Render from the dictionary keyed by ApiError.code — never
    // error.message, which is a developer-facing diagnostic.
    const code = health.error instanceof ApiError ? health.error.code : null;
    status =
      (code !== null ? t.backend_status.errors[code] : undefined) ??
      t.backend_status.unreachable;
  }

  return (
    <section
      aria-labelledby="backend-status-heading"
      className="rounded-lg border border-slate-200 bg-white p-6"
    >
      <h2 id="backend-status-heading" className="text-lg font-semibold">
        {t.backend_status.heading}
      </h2>
      <p role="status" aria-live="polite" className="mt-3 text-slate-700">
        {status}
      </p>
      <p className="mt-2 text-sm text-slate-500">
        {t.backend_status.explainer}
      </p>
      <button
        type="button"
        disabled={health.isFetching}
        onClick={() => void health.refetch()}
        className="mt-5 rounded-md border border-slate-300 px-4 py-2 text-sm font-medium hover:bg-slate-50 disabled:cursor-wait disabled:opacity-50"
      >
        {health.isError ? t.backend_status.retry : t.backend_status.check_again}
      </button>
    </section>
  );
}
