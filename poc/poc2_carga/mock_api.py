"""Servidor FALSO solo para comprobar que k6 está bien instalado y configurado.

NO sirve como resultado de la PoC-2: responde desde memoria, sin base de datos ni Laravel.
El resultado válido se obtiene corriendo carga.js contra el prototipo real.
Uso: python mock_api.py   (queda escuchando en http://localhost:8080; Ctrl+C para detener)
"""
import json
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from urllib.parse import urlparse, parse_qs

PRODUCTOS = [{"id": i, "sku": f"SKU-{i:04d}", "codigo_barras": f"78010000{i:05d}",
              "nombre": f"Producto {i}", "precio": 1000 + 10 * i} for i in range(1, 501)]

class Handler(BaseHTTPRequestHandler):
    def do_GET(self):
        u = urlparse(self.path)
        if u.path != '/api/pos/v1/productos':
            self.send_response(404); self.end_headers(); return
        if not self.headers.get('Authorization', '').startswith('Bearer '):
            self.send_response(401); self.end_headers(); return
        q = parse_qs(u.query).get('buscar', [''])[0]
        datos = [p for p in PRODUCTOS if q in p['codigo_barras'] or q in p['sku']][:20]
        cuerpo = json.dumps({"productos": datos}).encode()
        self.send_response(200)
        self.send_header('Content-Type', 'application/json')
        self.send_header('Content-Length', str(len(cuerpo)))
        self.end_headers(); self.wfile.write(cuerpo)
    def log_message(self, *args):
        pass

if __name__ == '__main__':
    print('Mock escuchando en http://localhost:8080 (solo para probar k6). Ctrl+C para salir.')
    ThreadingHTTPServer(('127.0.0.1', 8080), Handler).serve_forever()
