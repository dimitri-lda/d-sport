import { useState } from 'react';
import type { FormEvent } from 'react';
import { Link, useSearchParams } from 'react-router';
import { resetPassword } from '../api/auth';
import { useApp } from '../app/useApp';
import { Button } from '../components/forms/Button';
import { FormError } from '../components/forms/FormError';
import { FormField } from '../components/forms/FormField';
import { toSubmissionError } from '../lib/forms';

export function ResetPasswordPage() {
  const { t, csrfToken } = useApp();
  const [searchParams] = useSearchParams();
  const [email, setEmail] = useState(searchParams.get('email') ?? '');
  const [password, setPassword] = useState('');
  const [confirmation, setConfirmation] = useState('');
  const [pending, setPending] = useState(false);
  const [done, setDone] = useState(false);
  const [fields, setFields] = useState<Record<string, string[]>>({});
  const [genericError, setGenericError] = useState<string | null>(null);
  const token = searchParams.get('token') ?? '';

  async function onSubmit(event: FormEvent<HTMLFormElement>): Promise<void> {
    event.preventDefault();
    if (csrfToken === null) {
      return;
    }
    setPending(true);
    setFields({});
    setGenericError(null);
    try {
      await resetPassword(
        {
          email,
          token,
          password,
          password_confirmation: confirmation,
        },
        csrfToken,
      );
      setDone(true);
    } catch (error) {
      const submission = toSubmissionError(error);
      setFields(submission.fields);
      setGenericError(submission.generic ? t.auth.generic_error : null);
    } finally {
      setPending(false);
    }
  }

  if (done) {
    return (
      <section className="mx-auto max-w-md py-12">
        <h1 className="font-display text-3xl font-bold">
          {t.auth.reset_title}
        </h1>
        <p
          role="status"
          className="mt-6 rounded-md border border-dune bg-sand/50
          px-4 py-3 text-sm text-ink"
        >
          {t.auth.reset_success}
        </p>
        <p className="mt-6 text-sm">
          <Link to="/login" className="text-cobalt hover:underline">
            {t.auth.reset_login_link}
          </Link>
        </p>
      </section>
    );
  }

  return (
    <section className="mx-auto max-w-md py-12">
      <h1 className="font-display text-3xl font-bold">{t.auth.reset_title}</h1>
      <p className="mt-2 text-sm text-ink-mute">{t.auth.reset_body}</p>

      <form onSubmit={onSubmit} className="mt-8 flex flex-col gap-4" noValidate>
        <FormError message={genericError} />
        <FormField
          label={t.auth.email}
          name="email"
          type="email"
          value={email}
          onChange={setEmail}
          errors={fields.email}
          autoComplete="email"
          required
        />
        <FormField
          label={t.auth.reset_new_password}
          name="password"
          type="password"
          value={password}
          onChange={setPassword}
          errors={fields.password}
          autoComplete="new-password"
          required
        />
        <FormField
          label={t.auth.password_confirm}
          name="password_confirmation"
          type="password"
          value={confirmation}
          onChange={setConfirmation}
          errors={fields.password_confirmation}
          autoComplete="new-password"
          required
        />
        {fields.token !== undefined && fields.token.length > 0 ? (
          <p role="alert" className="text-xs text-red-600">
            {fields.token[0]}
          </p>
        ) : null}
        <Button
          pending={pending}
          pendingLabel={t.common.loading}
          disabled={token === ''}
        >
          {t.auth.reset_submit}
        </Button>
      </form>
    </section>
  );
}
