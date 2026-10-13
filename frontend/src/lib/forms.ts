import { ApiError } from '../api/client';
import type { ApiFields } from '../api/client';

export type SubmissionError = {
  fields: ApiFields;
  /** True when the failure has no field detail — show a generic message. */
  generic: boolean;
};

/** Normalizes a failed mutation into field errors plus a generic flag. */
export function toSubmissionError(error: unknown): SubmissionError {
  if (error instanceof ApiError) {
    const fields = error.fields ?? {};

    return { fields, generic: Object.keys(fields).length === 0 };
  }

  return { fields: {}, generic: true };
}
