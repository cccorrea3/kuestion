#!/bin/bash
# ==============================================================================
# setup-dev.sh — Levanta el entorno de desarrollo local de Kuestion + Kuaforia.
#
# Servicios que gestiona:
#   1. Kuaforia  (motor RAG)   → puerto ${KUAFORIA_PORT} (8000)  [o mock en 8080]
#   2. Kuestion  (vigilante)   → puerto ${KUESTION_PORT} (8001)
#   3. Worker de colas          → queue:work en background (Kuestion)
#   4. Worker de colas Kuaforia → queue:work en background (solo KUAWORIA real)
#   5. Scheduler                → schedule:work en background (job horario, cleanup)
#   6. MySQL                    → se asume servicio del sistema (verifica conexión)
#   7. Redis                    → se asume servicio del sistema (verifica conexión)
#
# Uso:
#   ./scripts/setup-dev.sh start     Levanta todo (idempotente).
#   ./scripts/setup-dev.sh stop      Detiene todo lo que el script levantó.
#   ./scripts/setup-dev.sh restart   stop + start.
#   ./scripts/setup-dev.sh status    Estado de cada servicio.
#
# Configurable por variables de entorno (con defaults razonables):
#   KUAFORIA_DIR, KUESTION_DIR, MYSQL_USER, MYSQL_PASS, MYSQL_HOST, MYSQL_PORT,
#   KUAFORIA_PORT, KUESTION_PORT, MOCK_PORT, KUAFORIA_REAL (0|1), KUAFORIA_SLUG,
#   KUAFORIA_CLIENT_API_KEY, TEST_USER_EMAIL
#   (las viejas KUAWORIA_* siguen funcionando como alias de compatibilidad)
# ==============================================================================

set -euo pipefail

# ------------------------------------------------------------------------------
# Configuración
# ------------------------------------------------------------------------------
# Renombradas de KUAWORIA_* → KUAFORIA_* (typo histórico). Las viejas siguen
# funcionando como fallback para no romper invocaciones previas del script.
KUAFORIA_DIR="${KUAFORIA_DIR:-${KUAWORIA_DIR:-/path/to/kuaforia}}"
KUESTION_DIR="${KUESTION_DIR:-$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)}"

MYSQL_HOST="${MYSQL_HOST:-127.0.0.1}"
MYSQL_PORT="${MYSQL_PORT:-3306}"
MYSQL_USER="${MYSQL_USER:-root}"
MYSQL_PASS="${MYSQL_PASS:-}"              # o exportar MYSQL_PASS en el entorno

KUAFORIA_PORT="${KUAFORIA_PORT:-${KUAWORIA_PORT:-8000}}"
KUESTION_PORT="${KUESTION_PORT:-8001}"
MOCK_PORT="${MOCK_PORT:-8080}"            # puerto del mock de Kuaforia (kuaforia-mock.php)
KUAFORIA_SLUG="${KUAFORIA_SLUG:-${KUAWORIA_SLUG:-ispend}}"  # tenant semilla de Kuaforia

# KUAFORIA_REAL=1 → usa el repo real de Kuaforia (requiere subdominios por tenant).
# KUAFORIA_REAL=0 (default en este repo) → usa kuaforia-mock.php, sin subdominios.
KUAFORIA_REAL="${KUAFORIA_REAL:-${KUAWORIA_REAL:-0}}"

# ClientApiKey kfr_... que Kuestion usará contra Kuaforia real. Si no se provee,
# el script intenta generarla en Kuaforia (best-effort) o reutiliza KUAFORIA_API_KEY.
KUA_CLIENT_API_KEY="${KUAFORIA_CLIENT_API_KEY:-}"

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

# ensure_env_value <archivo> <clave> <valor> — setea/actualiza una línea en un
# .env (idempotente: crea la línea si falta, la reemplaza si existe).
ensure_env_value() {
    local file="$1" key="$2" value="$3" escaped
    escaped=$(printf '%s' "${value}" | sed 's/&/\\&/g')
    if grep -qE "^${key}=" "${file}"; then
        sed -i "s|^${key}=.*|${key}=${escaped}|" "${file}"
    else
        printf '\n%s=%s\n' "${key}" "${value}" >> "${file}"
    fi
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

    # Chequeo de Redis en dos pasos: si redis-cli no está instalado no podemos
    # verificar nada — avisar explícitamente en vez de asumir que está bien.
    if command -v redis-cli >/dev/null 2>&1; then
        if redis-cli ping >/dev/null 2>&1; then
            ok "Redis disponible."
        else
            warn "Redis no responde en localhost:6379 (Kuestion lo usa para cola/cache/sesión)."
        fi
    else
        warn "redis-cli no está instalado — no se pudo verificar Redis. Asegurate de que el servicio esté corriendo en :6379."
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

    if [ "${KUAFORIA_REAL}" = "1" ]; then
        if [ ! -d "${KUAFORIA_DIR}" ]; then
            fail "KUAFORIA_REAL=1 pero KUAFORIA_DIR=${KUAFORIA_DIR} no existe."
            fail "Ajustá KUAFORIA_DIR o usá KUAFORIA_REAL=0 (mock)."
            exit 1
        fi
        if ! check_port "${KUAFORIA_PORT}" "Kuaforia"; then
            [ -f "${RUNTIME_DIR}/kuaforia.pid" ] && pid_alive "${RUNTIME_DIR}/kuaforia.pid" \
                && ok "Kuaforia ya está corriendo en :${KUAFORIA_PORT}." \
                || { fail "Puerto ${KUAFORIA_PORT} ocupado por un proceso ajeno."; exit 1; }
        fi
    elif ! check_port "${MOCK_PORT}" "mock Kuaforia"; then
        [ -f "${RUNTIME_DIR}/mock.pid" ] && pid_alive "${RUNTIME_DIR}/mock.pid" \
            && ok "Mock de Kuaforia ya está corriendo en :${MOCK_PORT}." \
            || { fail "Puerto ${MOCK_PORT} ocupado por un proceso ajeno."; exit 1; }
    fi
}

# ------------------------------------------------------------------------------
# 2) Hosts: subdominios por tenant (solo con Kuaforia real)
#    Usa sudo solo en esta línea puntual; no exige correr todo el script como root.
# ------------------------------------------------------------------------------
setup_hosts() {
    [ "${KUAFORIA_REAL}" = "1" ] || { info "Modo mock: sin subdominios de tenant (se omite /etc/hosts)."; return; }

    local entry="127.0.0.1 ${KUAFORIA_SLUG}.localhost"
    if grep -qF "${entry}" /etc/hosts 2>/dev/null; then
        ok "/etc/hosts ya contiene '${entry}'."
        return
    fi

    info "Agregando '${entry}' a /etc/hosts..."
    local line="# Kuestion dev (setup-dev.sh)"
    if [ "$(id -u)" -eq 0 ]; then
        printf '\n%s\n%s\n' "${line}" "${entry}" >> /etc/hosts
        ok "Hosts actualizado."
    elif command -v sudo >/dev/null 2>&1 \
         && printf '\n%s\n%s\n' "${line}" "${entry}" | sudo tee -a /etc/hosts >/dev/null; then
        ok "Hosts actualizado (vía sudo)."
    else
        warn "No se pudo escribir /etc/hosts (requiere sudo). Agregalo manualmente:"
        warn "  echo \"${entry}\" | sudo tee -a /etc/hosts"
    fi
}

# ------------------------------------------------------------------------------
# 3) .env de Kuestion — genera desde .env.example si falta y ajusta los valores
#    de conexión a Kuaforia según el modo elegido (mock :8080 vs real).
#    Nunca pisa valores que no sean los de conexión a Kuaforia.
# ------------------------------------------------------------------------------
setup_kuestion_env() {
    local env_file="${KUESTION_DIR}/.env"
    if [ ! -f "${env_file}" ]; then
        info "Kuestion: generando .env desde .env.example..."
        cp "${KUESTION_DIR}/.env.example" "${env_file}"
        # El .env.example arranca con una línea [TEMPLATE] que no es dotenv válido.
        sed -i '1{/^\[TEMPLATE\]$/d}' "${env_file}"

        # Genera APP_KEY si no existe.
        if grep -qE '^APP_KEY=$' "${env_file}"; then
            php "${KUESTION_DIR}/artisan" key:generate --force >/dev/null
        fi
        ok "Kuestion: .env generado."
    else
        ok "Kuestion: .env existente — se actualizarán solo los valores de conexión a Kuaforia."
    fi

    # Apuntar Kuestion a Kuaforia según el modo (mock vs real). KUAFORIA_MCP_URL
    # no está en .env.example: config/services.php lo deriva de KUAFORIA_BASE_URL,
    # así que se setea explícitamente para que el puente MCP apunte al modo elegido.
    if [ "${KUAFORIA_REAL}" = "1" ]; then
        ensure_env_value "${env_file}" "KUAFORIA_BASE_URL" "http://${KUAFORIA_SLUG}.localhost:${KUAFORIA_PORT}"
        ensure_env_value "${env_file}" "KUAFORIA_MCP_URL"  "http://${KUAFORIA_SLUG}.localhost:${KUAFORIA_PORT}/api/v1/mcp"
    else
        ensure_env_value "${env_file}" "KUAFORIA_BASE_URL" "http://127.0.0.1:${MOCK_PORT}"
        ensure_env_value "${env_file}" "KUAFORIA_MCP_URL"  "http://127.0.0.1:${MOCK_PORT}/api/v1/mcp"
    fi
    ok "Kuestion: KUAFORIA_BASE_URL / KUAFORIA_MCP_URL apuntando a $( [ "${KUAFORIA_REAL}" = "1" ] && echo "Kuaforia real (${KUAFORIA_SLUG}.localhost:${KUAFORIA_PORT})" || echo "mock local (:${MOCK_PORT})" )."
}

# ------------------------------------------------------------------------------
# 4) Kuaforia: preparación (seed tenant + key) o mock
# ------------------------------------------------------------------------------
prepare_kuaforia() {
    if [ "${KUAFORIA_REAL}" = "1" ]; then
        info "Kuaforia real: preparando tenant '${KUAFORIA_SLUG}' y datos semilla..."
        info "  (la base de datos del tenant la crea la propia app de Kuaforia en su seed/alta de tenant — no es responsabilidad de este script)"
        # Cada proyecto de Kuaforia expone su propio setup; ajustar al que corresponda.
        if [ -f "${KUAFORIA_DIR}/artisan" ]; then
            (cd "${KUAFORIA_DIR}" && php artisan migrate --force && php artisan db:seed --force) \
                || warn "Seed de Kuaforia falló — revisá la configuración del proyecto Kuaforia."
        fi
        ok "Kuaforia: tenant y seed listos (asumido)."
    else
        info "Kuaforia mock: se usará '${KUESTION_DIR}/kuaforia-mock.php' en :${MOCK_PORT}."
        if [ ! -f "${KUESTION_DIR}/kuaforia-mock.php" ]; then
            fail "No existe kuaforia-mock.php — y KUAFORIA_REAL=0."
            exit 1
        fi
    fi
}

# ------------------------------------------------------------------------------
# 4b) ClientApiKey kfr_... para el tenant semilla (solo Kuaforia real).
#     Prioridad: variable KUAFORIA_CLIENT_API_KEY → KUAFORIA_API_KEY ya seteada
#     en el .env → generación best-effort vía tinker. Nunca bloquea: si no se
#     puede generar, avisa cómo hacerlo a mano.
# ------------------------------------------------------------------------------
ensure_kuaforia_client_key() {
    [ "${KUAFORIA_REAL}" = "1" ] || return 0

    # 1) Provista por variable → usarla y persistirla en el .env de Kuestion.
    if [ -n "${KUA_CLIENT_API_KEY}" ]; then
        ensure_env_value "${KUESTION_DIR}/.env" "KUAFORIA_API_KEY" "${KUA_CLIENT_API_KEY}"
        ok "ClientApiKey provista por KUAFORIA_CLIENT_API_KEY (${KUA_CLIENT_API_KEY:0:10}...)."
        return 0
    fi

    # 2) Ya configurada en el .env de Kuestion → reutilizarla.
    local existing
    existing=$(grep -E '^KUAFORIA_API_KEY=' "${KUESTION_DIR}/.env" | cut -d= -f2- || true)
    if [ -n "${existing}" ]; then
        KUA_CLIENT_API_KEY="${existing}"
        ok "ClientApiKey reutilizada del .env de Kuestion (${existing:0:10}...)."
        return 0
    fi

    # 3) Best-effort: crearla en Kuaforia vía tinker. Ajustar al esquema real de
    #    Kuaforia si difiere (modelos Tenant / ClientApiKey, columnas).
    info "Kuaforia real: generando ClientApiKey para tenant '${KUAFORIA_SLUG}' (best-effort)..."
    local out key
    out=$(cd "${KUAFORIA_DIR}" && php artisan tinker --execute='
        $t = \App\Models\Tenant::where("slug", "'"${KUAFORIA_SLUG}"'")->first();
        if (!$t) { echo "KUAFORIA_KEY:NO_TENANT"; exit; }
        $k = \App\Models\ClientApiKey::where("tenant_id", $t->id)->first();
        if (!$k) { $k = \App\Models\ClientApiKey::create(["tenant_id" => $t->id, "api_key" => "kfr_" . \Illuminate\Support\Str::random(32)]); }
        echo "KUAFORIA_KEY:" . $k->api_key;
    ' 2>&1 || true)
    # Con set -euo pipefail, un grep sin match devolvería exit 1 y mataría el
    # script — el `|| true` hace que la degradación sea silenciosa.
    key=$(printf '%s' "${out}" | grep -oE 'KUAFORIA_KEY:[kfr_[:alnum:]]+' | head -1 | cut -d: -f2- || true)

    if [ -n "${key}" ] && [ "${key}" != "NO_TENANT" ]; then
        KUA_CLIENT_API_KEY="${key}"
        ensure_env_value "${KUESTION_DIR}/.env" "KUAFORIA_API_KEY" "${KUA_CLIENT_API_KEY}"
        ok "ClientApiKey generada en Kuaforia y guardada en el .env de Kuestion."
    else
        warn "No se pudo generar la ClientApiKey automáticamente (${key:-fallo del comando tinker})."
        warn "Creala en Kuaforia y seteá KUAFORIA_API_KEY en ${KUESTION_DIR}/.env, o usá KUAFORIA_CLIENT_API_KEY."
    fi
}

# ------------------------------------------------------------------------------
# 5) Base de datos de Kuestion: creación + migraciones + seeders
# ------------------------------------------------------------------------------
prepare_kuestion_db() {
    local db_user
    db_user=$(grep -E '^DB_USERNAME=' "${KUESTION_DIR}/.env" | cut -d= -f2- || true)
    db_user="${db_user:-kuestion}"

    ensure_db "kuestion" "${db_user}"

    info "Kuestion: migraciones + seeders..."
    (cd "${KUESTION_DIR}" && php artisan migrate --force && php artisan db:seed --force)

    # Usuario de prueba garantizado. DatabaseSeeder ya lo incluye (DevUsersSeeder),
    # pero se corre explícito para que el paso sea visible en la salida; el seeder
    # es idempotente por email, así que repetirlo no duplica nada.
    info "Kuestion: asegurando usuario de prueba ${TEST_USER_EMAIL}..."
    (cd "${KUESTION_DIR}" && php artisan db:seed --class=DevUsersSeeder --force)
    ok "Usuario de prueba ${TEST_USER_EMAIL} listo."

    ok "Kuestion: BD migrada y seedeada."
}

# ------------------------------------------------------------------------------
# 6) Servidores web (background)
# ------------------------------------------------------------------------------
start_kuaforia() {
    if [ "${KUAFORIA_REAL}" = "1" ]; then
        [ -f "${RUNTIME_DIR}/kuaforia.pid" ] && pid_alive "${RUNTIME_DIR}/kuaforia.pid" && return 0
        info "Kuaforia: arrancando en :${KUAFORIA_PORT}..."
        (cd "${KUAFORIA_DIR}" && php artisan serve --host=127.0.0.1 --port="${KUAFORIA_PORT}" \
            > "${LOG_DIR}/kuaforia.log" 2>&1 </dev/null & echo $! > "${RUNTIME_DIR}/kuaforia.pid")
        sleep 2
        ok "Kuaforia: http://${KUAFORIA_SLUG}.localhost:${KUAFORIA_PORT}"
    else
        [ -f "${RUNTIME_DIR}/mock.pid" ] && pid_alive "${RUNTIME_DIR}/mock.pid" && return 0
        info "Mock Kuaforia: arrancando en :${MOCK_PORT}..."
        (cd "${KUESTION_DIR}" && php -S 127.0.0.1:${MOCK_PORT} kuaforia-mock.php \
            > "${LOG_DIR}/kuaforia-mock.log" 2>&1 </dev/null & echo $! > "${RUNTIME_DIR}/mock.pid")
        sleep 1
        ok "Mock Kuaforia: http://127.0.0.1:${MOCK_PORT}"
    fi
}

# Worker de colas de Kuaforia (solo modo real): reindexado de embeddings y otros
# jobs propios de Kuaforia. Sin él, los flujos que dependen de colas de Kuaforia
# (p. ej. GenerateEmbeddingJob al editar/publicar un caso) quedan en estado incierto.
start_kuaforia_worker() {
    [ "${KUAFORIA_REAL}" = "1" ] || return 0
    [ -f "${RUNTIME_DIR}/kuaforia-worker.pid" ] && pid_alive "${RUNTIME_DIR}/kuaforia-worker.pid" && return 0
    info "Worker de colas de Kuaforia: arrancando queue:work..."
    (cd "${KUAFORIA_DIR}" && php artisan queue:work --sleep=10 --tries=3 \
        > "${LOG_DIR}/kuaforia-worker.log" 2>&1 </dev/null & echo $! > "${RUNTIME_DIR}/kuaforia-worker.pid")
    ok "Worker de colas de Kuaforia corriendo."
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
# 7) Detención — cubre los 6 pidfiles posibles (mock|kuaforia|kuaforia-worker|
#    kuestion|worker|scheduler); los que no aplican al modo simplemente no existen.
# ------------------------------------------------------------------------------
stop_services() {
    info "Deteniendo servicios gestionados por el script..."

    local pidfile had_any=0
    for pidfile in "${RUNTIME_DIR}/mock.pid" "${RUNTIME_DIR}/kuaforia.pid" \
                   "${RUNTIME_DIR}/kuaforia-worker.pid" "${RUNTIME_DIR}/kuestion.pid" \
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
# 8) Estado — reporta Kuaforia real (con su worker) o el mock según el modo.
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
    if [ "${KUAFORIA_REAL}" = "1" ]; then
        report "Kuaforia (real)"     "${RUNTIME_DIR}/kuaforia.pid"        "${KUAFORIA_PORT}" "http://${KUAFORIA_SLUG}.localhost:${KUAFORIA_PORT}"
        report "Worker de Kuaforia"  "${RUNTIME_DIR}/kuaforia-worker.pid"
    else
        report "Kuaforia (mock)"     "${RUNTIME_DIR}/mock.pid"            "${MOCK_PORT}"     "http://127.0.0.1:${MOCK_PORT}"
    fi
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
    if [ "${KUAFORIA_REAL}" = "1" ]; then
        kua_url="http://${KUAFORIA_SLUG}.localhost:${KUAFORIA_PORT}"
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
    if [ "${KUAFORIA_REAL}" = "1" ] && [ -n "${KUA_CLIENT_API_KEY}" ]; then
        echo ""
        echo "  ClientApiKey (kfr_): ${KUA_CLIENT_API_KEY}"
        echo "    → ya seteada como KUAFORIA_API_KEY en ${KUESTION_DIR}/.env"
    fi
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
    ensure_kuaforia_client_key
    prepare_kuestion_db
    start_kuaforia
    start_kuaforia_worker
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
        echo "Variables útiles: KUAFORIA_DIR, KUAFORIA_REAL (0|1), MYSQL_USER, MYSQL_PASS,"
        echo "                  KUAFORIA_PORT, KUESTION_PORT, MOCK_PORT, KUAFORIA_SLUG,"
        echo "                  KUAFORIA_CLIENT_API_KEY, TEST_USER_EMAIL"
        echo "                  (las viejas KUAWORIA_* siguen funcionando como alias)"
        exit 1
        ;;
esac
