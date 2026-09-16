# Plan de implementación — Tenancy Hardening (adjunto a ADR-001)

**Regla:** cada fase es **código + gate**. Si una fase no puede decir inequívocamente *"esto pasó / esto no pasó"*,
no es una fase segura. Cada lote responde las **3 preguntas** del ADR §10.

> **Correcciones integradas** (no estaban en el plan original):
> - **C1 — Baseline se re-graba DESPUÉS de arreglar Pest.** Los "235 tests / 2 failures" son solo el subconjunto
>   clase-PHPUnit (Pest roto). La **parity gate autoritativa** se graba en Fase 1 sobre la suite COMPLETA.
> - **C2 — Kill-switch de enforcement:** env `TENANCY_ENFORCEMENT=off|per_model|strict` para bajar un dominio
>   ENFORCE→OBSERVE en runtime sin redeploy (circuito de emergencia, no el mecanismo de rollout).
> - **C3 — Inventario cubre `app/Models` *y* `modules/*/src/Models`.** El architecture test no debe tener
>   puntos ciegos en los 466 módulos.

---

## Fase 0 — Recuperar baseline y bisectabilidad
**Código:** revert **funcional y documentado** de `0307b4b2` (con `git revert`, **no** reset; el commit se
conserva como referencia/inventario/fuente de cherry-pick). Estado equivalente a `ef5aa3b1`. Documentar los
2 fallos preexistentes.

**Gate:**
```
235 tests clase-PHPUnit ejecutables
Expected known failures = exactamente 2:
  - BankStatementReconciliationTest
  - SchemaConsistencyTest
New failures = 0
```
No se exige "suite verde"; se exige **baseline parity**.

## Fase 1 — Infraestructura de test (antes de tocar tenancy)
**Código:** arreglar **Pest**; Docker target/CI **`test`** (dev deps solo ahí); ejecutar PHPUnit + Pest de
forma reproducible. **No** copiar tests al contenedor productivo (eso es para investigar, no CI).

**Gate:**
```
100% de la suite descubrible ejecutable
Known failures documentados
Sin tests omitidos por error de infraestructura
SUITE COMPLETA: same commit → same suite → same result
BASELINE PARITY AUTORITATIVA grabada aquí (C1)
```

## Fase 2 — Runtime de tenancy (sin enforcement masivo)
**Código:** `TeamContext` (UNRESOLVED/TENANT/CENTRAL); **`singleton → scoped`**; resolvers (HTTP, Filament
adapter, Job middleware, Console helpers); `actingAsTenant()`, `runTenant()`, `runCentral()`. Resolver HTTP
con **middleware priority** (session/auth → ResolveCurrentTeam → SubstituteBindings). Autorización:
`candidate Team + membership → TENANT` (nunca `current_team_id → set()` directo).

**Gate (solo infraestructura):**
```
✓ request Team A → TENANT(A)
✓ usuario A → Team B → 403, TeamContext nunca = B
✓ request sin tenant → UNRESOLVED
✓ Filament tenant A → TeamContext A
✓ Filament tenant ≠ TeamContext → TenantContextConflictException
✓ Job A team=10 → contexto 10
✓ Job A termina → contexto no persiste
✓ Job A(10) throws; Job B(20) → B observa solo 20
✓ runTenant(10) ⊃ runTenant(20) → restaura 10
✓ runCentral() → restaura contexto anterior
✓ route model binding ocurre DESPUÉS de resolución
GLOBAL: suite completa → new regressions = 0
```
**Hasta que esto pase, no se activa `throw` en ningún modelo.**

## Fase 3 — Modelo de referencia: `Account`
**Código:** `Account` = `TenantOwned` + `TeamScope`, primero **OBSERVE** con telemetría
`TENANCY_UNRESOLVED_QUERY`; luego **ENFORCE** (solo Account). Con ENFORCE: TENANT→filtro; UNRESOLVED→
`MissingTenantContextException`; CENTRAL→unscoped.

**Gate:**
```
✓ Team A solo ve Account de A · Team B solo Account de B
✓ cross-team ID lookup → sin registro
✓ UNRESOLVED → excepción
✓ CENTRAL ve ambos (explícito)
✓ route binding de Account tenant-scoped
✓ domain tests de Account pasan · baseline regressions = 0
✓ Account ENFORCE + resto legacy FUNCIONA (si no, hay acoplamiento incorrecto)
```

## Fase 4 — Inventario y clasificación de los 171 modelos
**Código:** declarar cada modelo como `TenantOwned | Central | SharedOrTenant` (C3: **`app/Models` +
`modules/*/src/Models`**). Architecture test: tabla/modelo con `team_id` → clasificación obligatoria;
`TenantOwned` → debe usar el contrato de enforcement.

**Gate:**
```
171/171 clasificados · unknown/unclassified = 0
(No implica scoped; implica que conocemos su semántica.)
Aclara qué pasa con los ~115 modelos sin el trait legacy.
```

## Fase 5 — READ rollout por bounded context
**Código:** por dominio (5A accounting core → 5B inventory → 5C banking → 5D AR/AP → 5E projects →
5F integrations), cada uno `LEGACY → OBSERVE → ENFORCE`.

**Gate OBSERVE→ENFORCE (por dominio):**
```
✓ domain suite verde vs baseline · ✓ isolation tests · ✓ cross-tenant negative tests
✓ unresolved events = 0 en ventana relevante · ✓ rutas críticas ejecutadas
✓ bypasses identificados · ✓ raw-query usages del dominio identificados
Tráfico alto: 7 días · módulos raros: 14/30 días o pruebas sintéticas
```

## Fase 6 — Inventario de WRITES (aún sin bloquear)
**Código:** buscar y clasificar `forceFill(['team_id'])`, `create/update(['team_id'])`, `associate($team)`,
`insert/insertOrIgnore/upsert`, `DB::table(...)` → normal / provisioning / import / migration / integration /
central / bug.

**Gate:** `Known explicit team_id write sites = inventariados y clasificados · unknown write paths = 0` para el dominio a graduar.

## Fase 7 — Write Guard
**Código:** para `TenantOwned`: `TENANT(A)+NULL → auto A`; `TENANT(A)+A → OK`; `TENANT(A)+B →
CrossTenantWriteException`; `UPDATE A→B → reject`; `UNRESOLVED → exception`. Provisioning vía
`runTenant($newTeam->id, fn() => provision())`.

**Gate (por dominio):** create ownership · update ownership · reassignment rejected · provisioning ·
import · integration callback · rollback/exception restores context · baseline regressions = 0.

## Fase 8 — Escape hatches y CENTRAL
**Código:** `withoutTeamScope()` sigue interna; app prefiere `runCentral(reason, callback)`.

**Gate (automatizable):**
```
grep/static analysis: usos application-level de
  withoutTeamScope() · withoutGlobalScope(TeamScope::class) · withoutGlobalScopes()
= 0 fuera de allowlist (App\Platform\Tenancy, App\Platform\Maintenance)
```
(Allowlist vía PHPStan custom rule o Pest architecture test.)

## Fase 9 — Constraints de base de datos
**Código:** por tabla, `DISCOVER → REPORT → REPAIR → VERIFY → CONSTRAIN`.

**Gate:**
```
duplicate violations = 0 · unexpected NULL ownership = 0
orphan FKs = 0 · cross-team relationship violations = 0
```
(Pre-checks obligatorios: `GROUP BY team_id, … HAVING COUNT(*)>1`; `WHERE team_id IS NULL`.)

## Fase 10 — Superficies que Eloquent no protege
**Código/threat model:** `DB::table`, `DB::select`, raw SQL, Redis, cache keys, filesystem, exports,
imports, search indexes, webhooks, broadcasting, `exists/unique`, scheduled jobs.

**Gate:** cada acceso tenant-sensitive tiene **estrategia documentada y testeada** (no "0 raw SQL").
Ej.: cache prefix tenant-aware ✓ · file namespace ✓ · export tenant context test ✓ · raw SQL team predicate ✓.

---
### Kill-switch (C2)
`TENANCY_ENFORCEMENT=off|per_model|strict` — bajar un dominio ENFORCE→OBSERVE en runtime sin redeploy.
Complementa el modo por-modelo de `config/tenancy.php` (`enforcement` map), no lo reemplaza.

### Telemetría
Métrica `tenancy_unresolved_total{model, boundary, callsite_hash}` + log detallado solo en **primera
ocurrencia por fingerprint** + **N muestras/hora**. **Sin payloads contables ni datos sensibles.**