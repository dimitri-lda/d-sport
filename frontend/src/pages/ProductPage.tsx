import { useMemo, useState } from 'react';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { Link, useParams } from 'react-router';
import { getProduct } from '../api/catalog';
import type { ProductDetailResponse } from '../api/catalog';
import { addToCart, CART_QUERY_KEY } from '../api/cart';
import { ApiError } from '../api/client';
import { useApp } from '../app/useApp';
import { ProductCard } from '../components/ProductCard';
import { ProductImage } from '../components/ProductImage';
import { SelectShell } from '../components/forms/SelectShell';
import { formatMoney } from '../lib/i18n/format';

export function ProductPage() {
  const { slug = '' } = useParams();
  const { t, locale } = useApp();

  const detail = useQuery({
    queryKey: ['catalog', 'product', locale, slug],
    queryFn: ({ signal }) => getProduct(slug, signal),
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
          {notFound ? t.not_found.title : t.common.error}
        </h1>
        <Link
          to="/shop"
          className="mt-6 inline-block font-medium text-cobalt underline"
        >
          {t.not_found.home_link}
        </Link>
      </div>
    );
  }

  // Remount per product so gallery/selection state starts fresh on navigation.
  return (
    <ProductDetailView key={detail.data.product.id} detail={detail.data} />
  );
}

function ProductDetailView({ detail }: { detail: ProductDetailResponse }) {
  const { t, locale, csrfToken } = useApp();
  const queryClient = useQueryClient();
  const [imageIndex, setImageIndex] = useState(0);
  const [variantId, setVariantId] = useState<number | null>(null);
  const [quantity, setQuantity] = useState(1);
  const [cartState, setCartState] = useState<
    'idle' | 'pending' | 'added' | 'error'
  >('idle');

  const product = detail.product;
  const variants = useMemo(() => product.variants, [product]);
  // Explicit choice wins; otherwise prefer the first purchasable variant.
  const selectedVariant =
    variants.find((variant) => variant.id === variantId) ??
    variants.find((variant) => variant.available) ??
    variants[0] ??
    null;

  const maxQuantity = selectedVariant ? Math.max(1, selectedVariant.stock) : 1;
  // Derived clamp: quantity can never exceed the selected variant's stock.
  const effectiveQuantity = Math.min(quantity, maxQuantity);

  async function submitCart(): Promise<void> {
    if (!selectedVariant || csrfToken === null) {
      return;
    }
    setCartState('pending');
    try {
      const cart = await addToCart(
        { variant_id: selectedVariant.id, quantity: effectiveQuantity },
        csrfToken,
      );
      queryClient.setQueryData(CART_QUERY_KEY, cart);
      setCartState('added');
    } catch {
      setCartState('error');
    }
  }

  const images = product.images;
  const activeImage = images[imageIndex] ?? images[0];

  return (
    <div className="py-8 sm:py-12">
      <nav
        aria-label={t.product.breadcrumbs_label}
        className="reveal text-sm text-ink-mute"
      >
        <ol className="flex flex-wrap items-center gap-1">
          <li>
            <Link to="/shop" className="hover:text-ink hover:underline">
              {t.nav.shop}
            </Link>
          </li>
          {(detail.breadcrumbs ?? []).map((crumb) => (
            <li key={crumb.slug} className="flex items-center gap-1">
              <span aria-hidden="true">/</span>
              <Link
                to={`/shop?category=${encodeURIComponent(crumb.slug)}`}
                className="hover:text-ink hover:underline"
              >
                {crumb.name}
              </Link>
            </li>
          ))}
          <li className="flex items-center gap-1">
            <span aria-hidden="true">/</span>
            <span aria-current="page" className="text-ink">
              {product.name}
            </span>
          </li>
        </ol>
      </nav>

      <div className="mt-8 grid gap-10 lg:grid-cols-2">
        <section
          aria-label={t.product.gallery_label}
          className="reveal"
          style={{ '--reveal-order': 1 } as React.CSSProperties}
        >
          <div className="overflow-hidden rounded-3xl border border-dune/60 bg-ink-soft">
            <ProductImage
              src={activeImage?.url}
              alt={activeImage?.alt ?? product.name}
              className="aspect-square w-full object-cover"
            />
          </div>
          {images.length > 1 ? (
            <ul className="mt-3 flex gap-3">
              {images.map((image, index) => (
                <li key={image.id}>
                  <button
                    type="button"
                    aria-pressed={index === imageIndex}
                    onClick={() => setImageIndex(index)}
                    className={`overflow-hidden rounded-xl border-2 ${
                      index === imageIndex ? 'border-cobalt' : 'border-dune/60'
                    }`}
                  >
                    <ProductImage
                      src={image.url}
                      alt={image.alt ?? product.name}
                      className="h-16 w-16 object-cover"
                    />
                  </button>
                </li>
              ))}
            </ul>
          ) : null}
        </section>

        <section
          className="reveal"
          style={{ '--reveal-order': 2 } as React.CSSProperties}
        >
          {product.brand ? (
            <p className="text-xs font-semibold uppercase tracking-[0.25em] text-ink-mute">
              {product.brand.name}
            </p>
          ) : null}
          <h1 className="font-display mt-2 text-4xl font-bold leading-tight tracking-tight">
            {product.name}
          </h1>

          <div className="mt-4 flex items-baseline gap-3">
            <span className="font-display text-3xl font-bold text-cobalt">
              {formatMoney(
                selectedVariant?.price_cents ?? product.price_cents,
                product.currency,
                locale,
              )}
            </span>
            {product.compare_at_price_cents !== undefined ? (
              <span className="text-lg text-ink-mute line-through">
                {formatMoney(
                  product.compare_at_price_cents,
                  product.currency,
                  locale,
                )}
              </span>
            ) : null}
          </div>

          <p className="mt-6 leading-relaxed text-ink-soft">
            {product.description}
          </p>

          {variants.length > 0 ? (
            <div className="mt-8">
              <label htmlFor="variant" className="text-sm font-semibold">
                {t.product.variant_label}
              </label>
              <SelectShell className="mt-2 w-full">
                <select
                  id="variant"
                  value={selectedVariant?.id ?? ''}
                  onChange={(event) => setVariantId(Number(event.target.value))}
                  className="w-full appearance-none rounded-xl border border-dune bg-cream pl-4 pr-10 py-2.5 text-sm"
                >
                  {variants.map((variant) => (
                    <option key={variant.id} value={variant.id}>
                      {variant.name}
                    </option>
                  ))}
                </select>
              </SelectShell>
            </div>
          ) : null}

          {selectedVariant && !selectedVariant.available ? (
            <p
              role="status"
              className="mt-6 rounded-xl bg-sand px-4 py-3 text-sm font-medium"
            >
              {t.product.unavailable}
            </p>
          ) : (
            <div className="mt-8 flex items-end gap-4">
              <div>
                <label htmlFor="quantity" className="text-sm font-semibold">
                  {t.product.quantity_label}
                </label>
                <input
                  id="quantity"
                  type="number"
                  min={1}
                  max={maxQuantity}
                  value={effectiveQuantity}
                  onChange={(event) => {
                    const next = Number(event.target.value);
                    if (Number.isInteger(next) && next >= 1) {
                      setQuantity(Math.min(next, maxQuantity));
                    }
                  }}
                  className="mt-2 w-24 rounded-xl border border-dune bg-cream px-3 py-2.5 text-sm"
                />
              </div>
              <button
                type="button"
                disabled={
                  !selectedVariant ||
                  cartState === 'pending' ||
                  csrfToken === null
                }
                onClick={() => void submitCart()}
                className="flex-1 rounded-full bg-cobalt px-6 py-3 font-display text-sm font-semibold text-cream transition-transform hover:scale-[1.02] disabled:cursor-not-allowed disabled:opacity-50"
              >
                {t.product.add_to_cart}
              </button>
            </div>
          )}

          {cartState === 'added' ? (
            <p
              role="status"
              className="mt-4 rounded-xl bg-sand px-4 py-3 text-sm font-medium
                text-cobalt"
            >
              {t.product.added}
            </p>
          ) : null}

          {cartState === 'error' ? (
            <p
              role="alert"
              className="mt-4 rounded-xl bg-sand px-4 py-3 text-sm font-medium text-red-800"
            >
              {t.product.cart_error}
            </p>
          ) : null}

          {product.specifications.length > 0 ? (
            <section className="mt-10" aria-labelledby="product-specs">
              <h2 id="product-specs" className="font-display text-lg font-bold">
                {t.product.specs_title}
              </h2>
              <dl className="mt-3 divide-y divide-dune/60 rounded-2xl border border-dune/60">
                {product.specifications.map((spec) => (
                  <div
                    key={spec.name}
                    className="flex justify-between gap-4 px-4 py-2.5 text-sm"
                  >
                    <dt className="text-ink-mute">{spec.name}</dt>
                    <dd className="font-medium">
                      {spec.value}
                      {spec.unit ? ` ${spec.unit}` : ''}
                    </dd>
                  </div>
                ))}
              </dl>
            </section>
          ) : null}
        </section>
      </div>

      {(detail.related ?? []).length > 0 ? (
        <section className="mt-16" aria-labelledby="related-products">
          <h2
            id="related-products"
            className="font-display mb-6 text-2xl font-bold tracking-tight"
          >
            {t.product.related_title}
          </h2>
          <ul className="grid grid-cols-2 gap-4 sm:grid-cols-4">
            {(detail.related ?? []).map((related, index) => (
              <li key={related.id}>
                <ProductCard product={related} order={index} />
              </li>
            ))}
          </ul>
        </section>
      ) : null}
    </div>
  );
}
