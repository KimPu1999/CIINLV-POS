import http from 'k6/http';
import { check, sleep } from 'k6';

export const options = {
  vus: Number(__ENV.K6_VUS || 5),
  duration: __ENV.K6_DURATION || '30s',
  thresholds: {
    http_req_failed: ['rate<0.01'],
    http_req_duration: ['p(95)<500'],
  },
};

export default function () {
  const response = http.get(`${__ENV.API_URL || 'http://127.0.0.1:8000'}/api/v1/health/ready`, {
    headers: {
      Accept: 'application/json',
      Authorization: `Bearer ${__ENV.CIINLV_TOKEN || ''}`,
    },
  });

  check(response, { 'readiness is successful': (res) => res.status === 200 });
  sleep(1);
}
