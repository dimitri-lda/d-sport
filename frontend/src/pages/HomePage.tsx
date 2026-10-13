import { useQuery } from '@tanstack/react-query';
import { Link } from 'react-router';
import { getHome } from '../api/catalog';
import { useApp } from '../app/useApp';
import { ProductCard } from '../components/ProductCard';
import { ProductImage } from '../components/ProductImage';
import { format } from '../lib/i18n/dictionaries';

export function HomePage() {
  const { t, locale } = useApp();
  const home = useQuery({
    queryKey: ['store', 'home', locale],
    queryFn: ({ signal }) => getHome(signal),
  });

  return (
    <div className="py-8 sm:py-12">
      <section className="reveal relative overflow-hidden rounded-3xl bg-ink px-6 py-14 text-cream sm:px-12 sm:py-20">
        <div className="texture-grid absolute inset-0" aria-hidden="true" />
        <div className="relative max-w-2xl">
          <p className="text-xs font-semibold uppercase tracking-[0.3em] text-cobalt-soft">
            {t.home.hero_kicker}
          </p>
          <h1 className="font-display mt-4 text-5xl font-bold leading-[0.95] tracking-tight sm:text-7xl">
            {t.home.hero_title}
          </h1>
          <p className="mt-6 max-w-xl text-base leading-relaxed text-sand/90">
            {t.home.hero_subtitle}
          </p>
          <Link
            to="/shop"
            className="mt-8 inline-block rounded-full bg-cobalt px-7 py-3 font-display text-sm font-semibold text-cream transition-transform hover:scale-105"
          >
            {t.home.hero_cta}
          </Link>
        </div>
      </section>

      {home.isError ? (
        <p role="alert" className="mt-10 rounded-xl bg-sand p-5 text-ink">
          {t.catalog.error}{' '}
          <button
            type="button"
            onClick={() => void home.refetch()}
            className="font-semibold underline"
          >
            {t.catalog.retry}
          </button>
        </p>
      ) : null}

      <section className="mt-14" aria-labelledby="home-categories">
        <div
          className="reveal mb-6 flex items-baseline justify-between"
          style={{ '--reveal-order': 1 } as React.CSSProperties}
        >
          <h2
            id="home-categories"
            className="font-display text-2xl font-bold tracking-tight"
          >
            {t.home.categories_title}
          </h2>
          <Link
            to="/shop"
            className="text-sm font-medium text-cobalt hover:underline"
          >
            {t.home.view_all}
          </Link>
        </div>
        <ul className="grid grid-cols-2 gap-4 sm:grid-cols-4">
          {(home.data?.categories ?? []).map((category, index) => (
            <li key={category.id}>
              <Link
                to={`/shop?category=${encodeURIComponent(category.slug)}`}
                className="reveal group block overflow-hidden rounded-2xl border border-dune/60 bg-sand/50 transition-shadow hover:shadow-md"
                style={{ '--reveal-order': index + 1 } as React.CSSProperties}
              >
                <div className="aspect-square overflow-hidden bg-ink-soft">
                  <ProductImage
                    src={category.image_url}
                    alt={category.name}
                    className="h-full w-full object-cover transition-transform duration-500 group-hover:scale-105"
                  />
                </div>
                <div className="p-4">
                  <p className="font-display font-semibold">{category.name}</p>
                  <p className="mt-1 text-xs text-ink-mute">
                    {format(t.home.products_count, {
                      count: category.product_count,
                    })}
                  </p>
                </div>
              </Link>
            </li>
          ))}
        </ul>
      </section>

      <section className="mt-14" aria-labelledby="home-featured">
        <h2
          id="home-featured"
          className="reveal mb-6 font-display text-2xl font-bold tracking-tight"
          style={{ '--reveal-order': 2 } as React.CSSProperties}
        >
          {t.home.featured_title}
        </h2>
        {home.isPending ? (
          <p className="text-ink-mute">{t.catalog.loading}</p>
        ) : (
          <ul className="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4">
            {(home.data?.featured ?? []).map((product, index) => (
              <li key={product.id}>
                <ProductCard product={product} order={index + 2} />
              </li>
            ))}
          </ul>
        )}
      </section>
    </div>
  );
}
