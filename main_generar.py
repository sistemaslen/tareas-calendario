"""
Entry-point único para generar todas las tareas HR.
Corre los 7 generadores en secuencia.

Uso:
    python main_generar.py
    python main_generar.py --only ALTA_IMSS,BAJA_IMSS
"""
import sys
import argparse
import logging

logging.basicConfig(level=logging.INFO, format='%(asctime)s | %(message)s', datefmt='%Y-%m-%d %H:%M:%S',
                    handlers=[logging.StreamHandler()])
log = logging.getLogger(__name__)

SCRIPTS = {
    'ALTA_IMSS':      ('scripts.generar_alta_imss',      'run'),
    'BAJA_IMSS':      ('scripts.generar_baja_imss',      'run'),
    'EVALUACIONES':   ('scripts.generar_evaluaciones',   'run'),
    'FIRMA_CONTRATO': ('scripts.generar_firma_contrato', 'run'),
    'ONBOARDING_BASE':('scripts.generar_onboarding_base','run'),
    'PRIMER_CONTRATO':('scripts.generar_primer_contrato','run'),
    'SEGUNDO_CONTRATO':('scripts.generar_segundo_contrato','run'),
}


def main():
    parser = argparse.ArgumentParser(description='Genera tareas HR para todos los tipos.')
    parser.add_argument('--only', help='Coma-separado de keys a correr. Ej: ALTA_IMSS,BAJA_IMSS')
    args = parser.parse_args()

    keys = [k.strip().upper() for k in args.only.split(',')] if args.only else list(SCRIPTS.keys())
    unknown = [k for k in keys if k not in SCRIPTS]
    if unknown:
        log.error('Keys no reconocidos: %s. Disponibles: %s', unknown, list(SCRIPTS.keys()))
        sys.exit(1)

    log.info('=== INICIO main_generar — scripts: %s ===', keys)
    errors = []

    for key in keys:
        module_path, fn_name = SCRIPTS[key]
        log.info('--- Ejecutando %s ---', key)
        try:
            import importlib
            mod = importlib.import_module(module_path)
            getattr(mod, fn_name)()
        except Exception as exc:
            log.exception('Error en %s: %s', key, exc)
            errors.append(key)

    log.info('=== FIN main_generar — errores en: %s ===', errors if errors else 'ninguno')
    if errors:
        sys.exit(1)


if __name__ == '__main__':
    main()
