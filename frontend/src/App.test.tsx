import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { QueryClientProvider } from '@tanstack/react-query';
import { MemoryRouter } from 'react-router';
import { expect, it, vi } from 'vitest';
import { App } from './App';
import { createQueryClient } from './api/queryClient';

const contextPayload = {
  data: {
    user: null,
    market: {
      country: 'DE',
      code: 'eu_global',
      currency: 'EUR',
      locale: 'en',
      vat_basis_points: 1900,
      exchange_rate: '1.00000000',
    },
    countries: [
      { code: 'DE', name: 'Germany' },
      { code: 'PL', name: 'Poland' },
    ],
    locales: ['en', 'ru', 'pl'],
    csrf_token: 'test-csrf',
  },
};

const homePayload = {
  data: {
    categories: [
      {
        id: 1,
        slug: 'footwear',
        name: 'Footwear',
        image_url: null,
        product_count: 4,
        children: [],
      },
    ],
    featured: [
      {
        id: 9,
        slug: 'velocity-runner',
        name: 'Velocity Runner',
        image_url: '/fixtures/images/velocity-runner.svg',
        price_cents: 11900,
        currency: 'EUR',
        available: true,
      },
    ],
  },
};

const productDetailPayload = {
  data: {
    product: {
      id: 9,
      slug: 'velocity-runner',
      name: 'Velocity Runner',
      description: 'Light distance trainer.',
      brand: null,
      manufacturer: null,
      price_cents: 11900,
      currency: 'EUR',
      available: true,
      images: [],
      specifications: [],
      variants: [
        {
          id: 41,
          sku: 'VR-40',
          name: 'EU 40',
          price_cents: 11900,
          currency: 'EUR',
          stock: 0,
          available: false,
        },
        {
          id: 42,
          sku: 'VR-41',
          name: 'EU 41',
          price_cents: 11900,
          currency: 'EUR',
          stock: 3,
          available: true,
        },
      ],
    },
    breadcrumbs: [],
    related: [],
  },
};

function mockFetch(
  overrides: {
    contextFailuresBeforeSuccess?: number;
    productDetail?: unknown;
  } = {},
) {
  let contextCalls = 0;
  const fetch = vi.fn((input: RequestInfo | URL) => {
    const url =
      typeof input === 'string'
        ? input
        : input instanceof URL
          ? input.href
          : input.url;
    if (url.startsWith('/api/v1/context')) {
      contextCalls += 1;
      if (contextCalls <= (overrides.contextFailuresBeforeSuccess ?? 0)) {
        return Promise.resolve(
          new Response('{"error":{"code":"x","message":"x"}}', {
            status: 500,
          }),
        );
      }
      return Promise.resolve(
        new Response(JSON.stringify(contextPayload), {
          headers: { 'Content-Type': 'application/json' },
        }),
      );
    }
    if (url.startsWith('/api/v1/store/home')) {
      return Promise.resolve(
        new Response(JSON.stringify(homePayload), {
          headers: { 'Content-Type': 'application/json' },
        }),
      );
    }
    if (url.startsWith('/api/v1/products/')) {
      return Promise.resolve(
        new Response(
          JSON.stringify(overrides.productDetail ?? productDetailPayload),
          { headers: { 'Content-Type': 'application/json' } },
        ),
      );
    }
    if (url.startsWith('/api/health/')) {
      return Promise.resolve(new Response('{"status":"ok"}'));
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

it('renders the storefront home with navigation and featured products', async () => {
  const fetch = mockFetch();
  const client = renderApp('/');

  expect(
    await screen.findByRole('link', { name: /Velocity Runner/ }),
  ).toBeInTheDocument();
  expect(screen.getByRole('link', { name: 'Home' })).toBeInTheDocument();
  expect(screen.getByRole('link', { name: 'Shop' })).toBeInTheDocument();
  expect(screen.getByRole('link', { name: /Footwear/ })).toBeInTheDocument();
  expect(fetch).toHaveBeenCalledWith(
    '/api/v1/context',
    expect.objectContaining({ credentials: 'same-origin' }),
  );
  expect(fetch).toHaveBeenCalledWith(
    '/api/v1/store/home',
    expect.objectContaining({ credentials: 'same-origin' }),
  );
  client.clear();
});

it('renders the not-found page inside the storefront shell', async () => {
  mockFetch();
  const client = renderApp('/definitely-missing');

  expect(
    await screen.findByRole('link', { name: 'Back to the storefront' }),
  ).toBeInTheDocument();
  expect(screen.getByRole('link', { name: 'Shop' })).toBeInTheDocument();
  client.clear();
});

it('navigates from home to the catalog page', async () => {
  const fetch = mockFetch();
  const client = renderApp('/');

  await userEvent.click(await screen.findByRole('link', { name: 'Shop' }));
  expect(
    await screen.findByRole('heading', { name: 'All products' }),
  ).toBeInTheDocument();
  const requested = fetch.mock.calls.map((call) => String(call[0]));
  expect(requested.some((url) => url.startsWith('/api/v1/products'))).toBe(
    true,
  );
  client.clear();
});

it('shows a recoverable error when the context bootstrap fails', async () => {
  // retry:1 in AppProvider means two failing calls before the error state.
  mockFetch({ contextFailuresBeforeSuccess: 2 });
  const client = renderApp('/');

  const retry = await screen.findByRole('button', { name: 'Try again' });
  expect(screen.getByRole('alert')).toBeInTheDocument();

  await userEvent.click(retry);
  expect(
    await screen.findByRole('link', { name: /Velocity Runner/ }),
  ).toBeInTheDocument();
  client.clear();
});

it('selects the first in-stock variant when the first variant is sold out', async () => {
  mockFetch();
  const client = renderApp('/shop/velocity-runner');

  const select = await screen.findByRole('combobox', { name: 'Variant' });
  expect(select).toHaveValue('42');
  expect(screen.getByRole('button', { name: 'Add to cart' })).toBeEnabled();
  expect(
    screen.queryByText('This product is currently unavailable.'),
  ).not.toBeInTheDocument();
  client.clear();
});
