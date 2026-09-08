# Preguntas y Puntos Abiertos — Ola 1, Punto 4

*Estado: CERRADO — todas las respuestas recibidas de QuBeKa*
*Agosto 2026*

---

## Resumen

| Pregunta | Estado | Respuesta clave |
|---|---|---|
| B1. Endpoint de detalle de sesión | ✅ Cerrado | `GET /api/v1/sesiones-analisis/{sessionId}` (nuevo) |
| B2. Endpoint de aprobación/rechazo | ✅ Cerrado | `POST .../approve` + `POST .../reject` (nuevos) |
| B3. Criterio simple vs. compleja | ✅ Cerrado | ≤2 nodos + confianza ≥0.5 + sin conflictos = simple |
| NB1. `pregunta_previa` en revisión | ✅ Cerrado | Incluirlo en el endpoint de detalle |
| NB2. Autoconfirmación en móvil | ✅ Cerrado | UX de Kuestion, endpoints de QBK funcionan igual |

---

## Contratos Confirmados

### Endpoint de Detalle de Sesión

```
GET {QUBKA_API_URL}/sesiones-analisis/{sessionId}
Authorization: Bearer {token}

Response:
{
  "session_id": 42,
  "status": "lista_para_revision",
  "is_simple": true,
  "pregunta_previa": "¿Por qué falla el job de conciliación los lunes?",
  "nodes": [
    {
      "id": "sandbox_1",
      "tipo": "H",
      "texto": "El job falla porque el batch del banco no llega antes de las 6am",
      "relaciones": []
    },
    {
      "id": "sandbox_2",
      "tipo": "N-K",
      "texto": "Evidencia: en la revisión del 2026-08-10 se confirmó que el batch llega a las 7:30am",
      "relaciones": [{"con": "sandbox_1", "tipo": "evidencia_de"}]
    }
  ],
  "resumen": "Se propuso 1 hipótesis y 1 nota de conocimiento, pendientes de revisión.",
  "created_at": "2026-08-29T10:30:00Z",
  "workspace_nombre": "Investigación Jurídica"
}

Errors: 401, 404, 5xx
```

### Endpoint de Aprobación

```
POST {QUBKA_API_URL}/sesiones-analisis/{sessionId}/approve
Authorization: Bearer {token}
Content-Type: application/json

Request (opcional):
{
  "textos_ajustados": {
    "sandbox_1": "Texto ajustado de la hipótesis",
    "sandbox_2": "Texto ajustado de la nota"
  }
}

Response:
{
  "success": true,
  "session_id": 42,
  "status": "promocionada",
  "nodos_creados": 2,
  "enlaces_creados": 1
}

Errors: 401, 403, 404, 422, 5xx
```

### Endpoint de Rechazo

```
POST {QUBKA_API_URL}/sesiones-analisis/{sessionId}/reject
Authorization: Bearer {token}

Response:
{
  "success": true,
  "session_id": 42,
  "status": "rechazada"
}

Errors: 401, 403, 404, 5xx
```

### Criterio Simple vs. Compleja

```php
// SesionAnalisis::esSimple(): bool
// Simple = ≤2 nodos AND confianza ≥0.5 AND sin conflictos de tipo
// Compleja = cualquiera de las condiciones anteriores falla
```

### URL de Redirección para Sesiones Complejas

```
https://{QUBKA_URL}/analisis/{sessionId}/revision
```

Esta URL ya existe como ruta Livewire en QBK.
