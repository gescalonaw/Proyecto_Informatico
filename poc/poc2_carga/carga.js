// PoC-2 · Prueba de carga con k6
// Uso (Windows, desde esta carpeta):
//   k6 run carga.js                                   -> contra el mock local (http://localhost:8080)
//   k6 run -e BASE_URL=http://localhost:8000 -e TOKEN=xxx -e SEDE=1 carga.js   -> contra el prototipo
//   k6 run -e RATE=20 -e DURATION=2m carga.js         -> cambiar tasa y duración
// Criterio del SRS (RNF-R-1, RNF-R-2): p95 <= 300 ms y errores < 1 % a 30 req/s sostenidos.
import http from 'k6/http';
import { check } from 'k6';

const BASE_URL = __ENV.BASE_URL || 'http://localhost:8080'; // mock; el prototipo usa http://127.0.0.1:8000
const TOKEN = __ENV.TOKEN || 'token-de-prueba';
const SEDE = __ENV.SEDE || '2'; // 2 = Tienda Centro (la bodega no vende)
const RATE = parseInt(__ENV.RATE || '30', 10);
const DURATION = __ENV.DURATION || '30m';
// Códigos de barra a buscar: reemplazar por los de los datos de prueba del prototipo.
const CODIGOS = (__ENV.CODIGOS || '7801000000001,7801000000002,7801000000003,7801000000004,7801000000005').split(',');

export const options = {
  scenarios: {
    punta: {
      executor: 'constant-arrival-rate',
      rate: RATE,
      timeUnit: '1s',
      duration: DURATION,
      preAllocatedVUs: 50,
      maxVUs: 200,
    },
  },
  thresholds: {
    http_req_duration: ['p(95)<300'],
    http_req_failed: ['rate<0.01'],
  },
  summaryTrendStats: ['avg', 'med', 'p(90)', 'p(95)', 'p(99)', 'max'],
};

let yaMostrado = false;

export default function () {
  const codigo = CODIGOS[Math.floor(Math.random() * CODIGOS.length)];
  const res = http.get(`${BASE_URL}/api/pos/v1/productos?buscar=${codigo}`, {
    headers: { Accept: 'application/json', Authorization: `Bearer ${TOKEN}`, 'X-POS-Location-Id': SEDE },
  });
  check(res, { 'status 200': (r) => r.status === 200 });
  if (res.status !== 200 && !yaMostrado) {
    yaMostrado = true; // muestra solo el primer error de cada usuario virtual
    console.log(`ERROR ${res.status}: ${String(res.body).substring(0, 300)}`);
  }
}
