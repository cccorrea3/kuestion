# Contrato API — Ola 2 (Kuestion ↔ QuBeKa)

*Versión: 1.0 · Septiembre 2026*
*Fuente de verdad: `RESPUESTAS_OLA2_KUESTION.md` (decisiones congeladas) + código real de QuBeKa.*

---

## 1. Visión General

Este contrato describe la **API de la Ola 2** que expone QuBeKa para Kuestion. Reemplaza el borrador `CONTRATO_API_OLA2.md` recibido (rechazado por corrupción en `REVISION_CONTRATO_API_OLA2.md`).

**Estado de implementación:** los **Puntos 1 y 2** (Bandeja de Revisión y Reconfirmación periódica) están **implementados**. Los **Puntos 3 a 5** (indicador de vigencia, explicabilidad, notificaciones) están **acordados/planeados** — los endpoints se marcan `[PLANEADO]` y no deben consumirse hasta que QuBeKa confirme su despliegue.

**Base URL:** `https://tu-servidor.com/api/v1`

**Autenticación:** Bearer token (Laravel Sanctum) vía header `Authorization`.

**Rate limiting:** 60 requests por minuto por usuario (default de Laravel).

**Sobre de respuesta estándar:** los endpoints usan el mismo formato de la Ola 1 (respondemos por la opción **a** de `REVISION_CONTRATO_API_OLA2.md` §3.4 — Kuestion se adapta a la API de QuBeKa):

- Éxito: `{"success": true, "data": {...}}` (paginado: agrega `meta`)
- Error: `{"success": false, "data": null, "errors": {"message": "texto legible"}}` — sin códigos de máquina.

---

## 2. Endpoints — Punto 1: Bandeja de Revisión (IMPLEMENTADO)

### 2.1 `GET /api/v1/sesiones-analisis` — Listado de sesiones

Retorna las sesiones de análisis del workspace del token. Permite la bandeja de revisión (pendientes) y el historial (cerradas).

**Scope mínimo:** `api:read`

**Request:**

| Query param | Tipo | Default | Descripción |
|-------------|------|---------|-------------|
| `estado` | string | `pendientes` | `pendientes` → sesiones `creada`/`procesando`/`lista_para_revision`. `historial` → `aprobada`/`promocionada`/`rechazada`/`error`, ordenadas por `cerrado_en DESC` |
| `autor_id` | int | — | Filtro opcional por autor |
| `page` | int | 1 | Página actual |
| `per_page` | int | 20 | Ítems por página (máx no definido; se recomienda ≤ 100) |

**Response 200 (pendientes):** orden `creado_en ASC` (más antiguas primero).

```json
{
    "success": true,
    "data": [
        {
            "session_id": 42,
            "status": "lista_para_revision",
            "fecha_creacion": "2026-09-01T10:30:00+00:00",
            "autor_id": 7,
            "autor_nombre": "Juan Pérez",
            "texto_original_del_aporte": "El job falla porque el batch bancario no llega antes de las 6am los lunes.",
            "resumen_clasificacion": "Se propusieron 1 hipótesis y 1 nota de conocimiento.",
            "is_simple": false,
            "pregunta_previa": "¿Por qué falla el job?",
            "cerrado_en": null
        }
    ],
    "meta": {
        "page": 1,
        "per_page": 20,
        "total": 1,
        "last_page": 1
    }
}
```

**Response 200 (historial):** mismas columnas base; `status` = estado cerrado (`aprobada`/`promocionada`/`rechazada`/`error`); `cerrado_en` con fecha de decisión; orden `cerrado_en DESC`.

**Nota historial:** los ítems cerrados **no tienen nodos** (el sandbox se elimina al aprobar/rechazar — §4). El `resumen_clasificacion` proviene del campo persistido `resumen` (Q1.3); si una sesión vieja no tiene `resumen`, QuBeKa lo recalcula al vuelo mientras tenga nodos.

**Campos por ítem** (fuente en el código real):

| Campo | Origen |
|-------|--------|
| `session_id` | `sesiones_analisis.id` |
| `status` | `sesiones_analisis.estado` (nombre real; la bandeja usa los valores arriba) |
| `fecha_creacion` | `sesiones_analisis.creado_en` (ISO 8601) |
| `autor_id` | `sesiones_analisis.autor_id` |
| `autor_nombre` | `autor()->name` (relación existente; ver B2 — hoy es el dueño del token) |
| `texto_original_del_aporte` | `sesiones_analisis.contenido_entrada` |
| `resumen_clasificacion` | `resumen` persistido (fallback a `generarResumen()`) |
| `is_simple` | `$sesion->esSimple()` |
| `pregunta_previa` | `sesiones_analisis.pregunta_previa` (nullable) |
| `cerrado_en` | `sesiones_analisis.cerrado_en` (nullable; fecha de decisión Q1.6) |

**Errores:**

| Código | Caso | `errors.message` |
|--------|------|------------------|
| 401 | Sin token / token inválido | `Token de autenticación requerido.` / `Token de autenticación inválido.` |
| 403 | Token sin scope `api:read` | `Token no tiene permisos de lectura. Se requiere scope api:read.` |
| 403 | Usuario sin rol de revisión en el workspace | `No tienes permisos de revisión sobre este workspace.` |
| 404 | Token sin workspace asociado | `Token no asociado a ningún workspace.` |

---

### 2.2 `GET /api/v1/sesiones-analisis/{sessionId}` — Detalle de sesión

Ya implementado en la Ola 1. Ver `CONTRATO_API_REVISION.md` §2.1. Se menciona aquí por completitud: es la base del flujo de revisión (los metadatos de explicabilidad del Punto 4 se agregarán a `nodos[]` — ver §4).

### 2.3 `POST /api/v1/sesiones-analisis/{sessionId}/approve` — Aprobar (o aprobar+editar)

Implementado en la Ola 1. Ver `CONTRATO_API_REVISION.md` §2.2.

### 2.4 `POST /api/v1/sesiones-analisis/{sessionId}/reject` — Rechazar

Implementado en la Ola 1. Ver `CONTRATO_API_REVISION.md` §2.3.

---

## 3. Comportamiento de rechazo (decisión congelada)

**Rechazo = retención del registro + borrado físico del sandbox.**

- La fila de la sesión **persiste** en `sesiones_analisis` con `estado = 'rechazada'` y `cerrado_en = now()`.
- El sandbox y sus nodos se **eliminan físicamente** (`WorkspaceService::eliminarSandbox` → `forceDelete`).
- En el historial (`?estado=historial`) el ítem aparece con `status=rechazada`, texto original, autor, fecha y `resumen` persistido — sin nodos.
- La política de retención temporal de nodos es decisión de producto pendiente (Ola 1, Punto 4 §4.2). Hasta que se defina, se aplica la retención del registro sin nodos.

---

## 4. Salvaguarda de limpieza (`sandbox:limpiar`)

`php artisan sandbox:limpiar --dias=7` (default 7) **no elimina** los sandboxes asociados a sesiones en estados activos (`creada`, `procesando`, `lista_para_revision`). Solo elimina sandboxes **inactivos ≥ N días** y **sin** sesión pendiente asociada. De esta forma la bandeja no muestra ítems "fantasma" (sesiones pendientes sin contenido).

Output del comando (único formato, haya o no candidatos):

```
Limpieza completada: N sandboxes eliminados de M encontrados.
```

---

## 5. Endpoints de los Puntos 2–5

### 5.1 Punto 2 — Reconfirmación periódica (IMPLEMENTADO)

- `PATCH /api/v1/nodos/{id}/reconfirmar` — scope `api:write`. Actualiza `fecha_ultima_confirmacion` + `ultimo_confirmador_id` en `nodos`; registra auditoría vía `HistorialService::registrar()`. **No toca** `version` ni `actualizado_en`. Un llamado por nodo (batch queda para etapa posterior). Idempotente. **Permisos:** autor del nodo o revisor del workspace (403 en otro caso). 404 con `code: nodo_no_disponible` si el nodo no existe/está eliminado. Respuesta: `{ success, data: { node_id, fecha_ultima_confirmacion, ultimo_confirmador_id } }`.
- `POST /api/v1/query` — extensión aditiva de `sources[]`: cada elemento incluye `fecha_ultima_confirmacion` y `ultimo_confirmador_nombre` (null hasta la primera reconfirmación).

### 5.2 Punto 3 — Indicador de vigencia (PLANEADO)

Sin endpoints nuevos (depende íntegramente de los campos de 5.1). El estado agregado lo resuelve Kuestion cliente.

### 5.3 Punto 4 — Explicabilidad

- Objeto `explicacion` por nodo con `decision_type`, `confidence` (0.0–1.0, auto-evaluación del LLM — **señal orientativa, no métrica exacta**), `reasons[]`, `alternatives_considered[]`, `detected_patterns[]`.
- Se expone en: `GET /sesiones-analisis/{id}` (detalle, en `nodos[]`), `GET /sesiones-analisis` (listado), y `POST /contribute` (respuesta del análisis síncrono). `[PLANEADO]`
- Persistido por nodo en `NodoAnalisis.datos_especificos.explicacion` (vive solo mientras la sesión está pendiente; el historial conserva solo el `resumen`).

### 5.4 Punto 5 — Notificaciones por correo

- `GET /workspaces/{id}/miembros` `[PLANEADO]` — lista `{ user_id, nombre, email, rol }` del workspace; Kuestion filtra `rol=revisor`.
- Filtro `actualizado_desde={ISO-8601}` opcional sobre `estado=historial` `[PLANEADO]` — optimización del polling, aditivo.

---

## 6. Nudos B1 y B2

### B1 — Identidad del revisor humano

- **Opción B (MVP):** la bandeja muestra **todo** lo pendiente del workspace al token del conector, sin filtro por usuario humano.
- Header reservado `X-User-Email` (opcional desde el día 1, **ignorado por QuBeKa** en MVP). Validación real = 0,5–1 día en etapa posterior (middleware que resuelve User por email, verifica rol revisor en el workspace del token → 403 claro).

### B2 — Autor de los aportes

- `POST /contribute` aceptará `autor_email` + `autor_nombre` **opcionales** (validación de formato de email sí; contra cuentas de usuario no). `[PLANEADO]`
- Migración: dos columnas nullable en `sesiones_analisis`.
- **Atribución declarada, no verificada:** autor_email/autor_nombre los declara el conector; no se contrastan contra cuentas QuBeKa. Sesiones anteriores a B2 (y aportes sin campos) llegan con `autor_email = null` — el correo a esas sesiones **no puede enviarse** y es caso normal, no error.
- QuBeKa **no** rellena el email con el del dueño del token (dueño del workspace ≠ autor real).

---

## 7. Ejemplos curl

### 7.1 Listar pendientes

```bash
curl -X GET "https://tu-servidor.com/api/v1/sesiones-analisis" \
  -H "Authorization: Bearer {token}" \
  -H "Accept: application/json"
```

### 7.2 Listar historial

```bash
curl -X GET "https://tu-servidor.com/api/v1/sesiones-analisis?estado=historial&page=1&per_page=20" \
  -H "Authorization: Bearer {token}" \
  -H "Accept: application/json"
```

### 7.3 Filtrar por autor

```bash
curl -X GET "https://tu-servidor.com/api/v1/sesiones-analisis?autor_id=7" \
  -H "Authorization: Bearer {token}" \
  -H "Accept: application/json"
```

---

## 8. Changelog

| Versión | Fecha | Cambios |
|---------|-------|---------|
| 1.0 | 2026-09-08 | Contrato limpio de Ola 2 reemplazando el borrador corrupto (ver `REVISION_CONTRATO_API_OLA2.md`). Documenta Punto 1 (implementado), Puntos 2–5 (planeados), B1/B2, rechazo, salvaguarda de limpieza. |

---

*Documento de contrato para el equipo de Kuestion. Validado contra el código real de QuBeKa el 2026-09-08. Cambios acordados por ambos equipos antes de implementar.*