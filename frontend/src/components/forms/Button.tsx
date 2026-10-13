import type { ButtonHTMLAttributes, ReactNode } from 'react';

type ButtonProps = {
  children: ReactNode;
  pending?: boolean;
  pendingLabel?: string;
  variant?: 'primary' | 'danger' | 'ghost';
} & Pick<
  ButtonHTMLAttributes<HTMLButtonElement>,
  'type' | 'onClick' | 'disabled'
>;

const VARIANT_CLASSES: Record<NonNullable<ButtonProps['variant']>, string> = {
  primary: 'bg-cobalt text-cream hover:bg-ink',
  danger: 'bg-red-600 text-cream hover:bg-red-700',
  ghost: 'border border-dune text-ink hover:bg-sand',
};

export function Button({
  children,
  pending = false,
  pendingLabel,
  variant = 'primary',
  type = 'submit',
  onClick,
  disabled,
}: ButtonProps) {
  return (
    <button
      type={type}
      disabled={disabled ?? pending}
      aria-busy={pending || undefined}
      onClick={onClick}
      className={`inline-flex items-center justify-center rounded-full px-5 py-2
        text-sm font-semibold transition-colors disabled:cursor-not-allowed
        disabled:opacity-60 ${VARIANT_CLASSES[variant]}`}
    >
      {pending && pendingLabel !== undefined ? pendingLabel : children}
    </button>
  );
}
