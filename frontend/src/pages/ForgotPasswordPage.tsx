import { useState } from 'react';
import type { FormEvent } from 'react';
import { Link } from 'react-router';
import { forgotPassword } from '../api/auth';
import { useApp } from '../app/useApp';
import { Button } from '../components/forms/Button';
import { FormError } from '../components/forms/FormError';
import { FormField } from '../components/forms/FormField';
import { toSubmissionError } from '../lib/forms';

export function ForgotPasswordPage() {
  const { t, csrfToken } = useApp();
  const [email, setEmail] = useState('');
  const [pending, setPending] = useState(false);
  const [sent, setSent] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function onSubmit(event: FormEvent<HTMLFormElement>): Promise<void> {
    event.preventDefault();
    if (csrfToken === null) {
      return;
    }
    setPending(true);
    setError(null);
    try {
      await forgotPassword(email, csrfToken);
      setSent(true);
    } catch (submission) {
      setError(
        toSubmissionError(submission).generic ? t.auth.generic_error : null,
      );
    } finally {
      setPending(false);
    }
  }

  if (sent) {
    return (
      <section className="mx-auto max-w-md py-12">
        <h1 className="font-display text-3xl font-bold">
          {t.auth.forgot_title}
        </h1>
        <p
          role="status"
          className="mt-6 rounded-md border border-dune bg-sand/50
          px-4 py-3 text-sm text-ink"
        >
          {t.auth.forgot_sent}
        </p>
        <p className="mt-6 text-sm">
          <Link to="/login" className="text-cobalt hover:underline">
            {t.auth.login_submit}
          </Link>
        </p>
      </section>
    );
  }

  return (
    <section className="mx-auto max-w-md py-12">
      <h1 className="font-display text-3xl font-bold">{t.auth.forgot_title}</h1>
      <p className="mt-2 text-sm text-ink-mute">{t.auth.forgot_body}</p>

      <form onSubmit={onSubmit} className="mt-8 flex flex-col gap-4" noValidate>
        <FormError message={error} />
        <FormField
          label={t.auth.email}
          name="email"
          type="email"
          value={email}
          onChange={setEmail}
          autoComplete="email"
          required
        />
        <Button pending={pending} pendingLabel={t.common.loading}>
          {t.auth.forgot_submit}
        </Button>
      </form>
    </section>
  );
}
