# Auth Multi-Usuario + Asociación de Tenant

Feature: Permitir que cada usuario se registre en Kuestion, asocie su tenant de Kuaforia, y que todas las operaciones se realicen en el contexto de ese usuario y tenant.

---

## Estado Actual

- **0 autenticación**: rutas web públicas, sin login/register/logout
- **Single-user**: `APP_USER_ID` UUID hardcodeado en `.env`, usado en **31 archivos** vía `config('app.user_id')`
- **Tenant hardcodeado**: `KUAFORIA_URL` contiene `ispend` fijo; `workspace_slug` hardcodeado `'admin-seguridad'`
- **User model existe pero muerto**: scaffold Laravel, tabla `users` vacía, nadie la usa
- **Jobs sin auth context**: `CheckQuestionUpdatesJob` corre en queue worker sin sesión

---

## Arquitectura Final (visión)

```
┌─────────────────────────────────────────────────────┐
│  Kuestion (Laravel 11)                               │
│                                                       │
│  ┌───────────┐  ┌───────────────┐  ┌────────────┐   │
│  │ Register  │  │    Login      │  │  Settings   │   │
│  │ /register │  │   /login      │  │  /settings  │   │
│  └─────┬─────┘  └──────┬────────┘  └─────┬──────┘   │
│        │               │                  │           │
│        ▼               ▼                  ▼           │
│  ┌─────────────────────────────────────────────┐     │
│  │            User (id, email, tenant_slug)      │     │
│  └─────────────────────────────────────────────┘     │
│        │                                               │
│        │ auth()->user()->tenant_slug                   │
│        ▼                                               │
│  ┌─────────────────────────────────────────────┐     │
│  │        KuaforiaService                       │     │
│  │  POST /api/consult/{tenant_slug}             │     │
│  └─────────────┬───────────────────────────────┘     │
│                │                                       │
└────────────────┼──────────────────────────────────────┘
                 │ HTTP con API key compartida
                 ▼
┌─────────────────────────────────────────────────────┐
│  Kuaforia (multi-tenant)                              │
│  Database-per-tenant, usuarios por tenant DB          │
│  ConsultController resuelve tenant por slug           │
└─────────────────────────────────────────────────────┘
```

---

## MVP: Fase Única (Ship primero, lo demás después)

El feature se entrega en **una sola fase MVP** que cubre registro, login, tenant dinámico y refactor del single-user. Todo lo demás es backlog.

| # | User Story | Descripción | Criterios de Aceptación |
|---|-----------|-------------|------------------------|
| M1 | Migration `tenant_slug` | Agregar columna a users table | `tenant_slug string nullable index` en `users` |
| M2 | Model User activo | Fillable, casts, relación questions() | `User::create()` funciona, `$user->questions()` retorna collection |
| M3 | Registro con selector de tenant | Formulario con nombre, email, password + selector de tenant (dropdown si hay pocos, input con placeholder si muchos) | Crea usuario, login automático, redirect a onboarding |
| M4 | Login | Formulario email + password | Autentica, regenera sesión, redirect a intended |
| M5 | Logout | Cerrar sesión | Invalida sesión + token, redirect a / |
| M6 | Proteger rutas web | Middleware `auth` en todas las rutas existentes | GET /questions redirige a /login si no auth |
| M7 | Landing page adaptada | Welcome detecta sesión | Logueado → redirect a questions; no logueado → landing + CTAs |
| M8 | Header con sesión | Mostrar avatar/initial + nombre + logout | Header cambia según estado de sesión |
| M9 | Onboarding post-registro | Pantalla de bienvenida post-registro con CTA a primera consulta | Usuario ve "Cuenta creada. Ahora puedes hacer consultas." + botón a /questions |
| M10 | Helper `current_user_id()` | Helper con `auth()->id()` y fallback a config | No rompe código durante migración |
| M11 | Reemplazar single-user en 31 archivos | 7 Livewire + QuestionController + jobs usan `current_user_id()` | Todas las queries scoped por usuario autenticado |
| M12 | Seeder usuario admin + migración datos | Crear usuario con UUID del APP_USER_ID actual, reasignar questions | Questions existentes asociadas al admin |
| M13 | Eliminar `APP_USER_ID` de config | App funciona sin esa variable en .env | `config('app.user_id')` ya no se usa |
| M14 | KuaforiaService con tenant dinámico | `consult()` usa `auth()->user()->tenant_slug`, URL se construye dinámicamente | Consultas apuntan al tenant del usuario logueado |
| M15 | Fix jobs sin auth context | `CheckQuestionUpdatesJob` resuelve tenant desde `$question->user->tenant_slug` | Jobs funcionan sin sesión |

**Criterio de salida del MVP:** Un usuario se registra, elige su tenant, loguea, hace una consulta, y la consulta llega al tenant correcto de Kuaforia. Las questions existentes del admin siguen funcionando.

---

## Post-MVP (Backlog ordenado)

Implementar solo si hay demanda. No construyas nada de esto hasta que el MVP esté en producción y midas necesidad real.

### Backlog Priorizado

| # | Feature | Cuándo hacerlo | Notas |
|---|---------|---------------|-------|
| B1 | Settings básico (cambiar nombre, email, contraseña) | Cuando un usuario lo pida | Ruta `/settings`, formularios simples |
| B2 | Password reset ("Olvidé mi contraseña") | Cuando haya mail configurado | Tabla `password_reset_tokens` ya existe |
| B3 | Remember me en login | Cuando un usuario lo pida | Checkbox, Laravel lo soporta nativo |
| B4 | Email verification | Cuando el registro sea abierto | Previene cuentas spam |
| B5 | Multi-usuario (aislamiento de datos, compartir preguntas) | Cuando haya 2+ usuarios reales en el mismo tenant | Sin demanda hoy — YAGNI |

### No Hará (Eliminado del plan original)

| Feature | Razón |
|---------|-------|
| Editar `tenant_slug` desde settings | El usuario no cambia de tenant. Si necesita, es caso de soporte |
| "Probar conexión" contra Kuaforia | El error natural de Kuaforia es suficiente. No construir doble validación |
| Fase Multi-usuario completa | Sin demanda — no hay 2+ usuarios reales |
| SSO / OAuth | Futuro lejano, sin señales de demanda |

---

## UX Design

### Principios
- **Un solo formulario**, sin wizard ni pasos
- **No pedir el slug técnico** — dropdown si hay pocos tenants, o resolver por dominio de email
- **Validación en registro**: si el tenant no existe en Kuaforia, rechazar con mensaje claro
- **Onboarding post-registro**: guía al usuario a su primera consulta

### Flujo completo

```
Landing  ──click "Comenzar"──▶  Register  ──submit──▶  Validar datos
  │                                                         │
  │                                                    ✅ Válido
  │                                                         │
  │                                              Login automático
  │                                                         │
  │                                              Onboarding screen
  │                                          "Tu cuenta está lista"
  │                                               [Hacer mi primera consulta →]
  │                                                         │
  │                                              Redirect a /questions
```

### Registro (con selector de tenant)

Para el MVP, si hay pocos tenants (<10), usar **dropdown**. Si es uno solo, no mostrar el campo y asignar automáticamente.

```
┌──────────────────────────────────────┐
│  Crea tu cuenta en Kuestion           │
│                                       │
│  Nombre completo     [______________] │
│  Email               [______________] │
│  Contraseña          [______________] │
│                                       │
│  Organización        [ispend       ▼] │
│                       ──────────────  │
│                       ○ ispend        │
│                       ○ acme-corp     │
│                       ○ otromart      │
│                                       │
│  [    Crear cuenta    ]               │
│                                       │
│  ¿Ya tienes cuenta? Inicia sesión →  │
└──────────────────────────────────────┘
```

Si los tenants son muchos o dinámicos (vía API de Kuaforia), usar **input con autocompletado** o pedir el email con dominio corporativo para resolución automática.

### Login

```
┌──────────────────────────────────────┐
│  Inicia sesión                        │
│                                       │
│  Email               [______________] │
│  Contraseña          [______________] │
│                                       │
│  [  Iniciar sesión  ]                │
│                                       │
│  ¿No tienes cuenta? Regístrate →     │
└──────────────────────────────────────┘
```

### Onboarding post-registro

```
┌──────────────────────────────────────┐
│  ✅ Cuenta creada con éxito           │
│                                       │
│  Tu organización: ispend              │
│                                       │
│  Ahora puedes hacer consultas         │
│  sobre tu base de conocimiento.       │
│                                       │
│  [  Hacer mi primera consulta  ]     │
│                                       │
│  O explora preguntas existentes →    │
└──────────────────────────────────────┘
```

---

## Decisiones Técnicas

### Crear (no instalar)
- Auth manual con controladores Livewire o controllers vanilla (no Breeze: ~40 archivos innecesarios)
- No Jetstream (equipo, API, 2FA — no se necesita)
- No Spatie Permission (roles no existen aún)

### Reciclar
- `User` model ya existe — solo modificar
- `users` table ya existe — solo agregar `tenant_slug`
- `password_reset_tokens` existe — para cuando toque password reset
- Componentes Blade `<x-input>`, `<x-button>`, layout `app.blade.php` — ya existen

### Mantener
- Sesión Redis (ya configurada)
- Livewire 3 (compatible con auth nativo)
- API key compartida para Kuestion→Kuaforia

---

## Riesgos

| # | Riesgo | Impacto | Mitigación |
|---|--------|---------|-----------|
| R1 | Jobs sin auth context (`CheckQuestionUpdatesJob`) | 🔴 Crítico | Resolver `user_id` y `tenant_slug` desde `$question->user`, no desde `auth()` |
| R2 | Datos existentes con UUID hardcodeado | 🔴 Crítico | Seeder crea usuario admin con ese UUID, migración reasigna questions |
| R3 | Tenant slug inválido en Kuaforia | 🟡 Medio | Rechazar registro si tenant no existe (validación server-side) |
| R4 | Rate limiting en login | 🟡 Medio | Middleware `throttle:5,1` en POST login |
| R5 | API routes con doble auth | 🟡 Medio | API key se mantiene, pero `user_id` ahora es el ID real |

---

## Archivos del MVP

### Crear
- `database/migrations/XXXX_add_tenant_slug_to_users_table.php`
- `app/Http/Controllers/Auth/RegisterController.php` ~30 líneas
- `app/Http/Controllers/Auth/LoginController.php` ~20 líneas
- `app/Http/Controllers/Auth/LogoutController.php` ~10 líneas
- `resources/views/auth/register.blade.php` ~40 líneas
- `resources/views/auth/login.blade.php` ~30 líneas
- `resources/views/auth/onboarding.blade.php` ~20 líneas
- `database/seeders/AdminUserSeeder.php`

### Modificar
- `app/Models/User.php` — fillable + casts + tenant_slug
- `routes/web.php` — rutas auth + middleware
- `resources/views/welcome.blade.php` — adaptar CTA
- `resources/views/layouts/app.blade.php` — header con usuario
- `app/Services/KuaforiaService.php` — tenant dinámico
- 31 archivos con `config('app.user_id')` → `current_user_id()`
- `app/Console/Commands/CheckQuestionUpdatesJob.php` — resolver desde `$question->user`

---

## Métricas Post-MVP

| Métrica | Por qué | Target |
|---------|---------|--------|
| Tasa de registro completado | ¿Usuarios completan el registro? | >80% |
| Tiempo entre registro y primera consulta | ¿El onboarding es efectivo? | <5 min |
| Tasa de consultas por usuario semanal | ¿El usuario vuelve? | Crecimiento |
| Errores de tenant inválido en registro | ¿El selector de tenant funciona? | <5% |
| Churn post-registro a 7 días | ¿El usuario vuelve? | >50% |
