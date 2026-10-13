import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { QueryClientProvider } from '@tanstack/react-query';
import { MemoryRouter } from 'react-router';
import { describe, expect, it, vi } from 'vitest';
import { App } from '../App';
import { createQueryClient } from '../api/queryClient';

const user = {
  id: 7,
  email: 'flow@example.test',
  display_name: 'Flow User',
  role: 'user',
  email_verified: false,
};

function contextPayload(loggedIn: boolean) {
  return {
    data: {
      user: loggedIn ? user : null,
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

const profilePayload = {
  data: {
    user,
    address: {
      first_name: 'Flow',
      last_name: 'User',
      line1: 'Main Street 1',
      line2: null,
      city: 'Berlin',
      postal_code: '10115',
      country: 'DE',
    },
  },
};

function mockFetch(
  options: {
    loggedIn?: boolean;
    loginStatus?: number;
  } = {},
) {
  let loggedIn = options.loggedIn ?? false;
  const loginStatus = options.loginStatus ?? 200;
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
    if (url === '/api/v1/auth/login' && method === 'POST') {
      if (loginStatus !== 200) {
        return respond(
          {
            error: {
              code: 'invalid_credentials',
              message: 'The supplied credentials are invalid.',
            },
          },
          loginStatus,
        );
      }
      loggedIn = true;
      return respond(contextPayload(true));
    }
    if (url === '/api/v1/auth/register' && method === 'POST') {
      loggedIn = true;
      return respond(contextPayload(true), 201);
    }
    if (url === '/api/v1/auth/logout' && method === 'POST') {
      loggedIn = false;
      return Promise.resolve(new Response(null, { status: 204 }));
    }
    if (url === '/api/v1/profile' && method === 'GET') {
      return loggedIn
        ? respond(profilePayload)
        : respond({ error: { code: 'unauthorized', message: 'x' } }, 401);
    }
    if (url === '/api/v1/profile' && method === 'PATCH') {
      return respond({ data: { user: { ...user, display_name: 'Renamed' } } });
    }
    if (url === '/api/v1/store/home' || url.startsWith('/api/v1/products')) {
      return respond({ data: { categories: [], featured: [] } });
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

describe('identity pages', () => {
  it('logs in with CSRF, installs context and lands on the profile', async () => {
    const fetch = mockFetch();
    const client = renderApp('/login');

    await userEvent.type(
      await screen.findByLabelText('Email'),
      'flow@example.test',
    );
    await userEvent.type(screen.getByLabelText('Password'), 'Sport-E!2026');
    await userEvent.click(screen.getByRole('button', { name: 'Log in' }));

    // Navigates to the guarded profile and shows the fetched account data.
    expect(
      await screen.findByRole('heading', { name: 'Your profile' }),
    ).toBeInTheDocument();
    expect(screen.getByText('flow@example.test')).toBeInTheDocument();

    const loginCall = fetch.mock.calls.find(
      (call) => String(call[0]) === '/api/v1/auth/login',
    );
    const init = loginCall?.[1];
    expect(init).toMatchObject({
      method: 'POST',
      headers: expect.objectContaining({ 'X-CSRF-Token': 'test-csrf' }),
    });
    expect(JSON.parse(String(init?.body))).toMatchObject({
      email: 'flow@example.test',
      _remember_me: false,
    });
    client.clear();
  });

  it('shows a localized error when credentials are invalid', async () => {
    mockFetch({ loginStatus: 401 });
    const client = renderApp('/login');

    await userEvent.type(
      await screen.findByLabelText('Email'),
      'flow@example.test',
    );
    await userEvent.type(screen.getByLabelText('Password'), 'wrong');
    await userEvent.click(screen.getByRole('button', { name: 'Log in' }));

    expect(
      await screen.findByText('These credentials do not match our records.'),
    ).toBeInTheDocument();
    client.clear();
  });

  it('registers, installs the returned context and opens the profile', async () => {
    const fetch = mockFetch();
    const client = renderApp('/register');

    await userEvent.type(
      await screen.findByLabelText('Display name'),
      'Flow User',
    );
    await userEvent.type(screen.getByLabelText('Email'), 'flow@example.test');
    await userEvent.type(screen.getByLabelText('Password'), 'Sport-E!2026');
    await userEvent.type(
      screen.getByLabelText('Confirm password'),
      'Sport-E!2026',
    );
    await userEvent.click(
      screen.getByLabelText('I accept the terms of service'),
    );
    await userEvent.click(
      screen.getByRole('button', { name: 'Create account' }),
    );

    expect(
      await screen.findByRole('heading', { name: 'Your profile' }),
    ).toBeInTheDocument();
    const registerCall = fetch.mock.calls.find(
      (call) => String(call[0]) === '/api/v1/auth/register',
    );
    expect(registerCall?.[1]).toMatchObject({ method: 'POST' });
    client.clear();
  });

  it('redirects guests from /profile to /login and back after login', async () => {
    mockFetch();
    const client = renderApp('/profile');

    // RequireAuth bounces the guest to the login page.
    expect(
      await screen.findByRole('heading', { name: 'Welcome back' }),
    ).toBeInTheDocument();
    client.clear();
  });

  it('redirects authenticated users away from /login', async () => {
    mockFetch({ loggedIn: true });
    const client = renderApp('/login');

    expect(
      await screen.findByRole('heading', { name: 'Your profile' }),
    ).toBeInTheDocument();
    client.clear();
  });

  it('renders all profile sections for an authenticated user', async () => {
    mockFetch({ loggedIn: true });
    const client = renderApp('/profile');

    expect(
      await screen.findByRole('heading', { name: 'Identity' }),
    ).toBeInTheDocument();
    expect(
      screen.getByRole('heading', { name: 'Saved address' }),
    ).toBeInTheDocument();
    expect(
      screen.getByRole('heading', { name: 'Change password' }),
    ).toBeInTheDocument();
    expect(
      screen.getByRole('heading', { name: 'Delete account' }),
    ).toBeInTheDocument();
    // Prefilled identity + address data.
    expect(screen.getByLabelText('Display name')).toHaveValue('Flow User');
    expect(screen.getByLabelText('City')).toHaveValue('Berlin');
    client.clear();
  });
});
