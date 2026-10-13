import { createContext, useContext } from 'react';
import type {
  ContextPayload,
  ContextUser,
  ContextMarket,
  Locale,
} from '../api/context';
import type { Dictionary } from '../lib/i18n/dictionaries';

export type AppContextValue = {
  ready: boolean;
  failed: boolean;
  user: ContextUser | null;
  market: ContextMarket | null;
  countries: ContextPayload['countries'];
  locales: ContextPayload['locales'];
  csrfToken: string | null;
  locale: Locale;
  t: Dictionary;
  refreshContext: () => Promise<ContextPayload | null>;
  retryContext: () => void;
  installContext: (payload: ContextPayload) => void;
  signOut: () => Promise<void>;
};

export const AppContext = createContext<AppContextValue | null>(null);

export function useApp(): AppContextValue {
  const context = useContext(AppContext);
  if (context === null) {
    throw new Error('useApp must be used inside <AppProvider>.');
  }

  return context;
}
