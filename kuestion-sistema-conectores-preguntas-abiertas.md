# Kuestion — Sistema de Conectores RAG: Preguntas Abiertas e Inconsistencias

**Versión:** 1.0 | **Fecha:** 2026-08-15
**Propósito:** Este documento recoge todo lo que surgió de la **validación del documento de referencia v1.1 contra el código real** que NO estaba resuelto en el diseño cerrado. Está pensado para ser revisado por el equipo de Kuestion (producto + tecnología + ingeniería) antes o durante la implementación.

**Relación con la sección 8 del documento de referencia:** las preguntas 8.1 y 8.2 de ese documento (coordinación con Kuaforia) se mantienen y se referencian acá (P1, P2). El resto son hallazgos nuevos de la validación.

---

## A. Inconsistencias encontradas en la validación (doc vs código)

### A1. El documento y el código usan endpoints de identidad distintos

| Dónde | Qué dice |
|---|---|
| Documento §2.1 | "Descartada: la vía REST (`/api/v1/cli/health`) que requería subdominio por tenant. Adoptada: `get_client_context` a través del puente MCP." |
| Código actual (`KuaforiaService::resolveTenantFromApiKey`) | La vía REST configurada apunta a **`/api/validate-api-key`** (no `/api/v1/cli/health`), y `config/services.php` tiene `tenant_resolution => 'rest'` como **default**. La vía MCP existe pero es un POST directo a `/api/v1/mcp` con `{stateless: true}`, **no** un JSON-RPC `tools/call` de `get_client_context`. |

**Conclusión:** el documento describe un endpoint descartado (`/api/v1/cli/health`) que no es el que el código usa (`/api/validate-api-key`), y propone `get_client_context` por MCP como contrato que el código aún no implementa de esa forma. **Necesita aclaración del equipo:**
1. ¿El endpoint REST real de validación es `/api/validate-api-key` o `/api/v1/cli/health`?
2. ¿Se descarta la vía REST por completo (como dice §2.1) y la identidad pasa 100% a MCP con `get_client_context`? ¿O la vía REST queda como fallback documentado?

### A2. El documento referencia un namespace de clases que no existe

- §1.3 (registro de conectores) muestra: `\App\Connectors\Kuaforia\IdentityResolver::class`, `\App\Connectors\Kuaforia\KuaforiaService::class`, `\App\Connectors\Kuaforia\KuaforiaMcpProvider::class`.
- El código real vive en `app/Services/KuaforiaService.php` y `app/Services/KuaforiaMcpProvider.php`. El directorio `app/Connectors/` **no existe**.

**Pregunta al equipo:** ¿se reubican las clases a `app/Connectors/Kuaforia/` (refactor con churn en imports y tests) o se mantienen en `app/Services/` y el registro de conectores referencia las clases actuales? **Recomendación del plan:** mantener en `app/Services/` (evitar churn sin valor); crear `App\Connectors` solo cuando exista un segundo conector real.

### A3. §4.3 describe la implementación de `IdentityResolverInterface` como "KuaforiaService::resolveTenantFromApiKey()" pero §1.3 la muestra como clase separada `IdentityResolver`

Contradicción interna menor del documento: ¿la implementación es un método de `KuaforiaService` (que implementa `RagProviderInterface` + `IdentityResolverInterface`) o una clase dedicada `IdentityResolver`? **A definir por el equipo.** Recomendación del plan: clase dedicada que use `KuaforiaService`/el patrón MCP — mantiene la separación de responsabilidades y evita que una clase acumule tres interfaces.

### A4. La firma propuesta `resolveIdentity(array $credential)` rompe el contrato actual `resolveTenantFromApiKey(string $apiKey): array`

- El documento (§4.3) define `resolveIdentity(array $credential): ResolvedIdentity`.
- El código actual recibe `string $apiKey` y devuelve `array{tenant_slug, workspace_id?}`.
- Los llamadores actuales (`Register`, `Settings`) usan la firma con string.

**Pregunta:** ¿se mantiene `resolveTenantFromApiKey()` como wrapper de compatibilidad durante la transición (recomendado) o se migra todo de una? El plan asume wrapper + migración en Fase C.

### A5. `get_client_context` no está en `services.kuaforia.mcp_tools`

El mapeo `mcp_tools` de `config/services.php` solo incluye `get_workspace_health`, `get_dependency_health_report`, `get_case`. Si la identidad pasa por MCP con `get_client_context` (como dice §2.1), esa tool debe agregarse al mapeo o manejarse por separado en la implementación de identidad. **Confirmar contrato:** ¿`get_client_context` es una tool JSON-RPC `tools/call` del mismo puente, o un endpoint distinto? (Ver A1.)

---

## B. Componentes existentes que el documento no cubre en §7 (impacto)

### B1. `KuaforiaKeyPrompt` (banner 6.7 de Fase 1) no aparece en la sección de impacto §7

El componente `app/Livewire/KuaforiaKeyPrompt.php` muestra un banner a usuarios autenticados sin `kuaforia_api_key`. Con la eliminación de esa columna de `users`, el componente queda sin campo que evaluar — debe pasar a "usuario sin repositorios" (`repositories->isEmpty()`). **No es una pregunta, es una consecuencia directa** — se incluye en el plan (tarea C4) pero se informa acá para que el equipo lo tenga presente como alcance.

### B2. El onboarding post-registro usa `users.tenant_slug`

`resources/views/auth/onboarding.blade.php` resuelve el nombre del tenant desde `auth()->user()->tenant_slug` y `config('services.kuaforia.tenants')` (lista hardcodeada). Con el modelo de repositorios, debe leer `resolved_tenant_name` del repositorio del usuario. **No cubierto en §7 del documento.**

### B3. `services.kuaforia.tenants` (lista hardcodeada de tenants)

Se usa en `Register::getResolvedTenantNameProperty` y el onboarding. Con `ResolvedIdentity->tenant_name` (nombre real desde Kuaforia), esta lista queda obsoleta. **Pregunta:** ¿se elimina (recomendado) o se mantiene como fallback de display? El plan la elimina en G4.

### B4. La validación en vivo del registro ya existe — el documento la describe como si fuera nueva

§5.1/6.2 describen la validación con debounce 700ms y la confirmación del tenant. **Eso ya está implementado** en `Register::updatedKuaforiaApiKey()` (Fase 1, Bloque 6). La diferencia real es: (a) pasar de REST a MCP (A1), (b) mostrar `tenant_name (tenant_slug)` en lugar de solo slug, (c) persistir en `repositories` en lugar de `users`. No es una pregunta, es una nota de alcance para no duplicar trabajo.

### B5. `UserFactory` y `AdminUserSeeder` setean `tenant_slug => 'ispend'`

Con la eliminación de la columna, factories y seeders deben crear `Repository` en su lugar. Cubierto en el plan (G2), informado acá para dimensionar el impacto en la suite de tests (varios tests existentes usan el tenant implícito del factory).

---

## C. Preguntas de diseño no cerradas en el documento de referencia

### P1. ¿La `kfr_` del usuario autentica `POST /api/consult/{tenant_slug}`? *(documento §8.1)*

Mantenida tal cual. No bloqueante: mientras tanto se usa la key compartida. Si la respuesta es sí, se elimina la key compartida (plan G6).

### P2. ¿`get_client_context` puede devolver `workspace_id` por defecto? *(documento §8.2)*

Mantenida tal cual. No bloqueante: el fallback `workspace_map` ya está implementado. Si es sí, se elimina el fallback (plan G7).

### P3. Contrato exacto de `get_client_context` (nueva, derivada de A1/A5)

- ¿Endpoint JSON-RPC `tools/call` del puente MCP (mismo que las señales) o POST stateless directo (como el código actual)?
- ¿Qué campos devuelve exactamente: `tenant.slug`, `tenant.name`, `workspace_id`? ¿Nombres de clave?
- ¿Códigos de error: 401 para key inválida, 503 para servicio caído? (necesario para §6.11).

### P4. ¿Qué pasa si el usuario ya tiene preguntas sin repositorio al migrar?

El documento dice "sin migración de datos" porque el ambiente es MVP aislado. Pero el ambiente de **test** (RefreshDatabase) corre `migrate:fresh` y puede tener datos de fixtures. El plan (A2) hace backfill defensivo: asignar preguntas huérfanas al repositorio por defecto del usuario. **Confirmar** que no hay datos reales que preservar en dev/prod antes de ejecutar la migración (hoy `kuestion` tiene 0 usuarios/preguntas, verificado).

### P5. ¿Se puede desconectar el único repositorio activo?

§6.10 define la confirmación al desconectar, pero no define el caso límite: **desconectar el único repositorio activo**. Opciones: (a) permitirlo (queda 0 repos → bloqueo de creación con mensaje, flujo §6.5/6.12); (b) bloquear la desconexión si es el único activo. **Recomendación del plan:** opción (a) — coherente con el estado "0 repositorios activos" ya contemplado en §5.4, y le da al usuario la posibilidad real de desconectarse. A confirmar por producto.

### P6. Selector de tipo de conector: ¿"solo si hay >1 tipo configurado" incluye mostrar el tipo en el selector al agregar repositorio?

§5.2 paso 2 dice "Muestra el selector de tipo de conector (solo si hay >1 tipo configurado)". Como hoy solo hay Kuaforia, el selector nunca aparece. **Confirmar que no se pide implementar el selector genérico ahora** (YAGNI) o si se quiere dejar la estructura preparada. El plan asume: no construir el selector hasta que exista un segundo conector (decisión 8 del documento: "construir para 1").

### P7. ¿`name` autogenerado debe incluir el `tenant_name` o el `connector_type`?

§5.1 dice nombre autogenerado "Kuaforia - Ispend". Con varios conectores podría ser ambiguo ("Kuaforia - Ispend" vs "QuBeKa - Ispend" está bien). **Confirmar la fórmula:** `{display_name del conector} - {tenant_name}`. El plan asume esa fórmula.

### P8. `tenant_resolution` (`rest | mcp`) de `config/services.php`: ¿se elimina o se conserva como flag de fallback?

Con la identidad 100% MCP (A1), el flag queda obsoleto. Opciones: eliminarlo (limpieza G4) o conservarlo como interruptor de emergencia si el puente MCP cae. **Recomendación del plan:** eliminarlo y documentar la URL fija del MCP; un fallback REST solo tendría sentido si Kuaforia confirma el endpoint (A1). A decidir.

### P9. ¿`last_used_at` se actualiza en cada consulta?

§3.1 lo marca "sugerido para observabilidad". **Confirmar alcance:** ¿actualizarlo en cada `consult()` (costo de write por consulta) o solo en el job? El plan asume: actualizar en el job y en la creación de preguntas (no en cada follow-up), para no sumar escrituras al hot path. Si el equipo quiere observabilidad completa, se actualiza en todos lados.

### P10. El `circuit breaker` de Kuaforia (pausa tras 3 fallos) no está contemplado en el documento

`KuaforiaService::consult()` tiene un circuit breaker con cache (`kuaforia:paused` tras 3 fallos en 120s). El documento §7 no lo menciona. **Pregunta:** ¿se mantiene tal cual (recomendado — protege a Kuaforia y a Kuestion) o interactúa con el nuevo estado `invalid` del repositorio (p.ej. un repo con 401 no debería contar para el breaker global)? El plan asume: se mantiene y **no** se acopla al estado del repo (el breaker es por servicio, no por repo). A confirmar.

### P11. ¿Los repositorios `invalid`/`revoked` del usuario aparecen en el selector de repositorio al crear?

§6.7 dice que solo aparecen los `active`. **Confirmar si el selector muestra un aviso** ("Tenés N conexiones inactivas — actualizalas desde Configuración") junto al bloqueo cuando todas están `invalid` (mensaje §6.12 ya lo cubre parcialmente). El plan asume: solo los `active` en el selector + mensaje de bloqueo si no hay ninguno.

### P12. Resaltado del repositorio afectado en `/settings` (UX §6.4)

El indicador del header enlaza a `/settings` "con el repositorio afectado resaltado". **Confirmar el mecanismo de resaltado** (query param `?highlight=<repo_id>` + scroll/animation). El plan asume query param + resaltado visual (ring/border). Es un detalle de implementación, no de producto — se lista por si el equipo tiene preferencia.

### P13. Usuario con repositorios de DISTINTOS tenants (caso multi-repo)

§7.3 (TeamDashboard) dice "Un usuario puede tener repositorios con distintos `tenant_slug`". Esto abre una pregunta de producto: **¿qué tenant ve el usuario en el feed/detalle cuando tiene repos de dos tenants?** El feed muestra preguntas del usuario (no del tenant), así que no hay conflicto para listar. Pero el onboarding y el header muestran "tu organización" — ¿de cuál? **Recomendación del plan:** el dashboard agrega por el `resolved_tenant_slug` de los repos del usuario actual (si tiene varios, sumar todos sus repos — el gate es por `team_dashboard_access`). A confirmar si el producto quiere mostrar un selector de tenant en el futuro (fuera de alcance de este sistema).

### P14. ¿`structured_signals` (Bloque 10, Fase 2) se relaciona con los repositorios?

La tabla `structured_signals` existe (migración inocua, sin modelo). El documento de conectores no la menciona. **Confirmar:** ¿quedan sin relación (persistencia de señales es evolución futura del Bloque 8, como se documentó en Fase 2) o el sistema de conectores debe empezar a usarla (p.ej. guardar señales por repositorio)? El plan asume: **sin relación** — fuera de alcance, la tabla queda para el futuro.

---

## D. Resumen ejecutivo para el equipo

### Inconsistencias que requieren decisión del equipo (bloqueantes de diseño, no de código)
1. **A1**: endpoint de identidad — REST `/api/validate-api-key` vs MCP `get_client_context` (el doc descarta un endpoint que el código no usa).
2. **A2**: namespace de clases — `app/Services/` (actual) vs `app/Connectors/` (propuesto en el doc).
3. **A3**: implementación de `IdentityResolverInterface` — método en `KuaforiaService` vs clase dedicada.

### Preguntas de producto (no bloquean, definen UX)
4. **P4**: datos reales al migrar (hoy 0 — confirmar).
5. **P5**: desconectar el único repositorio activo.
6. **P6**: selector de tipo de conector (construir o no).
7. **P7**: fórmula del nombre autogenerado.
8. **P13**: usuario con repos de múltiples tenants — qué muestra el onboarding/header.

### Preguntas técnicas (no bloquean, definen implementación)
9. **A4**: firma de `resolveIdentity` vs `resolveTenantFromApiKey` (wrapper de transición).
10. **A5**: `get_client_context` en el mapeo de tools.
11. **P8**: eliminar o conservar `tenant_resolution`.
12. **P9**: alcance de `last_used_at`.
13. **P10**: interacción del circuit breaker con `invalid`.
14. **P14**: relación de `structured_signals` con repositorios.

### Preguntas externas (Kuaforia) — ya en el documento de referencia §8
15. **P1** (8.1): `kfr_` para consultas REST.
16. **P2** (8.2): `workspace_id` por defecto en `get_client_context`.
17. **P3** (nueva): contrato exacto de `get_client_context` (endpoint, campos, códigos de error).

---

*Documento generado a partir de la validación de `docs/kuestion-sistema-conectores-referencia.md` v1.1 contra el código actual del repo (2026-08-15). Los IDs P1–P14 y A1–A5 referenciados en este documento se usan también en `kuestion-sistema-conectores-plan-implementacion.md` donde corresponda.*
