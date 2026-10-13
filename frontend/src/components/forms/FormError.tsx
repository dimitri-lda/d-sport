type FormErrorProps = {
  message: string | null;
};

/** Form-level (non-field) server error, announced to assistive tech. */
export function FormError({ message }: FormErrorProps) {
  if (message === null) {
    return null;
  }

  return (
    <p
      role="alert"
      className="rounded-md border border-red-200 bg-red-50 px-3 py-2
        text-sm text-red-700"
    >
      {message}
    </p>
  );
}
