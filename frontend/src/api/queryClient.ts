import { QueryClient } from '@tanstack/react-query';
import { ApiError } from './client';

export function createQueryClient(): QueryClient {
  return new QueryClient({
    defaultOptions: {
      queries: {
        staleTime: 30_000,
        refetchOnWindowFocus: false,
        refetchOnReconnect: false,
        // At most two attempts per check, including the initial request.
        retry: (failureCount, error) =>
          failureCount < 1 && error instanceof ApiError && error.retryable,
        retryDelay: 300,
        // Surface offline errors immediately instead of leaving the UI paused.
        networkMode: 'always',
      },
    },
  });
}
