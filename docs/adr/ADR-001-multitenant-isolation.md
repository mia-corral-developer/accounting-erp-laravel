# ADR-001 — Multitenant Isolation (team-scoped, single database)

- **Estado:** Aceptado (2026-09-16)
- **Contexto:** SaaS de contabilidad/facturación para Colombia, motor Liben/Liberu ERP (MIT) + 466 módulos, runtime PHP-FPM.
- **Decisores:** Andrés Corral (Tech Lead) · diseño debatido con modelo externo (4 rondas).

## 1. Contexto y problema

Liberu es **team-scoped en una sola base de datos**. El tenant es el `Team` (Jetstream). Los registros
se separan por `team_id`, **pero el aislamiento es opt-in**: el trait legacy `App\Traits\IsTenantModel`
(56 modelos) **solo añade la relación `team()`** — no registra ningún global scope. Una query sin filtro
ve **todos** los teams.

Magnitud medida: **171 modelos con `team_id`**, **316 archivos** que filtran a mano, **solo 1 policy**
usa `belongsToCurrentTeam`. Cualquier query que olvide `where('team_id', …)` → **fuga silenciosa entre clientes**.
Mitigación insuficiente hoy: `IsTenantModel` → `BelongsToCurrentTeam` (rollout masivo, commit `0307b4b2`)
introdujo **5 regresiones** porque el `TeamScope` **fallaba en silencio** (`where 1=0`), convirtiendo
"no hay tenant definido" en "colección vacía".

## 2. Decisión (invariante congelada)

> **UNRESOLVED** es un estado transitorio permitido en el proceso, pero **ningún acceso a datos tenant-owned
> puede ejecutarse en ese estado**. **TENANT** es el estado normal. **CENTRAL** es una elevación explícita,
> excepcional y auditable.
>
> Ninguna operación tenant-owned puede ejecutarse con `UNRESOLVED`; ningún boundary puede seleccionar un
> tenant sin **autorizarlo**; ningún conflicto entre fuentes de tenant se resuelve **silenciosamente**; y
> `CENTRAL` debe ser **explícito**.

**La ausencia de contexto de tenant es un error de programación, no un resultado de consulta.**
Ante `UNRESOLVED` + acceso a datos tenant-owned → **excepción** (`MissingTenantContextException`), nunca
`where 1=0` ni acceso global.

## 3. Modelo de contexto

### 3.1 `TenancyContext` (a qué datos accedo)
```
UNRESOLVED | TENANT(id) | CENTRAL
```

### 3.2 `ExecutionPrincipal` (quién ejecuta) — dimensión separada
```
USER(userId) | SYSTEM(component) | COMMAND(name)
```
No se agrega `SYSTEM` al enum de tenancy: son dos dimensiones. Combinaciones válidas:
`SYSTEM(TenantProvisioningService) + TENANT(42)`, `SYSTEM(DatabaseSeeder) + CENTRAL`.

### 3.3 Lifetime
> `TeamContext` SHALL use Laravel **scoped** lifetime to prevent context persistence across request/job
> lifecycles. **Scoped lifetime alone SHALL NOT be considered a tenant isolation control.** Isolation begins
> only after an authorized boundary resolver has transitioned the context from `UNRESOLVED` to `TENANT` or
> explicitly to `CENTRAL`. **Consequently, enforcement (ENFORCE) SHALL NOT be enabled for any model before
> its boundary resolvers exist and are tested.**

**Nota operativa:** `scoped()` resuelve el *lifetime del objeto*; los resolvers resuelven la *inicialización
semántica del tenant*. Cambiar `singleton → scoped` no rompe nada siempre que `UNRESOLVED` conserve el
comportamiento de migración. Pero no cuenta como protección hasta que los boundaries inicialicen el contexto.
Se implementa en la **misma fase que los resolvers** (Fase 2).

## 4. Clasificación de modelos (declarativa, en código)

Todo modelo es **exactamente una** de:

| Categoría | Semántica | `team_id` | Scope | Uniques |
|---|---|---|---|---|
| **`TenantOwned`** | Cada fila pertenece a un team | `NOT NULL` | `where team_id = current` | compuestos `(team_id, …)` |
| **`Central`** | Registro de plataforma (`Team`, `plans`, settings globales) | idealmente ausente | sin scope | — |
| **`SharedOrTenant`** | Catálogo global + override por team | `NULL` válido | `team_id = current OR team_id IS NULL` | según dominio |

- **Declaración explícita** vía interfaces: `implements TenantOwnedModel | CentralModel | SharedTenantModel`.
- **Prohibido el tenant sentinela** (`team_id = 0` / `"GLOBAL"`): contamina FKs, billing, métricas y semántica.
- El **architecture test** falla si un modelo con `team_id` no declara una categoría reconocida.
- **La DB va después, como auditor** (declaración ↔ schema ↔ datos). Nunca al revés.

## 5. Fuentes de resolución — un resolver por boundary

Sin precedencia silenciosa entre fuentes. Interfaz:
```php
interface TeamResolver { public function resolve(): TeamResolution; }
```
| Boundary | Resolver |
|---|---|
| Web normal | `SessionTeamResolver` |
| Filament | `FilamentTeamResolver` |
| Queue | `JobPayloadTeamResolver` |
| Tenant Artisan command | `CommandArgumentTeamResolver` |
| Premium futuro | `DomainTeamResolver` |

- **Resolution ≠ Authorization.** `current_team_id` es preferencia/selección, **no** autorización. Siempre
  validar membership (`canAccessTenant` / `CurrentTeamResolver`) antes de `TENANT(id)`.
- Dos señales contradictorias (`session=10`, `route=20`) → **`TenantResolutionConflictException`**, nunca elegir.
- **IDOR test obligatorio:** `User A ∈ Team 10` → `GET /teams/20/…` → **403** y `TeamContext` **nunca** = `TENANT(20)`.

## 6. Tabla de decisiones

| Tema | Decisión |
|---|---|
| Route bindings | `ResolveCurrentTeam` **antes** de `SubstituteBindings` vía **middleware priority** (no `prependToGroup('web')`: correría antes de `StartSession`) |
| `Route::bind` | **No** para tenancy; `->scopeBindings()` como defensa adicional |
| Filament | **Mantener** tenancy nativa; `->tenantMiddleware([ResolveFilamentTeamContext::class], isPersistent: true)`. `TeamScope` **nunca** lee de Filament |
| Fuente de verdad | `TeamContext` en dominio; Filament = resolver de boundary |
| Doble scope temporal | Aceptable (`WHERE team_id=A AND team_id=A`); si Filament tenant ≠ TeamContext → `TenantContextConflictException` |
| `UNRESOLVED` + tenant data | **Excepción** (`MissingTenantContextException`) |
| Escrituras | **Write guard**: `TENANT(A)+team_id B → CrossTenantWriteException`; cambio de `team_id` en entidad existente prohibido salvo reassignment explícito |
| `SYSTEM` | **Principal/actor**, no 4.º estado de tenancy |
| Provisioning | Preferir `runTenant($newTeam->id, …)`, no `runCentral()` + `forceFill` masivo |
| `withoutTeamScope()` | Primitiva low-level para framework/migraciones; **no** API de aplicación |
| Escape sancionado | `TeamContext::runCentral(reason, callback)` **con logging** (actor/reason/timestamp/callsite) |
| Enforcement | `LEGACY | OBSERVE | ENFORCE` **por modelo/dominio** (no booleano global) |
| Nuevos modelos | **ENFORCE por defecto**; LEGACY/OBSERVE son deuda de migración |
| Spatie | **No migrar ahora.** Diseñar para que Spatie sustituya la *orchestration* (queue, finder, DB switching, cache) sin tocar el *domain enforcement* (`TeamContext` interface ← `SpatieTeamRuntimeAdapter`) |
| TeamContext lifetime | **`scoped`, no singleton** |
| Constraints SQL | `DISCOVER → REPORT → REPAIR → VERIFY → CONSTRAIN` (nunca al revés) |

## 7. Escrituras — invariantes

```php
static::creating(fn ($m) => /* CENTRAL: exige team_id explícito; TENANT: auto-asigna o CrossTenantWriteException */);
static::updating(fn ($m) => /* team_id no cambia silenciosamente */);
```
**READ isolation + WRITE ownership.** Para contabilidad, ambas.

## 8. Superficies que Eloquent NO protege (threat model — Fase 10)

`DB::table()`, `DB::select()`, raw SQL, cache, Redis keys, filesystem, exports, imports, search indexes,
webhooks, broadcasting, validaciones `exists`/`unique`, scheduled jobs. Requieren estrategia **documentada
y testeada** por superficie (ej. cache prefix tenant-aware, files bajo namespace de tenant, raw SQL con
predicado de team explícito).

## 9. Consecuencias

- **Positivas:** aislamiento como invariante del modelo (no esperanza por query); errores de contexto
  **visibles**; incrementos **verificables y bisecables**; base para el tier premium DB-per-tenant.
- **Costos:** resolver de tenant en cada boundary; `TeamContext` scoped; architecture test; telemetría;
  migración incremental por dominio.
- **Riesgo aceptado:** durante LEGACY/OBSERVE el aislamiento **no** está garantizado para un dominio aún
  no graduado. Se declara "protegido" **solo en ENFORCE**.

## 10. Disciplina de rollout

Cada commit de rollout responde **3 preguntas** (template de PR):
1. ¿Qué nueva superficie quedó protegida?
2. ¿Qué comportamiento anterior cambió?
3. ¿Qué test demuestra que no afectamos otra superficie?

Si no se responden las tres → el lote es **demasiado grande** (por eso `0307b4b2` falló metodológicamente).