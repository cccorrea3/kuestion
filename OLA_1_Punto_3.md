# Aportar: clasificación automática de conocimiento nuevo desde Kuestion

*Especificación de trabajo — Ola 1, punto 3 (incluye actualización del punto 2: superficie de entrada)*
*Agosto 2026 · Documento de entrada para los equipos de QuBeKa y Kuestion*

---

## 0. Qué resuelve este documento

El punto 1 (ya especificado) permite que Kuestion *lea* el grafo de QBK. Este documento define cómo Kuestion permite que alguien *aporte* conocimiento nuevo sin saber que existe un grafo por debajo — y cómo ese aporte se convierte, con ayuda de IA y sin saltarse el gate humano, en contenido estructurado dentro de QBK.

**Decisión ya tomada, que este documento da por cerrada:** la superficie de entrada tiene dos acciones explícitas, **Preguntar** y **Aportar**, en vez de que el sistema infiera la intención de un único campo de texto. Esto reemplaza la definición original del punto 2 ("campo de pregunta único") — ya no es un campo con una sola acción posible, es un campo con dos botones. La razón de este cambio: inferir si un texto es pregunta o afirmación es un problema de clasificación genuinamente difícil (hay evidencia real de este ecosistema de que ese tipo de decisión falla sin criterio explícito), y el costo de pedir que la persona elija un botón es mínimo comparado con el costo de que el sistema se equivoque en silencio.

**Fuera de alcance de este documento:** que un aporte responda automáticamente a una Q/SQ/H que ya existía sin evidencia (lo que veníamos llamando "Capa 2"). En esta primera versión, todo aporte entra como potencialmente nuevo; si en la revisión humana resulta que en realidad respondía algo ya abierto, quien revisa lo enlaza a mano.

---

## 1. Alcance funcional — superficie de entrada (Kuestion)

### 1.1 Qué ve la persona

Una única pantalla de entrada, con:

- Un campo de texto libre.
- Dos botones: **Preguntar** y **Aportar**.
- Sin selector de repositorio si solo hay uno conectado (ya definido en el sistema de conectores existente).

No hay ninguna otra decisión visible antes de escribir. La complejidad de qué pasa después vive completamente del lado del sistema.

### 1.2 Qué pasa al presionar "Preguntar"

Sin cambios respecto al punto 1 ya especificado: dispara el motor de consulta de QBK, muestra la respuesta con fuentes citadas, y ofrece "Vigilar esta pregunta" si el resultado fue relevante.

### 1.3 Qué pasa al presionar "Aportar"

1. Kuestion envía el texto al servicio de clasificación de QuBeKa (el mismo motor que ya usa el flujo de ingestión asistida, ahora invocado con origen `kuestion` en vez de una carga de documento).
2. Mientras se procesa, Kuestion muestra un estado de espera simple ("Analizando tu aporte...").
3. Cuando el servicio responde, Kuestion muestra una confirmación liviana, **no el grafo ni la estructura Q/SQ/H/N-K** — eso sería reintroducir la complejidad que estamos escondiendo: *"Gracias, esto quedó guardado y pendiente de revisión."*
4. No hay edición ni ajuste de la propuesta desde Kuestion en esta versión — quien ajusta la clasificación es quien revisa (punto 4, todavía por especificar), y esa revisión vive del lado de QBK o de una vista de Kuestion que llama a la API de QBK (decisión pendiente del punto 4, no de este documento).

### 1.4 Qué información viaja en el aporte

Además del texto, Kuestion debe enviar contexto que ayuda a la clasificación:

- El **workspace de QBK** resuelto para ese usuario (ya disponible desde el `IdentityResolver` construido en el punto 1 — no hay trabajo nuevo acá, se reutiliza).
- Si el aporte llegó **inmediatamente después de una búsqueda sin resultados** (el caso del ejemplo: alguien preguntó, no encontró nada, y aportó la respuesta a continuación), Kuestion debería mandar esa pregunta original como contexto — no para que el sistema decida solo, sino para que la propuesta de clasificación tenga más información y arme una Q asociada en vez de dejarla suelta. Si el aporte es espontáneo, sin pregunta previa, este campo va vacío y el sistema clasifica solo con el texto.

---

## 2. Alcance funcional — servicio de clasificación (QuBeKa)

### 2.1 Qué debe hacer

Recibir un texto (más contexto opcional de pregunta previa y workspace), y devolver una propuesta de estructura QBK, sin escribir nada todavía en el grafo activo — usando el mismo mecanismo de sandbox ya construido para el flujo de ingestión (workspace aislado, reutilizando el modelo de `nodos`/`enlaces`, con las mismas dos excepciones ya definidas: borrado físico permitido dentro del sandbox, e IDs nuevos vía `contadores_id` recién en el momento de promoción).

### 2.2 Reutilización del flujo de cinco pasos ya diseñado

Este punto no es un flujo nuevo — es una entrada nueva al flujo de cinco pasos que QuBeKa ya tiene decidido para la ingestión asistida (Entrada → Análisis → Sandbox → Revisión Humana → Resolución). La única diferencia real es el origen de la Entrada: en vez de un documento cargado, es una frase corta escrita en Kuestion. Esto implica:

- El aporte de Kuestion crea una **sesión** de análisis, igual que cualquier otra entrada al flujo (la sesión ya está diseñada como entidad que agrupa contenido por corrida, guardable y resumible).
- Dado que es una sola frase, la sesión resultante va a tener, casi siempre, uno o dos nodos propuestos — no un lote grande como en una carga de documento. El servicio no debería asumir volumen; debe funcionar igual de bien con una sesión de un solo nodo.

### 2.3 Qué debe proponer

Como mínimo:
- Si hace falta una **Q** nueva o si el aporte cuelga de una Q/SQ ya existente (usando el contexto de pregunta previa si Kuestion lo mandó).
- Una **H** cuando el texto tiene forma de explicación/causa.
- Una **N-K** con el texto del aporte como evidencia, vinculada a la H o directamente a la Q/SQ si no hay hipótesis clara.
- Todo en `pendiente_revision`, dentro del sandbox — nada toca el grafo activo en este paso.

### 2.4 Qué NO debe hacer en esta versión

- No debe intentar vincular el aporte a Q/SQ/H **sin evidencia** que ya existían y que el aporte podría estar respondiendo (la "Capa 2" que quedó fuera de alcance).
- No debe promover nada a estado validado — la promoción es exclusivamente resultado de la revisión humana (punto 4).
- No debe fusionar automáticamente con nodos casi-duplicados — eso ya está identificado como una capacidad separada, futura.

---

## 3. Contrato entre Kuestion y QuBeKa para este flujo

### 3.1 Request (Kuestion → QuBeKa)

```json
{
  "texto": "El job falla porque el batch del banco no llega antes de las 6am los lunes",
  "workspace_id": "resuelto desde IdentityResolver",
  "origen": "kuestion",
  "pregunta_previa": "¿Por qué falla el job de conciliación los lunes en Ispend?"
}
```

`pregunta_previa` es opcional — solo presente cuando el aporte sigue a una búsqueda sin resultados en la misma interacción.

### 3.2 Response (QuBeKa → Kuestion)

```json
{
  "session_id": "sesión de análisis creada",
  "status": "pendiente_revision",
  "resumen": "Se propuso 1 hipótesis y 1 nota de conocimiento, pendientes de revisión."
}
```

Deliberadamente **no** se devuelve el detalle de los nodos propuestos (tipo, contenido exacto, relaciones) — Kuestion no necesita mostrarlo, y no mostrarlo es justamente lo que mantiene la complejidad escondida (sección 1.3). El `resumen` es lo único que se usa para la confirmación visible a la persona.

---

## 4. Un punto de arquitectura que este documento deja abierto y que afecta al punto 1 ya cerrado

El documento del punto 1 dejó como pregunta pendiente si el token de agente de QBK usado por el conector de Kuestion podía emitirse con **scope de solo lectura**, porque en ese momento Kuestion solo necesitaba consultar. Con este punto 3, eso cambia: **Aportar requiere permiso de escritura** (crear contenido en el sandbox, aunque sea en `pendiente_revision`).

Esto no invalida nada de lo ya definido, pero sí cierra la pregunta abierta del punto 1 en una dirección concreta: el token que Kuestion usa para conectarse a QBK necesita, como mínimo, permiso de lectura sobre el grafo activo y permiso de escritura sobre el sandbox — no alcanza con un scope puramente de solo lectura si se quiere tener ambos puntos (1 y 3) funcionando con la misma credencial.

**A confirmar con QuBeKa:** si el token de agente actual ya permite esa combinación (lectura de grafo activo + escritura en sandbox) de forma natural, o si hace falta definir un scope específico para conectores externos como Kuestion, distinto del que usan los agentes de plataforma (QBK, Kuaforia) entre sí.

---

## 5. Operacional

| Aspecto | Definición |
|---|---|
| **Sincronía** | El "Aportar" puede responder de forma asíncrona si la clasificación toma más de unos segundos — no debería bloquear la UI de Kuestion esperando el análisis completo. La confirmación ("pendiente de revisión") puede llegar apenas se crea la sesión, sin esperar a que el análisis termine de proponer todos los nodos. |
| **Reintentos/errores** | Si el servicio de clasificación falla, Kuestion debe guardar el texto del aporte en algún lugar temporal y ofrecer reintentar — nunca debe perderse el aporte de la persona por un error del servicio. |
| **Autenticación** | Reutiliza la misma credencial del conector ya definida en el punto 1, con el ajuste de scope de la sección 4. |
| **Límites** | A definir junto con QuBeKa: si hay un límite de longitud de texto razonable para un aporte espontáneo, dado que la sesión de análisis fue diseñada pensando en documentos, no en frases sueltas. |

---

## 6. Qué se le pide a cada equipo

### A QuBeKa

1. Confirmar que el servicio de clasificación ya construido para el flujo de ingestión acepta esta nueva forma de entrada (texto corto + contexto opcional de pregunta previa + origen `kuestion`), o identificar qué ajuste mínimo necesita para aceptarla.
2. Confirmar que una sesión de análisis funciona correctamente con un solo nodo propuesto (caso de uso distinto al de carga de documentos, que probablemente genera sesiones más grandes).
3. Definir el scope de token necesario para que un conector externo pueda leer el grafo activo y escribir en el sandbox con la misma credencial (sección 4).
4. Confirmar el formato de respuesta de la sección 3.2, en particular si el `resumen` en lenguaje natural ya es algo que el servicio puede generar, o si es un campo nuevo a construir.

### A Kuestion

1. Actualizar la superficie de entrada para mostrar dos botones (Preguntar / Aportar) en vez de un campo con inferencia de intención.
2. Construir el llamado a QuBeKa para "Aportar" (análogo al ya construido para "Preguntar" en el punto 1, pero de escritura).
3. Construir el manejo de estado de espera + confirmación liviana (sección 1.3), sin exponer estructura de nodos en la UI.
4. Definir cómo se guarda un aporte localmente si el servicio de QuBeKa falla al recibirlo (sección 5), para no perder el texto de la persona.

---

## 7. Decisiones abiertas (no resueltas en este documento)

- Scope de token combinado lectura/escritura (sección 4) — depende de cómo está construido hoy el sistema de tokens de agente de QBK, misma pregunta que quedó abierta en el punto 1, ahora con una restricción más concreta.
- Si conviene, más adelante, mostrarle a la persona un resumen algo más rico que "se propuso 1 hipótesis y 1 nota" (por ejemplo, mostrar el texto de la H propuesta sin mostrar el grafo completo) — quedó descartado para esta versión por mantener la superficie simple, pero vale la pena revisarlo con uso real.
- La Capa 2 (aporte que responde a una Q/SQ/H ya existente sin evidencia) — explícitamente fuera de alcance, anotada para una vuelta posterior.
