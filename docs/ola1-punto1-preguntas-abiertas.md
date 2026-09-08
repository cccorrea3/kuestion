# Preguntas y Puntos Abiertos — Ola 1, Punto 1

*Estado: CERRADO — todas las respuestas recibidas de QuBeKa*
*Agosto 2026*
*Actualizado v1.1: workspace_id resuelto desde token, no desde body (corrección de contradicción QuBeKa)*

---

## Resumen

| Pregunta | Estado | Respuesta clave |
|---|---|---|
| B1. Endpoint de consulta | ✅ Cerrado | `POST /api/v1/query` |
| B2. Endpoint de identidad | ✅ Cerrado | `GET /api/v1/agent/me` |
| NB1. Scope de token | ✅ Cerrado | `api:read` es el default |
| NB2. Confianza | ✅ Cerrado | Fijo 0.5 (con resultados) / 0.0 (sin resultados) |
| NB3. Formato de fuentes | ✅ Cerrado | Formato 1.5 + `texto_preview` |
| NB4. "No encontré nada" | ✅ Cerrado | Siempre `found: false`, nunca caso mixto |
| NB5. Versionado | ✅ Cerrado | Bajo `/api/v1/` |

---

## Contratos Confirmados

### Endpoint de Motor de Consulta

```
POST {QUBKA_API_URL}/query
Authorization: Bearer {plainTextToken}
Content-Type: application/json

Request:
{
  "question": "texto de la pregunta"
}

# NOTA: workspace_id NO va en el body. Se resuelve desde el token
# del agente via middleware CheckWorkspace de QuBeKa.

Response (éxito):
{
  "answer": "texto generado",
  "confidence": 0.5,
  "sources": [
    {
      "node_id": "NK-0234",
      "tipo": "N-K",
      "estado_validacion": "validada",
      "fecha_ultima_validacion": "2026-07-10T00:00:00Z",
      "texto_preview": "Los glaciares del Aconcagua muestran retroceso..."
    }
  ],
  "found": true
}

Response (sin resultados):
{
  "answer": "No encontré información relevante...",
  "confidence": 0.0,
  "sources": [],
  "found": false
}

Errors:
  401 → Token inválida (Sanctum automático)
  404 → Workspace no existe o fue eliminado
  429 → Rate limit (60 req/min, throttle:api)
  5xx → Error del servidor / LLM
```

**Timeout esperado:** 5–15s (LLM local Ollama). Kuestion usa 120s como máximo.

### Endpoint de Resolución de Identidad

```
GET {QUBKA_API_URL}/agent/me
Authorization: Bearer {plainTextToken}

Response (éxito):
{
  "workspace_id": 123,
  "workspace_nombre": "Investigación Jurídica",
  "user_id": 45,
  "user_nombre": "Juan Pérez",
  "agente_nombre": "Kuestion Connector",
  "scopes": ["api:read"]
}

# NOTA: el workspace_id del token SIEMPRE es el mismo que se usa en
# la consulta (el middleware CheckWorkspace lo resuelve automáticamente).
# No hay forma de consultar un workspace distinto al autorizado por el token.

Errors:
  401 → Token inválida
  404 → Workspace eliminado
```

**Nota:** el workspace ES la unidad más alta en QuBeKa (no hay "tenant" o "organización" superior).

### Variables de Entorno para Kuestion

```env
QUBKA_API_URL=https://qubeka.ejemplo.com/api/v1
```

### Decisiones de Implementación Confirmadas

| Decisión | Confirmación |
|---|---|
| **Opción A vs B** (sección 1.4) | QuBeKa va por la **Opción A** — reutiliza el proveedor de IA embebido (`OllamaProvider` + `AiProviderFactory`). |
| **Scope de token** | `api:read` es el default y ya está implementado. No permite escritura. |
| **Confianza** | Fijo: 0.5 (con resultados) / 0.0 (sin resultados). Se calibrará cuando exista búsqueda semántica. |
| **`fecha_ultima_validacion`** | Puede ser `null` cuando no existe en `datos_especificos->revision_fecha`. Kuestion lo maneja como "fecha desconocida". |
| **Rate limiting** | `throttle:api` (60 req/min). Se puede ajustar si Kuestion necesita más volumen. |
| **workspace_id en query** | **CORREGIDO v1.1:** el body solo contiene `{"question": "..."}`. El `workspace_id` se resuelve desde el token del agente via middleware `CheckWorkspace` de QuBeKa. No se envía en el body. Si se envía, se ignora sin error. |
