import { request } from './client';

export type CartProductRef = {
  slug: string;
  name: string;
  image_url: string | null;
};

export type CartVariantRef = {
  id: number;
  sku: string;
  name: string;
};

export type CartItem = {
  id: number;
  quantity: number;
  available: boolean;
  stock: number;
  product: CartProductRef;
  variant: CartVariantRef;
  unit_price_cents: number;
  line_total_cents: number;
  net_unit_price_cents: number;
  tax_cents: number;
  currency: string;
};

export type CartSummary = {
  subtotal_cents: number;
  net_subtotal_cents: number;
  tax_cents: number;
  currency: string;
  delivery_quotes: { standard: number; express: number };
  delivery_net_quotes: { standard: number; express: number };
  delivery_tax_quotes: { standard: number; express: number };
  free_standard_threshold: number;
};

export type Cart = {
  id: number;
  count: number;
  items: CartItem[];
  summary: CartSummary;
};

export const CART_QUERY_KEY = ['cart'] as const;

type CartResponse = { data: Cart };

export async function getCart(signal?: AbortSignal): Promise<Cart> {
  const payload = await request<CartResponse>('/v1/cart', { signal });

  return payload.data;
}

export async function addToCart(
  item: { variant_id: number; quantity: number },
  csrfToken: string,
): Promise<Cart> {
  const payload = await request<CartResponse>('/v1/cart/items', {
    method: 'POST',
    body: item,
    csrfToken,
  });

  return payload.data;
}

export async function updateCartItem(
  itemId: number,
  quantity: number,
  csrfToken: string,
): Promise<Cart> {
  const payload = await request<CartResponse>(`/v1/cart/items/${itemId}`, {
    method: 'PATCH',
    body: { quantity },
    csrfToken,
  });

  return payload.data;
}

export async function removeCartItem(
  itemId: number,
  csrfToken: string,
): Promise<Cart> {
  const payload = await request<CartResponse>(`/v1/cart/items/${itemId}`, {
    method: 'DELETE',
    csrfToken,
  });

  return payload.data;
}
