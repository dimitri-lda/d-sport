import { QueryClientProvider } from '@tanstack/react-query';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';
import { createQueryClient } from '../api/queryClient';
import { BackendStatus } from './BackendStatus';

function renderStatus() {
  const client = createQueryClient();
  const result = render(
    <QueryClientProvider client={client}>
      <BackendStatus />
    </QueryClientProvider>,
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
      'Проверяем состояние API',
    );
    expect(screen.getByRole('button')).toBeDisabled();
    respond(new Response('{"status":"ok"}'));
    expect(await screen.findByText('API доступен')).toBeInTheDocument();
    expect(screen.getByRole('button')).toBeEnabled();
    client.clear();
  });

  it('stops automatic retries and lets the user recover after a network failure', async () => {
    const fetch = vi.fn().mockRejectedValue(new TypeError('Failed to fetch'));
    vi.stubGlobal('fetch', fetch);
    const { client } = renderStatus();

    expect(
      await screen.findByText('Нет соединения с API.'),
    ).toBeInTheDocument();
    expect(fetch).toHaveBeenCalledTimes(2);
    expect(client.isFetching()).toBe(0);

    fetch.mockResolvedValue(new Response('{"status":"ok"}'));
    await userEvent.click(
      screen.getByRole('button', { name: 'Повторить проверку' }),
    );
    expect(await screen.findByText('API доступен')).toBeInTheDocument();
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
      await screen.findByText('API вернул некорректный ответ.'),
    ).toBeInTheDocument();
    expect(fetch).toHaveBeenCalledTimes(1);
    client.clear();
  });
});
