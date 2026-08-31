#!/bin/bash
# =============================================================================
# dev-qbk.sh — Levanta Kuestion + QuBeKa mock para probar el conector Qbk.
#
# Servicios:
#   1. Kuestion     → http://127.0.0.1:8001
#   2. QuBeKa mock  → http://127.0.0.1:8002 (simula POST /query + GET /agent/me)
#   3. Worker colas → queue:work en background (Kuestion)
#   4. Scheduler    → schedule:work en background (job horario)
#
# Uso:
#   ./scripts/dev-qbk.sh start     Levanta todo.
#   ./scripts/dev-qbk.sh stop      Detiene todo.
#   ./scripts/dev-qbk.sh restart   stop + start.
#   ./scripts/dev-qbk.sh status    Estado de cada servicio.
#
# Variables configurables:
#   KUESTION_PORT   (default: 8001)
#   QBK_MOCK_PORT   (default: 8002)
# =============================================================================

set -uo pipefail

# -- Configuración --
KUESTION_DIR="${KUESTION_DIR:-$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)}"
KUESTION_PORT="${KUESTION_PORT:-8001}"
QBK_MOCK_PORT="${QBK_MOCK_PORT:-8002}"

RUNTIME_DIR="${KUESTION_DIR}/.dev"
LOG_DIR="${RUNTIME_DIR}/logs"

# -- Colores --
if [ -t 1 ]; then
    GREEN='\033[0;32m'; YELLOW='\033[1;33m'; RED='\033[0;31m'; CYAN='\033[0;36m'; NC='\033[0m'
else
    GREEN=''; YELLOW=''; RED=''; CYAN=''; NC=''
fi
info()  { echo -e "${CYAN}[qbk]${NC} $*"; }
ok()    { echo -e "${GREEN}[ok]${NC} $*"; }
warn()  { echo -e "${YELLOW}[warn]${NC} $*"; }
fail()  { echo -e "${RED}[error]${NC} $*"; }

# -- Helpers --
pid_alive() {
    local f="$1"; [ -f "$f" ] || return 1
    local pid; pid=$(cat "$f" 2>/dev/null || true)
    [ -n "$pid" ] && kill -0 "$pid" 2>/dev/null
}

kill_pidfile() {
    local f="$1" name="${2:-}"
    if [ -f "$f" ] && pid_alive "$f"; then
        local pid; pid=$(cat "$f")
        kill "$pid" 2>/dev/null || true
        # Esperar a que muera (max 5s)
        for _ in $(seq 1 10); do kill -0 "$pid" 2>/dev/null || break; sleep 0.5; done
        kill -0 "$pid" 2>/dev/null && kill -9 "$pid" 2>/dev/null || true
        ok "Detenido ${name} (pid ${pid})"
    fi
    rm -f "$f"
}

# Obtener PID que escucha en un puerto específico
get_pid_on_port() {
    local port="$1"
    ss -ltnp 2>/dev/null | awk -v p=":${port}" '$4 ~ p {print $NF}' | grep -oP 'pid=\K[0-9]+' | head -1
}

# Verificar si un proceso es nuestro (php artisan serve o php -S qbk-mock)
is_our_process() {
    local pid="$1"
    [ -z "$pid" ] && return 1
    local cmdline
    cmdline=$(cat /proc/"$pid"/cmdline 2>/dev/null | tr '\0' ' ' || true)
    echo "$cmdline" | grep -qE "(artisan serve|qbk-mock\.php|queue:work|schedule:work)" 2>/dev/null
}

# Matar procesos huérfanos que ocupan nuestros puertos
kill_orphan_if_ours() {
    local port="$1" name="$2"
    local pid
    pid=$(get_pid_on_port "$port")
    if [ -n "$pid" ] && is_our_process "$pid"; then
        warn "Proceso huérfano de ${name} encontrado (pid ${pid}). Limpiando..."
        kill "$pid" 2>/dev/null || true
        sleep 1
        # Matar también los hijos (php -S crea workers)
        local children
        children=$(pgrep -P "$pid" 2>/dev/null || true)
        for child in $children; do
            kill "$child" 2>/dev/null || true
        done
        sleep 1
        ok "Limpieza completada."
        return 0
    fi
    return 1
}

check_port() {
    local port="$1"
    if ss -ltn 2>/dev/null | awk '{print $4}' | grep -qE "[:.]${port}$" ; then
        return 1
    fi
    return 0
}

# -- Limpiar procesos huérfanos antes de verificar prerrequisitos --
cleanup_orphans() {
    info "Buscando procesos huérfanos..."
    local cleaned=0

    for port_info in "${KUESTION_PORT}:kuestion" "${QBK_MOCK_PORT}:qbk-mock"; do
        local port="${port_info%%:*}"
        local name="${port_info##*:}"
        if ! check_port "$port"; then
            # Verificar si ya tenemos un pidfile vivo
            local pidfile="${RUNTIME_DIR}/${name}.pid"
            if [ -f "$pidfile" ] && pid_alive "$pidfile"; then
                ok "${name} ya corriendo (pid $(cat "$pidfile"))."
            elif kill_orphan_if_ours "$port" "$name"; then
                cleaned=1
            else
                fail "Puerto ${port} ocupado por un proceso ajeno. No se puede liberar."
                echo "  Para liberarlo manualmente: lsof -iTCP:${port} -sTCP:LISTEN"
                exit 1
            fi
        fi
    done

    [ "$cleaned" = "1" ] && ok "Procesos huérfanos limpiados."
}

# -- Verificar prerrequisitos --
check_prereqs() {
    info "Verificando prerrequisitos..."

    # Redis
    if command -v redis-cli >/dev/null 2>&1; then
        if redis-cli ping >/dev/null 2>&1; then
            ok "Redis disponible."
        else
            warn "Redis no responde en :6379 (Kuestion lo usa para cola/cache)."
        fi
    else
        warn "redis-cli no instalado — no se pudo verificar Redis."
    fi

    # MySQL — verificar si está corriendo (sin autenticarse)
    if command -v mysql >/dev/null 2>&1; then
        if mysqladmin --protocol=tcp -h127.0.0.1 ping >/dev/null 2>&1; then
            ok "MySQL conectado."
        else
            warn "MySQL no responde — asegurate de que esté corriendo."
        fi
    else
        # Intentar con PHP como fallback (funciona sin cliente mysql instalado)
        if php -r "@new PDO('mysql:host=127.0.0.1', 'root', ''); echo 'ok';" >/dev/null 2>&1; then
            ok "MySQL conectado (via PDO)."
        else
            warn "MySQL no verificado — asegurate de que esté corriendo en :3306."
        fi
    fi
}

# -- Configurar .env de Kuestion para apuntar al mock de QuBeKa --
setup_env() {
    local env_file="${KUESTION_DIR}/.env"
    [ -f "$env_file" ] || { fail ".env de Kuestion no existe en ${env_file}"; exit 1; }

    local mock_url="http://127.0.0.1:${QBK_MOCK_PORT}"

    # QUBKA_API_URL → apunta al mock
    if grep -qE '^QUBKA_API_URL=' "$env_file"; then
        sed -i "s|^QUBKA_API_URL=.*|QUBKA_API_URL=${mock_url}|" "$env_file"
    else
        printf '\nQUBKA_API_URL=%s\n' "${mock_url}" >> "$env_file"
    fi

    ok "QUBKA_API_URL=${mock_url} (mock de QuBeKa)"
}

# -- Arrancar servicios --
start_qbk_mock() {
    [ -f "${RUNTIME_DIR}/qbk-mock.pid" ] && pid_alive "${RUNTIME_DIR}/qbk-mock.pid" && return 0
    info "QuBeKa mock: arrancando en :${QBK_MOCK_PORT}..."
    setsid bash -c "cd '${KUESTION_DIR}' && exec php -S 127.0.0.1:${QBK_MOCK_PORT} qbk-mock.php > '${LOG_DIR}/qbk-mock.log' 2>&1" </dev/null &
    sleep 2
    local pid
    pid=$(get_pid_on_port "${QBK_MOCK_PORT}")
    [ -n "${pid}" ] && echo "${pid}" > "${RUNTIME_DIR}/qbk-mock.pid"
    ok "QuBeKa mock: http://127.0.0.1:${QBK_MOCK_PORT}/query (POST) + /agent/me (GET)"
}

start_kuestion() {
    [ -f "${RUNTIME_DIR}/kuestion.pid" ] && pid_alive "${RUNTIME_DIR}/kuestion.pid" && return 0
    info "Kuestion: arrancando en :${KUESTION_PORT}..."
    setsid bash -c "cd '${KUESTION_DIR}' && exec php artisan serve --host=127.0.0.1 --port=${KUESTION_PORT} > '${LOG_DIR}/kuestion.log' 2>&1" </dev/null &
    sleep 3
    local pid
    pid=$(get_pid_on_port "${KUESTION_PORT}")
    [ -n "${pid}" ] && echo "${pid}" > "${RUNTIME_DIR}/kuestion.pid"
    ok "Kuestion: http://127.0.0.1:${KUESTION_PORT}"
}

start_worker() {
    [ -f "${RUNTIME_DIR}/worker.pid" ] && pid_alive "${RUNTIME_DIR}/worker.pid" && return 0
    info "Worker de colas: arrancando queue:work..."
    setsid bash -c "cd '${KUESTION_DIR}' && exec php artisan queue:work --sleep=10 --tries=3 > '${LOG_DIR}/queue-worker.log' 2>&1" </dev/null &
    sleep 2
    local pid
    pid=$(pgrep -f 'queue:work' | head -1)
    [ -n "${pid}" ] && echo "${pid}" > "${RUNTIME_DIR}/worker.pid"
    ok "Worker de colas corriendo."
}

start_scheduler() {
    [ -f "${RUNTIME_DIR}/scheduler.pid" ] && pid_alive "${RUNTIME_DIR}/scheduler.pid" && return 0
    info "Scheduler: arrancando schedule:work..."
    setsid bash -c "cd '${KUESTION_DIR}' && exec php artisan schedule:work > '${LOG_DIR}/scheduler.log' 2>&1" </dev/null &
    sleep 2
    local pid
    pid=$(pgrep -f 'schedule:work' | head -1)
    [ -n "${pid}" ] && echo "${pid}" > "${RUNTIME_DIR}/scheduler.pid"
    ok "Scheduler corriendo."
}

# -- Detener --
stop_services() {
    info "Deteniendo servicios..."
    local had_any=0
    for pidfile in "${RUNTIME_DIR}/qbk-mock.pid" "${RUNTIME_DIR}/kuestion.pid" \
                   "${RUNTIME_DIR}/worker.pid" "${RUNTIME_DIR}/scheduler.pid"; do
        if [ -f "$pidfile" ] && pid_alive "$pidfile"; then
            kill_pidfile "$pidfile" "$(basename "$pidfile" .pid)"
            had_any=1
        else
            rm -f "$pidfile"
        fi
    done

    # Matar también procesos huérfanos conocidos
    for port_info in "${KUESTION_PORT}:kuestion" "${QBK_MOCK_PORT}:qbk-mock"; do
        local port="${port_info%%:*}"
        local name="${port_info##*:}"
        local pid
        pid=$(get_pid_on_port "$port")
        if [ -n "$pid" ] && is_our_process "$pid"; then
            kill "$pid" 2>/dev/null || true
            # Matar hijos
            for child in $(pgrep -P "$pid" 2>/dev/null || true); do
                kill "$child" 2>/dev/null || true
            done
            ok "Detenido ${name} huérfano (pid ${pid})"
            had_any=1
        fi
    done

    # Matar jobs de Kuestion huérfanos
    pkill -f 'queue:work' 2>/dev/null || true
    pkill -f 'schedule:work' 2>/dev/null || true

    [ "$had_any" = "1" ] && ok "Todo detenido." || ok "No había servicios corriendo."
}

# -- Estado --
check_status() {
    echo ""
    echo -e "${CYAN}Estado del entorno Kuestion + QuBeKa${NC}"
    echo "------------------------------------------------"
    report() {
        local name="$1" f="$2" port="${3:-}" url="${4:-}"
        if [ -f "$f" ] && pid_alive "$f"; then
            echo -e "  ${GREEN}●${NC} ${name}: corriendo (pid $(cat "$f"))${port:+ en :${port}}${url:+ → ${url}}"
        else
            # Verificar si hay proceso huérfano
            local pid=""
            [ -n "$port" ] && pid=$(get_pid_on_port "$port")
            if [ -n "$pid" ] && is_our_process "$pid"; then
                echo -e "  ${YELLOW}◐${NC} ${name}: corriendo como huérfano (pid ${pid})${port:+ en :${port}} — sin pidfile"
            else
                echo -e "  ${RED}○${NC} ${name}: detenido"
            fi
        fi
    }
    report "QuBeKa mock"  "${RUNTIME_DIR}/qbk-mock.pid"  "${QBK_MOCK_PORT}" "http://127.0.0.1:${QBK_MOCK_PORT}"
    report "Kuestion"     "${RUNTIME_DIR}/kuestion.pid"  "${KUESTION_PORT}" "http://127.0.0.1:${KUESTION_PORT}"
    report "Worker colas" "${RUNTIME_DIR}/worker.pid"
    report "Scheduler"    "${RUNTIME_DIR}/scheduler.pid"
    echo "------------------------------------------------"
}

# -- Resumen --
show_summary() {
    local mock_url="http://127.0.0.1:${QBK_MOCK_PORT}"
    echo ""
    echo "=================================================================="
    echo -e "  ${GREEN}Entorno listo: Kuestion + QuBeKa${NC}"
    echo "=================================================================="
    echo ""
    echo "  Kuestion:     http://127.0.0.1:${KUESTION_PORT}"
    echo "  QuBeKa mock:  ${mock_url}"
    echo ""
    echo "  Endpoints de QuBeKa (mock):"
    echo "    POST ${mock_url}/query          ← Motor de Consulta"
    echo "    GET  ${mock_url}/agent/me       ← Resolución de identidad"
    echo ""
    echo "  Para conectar un repositorio Qbk en Kuestion:"
    echo "    1. Ir a http://127.0.0.1:${KUESTION_PORT}/settings"
    echo "    2. Conectar nuevo repositorio → seleccionar 'QuBeKa'"
    echo "    3. Token de agente: cualquier string (el mock lo acepta todo)"
    echo "    4. QUBKA_API_URL ya está seteado en .env → ${mock_url}"
    echo ""
    echo "  Para crear una pregunta Qbk:"
    echo "    1. Ir a http://127.0.0.1:${KUESTION_PORT}/questions/create"
    echo "    2. Seleccionar el repositorio QuBeKa en el dropdown"
    echo "    3. Escribir la pregunta y guardar"
    echo ""
    echo "  Credenciales de prueba:"
    echo "    Usuario: test@ispend.com / password123"
    echo "    Admin:   admin@kuestion.app / password"
    echo ""
    echo "  Logs:"
    echo "    Kuestion:    ${LOG_DIR}/kuestion.log"
    echo "    QuBeKa mock: ${LOG_DIR}/qbk-mock.log"
    echo "    Worker:      ${LOG_DIR}/queue-worker.log"
    echo "    Scheduler:   ${LOG_DIR}/scheduler.log"
    echo ""
    echo "  Comandos:"
    echo "    ./scripts/dev-qbk.sh status"
    echo "    ./scripts/dev-qbk.sh stop"
    echo "=================================================================="
}

# -- Arranque completo --
start_services() {
    mkdir -p "${LOG_DIR}" "${RUNTIME_DIR}"
    cleanup_orphans
    check_prereqs
    setup_env
    start_qbk_mock
    start_kuestion
    start_worker
    start_scheduler
    show_summary
}

# -- CLI --
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
        echo "Variables configurables:"
        echo "  KUESTION_PORT   (default: 8001)"
        echo "  QBK_MOCK_PORT   (default: 8002)"
        exit 1
        ;;
esac
