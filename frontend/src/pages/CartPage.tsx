import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Link } from 'react-router';
import {
  CART_QUERY_KEY,
  getCart,
  removeCartItem,
  updateCartItem,
} from '../api/cart';
import type { Cart } from '../api/cart';
import { ApiError } from '../api/client';
import { useApp } from '../app/useApp';
import { ProductImage } from '../components/ProductImage';
import { format } from '../lib/i18n/dictionaries';
import { formatMoney } from '../lib/i18n/format';

export function CartPage() {
  const { t, locale, ready, csrfToken } = useApp();
  const queryClient = useQueryClient();

  const cart = useQuery({
    queryKey: CART_QUERY_KEY,
    queryFn: ({ signal }) => getCart(signal),
    enabled: ready,
  });

  const mutation = useMutation({
    mutationFn: (call: () => Promise<Cart>) => call(),
    onSuccess: (next) => queryClient.setQueryData(CART_QUERY_KEY, next),
  });

  function setQuantity(itemId: number, quantity: number): void {
    if (csrfToken === null || mutation.isPending) {
      return;
    }
    mutation.mutate(() => updateCartItem(itemId, quantity, csrfToken));
  }

  function remove(itemId: number): void {
    if (csrfToken === null || mutation.isPending) {
      return;
    }
    mutation.mutate(() => removeCartItem(itemId, csrfToken));
  }

  if (cart.isPending) {
    return (
      <p className="py-16 text-center text-ink-mute">{t.common.loading}</p>
    );
  }

  if (cart.isError || cart.data === undefined) {
    return (
      <div className="py-16 text-center">
        <h1 className="font-display text-3xl font-bold">{t.cart.title}</h1>
        <p role="alert" className="mt-4 text-ink-mute">
          {t.common.error}
        </p>
      </div>
    );
  }

  const data = cart.data;
  const summary = data.summary;
  const blocked = data.items.some((item) => !item.available);

  return (
    <div className="py-8 sm:py-12">
      <h1 className="font-display text-3xl font-bold">{t.cart.title}</h1>

      {mutation.isError ? (
        <p
          role="alert"
          className="mt-4 rounded-xl bg-sand px-4 py-3 text-sm font-medium
            text-red-800"
        >
          {mutation.error instanceof ApiError &&
          mutation.error.fields !== undefined
            ? (Object.values(mutation.error.fields).flat()[0] ??
              t.cart.update_error)
            : t.cart.update_error}
        </p>
      ) : null}

      {data.items.length === 0 ? (
        <div className="py-12 text-center">
          <p className="text-ink-mute">{t.cart.empty}</p>
          <Link
            to="/shop"
            className="mt-6 inline-block rounded-full bg-cobalt px-6 py-2
              text-sm font-semibold text-cream"
          >
            {t.cart.shop_link}
          </Link>
        </div>
      ) : (
        <div className="mt-8 grid gap-10 lg:grid-cols-[1fr_320px]">
          <ul className="flex flex-col gap-4">
            {data.items.map((item) => {
              const max = Math.min(item.stock, 20);
              return (
                <li
                  key={item.id}
                  className="flex gap-4 rounded-2xl border border-dune/60 p-4"
                >
                  <Link
                    to={`/shop/${item.product.slug}`}
                    className="shrink-0 overflow-hidden rounded-xl"
                  >
                    <ProductImage
                      src={item.product.image_url}
                      alt={item.product.name}
                      className="h-24 w-24 object-cover"
                    />
                  </Link>
                  <div className="flex min-w-0 flex-1 flex-col">
                    <Link
                      to={`/shop/${item.product.slug}`}
                      className="font-semibold hover:underline"
                    >
                      {item.product.name}
                    </Link>
                    <p className="text-sm text-ink-mute">{item.variant.name}</p>
                    {!item.available ? (
                      <p role="status" className="mt-1 text-sm text-red-700">
                        {t.cart.line_unavailable}
                      </p>
                    ) : null}
                    <div className="mt-auto flex items-center gap-3 pt-2">
                      <div className="flex items-center rounded-full border border-dune">
                        <button
                          type="button"
                          aria-label={t.cart.decrease}
                          disabled={mutation.isPending || item.quantity <= 0}
                          onClick={() =>
                            setQuantity(item.id, item.quantity - 1)
                          }
                          className="px-3 py-1 disabled:opacity-40"
                        >
                          −
                        </button>
                        <span
                          aria-label={format(t.cart.quantity_label, {
                            quantity: item.quantity,
                          })}
                          className="min-w-8 text-center text-sm font-medium"
                        >
                          {item.quantity}
                        </span>
                        <button
                          type="button"
                          aria-label={t.cart.increase}
                          disabled={mutation.isPending || item.quantity >= max}
                          onClick={() =>
                            setQuantity(item.id, item.quantity + 1)
                          }
                          className="px-3 py-1 disabled:opacity-40"
                        >
                          +
                        </button>
                      </div>
                      <button
                        type="button"
                        disabled={mutation.isPending}
                        onClick={() => remove(item.id)}
                        className="text-sm text-ink-mute underline
                          hover:text-red-700 disabled:opacity-40"
                      >
                        {t.cart.remove}
                      </button>
                    </div>
                  </div>
                  <div className="text-right">
                    <p className="font-semibold">
                      {formatMoney(
                        item.line_total_cents,
                        item.currency,
                        locale,
                      )}
                    </p>
                    <p className="text-sm text-ink-mute">
                      {formatMoney(
                        item.unit_price_cents,
                        item.currency,
                        locale,
                      )}{' '}
                      {t.cart.each}
                    </p>
                  </div>
                </li>
              );
            })}
          </ul>

          <aside className="h-fit rounded-2xl border border-dune/60 p-5">
            <h2 className="font-display text-lg font-bold">
              {t.cart.summary_title}
            </h2>
            <dl className="mt-3 flex flex-col gap-2 text-sm">
              <div className="flex justify-between">
                <dt className="text-ink-mute">{t.cart.subtotal}</dt>
                <dd className="font-medium">
                  {formatMoney(
                    summary.subtotal_cents,
                    summary.currency,
                    locale,
                  )}
                </dd>
              </div>
              <div className="flex justify-between">
                <dt className="text-ink-mute">{t.cart.net_subtotal}</dt>
                <dd>
                  {formatMoney(
                    summary.net_subtotal_cents,
                    summary.currency,
                    locale,
                  )}
                </dd>
              </div>
              <div className="flex justify-between">
                <dt className="text-ink-mute">{t.cart.tax}</dt>
                <dd>
                  {formatMoney(summary.tax_cents, summary.currency, locale)}
                </dd>
              </div>
              <div className="flex justify-between">
                <dt className="text-ink-mute">{t.cart.delivery_standard}</dt>
                <dd>
                  {formatMoney(
                    summary.delivery_quotes.standard,
                    summary.currency,
                    locale,
                  )}
                </dd>
              </div>
              <div className="flex justify-between">
                <dt className="text-ink-mute">{t.cart.delivery_express}</dt>
                <dd>
                  {formatMoney(
                    summary.delivery_quotes.express,
                    summary.currency,
                    locale,
                  )}
                </dd>
              </div>
            </dl>
            {blocked ? (
              <p
                className="mt-4 rounded-xl bg-sand px-3 py-2 text-sm
                text-red-800"
              >
                {t.cart.blocked}
              </p>
            ) : null}
            {blocked ? (
              <span
                aria-disabled="true"
                className="mt-4 block cursor-not-allowed rounded-full bg-cobalt
                  px-6 py-2.5 text-center text-sm font-semibold text-cream
                  opacity-50"
              >
                {t.cart.checkout}
              </span>
            ) : (
              <Link
                to="/checkout"
                className="mt-4 block rounded-full bg-cobalt px-6 py-2.5
                  text-center text-sm font-semibold text-cream
                  transition-transform hover:scale-[1.02]"
              >
                {t.cart.checkout}
              </Link>
            )}
          </aside>
        </div>
      )}
    </div>
  );
}
