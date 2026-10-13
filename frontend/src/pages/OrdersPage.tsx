import { useQuery } from '@tanstack/react-query';
import { Link } from 'react-router';
import { ApiError } from '../api/client';
import { getMyOrders } from '../api/orders';
import { useApp } from '../app/useApp';
import { format } from '../lib/i18n/dictionaries';
import { formatMoney } from '../lib/i18n/format';

export function OrdersPage() {
  const { t, ready, locale } = useApp();

  const orders = useQuery({
    queryKey: ['me', 'orders'],
    queryFn: ({ signal }) => getMyOrders(signal),
    enabled: ready,
    retry: (failureCount, error) =>
      failureCount < 1 && error instanceof ApiError && error.retryable,
  });

  if (orders.isPending) {
    return (
      <p className="py-16 text-center text-ink-mute">{t.common.loading}</p>
    );
  }

  if (orders.isError || orders.data === undefined) {
    return (
      <div className="py-16 text-center">
        <h1 className="font-display text-3xl font-bold">
          {t.orders.list_title}
        </h1>
        <p role="alert" className="mt-4 text-ink-mute">
          {t.common.error}
        </p>
      </div>
    );
  }

  return (
    <div className="py-8 sm:py-12">
      <h1 className="font-display text-3xl font-bold">{t.orders.list_title}</h1>
      {orders.data.length === 0 ? (
        <div className="py-12 text-center">
          <p className="text-ink-mute">{t.orders.empty}</p>
          <Link
            to="/shop"
            className="mt-6 inline-block rounded-full bg-cobalt px-6 py-2
              text-sm font-semibold text-cream"
          >
            {t.cart.shop_link}
          </Link>
        </div>
      ) : (
        <ul className="mt-8 flex flex-col gap-4">
          {orders.data.map((order) => (
            <li key={order.id}>
              <Link
                to={`/orders/${order.number}`}
                className="flex flex-wrap items-center justify-between gap-3
                  rounded-2xl border border-dune/60 p-4 transition-colors
                  hover:bg-sand/40"
              >
                <div>
                  <p className="font-semibold">{order.number}</p>
                  <p className="text-sm text-ink-mute">
                    {format(t.orders.items_count, {
                      count: order.items.length,
                    })}
                  </p>
                </div>
                <div className="flex items-center gap-3">
                  <span
                    className="rounded-full bg-sand px-3 py-1 text-xs
                      font-semibold uppercase tracking-wide"
                  >
                    {t.orders.statuses[order.status] ?? order.status}
                  </span>
                  <span className="font-semibold">
                    {formatMoney(order.total_cents, order.currency, locale)}
                  </span>
                </div>
              </Link>
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}
