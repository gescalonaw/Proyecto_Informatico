// Prueba de concurrencia en línea (RB-1): 20 cajas intentan vender el mismo producto a la vez.
// Antes:  php artisan poc:stock 1 1 5     (deja el producto 1 con stock 5 en la sede 1)
// Correr: k6 run -e TOKEN=xxx ultima_unidad.js
// Esperado: exactamente 5 ventas aceptadas (201) y 15 rechazadas (409); stock final 0, nunca negativo.
import http from 'k6/http';
import { check } from 'k6';
import { Counter } from 'k6/metrics';

const BASE_URL = __ENV.BASE_URL || 'http://127.0.0.1:8000';
const TOKEN = __ENV.TOKEN || '';
const SEDE = __ENV.SEDE || '2'; // 2 = Tienda Centro (la bodega no vende)
const PRODUCTO = parseInt(__ENV.PRODUCTO || '1', 10);
const PRECIO = parseInt(__ENV.PRECIO || '1000', 10); // producto 1 del seeder: 990 + 10 = 1000

const aceptadas = new Counter('ventas_aceptadas');
const rechazadas = new Counter('ventas_rechazadas');

export const options = {
  scenarios: { carrera: { executor: 'per-vu-iterations', vus: 20, iterations: 1 } },
};

export default function () {
  const res = http.post(`${BASE_URL}/api/pos/v1/venta`, JSON.stringify({
    items: [{ producto_id: PRODUCTO, cantidad: 1 }],
    pagos: [{ metodo: 'efectivo', monto: PRECIO }],
  }), { headers: { 'Content-Type': 'application/json', Accept: 'application/json',
                   Authorization: `Bearer ${TOKEN}`, 'X-POS-Location-Id': SEDE } });
  if (res.status === 201) aceptadas.add(1);
  else if (res.status === 409) rechazadas.add(1);
  check(res, { 'aceptada o rechazada por stock': (r) => r.status === 201 || r.status === 409 });
}
