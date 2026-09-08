# Preguntas y Puntos Abiertos — Ola 1, Punto 3

*Estado: CERRADO — todas las respuestas recibidas de QuBeKa*
*Agosto 2026*

---

## Resumen

| Pregunta | Estado | Respuesta clave |
|---|---|---|
| B1. Endpoint de clasificación | ✅ Cerrado | `POST /api/v1/contribute` |
| B2. Scope de token | ✅ Cerrado | `api:read` + `api:write` (ya existe) |
| NB1. Síncrono/asincrónico | ✅ Cerrado | Síncrono (~2-5s) |
| NB2. Límite de longitud | ✅ Cerrado | 2000 chars |
| NB3. Generación del resumen | ✅ Cerrado | QuBeKa genera el resumen |
| NB4. Manejo de pregunta_previa | ✅ Cerrado | Ajuste menor en QuBeKa (migración + prompt) |

---

## Contratos Confirmados

### Endpoint de Clasificación de Aportes

```
POST {QUBKA_API_URL}/contribute
Authorization: Bearer {plainTextToken}
Content-Type: application/json

Request:
{
  "texto": "El job falla porque el batch del banco no llega antes de las 6am",
  "origen": "kuestion",
  "pregunta_previa": "¿Por qué falla el job de conciliación los lunes?"  // opcional
}

Response (éxito):
{
  "session_id": 42,
  "status": "pendiente_revision",
  "resumen": "Se propuso 1 hipótesis y 1 nota de conocimiento, pendientes de revisión."
}

Errors:
  401 → Token inválida
  403 → Sin permiso `api:write` o no es miembro del workspace
  422 → Validación fallida (texto vacío, demasiado largo, etc.)
  429 → Rate limit (60 req/min)
  5xx → Error interno del servicio de clasificación
```

**Diferencia con el contrato original:** el `workspace_id` **NO va en el body** — se resuelve desde el token (via `agente_tokens.workspace_id`), igual que el endpoint de consulta del punto 1.

**Sincronía:** síncrono. Para ~2000 chars (1 chunk), el análisis con Ollama toma ~2-5s. El endpoint devuelve la respuesta completa en la misma llamada.

**Validación server-side:** `required|string|min:10|max:2000`

### Scope de Token

```php
['api:read', 'api:write']  // Combinación correcta
```

- `api:read`: consulta al Motor de Consulta (punto 1)
- `api:write`: aporte de conocimiento (punto 3)
- El `api:write` permite crear en sandbox, NO en el grafo activo directamente

### Decisiones de Implementación Confirmadas

| Decisión | Confirmación |
|---|---|
| **Workspace en body** | No va — se resuelve desde el token |
| **Sincronía** | Síncrono, ~2-5s |
| **Límite de texto** | 2000 chars (mismo que "Preguntar") |
| **Resumen** | Generado por QuBeKa, Kuestion solo consume el string |
| **`pregunta_previa`** | QuBeKa la agrega al prompt de clasificación para proponer una Q asociada |
| **Tipo de origen** | Nuevo valor `'kuestion'` en `tipo_origen` (además de `texto_libre`, `archivo_md`) |
