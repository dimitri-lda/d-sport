import { useState } from 'react';

type Props = {
  src: string | null | undefined;
  alt: string;
  className?: string;
};

/** Local fixture image with a graceful ink placeholder on error/missing src. */
export function ProductImage({ src, alt, className }: Props) {
  const [errored, setErrored] = useState(false);

  if (!src || errored) {
    return (
      <div
        role="img"
        aria-label={alt}
        className={[
          'flex items-center justify-center bg-ink-soft text-dune',
          className ?? '',
        ].join(' ')}
      >
        <span
          aria-hidden="true"
          className="font-display text-3xl font-bold opacity-40"
        >
          S·E
        </span>
      </div>
    );
  }

  return (
    <img
      src={src}
      alt={alt}
      loading="lazy"
      onError={() => setErrored(true)}
      className={className}
    />
  );
}
