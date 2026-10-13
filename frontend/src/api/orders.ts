import { request } from './client';
import type { Cart } from './cart';
import type { ContextCountry, ContextUser } from './context';
import type { SavedAddressPayload } from './profile';

export type CheckoutPayload = {
  cart: Cart;
  user: ContextUser | null;
  address: SavedAddressPayload | null;
  countries: ContextCountry[];
};

export type PlaceOrderInput = {
  email: string;
  phone: string;
  first_name: string;
  last_name: string;
  line1: string;
  line2: string | null;
  city: string;
  postal_code: string;
  country: string;
  delivery_method: 'standard' | 'express';
};

export type OrderAddress = {
  first_name: string;
  last_name: string;
  line1: string;
  line2: string | null;
  city: string;
  postal_code: string;
  country: string;
};

export type OrderItem = {
  id: number;
  product_variant_id: number | null;
  name: string;
  variant_name: string;
  unit_price_cents: number;
  net_unit_price_cents: number | null;
  quantity: number;
  line_total_cents: number;
  tax_cents: number | null;
};

export type Order = {
  id: number;
  number: string;
  status: string;
  email: string;
  phone: string;
  delivery_method: string;
  currency: string;
  market: string;
  locale: string;
  subtotal_cents: number;
  delivery_cents: number;
  total_cents: number;
  net_subtotal_cents: number | null;
  tax_cents: number | null;
  delivery_net_cents: number | null;
  delivery_tax_cents: number | null;
  created_at: string | null;
  address: OrderAddress | null;
  items: OrderItem[];
};

export async function getCheckout(
  signal?: AbortSignal,
): Promise<CheckoutPayload> {
  const payload = await request<{ data: CheckoutPayload }>('/v1/checkout', {
    signal,
  });

  return payload.data;
}

export async function placeOrder(
  input: PlaceOrderInput,
  csrfToken: string,
): Promise<{ id: number; number: string }> {
  const payload = await request<{ data: { id: number; number: string } }>(
    '/v1/orders',
    { method: 'POST', body: input, csrfToken },
  );

  return payload.data;
}

export async function getOrder(
  number: string,
  signal?: AbortSignal,
): Promise<Order> {
  const payload = await request<{ data: Order }>(
    `/v1/orders/${encodeURIComponent(number)}`,
    { signal },
  );

  return payload.data;
}

export async function getMyOrders(signal?: AbortSignal): Promise<Order[]> {
  const payload = await request<{ data: Order[] }>('/v1/me/orders', {
    signal,
  });

  return payload.data;
}
