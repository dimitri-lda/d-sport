import { useState } from 'react';
import type { FormEvent, ReactNode } from 'react';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { useNavigate } from 'react-router';
import {
  deleteAccount,
  getProfile,
  updateAddress,
  updatePassword,
  updateProfile,
} from '../api/profile';
import type { ProfilePayload, SavedAddressPayload } from '../api/profile';
import { useApp } from '../app/useApp';
import { Button } from '../components/forms/Button';
import { FormError } from '../components/forms/FormError';
import { FormField } from '../components/forms/FormField';
import { toSubmissionError } from '../lib/forms';
import type { ApiFields } from '../api/client';

const PROFILE_KEY = ['account', 'profile'] as const;

const EMPTY_ADDRESS: SavedAddressPayload = {
  first_name: '',
  last_name: '',
  line1: '',
  line2: null,
  city: '',
  postal_code: '',
  country: '',
};

type SectionState = {
  fields: ApiFields;
  generic: string | null;
  saved: boolean;
  pending: boolean;
};

const INITIAL_SECTION: SectionState = {
  fields: {},
  generic: null,
  saved: false,
  pending: false,
};

export function ProfilePage() {
  const { t } = useApp();
  const profile = useQuery({
    queryKey: PROFILE_KEY,
    queryFn: ({ signal }) => getProfile(signal),
  });

  if (profile.isPending) {
    return (
      <p className="py-16 text-center text-ink-mute">{t.common.loading}</p>
    );
  }

  if (profile.isError) {
    return (
      <p role="alert" className="py-16 text-center text-ink-mute">
        {t.common.error}
      </p>
    );
  }

  // Mount the sections with the fetched data as initial form state; keying by
  // user id resets the forms when a different account signs in.
  return <ProfileSections key={profile.data.user.id} data={profile.data} />;
}

function ProfileSections({ data }: { data: ProfilePayload }) {
  const { t, csrfToken, refreshContext } = useApp();
  const navigate = useNavigate();
  const queryClient = useQueryClient();

  const [displayName, setDisplayName] = useState(data.user.display_name);
  const [email, setEmail] = useState(data.user.email);
  const [identity, setIdentity] = useState<SectionState>(INITIAL_SECTION);

  const [address, setAddress] = useState<SavedAddressPayload>(
    data.address ?? EMPTY_ADDRESS,
  );
  const [addressState, setAddressState] =
    useState<SectionState>(INITIAL_SECTION);

  const [currentPassword, setCurrentPassword] = useState('');
  const [newPassword, setNewPassword] = useState('');
  const [newConfirmation, setNewConfirmation] = useState('');
  const [passwordState, setPasswordState] =
    useState<SectionState>(INITIAL_SECTION);

  const [deletePassword, setDeletePassword] = useState('');
  const [deleteState, setDeleteState] = useState<SectionState>(INITIAL_SECTION);

  function failure(
    error: unknown,
    set: (update: (state: SectionState) => SectionState) => void,
  ): void {
    const submission = toSubmissionError(error);
    set((state) => ({
      ...state,
      pending: false,
      fields: submission.fields,
      generic: submission.generic ? t.auth.generic_error : null,
    }));
  }

  function sectionView(state: SectionState): ReactNode {
    return (
      <>
        <FormError message={state.generic} />
        {state.saved ? (
          <p role="status" className="text-sm text-cobalt">
            {t.profile.saved}
          </p>
        ) : null}
      </>
    );
  }

  async function onIdentitySubmit(event: FormEvent): Promise<void> {
    event.preventDefault();
    if (csrfToken === null) {
      return;
    }
    setIdentity({ ...INITIAL_SECTION, pending: true });
    try {
      await updateProfile({ display_name: displayName, email }, csrfToken);
      await refreshContext();
      await queryClient.invalidateQueries({ queryKey: PROFILE_KEY });
      setIdentity({ ...INITIAL_SECTION, saved: true });
    } catch (error) {
      failure(error, setIdentity);
    }
  }

  async function onAddressSubmit(event: FormEvent): Promise<void> {
    event.preventDefault();
    if (csrfToken === null) {
      return;
    }
    setAddressState({ ...INITIAL_SECTION, pending: true });
    try {
      await updateAddress(address, csrfToken);
      await queryClient.invalidateQueries({ queryKey: PROFILE_KEY });
      setAddressState({ ...INITIAL_SECTION, saved: true });
    } catch (error) {
      failure(error, setAddressState);
    }
  }

  async function onPasswordSubmit(event: FormEvent): Promise<void> {
    event.preventDefault();
    if (csrfToken === null) {
      return;
    }
    setPasswordState({ ...INITIAL_SECTION, pending: true });
    try {
      await updatePassword(
        {
          current_password: currentPassword,
          password: newPassword,
          password_confirmation: newConfirmation,
        },
        csrfToken,
      );
      setCurrentPassword('');
      setNewPassword('');
      setNewConfirmation('');
      setPasswordState({ ...INITIAL_SECTION, saved: true });
    } catch (error) {
      failure(error, setPasswordState);
    }
  }

  async function onDeleteSubmit(event: FormEvent): Promise<void> {
    event.preventDefault();
    if (csrfToken === null) {
      return;
    }
    setDeleteState({ ...INITIAL_SECTION, pending: true });
    try {
      await deleteAccount(deletePassword, csrfToken);
      await refreshContext();
      void navigate('/', { replace: true });
    } catch (error) {
      failure(error, setDeleteState);
    }
  }

  return (
    <section className="mx-auto max-w-2xl py-12">
      <h1 className="font-display text-3xl font-bold">{t.profile.title}</h1>
      <p className="mt-2 text-sm text-ink-mute">
        {data.user.email}
        {data.user.email_verified ? (
          <span
            className="ml-2 rounded-full bg-cobalt/10 px-2 py-0.5 text-xs
            font-medium text-cobalt"
          >
            {t.nav.verified}
          </span>
        ) : (
          <span
            className="ml-2 rounded-full bg-sand px-2 py-0.5 text-xs
            font-medium text-ink-mute"
          >
            {t.nav.unverified}
          </span>
        )}
      </p>

      <section aria-labelledby="profile-identity" className="mt-10">
        <h2
          id="profile-identity"
          className="font-display text-xl font-semibold"
        >
          {t.profile.identity_section}
        </h2>
        <form
          onSubmit={onIdentitySubmit}
          className="mt-4 flex flex-col gap-4"
          noValidate
        >
          {sectionView(identity)}
          <FormField
            label={t.profile.display_name}
            name="display_name"
            value={displayName}
            onChange={setDisplayName}
            errors={identity.fields.display_name}
            autoComplete="name"
            required
          />
          <FormField
            label={t.profile.email}
            name="email"
            type="email"
            value={email}
            onChange={setEmail}
            errors={identity.fields.email}
            autoComplete="email"
            required
          />
          <div>
            <Button pending={identity.pending} pendingLabel={t.common.loading}>
              {t.profile.save}
            </Button>
          </div>
        </form>
      </section>

      <section aria-labelledby="profile-address" className="mt-10">
        <h2 id="profile-address" className="font-display text-xl font-semibold">
          {t.profile.address_section}
        </h2>
        <form
          onSubmit={onAddressSubmit}
          className="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2"
          noValidate
        >
          <FormField
            label={t.profile.first_name}
            name="first_name"
            value={address.first_name}
            onChange={(value) =>
              setAddress((current) => ({ ...current, first_name: value }))
            }
            errors={addressState.fields.first_name}
            autoComplete="given-name"
            required
          />
          <FormField
            label={t.profile.last_name}
            name="last_name"
            value={address.last_name}
            onChange={(value) =>
              setAddress((current) => ({ ...current, last_name: value }))
            }
            errors={addressState.fields.last_name}
            autoComplete="family-name"
            required
          />
          <FormField
            label={t.profile.line1}
            name="line1"
            value={address.line1}
            onChange={(value) =>
              setAddress((current) => ({ ...current, line1: value }))
            }
            errors={addressState.fields.line1}
            autoComplete="address-line1"
            required
          />
          <FormField
            label={t.profile.line2}
            name="line2"
            value={address.line2 ?? ''}
            onChange={(value) =>
              setAddress((current) => ({
                ...current,
                line2: value === '' ? null : value,
              }))
            }
            errors={addressState.fields.line2}
            autoComplete="address-line2"
          />
          <FormField
            label={t.profile.city}
            name="city"
            value={address.city}
            onChange={(value) =>
              setAddress((current) => ({ ...current, city: value }))
            }
            errors={addressState.fields.city}
            autoComplete="address-level2"
            required
          />
          <FormField
            label={t.profile.postal_code}
            name="postal_code"
            value={address.postal_code}
            onChange={(value) =>
              setAddress((current) => ({ ...current, postal_code: value }))
            }
            errors={addressState.fields.postal_code}
            autoComplete="postal-code"
            required
          />
          <FormField
            label={t.profile.country}
            name="country"
            value={address.country}
            onChange={(value) =>
              setAddress((current) => ({ ...current, country: value }))
            }
            errors={addressState.fields.country}
            autoComplete="country"
            required
          />
          <div className="sm:col-span-2">
            {sectionView(addressState)}
            <Button
              pending={addressState.pending}
              pendingLabel={t.common.loading}
            >
              {t.profile.save}
            </Button>
          </div>
        </form>
      </section>

      <section aria-labelledby="profile-password" className="mt-10">
        <h2
          id="profile-password"
          className="font-display text-xl font-semibold"
        >
          {t.profile.password_section}
        </h2>
        <form
          onSubmit={onPasswordSubmit}
          className="mt-4 flex flex-col gap-4"
          noValidate
        >
          {sectionView(passwordState)}
          <FormField
            label={t.profile.current_password}
            name="current_password"
            type="password"
            value={currentPassword}
            onChange={setCurrentPassword}
            errors={passwordState.fields.current_password}
            autoComplete="current-password"
            required
          />
          <FormField
            label={t.profile.new_password}
            name="password"
            type="password"
            value={newPassword}
            onChange={setNewPassword}
            errors={passwordState.fields.password}
            autoComplete="new-password"
            required
          />
          <FormField
            label={t.profile.confirm_password}
            name="password_confirmation"
            type="password"
            value={newConfirmation}
            onChange={setNewConfirmation}
            errors={passwordState.fields.password_confirmation}
            autoComplete="new-password"
            required
          />
          <div>
            <Button
              pending={passwordState.pending}
              pendingLabel={t.common.loading}
            >
              {t.profile.save}
            </Button>
          </div>
        </form>
      </section>

      <section
        aria-labelledby="profile-danger"
        className="mt-10 rounded-xl border border-red-200 bg-red-50/50 p-5"
      >
        <h2
          id="profile-danger"
          className="font-display text-xl font-semibold
          text-red-700"
        >
          {t.profile.danger_section}
        </h2>
        <p className="mt-2 text-sm text-ink-mute">{t.profile.delete_body}</p>
        <form
          onSubmit={onDeleteSubmit}
          className="mt-4 flex flex-col gap-4"
          noValidate
        >
          {sectionView(deleteState)}
          <FormField
            label={t.profile.delete_password}
            name="password"
            type="password"
            value={deletePassword}
            onChange={setDeletePassword}
            errors={deleteState.fields.password}
            autoComplete="current-password"
            required
          />
          <div>
            <Button
              variant="danger"
              pending={deleteState.pending}
              pendingLabel={t.common.loading}
            >
              {t.profile.delete_submit}
            </Button>
          </div>
        </form>
      </section>
    </section>
  );
}
