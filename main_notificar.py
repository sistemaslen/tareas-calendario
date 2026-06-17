"""
Entry-point único para enviar todas las notificaciones HR semanales.
Corre los 8 notificadores en secuencia (correo + notificaciones in-app).

Uso:
    python main_notificar.py
    python main_notificar.py --only ALTA_IMSS,JEFE_EVALUACION
"""
import sys
import argparse
import logging

logging.basicConfig(level=logging.INFO, format='%(asctime)s | %(message)s', datefmt='%Y-%m-%d %H:%M:%S',
                    handlers=[logging.StreamHandler()])
log = logging.getLogger(__name__)

SCRIPTS = {
    'ALTA_IMSS':        ('scripts.notificar_alta_imss',       'run'),
    'BAJA_IMSS':        ('scripts.notificar_baja_imss',       'run'),
    'EVALUACIONES':     ('scripts.notificar_evaluaciones',    'run'),
    'FIRMA_CONTRATO':   ('scripts.notificar_firma_contrato',  'run'),
    'ONBOARDING_BASE':  ('scripts.notificar_onboarding_base', 'run'),
    'PRIMER_CONTRATO':  ('scripts.notificar_primer_contrato', 'run'),
    'SEGUNDO_CONTRATO': ('scripts.notificar_segundo_contrato','run'),
    'JEFE_EVALUACION':  ('scripts.notificar_jefe_evaluacion', 'run'),
}


def main():
    parser = argparse.ArgumentParser(description='Envía notificaciones semanales HR.')
    parser.add_argument('--only', help='Coma-separado de keys a correr. Ej: ALTA_IMSS,JEFE_EVALUACION')
    args = parser.parse_args()

    keys = [k.strip().upper() for k in args.only.split(',')] if args.only else list(SCRIPTS.keys())
    unknown = [k for k in keys if k not in SCRIPTS]
    if unknown:
        log.error('Keys no reconocidos: %s. Disponibles: %s', unknown, list(SCRIPTS.keys()))
        sys.exit(1)

    log.info('=== INICIO main_notificar — scripts: %s ===', keys)
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

    log.info('=== FIN main_notificar — errores en: %s ===', errors if errors else 'ninguno')
    if errors:
        sys.exit(1)


if __name__ == '__main__':
    main()
