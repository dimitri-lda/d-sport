import { useCallback, useMemo } from 'react';
import type { ReactNode } from 'react';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { logout } from '../api/auth';
import { getContext } from '../api/context';
import type { ContextPayload } from '../api/context';
import { dictionaries } from '../lib/i18n/dictionaries';
import { AppContext } from './useApp';
import type { AppContextValue } from './useApp';

const CONTEXT_KEY = ['app', 'context'] as const;

export function AppProvider({ children }: { children: ReactNode }) {
  const queryClient = useQueryClient();
  const query = useQuery<ContextPayload>({
    queryKey: CONTEXT_KEY,
    queryFn: ({ signal }) => getContext(signal),
    retry: 1,
  });

  const refreshContext =
    useCallback(async (): Promise<ContextPayload | null> => {
      try {
        const payload = await queryClient.fetchQuery<ContextPayload>({
          queryKey: CONTEXT_KEY,
          queryFn: ({ signal }) => getContext(signal),
          // An explicit refresh must hit the server — the global staleTime
          // would otherwise return the stale authenticated context (e.g.
          // immediately after logout).
          staleTime: 0,
        });

        return payload;
      } catch {
        return null;
      }
    }, [queryClient]);

  const retryContext = useCallback(() => {
    void query.refetch();
  }, [query]);

  const installContext = useCallback(
    (payload: ContextPayload) => {
      queryClient.setQueryData(CONTEXT_KEY, payload);
    },
    [queryClient],
  );

  const payload = query.data ?? null;
  const csrfToken = payload?.csrf_token ?? null;

  const signOut = useCallback(async (): Promise<void> => {
    if (csrfToken !== null) {
      await logout(csrfToken);
    }
    // The session is dead; fetch a fresh anonymous context and CSRF token.
    await refreshContext();
  }, [csrfToken, refreshContext]);

  const value = useMemo<AppContextValue>(() => {
    const locale = payload?.market.locale ?? 'en';

    return {
      ready: payload !== null,
      failed: query.isError,
      user: payload?.user ?? null,
      market: payload?.market ?? null,
      countries: payload?.countries ?? [],
      locales: payload?.locales ?? ['en', 'ru', 'pl'],
      csrfToken,
      locale,
      t: dictionaries[locale] ?? dictionaries.en,
      refreshContext,
      retryContext,
      installContext,
      signOut,
    };
  }, [
    payload,
    query.isError,
    csrfToken,
    refreshContext,
    retryContext,
    installContext,
    signOut,
  ]);

  return <AppContext.Provider value={value}>{children}</AppContext.Provider>;
}
