# ==============================================================================
# Kuestion — atajos para el entorno de desarrollo (envuelven scripts/setup-dev.sh)
#
#   make dev              Levanta el entorno completo (Kuaforia[mock] + Kuestion
#                         + worker + scheduler + BD + seeders).
#   make dev-stop         Detiene todo lo que levantó el script.
#   make dev-restart      Reinicia el entorno.
#   make dev-status       Estado de cada servicio.
#
# Variables que se pueden pasar por línea de comandos:
#   make dev KUAWORIA_REAL=1 KUAWORIA_DIR=/ruta/a/kuaforia MYSQL_PASS=secret
# ==============================================================================

SHELL := /bin/bash
SETUP := ./scripts/setup-dev.sh

.PHONY: dev dev-stop dev-restart dev-status

dev:
	@$(SETUP) start

dev-stop:
	@$(SETUP) stop

dev-restart:
	@$(SETUP) restart

dev-status:
	@$(SETUP) status

# Atajo directo a la app (sin levantar nada nuevo).
serve:
	@php artisan serve --host=127.0.0.1 --port=8001
