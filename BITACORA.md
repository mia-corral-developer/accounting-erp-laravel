# BITÁCORA — SaaS de Contabilidad Colombia (Liberu ERP / Zellia)

> **Propósito:** punto de retorno exacto. Si la sesión se cierra, leer este archivo **primero** y luego los documentos referenciados. Actualizar al cerrar cada fase.
>
> **Última actualización:** 2026-09-16 · **Autor de la bitácora:** Hermes (agente Tech Lead)

---

## 1. Identidad del proyecto

| Campo | Valor |
|---|---|
| **Qué es** | SaaS de **contabilidad + facturación electrónica para Colombia**, construido sobre el motor **Liberu ERP** (open source, MIT). |
| **Marca whitelabel** | **Zellia** (whitelabel; NUNCA debe aparecer "Estarter" ni marcas del upstream como dueñas del producto). |
| **Repo (fork)** | `github.com/mia-corral-developer/accounting-erp-laravel` |
| **Upstream** | `liberusoftware/accounting-erp-laravel` — MIT © Liberu |
| **Rama de trabajo** | `fix/gate-boot-selfhost` |
| **Último commit de CÓDIGO** | `d981ed88` — *revert(tenancy): roll back the 56-model guardrail rollout (0307b4b2)*. Los commits HEAD posteriores son **únicamente esta bitácora** (docs). |
| **Clone de inspección local** | `/tmp/liberu-inspect` (⚠️ en `/tmp` — puede perderse; el repo real vive en GitHub) |
| **Entorno de gate en VPS** | `/tmp/liberu-fork` (fuera de `/etc/dokploy`) |
| **Cliente** | Dueño de la agencia whitelabel (usuario). Proyecto propio, no de terceros. |

### Stack técnico verificado
- **PHP 8.5.10** · **Laravel 13.29** · **Filament 5** · **Livewire 4**
- Runtime: **nginx + PHP-FPM** (contenedores SEPARADOS) — **NO Octane** (ver Decisión D1)
- MySQL 8 + Redis 7
- **438 tablas** · **281 migraciones `Ran`** · **466 módulos** (403 con `default_enabled:true`)
- 673 paquetes Composer
- Self-hosting: Docker Swarm + **Traefik** en el VPS Hostinger `177.7.42.143`, red `dokploy-network`

---

## 2. Documentos de referencia (leer en este orden al retomar)

### Planes maestros (en `/opt/data`, persistentes)
| Archivo | Qué contiene |
|---|---|
| `/opt/data/plan-saas-contabilidad-colombia.md` | **Plan estratégico/visión** del negocio. |
| `/opt/data/plan-ejecucion-saas-contabilidad-co.md` | **Plan de ejecución de 31 tareas** (Fase 0.5 → Fase 1 Colombia…). Cada tarea = PR ≤400 líneas con criterios de aceptación + comando de verificación. **Este es el documento que manda.** |
| `/opt/data/fase0-boot-liberu-erp.md` | Fase 0: boot inicial (SQLite). |
| `/opt/data/fase0.5-gate-liberu-stack-real.md` | **Fase 0.5**: los **9 bugs** de self-hosting con causa raíz + fix + commit. Veredicto GO. |
| `/opt/data/consulta-guardrail-tenancy-liberu.md` | Consulta externa (GPT) sobre el diseño del guardrail. 8 secciones autocontenidas. |

### Specs por tarea (en `/opt/data`)
| Archivo | Tarea |
|---|---|
| `/opt/data/t0.5.2-spec-inventario-modulos.md` | T0.5.2 — inventario de módulos |
| `/opt/data/t0.5.3-spec-fpm-split.md` | T0.5.3 — split nginx + php-fpm |
| `/opt/data/t0.5.4-spec-aislamiento-tenancy.md` | T0.5.4 — aislamiento team-scoped |

### Docs DENTRO del repo (rama `fix/gate-boot-selfhost`)
| Archivo | Qué contiene |
|---|---|
| `docs/adr/ADR-001-multitenant-isolation.md` | **ADR-001**: diseño del guardrail multitenant. Invariante congelada + 17 decisiones + texto literal de `scoped`. |
| `docs/plan-tenancy-hardening.md` | **Plan de 10 fases** de tenancy, cada una con gate de test explícito. |
| `docs/fase0.5-inventario-modulos.md` | Resultado T0.5.2. |
| `docs/fase0.5-aislamiento-tenant.md` | Resultado T0.5.4 (hallazgo crítico). |

---

## 3. Historia de fases (qué ya está hecho)

### ✅ FASE 0 — Boot inicial
Instala (673 paquetes), migra (438 tablas), siembra y sirve panel Filament. Probado con `artisan serve` + SQLite.

### ✅ FASE 0.5 — Gate del stack REAL (COMPLETA)
**Objetivo:** GO/NO-GO definitivo sobre MySQL 8 + Redis 7 reales (no SQLite).

| Tarea | Estado | Evidencia |
|---|---|---|
| **T0.5.1** Levantar stack + verificar panel | ✅ SUPERADA | `/`, `/login`, `/admin/login` → **200**; 438 tablas; 281 migraciones `Ran`. **9 bugs corregidos.** |
| **T0.5.2** Inventario de módulos sanos | ✅ SUPERADA | `route:list` → exit 0, **1228 rutas**; `XeroWebhookController` inexistente → creado (`d845e52b`). |
| **T0.5.3** Octane → PHP-FPM (split nginx+fpm) | ✅ SUPERADA | 3 endpoints → **200/200/200**; `ps` en `app` = solo `php-fpm` (sin supervisord/RoadRunner). |
| **T0.5.4** Aislamiento team-scoped (auditoría + test) | ✅ SUPERADA | `TenantIsolationTest` → `OK (3 tests, 8 assertions)`. **Hallazgo crítico:** aislamiento es *opt-in*. |
| **T0.6** Guardrail opt-in (`BelongsToCurrentTeam`) | ✅ SUPERADA | `OK (6 tests, 15 assertions)`. Aplicado a `Account`. Commit `ef5aa3b1`. |
| **T0.7** Rollout a 56 modelos | ⚠️ **APLICADO Y REVERTIDO** | Commit `0307b4b2` → introdujo **5 regresiones** → revertido en `d981ed88`. Conservado como referencia. |

**Los 9 bugs de self-hosting** (todos del envoltorio, ninguno del núcleo): opcache dir, índice >64 manual, views sin `is_dir`, supervisord include roto, puerto 80 vs 8000, env DB comentado, **104 índices >64**, **límite InnoDB 3072 bytes**, **Vite manifest faltante**. (+ Bug 10: `php.ini` con `#` en vez de `;`. + Bug 11: `XeroWebhookController` inexistente.)

### 🔄 FASE 0.6 — Tenancy Hardening (EN CURSO)
**Documentos:** `docs/adr/ADR-001-multitenant-isolation.md` + `docs/plan-tenancy-hardening.md`.

**Invariante congelada:**
> Ninguna operación tenant-owned puede ejecutarse con `UNRESOLVED`; ningún boundary puede seleccionar un tenant sin **autorizarlo**; ningún conflicto entre fuentes de tenant se resuelve **silenciosamente**; y `CENTRAL` debe ser **explícito**. La ausencia de contexto es **error de programación**, no resultado de consulta → **excepción**, nunca `where 1=0`.

**Por qué existe esta fase:** el aislamiento de Liberu es *opt-in* (`IsTenantModel` solo añade la relación `team()`; **171 modelos** tienen `team_id`, **316 archivos** filtran a mano, solo **1 policy** usa `belongsToCurrentTeam`). El rollout masivo `0307b4b2` rompió valuación de inventario, revaluación FX y conciliación bancaria.

#### Estado de las 10 fases de tenancy
| # | Fase | Estado |
|---|---|---|
| **0** | Recuperar baseline y bisectabilidad (revert `0307b4b2`) | ✅ **HECHA** — commit `d981ed88` |
| **1** | Arreglar Pest + Docker target `test` | ⏳ **SIGUIENTE** |
| 2 | Runtime tenancy (`scoped` + resolvers) | pendiente |
| 3 | Modelo de referencia `Account` (OBSERVE→ENFORCE) | pendiente |
| 4 | Clasificar los 171 modelos (app + módulos) | pendiente |
| 5 | READ rollout por bounded context | pendiente |
| 6 | Inventario de WRITES | pendiente |
| 7 | Write Guard | pendiente |
| 8 | Escape hatches / CENTRAL | pendiente |
| 9 | Constraints de BD (DISCOVER→REPORT→REPAIR) | pendiente |
| 10 | Superficies que Eloquent no protege | pendiente |

#### ✅ Gate de Fase 0 — CONFIRMADO
```
HEAD: d981ed88
Tests: 235, Assertions: 565, Failures: 2
  - BankStatementReconciliationTest::test_bank_statement_has_account_relationship  (preexistente, conocido)
  - SchemaConsistencyTest::test_every_model_fillable_has_a_table_or_casts           (preexistente, conocido)
New failures = 0   ✅ BASELINE PARITY
```
Estado equivalente a `ef5aa3b1`.

> ⚠️ **Ojo (corrección C1):** estos 235 tests son **solo el subconjunto clase-PHPUnit** (Pest está roto). El ~450 completo saldrá cuando se arregle Pest en Fase 1. La **parity gate autoritativa se re-graba al final de la Fase 1**.

### ⏸️ FASE 1 Colombia (moneda, PUC, impuestos) — NO ARRANZADA
Arranca **después** de cerrar la Fase 0.6 (tenancy), porque toda la capa Colombia es tenant-owned. Tareas T1.1 (COP), T1.2 (locale es-CO), T1.3+ (PUC, IVA/retención/ICA) están detalladas en `plan-ejecucion-saas-contabilidad-co.md` desde la línea 103.

---

## 4. Commits de la rama `fix/gate-boot-selfhost` (más recientes primero)

```
d981ed88  revert(tenancy): roll back the 56-model guardrail rollout (0307b4b2)   ← HEAD / Fase 0
b45e9ecf  docs(adr): ADR-001 multitenant isolation + tenancy hardening plan
0307b4b2  feat(tenancy): rollout BelongsToCurrentTeam to all 56 tenant models    ← REVERTIDO, conservar como referencia
ef5aa3b1  feat(tenancy): opt-in global team guardrail (T0.6)
d1f0d80f  test(tenancy): pin down team-scoped isolation (T0.5.4)
d845e52b  fix(routes): add missing XeroWebhookController so route:list passes
2e593480  fix(php.ini): use ';' comment marker and fix timezone
8acfbb09  feat(runtime): replace Octane/RoadRunner with split nginx + PHP-FPM
d695bf2a  fix(build): compile frontend assets so @vite() manifest exists
48f0ebb7  fix(module) three-way-matching: keep unique key within 3072-byte limit
4d2f4b89  fix(schema): cap auto-generated index names at MySQL's 64-char limit
354557da  fix(compose): wire MySQL/Redis env + correct app port for RoadRunner
9b385cf8  fix(supervisord): copy shared config to /etc/supervisord.conf (+include)
05ba7d1 / 15d69c8 / 8a0bb58 / 76301df  (Fase 0.5 primeros fixes)
```

---

## 5. Próximo paso concreto (retomar aquí)

### ➡️ FASE 1 — Arreglar **Pest** + **Docker target `test`**

**Por qué es el desbloqueante:** sin la suite completa no existe quality gate. Hoy solo corren 235 de ~450 tests (66 archivos Feature clase-PHPUnit). Pest crashea en el contenedor:
```
Could not read XML from file "--cache-directory"
plugin "Tia" sin resolver
```
La imagen **prod excluye deps dev** por diseño → de ahí el **target `test`** dedicado (multi-stage Docker + compose de test) que instale dev, **sin** tocar la imagen prod.

**Dos caminos (recomendación: el primero):**
1. **Docker target `test`** en el mismo Dockerfile (multi-stage), invocado desde `docker-compose.test.yml`. Es lo que pide GPT; no contamina prod.
2. Runner de CI con compose separado.

**Gate de Fase 1:**
```
100% de la suite descubrible ejecutable
Known failures documentados
Sin tests omitidos por error de infraestructura
SUITE COMPLETA: same commit → same suite → same result
BASELINE PARITY AUTORITATIVA re-grabada aquí (C1)
```
Luego Fase 2 (runtime tenancy: `singleton → scoped` + resolvers por boundary).

---

## 6. Decisiones clave (no re-litigar)

- **D1 — Runtime = PHP-FPM, NO Octane.** Octane mantiene estado en memoria entre requests → fuga de estado entre tenants (incidente de privacidad en un ERP contable). FPM es stateless por request → aislamiento gratis. Los bugs 1/3/4/5 del gate eran todos de Octane. Octane se reevalúa en Fase 6 (rendimiento) con tests de aislamiento.
- **D2 — Split `nginx` + `php-fpm`** como contenedores separados (no supervisord).
- **D3 — Guardrail de tenancy (ADR-001):** `TeamContext` con 3 estados (`UNRESOLVED`/`TENANT(id)`/`CENTRAL`) y **`scoped`, NO singleton**; fail-closed **loud** (`MissingTenantContextException`, no `where 1=0`); **un resolver por boundary** (no precedencia de fuentes); **`ExecutionPrincipal` separado** (`USER`/`SYSTEM`/`COMMAND`); clasificación declarativa `TenantOwned`/`Central`/`SharedOrTenant`; **gate = PARITY**, no "suite verde"; revertir a Account-only, rollout incremental bisecable.
- **D4 — Localización Colombia:** DIAN vía **PAS comercial** (no repos sin licencia); PUC como **dato**, no código; **NO tocar Odoo `l10n_co` (AGPL-3.0 = inaceptable para reventa)**.
- **D5 — Licencias:** Odoo `l10n_co` AGPL ✗ · repos DIAN sin licencia ✗ · Chatwoot CE MIT ✓ · Liberu MIT ✓.

---

## 7. Bloqueantes conocidos

| Bloqueante | Detalle |
|---|---|
| **Pest roto** | `Could not read XML from file "--cache-directory"` + plugin `Tia`. Bloquea la suite completa → bloquea todos los gates siguientes. Es exactamente lo que resuelve la Fase 1. |
| **Docker no disponible local** | Daemon apagado en la máquina del agente → todos los builds/gates corren en el VPS. |
| **SSH key `hostinger_vps` no existe** | Acceso al VPS `177.7.42.143` por password vía paramiko (`/tmp/vpsssh.py`). |
| **Agent Vault token inválido** | El servicio vive en `pro.jero.whitelabel.lat`, que está **502**. `agent-vault auth login` → "not logged in". No bloquea el proyecto Liberu. |
| **Provider OpenCode `opencode-go`** | Nunca configurado (0 credenciales). **El flujo "OpenCode + DeepSeek + Fable 5.1" NUNCA ha corrido** — el agente hace arquitectura Y ejecución con sus propias herramientas. No bloquea el proyecto. |

---

## 8. Reglas operativas del proyecto (recordatorio)

1. **Cada tarea → PR ≤400 líneas** con criterios de aceptación + comando de verificación crudo.
2. **Gate de fases de tenancy = PARITY** (0 regresiones NUEVAS vs baseline con 2 fallos conocidos), no "suite verde".
3. **Nunca perder datos:** todo `ALTER`/constraint lleva paso previo **DISCOVER → REPORT → REPAIR → VERIFY → CONSTRAIN**.
4. **Commit author en repos de Mia:** `Mia Corral <mia.corral.developer@gmail.com>` (git global vacío → usar `--author`).
5. **Validar con herramientas reales** (curl+grep, hashes, Playwright), no confiar en "deploy succeeded".
6. **"Estarter" NUNCA** como marca/dueño. Eliminar referencias al upstream del producto visible.
7. **No commitear secretos.** Credenciales DIAN/PAS solo en `.env`.
8. **3 preguntas obligatorias por commit de rollout** (ADR §10): ¿qué superficie nueva protegí? · ¿qué comportamiento cambié? · ¿qué test prueba que no rompí otra superficie?

---

## 9. Cómo retomar (checklist de arranque)

1. Leer este `BITACORA.md`.
2. Leer `docs/adr/ADR-001-multitenant-isolation.md` + `docs/plan-tenancy-hardening.md` (dentro del repo).
3. Verificar estado real: `cd <clone> && git log --oneline -5 && git branch --show-current` → debe estar en `d981ed88`, rama `fix/gate-boot-selfhost`.
4. Si el clone local `/tmp/liberu-inspect` desapareció: `git clone git@github.com:mia-corral-developer/accounting-erp-laravel.git` y checkout `fix/gate-boot-selfhost`.
5. Arrancar **Fase 1** (Pest + Docker target `test`).

---
*Bitácora mantenida por el agente. Actualizar al cerrar cada fase con commit + gate result.*