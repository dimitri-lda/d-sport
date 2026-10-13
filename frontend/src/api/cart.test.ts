import { describe, expect, it, vi } from 'vitest';
import { addToCart, getCart, removeCartItem, updateCartItem } from './cart';
import { getCheckout, getMyOrders, getOrder, placeOrder } from './orders';

function stubFetch(response: Response) {
  const fetch = vi.fn().mockResolvedValue(response);
  vi.stubGlobal('fetch', fetch);

  return fetch;
}

function json(payload: unknown, init: ResponseInit = {}) {
  return new Response(JSON.stringify(payload), {
    headers: { 'Content-Type': 'application/json' },
    ...init,
  });
}

const cartPayload = {
  data: {
    id: 1,
    count: 2,
    items: [
      {
        id: 11,
        quantity: 2,
        available: true,
        stock: 5,
        product: { slug: 'x', name: 'X', image_url: '/img.svg' },
        variant: { id: 2, sku: 'SKU', name: 'One size' },
        unit_price_cents: 1190,
        line_total_cents: 2380,
        net_unit_price_cents: 1000,
        tax_cents: 380,
        currency: 'EUR',
      },
    ],
    summary: {
      subtotal_cents: 2380,
      net_subtotal_cents: 2000,
      tax_cents: 380,
      currency: 'EUR',
      delivery_quotes: { standard: 702, express: 1535 },
      delivery_net_quotes: { standard: 590, express: 1290 },
      delivery_tax_quotes: { standard: 112, express: 245 },
      free_standard_threshold: 5000,
    },
  },
};

describe('cart API', () => {
  it('gets the current cart', async () => {
    const fetch = stubFetch(json(cartPayload));

    const cart = await getCart();

    expect(cart.count).toBe(2);
    expect(fetch).toHaveBeenCalledWith(
      '/api/v1/cart',
      expect.objectContaining({ method: 'GET' }),
    );
  });

  it('posts a new cart item with the CSRF header', async () => {
    const fetch = stubFetch(json(cartPayload, { status: 201 }));

    const cart = await addToCart({ variant_id: 2, quantity: 2 }, 'csrf-1');

    expect(cart.items).toHaveLength(1);
    expect(fetch).toHaveBeenCalledWith(
      '/api/v1/cart/items',
      expect.objectContaining({
        method: 'POST',
        headers: expect.objectContaining({
          'X-CSRF-Token': 'csrf-1',
          'Content-Type': 'application/json',
        }),
        body: JSON.stringify({ variant_id: 2, quantity: 2 }),
      }),
    );
  });

  it('patches and deletes items with the CSRF header', async () => {
    let fetch = stubFetch(json(cartPayload));

    await updateCartItem(11, 3, 'csrf-2');
    expect(fetch).toHaveBeenCalledWith(
      '/api/v1/cart/items/11',
      expect.objectContaining({
        method: 'PATCH',
        headers: expect.objectContaining({ 'X-CSRF-Token': 'csrf-2' }),
        body: JSON.stringify({ quantity: 3 }),
      }),
    );

    fetch = stubFetch(json(cartPayload));
    await removeCartItem(11, 'csrf-3');
    expect(fetch).toHaveBeenCalledWith(
      '/api/v1/cart/items/11',
      expect.objectContaining({
        method: 'DELETE',
        headers: expect.objectContaining({ 'X-CSRF-Token': 'csrf-3' }),
      }),
    );
  });
});

describe('orders API', () => {
  it('gets the checkout bootstrap', async () => {
    const fetch = stubFetch(
      json({
        data: {
          cart: cartPayload.data,
          user: null,
          address: null,
          countries: [{ code: 'DE', name: 'Germany' }],
        },
      }),
    );

    const checkout = await getCheckout();

    expect(checkout.cart.id).toBe(1);
    expect(fetch).toHaveBeenCalledWith(
      '/api/v1/checkout',
      expect.objectContaining({ method: 'GET' }),
    );
  });

  it('posts an order and returns id and number', async () => {
    const fetch = stubFetch(
      json({ data: { id: 5, number: 'SP-AB12CD34' } }, { status: 201 }),
    );
    const input = {
      email: 'a@b.test',
      phone: '+49 1',
      first_name: 'A',
      last_name: 'B',
      line1: 'Main 1',
      line2: null,
      city: 'Berlin',
      postal_code: '10115',
      country: 'DE',
      delivery_method: 'standard' as const,
    };

    const placed = await placeOrder(input, 'csrf-4');

    expect(placed.number).toBe('SP-AB12CD34');
    expect(fetch).toHaveBeenCalledWith(
      '/api/v1/orders',
      expect.objectContaining({
        method: 'POST',
        headers: expect.objectContaining({ 'X-CSRF-Token': 'csrf-4' }),
        body: JSON.stringify(input),
      }),
    );
  });

  it('gets an order by number and the account order list', async () => {
    const order = {
      data: {
        id: 5,
        number: 'SP-AB12CD34',
        status: 'pending_payment',
        email: 'a@b.test',
        phone: '+49 1',
        delivery_method: 'standard',
        currency: 'EUR',
        market: 'eu_global',
        locale: 'en',
        subtotal_cents: 2380,
        delivery_cents: 702,
        total_cents: 3082,
        net_subtotal_cents: 2000,
        tax_cents: 380,
        delivery_net_cents: 590,
        delivery_tax_cents: 112,
        created_at: '2026-01-01T10:00:00+00:00',
        address: null,
        items: [],
      },
    };
    let fetch = stubFetch(json(order));

    const fetched = await getOrder('SP-AB12CD34');
    expect(fetched.status).toBe('pending_payment');
    expect(fetch).toHaveBeenCalledWith(
      '/api/v1/orders/SP-AB12CD34',
      expect.objectContaining({ method: 'GET' }),
    );

    fetch = stubFetch(json({ data: [order.data] }));
    const mine = await getMyOrders();
    expect(mine).toHaveLength(1);
    expect(fetch).toHaveBeenCalledWith(
      '/api/v1/me/orders',
      expect.objectContaining({ method: 'GET' }),
    );
  });
});
