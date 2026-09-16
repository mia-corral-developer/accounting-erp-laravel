# Fase 1 — Baseline autoritativo de la suite (parity gate)

> **Generado:** 2026-09-16 · **HEAD:** `d981ed88` (rama `fix/gate-boot-selfhost`)
> **Comando:** `vendor/bin/pest --no-coverage` con `memory_limit=-1`.

## Los DOS baselines (y por qué difieren)

| Run | Entorno | Resultado | Total |
|---|---|---|---|
| A — dentro de la imagen prod | `APP_ENV=local`, **MySQL real `liberu`** (env sin `force`) | 30 failed / 1222 passed | 1252 |
| B — target `test` aislado | `APP_ENV=testing`, **sqlite `:memory:`** | **42 failed / 1210 passed** | 1252 |

Los "1195 warnings" de la primera corrida del target B eran **artefacto de imagen** (faltaban
`.env` y `public/build/manifest.json`); corregidos → 0 warnings.

## 🔴 Hallazgos de infraestructura (los 3)

1. **Pest "roto" = `phpunit.xml` ausente.** `.dockerignore` lo excluye de la imagen prod; sin
   él, Pest cae en `Could not read XML from file "--cache-directory"`. **No era Pest.**
2. **La suite no corría aislada.** `phpunit.xml` declara `sqlite :memory:` pero sus `<env>` no
   llevan `force="true"` → no sobrescriben el entorno del contenedor → los tests corrían
   **contra la MySQL real**. (Run A.)
3. **La suite NO es compatible con sqlite.** Con el aislamiento forzado (run B), ~26 tests
   adicionales fallan con `MissingAttributeException`. Causa: `App\Providers\AppServiceProvider`
   hace `Model::shouldBeStrict(! isProduction())` → en `testing` **lanza** al acceder un
   atributo ausente. En sqlite faltan columnas que sí existen en MySQL (el fork se desarrolló
   contra MySQL, pese a lo que dice `phpunit.xml`).

## Triage del run B (42 fallos, aislado)

| Grupo | N | Causa |
|---|---|---|
| **`MissingAttributeException`** (modo estricto + schema sqlite incompleto) | ~26 | `AccountingAccountsPayable/Receivable/BankAccountsPresets/BankRules/BillPayments/CashCoding/SupplierBills Presets`, `AdminPanelTenancy`, `InvoicePosting`, `PaymentPosting`, `PostingReversal`, `Provisioning`, `TeamResource`, `UserResource`, `ManageSiteSettings*`, `Qbo*`, `QuickBooks*`, `Sage*`, `Xero*` |
| `QueryException` (columna ausente) | 2 | `SageSyncTest` (`sage_id`) |
| `BindingResolutionException` (clase inexistente) | 1 | `XeroEntitiesSyncTest` |
| Rutas/roles/redirect | ~6 | `AccountSetupWizardTest`, `AdminPanelRendersTest`, `ChartOfAccountApiTest` (404), `LoginLandingRedirectTest` ×3 |
| Tema/forecast | 3 | `ForecastTenancyTest`, `ThemeSiteResolutionTest` ×2 |
| **Preexistentes conocidos** | 2 | `BankStatementReconciliationTest`, `SchemaConsistencyTest` |

**Ninguno proviene del trabajo de tenancy** (revertido en `d981ed88`): son preexistentes del fork.

## Gate de Fase 1 (decidido)

El target de test correcto **no es sqlite** (el código no lo soporta) sino **MySQL efímero**:
aislado del stack runtime y del dato real, pero con el mismo motor que producción →
schema fiel + resultados reproducibles. `docker-compose.test.yml` provisiona un servicio
`mysql` con volumen `tmpfs` (desechable) y `DB_CONNECTION=mysql`.

```
✓ suite completa descubrible y ejecutable (1252 tests)      ← HECHO
✓ aislamiento real (MySQL efímero, sin tocar el dato real)   ← target listo
✓ same commit → same suite → same result                     ← objetivo
✓ baseline grabado y clasificado (42 sqlite / 30 MySQL)      ← HECHO (este doc)
```
**Parity gate = 0 regresiones NUEVAS vs el baseline elegido**, no "suite verde".

## Reproducir

```bash
# build + run aislado con MySQL efímero
docker compose -f docker-compose.test.yml up -d mysql
docker compose -f docker-compose.test.yml run --rm test
```
**Pitfalls:** `memory_limit` 256M revienta al bootear los 466 módulos → `-1`. La suite tarda
~10-11 min → lanzar detachado (`setsid nohup`) y leer el log en pollings; un `exec_command`
mantenido abierto muere con `PipeTimeout`.