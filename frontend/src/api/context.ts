import { request } from './client';

export type Locale = 'en' | 'ru' | 'pl';

export type ContextUser = {
  id: number;
  email: string;
  display_name: string;
  role: 'user' | 'admin' | 'superadmin';
  email_verified: boolean;
};

export type ContextMarket = {
  country: string;
  code: string;
  currency: string;
  locale: Locale;
  vat_basis_points: number;
  exchange_rate: string;
};

export type ContextCountry = { code: string; name: string };

export type ContextPayload = {
  user: ContextUser | null;
  market: ContextMarket;
  countries: ContextCountry[];
  locales: Locale[];
  csrf_token: string;
};

export type ContextResponse = { data: ContextPayload };

export async function getContext(
  signal?: AbortSignal,
): Promise<ContextPayload> {
  const payload = await request<ContextResponse>('/v1/context', { signal });

  return payload.data;
}

export async function updatePreferences(
  preferences: { country: string; locale: Locale },
  csrfToken: string,
): Promise<ContextPayload> {
  const payload = await request<ContextResponse>('/v1/preferences', {
    method: 'PUT',
    body: preferences,
    csrfToken,
  });

  return payload.data;
}
