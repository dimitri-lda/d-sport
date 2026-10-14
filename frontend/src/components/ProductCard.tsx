import { Link } from 'react-router';
import type { ProductCard as ProductCardType } from '../api/catalog';
import { useApp } from '../app/useApp';
import { formatMoney } from '../lib/i18n/format';
import { ProductImage } from './ProductImage';

export function ProductCard({
  product,
  order = 0,
}: {
  product: ProductCardType;
  order?: number;
}) {
  const { locale, t } = useApp();

  return (
    <Link
      to={`/shop/${product.slug}`}
      className="reveal group block overflow-hidden rounded-2xl border border-dune/60
        bg-cream shadow-sm transition-shadow hover:shadow-lg"
      style={{ '--reveal-order': order } as React.CSSProperties}
    >
      <div className="aspect-[4/3] overflow-hidden bg-ink-soft">
        <ProductImage
          src={product.image_url}
          alt={product.name}
          className="h-full w-full object-cover transition-transform duration-500 group-hover:scale-105"
        />
      </div>
      <div className="p-4">
        {product.brand !== null ? (
          <p className="mb-1 text-xs font-medium uppercase tracking-wide text-ink-mute">
            {product.brand}
          </p>
        ) : null}
        <h3 className="font-display text-base font-semibold leading-snug">
          {product.name}
        </h3>
        <div className="mt-2 flex items-baseline gap-2">
          <span className="font-display text-lg font-bold text-cobalt">
            {formatMoney(product.price_cents, product.currency, locale)}
          </span>
          {product.compare_at_price_cents !== undefined ? (
            <span className="text-sm text-ink-mute line-through">
              {formatMoney(
                product.compare_at_price_cents,
                product.currency,
                locale,
              )}
            </span>
          ) : null}
        </div>
        {!product.available ? (
          <p className="mt-1 text-xs font-medium uppercase tracking-wide text-ink-mute">
            {t.product.out_of_stock}
          </p>
        ) : null}
      </div>
    </Link>
  );
}
