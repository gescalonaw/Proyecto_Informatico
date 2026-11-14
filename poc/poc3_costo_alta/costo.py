"""PoC-3 (parte 1) · Costo mensual de operar el producto.

Edita los valores de COTIZACION con precios reales cotizados (los de abajo son supuestos
del SRS, por cotizar). Uso: python costo.py
"""
COTIZACION = {
    'servidor_produccion_usd_mes': 24,   # VPS 2 vCPU / 4 GB (supuesto)
    'respaldos_usd_mes': 5,              # almacenamiento de respaldos (supuesto)
    'dominio_clp_anio': 10000,           # dominio .cl (NIC publica ≈ CLP 9.990)
}
CLP_POR_USD = 950        # supuesto S-8
NEGOCIOS = 20            # escenario del criterio de la PoC-3
TOPE_CLP_MES = 100000    # criterio de descarte del SRS para 20 negocios

def main():
    prod = COTIZACION['servidor_produccion_usd_mes'] * CLP_POR_USD
    resp = COTIZACION['respaldos_usd_mes'] * CLP_POR_USD
    dom = COTIZACION['dominio_clp_anio'] / 12
    total = prod + resp + dom
    print(f"Servidor de producción: CLP {prod:,.0f}/mes".replace(',', '.'))
    print(f"Respaldos:              CLP {resp:,.0f}/mes".replace(',', '.'))
    print(f"Dominio:                CLP {dom:,.0f}/mes".replace(',', '.'))
    print(f"TOTAL:                  CLP {total:,.0f}/mes".replace(',', '.'))
    print(f"Por negocio ({NEGOCIOS}):      CLP {total/NEGOCIOS:,.0f}/mes".replace(',', '.'))
    print('CRITERIO CUMPLIDO' if total <= TOPE_CLP_MES else 'CRITERIO NO CUMPLIDO',
          f"(tope CLP {TOPE_CLP_MES:,.0f}/mes para {NEGOCIOS} negocios)".replace(',', '.'))
    print('En el semestre (todo en local): CLP 0 de infraestructura.')

if __name__ == '__main__':
    main()
