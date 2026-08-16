#!/bin/bash
# ==============================================================================
# setup-dev.sh — Levanta el entorno de desarrollo local de Kuestion + Kuaforia.
#
# Servicios que gestiona:
#   1. Kuaforia  (motor RAG)   → puerto ${KUAWORIA_PORT} (8000)  [o mock en 8080]
#   2. Kuestion  (vigilante)   → puerto ${KUESTION_PORT} (8001)
#   3. Worker de colas          → queue:work en background
#   4. Scheduler                → schedule:work en background (job horario, cleanup)
#   5. MySQL                    → se asume servicio del sistema (verifica conexión)
#   6. Redis                    → se asume servicio del sistema (verifica conexión)
#
# Uso:
#   ./scripts/setup-dev.sh start     Levanta todo (idempotente).
#   ./scripts/setup-dev.sh stop      Detiene todo lo que el script levantó.
#   ./scripts/setup-dev.sh restart   stop + start.
#   ./scripts/setup-dev.sh status    Estado de cada servicio.
#
# Configurable por variables de entorno (con defaults razonables):
#   KUAWORIA_DIR, KUESTION_DIR, MYSQL_USER, MYSQL_PASS, MYSQL_HOST, MYSQL_PORT,
#   KUAWORIA_PORT, KUESTION_PORT, MOCK_PORT, KUAWORIA_REAL (0|1), KUAWORIA_SLUG
# ==============================================================================

set -euo pipefail

# ------------------------------------------------------------------------------
# Configuración
# ------------------------------------------------------------------------------
KUAWORIA_DIR="${KUAWORIA_DIR:-/path/to/kuaforia}"
KUESTION_DIR="${KUESTION_DIR:-$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)}"

MYSQL_HOST="${MYSQL_HOST:-127.0.0.1}"
MYSQL_PORT="${MYSQL_PORT:-3306}"
MYSQL_USER="${MYSQL_USER:-root}"
MYSQL_PASS="${MYSQL_PASS:-}"              # o exportar MYSQL_PASS en el entorno

KUAWORIA_PORT="${KUAWORIA_PORT:-8000}"
KUESTION_PORT="${KUESTION_PORT:-8001}"
MOCK_PORT="${MOCK_PORT:-8080}"            # puerto del mock de Kuaforia (kuaforia-mock.php)
KUAWORIA_SLUG="${KUAWORIA_SLUG:-ispend}"  # tenant semilla de Kuaforia

# KUAWORIA_REAL=1 → usa el repo real de Kuaforia (requiere subdominios por tenant).
# KUAWORIA_REAL=0 (default en este repo) → usa kuaforia-mock.php, sin subdominios.
KUAWORIA_REAL="${KUAWORIA_REAL:-0}"

# Usuario de prueba que el script garantiza en la BD de Kuestion.
TEST_USER_EMAIL="${TEST_USER_EMAIL:-test@ispend.com}"
TEST_USER_PASS="${TEST_USER_PASS:-password123}"

# Directorio de runtime del script (pids, logs). Se crea si no existe.
RUNTIME_DIR="${KUESTION_DIR}/.dev"
PID_FILE="${RUNTIME_DIR}/services.pid"
LOG_DIR="${RUNTIME_DIR}/logs"

# Comandos disponibles
MYSQL="mysql --protocol=tcp -h${MYSQL_HOST} -P${MYSQL_PORT} -u${MYSQL_USER}"

# Colores (desactivados si no hay TTY)
if [ -t 1 ]; then
    GREEN='\033[0;32m'; YELLOW='\033[1;33m'; RED='\033[0;31m'; CYAN='\033[0;36m'; NC='\033[0m'
else
    GREEN=''; YELLOW=''; RED=''; CYAN=''; NC=''
fi
info()  { echo -e "${CYAN}[dev]${NC} $*"; }
ok()    { echo -e "${GREEN}[ok]${NC} $*"; }
warn()  { echo -e "${YELLOW}[warn]${NC} $*"; }
fail()  { echo -e "${RED}[error]${NC} $*"; }

# ------------------------------------------------------------------------------
# Helpers
# ------------------------------------------------------------------------------

# check_port <puerto> [nombre]: 0 si está libre, 1 si está ocupado.
check_port() {
    local port="$1" name="${2:-$1}"
    if ss -ltn 2>/dev/null | awk '{print $4}' | grep -qE "[:.]${port}$" \
       || lsof -iTCP:"${port}" -sTCP:LISTEN -t >/dev/null 2>&1; then
        warn "Puerto ${port} (${name}) ocupado — ¿otro servicio ya está corriendo?"
        return 1
    fi
    return 0
}

# is_running <pidfile>: 0 si el pid del archivo está vivo.
pid_alive() {
    local pidfile="$1"
    [ -f "${pidfile}" ] || return 1
    local pid
    pid=$(cat "${pidfile}" 2>/dev/null || true)
    [ -n "${pid}" ] && kill -0 "${pid}" 2>/dev/null
}

# kill_pidfile <pidfile> [nombre] — mata el proceso y limpia el archivo.
kill_pidfile() {
    local pidfile="$1" name="${2:-}"
    if [ -f "${pidfile}" ] && pid_alive "${pidfile}"; then
        local pid
        pid=$(cat "${pidfile}")
        kill "${pid}" 2>/dev/null || true
        # Graceful: espera hasta 5 s, luego SIGKILL.
        for _ in $(seq 1 10); do kill -0 "${pid}" 2>/dev/null || break; sleep 0.5; done
        kill -0 "${pid}" 2>/dev/null && kill -9 "${pid}" 2>/dev/null || true
        ok "Detenido ${name} (pid ${pid})"
    fi
    rm -f "${pidfile}"
}

# stop_one <pidfile> — mata el proceso del pidfile si está vivo.
stop_one() {
    local pidfile="$1"
    if [ -f "${pidfile}" ] && pid_alive "${pidfile}"; then
        kill_pidfile "${pidfile}" "$(basename "${pidfile}" .pid)"
    fi
}

# mysql_run <sql...> — ejecuta SQL como root (con password si aplica).
mysql_run() {
    if [ -n "${MYSQL_PASS}" ]; then
        MYSQL_PWD="${MYSQL_PASS}" ${MYSQL} -e "$1"
    else
        ${MYSQL} -e "$1"
    fi
}

# ensure_db <dbname> <usuario> — crea la base si no existe y da permisos.
# Degradación con gracia: si el usuario MySQL no tiene privilegios globales de
# CREATE DATABASE / GRANT (común en entornos donde las bases ya fueron creadas
# con root), se avisa y se continúa — la base ya existe y el app ya puede usarla.
ensure_db() {
    local db="$1" user="$2"
    info "Base de datos: asegurando '${db}'..."
    if ! mysql_run "CREATE DATABASE IF NOT EXISTS \`${db}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"; then
        warn "No se pudo crear '${db}' (permisos de CREATE DATABASE). Si ya existe, no es bloqueante."
        return
    fi
    mysql_run "GRANT ALL PRIVILEGES ON \`${db}\`.* TO '${user}'@'%'; GRANT ALL PRIVILEGES ON \`${db}\`.* TO '${user}'@'localhost'; FLUSH PRIVILEGES;" \
        || warn "No se pudieron otorgar permisos sobre '${db}' (ejecutar GRANT con un usuario con privilegios)."
    ok "Base '${db}' lista."
}

# ------------------------------------------------------------------------------
# 1) Verificación de prerrequisitos (MySQL, Redis, puertos)
# ------------------------------------------------------------------------------
check_prereqs() {
    info "Verificando prerrequisitos..."

    if ! mysql_run "SELECT 1;" >/dev/null 2>&1; then
        fail "No hay conexión a MySQL (${MYSQL_USER}@${MYSQL_HOST}:${MYSQL_PORT})."
        fail "Revisá que el servicio MySQL esté levantado y MYSQL_USER/MYSQL_PASS sean correctos."
        exit 1
    fi
    ok "MySQL conectado."

    if command -v redis-cli >/dev/null 2>&1 && ! redis-cli ping >/dev/null 2>&1; then
        warn "Redis no responde en localhost:6379 (Kuestion lo usa para cola/cache/sesión)."
    else
        ok "Redis disponible."
    fi

    # Puertos destino: si están ocupados por un proceso nuestro, ya está levantado.
    if ! check_port "${KUESTION_PORT}" "Kuestion"; then
        if [ -f "${RUNTIME_DIR}/kuestion.pid" ] && pid_alive "${RUNTIME_DIR}/kuestion.pid"; then
            ok "Kuestion ya está corriendo en :${KUESTION_PORT}."
        else
            fail "El puerto ${KUESTION_PORT} está ocupado por un proceso que no gestiona este script."
            exit 1
        fi
    fi

    if [ "${KUAWORIA_REAL}" = "1" ]; then
        if [ ! -d "${KUAWORIA_DIR}" ]; then
            fail "KUAWORIA_REAL=1 pero KUAWORIA_DIR=${KUAWORIA_DIR} no existe."
            fail "Ajustá KUAWORIA_DIR o usá KUAWORIA_REAL=0 (mock)."
            exit 1
        fi
        if ! check_port "${KUAWORIA_PORT}" "Kuaforia"; then
            [ -f "${RUNTIME_DIR}/kuaforia.pid" ] && pid_alive "${RUNTIME_DIR}/kuaforia.pid" \
                && ok "Kuaforia ya está corriendo en :${KUAWORIA_PORT}." \
                || { fail "Puerto ${KUAWORIA_PORT} ocupado por un proceso ajeno."; exit 1; }
        fi
    elif ! check_port "${MOCK_PORT}" "mock Kuaforia"; then
        [ -f "${RUNTIME_DIR}/mock.pid" ] && pid_alive "${RUNTIME_DIR}/mock.pid" \
            && ok "Mock de Kuaforia ya está corriendo en :${MOCK_PORT}." \
            || { fail "Puerto ${MOCK_PORT} ocupado por un proceso ajeno."; exit 1; }
    fi
}

# ------------------------------------------------------------------------------
# 2) Hosts: subdominios por tenant (solo con Kuaforia real)
# ------------------------------------------------------------------------------
setup_hosts() {
    [ "${KUAWORIA_REAL}" = "1" ] || { info "Modo mock: sin subdominios de tenant (se omite /etc/hosts)."; return; }

    local entry="127.0.0.1 ${KUAWORIA_SLUG}.localhost"
    if grep -qF "${entry}" /etc/hosts 2>/dev/null; then
        ok "/etc/hosts ya contiene '${entry}'."
        return
    fi

    info "Agregando '${entry}' a /etc/hosts..."
    if [ "$(id -u)" -eq 0 ]; then
        printf '\n# Kuestion dev\n%s\n' "${entry}" >> /etc/hosts
    else
        warn "No se pudo escribir /etc/hosts (falta sudo). Agregá manualmente:"
        warn "  ${entry}"
    fi
    ok "Hosts listo."
}

# ------------------------------------------------------------------------------
# 3) .env de Kuestion (a partir de .env.example si falta; nunca pisa el existente)
# ------------------------------------------------------------------------------
setup_kuestion_env() {
    local env_file="${KUESTION_DIR}/.env"
    if [ -f "${env_file}" ]; then
        ok "Kuestion: .env existente (no se modifica). Revisá que apunte a Kuaforia:"
        grep -E "^(KUAFORIA_BASE_URL|KUAFORIA_API_KEY|KUAFORIA_MCP_URL)" "${env_file}" \
            | sed 's/=.*/=<configurado>/' | sed 's/^/    /'
        return
    fi

    info "Kuestion: generando .env desde .env.example..."
    cp "${KUESTION_DIR}/.env.example" "${env_file}"
    # El .env.example arranca con una línea [TEMPLATE] que no es dotenv válido.
    sed -i '1{/^\[TEMPLATE\]$/d}' "${env_file}"

    # Genera APP_KEY si no existe.
    if grep -qE '^APP_KEY=$' "${env_file}"; then
        php "${KUESTION_DIR}/artisan" key:generate --force >/dev/null
    fi
    ok "Kuestion: .env generado."
}

# ------------------------------------------------------------------------------
# 4) Kuaforia: preparación (seed tenant + key) o mock
# ------------------------------------------------------------------------------
prepare_kuaforia() {
    if [ "${KUAWORIA_REAL}" = "1" ]; then
        info "Kuaforia real: preparando tenant '${KUAWORIA_SLUG}' y datos semilla..."
        # Cada proyecto de Kuaforia expone su propio setup; ajustar al que corresponda.
        if [ -f "${KUAWORIA_DIR}/artisan" ]; then
            (cd "${KUAWORIA_DIR}" && php artisan migrate --force && php artisan db:seed --force) \
                || warn "Seed de Kuaforia falló — revisá la configuración del proyecto Kuaforia."
        fi
        ok "Kuaforia: tenant y seed listos (asumido)."
    else
        info "Kuaforia mock: se usará '${KUESTION_DIR}/kuaforia-mock.php' en :${MOCK_PORT}."
        if [ ! -f "${KUESTION_DIR}/kuaforia-mock.php" ]; then
            fail "No existe kuaforia-mock.php — y KUAWORIA_REAL=0."
            exit 1
        fi
    fi
}

# ------------------------------------------------------------------------------
# 5) Base de datos de Kuestion: creación + migraciones + seeders
# ------------------------------------------------------------------------------
prepare_kuestion_db() {
    local db_user
    db_user=$(grep -E '^DB_USERNAME=' "${KUESTION_DIR}/.env" | cut -d= -f2-)
    db_user="${db_user:-kuestion}"

    ensure_db "kuestion" "${db_user}"
    ensure_db "kuaforia_tenant_${KUAWORIA_SLUG}" "${db_user}"

    info "Kuestion: migraciones + seeders..."
    (cd "${KUESTION_DIR}" && php artisan migrate --force && php artisan db:seed --force)
    ok "Kuestion: BD migrada y seedeada."
}

# ------------------------------------------------------------------------------
# 6) Servidores web (background)
# ------------------------------------------------------------------------------
start_kuaforia() {
    if [ "${KUAWORIA_REAL}" = "1" ]; then
        [ -f "${RUNTIME_DIR}/kuaforia.pid" ] && pid_alive "${RUNTIME_DIR}/kuaforia.pid" && return 0
        info "Kuaforia: arrancando en :${KUAWORIA_PORT}..."
        (cd "${KUAWORIA_DIR}" && php artisan serve --host=127.0.0.1 --port="${KUAWORIA_PORT}" \
            > "${LOG_DIR}/kuaforia.log" 2>&1 </dev/null & echo $! > "${RUNTIME_DIR}/kuaforia.pid")
        sleep 2
        ok "Kuaforia: http://${KUAWORIA_SLUG}.localhost:${KUAWORIA_PORT}"
    else
        [ -f "${RUNTIME_DIR}/mock.pid" ] && pid_alive "${RUNTIME_DIR}/mock.pid" && return 0
        info "Mock Kuaforia: arrancando en :${MOCK_PORT}..."
        (cd "${KUESTION_DIR}" && php -S 127.0.0.1:${MOCK_PORT} kuaforia-mock.php \
            > "${LOG_DIR}/kuaforia-mock.log" 2>&1 </dev/null & echo $! > "${RUNTIME_DIR}/mock.pid")
        sleep 1
        ok "Mock Kuaforia: http://127.0.0.1:${MOCK_PORT}"
    fi
}

start_kuestion() {
    [ -f "${RUNTIME_DIR}/kuestion.pid" ] && pid_alive "${RUNTIME_DIR}/kuestion.pid" && return 0
    info "Kuestion: arrancando en :${KUESTION_PORT}..."
    (cd "${KUESTION_DIR}" && php artisan serve --host=127.0.0.1 --port="${KUESTION_PORT}" \
        > "${LOG_DIR}/kuestion.log" 2>&1 </dev/null & echo $! > "${RUNTIME_DIR}/kuestion.pid")
    sleep 2
    ok "Kuestion: http://127.0.0.1:${KUESTION_PORT}"
}

start_worker() {
    [ -f "${RUNTIME_DIR}/worker.pid" ] && pid_alive "${RUNTIME_DIR}/worker.pid" && return 0
    info "Worker de colas: arrancando queue:work..."
    (cd "${KUESTION_DIR}" && php artisan queue:work --sleep=10 --tries=3 \
        > "${LOG_DIR}/queue-worker.log" 2>&1 </dev/null & echo $! > "${RUNTIME_DIR}/worker.pid")
    ok "Worker de colas corriendo."
}

start_scheduler() {
    [ -f "${RUNTIME_DIR}/scheduler.pid" ] && pid_alive "${RUNTIME_DIR}/scheduler.pid" && return 0
    info "Scheduler: arrancando schedule:work (job horario, cleanup diario)..."
    (cd "${KUESTION_DIR}" && php artisan schedule:work \
        > "${LOG_DIR}/scheduler.log" 2>&1 </dev/null & echo $! > "${RUNTIME_DIR}/scheduler.pid")
    ok "Scheduler corriendo."
}

# ------------------------------------------------------------------------------
# 7) Detención
# ------------------------------------------------------------------------------
stop_services() {
    info "Deteniendo servicios gestionados por el script..."

    # Los pidfiles son conocidos (uno por servicio); el maestro services.pid
    # ya no se usa (la redirección en subshell no compartía la variable).
    local had_any=0
    for pidfile in "${RUNTIME_DIR}/mock.pid" "${RUNTIME_DIR}/kuestion.pid" \
                   "${RUNTIME_DIR}/worker.pid" "${RUNTIME_DIR}/scheduler.pid"; do
        if [ -f "${pidfile}" ] && pid_alive "${pidfile}"; then
            kill_pidfile "${pidfile}" "$(basename "${pidfile}" .pid)"
            had_any=1
        else
            rm -f "${pidfile}"
        fi
    done

    rm -f "${PID_FILE}"
    if [ "${had_any}" = "1" ]; then
        ok "Todo detenido."
    else
        ok "No había servicios corriendo."
    fi
}

# ------------------------------------------------------------------------------
# 8) Estado
# ------------------------------------------------------------------------------
check_status() {
    echo -e "\n${CYAN}Estado del entorno de desarrollo${NC}"
    echo "------------------------------------------------"
    report() { # <nombre> <pidfile> <puerto?> <url?>
        local name="$1" pidfile="$2" port="${3:-}" url="${4:-}"
        if [ -f "${pidfile}" ] && pid_alive "${pidfile}"; then
            echo -e "  ${GREEN}●${NC} ${name}: corriendo (pid $(cat "${pidfile}"))${port:+ en :${port}}${url:+ → ${url}}"
        else
            echo -e "  ${RED}○${NC} ${name}: detenido"
        fi
    }
    report "Kuaforia (mock)"   "${RUNTIME_DIR}/mock.pid"      "${MOCK_PORT}"      "http://127.0.0.1:${MOCK_PORT}"
    report "Kuestion"          "${RUNTIME_DIR}/kuestion.pid"  "${KUESTION_PORT}"  "http://127.0.0.1:${KUESTION_PORT}"
    report "Worker de colas"   "${RUNTIME_DIR}/worker.pid"
    report "Scheduler"         "${RUNTIME_DIR}/scheduler.pid"
    echo "------------------------------------------------"
}

# ------------------------------------------------------------------------------
# 9) Mensaje final
# ------------------------------------------------------------------------------
show_summary() {
    local kua_url mock_label=""
    if [ "${KUAWORIA_REAL}" = "1" ]; then
        kua_url="http://${KUAWORIA_SLUG}.localhost:${KUAWORIA_PORT}"
    else
        kua_url="http://127.0.0.1:${MOCK_PORT}"
        mock_label=" (mock local de Kuaforia)"
    fi

    echo ""
    echo "=================================================================="
    echo -e "  ${GREEN}Entorno de desarrollo listo${NC}"
    echo "=================================================================="
    echo ""
    echo "  Kuestion:    http://127.0.0.1:${KUESTION_PORT}"
    echo "  Kuaforia:    ${kua_url}${mock_label}"
    echo "  MCP bridge:  ${kua_url}/api/v1/mcp"
    echo ""
    echo "  Credenciales de prueba:"
    echo "    Usuario:   ${TEST_USER_EMAIL}  /  ${TEST_USER_PASS}"
    echo "    Admin:     admin@kuestion.app  /  password"
    echo ""
    echo "  Comandos útiles:"
    echo "    ./scripts/setup-dev.sh status"
    echo "    ./scripts/setup-dev.sh stop"
    echo "    make dev            # atajo equivalente a start"
    echo "=================================================================="
}

# ------------------------------------------------------------------------------
# Arranque completo
# ------------------------------------------------------------------------------
start_services() {
    mkdir -p "${LOG_DIR}" "${RUNTIME_DIR}"

    check_prereqs
    setup_hosts
    setup_kuestion_env
    prepare_kuaforia
    prepare_kuestion_db
    start_kuaforia
    start_kuestion
    start_worker
    start_scheduler
    show_summary
}

# ------------------------------------------------------------------------------
# CLI
# ------------------------------------------------------------------------------
case "${1:-}" in
    start)
        start_services
        ;;
    stop)
        stop_services
        ;;
    restart)
        stop_services
        start_services
        ;;
    status)
        check_status
        ;;
    *)
        echo "Uso: $0 {start|stop|restart|status}"
        echo ""
        echo "Variables útiles: KUAWORIA_DIR, KUAWORIA_REAL (0|1), MYSQL_USER, MYSQL_PASS,"
        echo "                  KUAWORIA_PORT, KUESTION_PORT, MOCK_PORT, KUAWORIA_SLUG, TEST_USER_EMAIL"
        exit 1
        ;;
esac
