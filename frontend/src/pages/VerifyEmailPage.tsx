import { useEffect, useState } from 'react';
import { Link, useSearchParams } from 'react-router';
import { resendVerification } from '../api/auth';
import { useApp } from '../app/useApp';
import { Button } from '../components/forms/Button';
import { FormError } from '../components/forms/FormError';

const RESEND_COOLDOWN_SECONDS = 60;

export function VerifyEmailPage() {
  const { t, user, csrfToken } = useApp();
  const [searchParams] = useSearchParams();
  const [sent, setSent] = useState(false);
  const [cooldown, setCooldown] = useState(0);
  const [error, setError] = useState<string | null>(null);
  const justVerified = searchParams.get('verified') === '1';

  useEffect(() => {
    if (cooldown <= 0) {
      return;
    }
    const timer = setTimeout(() => setCooldown((value) => value - 1), 1_000);

    return () => clearTimeout(timer);
  }, [cooldown]);

  async function resend(): Promise<void> {
    if (csrfToken === null || cooldown > 0) {
      return;
    }
    setError(null);
    try {
      await resendVerification(csrfToken);
      setSent(true);
      setCooldown(RESEND_COOLDOWN_SECONDS);
    } catch {
      setError(t.auth.generic_error);
    }
  }

  return (
    <section className="mx-auto max-w-md py-12">
      <h1 className="font-display text-3xl font-bold">{t.auth.verify_title}</h1>

      {justVerified ? (
        <p
          role="status"
          className="mt-6 rounded-md border border-dune bg-sand/50 px-4 py-3
            text-sm text-ink"
        >
          {t.auth.verify_verified_body}
        </p>
      ) : null}

      {user !== null && !user.email_verified ? (
        <div className="mt-6 flex flex-col gap-4">
          <p className="text-sm text-ink-mute">{t.auth.verify_prompt}</p>
          <FormError message={error} />
          {sent ? (
            <p role="status" className="text-sm text-cobalt">
              {t.auth.verify_sent}
            </p>
          ) : null}
          <div>
            <Button
              type="button"
              variant="ghost"
              pending={false}
              disabled={csrfToken === null || cooldown > 0}
              onClick={() => void resend()}
            >
              {cooldown > 0
                ? `${t.auth.verify_resend} (${cooldown})`
                : t.auth.verify_resend}
            </Button>
          </div>
        </div>
      ) : null}

      <p className="mt-8 text-sm">
        <Link to="/" className="text-cobalt hover:underline">
          {t.auth.verify_open_store}
        </Link>
      </p>
    </section>
  );
}
