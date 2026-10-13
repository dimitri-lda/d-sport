import { check, sleep } from 'k6';
import http from 'k6/http';

const baseUrl = (__ENV.BASE_URL || 'http://localhost:8080').replace(/\/$/, '');

export const options = {
  scenarios: {
    health_smoke: {
      executor: 'shared-iterations',
      vus: 1,
      iterations: 3,
      maxDuration: '15s',
      gracefulStop: '0s',
    },
  },
  thresholds: {
    checks: ['rate==1'],
    http_req_failed: ['rate==0'],
  },
};

export default function () {
  const response = http.get(`${baseUrl}/api/health/live`, {
    timeout: '3s',
    redirects: 0,
    headers: { Accept: 'application/json' },
    tags: { name: 'GET /api/health/live' },
  });

  let body = null;
  try {
    body = response.json();
  } catch {
    // A malformed/empty response must fail the JSON check below.
  }

  check(response, {
    'liveness returns HTTP 200': (result) => result.status === 200,
    'liveness returns status ok': () => body?.status === 'ok',
  });

  sleep(0.5);
}
