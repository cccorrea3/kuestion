# MCP Server de Kuestion

El MCP Server propio de Kuestion (Bloque 9, Fase 2) expone tools de **solo lectura**
sobre las preguntas de un usuario autenticado. Se sirve por **stdio** (transporte
estándar de MCP) y se integra con clientes como Claude Code.

> **Principio del ecosistema (resolución de revisión):** el MCP de Kuestion **no**
> expone señales de Kuaforia. Un agente que quiera señales estructuradas le habla
> directo al MCP de Kuaforia ("un MCP, un agente, por plataforma").

## 1. Crear un token de agente

Los tokens se generan con el comando `agent-token:create`, apuntando al usuario por
**uuid** o **email**:

```bash
php artisan agent-token:create <uuid-o-email> "nombre-del-token"
# Ejemplo:
php artisan agent-token:create 0f8a2c1e-... "claude-code"
```

El comando imprime el token **una sola vez** (prefijo `kqt_`). Solo se guarda su
hash bcrypt: **no es recuperable**. Si se pierde, se crea otro.

- Los tokens son `scoped` por el `user_id` del usuario: cada tool solo ve las
  preguntas de ese usuario.
- `scopes` default: `["read"]` (solo lectura). Deja la puerta para scopes futuros.
- `expires_at` opcional: si se define, el token deja de ser válido al vencer.

## 2. Configurar Claude Code

En la configuración de Claude Code (`~/.claude/settings.json` o `.mcp.json` del
proyecto), agregar el server:

```json
{
  "mcpServers": {
    "kuestion": {
      "command": "php",
      "args": ["artisan", "mcp:serve", "--token=kqt_..."],
      "cwd": "/ruta/al/proyecto/kuestion"
    }
  }
}
```

Alternativa sin exponer el token en la config: usar la variable de entorno
`KUESTION_AGENT_TOKEN` y omitir `--token`:

```json
{
  "mcpServers": {
    "kuestion": {
      "command": "php",
      "args": ["artisan", "mcp:serve"],
      "env": { "KUESTION_AGENT_TOKEN": "kqt_..." },
      "cwd": "/ruta/al/proyecto/kuestion"
    }
  }
}
```

## 3. Tools disponibles

Todas devuelven JSON estructurado en `result.content[].text` y están scoped por el
usuario del token.

| Tool | Descripción | Argumentos |
|---|---|---|
| `list_questions` | Lista preguntas del usuario (máx. 50) | `status?` (`active`/`archived`), `tag?` (exacto), `search?` (texto) |
| `get_question_details` | Pregunta + versión actual (respuesta, confianza, fuentes) | `question_id` (requerido) |
| `list_unreviewed_changes` | Preguntas con cambios detectados sin revisar (versión actual incluida) | `limit?` (default 20, máx. 100) |

## 4. Prueba manual del protocolo

El server lee JSON-RPC 2.0 newline-delimited de stdin:

```bash
printf '%s\n' \
  '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2024-11-05"}}' \
  '{"jsonrpc":"2.0","id":2,"method":"tools/list"}' \
  '{"jsonrpc":"2.0","id":3,"method":"tools/call","params":{"name":"list_questions","arguments":{}}}' \
  | php artisan mcp:serve --token=kqt_...
```

Métodos soportados: `initialize`, `ping`, `tools/list`, `tools/call` y las
notificaciones `notifications/*` (sin respuesta).

## 5. Seguridad y notas de implementación

- **Autenticación:** el token se valida contra `agente_tokens` con `Hash::check`
  (una vez por sesión) y se re-valida existencia/expiración en cada mensaje.
  bcrypt no es buscable por hash: el comando itera los tokens vigentes — suficiente
  para una tabla de pocos agentes.
- **Sin SDK:** protocolo hand-rolled (consistente con la convención del proyecto).
  Si el protocolo crece, evaluar `php-mcp/server`.
- **Tokens inválidos/expirados:** respuesta JSON-RPC de error `-32001`.
- **Regeneración:** borrar el `AgentToken` de la tabla revoca el token de inmediato
  (la validación por mensaje lo detecta).
