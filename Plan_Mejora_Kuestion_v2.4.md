# Kuestion — Plan de mejora (deuda técnica + arquitectura + usabilidad)

**Versión:** 2.4 (correcciones de redacción aplicadas)  
**Fecha:** Agosto 2026  
**Audiencia:** Equipo de Ingeniería de Kuestion  
**Propósito:** Documento ejecutable para la planificación de mejoras, priorizando deuda técnica, arquitectura interna y usabilidad. La vía MCP hacia Kuaforia (señales estructuradas) se incluye como parte central de la Fase 2.

---

## 1. Contexto y restricciones

Kuestion está en estado **MVP funcional**, con un flujo central de vigilancia de respuestas RAG que funciona de punta a punta, pero con deuda técnica acumulada y limitaciones de escalabilidad y usabilidad.

**Restricciones clave:**

1. **No se modifica la integración REST con Kuaforia** en su esencia:
   - El llamado `POST /api/consult/{tenant_slug}` sigue siendo síncrono.
   - El mecanismo de detección de cambios (hash SHA-256 + similitud coseno) se mantiene.
   - El `CheckQuestionUpdatesJob` sigue siendo el responsable de la re-consulta periódica.
   - Estos elementos no se tocan para no interferir con el plan de pruebas de ciclo completo del ecosistema.

2. **Se prioriza la deuda técnica** como base de todas las mejoras posteriores.

3. **Las mejoras arquitectónicas y de usabilidad** se diseñan como capas adicionales que no modifican el comportamiento central.

4. **La vía MCP hacia Kuaforia (señales estructuradas) y el futuro MCP Server de Kuestion** se construyen en paralelo al mecanismo REST actual, en el sentido técnico: no lo reemplazan ni lo modifican — el `POST /api/consult/{tenant_slug}`, el hash + similitud coseno y el `CheckQuestionUpdatesJob` siguen intactos. Esto no implica baja prioridad. Es una de las partes más importantes de este plan y se desarrolla con ese peso — no es una capa que se agrega "si sobra tiempo" después de todo lo demás. El puente HTTP MCP de Kuaforia ya está construido y funcionando (`POST /api/v1/mcp`, autenticación por API key), así que esta vía es plenamente factible y se prioriza como tal dentro de la Fase 2.

5. **Validación de tenant mediante API key scoped (decisión tomada):**
   - **Descartado:** usuario/clave como mecanismo de autenticación porque (a) las credenciales de usuario viven en la base de datos del tenant (no en landlord), por lo que Kuaforia necesitaría saber el tenant antes de autenticar — exactamente el problema que la API key resuelve; (b) usuario/clave entraría en conflicto con 2FA/passkeys que ya soporta Kuaforia; (c) el flujo "Conectate a X" en productos reales usa OAuth o tokens, no credenciales directas.
   - **Adoptado:** el usuario pega su API key de Kuaforia (prefijo `kfr_`). Kuestion valida la key contra Kuaforia (vía REST o MCP) y resuelve el `tenant_slug` automáticamente. La UX se diseña como "Conectate a Kuaforia" (un campo donde se pega la key), pero el mecanismo subyacente es API key scoped al tenant.
   - **Extensibilidad futura:** el mismo patrón de "Conectate a X" se puede reutilizar para QBK (cuyo esquema de `agente_tokens` tiene una lógica similar, aunque con distinta arquitectura de tenancy). Si Kuaforia algún día expone OAuth, se puede upgradear el mecanismo sin romper el concepto de producto.

6. **Exclusión de TenantTools:** Las operaciones de listado de tenants están excluidas por diseño en Kuaforia. La API key scoped resuelve la validación sin necesidad de acceder a TenantTools.

---

## 2. Objetivos

1. **Saldar la deuda técnica** (confiabilidad, seguridad, escalabilidad, observabilidad).
2. **Preparar la arquitectura** para multi-proveedor (RAG + señales estructuradas) y para el futuro MCP de Kuestion.
3. **Fortalecer la integración con Kuaforia** mediante el consumo de herramientas MCP que proporcionen señales estructuradas (stale_case, low_confidence, deps_changed), sin modificar la esencia REST.
4. **Mejorar la usabilidad y claridad** para que los usuarios (especialmente los early adopters como el equipo de Ispend) entiendan el valor de Kuestion desde el primer momento y puedan adoptarlo con baja fricción.

---

## 3. Fase 1: Deuda técnica fundamental

### Bloque 1 — Cuenta y comunicación con el usuario
- **1.1 Notificaciones por correo reales:** Configurar un proveedor SMTP (SendGrid, Mailgun, etc.) y activar el envío de notificaciones de cambio por correo. El modelo `notifications` ya está preparado.
- **1.2 Recuperación de contraseña + perfil de usuario:** Implementar flujo de "Olvidé mi contraseña" (token por correo). Agregar pantalla de configuración de perfil (nombre, email, contraseña).

**Criterios de aceptación:**
- El usuario recibe un correo cuando una pregunta vigilada cambia, con enlace directo.
- El usuario puede activar/desactivar notificaciones por correo desde su perfil.
- El usuario puede solicitar un enlace de reseteo de contraseña (válido 60 min).
- El usuario puede cambiar su nombre, email y contraseña desde `/settings`.

**Dependencias:** 1.1 es prerequisito de 1.2 (envío de correo).

---

### Bloque 2 — Seguridad de la aplicación
- **1.3 CSP con nonces:** Migrar de `unsafe-inline` a nonces generados por Laravel, cumpliendo con los requisitos de Livewire.

**Criterios de aceptación:**
- Las cabeceras CSP no incluyen `unsafe-inline`.
- Livewire sigue funcionando correctamente.
- Las herramientas de seguridad (Mozilla Observatory) reportan mejora.

**Dependencias:** Ninguna.

---

### Bloque 3 — Integridad de datos bajo concurrencia y manejo de fallos
- **1.4 Concurrencia en numeración de versiones:** Usar `lockForUpdate` en la transacción que crea `AnswerVersion`.
- **1.9 Preparación para múltiples workers (extendido):** Extender `lockForUpdate` a la creación de `Question` y a la actualización de `has_unreviewed_changes`.
- **1.6 Política de retención de versiones:** Conservar todas las versiones de preguntas activas, y solo las últimas 5 de preguntas archivadas.
- **1.8 Manejo de respuestas vacías de Kuaforia:** Si `answer` está vacío, no crear nueva versión y marcar error.

**Criterios de aceptación:**
- El job `CheckQuestionUpdatesJob` puede correr en múltiples workers sin generar duplicados ni condiciones de carrera.
- Todas las escrituras críticas usan `lockForUpdate`.
- `CleanupOldVersionsJob` respeta la política de retención documentada.
- El usuario ve una notificación de "error en la consulta" cuando Kuaforia devuelve vacío.

**Dependencias:** 1.9 extiende 1.4. Los demás son independientes.

---

### Bloque 4 — Escalabilidad de búsqueda
- **1.10 Índice FULLTEXT para búsqueda:** Reemplazar `LIKE` por `FULLTEXT` en `question_text`.

**Criterios de aceptación:**
- La búsqueda de texto es más rápida y relevante.
- El `RelationSuggester` puede usar `FULLTEXT` en lugar de carga en memoria.

**Dependencias:** Ninguna (migración de índice).

---

### Bloque 5 — Observabilidad base
- **1.5 Instrumentación de métricas clave:** Registrar en BD/logs: preguntas/semana, % de cambios revisados, tiempo promedio de revisión. No se construye dashboard aún.

**Criterios de aceptación:**
- Las métricas se almacenan en una tabla `daily_metrics` (o similar).
- Se pueden consultar vía Artisan o API interna.

**Dependencias:** Ninguna.

---

### Bloque 6 — Conexión de tenant ("Conectate a Kuaforia")
- **1.11 Validación de tenant mediante API key scoped:** Reemplazar el dropdown de tenants por un flujo donde el usuario pega su API key de Kuaforia (prefijo `kfr_`). Kuestion valida la key contra Kuaforia (vía REST o MCP) y resuelve el `tenant_slug` automáticamente.

**Criterios de aceptación:**
- El registro de usuario valida la API key en tiempo real.
- El tenant se resuelve automáticamente, no se selecciona de una lista.
- No se usan TenantTools (excluido por diseño).
- La UX es "Conectate a Kuaforia" (campo para pegar la key).

**Dependencias:** Coordinación con Ingeniería de Kuaforia para confirmar endpoint de validación (ver sección 6).

---

## 4. Fase 2: Arquitectura interna y preparación para el ecosistema

### Bloque 7 — Interfaz de proveedor RAG
- **2.1 Extraer `RagProviderInterface`:** Crear una interfaz con un único método `consult($question, $conversationId = null)`. El `KuaforiaService` actual la implementa.

**Criterios de aceptación:**
- El código existente sigue funcionando sin cambios.
- Se puede inyectar un proveedor mock en tests.
- La interfaz es mínima y específica para el caso de uso de vigilancia.

**Dependencias:** Ninguna (refactor interno).

---

### Bloque 8 — Señales estructuradas vía MCP

*Este bloque es una de las piezas más importantes del plan completo: fortalece la integración con Kuaforia sin tocar el mecanismo REST vigente. Se prioriza dentro de la Fase 2 inmediatamente después del refactor base (Bloque 7).*

- **2.2 Crear `StructuredSignalProviderInterface`:** Definir una interfaz para obtener señales estructuradas de Kuaforia (vía MCP). Métodos: `getWorkspaceHealth($workspaceId)`, `getDependencyHealthReport($workspaceId)`, `getCaseDetails($caseId)`.
- **2.3 Implementar `KuaforiaMcpProvider`:** Implementar la interfaz usando el puente HTTP MCP de Kuaforia (`POST /api/v1/mcp`).
- **2.4 Enriquecer `CheckQuestionUpdatesJob`:** El job consulta el proveedor de señales estructuradas para agregar contexto a la notificación (ej. "el caso X se marcó como stale_case"). El enriquecimiento está diseñado para degradarse con gracia en tiempo de ejecución — si el proveedor de señales falla o no está disponible, el job sigue funcionando con el mecanismo actual (hash + similitud) sin interrupción. Esto no es opcional como ítem del plan: es una decisión de resiliencia técnica, no de prioridad.

**Criterios de aceptación:**
- La interfaz mapea a tools reales del catálogo de Kuaforia: `get_workspace_health`, `get_dependency_health_report`, `get_case`.
- El proveedor MCP funciona y devuelve señales estructuradas.
- La notificación enriquece el diff con metadatos de señales (sin modificar el comportamiento principal de hash + similitud).
- El código está preparado para ajustes si el catálogo de tools cambia.

**Dependencias:** 2.2 → 2.3 → 2.4. El puente MCP ya existe.

---

### Bloque 9 — MCP Server propio de Kuestion
- **2.5 Esqueleto del MCP Server de Kuestion:** Implementar un servidor MCP (STDIO) con herramientas de solo lectura: `list_questions`, `get_question_details`, `list_unreviewed_changes`. Autenticación por token de agente.

**Criterios de aceptación:**
- Un agente externo (Claude Code) puede listar preguntas de un usuario autenticado.
- Las herramientas devuelven datos en formato estructurado (JSON).
- El token se valida contra la tabla `agente_tokens`.

**Dependencias:** Requiere crear tabla `agente_tokens` (migración).

---

### Bloque 10 — Modelo de datos para contrato mínimo multi-fuente
- **2.6 Preparar modelo de datos para el contrato mínimo:** Agregar columnas a `questions`: `source_platform` (enum: `kuaforia`, `qbk`), `external_id` (string), `last_external_check` (timestamp). Crear tabla `structured_signals` con FK a `questions`.

**Criterios de aceptación:**
- Las migraciones se ejecutan sin errores.
- El código existente ignora estas columnas (no se usan aún).
- La tabla `structured_signals` está lista para almacenar señales futuras.

**Dependencias:** Ninguna (migraciones inocuas).

---

## 5. Fase 3: Mejoras de usabilidad y claridad

*Las dependencias se definen ítem por ítem; no hay un bloqueo general de toda la fase.*

### Bloque 11 — Primera experiencia (onboarding)
- **3.1 Onboarding con ejemplo interactivo:** Antes de crear la primera pregunta real, mostrar un ejemplo de pregunta ficticia con su diff (cambio simulado).

**Criterios de aceptación:**
- El usuario ve un diff visual antes de crear su primera pregunta.
- El ejemplo no persiste en BD (es hardcodeado en la UI).
- El onboarding se puede saltar con un botón "Omitir".

**Dependencias:** Ninguna (UI).

---

### Bloque 12 — Panorama de equipo (vista agregada de salud del tenant)
- **3.2 Vista agregada de "salud del tenant":** Nueva vista que agregue métricas de todas las preguntas del mismo `tenant_slug` (resuelto automáticamente vía API key). Visible solo para usuarios con `team_dashboard_access = 'readonly'`.

**Criterios de aceptación:**
- Se muestran: total de preguntas, % con cambios sin revisar, tags más vigilados.
- El acceso se controla por un campo `team_dashboard_access` (enum: `none`, `readonly`) en `users`.
- Es solo lectura (no permite acciones).
- Este campo se documenta como una solución temporal, a ser reemplazada por un sistema de roles en el futuro.
- **Nota de privacidad:** la vista asume que el `tenant_slug` es un equipo de confianza y que, por ahora, no hay distinción de subgrupos dentro del mismo tenant. Es una decisión consciente para el piloto inicial. Si en el futuro se necesita granularidad (ej. equipos dentro del mismo tenant), se abordará con un sistema de roles.

**Dependencias:** 1.5 (métricas). **No depende de 2.6.**

---

### Bloque 13 — Señales de estado en el flujo existente
- **3.3 Panel de salud por tags:** En el índice de tags, agregar un badge que indique cuántas preguntas de ese tag tienen cambios sin revisar.
- **3.4 Feedback visible y acumulado:** En el detalle de la pregunta, mostrar un pequeño histórico de feedback (útil/no útil) por versión.

**Criterios de aceptación (3.3):**
- El badge se actualiza dinámicamente.
- Al hacer clic en el badge, se filtra el feed por ese tag y estado "con cambios".

**Criterios de aceptación (3.4):**
- Se ve una línea de tiempo de feedback.
- Se indica si la percepción de utilidad ha mejorado o empeorado.
- El feedback sigue siendo simple (👍/👎).

**Dependencias:** Ninguna (UI).

---

### Bloque 14 — Red de relaciones (visualización)
- **3.5 Visualización de la red de preguntas relacionadas:** Representación visual simple del grafo de relaciones entre preguntas (vía `question_relations`).

**Criterios de aceptación:**
- El usuario ve de un vistazo cuántas preguntas están conectadas y cómo, sin tener que navegarlas de a una vía backlinks.
- La visualización es liviana (no pretende ser un motor de grafo complejo).
- Se construye cuando exista una masa crítica de relaciones (recomendado: activar cuando el piloto de Ispend tenga al menos 10 preguntas con relaciones).

**Dependencias:** Ninguna (UI).

---

## 6. Decisiones pendientes (coordinación con Kuaforia)

1. **Validación de tenant (1.11):** Confirmar con Ingeniería de Kuaforia si existe un endpoint liviano (REST o MCP) que, dada una API key de cliente (prefijo `kfr_`), devuelva el `tenant_slug` asociado. Si no existe, definir si se construye o si Kuestion usa el MCP con `stateless: true` para obtener esa información.

2. **Tools MCP para señales (Bloque 8):** El mapeo propuesto (`get_workspace_health`, `get_dependency_health_report`, `get_case`) se basa en el catálogo actual de Kuaforia. Durante la implementación se verificará que estas tools cubren las necesidades. Si se requiere una señal a nivel de caso individual (ej. `stale_case`), se evaluará si se puede obtener combinando `get_case` con lógica interna, o se solicitará a Kuaforia que la exponga como tool MCP.

---

## 7. Criterios de éxito generales

- **Confiabilidad:** El sistema puede enviar correos, recuperar contraseñas, manejar respuestas vacías y concurrencia sin errores.
- **Seguridad:** CSP con nonces, autenticación robusta, validación de tenant mediante API key scoped.
- **Extensibilidad:** La arquitectura permite agregar nuevos proveedores (MCP, QBK) sin modificar el núcleo.
- **Observabilidad:** Las métricas clave están disponibles para medir el impacto de las mejoras.
- **Integración fortalecida:** Kuestion puede consumir señales estructuradas de Kuaforia vía MCP, enriqueciendo la vigilancia sin alterar el flujo principal.
- **Usabilidad:** Los nuevos usuarios entienden el valor de Kuestion en menos de 60 segundos, los supervisores pueden ver el estado agregado del tenant y la red de relaciones está disponible cuando tiene sentido.

---

**Fin del documento.**