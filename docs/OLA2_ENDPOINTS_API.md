# Fuente de Verdad de Endpoints — Ola 2: Kuestion ↔ QuBeKa

**Ecosistema:** Kuestion · QuBeKa
**Versión:** 1.1 (basado en el contrato revalidado por QuBeKa el 2026-09-08)
**Fecha:** Septiembre 2026
**Estado real:** Puntos 1 y 2 **implementados** en QuBeKa; Puntos 3–5 `[PLANEADO]` — no consumir hasta confirmación de despliegue.
**Fuente única:** `docs/CONTRATO_API_OLA2.md` — este archivo es extracto por endpoint para consumo rápido; el contrato es la fuente de verdad.

---

## 1. Sobre de respuesta (válido para todos los endpoints)

**Éxito:**
```json
{
  "success": true,
  "data": { ... }
}
```

**Error:**
```json
{
  "success": false,
  "data": null,
  "errors": {
    "message": "..."
  }
}
```

> No usar `message`/`data` ni `error.code`/`message`/`details` — formato confirmado por QuBeKa contra código real (opción a de `REVISION_CONTRATO_API_OLA2.md` §3.4). Sin códigos de máquina, salvo `code` puntual documentado por endpoint (ej. `nodo_no_disponible` en reconfirmar).

---

## 2. Autenticación y autorización (válido para todos)

- **Token:** Bearer del agente de QuBeKa (`Authorization: Bearer {token}`)
- **Workspace:** resuelto desde `agente_tokens.workspace_id` (no desde body)
- **Scopes:**
  - `api:read` → lectura
  - `api:write` → `POST /contribute`, `POST .../approve`, `POST .../reject`, `PATCH .../reconfirmar`
- **Nudo B1 (MVP):** header `X-User-Email` definido como opcional, ignorado por QuBeKa hasta validación posterior (sección 9 del contrato)

---

## 3. Endpoint — Listado de sesiones de análisis

**Ruta:** `GET /api/v1/sesiones-analisis`
**Ciclo:** C1 (Bandeja de revisión)

### Parámetros

| Parámetro | Tipo | Default | Valores |
|---|---|---|---|
| `estado` | string | `pendientes` | `pendientes` | `historial` |
| `autor_id` | integer | — | opcional |
| `page` | integer | 1 | — |
| `per_page` | integer | 20 | — |

### Filtros de estado

| Valor | Estados incluidos |
|---|---|
| `pendientes` | `creada`, `procesando`, `lista_para_revision` |
| `historial` | `aprobada`, `promocionada`, `rechazada`, `error` |

> No existen los valores `pendiendo` ni `historia_execution` (corrección de borrador anterior, ver sección 15 del contrato).

### Respuesta — Ítem por sesión

| Campo | Origen QuBeKa | Tipo |
|---|---|---|
| `session_id` | `sesiones_analisis.id` | string/UUID |
| `status` | `estado` | string |
| `fecha_creacion` | `creado_en` | datetime |
| `texto_original_del_aporte` | `contenido_entrada` | text |
| `resumen_clasificacion` | `resumen` (persistido) | text/nullable |
| `is_simple` | `esSimple()` | boolean |
| `pregunta_previa` | `pregunta_previa` | text/nullable |
| `autor_email` | sujeto a B2 | string/nullable |
| `autor_nombre` | sujeto a B2 | string/nullable |
| `fecha_decision` | `cerrado_en` | datetime/nullable |

> **Naming real del response (contrato 2026-09-08):** el listado expone los nombres de la spec — `fecha_creacion`, `texto_original_del_aporte`, `resumen_clasificacion` — mapeados internamente desde las columnas `creado_en`, `contenido_entrada` y `resumen`. `autor_email` **no existe aún** (llega con B2 `[PLANEADO]`); `autor_nombre` hoy es el dueño del token hasta B2.

### Estados de decisión para polling (C4)

- `aprobada` → estado **intermedio** (la promoción corre en cola, `cerrado_en` se setea después)
- `promocionada` → estado **final** (éxito del ciclo)
- `rechazada` → estado **final** (rechazo)

> El polling de Kuestion detecta decisión por estados finales, no por `aprobada`.

---

## 4. Endpoint — Detalle de sesión de análisis

**Ruta:** `GET /api/v1/sesiones-analisis/{id}`
**Ciclo:** C1, C3

### Respuesta

- Incluye `is_simple`, nodos propuestos, enlaces
- Incluye metadatos de explicabilidad cuando existan (ver Punto 4: campo `explicacion` por nodo)
- Campos de listado también disponibles (consistencia con §3)

---

## 5. Endpoint — Aprobar sesión

**Ruta:** `POST /api/v1/sesiones-analisis/{id}/approve`
**Ciclo:** C1
**Scope:** `api:write`

### Body opcional

```json
{
  "textos_ajustados": {
    "nodo_sandbox_id": "texto ajustado",
    ...
  }
}
```

### Comportamiento

-_respuesta: estado `aprobada` (transitorio)
- La promoción real corre en cola → estado final `promocionada` + `cerrado_en` seteado
- No se debe considerar `aprobada` como decisión final para el ciclo C4

---

## 6. Endpoint — Rechazar sesión

**Ruta:** `POST /api/v1/sesiones-analisis/{id}/reject`
**Ciclo:** C1
**Scope:** `api:write`

### Comportamiento

- Sesión conservada con estado `rechazada` + `cerrado_en` = now()
- Sandbox eliminado físicamente
- Aparece en historial (`estado=historial`)

---

## 7. Endpoint — Contribuir (análisis)

**Ruta:** `POST /api/v1/contribute`
**Ciclo:** C1, C3
**Scope:** `api:write`

### Body

| Campo | Tipo | Obligatorio | Nota |
|---|---|---|---|
| `texto` | string | sí | — |
| `pregunta_previa` | string | no | — |
| `autor_email` | string | no | sujeto B2 (atribución declarada) |
| `autor_nombre` | string | no | sujeto B2 (atribución declarada) |

> `autor_email`/`autor_nombre` son **opcionales** y **no se validan contra cuentas QuBeKa** (condición de honestidad, sección 10 del contrato).

### Respuesta

- `session_id`, `status`, `resumen`
- **Incluye metadatos de explicabilidad** cuando el análisis es síncrono (metadatos persistidos por nodo ya existen al responder)

---

## 8. Endpoint — Reconfirmar nodo

**Ruta:** `PATCH /api/v1/nodos/{id}/reconfirmar`
**Ciclo:** C2
**Scope:** `api:write`

### Comportamiento (IMPLEMENTADO en QuBeKa, contrato 2026-09-08)

- Actualiza `fecha_ultima_confirmacion` + `ultimo_confirmador_id` en `nodos`
- Registra auditoría vía `HistorialService::registrar()`
- **No toca** `version` ni `actualizado_en` (para no romper hash de vigilancia)
- **Idempotente**

### Permisos

- Autor del nodo o revisor del workspace → 200
- Otro caso → 403 con `errors.message` legible
- Nodo inexistente/eliminado → 404 con `code: nodo_no_disponible`

### Respuesta de éxito

```json
{
  "success": true,
  "data": {
    "node_id": 123,
    "fecha_ultima_confirmacion": "2026-09-08T15:00:00+00:00",
    "ultimo_confirmador_id": null
  }
}
```

> `ultimo_confirmador_id` queda `null` en modo MVP (sin identidad humana, B1).

### Forma de llamado

- **Por nodo individual** (esta versión): Kuestion itera sobre los `sources` de la versión actual
- Array de `node_ids` → fase posterior (no breaking si se agrega)

### Campo en respuesta de `POST /query` (extensión aditiva)

Cada elemento de `sources[]` incluye:
- `fecha_ultima_confirmacion`: siempre presente (`null` si nunca se reconfirmó)
- `ultimo_confirmador_nombre`: siempre presente, **valor variable** (Decisión D-Confirmador, contrato §5.3): nombre real del usuario de QuBeKa cuando reconfirmó una persona (PAT humano), o `"Kuestion (conector)"` cuando fue el conector (B1 MVP). La vía queda en `confirmacion_via` (`'humano'`/`'conector'`). Renderizar tal cual viene — no asumir un literal fijo.

> Esta extensión es aditiva: no modifica `answer`, `confidence`, `found` ni el hash de vigilancia.

---

## 9. Endpoint — Miembros del workspace

**Ruta:** `GET /api/v1/workspaces/{id}/miembros`
**Ciclo:** C4
**Scope:** `api:read` (asumido)

### Respuesta

```json
{
  "data": [
    {
      "user_id": "...",
      "nombre": "...",
      "email": "...",
      "rol": "propietario|editor|revisor"
    },
    ...
  ]
}
```

> No es extensión de `GET /agent/me` (ese representa contexto del token, no censo de personas).

> Kuestion filtra `rol=revisor` para el correo "aporte pendiente de revisión" (Punto 5).

---

## 10. Endpoint — Filtro incremental (optimización de polling)

**Ruta:** `GET /api/v1/sesiones-analisis?estado=historial&actualizado_desde={ISO-8601}`
**Ciclo:** C4

### Parámetros adicionales

| Parámetro | Tipo | Descripción |
|---|---|---|
| `actualizado_desde` | ISO-8601 | Filtra por `cerrado_en`/`actualizado_en` |

> No es requisito del ciclo (polling completo funciona); es optimización aditiva.

---

## 11. Resumen de endpoints por ciclo

| # | Endpoint | Método | Scope | Ciclo | Estado |
|---|---|---|---|---|---|
| 1 | `/api/v1/sesiones-analisis` | GET | `api:read` | C1 | **Implementado** en QuBeKa (2026-09-08) |
| 2 | `/api/v1/sesiones-analisis/{id}` | GET | `api:read` | C1, C3 | Existente (extensión explicabilidad `[PLANEADO]`) |
| 3 | `/api/v1/sesiones-analisis/{id}/approve` | POST | `api:write` | C1 | Contrato confirmado |
| 4 | `/api/v1/sesiones-analisis/{id}/reject` | POST | `api:write` | C1 | Contrato confirmado |
| 5 | `/api/v1/contribute` | POST | `api:write` | C1, C3 | Existente (extensión B2/explicabilidad `[PLANEADO]`) |
| 6 | `/api/v1/nodos/{id}/reconfirmar` | PATCH | `api:write` | C2 | **Implementado** en QuBeKa (2026-09-08) |
| 7 | `/api/v1/workspaces/{id}/miembros` | GET | `api:read` | C4 | Nuevo (~0.5 día QuBeKa) |
| 8 | filtro `actualizado_desde` | query | — | C4 | Aditivo (~0.25–0.5 día QuBeKa) |

---

## 12. Pendientes de decisión (para versiones futuras del contrato)

| # | Asunto | Estado |
|---|---|---|
| 1 | `confidence` de explicabilidad: documentar como "señal orientativa" del LLM | ⏳ Pendiente decisión de producto (ver sección 13-4 del contrato) |
| 2 | Array de `node_ids` en `PATCH .../reconfirmar` (vs. uno solo) | Deferido (no breaking) |
| 3 | Validación de header `X-User-Email` en QuBeKa (B1) | Deferido (header ignorado hasta entonces) |
| 4 | Semántica de permisos del header (B1 etapa posterior) | Sin definir aún |
| 5 | Forma del payload de errores (`{message}` vs códigos tipificados) | Solo `errors.message` confirmado; códigos de máquina → aditivo (~0.5 día + regresión) |

---

## 13. Referencias

- `docs/CONTRATO_API_OLA2.md` — fuente única de contrato
- `docs/ola2-preguntas-contrato-qubeka.md` — preguntas y respuestas que originaron el contrato
- `docs/ola2-punto1-plan-implementacion-kuestion.md` — Plan Kuestion P1 (compromiso §7)
- `docs/ola2-punto2-plan-implementacion-kuestion.md` — Plan Kuestion P2 (compromiso §7)
- `docs/ola2-punto3-plan-implementacion-kuestion.md` — Plan Kuestion P3 (compromiso §7)
- `docs/ola2-punto4-plan-implementacion-kuestion.md` — Plan Kuestion P4 (compromiso §7)
- `docs/ola2-punto5-plan-implementacion-kuestion.md` — Plan Kuestion P5 (compromiso §7)
