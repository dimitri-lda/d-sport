import { useState } from 'react';
import type { FormEvent } from 'react';
import { Link, useNavigate } from 'react-router';
import { register } from '../api/auth';
import { useApp } from '../app/useApp';
import { Button } from '../components/forms/Button';
import { CheckboxField } from '../components/forms/CheckboxField';
import { FormError } from '../components/forms/FormError';
import { FormField } from '../components/forms/FormField';
import { toSubmissionError } from '../lib/forms';

export function RegisterPage() {
  const { t, csrfToken, installContext } = useApp();
  const navigate = useNavigate();
  const [displayName, setDisplayName] = useState('');
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [confirmation, setConfirmation] = useState('');
  const [acceptedTerms, setAcceptedTerms] = useState(false);
  const [pending, setPending] = useState(false);
  const [fields, setFields] = useState<Record<string, string[]>>({});
  const [genericError, setGenericError] = useState<string | null>(null);

  async function onSubmit(event: FormEvent<HTMLFormElement>): Promise<void> {
    event.preventDefault();
    if (csrfToken === null) {
      return;
    }
    setPending(true);
    setFields({});
    setGenericError(null);
    try {
      const payload = await register(
        {
          display_name: displayName,
          email,
          password,
          password_confirmation: confirmation,
          accepted_terms: acceptedTerms,
        },
        csrfToken,
      );
      installContext(payload);
      void navigate('/profile', { replace: true });
    } catch (error) {
      const submission = toSubmissionError(error);
      setFields(submission.fields);
      setGenericError(submission.generic ? t.auth.generic_error : null);
    } finally {
      setPending(false);
    }
  }

  return (
    <section className="mx-auto max-w-md py-12">
      <h1 className="font-display text-3xl font-bold">
        {t.auth.register_title}
      </h1>
      <p className="mt-2 text-sm text-ink-mute">{t.auth.register_subtitle}</p>

      <form onSubmit={onSubmit} className="mt-8 flex flex-col gap-4" noValidate>
        <FormError message={genericError} />
        <FormField
          label={t.auth.display_name}
          name="display_name"
          value={displayName}
          onChange={setDisplayName}
          errors={fields.display_name}
          autoComplete="name"
          required
        />
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
          label={t.auth.password}
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
        <CheckboxField
          label={t.auth.accepted_terms}
          name="accepted_terms"
          checked={acceptedTerms}
          onChange={setAcceptedTerms}
          errors={fields.accepted_terms}
        />
        <Button pending={pending} pendingLabel={t.common.loading}>
          {t.auth.register_submit}
        </Button>
      </form>

      <p className="mt-6 text-sm text-ink-mute">
        {t.auth.have_account}{' '}
        <Link to="/login" className="text-cobalt hover:underline">
          {t.auth.login_submit}
        </Link>
      </p>
    </section>
  );
}
