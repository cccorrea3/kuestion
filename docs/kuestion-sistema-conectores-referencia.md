# Kuestion — Sistema de Conectores RAG: Documento de Referencia

**Versión:** 1.1 (correcciones de consistencia aplicadas) | **Fecha:** 2026-08-15
**Propósito:** Definir el modelo genérico de conectores para Kuestion, la ficha del conector Kuaforia, el modelo de datos de repositorios, el flujo funcional del usuario y las decisiones de UX asociadas.

**Estado:** Diseño validado y cerrado. Pendiente de coordinación con Kuaforia para preguntas abiertas (sección 8).

---

## 0. Registro de cambios respecto a la versión 1.0

La versión 1.0 tenía una contradicción interna real, detectada en revisión cruzada antes de enviar este documento a Ingeniería:

1. **Contradicción sobre el campo "Nombre" del repositorio.** Las secciones 1.4, 3.4 y el flujo 5.1 establecían que la primera conexión no pide nombre (se autogenera, solo aparece en UI con más de un repositorio) — pero la sección 6.3 y la fila 9 de la tabla de decisiones cerradas (§9) afirmaban lo contrario, presentado como si ya estuviera validado. No fue una decisión tomada en ningún momento del proceso de diseño: se revierte a la versión consistente con el resto del documento ("diseñar para N, construir para 1"). Ver sección 6.3 corregida.
2. **Corrección de fase:** la sección 4.1 y la tabla 4.6 ubicaban `RagProviderInterface` (Bloque 7) en la "Fase 1". Bloque 7 pertenece a la **Fase 2** (Arquitectura interna) — la Fase 1 termina en el Bloque 6.
3. **Referencia cruzada agregada** entre las secciones 5.5 y 6.9, que repetían el mismo contenido, para evitar mantenerlo duplicado en dos lugares.

Ningún otro contenido de la versión 1.0 cambió.

---

## 1. Modelo genérico de conectores

### 1.1 ¿Qué es un conector?

Un conector es la representación de una fuente de conocimiento RAG (Kuaforia, QuBeKa, etc.) que Kuestion puede utilizar para:

- **Consultar:** enviar una pregunta y recibir una respuesta (texto, confianza, fuentes).
- **Resolver identidad:** determinar a qué organización/tenant pertenece una credencial.
- **Obtener señales:** conocer el estado de vigencia de una respuesta (stale, confidence, etc.).

### 1.2 Componentes de un conector

| Componente | Responsabilidad |
|---|---|
| **Declaración** | Nombre, descripción, campos de autenticación requeridos. |
| **Resolución de identidad** | Dada una credencial, devuelve el tenant/workspace asociado. |
| **Consulta RAG** | Envía una pregunta y devuelve una respuesta. |
| **Señales estructuradas** | (Opcional) Devuelve información de vigencia (stale, confidence, dependencias). |

### 1.3 Registro de conectores

Los conectores se declaran en configuración (`config/kuestion.connectors.php`), no en base de datos. Agregar un conector es un evento de despliegue, no de administración en runtime.

**Forma de cada entrada:**

```php
'kuaforia' => [
    'display_name' => 'Kuaforia',
    'description' => 'Casos técnicos con ciclo de vida y respuestas citadas.',
    'auth_fields' => [
        ['key' => 'api_key', 'label' => 'API key de Kuaforia', 'type' => 'password', 'hint' => 'kfr_...', 'required' => true],
    ],
    'identity_resolver' => \App\Connectors\Kuaforia\IdentityResolver::class,
    'rag_provider' => \App\Connectors\Kuaforia\KuaforiaService::class,
    'signal_provider' => \App\Connectors\Kuaforia\KuaforiaMcpProvider::class,
]
```

### 1.4 Principio de diseño: "diseñar para N, construir para 1"

- El modelo de datos soporta múltiples repositorios por usuario (diseñar para N).
- La UI de la primera conexión no pide nombre (construir para 1). El nombre se autogenera.
- El campo "Nombre" solo aparece en la UI cuando hay más de un repositorio.
- El selector de tipo de conector solo aparece cuando hay más de un tipo de conector configurado.

## 2. Ficha del conector Kuaforia

| Dimensión | Valor |
|---|---|
| **Nombre** | Kuaforia |
| **Descripción** | "Casos técnicos con ciclo de vida y respuestas citadas — pensado para resolver problemas concretos, no para conocimiento epistémico profundo." |
| **Campos de autenticación** | Uno solo: API key de Kuaforia (prefijo `kfr_...`), cifrada en reposo. |
| **Resolución de identidad** | `get_client_context` vía el puente MCP (`POST /api/v1/mcp`). URL fija a nivel de despliegue de Kuestion. **No hay `{slug}` en la URL.** La vía REST (`/api/v1/cli/health`) queda **descartada** para este flujo. |
| **Consulta RAG** | `POST /api/consult/{tenant_slug}`, síncrono, con hash+similitud del lado de Kuestion. **Pendiente:** confirmar si usa la `kfr_` del usuario o la key compartida. |
| **Señales estructuradas** | `get_workspace_health`, `get_dependency_health_report`, `get_case` vía el puente MCP. Usa la credencial del repositorio (no key compartida). |

### 2.1 Decisión clave: Resolución de identidad solo por MCP

- **Descartada:** la vía REST (`/api/v1/cli/health`) que requería subdominio por tenant.
- **Adoptada:** `get_client_context` a través del puente MCP, que resuelve tenant y nombre desde la key, sin necesidad de que el usuario ingrese URL o subdominio.
- **URL del MCP:** fija, configurada a nivel de despliegue de Kuestion (ej. `https://kuaforia.com/api/v1/mcp`). No varía por tenant.

### 2.2 Pendiente de confirmar con Kuaforia

1. **¿La misma `kfr_` del usuario sirve para autenticar `POST /api/consult/{tenant_slug}`?** Si es sí, eliminamos la key compartida global. Si es no, seguimos con la key compartida para las consultas (pero identidad y señales ya usan la `kfr_` del repositorio).

2. **¿`get_client_context` puede devolver `workspace_id` por defecto (extensión que Kuaforia ofreció)?** Si es sí, usamos `resolved_workspace_id`. Si es no, seguimos con el fallback `workspace_map` (ya implementado en Fase 2).

## 3. Modelo de datos

### 3.1 Tabla `repositories` (nueva)

| Campo | Tipo | Descripción |
|---|---|---|
| `id` | UUID (PK) | Identificador único del repositorio. |
| `user_id` | UUID (FK) | Usuario propietario. `onDelete('cascade')`. |
| `connector_type` | string(50), NOT NULL | Tipo de conector. Hoy solo `'kuaforia'`. Valor del registro de conectores, no enum fijo. |
| `name` | string(100), NULLABLE | Autogenerado tras la validación ("Kuaforia - Ispend"). Editable por el usuario. Solo visible en UI si hay más de un repositorio. |
| `credential` | encrypted:array, NOT NULL | JSON cifrado: `{"api_key": "kfr_..."}`. Soporta conectores futuros con múltiples campos. |
| `resolved_tenant_slug` | string(100), NULLABLE | Slug del tenant resuelto por `get_client_context`. |
| `resolved_tenant_name` | string(150), NULLABLE | Nombre del tenant (para mostrar al usuario). |
| `resolved_workspace_id` | string(100), NULLABLE | Workspace por defecto (si Kuaforia lo expone en el futuro). |
| `status` | enum: active \| invalid \| revoked, default 'active' | `active`: funcionando. `invalid`: la key dejó de funcionar (detectado por el job). `revoked`: el usuario desconectó manualmente. |
| `is_default` | boolean, default true | Relevante cuando hay más de un repositorio. El primero es el predeterminado por defecto. |
| `last_validated_at` | timestamp, NULLABLE | Última vez que la validación fue exitosa. |
| `last_used_at` | timestamp, NULLABLE | Última vez que se usó para una consulta. (Sugerido para observabilidad). |
| `created_at` / `updated_at` | timestamps | Auto. |

**Índices:**
- `(user_id, status)` — listar repositorios activos de un usuario.
- `(user_id, connector_type)` — filtrar por tipo.

### 3.2 Cambios en `questions`

| Campo | Acción |
|---|---|
| `repository_id` | **Agregar.** UUID (FK) → `repositories(id)`, NOT NULL. `onDelete('restrict')`. Una pregunta siempre pertenece a un repositorio específico. |

**Nota:** `questions` nunca tuvo `tenant_slug`. El tenant se resolvía desde `users`. Eso queda eliminado.

### 3.3 Eliminaciones en `users`

| Campo | Acción |
|---|---|
| `tenant_slug` | **Eliminar.** Se resuelve desde `repositories`. |
| `kuaforia_api_key` | **Eliminar.** Se almacena en `repositories.credential`. |

**Nota:** No se requiere migración de datos (ambiente de MVP aislado, sin usuarios reales).

### 3.4 Decisiones de diseño del modelo

| Decisión | Alternativa descartada | Razón |
|---|---|---|
| `credential` como `encrypted:array` | `encrypted:string` | Soporta conectores futuros con múltiples campos sin migrar la tabla. |
| `status: active\|invalid\|revoked` | `is_active: boolean` | Diferencia fallo de autenticación (key expirada) de desconexión manual. |
| Borrado físico restringido (`onDelete('restrict')`) | Borrado en cascada | Preserva historial de preguntas. Se usa `revoked` en su lugar. |
| `connector_type` como string (sin enum fijo en BD) | Enum nativo de MySQL | Agregar un conector no requiere migración. |
| `name` nullable | `name` NOT NULL | En la primera conexión no se pide nombre; se autogenera. |

## 4. Interfaces de código

### 4.1 RagProviderInterface (ya construida, Bloque 7)

Esta interfaz define el contrato mínimo para que Kuestion pueda consultar una fuente RAG.

```php
interface RagProviderInterface {
    public function consult(string $question, ?string $conversationId = null): KuaforiaResponse;
}
```

Responsabilidad: enviar una pregunta y recibir una respuesta estructurada (texto, confianza, fuentes, conversation_id).

Implementación actual: `KuaforiaService` (**Fase 2**, Bloque 7).

Uso en el sistema: `CreateQuestion::save`, `QuestionDetail::askFollowUp`, `CheckQuestionUpdatesJob`.

### 4.2 StructuredSignalProviderInterface (ya construida, Bloque 8)

Esta interfaz define el contrato para obtener señales de vigencia desde una fuente RAG.

```php
interface StructuredSignalProviderInterface {
    public function getWorkspaceHealth(string $workspaceId): array;
    public function getDependencyHealthReport(string $workspaceId): array;
    public function getCaseDetails(string $caseId): array;
}
```

Responsabilidad: devolver información estructurada sobre el estado de salud, dependencias y detalles de casos.

Implementación actual: `KuaforiaMcpProvider` (Fase 2, Bloque 8).

Uso en el sistema: `CheckQuestionUpdatesJob` (enriquecimiento de notificaciones con señales).

Nota: los métodos devuelven `array` en lugar de DTOs para resiliencia ante cambios en el catálogo de tools MCP.

### 4.3 IdentityResolverInterface (nueva)

Esta interfaz formaliza la resolución de identidad que hoy existe como código suelto en `KuaforiaService::resolveTenantFromApiKey()`.

```php
interface IdentityResolverInterface {
    public function resolveIdentity(array $credential): ResolvedIdentity;
}

class ResolvedIdentity {
    public string $tenant_slug;
    public string $tenant_name;
    public ?string $workspace_id;
    public array $raw; // respuesta cruda para debug
}
```

Responsabilidad: dada una credencial (ej. `api_key`), devuelve el tenant y workspace asociado.

Implementación concreta: `KuaforiaService::resolveTenantFromApiKey()` pasa a ser la implementación de esta interfaz, usando `get_client_context` vía el puente MCP.

Registro: se asocia al conector en `config/kuestion.connectors.php` mediante la clave `identity_resolver`.

Uso en el sistema: durante la validación de credenciales en el registro (`/register`) y en `/settings` (Bloque 6.6).

### 4.4 Relación entre interfaces y el conector

Cada conector declarado en `config/kuestion.connectors.php` debe especificar:

| Clave | Interfaz que implementa |
|---|---|
| `identity_resolver` | `IdentityResolverInterface` |
| `rag_provider` | `RagProviderInterface` |
| `signal_provider` | `StructuredSignalProviderInterface` (opcional) |

Esto permite que Kuestion, dado un `connector_type` y una credencial, pueda:

1. Resolver la identidad (`identity_resolver`).
2. Consultar el RAG (`rag_provider`).
3. Obtener señales estructuradas (`signal_provider`, si está disponible).

### 4.5 Decisión de diseño: no usar DTOs en StructuredSignalProviderInterface

Alternativa descartada: DTOs específicos para cada tipo de señal (ej. `WorkspaceHealth`, `DependencyReport`).

Razón: el catálogo de tools MCP de Kuaforia puede cambiar. Usar `array` permite ajustar el mapeo en configuración (`mcp_tools`) sin refactorizar el código. Si el catálogo se estabiliza en el futuro, se pueden extraer DTOs sin romper la interfaz.

### 4.6 Resumen de interfaces y su estado

| Interfaz | Estado | Implementación actual | Propósito |
|---|---|---|---|
| `RagProviderInterface` | ✅ Construida (Fase 2) | `KuaforiaService` | Consulta RAG |
| `StructuredSignalProviderInterface` | ✅ Construida (Fase 2) | `KuaforiaMcpProvider` | Señales de vigencia |
| `IdentityResolverInterface` | ❌ Nueva (por implementar) | `KuaforiaService::resolveTenantFromApiKey()` | Resolución de identidad |

## 5. Flujo funcional del usuario

### Principio rector: diseñar para N, construir para 1

- **El modelo de datos** soporta múltiples repositorios por usuario (diseñar para N).
- **La UI de la primera conexión** no pide nombre (construir para 1). El nombre se autogenera.
- **El campo "Nombre"** solo aparece en la UI cuando hay más de un repositorio.
- **El selector de tipo de conector** solo aparece cuando hay más de un tipo de conector configurado.

---

### 5.1 Primera conexión (sin repositorios previos)

**Contexto:** El usuario se acaba de registrar o accede a configuración sin repositorios configurados.

| Paso | Acción del usuario | Comportamiento del sistema |
|---|---|---|
| 1 | Accede a la pantalla de conexión. | Muestra: "Conectá una fuente de conocimiento." |
| 2 | Ve la lista de conectores disponibles. | Muestra los conectores declarados en `config/kuestion.connectors.php`. Hoy solo "Kuaforia" con su descripción. |
| 3 | Selecciona "Kuaforia". | Muestra el formulario de autenticación específico (campo API key). |
| 4 | Ingresa la API key (prefijo `kfr_...`). | Valida en tiempo real (debounce 700ms). |
| 5 | Espera la validación. | **Éxito:** muestra "✅ Conectado a Kuaforia - Ispend". **Fallo:** muestra "❌ No se pudo conectar. Revisá que la API key sea correcta." |
| 6 | Confirma la conexión (botón "Guardar"). | Cifra la key, crea el repositorio con nombre autogenerado "Kuaforia - Ispend", estado `active`, `is_default = true`. |
| 7 | Es redirigido al feed. | El repositorio está activo y listo para usar. |

---

### 5.2 Agregar un segundo repositorio

**Contexto:** El usuario ya tiene al menos un repositorio activo.

| Paso | Acción del usuario | Comportamiento del sistema |
|---|---|---|
| 1 | Va a `/settings`. | Muestra el repositorio existente (sin nombre si es el único). |
| 2 | Hace clic en "+ Agregar repositorio". | Muestra el selector de tipo de conector (solo si hay >1 tipo configurado). |
| 3 | Elige el tipo de conector. | Muestra el formulario de autenticación correspondiente. |
| 4 | Ingresa la credencial. | Valida en tiempo real (mismo mecanismo que la primera conexión). |
| 5 | Guarda. | Crea el nuevo repositorio con nombre autogenerado. `is_default` permanece en el primero. |
| 6 | Visualiza la lista en `/settings`. | Ahora muestra ambos repositorios con sus nombres (visibles y editables). Puede marcar cuál es el predeterminado. |

---

### 5.3 Gestión de repositorios desde `/settings`

**Contexto:** El usuario accede a `/settings` para administrar sus conexiones.

| Acción | Comportamiento del sistema |
|---|---|
| **Ver lista** | Muestra todos los repositorios del usuario con: nombre, tipo de conector, tenant resuelto, estado (badge), indicador de "predeterminado". Con un solo repositorio activo, se mantiene el formulario plano existente (sin lista, sin nombre) — ver 6.3. |
| **Editar nombre** | Solo disponible cuando hay más de un repositorio. Permite editar el nombre in-place o mediante modal. |
| **Editar credencial** | Muestra el formulario de autenticación del conector, con validación en tiempo real. |
| **Marcar como predeterminado** | Cambia `is_default = true` para ese repositorio; el anterior pierde la marca. |
| **Desconectar** | Solicita confirmación. Al confirmar, pasa a `revoked`. Las preguntas existentes siguen siendo visibles pero dejan de actualizarse. |
| **Agregar nuevo** | Inicia el flujo de "Agregar un segundo repositorio" (ver 5.2). |

---

### 5.4 Crear una pregunta (con repositorios activos)

**Contexto:** El usuario crea una nueva pregunta desde `/questions/create`.

| Escenario | Comportamiento del sistema |
|---|---|
| **1 repositorio activo** | No muestra selector. La pregunta se asocia automáticamente al único repositorio activo. |
| **2+ repositorios activos** | Muestra selector obligatorio con los repositorios `active`. El `is_default` está preseleccionado. Los repositorios `invalid` o `revoked` no aparecen. |
| **0 repositorios activos** | Muestra mensaje de bloqueo: "No hay conexiones activas a fuentes de conocimiento. Conectá un repositorio desde Configuración." con enlace a `/settings`. Es el mismo estado de fondo que gatilla el onboarding del Bloque 11 en el feed vacío — este mensaje cubre el caso de que el usuario llegue directo a `/questions/create` sin pasar por el feed. |

---

### 5.5 Estado de repositorio en preguntas existentes

**Contexto:** Una pregunta ya creada usa un repositorio que cambió de estado. El detalle completo de esta decisión, con su razón, está desarrollado en la sección 6.9 — acá el resumen operativo:

| Estado del repositorio | Comportamiento en feed y detalle |
|---|---|
| `active` | No se muestra nada (comportamiento normal). |
| `invalid` | Muestra badge "Conexión inactiva" con enlace a `/settings` para actualizar la key. |
| `revoked` | Muestra badge "Desconectado" (sin acción de reparación; se puede reconectar desde `/settings`). |

## 6. Decisiones de UX y comunicación con el usuario

*Esta sección define las decisiones de experiencia de usuario que deben implementarse junto con el modelo de repositorios. No son sugerencias ni preguntas abiertas: son parte del alcance funcional del sistema de conectores.*

---

### 6.1 Ayuda contextual para obtener credenciales

| **Escenario** | El usuario está en el formulario de conexión y ve un campo para ingresar una API key. No sabe cómo obtenerla. |
|---|---|
| **Fricción detectada** | El usuario puede abandonar el proceso por no saber qué poner en el campo. |
| **Decisión** | En el formulario de conexión debe existir un enlace o tooltip contextual que indique "¿Cómo obtengo mi API key?". |
| **Comportamiento esperado** | El enlace es específico al conector seleccionado (Kuaforia → documentación de Kuaforia). Se muestra siempre. Abre en nueva pestaña o modal con información breve. |

---

### 6.2 Confirmación visual del tenant resuelto

| **Escenario** | El usuario pega la API key. El sistema valida y muestra "✅ Conectado a Kuaforia - Ispend". |
|---|---|
| **Fricción detectada** | El usuario puede tener acceso a múltiples tenants y no saber si la conexión es al correcto. |
| **Decisión** | El sistema muestra el tenant resuelto con información adicional que permita confirmar que es el correcto. |
| **Comportamiento esperado** | Se muestra `tenant_name` y `tenant_slug` en la misma línea. Ej: "✅ Conectado a Ispend (ispend)". Si hay más información (entorno, workspace), se agrega. |

---

### 6.3 Nombre del repositorio: autogenerado en la primera conexión, no se pide

| **Escenario** | El usuario conecta su primer repositorio. El sistema resuelve `tenant_name = "Ispend"` y autogenera el nombre "Kuaforia - Ispend". |
|---|---|
| **Fricción evitada** | Pedir un nombre cuando no hay nada todavía que distinguir es fricción innecesaria — el usuario tiene que inventar un nombre para algo de lo que no existe una segunda instancia. |
| **Decisión** | En la primera conexión, el formulario **no** incluye un campo "Nombre". El nombre se autogenera y queda guardado, pero no se expone como campo editable hasta que exista un segundo repositorio. |
| **Comportamiento esperado** | Con un solo repositorio activo, `/settings` no muestra el campo "Nombre" — mismo criterio que el selector de tipo de conector (no se muestra si no hay una elección real que hacer). Al agregar un segundo repositorio, el nombre de ambos se vuelve visible y editable en la lista (ver 5.2, 5.3). |

*Nota de corrección (v1.1): una versión anterior de este documento proponía mostrar el campo "Nombre" desde la primera conexión, en contradicción con las secciones 1.4, 3.4 y 5.1 del mismo documento. Se revierte para mantener consistencia con el principio "diseñar para N, construir para 1", ya cerrado en rondas previas de este proceso de diseño.*

---

### 6.4 Indicador de estado del repositorio en el header

| **Escenario** | La API key del repositorio expiró. El job detecta el error y marca el repositorio como `invalid`. El usuario no se entera hasta que abre `/settings`. |
|---|---|
| **Fricción detectada** | El usuario pierde confianza al no saber que la conexión falló. |
| **Decisión** | Cuando un repositorio pasa a `invalid`, el sistema muestra un indicador visible en el header. |
| **Comportamiento esperado** | Un badge de advertencia junto al menú de usuario. Al hacer clic, redirige a `/settings` con el repositorio afectado resaltado. Permanece visible hasta que se resuelva. |

---

### 6.5 Bloqueo de creación de preguntas sin repositorios activos

| **Escenario** | El usuario no tiene repositorios activos (nunca configuró o todos están `invalid`/`revoked`). Intenta crear una pregunta. |
|---|---|
| **Fricción detectada** | El usuario queda atascado sin saber por qué no puede crear una pregunta. |
| **Decisión** | Si no hay repositorios activos, el formulario de creación muestra un mensaje de bloqueo con un enlace a `/settings`. |
| **Comportamiento esperado** | Mensaje: "No hay conexiones activas a fuentes de conocimiento. Conectá un repositorio desde Configuración." El enlace lleva a `/settings`. No se permite crear preguntas hasta que haya al menos un repositorio activo. Este es el mismo estado de fondo que activa el onboarding del Bloque 11 en el feed vacío (ver 5.4) — se mantiene un mensaje específico acá porque el contexto es distinto (una acción bloqueada, no una invitación a empezar). |

---

### 6.6 Selección de repositorio al crear una pregunta (con un solo repositorio)

| **Escenario** | El usuario tiene un solo repositorio activo. El formulario de creación muestra un selector con ese repositorio. |
|---|---|
| **Fricción detectada** | Mostrar un selector cuando solo hay una opción es ruido innecesario. |
| **Decisión** | Con un solo repositorio activo, el selector de repositorio no se muestra. Se usa implícitamente. |
| **Comportamiento esperado** | El usuario no ve ningún selector. La pregunta se asocia automáticamente al único repositorio activo. |

---

### 6.7 Selección de repositorio al crear una pregunta (con múltiples repositorios)

| **Escenario** | El usuario tiene dos o más repositorios activos. El formulario de creación muestra un selector. |
|---|---|
| **Fricción detectada** | El usuario podría querer usar siempre el mismo repositorio y tener que elegir cada vez es fricción. |
| **Decisión** | Con dos o más repositorios activos, el formulario muestra un selector obligatorio. El `is_default` está preseleccionado. |
| **Comportamiento esperado** | El selector lista repositorios `active`. El `is_default` está preseleccionado. El usuario puede cambiarlo. Los repositorios `invalid` o `revoked` no aparecen. |

---

### 6.8 Cambio del repositorio por defecto

| **Escenario** | El usuario tiene dos repositorios y quiere que el segundo sea el predeterminado. |
|---|---|
| **Fricción detectada** | El usuario no sabe cómo cambiar cuál se usa por defecto. |
| **Decisión** | El usuario puede cambiar el repositorio por defecto desde `/settings`. |
| **Comportamiento esperado** | En la lista de repositorios, cada fila tiene una acción "Marcar como predeterminado". Al hacer clic, el repositorio se marca como `is_default`. El repositorio anterior pierde la marca. La acción es inmediata. |

---

### 6.9 Estado del repositorio visible en preguntas existentes

| **Escenario** | Una pregunta ya creada usa un repositorio que pasó a `invalid` o `revoked`. El usuario abre el feed o el detalle. |
|---|---|
| **Fricción detectada** | El usuario no sabe que la pregunta ya no se actualiza. Podría confiar en información desactualizada. |
| **Decisión** | Las preguntas muestran el estado de su repositorio asociado en el feed y en el detalle. |
| **Comportamiento esperado** | Si el repositorio está `active`: no se muestra nada. Si está `invalid`: badge "Conexión inactiva" con enlace a `/settings` para actualizar. Si está `revoked`: badge "Desconectado", sin acción de reparación (se puede reconectar desde `/settings`). |

---

### 6.10 Confirmación al desconectar un repositorio

| **Escenario** | El usuario hace clic en "Desconectar" en `/settings` para un repositorio. |
|---|---|
| **Fricción detectada** | El usuario puede no entender la diferencia entre "desconectar" y "eliminar", o qué pasa con las preguntas existentes. |
| **Decisión** | Al desconectar, el sistema muestra un mensaje de confirmación claro sobre el impacto. |
| **Comportamiento esperado** | Mensaje: "¿Seguro que querés desconectar [nombre]? Las preguntas existentes seguirán siendo visibles, pero dejarán de actualizarse. Podés volver a conectarlo más tarde." Confirmación explícita. Al confirmar, el repositorio pasa a `revoked`. |

---

### 6.11 Distinción de errores en la UI: key inválida vs servicio no disponible

| **Escenario** | El job falla al consultar Kuaforia. Puede ser `401` (key inválida) o `503` (Kuaforia caído). |
|---|---|
| **Fricción detectada** | El usuario ve un error genérico y no sabe si es su problema o de Kuaforia. |
| **Decisión** | El sistema distingue en la UI si el error es de autenticación o de servicio. |
| **Comportamiento esperado** | **`401`:** el repositorio pasa a `invalid`. La UI muestra: "Tu API key de Kuaforia no es válida. Actualizala desde Configuración." **`503` o timeout:** el repositorio permanece `active`. La UI muestra: "Kuaforia no está disponible en este momento. El sistema seguirá intentando automáticamente." El job debe distinguir el código de error HTTP. |

---

### 6.12 Mensaje de bloqueo al crear pregunta con repositorios solo `invalid`

| **Escenario** | El usuario tiene repositorios, pero todos están en estado `invalid`. Intenta crear una pregunta. |
|---|---|
| **Fricción detectada** | El usuario no sabe que todas sus conexiones fallaron y por qué no puede crear una pregunta. |
| **Decisión** | Si todos los repositorios están `invalid`, el formulario de creación muestra un mensaje de bloqueo con un enlace a `/settings`. |
| **Comportamiento esperado** | Mensaje: "Tus conexiones a fuentes de conocimiento están inactivas. Actualizá tus API keys desde Configuración." No se permite crear preguntas hasta que al menos un repositorio esté `active`. |

---

### Nota final

Estas 12 decisiones de UX deben ser implementadas en conjunto con el modelo de repositorios. No son opcionales ni diferibles. Forman parte del contrato de usuario que estamos diseñando.

## 7. Impacto en bloques ya construidos

*Esta sección describe cómo el nuevo modelo de repositorios afecta a los bloques ya implementados en las Fases 1, 2 y 3. No son cambios opcionales: son consecuencia directa del nuevo diseño y deben ser ejecutados como parte de esta implementación.*

---

### 7.1 Bloque 6 (Fase 1) — Conexión de tenant "Conectate a Kuaforia"

**Estado actual:** El Bloque 6 implementa el flujo de validación de API key y guarda `tenant_slug` y `kuaforia_api_key` en la tabla `users`.

**Cambio requerido:** El flujo de validación y conexión es el mismo desde la perspectiva del usuario, pero internamente:

- En lugar de escribir en `users.tenant_slug` y `users.kuaforia_api_key`, crea un registro en la tabla `repositories`.
- El campo `credential` almacena la API key cifrada como `{"api_key": "kfr_..."}`.
- Los campos `resolved_tenant_slug` y `resolved_tenant_name` se completan con la respuesta de `get_client_context`.
- `status` se establece como `active`.
- `name` se autogenera como "Kuaforia - {tenant_name}" (no se pide al usuario en la primera conexión).
- `is_default` se establece como `true` (primer repositorio del usuario).

**Impacto en código:** Modificar `KuaforiaService::resolveTenantFromApiKey()` para que devuelva el array completo necesario para crear el repositorio. Ajustar el flujo de registro (`Register` Livewire) y `/settings` para usar `repositories` en lugar de columnas de `users`.

---

### 7.2 Bloque 8 (Fase 2) — Señales estructuradas vía MCP

**Estado actual:** `KuaforiaMcpProvider` usa la key compartida `mcp_api_key` de `config/services.php` para autenticar las llamadas MCP.

**Cambio requerido:** El proveedor deja de usar la key compartida global y utiliza la credencial del repositorio que originó la pregunta.

**Comportamiento esperado:**
- `CheckQuestionUpdatesJob` obtiene la pregunta y, de ella, el `repository_id`.
- Carga el repositorio y extrae `credential` (descifrada).
- Pasa la credencial al proveedor de señales estructuradas.
- Si la credencial es inválida, el proveedor falla y el job degrada con gracia (como ya está diseñado, con `try/catch` y `Log::warning`).

**Impacto en código:**
- Modificar `CheckQuestionUpdatesJob::collectSignals()` para recibir la credencial desde el repositorio.
- Modificar `KuaforiaMcpProvider` para aceptar la credencial como parámetro en lugar de usar la configuración global.

**Esto cierra el Hallazgo 2 (superficie de confianza de la key compartida).**

---

### 7.3 Bloque 12 (Fase 3) — Panorama de equipo (vista agregada de salud del tenant)

**Estado actual:** La vista de equipo agrupa por `tenant_slug` en la tabla `users`.

**Cambio requerido:** El agregado cambia su fuente de datos: de "mismo `tenant_slug` en `users`" a "mismo `resolved_tenant_slug` entre los `repositories` de ese tenant, cruzando usuarios".

**Comportamiento esperado:**
- Un usuario puede tener repositorios con distintos `tenant_slug`.
- La vista de equipo muestra métricas agregadas de todos los repositorios que comparten el mismo `resolved_tenant_slug`, sin importar a qué usuario pertenezcan.
- El acceso se controla por el flag `team_dashboard_access` (ya existente en Fase 3), que sigue viviendo en `users`.

**Impacto en código:**
- Modificar la consulta del componente `TeamDashboard` para usar `repositories.resolved_tenant_slug` en lugar de `users.tenant_slug`.
- Mantener el gate de acceso basado en `users.team_dashboard_access`.

---

### 7.4 Bloque 3 (Fase 1) — Manejo de errores en el job

**Estado actual:** `CheckQuestionUpdatesJob` maneja fallos de Kuaforia de forma genérica (log + reintento con backoff). No distingue entre errores de autenticación y errores de servicio.

**Cambio requerido:** El job debe distinguir errores de autenticación (`401`) de errores de servicio (`503`, timeout, etc.) para actualizar el estado del repositorio.

**Comportamiento esperado:**
- **Error `401` (key inválida/revocada):** el repositorio pasa a `invalid`. Se registra el evento y se notifica al usuario (ver sección 6.4 y 6.9).
- **Error `503`, timeout o similar:** el repositorio permanece `active`. Se registra el error y se reintenta según la política de backoff existente.
- La distinción debe hacerse en el momento de la consulta, antes de cualquier otra lógica.

**Impacto en código:**
- Modificar `CheckQuestionUpdatesJob` para capturar el código de error HTTP de la respuesta de Kuaforia.
- Si es `401`, actualizar el repositorio a `status = invalid` y notificar (o permitir que el indicador de header lo refleje).
- Si es otro error, mantener el comportamiento actual (reintentar con backoff).

---

### 7.5 Resumen de impacto por bloque

| Bloque | Cambio principal | Archivos afectados (referencia) |
|---|---|---|
| **Bloque 6 (Fase 1)** | Crear `repositories` en lugar de columnas de `users`. | `app/Livewire/Auth/Register.php`, `app/Livewire/Settings.php`, `app/Services/KuaforiaService.php` |
| **Bloque 8 (Fase 2)** | Usar credencial del repositorio en lugar de key compartida. | `app/Jobs/CheckQuestionUpdatesJob.php`, `app/Services/KuaforiaMcpProvider.php` |
| **Bloque 12 (Fase 3)** | Agrupar por `repositories.resolved_tenant_slug` en lugar de `users.tenant_slug`. | `app/Livewire/TeamDashboard.php` |
| **Bloque 3 (Fase 1)** | Distinguir `401` de otros errores para actualizar `status`. | `app/Jobs/CheckQuestionUpdatesJob.php` |

---

### 7.6 Nota sobre el alcance de los cambios

Los cambios descritos en esta sección afectan a código ya construido, probado y con tests pasando. No son "ajustes menores" — requieren modificar la lógica interna de bloques que ya estaban funcionales. El equipo de Ingeniería debe dimensionar este esfuerzo con el mismo peso que una nueva feature, no como un parche.

## 8. Preguntas abiertas para coordinar con Kuaforia

*Esta sección recoge las preguntas que requieren coordinación con el equipo de Ingeniería de Kuaforia para desbloquear funcionalidades del sistema de conectores. No son decisiones de diseño de Kuestion: son dependencias externas que deben ser resueltas para completar la implementación.*

---

### 8.1 Pregunta 1: Autenticación de consultas REST con la credencial del usuario

| **Contexto** | Hoy `KuaforiaService` usa una API key compartida (global) para autenticar `POST /api/consult/{tenant_slug}`. Con el nuevo modelo de repositorios, Kuestion almacena la `kfr_` del usuario en `repositories.credential`. |
|---|---|
| **Pregunta** | ¿La misma `kfr_` del usuario (ClientApiKey) sirve para autenticar `POST /api/consult/{tenant_slug}`? |
| **Impacto** | **Si es sí:** podemos eliminar la key compartida global y simplificar la seguridad. Todas las consultas usan la credencial del repositorio del usuario. **Si es no:** debemos mantener la key compartida para las consultas REST (pero identidad y señales ya usan la `kfr_` del repositorio). |
| **Estado** | Pendiente de confirmación con Ingeniería de Kuaforia. |

---

### 8.2 Pregunta 2: Extensión de `get_client_context` para devolver `workspace_id`

| **Contexto** | Hoy `get_client_context` devuelve `tenant.slug` y `tenant.name`. Para el Bloque 8 (señales estructuradas), Kuestion necesita un `workspace_id` para llamar a `get_workspace_health` y `get_dependency_health_report`. Actualmente usa `workspace_map` (fallback manual) para resolver esta relación. |
|---|---|
| **Pregunta** | ¿`get_client_context` puede devolver el `workspace_id` por defecto del tenant? Kuaforia ofreció agregar esta extensión como "una línea de meta" en sesiones anteriores. |
| **Impacto** | **Si es sí:** usamos `resolved_workspace_id` en el repositorio y eliminamos el fallback `workspace_map`. **Si es no:** seguimos con el fallback `workspace_map` (ya implementado en Fase 2, Bloque 8), pero requiere mantenimiento manual por parte del usuario o administrador. |
| **Estado** | Pendiente de confirmación con Ingeniería de Kuaforia. Ofrecida pero no implementada. |

---

### 8.3 Nota sobre la prioridad de estas preguntas

- **Pregunta 1** es deseable pero no bloqueante: si la respuesta es "no", el sistema sigue funcionando con la key compartida actual.
- **Pregunta 2** es bloqueante para eliminar el `workspace_map` manual, pero no para el funcionamiento básico del Bloque 8 (el fallback ya está construido y probado).

**Recomendación:** Priorizar la coordinación para la Pregunta 2 (extensión de `get_client_context`), ya que reduce complejidad operativa y mejora la experiencia del usuario al eliminar configuraciones manuales.

## 9. Resumen de decisiones cerradas

*Esta sección recopila todas las decisiones de diseño que han sido validadas y cerradas durante el proceso de definición del sistema de conectores. Cada decisión incluye la alternativa descartada y la razón que justifica la elección, para que el equipo de Ingeniería comprenda el contexto detrás de cada una.*

---

| # | Decisión adoptada | Alternativa descartada | Razón |
|---|---|---|---|
| 1 | `credential` como `encrypted:array` (JSON cifrado) | `encrypted:string` (texto plano) | Soporta conectores futuros con múltiples campos de autenticación sin necesidad de migrar la tabla nuevamente. |
| 2 | `status` con valores `active`, `invalid`, `revoked` | `is_active: boolean` | Diferencia entre fallo de autenticación (key expirada/revocada) y desconexión manual del usuario. La UI debe mostrar mensajes distintos en cada caso. |
| 3 | Borrado físico restringido (`onDelete('restrict')`) y uso de `revoked` para desconexión | Borrado en cascada (`onDelete('cascade')`) | Preserva el historial de preguntas existentes que usaban ese repositorio. Las preguntas siguen siendo legibles aunque el repositorio ya no esté activo. |
| 4 | `IdentityResolverInterface` formalizada como interfaz | Función suelta en `KuaforiaService` | Establece un contrato claro para futuros conectores (QuBeKa, etc.). Cada conector sabe cómo resolver su propia identidad. |
| 5 | `connector_type` como `string` (sin enum fijo en BD) | Enum nativo de MySQL | Agregar un nuevo conector no requiere migración de base de datos. Es un cambio de configuración + despliegue. |
| 6 | `url_override` eliminado del modelo | URL del MCP configurable por repositorio | La URL del puente MCP es fija a nivel de despliegue de Kuestion. No hay razón para que el usuario final la modifique por repositorio. |
| 7 | Sin migración de datos existentes | Migrar `users.tenant_slug` y `users.kuaforia_api_key` a `repositories` | El entorno es un MVP aislado sin usuarios reales. No hay datos que preservar, lo que simplifica el cambio. |
| 8 | Diseñar para N, construir para 1 | Diseñar y construir para N desde el inicio | El modelo soporta múltiples repositorios, pero la UI de la primera conexión es simple (sin nombre, sin selector de tipo). La complejidad aparece solo cuando el usuario agrega un segundo repositorio. |
| 9 | `name` autogenerado, no se pide en la primera conexión; visible y editable solo con más de un repositorio | Pedir el nombre desde el primer momento | Evita fricción cuando no hay nada todavía que distinguir. Consistente con la decisión 8 ("diseñar para N, construir para 1") — ver corrección aplicada en 6.3. |
| 10 | Validación de credenciales solo en el momento de conexión/edición | Validación periódica en cada consulta | La validación en cada consulta añade latencia innecesaria. El job ya maneja fallos de autenticación y actualiza el estado del repositorio cuando ocurren. |

---

### Nota sobre decisiones abiertas

Las siguientes decisiones **no** están cerradas en este documento porque dependen de coordinación externa con Kuaforia (ver sección 8):

1. **Uso de la `kfr_` del usuario para autenticar `POST /api/consult/{tenant_slug}`:** pendiente de confirmación.
2. **Extensión de `get_client_context` para devolver `workspace_id`:** pendiente de implementación por parte de Kuaforia.

Ambas decisiones no bloquean la implementación del sistema de conectores: el sistema funciona con las alternativas actuales (key compartida para consultas, fallback `workspace_map` para señales). Su resolución permitirá simplificar y mejorar la seguridad en versiones futuras.
