# Fase 0.5 — Aislamiento team-scoped: auditoría + test (T0.5.4)

**Fecha:** 2026-09-16 · **Rama:** `fix/gate-boot-selfhost` · **Test:** `tests/Feature/TenantIsolationTest.php`

## Hallazgo central

**Liberu es team-scoped en UNA sola base de datos, y el aislamiento es OPT-IN.**

No hay DB-per-tenant, y — lo importante — **no hay red de seguridad automática**: el trait `App\Traits\IsTenantModel` (usado por 56 modelos) tiene un nombre engañoso: **solo define la relación `team()`**, no registra ningún global scope. Verificado en código:

```php
trait IsTenantModel {
    public function team(): BelongsTo { return $this->belongsTo(Team::class); }
}
```

→ Un `Account::query()->get()` **sin filtro devuelve los datos de TODOS los teams**. Lo confirmó el test: 2 teams × 18 cuentas = **36 filas visibles sin scoping**.

## Cómo se aplica el aislamiento (cuando funciona)

- **Resolución del team actual:** `Liberu\Foundation\Organizations\Services\CurrentTeamResolver::resolve($actor, $requested)` valida membresía activa en `team_user` **o** propiedad (`teams.user_id`).
- **Llenado al crear:** hooks `creating` (ej. `Account`) estampan `team_id` desde `auth()->user()->currentTeam`.
- **Filtrado en lectura:** depende de que cada `*Query.php` de módulo, cada Filament Resource y cada Policy agreguen `where('team_id', …)` **a mano**.

### Magnitud (medida)
| Métrica | Valor |
|---------|-------|
| Modelos con `team_id` | **171** |
| Archivos que filtran por `team_id`/`current_team_id` | **316** |
| Modelos que usan `IsTenantModel` (solo relación, sin scope) | **56** |
| Policies con `belongsToCurrentTeam` | **1** |

## Resultado del test (`../vendor/bin/phpunit --filter TenantIsolationTest`)

```
OK (3 tests, 8 assertions)   Time: 00:36.818
```

1. `test_provisioning_scopes_accounts_to_their_own_team` — `TenantProvisioningService` etiqueta las 18 cuentas de cada team correctamente.
2. `test_scoped_query_never_returns_another_teams_rows` — la lectura scoped por `team_id` no filtra filas del otro team.
3. `test_isolation_is_opt_in_because_there_is_no_global_scope` — **fija el contrato**: una lectura sin scoping ve ambos teams (36 filas) → la isolation es responsabilidad de cada query.

## Riesgo (negocio)

Para el **tier base compartido**, cualquier query/Filament Resource/policy que **olvide** el `where('team_id', …)` produce una **fuga lógica entre clientes** — silenciosa, no un error. Con 466 módulos y 171 modelos con `team_id`, la superficie a auditar es grande. Es exactamente el riesgo que motivó elegir **PHP-FPM** (sin fuga de estado *de request*), pero el filtrado de *datos* sigue siendo responsabilidad del código.

## Recomendaciones

1. **Guardrail global (recomendado):** un global scope opt-in por modelo sensible (trait `BelongsToCurrentTeam`) que aplique `where team_id` cuando hay team actual, con escape explícito para contextos sin sesión (jobs/console). Elimina la clase entera de fuga.
2. **Test de aislamiento por módulo crítico** (facturación, cuentas, clientes) en CI.
3. **Auditoría priorizada:** rankear los 316 archivos que filtran y buscar los modelos con `team_id` **sin** `*Query` que los aísle.

## Anexo — DB-per-tenant (Fase 4, upsell, NO en este PR)
Requiere build custom: conexión `tenant` conmutada por request + provisioning/migración de la DB del tenant premium. **Solo con runtime FPM** (en FPM la conmutación es *per-request* → sin fuga entre clientes).