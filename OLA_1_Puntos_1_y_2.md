# Motor de Consulta QBK y Conector Kuestion↔QBK

*Especificación de trabajo — Ola 1, punto 1*
*Agosto 2026 · Documento de entrada para los equipos de QuBeKa y Kuestion*

---

## 0. Qué resuelve este documento

Hoy Kuestion solo sabe "consultar" una fuente que tiene un motor conversacional completo (Kuaforia: búsqueda híbrida + IA generativa + citas). Para que Kuestion pueda usar QBK como fuente de conocimiento, QBK necesita un punto de entrada equivalente — aunque sea más simple — y Kuestion necesita un conector nuevo que hable con ese punto de entrada. Este documento define ambos lados y deja explícito qué decisiones quedan abiertas para cada equipo.

No cubre: estructuración automática de contenido nuevo (Ola 1, punto 3), ni la experiencia de revisión humana (punto 4). Este documento es exclusivamente sobre **cómo se pregunta y cómo se responde**.

---

## 1. Alcance funcional del Motor de Consulta QBK

### 1.1 Qué debe hacer

Dado un texto en lenguaje natural (una pregunta), el motor debe:

1. Buscar en el grafo activo (`scopeGrafoActivo` — solo N-K validadas, igual que ya filtra la API actual para agentes de IA) los nodos relevantes a esa pregunta.
2. Devolver una respuesta que combine esos nodos en algo legible, no una lista cruda de resultados de búsqueda.
3. Citar de qué nodos sale cada afirmación de la respuesta, con su `id` y su estado de validación.
4. Indicar si no encontró nada relevante, sin inventar una respuesta.

Esto es, en espíritu, lo mismo que hace `run_query` en Kuaforia — no es necesario que sea igual de sofisticado, pero la **forma** de la respuesta (texto generado + fuentes citadas + nivel de confianza) debe ser compatible, porque Kuestion va a tratar a QBK como una fuente intercambiable con Kuaforia.

### 1.2 Qué NO debe hacer (en esta primera versión)

- No necesita búsqueda semántica por embeddings — QBK hoy no la tiene (`LIKE` textual), y agregarla es un proyecto aparte, ya en el roadmap de QBK como capacidad futura independiente de este trabajo.
- No necesita multi-turno/conversación con contexto — Kuestion hoy tampoco lo usa para la vigilancia automática (el job horario re-consulta la pregunta original cada vez, sin historial).
- No necesita exponerse todavía como tool MCP formal — puede ser un endpoint REST nuevo. La exposición MCP de QBK es una decisión de arquitectura separada, no bloqueante para este punto.

### 1.3 Cómo debe buscar (primera versión aceptable)

Dado que QBK no tiene búsqueda semántica: búsqueda textual sobre `contenido` de los nodos N-K validados (reutilizando el mecanismo `LIKE` ya existente), con una extensión mínima: incluir también el contenido de la Q/SQ/H asociadas al N-K en el ranking, para que una pregunta que menciona el tema general (no el texto exacto de la nota) igual encuentre resultados relevantes. Esto es más simple que la búsqueda híbrida de Kuaforia, y se acepta como punto de partida — no bloquea el resto del trabajo mientras se declare explícitamente esta limitación en la respuesta cuando la confianza sea baja.

### 1.4 Cómo debe generar la respuesta

Acá hay una decisión de arquitectura real, no solo de implementación, y es la pieza central que falta definir con QuBeKa:

**Opción A — Motor de consulta usa el proveedor de IA embebido que ya se está construyendo para el flujo de ingestión.** QuBeKa ya tiene decidido embeber su propio proveedor de IA (dejando de depender siempre de un LLM externo) como parte del nuevo flujo de ingestión asistida. Si ese servicio de análisis interno queda con una interfaz limpia y extraíble desde el día uno (como ya está recomendado en su propio diseño), el motor de consulta podría reutilizar la misma conexión al proveedor de IA para generar la respuesta a partir de los nodos encontrados, en lugar de construir una integración nueva y separada.

**Opción B — Motor de consulta como pieza independiente, con su propia llamada a un proveedor de IA**, sin esperar a que el servicio de análisis de ingestión esté listo. Más rápido de construir en el corto plazo, pero duplica la integración con el proveedor de IA en dos lugares de QBK que después probablemente conviene unificar.

**Recomendación para decidir con el equipo de QuBeKa:** si el servicio de análisis de ingestión ya tiene, aunque sea en fase temprana, una interfaz callable (no solo diseñada en papel), conviene la Opción A — evita duplicar la integración de IA. Si ese servicio todavía está lejos de tener una interfaz usable, la Opción B desbloquea este punto sin esperar, aceptando que en algún momento se puede migrar a la A.

### 1.5 Forma de la respuesta esperada

Para que el conector de Kuestion (sección 2) pueda tratar a QBK igual que trata a Kuaforia, la respuesta del motor de consulta debería tener una forma compatible con lo que ya consume `KuaforiaService` hoy:

```json
{
  "answer": "texto de la respuesta generada, en español",
  "confidence": 0.0,
  "sources": [
    {
      "node_id": "NK-0234",
      "tipo": "N-K",
      "estado_validacion": "validada",
      "fecha_ultima_validacion": "2026-07-10T00:00:00Z"
    }
  ],
  "found": true
}
```

`found: false` cuando no hay resultados relevantes, con `answer` explicando la ausencia en vez de forzar una respuesta vacía o inventada.

---

## 2. Alcance funcional del conector Kuestion↔QBK

### 2.1 Qué debe construirse en Kuestion

Un nuevo `QbkService`, análogo a `KuaforiaService`, implementando las tres interfaces ya definidas en el sistema de conectores:

| Interfaz | Responsabilidad para QBK |
|---|---|
| `RagProviderInterface` | Llama al Motor de Consulta QBK (sección 1) con la pregunta, devuelve `KuaforiaResponse` (o el DTO equivalente ya en uso) con el `answer`/`sources`/`confidence` mapeados. |
| `IdentityResolverInterface` | Dada una credencial (token de agente de QBK), resuelve a qué workspace de QBK pertenece — equivalente a `get_client_context`, pero contra la API de QBK en vez de la de Kuaforia. |
| `StructuredSignalProviderInterface` | Opcional en esta primera versión. QBK hoy no tiene señales de vigencia calculadas (no existe aún `fecha_ultima_confirmacion` como campo separado — ver brecha ya identificada en el Contrato Mínimo, sección 3.2). Puede quedar sin implementar por ahora, devolviendo un array vacío, sin romper el contrato de la interfaz. |

### 2.2 Registro del conector

Agregar la entrada en `config/kuestion.connectors.php`, siguiendo el mismo patrón ya usado para Kuaforia:

```php
'qbk' => [
    'display_name' => 'QuBeKa',
    'description' => 'Grafo epistémico propio — preguntas, hipótesis y evidencia validada.',
    'auth_fields' => [
        ['key' => 'api_token', 'label' => 'Token de agente de QuBeKa', 'type' => 'password', 'required' => true],
    ],
    'identity_resolver' => \App\Connectors\Qbk\IdentityResolver::class,
    'rag_provider' => \App\Connectors\Qbk\QbkService::class,
    'signal_provider' => null, // pendiente
]
```

Esto activa automáticamente el flujo de conexión ya diseñado para el sistema de repositorios: pantalla de conexión, validación de credencial en tiempo real, autogeneración de nombre, sin trabajo de UI adicional — ese mecanismo ya es agnóstico del tipo de conector.

### 2.3 Autenticación

QBK ya tiene tokens de agente (`agente_tokens`, atados a usuario + workspace, vía Sanctum). Kuestion almacenaría ese token en `repositories.credential` igual que hace hoy con la `kfr_` de Kuaforia. No requiere que QBK construya un mecanismo de autenticación nuevo — reutiliza lo que ya existe para agentes externos.

**Punto a confirmar con QuBeKa:** si el token de agente actual permite consultas de "solo lectura sobre el grafo activo" sin además dar permiso de escritura — porque el conector de Kuestion, en este punto 1, solo necesita leer, no crear nodos. Si el token de agente hoy es todo-o-nada, conviene evaluar un scope de token más acotado antes de emitir credenciales para este conector.

### 2.4 Qué pasa con la vigilancia (dependencia hacia el punto 5 de la Ola 1)

El `ChangeDetector` de Kuestion re-consulta la pregunta original cada hora y hashea la respuesta. Con este motor de consulta ya definido, ese mecanismo funciona sin cambios — el job simplemente llama al `QbkService::consult()` en vez de a `KuaforiaService::consult()`, y hashea el `answer` recibido, igual que hace hoy. No hace falta ningún cambio adicional al `ChangeDetector` para que esto funcione — la dependencia que habíamos marcado antes queda resuelta en cuanto este punto esté implementado.

---

## 3. Operacional

| Aspecto | Definición |
|---|---|
| **Timeout** | Mismo criterio que Kuaforia (120s) hasta tener datos reales de latencia del motor de QBK — probablemente menor, dado que no hace búsqueda híbrida pesada. |
| **Circuit breaker** | Reutilizar el mecanismo ya existente en Kuestion (3 fallos → pausa 60s) — es genérico, no específico de Kuaforia. |
| **Rate limiting** | A definir junto con QuBeKa según el volumen esperado de consultas del job horario (una consulta por pregunta vigilada activa, cada hora). |
| **Versionado del endpoint** | Bajo `/api/v1/` de QBK, consistente con el resto de su API REST ya versionada. |
| **Errores 401 vs 5xx/timeout** | Mismo tratamiento ya diseñado para el conector: 401 marca el repositorio como `invalid`; 5xx/timeout lo deja `active` y reintenta. No requiere trabajo nuevo, ya está especificado a nivel de Kuestion. |

---

## 4. Qué se le pide a cada equipo

### A QuBeKa

1. Construir el endpoint de Motor de Consulta (sección 1): recibe pregunta en texto libre, busca en nodos N-K validados (+ contexto de Q/SQ/H asociadas), genera una respuesta con fuentes citadas, en el formato de la sección 1.5.
2. Decidir entre Opción A u Opción B (sección 1.4) para la generación de la respuesta, según el estado real del servicio de análisis de ingestión.
3. Confirmar si el token de agente actual permite emitirse con scope de solo lectura, o si hace falta un scope nuevo para este conector (sección 2.3).
4. Declarar explícitamente el nivel de confianza esperable de este primer motor (dado que la búsqueda sigue siendo textual, no semántica) para que Kuestion pueda mostrarlo con honestidad en la respuesta al usuario.

### A Kuestion

1. Construir `QbkService` implementando `RagProviderInterface` e `IdentityResolverInterface` (sección 2.1).
2. Registrar el conector `qbk` en `config/kuestion.connectors.php` (sección 2.2).
3. Confirmar que el `ChangeDetector` no requiere cambios más allá de apuntar al nuevo servicio (sección 2.4) — validar con una prueba real, no solo por inspección de código.
4. Definir en la UI cómo se comunica al usuario que la fuente conectada es QBK y no Kuaforia cuando la confianza de la respuesta sea baja (dado que la búsqueda textual de QBK es menos precisa que la híbrida de Kuaforia) — para no generar una expectativa de calidad pareja entre ambas fuentes desde el día uno.

---

## 5. Decisiones abiertas (no resueltas en este documento)

- Opción A vs. B de la sección 1.4 — depende del estado real del servicio de análisis de ingestión de QuBeKa, información que no tengo verificada al momento de escribir esto.
- Scope de token acotado (sección 2.3) — depende de cómo está construido hoy el sistema de tokens de agente de QBK.
- Si el motor de consulta se expone eventualmente como tool MCP (para que otros agentes lo usen, no solo Kuestion) — explícitamente fuera de alcance de este punto, pero vale la pena que quede anotado como posible economía futura si se construye pensando en esa extensión desde el diseño del endpoint.
