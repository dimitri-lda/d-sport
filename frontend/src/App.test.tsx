import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { QueryClientProvider } from '@tanstack/react-query';
import { MemoryRouter } from 'react-router';
import { expect, it, vi } from 'vitest';
import { App } from './App';
import { createQueryClient } from './api/queryClient';

it('renders a 404 route and returns to the technical home page', async () => {
  const fetch = vi.fn().mockResolvedValue(new Response('{"status":"ok"}'));
  vi.stubGlobal('fetch', fetch);
  const client = createQueryClient();
  render(
    <QueryClientProvider client={client}>
      <MemoryRouter initialEntries={['/unknown-page']}>
        <App />
      </MemoryRouter>
    </QueryClientProvider>,
  );

  expect(
    screen.getByRole('heading', { name: '404 — страница не найдена' }),
  ).toBeInTheDocument();
  expect(fetch).not.toHaveBeenCalled();
  await userEvent.click(screen.getByRole('link', { name: 'На главную' }));
  expect(
    screen.getByRole('heading', { name: 'Основа приложения' }),
  ).toBeInTheDocument();
  expect(await screen.findByText('API доступен')).toBeInTheDocument();
  client.clear();
});
