import { useState } from 'react';
import type { FormEvent } from 'react';
import { Link, useLocation, useNavigate } from 'react-router';
import { login } from '../api/auth';
import { ApiError } from '../api/client';
import { useApp } from '../app/useApp';
import { Button } from '../components/forms/Button';
import { CheckboxField } from '../components/forms/CheckboxField';
import { FormError } from '../components/forms/FormError';
import { FormField } from '../components/forms/FormField';
import { toSubmissionError } from '../lib/forms';

export function LoginPage() {
  const { t, csrfToken, installContext } = useApp();
  const navigate = useNavigate();
  const location = useLocation();
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [rememberMe, setRememberMe] = useState(false);
  const [pending, setPending] = useState(false);
  const [fields, setFields] = useState<Record<string, string[]>>({});
  const [genericError, setGenericError] = useState<string | null>(null);

  const from =
    typeof location.state === 'object' &&
    location.state !== null &&
    'from' in location.state &&
    typeof location.state.from === 'string'
      ? location.state.from
      : '/profile';

  async function onSubmit(event: FormEvent<HTMLFormElement>): Promise<void> {
    event.preventDefault();
    if (csrfToken === null) {
      return;
    }
    setPending(true);
    setFields({});
    setGenericError(null);
    try {
      const payload = await login(
        { email, password, _remember_me: rememberMe },
        csrfToken,
      );
      installContext(payload);
      void navigate(from, { replace: true });
    } catch (error) {
      const submission = toSubmissionError(error);
      setFields(submission.fields);
      setGenericError(
        submission.generic
          ? error instanceof ApiError && error.code === 'invalid_credentials'
            ? t.auth.invalid_credentials
            : t.auth.generic_error
          : null,
      );
    } finally {
      setPending(false);
    }
  }

  return (
    <section className="mx-auto max-w-md py-12">
      <h1 className="font-display text-3xl font-bold">{t.auth.login_title}</h1>
      <p className="mt-2 text-sm text-ink-mute">{t.auth.login_subtitle}</p>

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
          label={t.auth.password}
          name="password"
          type="password"
          value={password}
          onChange={setPassword}
          errors={fields.password}
          autoComplete="current-password"
          required
        />
        <CheckboxField
          label={t.auth.remember_me}
          name="_remember_me"
          checked={rememberMe}
          onChange={setRememberMe}
        />
        <Button pending={pending} pendingLabel={t.common.loading}>
          {t.auth.login_submit}
        </Button>
      </form>

      <div className="mt-6 flex flex-col gap-2 text-sm">
        <Link to="/forgot-password" className="text-cobalt hover:underline">
          {t.auth.forgot_link}
        </Link>
        <p className="text-ink-mute">
          {t.auth.no_account}{' '}
          <Link to="/register" className="text-cobalt hover:underline">
            {t.auth.register_link}
          </Link>
        </p>
      </div>
    </section>
  );
}
