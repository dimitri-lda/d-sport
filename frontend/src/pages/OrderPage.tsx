import { useQuery } from '@tanstack/react-query';
import { Link, useParams } from 'react-router';
import { ApiError } from '../api/client';
import { getOrder } from '../api/orders';
import type { Order } from '../api/orders';
import { useApp } from '../app/useApp';
import { format } from '../lib/i18n/dictionaries';
import type { Dictionary } from '../lib/i18n/dictionaries';
import { formatMoney } from '../lib/i18n/format';

function statusLabel(t: Dictionary, status: string): string {
  return t.orders.statuses[status] ?? status;
}

function formatDate(value: string | null, locale: string): string | null {
  if (value === null) {
    return null;
  }
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) {
    return null;
  }

  return new Intl.DateTimeFormat(locale, { dateStyle: 'long' }).format(date);
}

export function OrderPage() {
  const { number = '' } = useParams();
  const { t, ready } = useApp();

  const order = useQuery({
    queryKey: ['order', number],
    queryFn: ({ signal }) => getOrder(number, signal),
    enabled: ready && number !== '',
    retry: (failureCount, error) =>
      failureCount < 1 && error instanceof ApiError && error.retryable,
  });

  if (order.isPending) {
    return (
      <p className="py-16 text-center text-ink-mute">{t.common.loading}</p>
    );
  }

  if (order.isError || order.data === undefined) {
    const notFound =
      order.error instanceof ApiError && order.error.status === 404;
    return (
      <div className="py-16 text-center">
        <h1 className="font-display text-3xl font-bold">
          {notFound ? t.orders.not_found_title : t.common.error}
        </h1>
        {notFound ? (
          <p className="mt-3 text-ink-mute">{t.orders.not_found_body}</p>
        ) : null}
        <Link
          to="/shop"
          className="mt-6 inline-block font-medium text-cobalt underline"
        >
          {t.not_found.home_link}
        </Link>
      </div>
    );
  }

  return <OrderView order={order.data} />;
}

function OrderView({ order }: { order: Order }) {
  const { t, locale } = useApp();
  const money = (cents: number | null) =>
    cents === null ? '—' : formatMoney(cents, order.currency, locale);
  const placedAt = formatDate(order.created_at, locale);

  return (
    <div className="py-8 sm:py-12">
      <div className="flex flex-wrap items-center gap-3">
        <h1 className="font-display text-3xl font-bold">
          {format(t.orders.title, { number: order.number })}
        </h1>
        <span
          className="rounded-full bg-sand px-3 py-1 text-xs font-semibold
            uppercase tracking-wide"
        >
          {statusLabel(t, order.status)}
        </span>
      </div>
      {placedAt !== null ? (
        <p className="mt-2 text-sm text-ink-mute">
          {format(t.orders.placed_at, { date: placedAt })}
        </p>
      ) : null}

      <div className="mt-8 grid gap-10 lg:grid-cols-[1fr_340px]">
        <div className="flex flex-col gap-8">
          <section aria-labelledby="order-items">
            <h2 id="order-items" className="font-display text-lg font-bold">
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
            <section aria-labelledby="order-address">
              <h2 id="order-address" className="font-display text-lg font-bold">
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

          {order.status === 'pending_payment' ? (
            <section
              aria-labelledby="order-payment"
              className="rounded-2xl border border-dashed border-dune p-4"
            >
              <h2 id="order-payment" className="font-display text-lg font-bold">
                {t.orders.payment_section}
              </h2>
              <p className="mt-2 text-sm text-ink-mute">
                {t.orders.payment_placeholder}
              </p>
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
          <Link
            to="/shop"
            className="mt-4 inline-block text-sm font-medium text-cobalt
              underline"
          >
            {t.not_found.home_link}
          </Link>
        </aside>
      </div>
    </div>
  );
}
