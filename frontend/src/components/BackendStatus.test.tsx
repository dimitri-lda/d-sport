import { QueryClientProvider } from '@tanstack/react-query';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';
import { createQueryClient } from '../api/queryClient';
import { AppContext } from '../app/useApp';
import type { AppContextValue } from '../app/useApp';
import { dictionaries } from '../lib/i18n/dictionaries';
import { BackendStatus } from './BackendStatus';

function renderStatus() {
  const client = createQueryClient();
  const app: AppContextValue = {
    ready: true,
    failed: false,
    user: null,
    market: null,
    countries: [],
    locales: ['en', 'ru', 'pl'],
    csrfToken: null,
    locale: 'en',
    t: dictionaries.en,
    refreshContext: () => Promise.resolve(null),
    retryContext: () => {},
    installContext: () => {},
    signOut: () => Promise.resolve(),
  };
  const result = render(
    <AppContext.Provider value={app}>
      <QueryClientProvider client={client}>
        <BackendStatus />
      </QueryClientProvider>
    </AppContext.Provider>,
  );
  return { ...result, client };
}

describe('backend status', () => {
  it('shows loading while the API is responding, then reports availability', async () => {
    let respond: (response: Response) => void = () => {};
    vi.stubGlobal(
      'fetch',
      vi.fn().mockImplementation(
        () =>
          new Promise<Response>((resolve) => {
            respond = resolve;
          }),
      ),
    );
    const { client } = renderStatus();

    expect(screen.getByRole('status')).toHaveTextContent(
      'Checking the API status',
    );
    expect(screen.getByRole('button')).toBeDisabled();
    respond(new Response('{"status":"ok"}'));
    expect(await screen.findByText('API is available')).toBeInTheDocument();
    expect(screen.getByRole('button')).toBeEnabled();
    client.clear();
  });

  it('stops automatic retries and lets the user recover after a network failure', async () => {
    const fetch = vi.fn().mockRejectedValue(new TypeError('Failed to fetch'));
    vi.stubGlobal('fetch', fetch);
    const { client } = renderStatus();

    expect(
      await screen.findByText('No connection to the API.'),
    ).toBeInTheDocument();
    expect(fetch).toHaveBeenCalledTimes(2);
    expect(client.isFetching()).toBe(0);

    fetch.mockResolvedValue(new Response('{"status":"ok"}'));
    await userEvent.click(
      screen.getByRole('button', { name: 'Retry the check' }),
    );
    expect(await screen.findByText('API is available')).toBeInTheDocument();
    expect(fetch).toHaveBeenCalledTimes(3);
    client.clear();
  });

  it('displays an API failure without retrying an invalid health payload', async () => {
    const fetch = vi
      .fn()
      .mockResolvedValue(new Response('{"status":"unknown"}'));
    vi.stubGlobal('fetch', fetch);
    const { client } = renderStatus();

    expect(
      await screen.findByText('The API returned an invalid response.'),
    ).toBeInTheDocument();
    expect(fetch).toHaveBeenCalledTimes(1);
    client.clear();
  });
});
