#!/bin/bash
# =============================================================================
# dev-qbk.sh — Levanta Kuestion + QuBeKa (real) para probar la integración.
#
# Servicios:
#   1. QuBeKa web     → http://127.0.0.1:8000
#   2. QuBeKa worker  → queue:work (database queue, Kuestion)
#   3. Kuestion web   → http://127.0.0.1:8001
#   4. Kuestion worker→ queue:work (Kuestion)
#   5. Kuestion sched → schedule:work (job horario de vigilancia)
#
# Uso:
#   ./scripts/dev-qbk.sh start     Levanta todo.
#   ./scripts/dev-qbk.sh stop      Detiene todo.
#   ./scripts/dev-qbk.sh restart   stop + start.
#   ./scripts/dev-qbk.sh status    Estado de cada servicio.
#
# Variables configurables:
#   KUESTION_PORT   (default: 8001)
#   QUBEKA_PORT     (default: 8000)
# =============================================================================

set -uo pipefail

# -- Configuración --
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
KUESTION_DIR="${KUESTION_DIR:-$(cd "${SCRIPT_DIR}/.." && pwd)}"
QUBEKA_DIR="${QUBEKA_DIR:-$(cd "${KUESTION_DIR}/../QuBeKa/qubeka" && pwd)}"

KUESTION_PORT="${KUESTION_PORT:-8001}"
QUBEKA_PORT="${QUBEKA_PORT:-8000}"

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
        for _ in $(seq 1 10); do kill -0 "$pid" 2>/dev/null || break; sleep 0.5; done
        kill -0 "$pid" 2>/dev/null && kill -9 "$pid" 2>/dev/null || true
        ok "Detenido ${name} (pid ${pid})"
    fi
    rm -f "$f"
}

get_pid_on_port() {
    local port="$1"
    ss -ltnp 2>/dev/null | awk -v p=":${port}" '$4 ~ p {print $NF}' | grep -oP 'pid=\K[0-9]+' | head -1
}

is_our_process() {
    local pid="$1"
    [ -z "$pid" ] && return 1
    local cmdline
    cmdline=$(cat /proc/"$pid"/cmdline 2>/dev/null | tr '\0' ' ' || true)
    echo "$cmdline" | grep -qE "(artisan serve|qubeka|kuestion|queue:work|schedule:work)" 2>/dev/null
}

kill_orphan_if_ours() {
    local port="$1" name="$2"
    local pid
    pid=$(get_pid_on_port "$port")
    if [ -n "$pid" ] && is_our_process "$pid"; then
        warn "Proceso huérfano de ${name} encontrado (pid ${pid}). Limpiando..."
        kill "$pid" 2>/dev/null || true
        sleep 1
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
    if ss -ltn 2>/dev/null | awk '{print $4}' | grep -qE "[:.]${port}$"; then
        return 1
    fi
    return 0
}

# -- Limpiar procesos huérfanos --
cleanup_orphans() {
    info "Buscando procesos huérfanos..."
    local cleaned=0

    for port_info in "${QUBEKA_PORT}:qubeka" "${KUESTION_PORT}:kuestion"; do
        local port="${port_info%%:*}"
        local name="${port_info##*:}"
        if ! check_port "$port"; then
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
            warn "Redis no responde en :6379."
        fi
    else
        warn "redis-cli no instalado."
    fi

    # MySQL
    if command -v mysqladmin >/dev/null 2>&1; then
        if mysqladmin --protocol=tcp -h127.0.0.1 ping >/dev/null 2>&1; then
            ok "MySQL conectado."
        else
            warn "MySQL no responde en :3306."
        fi
    else
        warn "mysqladmin no instalado."
    fi

    # Verificar que QuBeKa existe
    if [ ! -f "${QUBEKA_DIR}/artisan" ]; then
        fail "QuBeKa no encontrado en ${QUBEKA_DIR}"
        exit 1
    fi
    ok "QuBeKa encontrado en ${QUBEKA_DIR}"

    # Verificar que Kuestion existe
    if [ ! -f "${KUESTION_DIR}/artisan" ]; then
        fail "Kuestion no encontrado en ${KUESTION_DIR}"
        exit 1
    fi
    ok "Kuestion encontrado en ${KUESTION_DIR}"
}

# -- Configurar .env de Kuestion para apuntar a QuBeKa real --
setup_env() {
    local env_file="${KUESTION_DIR}/.env"
    [ -f "$env_file" ] || { fail ".env de Kuestion no existe en ${env_file}"; exit 1; }

    local qubeka_url="http://127.0.0.1:${QUBEKA_PORT}"

    # QUBKA_API_URL → apunta a QuBeKa real
    if grep -qE '^QUBKA_API_URL=' "$env_file"; then
        sed -i "s|^QUBKA_API_URL=.*|QUBKA_API_URL=${qubeka_url}|" "$env_file"
    else
        printf '\nQUBKA_API_URL=%s\n' "${qubeka_url}" >> "$env_file"
    fi

    ok "QUBKA_API_URL=${qubeka_url} (QuBeKa real)"
}

# -- Arrancar QuBeKa --
start_qubeka_web() {
    [ -f "${RUNTIME_DIR}/qubeka.pid" ] && pid_alive "${RUNTIME_DIR}/qubeka.pid" && return 0
    info "QuBeKa web: arrancando en :${QUBEKA_PORT}..."
    setsid bash -c "cd '${QUBEKA_DIR}' && exec php artisan serve --host=127.0.0.1 --port=${QUBEKA_PORT} > '${LOG_DIR}/qubeka-web.log' 2>&1" </dev/null &
    sleep 3
    local pid
    pid=$(get_pid_on_port "${QUBEKA_PORT}")
    [ -n "${pid}" ] && echo "${pid}" > "${RUNTIME_DIR}/qubeka.pid"
    ok "QuBeKa web: http://127.0.0.1:${QUBEKA_PORT}"
}

start_qubeka_worker() {
    [ -f "${RUNTIME_DIR}/qubeka-worker.pid" ] && pid_alive "${RUNTIME_DIR}/qubeka-worker.pid" && return 0
    info "QuBeKa worker: arrancando queue:work..."
    setsid bash -c "cd '${QUBEKA_DIR}' && exec php artisan queue:work --sleep=10 --tries=3 --max-jobs=500 > '${LOG_DIR}/qubeka-worker.log' 2>&1" </dev/null &
    sleep 3
    # Buscar el PID del queue:work que corre en el directorio de QuBeKa
    local pid
    pid=$(pgrep -f "artisan queue:work" | while read p; do
        local cmd; cmd=$(cat /proc/$p/cmdline 2>/dev/null | tr '\0' ' ')
        echo "$cmd" | grep -q "${QUBEKA_DIR}" && echo $p && break
    done | head -1)
    [ -z "${pid}" ] && pid=$(pgrep -f 'artisan queue:work' | head -1)
    [ -n "${pid}" ] && echo "${pid}" > "${RUNTIME_DIR}/qubeka-worker.pid"
    ok "QuBeKa worker corriendo."
}

# -- Arrancar Kuestion --
start_kuestion_web() {
    [ -f "${RUNTIME_DIR}/kuestion.pid" ] && pid_alive "${RUNTIME_DIR}/kuestion.pid" && return 0
    info "Kuestion web: arrancando en :${KUESTION_PORT}..."
    setsid bash -c "cd '${KUESTION_DIR}' && exec php artisan serve --host=127.0.0.1 --port=${KUESTION_PORT} > '${LOG_DIR}/kuestion-web.log' 2>&1" </dev/null &
    sleep 3
    local pid
    pid=$(get_pid_on_port "${KUESTION_PORT}")
    [ -n "${pid}" ] && echo "${pid}" > "${RUNTIME_DIR}/kuestion.pid"
    ok "Kuestion web: http://127.0.0.1:${KUESTION_PORT}"
}

start_kuestion_worker() {
    [ -f "${RUNTIME_DIR}/kuestion-worker.pid" ] && pid_alive "${RUNTIME_DIR}/kuestion-worker.pid" && return 0
    info "Kuestion worker: arrancando queue:work..."
    setsid bash -c "cd '${KUESTION_DIR}' && exec php artisan queue:work --sleep=10 --tries=3 > '${LOG_DIR}/kuestion-worker.log' 2>&1" </dev/null &
    sleep 3
    # Buscar el PID del queue:work que corre en el directorio de Kuestion
    local pid
    pid=$(pgrep -f "artisan queue:work" | while read p; do
        local cmd; cmd=$(cat /proc/$p/cmdline 2>/dev/null | tr '\0' ' ')
        echo "$cmd" | grep -q "${KUESTION_DIR}" && echo $p && break
    done | head -1)
    [ -z "${pid}" ] && pid=$(pgrep -f 'artisan queue:work' | tail -1)
    [ -n "${pid}" ] && echo "${pid}" > "${RUNTIME_DIR}/kuestion-worker.pid"
    ok "Kuestion worker corriendo."
}

start_kuestion_scheduler() {
    [ -f "${RUNTIME_DIR}/kuestion-scheduler.pid" ] && pid_alive "${RUNTIME_DIR}/kuestion-scheduler.pid" && return 0
    info "Kuestion scheduler: arrancando schedule:work..."
    setsid bash -c "cd '${KUESTION_DIR}' && exec php artisan schedule:work > '${LOG_DIR}/kuestion-scheduler.log' 2>&1" </dev/null &
    sleep 2
    local pid
    pid=$(pgrep -f "schedule:work" | head -1)
    [ -n "${pid}" ] && echo "${pid}" > "${RUNTIME_DIR}/kuestion-scheduler.pid"
    ok "Kuestion scheduler corriendo."
}

# -- Detener --
stop_services() {
    info "Deteniendo servicios..."

    # Archivos pid en orden inverso de dependencia
    local pidfiles=(
        "${RUNTIME_DIR}/kuestion-scheduler.pid"
        "${RUNTIME_DIR}/kuestion-worker.pid"
        "${RUNTIME_DIR}/kuestion.pid"
        "${RUNTIME_DIR}/qubeka-worker.pid"
        "${RUNTIME_DIR}/qubeka.pid"
    )

    local had_any=0
    for pidfile in "${pidfiles[@]}"; do
        if [ -f "$pidfile" ] && pid_alive "$pidfile"; then
            kill_pidfile "$pidfile" "$(basename "$pidfile" .pid)"
            had_any=1
        else
            rm -f "$pidfile"
        fi
    done

    # Matar huérfanos conocidos
    for port_info in "${QUBEKA_PORT}:qubeka" "${KUESTION_PORT}:kuestion"; do
        local port="${port_info%%:*}"
        local name="${port_info##*:}"
        local pid
        pid=$(get_pid_on_port "$port")
        if [ -n "$pid" ] && is_our_process "$pid"; then
            kill "$pid" 2>/dev/null || true
            for child in $(pgrep -P "$pid" 2>/dev/null || true); do
                kill "$child" 2>/dev/null || true
            done
            ok "Detenido ${name} huérfano (pid ${pid})"
            had_any=1
        fi
    done

    # Matar workers y scheduler huérfanos
    pkill -f "queue:work.*(Kuestion|QuBeKa|qubeka)" 2>/dev/null || true
    pkill -f "schedule:work" 2>/dev/null || true

    [ "$had_any" = "1" ] && ok "Todo detenido." || ok "No había servicios corriendo."
}

# -- Estado --
check_status() {
    echo ""
    echo -e "${CYAN}Estado del entorno Kuestion + QuBeKa${NC}"
    echo "================================================"

    report() {
        local name="$1" f="$2" port="${3:-}" url="${4:-}"
        if [ -f "$f" ] && pid_alive "$f"; then
            echo -e "  ${GREEN}●${NC} ${name}: corriendo (pid $(cat "$f"))${port:+ en :${port}}${url:+ → ${url}}"
        else
            local pid=""
            [ -n "$port" ] && pid=$(get_pid_on_port "$port")
            if [ -n "$pid" ] && is_our_process "$pid"; then
                echo -e "  ${YELLOW}◐${NC} ${name}: corriendo como huérfano (pid ${pid})${port:+ en :${port}}"
            else
                echo -e "  ${RED}○${NC} ${name}: detenido"
            fi
        fi
    }

    report "QuBeKa web"       "${RUNTIME_DIR}/qubeka.pid"          "${QUBEKA_PORT}" "http://127.0.0.1:${QUBEKA_PORT}"
    report "QuBeKa worker"    "${RUNTIME_DIR}/qubeka-worker.pid"
    report "Kuestion web"     "${RUNTIME_DIR}/kuestion.pid"        "${KUESTION_PORT}" "http://127.0.0.1:${KUESTION_PORT}"
    report "Kuestion worker"  "${RUNTIME_DIR}/kuestion-worker.pid"
    report "Kuestion sched"   "${RUNTIME_DIR}/kuestion-scheduler.pid"
    echo "================================================"
}

# -- Resumen --
show_summary() {
    local qubeka_url="http://127.0.0.1:${QUBEKA_PORT}"
    local kuestion_url="http://127.0.0.1:${KUESTION_PORT}"
    echo ""
    echo "=================================================================="
    echo -e "  ${GREEN}Entorno listo: Kuestion + QuBeKa (real)${NC}"
    echo "=================================================================="
    echo ""
    echo "  QuBeKa:       ${qubeka_url}"
    echo "  Kuestion:     ${kuestion_url}"
    echo ""
    echo "  Endpoints de QuBeKa:"
    echo "    POST ${qubeka_url}/api/v1/query          ← Motor de Consulta"
    echo "    GET  ${qubeka_url}/api/v1/agent/me       ← Resolución de identidad"
    echo "    GET  ${qubeka_url}/api/v1/workspaces     ← Workspaces"
    echo ""
    echo "  QUBKA_API_URL en .env de Kuestion → ${qubeka_url}"
    echo ""
    echo "  Para conectar un repositorio Qbk en Kuestion:"
    echo "    1. Ir a ${kuestion_url}/settings"
    echo "    2. Conectar nuevo repositorio → seleccionar 'QuBeKa'"
    echo "    3. Token de agente: crear en QuBeKa → ${qubeka_url}"
    echo ""
    echo "  Credenciales de prueba:"
    echo "    Kuestion:  test@ispend.com / password123"
    echo "    QuBeKa:    (las que tengas configuradas)"
    echo ""
    echo "  Logs:"
    echo "    QuBeKa web:     ${LOG_DIR}/qubeka-web.log"
    echo "    QuBeKa worker:  ${LOG_DIR}/qubeka-worker.log"
    echo "    Kuestion web:   ${LOG_DIR}/kuestion-web.log"
    echo "    Kuestion worker: ${LOG_DIR}/kuestion-worker.log"
    echo "    Kuestion sched:  ${LOG_DIR}/kuestion-scheduler.log"
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
    start_qubeka_web
    start_qubeka_worker
    start_kuestion_web
    start_kuestion_worker
    start_kuestion_scheduler
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
        echo "  QUBEKA_PORT     (default: 8000)"
        echo "  QUBEKA_DIR      (default: ../QuBeKa/qubeka)"
        exit 1
        ;;
esac
