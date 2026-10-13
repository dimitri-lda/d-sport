import { useId } from 'react';
import type { ChangeEvent, InputHTMLAttributes } from 'react';

type FormFieldProps = {
  label: string;
  name: string;
  type?: 'text' | 'email' | 'password';
  value: string;
  onChange: (value: string) => void;
  errors?: string[];
  autoComplete?: string;
  required?: boolean;
} & Pick<InputHTMLAttributes<HTMLInputElement>, 'placeholder' | 'disabled'>;

/** Labelled input wired to server field errors via aria-describedby. */
export function FormField({
  label,
  name,
  type = 'text',
  value,
  onChange,
  errors,
  autoComplete,
  required = false,
  placeholder,
  disabled,
}: FormFieldProps) {
  const id = useId();
  const errorId = `${id}-error`;
  const hasError = errors !== undefined && errors.length > 0;

  return (
    <div className="flex flex-col gap-1.5">
      <label htmlFor={id} className="text-sm font-medium text-ink">
        {label}
      </label>
      <input
        id={id}
        name={name}
        type={type}
        value={value}
        required={required}
        autoComplete={autoComplete}
        placeholder={placeholder}
        disabled={disabled}
        aria-invalid={hasError || undefined}
        aria-describedby={hasError ? errorId : undefined}
        className="rounded-md border border-dune bg-white px-3 py-2 text-sm text-ink
          outline-none transition-colors placeholder:text-ink-mute
          focus:border-cobalt focus:ring-2 focus:ring-cobalt/30
          aria-invalid:border-red-500 disabled:opacity-60"
        onChange={(event: ChangeEvent<HTMLInputElement>) =>
          onChange(event.target.value)
        }
      />
      {hasError ? (
        <p id={errorId} role="alert" className="text-xs text-red-600">
          {errors[0]}
        </p>
      ) : null}
    </div>
  );
}
