# Reporte al equipo de QuBeKa — Verificación posterior a aprobación (Ola 1, Punto 4)

*De: Equipo de Kuestion · Fecha: 2026-09-03*
*Contexto: prueba funcional de usuario del "Gate humano de revisión" (Punto 4, OLA 1)*

---

## 1. Qué se probó

Desde Kuestion (`localhost:8001`) se aprobó el aporte de conocimiento correspondiente a la
**sesión de análisis id = 13**. Luego se verificó directamente en la base de datos `qubeka`
cómo quedaron la sesión y los nodos resultantes, tal como indicaba el plan de implementación.

El aporte aprobado (ingresado por el usuario) fue, en resumen:
> *Datos personales y profesionales de Cristian Correa: ingeniero civil informático de la
> Universidad Diego Portales, 52 años, casado con Susana hace 25 años, un hijo de 18 años,
> aficionado al ajedrez y al golf.*

---

## 2. Resultado de la verificación — el flujo FUNCIONA ✅

La aprobación desde Kuestion se propagó correctamente a QuBeKa.

### 2.1 Sesión de análisis

```sql
SELECT id, estado, nodos_propuestos, enlaces_propuestos,
       workspace_sandbox_id, workspace_destino_id,
       creado_en, actualizado_en, cerrado_en
FROM qubeka.sesiones_analisis WHERE id = 13;
```

| Campo | Valor |
|---|---|
| `id` | 13 |
| `estado` | **`promocionada`** |
| `nodos_propuestos` | 2 |
| `enlaces_propuestos` | 1 |
| `workspace_sandbox_id` | NULL |
| `workspace_destino_id` | 1 |
| `creado_en` | 2026-09-02 22:03:05 |
| `actualizado_en` | 2026-09-03 08:06:48 |
| `cerrado_en` | 2026-09-03 12:06:48 |

### 2.2 Nodos promovidos al grafo activo (workspace 1)

Dos nodos fueron creados en `nodos` con `workspace_id = 1` y `creado_en = 2026-09-03 12:06:48`
(idéntico a `cerrado_en` de la sesión):

- **Q-9467** — tipo `Q` (Pregunta), `parent_id = NULL`
  - `datos_especificos.texto`: *"¿Quién es Cristian Correa y cuáles son sus características personales y profesionales?"*
  - `estado: abierta`, `fecha_planteamiento: 2026-09-03`
- **H-029** — tipo `H` (Hipótesis), `parent_id = Q-9467` (colgando de la pregunta)
  - `datos_especificos.texto`: *"Cristian Correa es un ingeniero Civil informático de la Universidad Diego Portales, de 52 años, casado con Susana hace 25 años, padre de un hijo de 18 años, aficionado al ajedrez y al golf."*
  - Datos estructurados: `edad: 52`, `hijos: 1`, `pareja: Susana`, `años_matrimonio: 25`,
    `edad_hijo: 18`, `hobbies: [Ajedrez, Golf]`, `profesion: Ingeniero Civil Informático`,
    `universidad: Universidad Diego Portales`, `estado_civil: Casado`,
    `nivel_confianza: 0.5`, `epistemic_status: especulativo`, `validada: false`

**Conclusión:** el gate humano de revisión aprobó y los nodos quedaron creados en el grafo
activo de QuBeKa correctamente.

---

## 3. Hallazgos que requieren corrección o aclaración

### 3.1 Discrepancia entre el plan y la implementación real del valor de `estado`

- **El plan de implementación (Punto 4) indicaba** que tras aprobar, el estado de la sesión
  debería cambiar de `lista_para_revision` a **`aprobada`**.
- **El valor real almacenado** en `sesiones_analisis.estado` es **`promocionada`**.

> La aprobación funcionó (la sesión se cerró y los nodos se promovieron), pero el literal
> del estado **no coincide con el documentado en el plan**. Es una diferencia de
> nomenclatura que generó confusión durante la verificación.
>
> **Pedido:** confirmar el conjunto canónico de valores que puede tomar `estado`
> (`lista_para_revision`, `promocionada`, `rechazada`, otros) y actualizar / aclarar la
> documentación del contrato `GET /sesiones-analisis/{id}` (campo `status`) para que Kuestion
> muestre/mapee los estados correctamente.

### 3.2 Esquema de la tabla / respuesta del endpoint difiere del documentado

El plan y el contrato del endpoint asumen un esquema que **no coincide** con lo
observado en la base real. Ejemplos:

| Concepto del plan / contrato | Realidad en la tabla `sesiones_analisis` |
|---|---|
| Columna `estatus` (como la consulta sugerida del plan) | La columna real se llama **`estado`** |
| Campo `is_simple` | **No existe** en la tabla |
| Campo `resumen` | **No existe** en la tabla (el resumen viene en otra parte o no se persiste) |
| Columnas `created_at` / `updated_at` | Se llaman **`creado_en` / `actualizado_en`**, y hay además `procesado_en`, `cerrado_en` |
| `workspace_id` del sandbox | Hay `workspace_sandbox_id` y `workspace_destino_id`; en la sesión 13 `workspace_sandbox_id` es NULL |

> **Pedido:** aclarar cuál es el esquema correcto de `sesiones_analisis` y si el endpoint
> `GET /sesiones-analisis/{id}` devuelve `is_simple` y `resumen` calculados (no columnas),
> para alinear el mapeo en el servicio de Kuestion (`QbkContributionService::getSession`).

### 3.3 `nodos_analisis` vacío para la sesión 13 (a confirmar si es esperado)

La tabla sandbox `nodos_analisis` **no tiene filas para `sesion_analisis_id = 13`**, aunque
sí las tiene para las sesiones 7, 8, 9 y 12:

```sql
SELECT sesion_analisis_id, COUNT(*) AS nodos FROM nodos_analisis GROUP BY sesion_analisis_id;
-- 7 → 3, 8 → 3, 9 → 2, 12 → 3    (la 13 no aparece)
```

A pesar de eso, los nodos **sí** se promovieron al grafo activo (`nodos` → Q-9467 y H-029).

> **Pedido:** confirmar si es el comportamiento esperado que, al cerrar una sesión como
> "promocionada", los registros de `nodos_analisis` del sandbox se limpien/transfieran (y por
> eso no quedan filas), o si la sesión 13 se procesó por un camino distinto que dejó el
> sandbox sin persistir. No es un bloqueo — el resultado en el grafo activo es correcto —
> pero queremos entender el ciclo de vida de los nodos del sandbox.

---

## 4. Verificación adicional sugerida (opcional)

- Confirmar que el **embeddings / reindexado** de los nodos Q-9467 y H-029 se dispara (la
  columna `estado_indexado` de ambos está `NULL` en la verificación, y puede ser el flujo
  normal si se indexa por job posterior).
- Si el endpoint expone los nodos promovidos, validar que `GET /sesiones-analisis/13` (o el
  detalle de la sesión cerrada) muestre el vínculo a Q-9467 / H-029 para trazabilidad.
