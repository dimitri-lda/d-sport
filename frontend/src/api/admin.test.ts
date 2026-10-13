import { describe, expect, it, vi } from 'vitest';
import { getAdminOrder, getAdminOrders, updateAdminOrderStatus } from './admin';

function stubFetch(response: Response) {
  const fetch = vi.fn().mockResolvedValue(response);
  vi.stubGlobal('fetch', fetch);

  return fetch;
}

function json(payload: unknown, init: ResponseInit = {}) {
  return new Response(JSON.stringify(payload), {
    headers: { 'Content-Type': 'application/json' },
    ...init,
  });
}

const listPayload = {
  data: [
    {
      id: 5,
      number: 'SP-DEADBEEF',
      status: 'pending_payment',
      email: 'a@b.test',
      delivery_method: 'standard',
      total_cents: 3082,
      currency: 'EUR',
      items_count: 1,
      created_at: '2026-01-01T10:00:00+00:00',
    },
  ],
  meta: { page: 1, per_page: 20, total: 1 },
  statuses: ['pending_payment', 'paid'],
};

describe('admin orders API', () => {
  it('serializes filters into the list query string', async () => {
    const fetch = stubFetch(json(listPayload));

    const page = await getAdminOrders({
      q: 'dead',
      status: 'paid',
      from: '2026-01-01',
      to: '2026-01-31',
      page: 2,
    });

    expect(page.items).toHaveLength(1);
    expect(page.meta.total).toBe(1);
    expect(fetch).toHaveBeenCalledWith(
      '/api/v1/admin/orders?q=dead&status=paid&from=2026-01-01&to=2026-01-31&page=2',
      expect.objectContaining({ method: 'GET' }),
    );
  });

  it('omits empty filters and page 1', async () => {
    const fetch = stubFetch(json(listPayload));

    await getAdminOrders({ q: '', status: 'paid', page: 1 });

    expect(fetch).toHaveBeenCalledWith(
      '/api/v1/admin/orders?status=paid',
      expect.objectContaining({ method: 'GET' }),
    );
  });

  it('gets an order detail with next statuses', async () => {
    const fetch = stubFetch(
      json({
        data: {
          order: { id: 5, number: 'SP-DEADBEEF', status: 'paid' },
          next_statuses: ['processing', 'refunded'],
        },
      }),
    );

    const detail = await getAdminOrder(5);

    expect(detail.order.number).toBe('SP-DEADBEEF');
    expect(detail.next_statuses).toEqual(['processing', 'refunded']);
    expect(fetch).toHaveBeenCalledWith(
      '/api/v1/admin/orders/5',
      expect.objectContaining({ method: 'GET' }),
    );
  });

  it('patches the status with the CSRF header', async () => {
    const fetch = stubFetch(
      json({ data: { status: 'paid', next_statuses: ['processing'] } }),
    );

    const result = await updateAdminOrderStatus(5, 'paid', 'csrf-9');

    expect(result.status).toBe('paid');
    expect(fetch).toHaveBeenCalledWith(
      '/api/v1/admin/orders/5/status',
      expect.objectContaining({
        method: 'PATCH',
        headers: expect.objectContaining({
          'X-CSRF-Token': 'csrf-9',
          'Content-Type': 'application/json',
        }),
        body: JSON.stringify({ status: 'paid' }),
      }),
    );
  });
});
