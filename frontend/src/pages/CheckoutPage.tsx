import { useState } from 'react';
import type { FormEvent } from 'react';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { Link, useNavigate } from 'react-router';
import { CART_QUERY_KEY } from '../api/cart';
import { getCheckout, placeOrder } from '../api/orders';
import type { CheckoutPayload, PlaceOrderInput } from '../api/orders';
import { useApp } from '../app/useApp';
import { Button } from '../components/forms/Button';
import { FormError } from '../components/forms/FormError';
import { FormField } from '../components/forms/FormField';
import { ProductImage } from '../components/ProductImage';
import { formatMoney } from '../lib/i18n/format';
import { toSubmissionError } from '../lib/forms';

export function CheckoutPage() {
  const { t, ready } = useApp();
  const checkout = useQuery({
    queryKey: ['checkout'],
    queryFn: ({ signal }) => getCheckout(signal),
    enabled: ready,
  });

  if (checkout.isPending) {
    return (
      <p className="py-16 text-center text-ink-mute">{t.common.loading}</p>
    );
  }

  if (checkout.isError || checkout.data === undefined) {
    return (
      <div className="py-16 text-center">
        <h1 className="font-display text-3xl font-bold">{t.checkout.title}</h1>
        <p role="alert" className="mt-4 text-ink-mute">
          {t.common.error}
        </p>
      </div>
    );
  }

  if (checkout.data.cart.items.length === 0) {
    return (
      <div className="py-16 text-center">
        <h1 className="font-display text-3xl font-bold">{t.checkout.title}</h1>
        <p className="mt-4 text-ink-mute">{t.checkout.empty}</p>
        <Link
          to="/cart"
          className="mt-6 inline-block rounded-full bg-cobalt px-6 py-2
            text-sm font-semibold text-cream"
        >
          {t.checkout.back_to_cart}
        </Link>
      </div>
    );
  }

  return <CheckoutForm payload={checkout.data} />;
}

function CheckoutForm({ payload }: { payload: CheckoutPayload }) {
  const { t, locale, csrfToken } = useApp();
  const navigate = useNavigate();
  const queryClient = useQueryClient();

  const { cart, user, address, countries } = payload;
  const [email, setEmail] = useState(user?.email ?? '');
  const [phone, setPhone] = useState('');
  const [firstName, setFirstName] = useState(address?.first_name ?? '');
  const [lastName, setLastName] = useState(address?.last_name ?? '');
  const [line1, setLine1] = useState(address?.line1 ?? '');
  const [line2, setLine2] = useState(address?.line2 ?? '');
  const [city, setCity] = useState(address?.city ?? '');
  const [postalCode, setPostalCode] = useState(address?.postal_code ?? '');
  const [country, setCountry] = useState(
    address?.country ?? countries[0]?.code ?? '',
  );
  const [deliveryMethod, setDeliveryMethod] = useState<'standard' | 'express'>(
    'standard',
  );
  const [pending, setPending] = useState(false);
  const [fields, setFields] = useState<Record<string, string[]>>({});
  const [genericError, setGenericError] = useState<string | null>(null);

  const summary = cart.summary;
  const deliveryCents = summary.delivery_quotes[deliveryMethod];
  const totalCents = summary.subtotal_cents + deliveryCents;
  const taxIncludedCents =
    summary.tax_cents + summary.delivery_tax_quotes[deliveryMethod];

  async function onSubmit(event: FormEvent<HTMLFormElement>): Promise<void> {
    event.preventDefault();
    if (csrfToken === null) {
      return;
    }
    setPending(true);
    setFields({});
    setGenericError(null);
    const input: PlaceOrderInput = {
      email,
      phone,
      first_name: firstName,
      last_name: lastName,
      line1,
      line2: line2 === '' ? null : line2,
      city,
      postal_code: postalCode,
      country,
      delivery_method: deliveryMethod,
    };
    try {
      const placed = await placeOrder(input, csrfToken);
      await queryClient.invalidateQueries({ queryKey: CART_QUERY_KEY });
      await queryClient.invalidateQueries({ queryKey: ['checkout'] });
      void navigate(`/orders/${placed.number}`);
    } catch (error) {
      const submission = toSubmissionError(error);
      setFields(submission.fields);
      const cartError = submission.fields.cart?.[0];
      setGenericError(
        cartError ?? (submission.generic ? t.checkout.order_error : null),
      );
    } finally {
      setPending(false);
    }
  }

  return (
    <div className="py-8 sm:py-12">
      <h1 className="font-display text-3xl font-bold">{t.checkout.title}</h1>

      <div className="mt-8 grid gap-10 lg:grid-cols-[1fr_340px]">
        <form onSubmit={onSubmit} className="flex flex-col gap-8" noValidate>
          <FormError message={genericError} />

          <section aria-labelledby="checkout-contact">
            <h2
              id="checkout-contact"
              className="font-display text-lg font-bold"
            >
              {t.checkout.contact_section}
            </h2>
            <div className="mt-3 grid gap-4 sm:grid-cols-2">
              <FormField
                label={t.checkout.email}
                name="email"
                type="email"
                value={email}
                onChange={setEmail}
                errors={fields.email}
                autoComplete="email"
                required
              />
              <FormField
                label={t.checkout.phone}
                name="phone"
                type="text"
                value={phone}
                onChange={setPhone}
                errors={fields.phone}
                autoComplete="tel"
                required
              />
            </div>
          </section>

          <section aria-labelledby="checkout-address">
            <h2
              id="checkout-address"
              className="font-display text-lg font-bold"
            >
              {t.checkout.address_section}
            </h2>
            <div className="mt-3 grid gap-4 sm:grid-cols-2">
              <FormField
                label={t.checkout.first_name}
                name="first_name"
                value={firstName}
                onChange={setFirstName}
                errors={fields.first_name}
                autoComplete="given-name"
                required
              />
              <FormField
                label={t.checkout.last_name}
                name="last_name"
                value={lastName}
                onChange={setLastName}
                errors={fields.last_name}
                autoComplete="family-name"
                required
              />
              <FormField
                label={t.checkout.line1}
                name="line1"
                value={line1}
                onChange={setLine1}
                errors={fields.line1}
                autoComplete="address-line1"
                required
              />
              <FormField
                label={t.checkout.line2}
                name="line2"
                value={line2}
                onChange={setLine2}
                errors={fields.line2}
                autoComplete="address-line2"
              />
              <FormField
                label={t.checkout.city}
                name="city"
                value={city}
                onChange={setCity}
                errors={fields.city}
                autoComplete="address-level2"
                required
              />
              <FormField
                label={t.checkout.postal_code}
                name="postal_code"
                value={postalCode}
                onChange={setPostalCode}
                errors={fields.postal_code}
                autoComplete="postal-code"
                required
              />
              <div className="flex flex-col gap-1.5">
                <label
                  htmlFor="checkout-country"
                  className="text-sm font-medium text-ink"
                >
                  {t.checkout.country}
                </label>
                <select
                  id="checkout-country"
                  name="country"
                  value={country}
                  required
                  aria-invalid={
                    fields.country !== undefined && fields.country.length > 0
                  }
                  onChange={(event) => setCountry(event.target.value)}
                  className="rounded-md border border-dune bg-white px-3 py-2
                    text-sm text-ink outline-none transition-colors
                    focus:border-cobalt focus:ring-2 focus:ring-cobalt/30"
                >
                  {countries.map((entry) => (
                    <option key={entry.code} value={entry.code}>
                      {entry.name}
                    </option>
                  ))}
                </select>
                {fields.country !== undefined && fields.country.length > 0 ? (
                  <p role="alert" className="text-xs text-red-600">
                    {fields.country[0]}
                  </p>
                ) : null}
              </div>
            </div>
          </section>

          <section aria-labelledby="checkout-delivery">
            <h2
              id="checkout-delivery"
              className="font-display text-lg font-bold"
            >
              {t.checkout.delivery_section}
            </h2>
            <div className="mt-3 flex flex-col gap-3" role="radiogroup">
              {(['standard', 'express'] as const).map((method) => (
                <label
                  key={method}
                  className={`flex cursor-pointer items-center justify-between
                    gap-4 rounded-xl border px-4 py-3 text-sm ${
                      deliveryMethod === method
                        ? 'border-cobalt bg-sand/40'
                        : 'border-dune'
                    }`}
                >
                  <span className="flex items-center gap-3">
                    <input
                      type="radio"
                      name="delivery_method"
                      value={method}
                      checked={deliveryMethod === method}
                      onChange={() => setDeliveryMethod(method)}
                      className="h-4 w-4 accent-cobalt"
                    />
                    <span className="font-medium">
                      {method === 'standard'
                        ? t.checkout.delivery_standard
                        : t.checkout.delivery_express}
                    </span>
                  </span>
                  <span className="font-semibold">
                    {formatMoney(
                      summary.delivery_quotes[method],
                      summary.currency,
                      locale,
                    )}
                  </span>
                </label>
              ))}
            </div>
            {fields.delivery_method !== undefined &&
            fields.delivery_method.length > 0 ? (
              <p role="alert" className="mt-2 text-xs text-red-600">
                {fields.delivery_method[0]}
              </p>
            ) : null}
          </section>

          <Button pending={pending} pendingLabel={t.common.loading}>
            {t.checkout.submit}
          </Button>
        </form>

        <aside className="h-fit rounded-2xl border border-dune/60 p-5">
          <h2 className="font-display text-lg font-bold">
            {t.checkout.summary_title}
          </h2>
          <ul className="mt-3 flex flex-col gap-3">
            {cart.items.map((item) => (
              <li key={item.id} className="flex items-center gap-3">
                <ProductImage
                  src={item.product.image_url}
                  alt={item.product.name}
                  className="h-12 w-12 rounded-lg object-cover"
                />
                <div className="min-w-0 flex-1">
                  <p className="truncate text-sm font-medium">
                    {item.product.name}
                  </p>
                  <p className="text-xs text-ink-mute">
                    {item.variant.name} × {item.quantity}
                  </p>
                </div>
                <p className="text-sm font-medium">
                  {formatMoney(item.line_total_cents, item.currency, locale)}
                </p>
              </li>
            ))}
          </ul>
          <dl
            className="mt-4 flex flex-col gap-2 border-t border-dune/60
            pt-4 text-sm"
          >
            <div className="flex justify-between">
              <dt className="text-ink-mute">{t.checkout.subtotal}</dt>
              <dd>
                {formatMoney(summary.subtotal_cents, summary.currency, locale)}
              </dd>
            </div>
            <div className="flex justify-between">
              <dt className="text-ink-mute">{t.checkout.delivery}</dt>
              <dd>{formatMoney(deliveryCents, summary.currency, locale)}</dd>
            </div>
            <div className="flex justify-between">
              <dt className="text-ink-mute">{t.checkout.tax_included}</dt>
              <dd>{formatMoney(taxIncludedCents, summary.currency, locale)}</dd>
            </div>
            <div
              className="flex justify-between border-t border-dune/60 pt-2
                text-base font-bold"
            >
              <dt>{t.checkout.total}</dt>
              <dd>{formatMoney(totalCents, summary.currency, locale)}</dd>
            </div>
          </dl>
        </aside>
      </div>
    </div>
  );
}
