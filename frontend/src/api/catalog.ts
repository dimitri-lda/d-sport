import { request } from './client';

export type ProductCard = {
  id: number;
  slug: string;
  name: string;
  brand: string | null;
  image_url: string | null;
  price_cents: number;
  currency: string;
  compare_at_price_cents?: number;
  available: boolean;
};

export type CategoryNode = {
  id: number;
  slug: string;
  name: string;
  image_url: string | null;
  product_count: number;
  children: CategoryNode[];
};

export type ProductVariant = {
  id: number;
  sku: string;
  name: string;
  price_cents: number;
  currency: string;
  stock: number;
  available: boolean;
};

export type ProductImageItem = { id: number; url: string; alt: string | null };

export type ProductSpecification = {
  name: string;
  value: string;
  unit: string | null;
};

export type ProductDetail = {
  id: number;
  slug: string;
  name: string;
  description: string;
  brand: { id: number; name: string; slug: string } | null;
  manufacturer: { id: number; name: string; slug: string } | null;
  price_cents: number;
  currency: string;
  compare_at_price_cents?: number;
  available: boolean;
  images: ProductImageItem[];
  specifications: ProductSpecification[];
  variants: ProductVariant[];
};

export type ProductDetailResponse = {
  product: ProductDetail;
  breadcrumbs: { slug: string; name: string }[];
  related: ProductCard[];
};

export type HomePayload = {
  categories: CategoryNode[];
  featured: ProductCard[];
};

export type ProductSort = 'newest' | 'price_asc' | 'price_desc';

export type CatalogParams = {
  q?: string;
  category?: string;
  sort?: ProductSort;
  page?: number;
};

export async function getHome(signal?: AbortSignal): Promise<HomePayload> {
  const payload = await request<{ data: HomePayload }>('/v1/store/home', {
    signal,
  });

  return payload.data;
}

export async function getCategories(
  signal?: AbortSignal,
): Promise<CategoryNode[]> {
  const payload = await request<{ data: CategoryNode[] }>('/v1/categories', {
    signal,
  });

  return payload.data;
}

export async function getProducts(
  params: CatalogParams,
  signal?: AbortSignal,
): Promise<{
  items: ProductCard[];
  page: number;
  per_page: number;
  total: number;
}> {
  const search = new URLSearchParams();
  if (params.q) {
    search.set('q', params.q);
  }
  if (params.category) {
    search.set('category', params.category);
  }
  if (params.sort) {
    search.set('sort', params.sort);
  }
  if (params.page && params.page > 1) {
    search.set('page', String(params.page));
  }
  const suffix = search.size > 0 ? `?${search.toString()}` : '';
  const payload = await request<{
    data: ProductCard[];
    meta: { page: number; per_page: number; total: number };
  }>(`/v1/products${suffix}` as `/${string}`, { signal });

  return { items: payload.data, ...payload.meta };
}

export async function getProduct(
  slug: string,
  signal?: AbortSignal,
): Promise<ProductDetailResponse> {
  const payload = await request<{ data: ProductDetailResponse }>(
    `/v1/products/${encodeURIComponent(slug)}`,
    { signal },
  );

  return payload.data;
}
