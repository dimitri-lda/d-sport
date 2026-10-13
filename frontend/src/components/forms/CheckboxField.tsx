import { useId } from 'react';

type CheckboxFieldProps = {
  label: string;
  name: string;
  checked: boolean;
  onChange: (checked: boolean) => void;
  errors?: string[];
  disabled?: boolean;
};

export function CheckboxField({
  label,
  name,
  checked,
  onChange,
  errors,
  disabled,
}: CheckboxFieldProps) {
  const id = useId();
  const errorId = `${id}-error`;
  const hasError = errors !== undefined && errors.length > 0;

  return (
    <div className="flex flex-col gap-1.5">
      <label
        htmlFor={id}
        className="flex items-start gap-2 text-sm text-ink select-none"
      >
        <input
          id={id}
          name={name}
          type="checkbox"
          checked={checked}
          disabled={disabled}
          aria-invalid={hasError || undefined}
          aria-describedby={hasError ? errorId : undefined}
          className="mt-0.5 h-4 w-4 rounded border-dune accent-cobalt"
          onChange={(event) => onChange(event.target.checked)}
        />
        <span>{label}</span>
      </label>
      {hasError ? (
        <p id={errorId} role="alert" className="text-xs text-red-600">
          {errors[0]}
        </p>
      ) : null}
    </div>
  );
}
