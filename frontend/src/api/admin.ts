import { request } from './client';
import type { Order } from './orders';

export type AdminOrderSummary = {
  id: number;
  number: string;
  status: string;
  email: string;
  delivery_method: string;
  total_cents: number;
  currency: string;
  items_count: number;
  created_at: string | null;
};

export type AdminOrdersFilters = {
  q?: string;
  status?: string;
  from?: string;
  to?: string;
  page?: number;
};

export type AdminOrdersPage = {
  items: AdminOrderSummary[];
  meta: { page: number; per_page: number; total: number };
  statuses: string[];
};

export type AdminOrderDetail = {
  order: Order;
  next_statuses: string[];
};

export async function getAdminOrders(
  filters: AdminOrdersFilters,
  signal?: AbortSignal,
): Promise<AdminOrdersPage> {
  const params = new URLSearchParams();
  if (filters.q !== undefined && filters.q !== '') {
    params.set('q', filters.q);
  }
  if (filters.status !== undefined && filters.status !== '') {
    params.set('status', filters.status);
  }
  if (filters.from !== undefined && filters.from !== '') {
    params.set('from', filters.from);
  }
  if (filters.to !== undefined && filters.to !== '') {
    params.set('to', filters.to);
  }
  if (filters.page !== undefined && filters.page > 1) {
    params.set('page', String(filters.page));
  }
  const query = params.toString();
  const payload = await request<{
    data: AdminOrderSummary[];
    meta: AdminOrdersPage['meta'];
    statuses: string[];
  }>(`/v1/admin/orders${query === '' ? '' : `?${query}`}`, { signal });

  return {
    items: payload.data,
    meta: payload.meta,
    statuses: payload.statuses,
  };
}

export async function getAdminOrder(
  id: number,
  signal?: AbortSignal,
): Promise<AdminOrderDetail> {
  const payload = await request<{ data: AdminOrderDetail }>(
    `/v1/admin/orders/${id}`,
    { signal },
  );

  return payload.data;
}

export async function updateAdminOrderStatus(
  id: number,
  status: string,
  csrfToken: string,
): Promise<{ status: string; next_statuses: string[] }> {
  const payload = await request<{
    data: { status: string; next_statuses: string[] };
  }>(`/v1/admin/orders/${id}/status`, {
    method: 'PATCH',
    body: { status },
    csrfToken,
  });

  return payload.data;
}
