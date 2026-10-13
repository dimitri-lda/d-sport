import { request } from './client';
import type { ContextPayload, ContextResponse } from './context';

export type LoginInput = {
  email: string;
  password: string;
  _remember_me?: boolean;
};

export type RegisterInput = {
  display_name: string;
  email: string;
  password: string;
  password_confirmation: string;
  accepted_terms: boolean;
};

export type ResetPasswordInput = {
  email: string;
  token: string;
  password: string;
  password_confirmation: string;
};

export type PasswordConfirmationStatus = {
  confirmed: boolean;
  confirmed_at: number | null;
};

type MessageResponse = { data: { message: string } };

/** Log in through the firewall; the response carries the rotated-session context. */
export async function login(
  input: LoginInput,
  csrfToken: string,
): Promise<ContextPayload> {
  const payload = await request<ContextResponse>('/v1/auth/login', {
    method: 'POST',
    body: input,
    csrfToken,
  });

  return payload.data;
}

export async function register(
  input: RegisterInput,
  csrfToken: string,
): Promise<ContextPayload> {
  const payload = await request<ContextResponse>('/v1/auth/register', {
    method: 'POST',
    body: input,
    csrfToken,
  });

  return payload.data;
}

export async function logout(csrfToken: string): Promise<void> {
  await request<undefined>('/v1/auth/logout', { method: 'POST', csrfToken });
}

/**
 * Always resolves to the same generic message — the API never reveals
 * whether the email is registered.
 */
export async function forgotPassword(
  email: string,
  csrfToken: string,
): Promise<string> {
  const payload = await request<MessageResponse>('/v1/auth/forgot-password', {
    method: 'POST',
    body: { email },
    csrfToken,
  });

  return payload.data.message;
}

export async function resetPassword(
  input: ResetPasswordInput,
  csrfToken: string,
): Promise<void> {
  await request<undefined>('/v1/auth/reset-password', {
    method: 'POST',
    body: input,
    csrfToken,
  });
}

export async function resendVerification(csrfToken: string): Promise<string> {
  const payload = await request<MessageResponse>(
    '/v1/auth/email/verification-notification',
    { method: 'POST', csrfToken },
  );

  return payload.data.message;
}

export async function getPasswordConfirmationStatus(
  signal?: AbortSignal,
): Promise<PasswordConfirmationStatus> {
  const payload = await request<{ data: PasswordConfirmationStatus }>(
    '/v1/auth/confirm-password',
    { signal },
  );

  return payload.data;
}

export async function confirmPassword(
  password: string,
  csrfToken: string,
): Promise<void> {
  await request<undefined>('/v1/auth/confirm-password', {
    method: 'POST',
    body: { password },
    csrfToken,
  });
}
