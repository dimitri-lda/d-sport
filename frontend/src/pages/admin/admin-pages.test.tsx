import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { QueryClientProvider } from '@tanstack/react-query';
import { MemoryRouter } from 'react-router';
import { describe, expect, it, vi } from 'vitest';
import { App } from '../../App';
import { createQueryClient } from '../../api/queryClient';

type Role = 'guest' | 'user' | 'admin';

const users = {
  user: {
    id: 7,
    email: 'flow@example.test',
    display_name: 'Flow User',
    role: 'user',
    email_verified: true,
  },
  admin: {
    id: 9,
    email: 'admin@example.test',
    display_name: 'Store Admin',
    role: 'admin',
    email_verified: true,
  },
};

function contextPayload(role: Role) {
  return {
    data: {
      user: role === 'guest' ? null : users[role],
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

const orderSummary = {
  id: 5,
  number: 'SP-DEADBEEF',
  status: 'pending_payment',
  email: 'flow@example.test',
  delivery_method: 'standard',
  total_cents: 3082,
  currency: 'EUR',
  items_count: 1,
  created_at: '2026-01-01T10:00:00+00:00',
};

const orderDetail = {
  data: {
    order: {
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
    next_statuses: ['paid', 'cancelled'],
  },
};

function adminListPayload() {
  return {
    data: [orderSummary],
    meta: { page: 1, per_page: 20, total: 1 },
    statuses: [
      'pending_payment',
      'paid',
      'processing',
      'shipped',
      'delivered',
      'cancelled',
      'refunded',
    ],
  };
}

function mockFetch(
  options: { role?: Role; patchStatus?: number; patched?: boolean } = {},
) {
  const role = options.role ?? 'admin';
  let transitioned = options.patched ?? false;
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
      return respond(contextPayload(role));
    }
    if (url === '/api/v1/admin/orders/5/status' && method === 'PATCH') {
      if (options.patchStatus !== undefined) {
        return respond(
          {
            error: {
              code: 'invalid_status_transition',
              message: 'invalid',
              fields: { status: ['This transition is not allowed.'] },
            },
          },
          options.patchStatus,
        );
      }
      transitioned = true;
      return respond({
        data: { status: 'paid', next_statuses: ['processing', 'refunded'] },
      });
    }
    if (url === '/api/v1/admin/orders/5') {
      if (transitioned) {
        return respond({
          data: {
            order: { ...orderDetail.data.order, status: 'paid' },
            next_statuses: ['processing', 'refunded'],
          },
        });
      }
      return respond(orderDetail);
    }
    if (url.startsWith('/api/v1/admin/orders')) {
      return respond(adminListPayload());
    }
    if (url === '/api/v1/cart') {
      return respond({
        data: {
          id: 1,
          count: 0,
          items: [],
          summary: {
            subtotal_cents: 0,
            net_subtotal_cents: 0,
            tax_cents: 0,
            currency: 'EUR',
            delivery_quotes: { standard: 702, express: 1535 },
            delivery_net_quotes: { standard: 590, express: 1290 },
            delivery_tax_quotes: { standard: 112, express: 245 },
            free_standard_threshold: 5000,
          },
        },
      });
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

describe('admin guard', () => {
  it('redirects guests to login', async () => {
    mockFetch({ role: 'guest' });
    const client = renderApp('/admin/orders');

    expect(
      await screen.findByRole('heading', { name: 'Welcome back' }),
    ).toBeInTheDocument();
    client.clear();
  });

  it('shows a forbidden page to authenticated non-admins', async () => {
    mockFetch({ role: 'user' });
    const client = renderApp('/admin/orders');

    expect(
      await screen.findByRole('heading', { name: 'No access' }),
    ).toBeInTheDocument();
    client.clear();
  });
});

describe('admin orders list', () => {
  it('renders rows and applies URL filters to the request', async () => {
    const fetch = mockFetch({ role: 'admin' });
    const client = renderApp('/admin/orders?q=dead&status=paid');

    expect(
      await screen.findByRole('heading', { name: 'Orders' }),
    ).toBeInTheDocument();
    // The number renders in both the desktop table and the mobile cards.
    expect((await screen.findAllByText('SP-DEADBEEF')).length).toBeGreaterThan(
      0,
    );
    expect(fetch).toHaveBeenCalledWith(
      '/api/v1/admin/orders?q=dead&status=paid',
      expect.objectContaining({ method: 'GET' }),
    );

    // Typing a search term re-filters through the URL, resetting the page.
    await userEvent.clear(screen.getByLabelText('Search'));
    await userEvent.type(screen.getByLabelText('Search'), 'zz');
    await waitFor(() =>
      expect(fetch).toHaveBeenCalledWith(
        expect.stringContaining('q=zz'),
        expect.objectContaining({ method: 'GET' }),
      ),
    );
    client.clear();
  });
});

describe('admin order detail', () => {
  it('offers only the allowed next statuses and transitions', async () => {
    const fetch = mockFetch({ role: 'admin' });
    const client = renderApp('/admin/orders/5');

    expect(
      await screen.findByRole('heading', { name: 'Order SP-DEADBEEF' }),
    ).toBeInTheDocument();
    const select = screen.getByLabelText('Next status');
    const options = Array.from(select.querySelectorAll('option')).map(
      (option) => option.getAttribute('value'),
    );
    expect(options).toEqual(['', 'paid', 'cancelled']);

    await userEvent.selectOptions(select, 'paid');
    await userEvent.click(screen.getByRole('button', { name: 'Apply' }));

    expect(fetch).toHaveBeenCalledWith(
      '/api/v1/admin/orders/5/status',
      expect.objectContaining({
        method: 'PATCH',
        headers: expect.objectContaining({ 'X-CSRF-Token': 'test-csrf' }),
        body: JSON.stringify({ status: 'paid' }),
      }),
    );
    // The invalidated detail query refetches and shows the new status.
    expect(
      await screen.findByRole('option', { name: 'Processing' }),
    ).toBeInTheDocument();
    client.clear();
  });

  it('surfaces an invalid transition field error', async () => {
    mockFetch({ role: 'admin', patchStatus: 422 });
    const client = renderApp('/admin/orders/5');

    const select = await screen.findByLabelText('Next status');
    await userEvent.selectOptions(select, 'paid');
    await userEvent.click(screen.getByRole('button', { name: 'Apply' }));

    expect(
      await screen.findByText('This transition is not allowed.'),
    ).toBeInTheDocument();
    client.clear();
  });
});
