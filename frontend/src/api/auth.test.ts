import { describe, expect, it, vi } from 'vitest';
import {
  confirmPassword,
  forgotPassword,
  getPasswordConfirmationStatus,
  login,
  logout,
  register,
  resendVerification,
  resetPassword,
} from './auth';

function stubFetch(response: Response) {
  const fetch = vi.fn().mockResolvedValue(response);
  vi.stubGlobal('fetch', fetch);

  return fetch;
}

function json(payload: unknown, init: ResponseInit = {}) {
  return new Response(JSON.stringify(payload), {
    headers: { 'Content-Type': 'application/json' },
    ...init,
  });
}

const contextPayload = {
  data: {
    user: {
      id: 7,
      email: 'flow@example.test',
      display_name: 'Flow',
      role: 'user',
      email_verified: false,
    },
    market: {
      country: 'DE',
      code: 'eu_global',
      currency: 'EUR',
      locale: 'en',
      vat_basis_points: 1900,
      exchange_rate: '1.00000000',
      free_standard_threshold: 5000,
    },
    countries: [],
    locales: ['en'],
    csrf_token: 'fresh-csrf',
  },
};

describe('auth API', () => {
  it('posts login credentials with the CSRF header and returns context', async () => {
    const fetch = stubFetch(json(contextPayload));

    const payload = await login(
      { email: 'a@b.test', password: 'secret', _remember_me: true },
      'csrf-1',
    );

    expect(payload.user?.email).toBe('flow@example.test');
    expect(fetch).toHaveBeenCalledWith(
      '/api/v1/auth/login',
      expect.objectContaining({
        method: 'POST',
        credentials: 'same-origin',
        headers: expect.objectContaining({
          'X-CSRF-Token': 'csrf-1',
          'Content-Type': 'application/json',
        }),
        body: JSON.stringify({
          email: 'a@b.test',
          password: 'secret',
          _remember_me: true,
        }),
      }),
    );
  });

  it('posts registration and returns the authenticated context', async () => {
    const fetch = stubFetch(json(contextPayload, { status: 201 }));

    const payload = await register(
      {
        display_name: 'Flow',
        email: 'flow@example.test',
        password: 'Sport-E!2026',
        password_confirmation: 'Sport-E!2026',
        accepted_terms: true,
      },
      'csrf-2',
    );

    expect(payload.csrf_token).toBe('fresh-csrf');
    expect(fetch).toHaveBeenCalledWith(
      '/api/v1/auth/register',
      expect.objectContaining({ method: 'POST' }),
    );
  });

  it('logout resolves on a 204', async () => {
    const fetch = stubFetch(new Response(null, { status: 204 }));

    await expect(logout('csrf-3')).resolves.toBeUndefined();
    expect(fetch).toHaveBeenCalledWith(
      '/api/v1/auth/logout',
      expect.objectContaining({ method: 'POST' }),
    );
  });

  it('forgot returns the generic server message', async () => {
    stubFetch(
      json({ data: { message: 'password_reset_if_known' } }, { status: 202 }),
    );

    await expect(forgotPassword('x@y.test', 'csrf-4')).resolves.toBe(
      'password_reset_if_known',
    );
  });

  it('reset resolves on 204 and carries field errors on 422', async () => {
    const ok = stubFetch(new Response(null, { status: 204 }));
    await expect(
      resetPassword(
        {
          email: 'x@y.test',
          token: 't',
          password: 'Sport-E!2026',
          password_confirmation: 'Sport-E!2026',
        },
        'csrf-5',
      ),
    ).resolves.toBeUndefined();
    expect(ok).toHaveBeenCalled();

    stubFetch(
      json(
        {
          error: {
            code: 'password_reset_invalid',
            message: 'invalid',
            fields: { token: ['bad'] },
          },
        },
        { status: 422 },
      ),
    );
    await expect(
      resetPassword(
        {
          email: 'x@y.test',
          token: 't',
          password: 'p',
          password_confirmation: 'p',
        },
        'csrf-5',
      ),
    ).rejects.toMatchObject({
      code: 'password_reset_invalid',
      fields: { token: ['bad'] },
    });
  });

  it('resend returns the sent message and confirm round-trips status', async () => {
    stubFetch(
      json({ data: { message: 'verification_link_sent' } }, { status: 202 }),
    );
    await expect(resendVerification('csrf-6')).resolves.toBe(
      'verification_link_sent',
    );

    stubFetch(json({ data: { confirmed: true, confirmed_at: 123 } }));
    await expect(getPasswordConfirmationStatus()).resolves.toEqual({
      confirmed: true,
      confirmed_at: 123,
    });

    stubFetch(new Response(null, { status: 204 }));
    await expect(confirmPassword('pw', 'csrf-7')).resolves.toBeUndefined();
  });
});
