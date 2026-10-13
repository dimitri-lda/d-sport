import { Link } from 'react-router';
import { useApp } from '../app/useApp';

export function NotFoundPage() {
  const { t } = useApp();

  return (
    <div className="py-24 text-center">
      <h1 className="font-display text-5xl font-bold tracking-tight">
        {t.not_found.title}
      </h1>
      <p className="mt-4 text-ink-mute">{t.not_found.body}</p>
      <Link
        to="/"
        className="mt-8 inline-block rounded-full bg-cobalt px-6 py-3 font-display text-sm font-semibold text-cream"
      >
        {t.not_found.home_link}
      </Link>
    </div>
  );
}
