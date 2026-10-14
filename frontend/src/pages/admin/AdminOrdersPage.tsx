import { useQuery } from '@tanstack/react-query';
import { Link, useSearchParams } from 'react-router';
import { getAdminOrders } from '../../api/admin';
import type { AdminOrdersFilters } from '../../api/admin';
import { useApp } from '../../app/useApp';
import { SelectShell } from '../../components/forms/SelectShell';
import { format } from '../../lib/i18n/dictionaries';
import { formatMoney } from '../../lib/i18n/format';

function dateLabel(value: string | null, locale: string): string {
  if (value === null) {
    return '—';
  }
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) {
    return '—';
  }

  return new Intl.DateTimeFormat(locale, {
    dateStyle: 'medium',
    timeStyle: 'short',
  }).format(date);
}

/**
 * Admin order list: filter state lives in the URL so results are linkable.
 * Table on desktop, stacked cards on mobile.
 */
export function AdminOrdersPage() {
  const { t, locale } = useApp();
  const [searchParams, setSearchParams] = useSearchParams();

  const filters: AdminOrdersFilters = {
    q: searchParams.get('q') ?? '',
    status: searchParams.get('status') ?? '',
    from: searchParams.get('from') ?? '',
    to: searchParams.get('to') ?? '',
    page: Number(searchParams.get('page') ?? '1') || 1,
  };

  const orders = useQuery({
    queryKey: ['admin', 'orders', filters],
    queryFn: ({ signal }) => getAdminOrders(filters, signal),
    placeholderData: (previous) => previous,
  });

  function patchParams(patch: Record<string, string>): void {
    const next = new URLSearchParams(searchParams);
    for (const [key, value] of Object.entries(patch)) {
      if (value === '') {
        next.delete(key);
      } else {
        next.set(key, value);
      }
    }
    setSearchParams(next);
  }

  function setFilter(key: 'q' | 'status' | 'from' | 'to', value: string): void {
    // Any filter change restarts pagination.
    patchParams({ [key]: value, page: '' });
  }

  const meta = orders.data?.meta;
  const totalPages =
    meta === undefined ? 1 : Math.max(1, Math.ceil(meta.total / meta.per_page));

  const inputClass = `rounded-md border border-dune bg-white px-3 py-2 text-sm
    text-ink outline-none transition-colors focus:border-cobalt
    focus:ring-2 focus:ring-cobalt/30`;

  return (
    <div>
      <h1 className="font-display text-2xl font-bold">
        {t.admin.orders_title}
      </h1>

      <form
        className="mt-4 flex flex-wrap items-end gap-3"
        onSubmit={(event) => event.preventDefault()}
      >
        <label className="flex flex-col gap-1 text-xs font-medium text-ink-mute">
          {t.admin.filter_q}
          <input
            type="search"
            value={filters.q}
            onChange={(event) => setFilter('q', event.target.value)}
            placeholder={t.admin.filter_q_placeholder}
            className={inputClass}
          />
        </label>
        <label className="flex flex-col gap-1 text-xs font-medium text-ink-mute">
          {t.admin.filter_status}
          <SelectShell>
            <select
              value={filters.status}
              onChange={(event) => setFilter('status', event.target.value)}
              className="w-full appearance-none rounded-md border border-dune
                bg-white pl-3 pr-10 py-2 text-sm text-ink outline-none
                transition-colors focus:border-cobalt focus:ring-2
                focus:ring-cobalt/30"
            >
              <option value="">{t.admin.filter_status_all}</option>
              {(orders.data?.statuses ?? []).map((status) => (
                <option key={status} value={status}>
                  {t.orders.statuses[status] ?? status}
                </option>
              ))}
            </select>
          </SelectShell>
        </label>
        <label className="flex flex-col gap-1 text-xs font-medium text-ink-mute">
          {t.admin.filter_from}
          <input
            type="date"
            value={filters.from}
            onChange={(event) => setFilter('from', event.target.value)}
            className={inputClass}
          />
        </label>
        <label className="flex flex-col gap-1 text-xs font-medium text-ink-mute">
          {t.admin.filter_to}
          <input
            type="date"
            value={filters.to}
            onChange={(event) => setFilter('to', event.target.value)}
            className={inputClass}
          />
        </label>
      </form>

      {orders.isPending ? (
        <p className="py-16 text-center text-ink-mute">{t.common.loading}</p>
      ) : orders.isError || orders.data === undefined ? (
        <p role="alert" className="py-16 text-center text-ink-mute">
          {t.common.error}
        </p>
      ) : orders.data.items.length === 0 ? (
        <p className="py-16 text-center text-ink-mute">
          {t.admin.orders_empty}
        </p>
      ) : (
        <>
          {/* Desktop table */}
          <div
            className="mt-6 hidden overflow-x-auto rounded-2xl border
              border-dune/60 md:block"
          >
            <table className="w-full text-left text-sm">
              <thead className="bg-sand/40 text-xs uppercase text-ink-mute">
                <tr>
                  <th className="px-4 py-3">{t.admin.col_number}</th>
                  <th className="px-4 py-3">{t.admin.col_email}</th>
                  <th className="px-4 py-3">{t.admin.col_date}</th>
                  <th className="px-4 py-3 text-right">{t.admin.col_total}</th>
                  <th className="px-4 py-3">{t.admin.col_status}</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-dune/60">
                {orders.data.items.map((order) => (
                  <tr key={order.id}>
                    <td className="px-4 py-3 font-medium">
                      <Link
                        to={`/admin/orders/${order.id}`}
                        className="text-cobalt underline"
                      >
                        {order.number}
                      </Link>
                    </td>
                    <td className="px-4 py-3">{order.email}</td>
                    <td className="px-4 py-3 text-ink-mute">
                      {dateLabel(order.created_at, locale)}
                    </td>
                    <td className="px-4 py-3 text-right font-medium">
                      {formatMoney(order.total_cents, order.currency, locale)}
                    </td>
                    <td className="px-4 py-3">
                      <span
                        className="rounded-full bg-sand px-3 py-1 text-xs
                          font-semibold uppercase tracking-wide"
                      >
                        {t.orders.statuses[order.status] ?? order.status}
                      </span>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>

          {/* Mobile cards */}
          <ul className="mt-6 flex flex-col gap-3 md:hidden">
            {orders.data.items.map((order) => (
              <li
                key={order.id}
                className="rounded-2xl border border-dune/60 p-4"
              >
                <div className="flex items-center justify-between gap-3">
                  <Link
                    to={`/admin/orders/${order.id}`}
                    className="font-medium text-cobalt underline"
                  >
                    {order.number}
                  </Link>
                  <span
                    className="rounded-full bg-sand px-3 py-1 text-xs
                      font-semibold uppercase tracking-wide"
                  >
                    {t.orders.statuses[order.status] ?? order.status}
                  </span>
                </div>
                <p className="mt-2 truncate text-sm">{order.email}</p>
                <p className="mt-1 flex justify-between text-sm text-ink-mute">
                  <span>{dateLabel(order.created_at, locale)}</span>
                  <span className="font-medium text-ink">
                    {formatMoney(order.total_cents, order.currency, locale)}
                  </span>
                </p>
              </li>
            ))}
          </ul>

          {meta !== undefined && totalPages > 1 ? (
            <nav
              aria-label={t.admin.pagination_label}
              className="mt-6 flex items-center justify-center gap-3 text-sm"
            >
              <button
                type="button"
                disabled={meta.page <= 1}
                onClick={() => patchParams({ page: String(meta.page - 1) })}
                className="rounded-full border border-dune px-4 py-1.5
                  disabled:opacity-40"
              >
                {t.admin.page_prev}
              </button>
              <span className="text-ink-mute">
                {format(t.admin.page_of, {
                  page: meta.page,
                  pages: totalPages,
                })}
              </span>
              <button
                type="button"
                disabled={meta.page >= totalPages}
                onClick={() => patchParams({ page: String(meta.page + 1) })}
                className="rounded-full border border-dune px-4 py-1.5
                  disabled:opacity-40"
              >
                {t.admin.page_next}
              </button>
            </nav>
          ) : null}
        </>
      )}
    </div>
  );
}
