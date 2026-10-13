import { useState } from 'react';
import type { FormEvent } from 'react';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { confirmPassword, getPasswordConfirmationStatus } from '../api/auth';
import { useApp } from '../app/useApp';
import { Button } from '../components/forms/Button';
import { FormError } from '../components/forms/FormError';
import { FormField } from '../components/forms/FormField';
import { toSubmissionError } from '../lib/forms';

const STATUS_KEY = ['auth', 'password-confirmation'] as const;

export function ConfirmPasswordPage() {
  const { t, csrfToken } = useApp();
  const queryClient = useQueryClient();
  const status = useQuery({
    queryKey: STATUS_KEY,
    queryFn: ({ signal }) => getPasswordConfirmationStatus(signal),
  });
  const [password, setPassword] = useState('');
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
      await confirmPassword(password, csrfToken);
      setPassword('');
      await queryClient.invalidateQueries({ queryKey: STATUS_KEY });
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
        {t.auth.confirm_title}
      </h1>

      <p role="status" className="mt-6 text-sm">
        {status.isPending ? (
          <span className="text-ink-mute">{t.common.loading}</span>
        ) : status.data?.confirmed ? (
          <span className="text-cobalt">{t.auth.confirm_status_confirmed}</span>
        ) : (
          <span className="text-ink-mute">{t.auth.confirm_status_not}</span>
        )}
      </p>

      <form onSubmit={onSubmit} className="mt-6 flex flex-col gap-4" noValidate>
        <FormError message={genericError} />
        <FormField
          label={t.auth.confirm_current}
          name="password"
          type="password"
          value={password}
          onChange={setPassword}
          errors={fields.password}
          autoComplete="current-password"
          required
        />
        <Button pending={pending} pendingLabel={t.common.loading}>
          {t.auth.confirm_submit}
        </Button>
      </form>
    </section>
  );
}
