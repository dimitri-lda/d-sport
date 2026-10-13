import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { QueryClientProvider } from '@tanstack/react-query';
import { MemoryRouter } from 'react-router';
import { describe, expect, it, vi } from 'vitest';
import { App } from '../App';
import { createQueryClient } from '../api/queryClient';

const verifiedUser = {
  id: 7,
  email: 'flow@example.test',
  display_name: 'Flow User',
  role: 'user',
  email_verified: true,
};

const unverifiedUser = { ...verifiedUser, email_verified: false };

function contextPayload(loggedIn: 'guest' | 'verified' | 'unverified') {
  return {
    data: {
      user:
        loggedIn === 'verified'
          ? verifiedUser
          : loggedIn === 'unverified'
            ? unverifiedUser
            : null,
      market: {
        country: 'DE',
        code: 'eu_global',
        currency: 'EUR',
        locale: 'en',
        vat_basis_points: 1900,
        exchange_rate: '1.00000000',
      },
      countries: [{ code: 'DE', name: 'Germany' }],
      locales: ['en', 'ru', 'pl'],
      csrf_token: 'test-csrf',
    },
  };
}

function cartPayload(quantity = 2) {
  return {
    data: {
      id: 1,
      count: quantity,
      items: [
        {
          id: 11,
          quantity,
          available: true,
          stock: 5,
          product: { slug: 'runner', name: 'Runner', image_url: null },
          variant: { id: 2, sku: 'SKU', name: 'One size' },
          unit_price_cents: 1190,
          line_total_cents: 1190 * quantity,
          net_unit_price_cents: 1000,
          tax_cents: 190 * quantity,
          currency: 'EUR',
        },
      ],
      summary: {
        subtotal_cents: 1190 * quantity,
        net_subtotal_cents: 1000 * quantity,
        tax_cents: 190 * quantity,
        currency: 'EUR',
        delivery_quotes: { standard: 702, express: 1535 },
        delivery_net_quotes: { standard: 590, express: 1290 },
        delivery_tax_quotes: { standard: 112, express: 245 },
        free_standard_threshold: 5000,
      },
    },
  };
}

const checkoutPayload = {
  data: {
    cart: cartPayload().data,
    user: verifiedUser,
    address: {
      first_name: 'Flow',
      last_name: 'User',
      line1: 'Main Street 1',
      line2: null,
      city: 'Berlin',
      postal_code: '10115',
      country: 'DE',
    },
    countries: [
      { code: 'DE', name: 'Germany' },
      { code: 'PL', name: 'Poland' },
    ],
  },
};

const orderPayload = {
  data: {
    id: 5,
    number: 'SP-DEADBEEF',
    status: 'pending_payment',
    email: 'flow@example.test',
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
    address: {
      first_name: 'Flow',
      last_name: 'User',
      line1: 'Main Street 1',
      line2: null,
      city: 'Berlin',
      postal_code: '10115',
      country: 'DE',
    },
    items: [
      {
        id: 1,
        product_variant_id: 2,
        name: 'Runner',
        variant_name: 'One size',
        unit_price_cents: 1190,
        net_unit_price_cents: 1000,
        quantity: 2,
        line_total_cents: 2380,
        tax_cents: 380,
      },
    ],
  },
};

const productDetailPayload = {
  data: {
    product: {
      id: 9,
      slug: 'runner',
      name: 'Runner',
      description: 'Light trainer.',
      brand: null,
      manufacturer: null,
      price_cents: 1190,
      currency: 'EUR',
      available: true,
      images: [],
      specifications: [],
      variants: [
        {
          id: 2,
          sku: 'SKU',
          name: 'One size',
          price_cents: 1190,
          currency: 'EUR',
          stock: 5,
          available: true,
        },
      ],
    },
    breadcrumbs: [],
    related: [],
  },
};

function mockFetch(
  options: {
    loggedIn?: 'guest' | 'verified' | 'unverified';
    orderStatus?: number;
    placeOrderStatus?: number;
  } = {},
) {
  const loggedIn = options.loggedIn ?? 'guest';
  const fetch = vi.fn((input: RequestInfo | URL, init?: RequestInit) => {
    const url =
      typeof input === 'string'
        ? input
        : input instanceof URL
          ? input.href
          : input.url;
    const method = init?.method ?? 'GET';
    const respond = (payload: unknown, status = 200) =>
      Promise.resolve(
        new Response(JSON.stringify(payload), {
          status,
          headers: { 'Content-Type': 'application/json' },
        }),
      );

    if (url === '/api/v1/context') {
      return respond(contextPayload(loggedIn));
    }
    if (url === '/api/v1/cart' && method === 'GET') {
      return respond(cartPayload());
    }
    if (url === '/api/v1/cart/items' && method === 'POST') {
      return respond(cartPayload(), 201);
    }
    if (url.startsWith('/api/v1/cart/items/')) {
      return respond(cartPayload(3));
    }
    if (url === '/api/v1/checkout') {
      return respond(checkoutPayload);
    }
    if (url === '/api/v1/orders' && method === 'POST') {
      if (options.placeOrderStatus !== undefined) {
        return respond(
          {
            error: {
              code: 'validation_failed',
              message: 'invalid',
              fields: { email: ['A valid email is required.'] },
            },
          },
          options.placeOrderStatus,
        );
      }
      return respond({ data: { id: 5, number: 'SP-DEADBEEF' } }, 201);
    }
    if (url.startsWith('/api/v1/orders/')) {
      if (options.orderStatus === 404) {
        return respond(
          { error: { code: 'not_found', message: 'missing' } },
          404,
        );
      }
      return respond(orderPayload);
    }
    if (url === '/api/v1/me/orders') {
      return respond({ data: [orderPayload.data] });
    }
    if (url.startsWith('/api/v1/products/')) {
      return respond(productDetailPayload);
    }
    if (url === '/api/v1/store/home' || url === '/api/v1/products') {
      return respond({ data: { categories: [], featured: [] } });
    }
    if (url.startsWith('/api/health/')) {
      return respond({ status: 'ok' });
    }

    return Promise.resolve(new Response('Not found', { status: 404 }));
  });
  vi.stubGlobal('fetch', fetch);

  return fetch;
}

function renderApp(route: string) {
  const client = createQueryClient();
  render(
    <QueryClientProvider client={client}>
      <MemoryRouter initialEntries={[route]}>
        <App />
      </MemoryRouter>
    </QueryClientProvider>,
  );

  return client;
}

describe('cart page', () => {
  it('renders lines, updates quantity and shows the nav badge', async () => {
    mockFetch();
    const client = renderApp('/cart');

    expect(
      await screen.findByRole('heading', { name: 'Your cart' }),
    ).toBeInTheDocument();
    expect(screen.getByText('Runner')).toBeInTheDocument();
    // The layout badge reflects the cart query count.
    expect(screen.getByRole('link', { name: 'Cart2' })).toBeInTheDocument();

    await userEvent.click(
      screen.getByRole('button', { name: 'Increase quantity' }),
    );
    expect(await screen.findByLabelText('Quantity: 3')).toBeInTheDocument();
    client.clear();
  });

  it('removes a line through the remove button', async () => {
    const fetch = mockFetch();
    const client = renderApp('/cart');

    await userEvent.click(
      await screen.findByRole('button', { name: 'Remove' }),
    );
    expect(fetch).toHaveBeenCalledWith(
      '/api/v1/cart/items/11',
      expect.objectContaining({ method: 'DELETE' }),
    );
    client.clear();
  });
});

describe('checkout page', () => {
  it('prefills the account address and submits to the order page', async () => {
    const fetch = mockFetch({ loggedIn: 'verified' });
    const client = renderApp('/checkout');

    expect(
      await screen.findByRole('heading', { name: 'Checkout' }),
    ).toBeInTheDocument();
    expect(screen.getByLabelText('First name')).toHaveValue('Flow');
    expect(screen.getByLabelText('City')).toHaveValue('Berlin');

    await userEvent.type(screen.getByLabelText('Email'), 'flow@example.test');
    await userEvent.type(screen.getByLabelText('Phone'), '+49 1');
    await userEvent.click(screen.getByRole('button', { name: 'Place order' }));

    expect(fetch).toHaveBeenCalledWith(
      '/api/v1/orders',
      expect.objectContaining({
        method: 'POST',
        headers: expect.objectContaining({ 'X-CSRF-Token': 'test-csrf' }),
      }),
    );
    expect(
      await screen.findByRole('heading', { name: 'Order SP-DEADBEEF' }),
    ).toBeInTheDocument();
    client.clear();
  });

  it('surfaces server field errors on the matching inputs', async () => {
    mockFetch({ loggedIn: 'verified', placeOrderStatus: 422 });
    const client = renderApp('/checkout');

    await screen.findByRole('heading', { name: 'Checkout' });
    await userEvent.type(screen.getByLabelText('Phone'), '+49 1');
    await userEvent.click(screen.getByRole('button', { name: 'Place order' }));

    expect(
      await screen.findByText('A valid email is required.'),
    ).toBeInTheDocument();
    client.clear();
  });
});

describe('order page', () => {
  it('renders the order snapshot with a pending payment section', async () => {
    mockFetch({ loggedIn: 'verified' });
    const client = renderApp('/orders/SP-DEADBEEF');

    expect(
      await screen.findByRole('heading', { name: 'Order SP-DEADBEEF' }),
    ).toBeInTheDocument();
    expect(screen.getByText('Pending payment')).toBeInTheDocument();
    expect(screen.getByText('Main Street 1')).toBeInTheDocument();
    client.clear();
  });

  it('shows a not-found state for an order the session does not own', async () => {
    mockFetch({ orderStatus: 404 });
    const client = renderApp('/orders/SP-DEADBEEF');

    expect(
      await screen.findByRole('heading', { name: 'Order not found.' }),
    ).toBeInTheDocument();
    client.clear();
  });
});

describe('account orders guard', () => {
  it('redirects unverified users to the verify-email page', async () => {
    mockFetch({ loggedIn: 'unverified' });
    const client = renderApp('/account/orders');

    expect(
      await screen.findByRole('heading', { name: 'Email verification' }),
    ).toBeInTheDocument();
    client.clear();
  });

  it('redirects guests to the login page', async () => {
    mockFetch({ loggedIn: 'guest' });
    const guestClient = renderApp('/account/orders');
    expect(
      await screen.findByRole('heading', { name: 'Welcome back' }),
    ).toBeInTheDocument();
    guestClient.clear();
  });

  it('lists orders for a verified account', async () => {
    mockFetch({ loggedIn: 'verified' });
    const client = renderApp('/account/orders');

    expect(
      await screen.findByRole('heading', { name: 'Your orders' }),
    ).toBeInTheDocument();
    expect(screen.getByText('SP-DEADBEEF')).toBeInTheDocument();
    client.clear();
  });
});

describe('product page cart flow', () => {
  it('adds to the cart and updates the nav badge', async () => {
    mockFetch();
    const client = renderApp('/shop/runner');

    await userEvent.click(
      await screen.findByRole('button', { name: 'Add to cart' }),
    );
    expect(await screen.findByText('Added to cart')).toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'Cart2' })).toBeInTheDocument();
    client.clear();
  });
});
