import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Link, useParams } from 'react-router';
import { ApiError } from '../../api/client';
import { getAdminOrder, updateAdminOrderStatus } from '../../api/admin';
import type { Order } from '../../api/orders';
import { useApp } from '../../app/useApp';
import { FormError } from '../../components/forms/FormError';
import { format } from '../../lib/i18n/dictionaries';
import { formatMoney } from '../../lib/i18n/format';
import { toSubmissionError } from '../../lib/forms';

/**
 * Admin order detail: immutable snapshots plus a status transition control
 * constrained to the server-provided next_statuses.
 */
export function AdminOrderPage() {
  const { id = '' } = useParams();
  const { t } = useApp();
  const orderId = Number(id);

  const detail = useQuery({
    queryKey: ['admin', 'order', orderId],
    queryFn: ({ signal }) => getAdminOrder(orderId, signal),
    enabled: Number.isInteger(orderId) && orderId > 0,
    retry: (failureCount, error) =>
      failureCount < 1 && error instanceof ApiError && error.retryable,
  });

  if (detail.isPending) {
    return (
      <p className="py-16 text-center text-ink-mute">{t.common.loading}</p>
    );
  }

  if (detail.isError || detail.data === undefined) {
    const notFound =
      detail.error instanceof ApiError && detail.error.status === 404;
    return (
      <div className="py-16 text-center">
        <h1 className="font-display text-3xl font-bold">
          {notFound ? t.orders.not_found_title : t.common.error}
        </h1>
        <Link
          to="/admin/orders"
          className="mt-6 inline-block font-medium text-cobalt underline"
        >
          {t.admin.nav_orders}
        </Link>
      </div>
    );
  }

  return (
    <AdminOrderView
      order={detail.data.order}
      nextStatuses={detail.data.next_statuses}
    />
  );
}

function AdminOrderView({
  order,
  nextStatuses,
}: {
  order: Order;
  nextStatuses: string[];
}) {
  const { t, locale, csrfToken } = useApp();
  const queryClient = useQueryClient();
  const [selected, setSelected] = useState('');
  const [error, setError] = useState<string | null>(null);

  const transition = useMutation({
    mutationFn: (status: string) => {
      if (csrfToken === null) {
        return Promise.reject(new ApiError('missing_csrf', 'Missing CSRF.'));
      }
      return updateAdminOrderStatus(order.id, status, csrfToken);
    },
    onSuccess: async () => {
      setSelected('');
      setError(null);
      await queryClient.invalidateQueries({ queryKey: ['admin', 'orders'] });
      await queryClient.invalidateQueries({
        queryKey: ['admin', 'order', order.id],
      });
    },
    onError: (cause) => {
      const submission = toSubmissionError(cause);
      setError(
        submission.fields.status?.[0] ??
          (submission.generic ? t.admin.transition_error : null) ??
          t.admin.transition_error,
      );
    },
  });

  const money = (cents: number | null) =>
    cents === null ? '—' : formatMoney(cents, order.currency, locale);
  const placedAt =
    order.created_at === null
      ? null
      : new Intl.DateTimeFormat(locale, {
          dateStyle: 'long',
          timeStyle: 'short',
        }).format(new Date(order.created_at));

  return (
    <div>
      <Link
        to="/admin/orders"
        className="text-sm font-medium text-cobalt underline"
      >
        {t.admin.back_to_orders}
      </Link>

      <div className="mt-3 flex flex-wrap items-center gap-3">
        <h1 className="font-display text-2xl font-bold">
          {format(t.orders.title, { number: order.number })}
        </h1>
        <span
          className="rounded-full bg-sand px-3 py-1 text-xs font-semibold
            uppercase tracking-wide"
        >
          {t.orders.statuses[order.status] ?? order.status}
        </span>
      </div>
      {placedAt !== null ? (
        <p className="mt-1 text-sm text-ink-mute">
          {format(t.orders.placed_at, { date: placedAt })}
        </p>
      ) : null}

      <section
        aria-labelledby="admin-transition"
        className="mt-6 rounded-2xl border border-dune/60 p-4"
      >
        <h2 id="admin-transition" className="font-display text-lg font-bold">
          {t.admin.transition_section}
        </h2>
        <FormError message={error} />
        <div className="mt-3 flex flex-wrap items-center gap-3">
          <select
            aria-label={t.admin.transition_select}
            value={selected}
            onChange={(event) => setSelected(event.target.value)}
            className="rounded-md border border-dune bg-white px-3 py-2 text-sm
              text-ink outline-none focus:border-cobalt focus:ring-2
              focus:ring-cobalt/30"
          >
            <option value="">{t.admin.transition_placeholder}</option>
            {nextStatuses.map((status) => (
              <option key={status} value={status}>
                {t.orders.statuses[status] ?? status}
              </option>
            ))}
          </select>
          <button
            type="button"
            disabled={selected === '' || transition.isPending}
            onClick={() => transition.mutate(selected)}
            className="rounded-full bg-cobalt px-5 py-2 text-sm font-semibold
              text-cream disabled:opacity-40"
          >
            {transition.isPending ? t.common.loading : t.admin.transition_apply}
          </button>
        </div>
        {nextStatuses.length === 0 ? (
          <p className="mt-2 text-xs text-ink-mute">
            {t.admin.transition_terminal}
          </p>
        ) : null}
      </section>

      <div className="mt-6 grid gap-8 lg:grid-cols-[1fr_340px]">
        <div className="flex flex-col gap-8">
          <section aria-labelledby="admin-order-items">
            <h2
              id="admin-order-items"
              className="font-display text-lg font-bold"
            >
              {t.orders.items_section}
            </h2>
            <ul
              className="mt-3 divide-y divide-dune/60 rounded-2xl border
                border-dune/60"
            >
              {order.items.map((item) => (
                <li
                  key={item.id}
                  className="flex items-center justify-between gap-4 px-4 py-3"
                >
                  <div className="min-w-0">
                    <p className="truncate font-medium">{item.name}</p>
                    <p className="text-sm text-ink-mute">
                      {item.variant_name} × {item.quantity}
                    </p>
                  </div>
                  <p className="font-medium">{money(item.line_total_cents)}</p>
                </li>
              ))}
            </ul>
          </section>

          {order.address !== null ? (
            <section aria-labelledby="admin-order-address">
              <h2
                id="admin-order-address"
                className="font-display text-lg font-bold"
              >
                {t.orders.address_section}
              </h2>
              <address
                className="mt-3 rounded-2xl border border-dune/60 p-4 text-sm
                  not-italic"
              >
                <p className="font-medium">
                  {order.address.first_name} {order.address.last_name}
                </p>
                <p>{order.address.line1}</p>
                {order.address.line2 !== null ? (
                  <p>{order.address.line2}</p>
                ) : null}
                <p>
                  {order.address.postal_code} {order.address.city}
                </p>
                <p>{order.address.country}</p>
                <p className="mt-2 text-ink-mute">
                  {order.email} · {order.phone}
                </p>
              </address>
            </section>
          ) : null}
        </div>

        <aside className="h-fit rounded-2xl border border-dune/60 p-5">
          <h2 className="font-display text-lg font-bold">
            {t.orders.totals_section}
          </h2>
          <dl className="mt-3 flex flex-col gap-2 text-sm">
            <div className="flex justify-between">
              <dt className="text-ink-mute">{t.checkout.subtotal}</dt>
              <dd>{money(order.subtotal_cents)}</dd>
            </div>
            <div className="flex justify-between">
              <dt className="text-ink-mute">{t.checkout.delivery}</dt>
              <dd>{money(order.delivery_cents)}</dd>
            </div>
            <div className="flex justify-between">
              <dt className="text-ink-mute">{t.checkout.tax_included}</dt>
              <dd>
                {money(
                  (order.tax_cents ?? 0) + (order.delivery_tax_cents ?? 0),
                )}
              </dd>
            </div>
            <div
              className="flex justify-between border-t border-dune/60 pt-2
                text-base font-bold"
            >
              <dt>{t.checkout.total}</dt>
              <dd>{money(order.total_cents)}</dd>
            </div>
          </dl>
          <p className="mt-4 text-xs text-ink-mute">
            {t.orders.delivery_label}:{' '}
            {order.delivery_method === 'express'
              ? t.checkout.delivery_express
              : t.checkout.delivery_standard}
          </p>
        </aside>
      </div>
    </div>
  );
}
