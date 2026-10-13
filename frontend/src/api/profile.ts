import { request } from './client';
import type { ContextUser } from './context';

export type SavedAddressPayload = {
  first_name: string;
  last_name: string;
  line1: string;
  line2: string | null;
  city: string;
  postal_code: string;
  country: string;
};

export type ProfilePayload = {
  user: ContextUser;
  address: SavedAddressPayload | null;
};

export type UpdateProfileInput = {
  display_name: string;
  email: string;
};

export type UpdatePasswordInput = {
  current_password: string;
  password: string;
  password_confirmation: string;
};

export async function getProfile(
  signal?: AbortSignal,
): Promise<ProfilePayload> {
  const payload = await request<{ data: ProfilePayload }>('/v1/profile', {
    signal,
  });

  return payload.data;
}

export async function updateProfile(
  input: UpdateProfileInput,
  csrfToken: string,
): Promise<ContextUser> {
  const payload = await request<{ data: { user: ContextUser } }>(
    '/v1/profile',
    { method: 'PATCH', body: input, csrfToken },
  );

  return payload.data.user;
}

export async function updateAddress(
  input: SavedAddressPayload,
  csrfToken: string,
): Promise<SavedAddressPayload> {
  const payload = await request<{ data: { address: SavedAddressPayload } }>(
    '/v1/profile/address',
    { method: 'PUT', body: input, csrfToken },
  );

  return payload.data.address;
}

export async function updatePassword(
  input: UpdatePasswordInput,
  csrfToken: string,
): Promise<void> {
  await request<undefined>('/v1/profile/password', {
    method: 'PUT',
    body: input,
    csrfToken,
  });
}

export async function deleteAccount(
  password: string,
  csrfToken: string,
): Promise<void> {
  await request<undefined>('/v1/profile', {
    method: 'DELETE',
    body: { password },
    csrfToken,
  });
}
