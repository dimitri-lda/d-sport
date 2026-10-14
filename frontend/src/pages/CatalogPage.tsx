import { useEffect, useMemo, useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { useSearchParams } from 'react-router';
import { getCategories, getProducts } from '../api/catalog';
import type { ProductSort } from '../api/catalog';
import { useApp } from '../app/useApp';
import { ProductCard } from '../components/ProductCard';
import { SelectShell } from '../components/forms/SelectShell';
import { format } from '../lib/i18n/dictionaries';

const SORTS: ProductSort[] = ['newest', 'price_asc', 'price_desc'];
const PAGE_SIZE = 12;

export function CatalogPage() {
  const { t, locale } = useApp();
  const [params, setParams] = useSearchParams();
  const q = params.get('q') ?? '';
  const category = params.get('category') ?? undefined;
  const sortParam = params.get('sort') ?? undefined;
  const sort = SORTS.includes(sortParam as ProductSort)
    ? (sortParam as ProductSort)
    : undefined;
  const pageParam = Number(params.get('page') ?? '1');
  const page = Number.isInteger(pageParam) && pageParam >= 1 ? pageParam : 1;

  const [searchInput, setSearchInput] = useState(q);
  const [syncedQuery, setSyncedQuery] = useState(q);
  if (syncedQuery !== q) {
    // Sync local input when the URL query changes outside the debounced input.
    setSyncedQuery(q);
    setSearchInput(q);
  }

  // Debounce the search input before it reaches the URL/API.
  useEffect(() => {
    if (searchInput === q) {
      return;
    }
    const timer = setTimeout(() => {
      const next = new URLSearchParams(params);
      if (searchInput === '') {
        next.delete('q');
      } else {
        next.set('q', searchInput);
      }
      next.delete('page');
      setParams(next, { replace: true });
    }, 350);

    return () => clearTimeout(timer);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [searchInput]);

  const products = useQuery({
    queryKey: ['catalog', 'page', locale, q, category, sort, page],
    queryFn: ({ signal }) =>
      getProducts({ q: q || undefined, category, sort, page }, signal),
  });
  const categories = useQuery({
    queryKey: ['catalog', 'categories', locale],
    queryFn: ({ signal }) => getCategories(signal),
  });

  const categoryNodes = categories.data;
  const flatCategories = useMemo(() => {
    const list: { slug: string; name: string }[] = [];
    const walk = (nodes: NonNullable<typeof categoryNodes>) => {
      for (const node of nodes) {
        list.push({ slug: node.slug, name: node.name });
        walk(node.children);
      }
    };
    walk(categoryNodes ?? []);

    return list;
  }, [categoryNodes]);

  function updateParam(key: string, value: string | null): void {
    const next = new URLSearchParams(params);
    if (value === null || value === '') {
      next.delete(key);
    } else {
      next.set(key, value);
    }
    next.delete('page');
    setParams(next, { replace: true });
  }

  function goToPage(nextPage: number): void {
    const next = new URLSearchParams(params);
    if (nextPage <= 1) {
      next.delete('page');
    } else {
      next.set('page', String(nextPage));
    }
    setParams(next);
  }

  const totalPages = products.data
    ? Math.max(1, Math.ceil(products.data.total / PAGE_SIZE))
    : 1;

  return (
    <div className="py-8 sm:py-12">
      <div className="reveal flex flex-wrap items-end justify-between gap-4">
        <h1 className="font-display text-4xl font-bold tracking-tight">
          {t.catalog.title}
        </h1>
        {products.data ? (
          <p className="text-sm text-ink-mute">
            {format(t.catalog.results, { total: products.data.total })}
          </p>
        ) : null}
      </div>

      <div
        className="reveal mt-6 flex flex-wrap items-center gap-3"
        style={{ '--reveal-order': 1 } as React.CSSProperties}
      >
        <label className="sr-only" htmlFor="catalog-q">
          {t.catalog.search_label}
        </label>
        <input
          id="catalog-q"
          type="search"
          value={searchInput}
          placeholder={t.catalog.search_placeholder}
          onChange={(event) => setSearchInput(event.target.value)}
          className="min-w-52 flex-1 rounded-full border border-dune bg-cream px-4 py-2 text-sm sm:max-w-xs"
        />

        <label className="sr-only" htmlFor="catalog-category">
          {t.catalog.category_label}
        </label>
        <SelectShell>
          <select
            id="catalog-category"
            value={category ?? ''}
            onChange={(event) =>
              updateParam('category', event.target.value || null)
            }
            className="appearance-none rounded-full border border-dune bg-cream pl-4 pr-10 py-2 text-sm"
          >
            <option value="">{t.catalog.all_categories}</option>
            {flatCategories.map((node) => (
              <option key={node.slug} value={node.slug}>
                {node.name}
              </option>
            ))}
          </select>
        </SelectShell>

        <label className="sr-only" htmlFor="catalog-sort">
          {t.catalog.sort_label}
        </label>
        <SelectShell>
          <select
            id="catalog-sort"
            value={sort ?? 'newest'}
            onChange={(event) =>
              updateParam(
                'sort',
                event.target.value === 'newest' ? null : event.target.value,
              )
            }
            className="appearance-none rounded-full border border-dune bg-cream pl-4 pr-10 py-2 text-sm"
          >
            <option value="newest">{t.catalog.sort_newest}</option>
            <option value="price_asc">{t.catalog.sort_price_asc}</option>
            <option value="price_desc">{t.catalog.sort_price_desc}</option>
          </select>
        </SelectShell>
      </div>

      {products.isPending ? (
        <p className="mt-12 text-ink-mute" role="status">
          {t.catalog.loading}
        </p>
      ) : products.isError ? (
        <p role="alert" className="mt-12 rounded-xl bg-sand p-5 text-ink">
          {t.catalog.error}{' '}
          <button
            type="button"
            onClick={() => void products.refetch()}
            className="font-semibold underline"
          >
            {t.catalog.retry}
          </button>
        </p>
      ) : products.data.items.length === 0 ? (
        <p className="mt-12 rounded-xl bg-sand/60 p-8 text-center text-ink-mute">
          {t.catalog.empty}
        </p>
      ) : (
        <>
          <ul className="mt-8 grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4">
            {products.data.items.map((product, index) => (
              <li key={product.id}>
                <ProductCard product={product} order={index % PAGE_SIZE} />
              </li>
            ))}
          </ul>

          {totalPages > 1 ? (
            <nav
              aria-label={t.catalog.sort_label}
              className="mt-10 flex items-center justify-center gap-4"
            >
              <button
                type="button"
                disabled={page <= 1}
                onClick={() => goToPage(page - 1)}
                className="rounded-full border border-dune px-4 py-2 text-sm disabled:opacity-40"
              >
                {t.catalog.prev_page}
              </button>
              <span className="text-sm text-ink-mute">
                {format(t.catalog.page_label, { page })}
              </span>
              <button
                type="button"
                disabled={page >= totalPages}
                onClick={() => goToPage(page + 1)}
                className="rounded-full border border-dune px-4 py-2 text-sm disabled:opacity-40"
              >
                {t.catalog.next_page}
              </button>
            </nav>
          ) : null}
        </>
      )}
    </div>
  );
}
