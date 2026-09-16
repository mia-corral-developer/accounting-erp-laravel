# Fase 0.5 — Inventario de módulos y salud del boot (T0.5.2)

**Fecha:** 2026-09-16 · **Rama:** `fix/gate-boot-selfhost` · **Stack:** split nginx + php-fpm + MySQL 8 + Redis 7

## Resultado

| Comando | Antes | Ahora | Veredicto |
|---------|-------|-------|-----------|
| `php artisan module:validate` | — | **exit 0** — "All discovered modules are valid" | ✅ |
| `php artisan module:list` | — | **470 líneas** (466 módulos) | ✅ |
| `php artisan route:list` | **exit 1** — `Class "App\Http\Controllers\Api\XeroWebhookController" does not exist` | **exit 0** — 1228 rutas | ✅ FIXED |

## Panorama de módulos
- **466** directorios en `modules/`; **403** con `"default_enabled":true`, **6** con `false`.
- `module:validate` pasa sin errores → manifests, providers y compatibilidad OK.
- Comandos disponibles: `module:list`, `module:validate`, `module:status {name}`, `module:features {query}`.

## Bug encontrado y corregido (T0.5.2)
**Síntoma:** `route:list` fallaba con `Class "App\Http\Controllers\Api\XeroWebhookController" does not exist`
(por eso Fase 0 no pudo generar la lista de rutas).

**Causa raíz:** `routes/api.php:172` registra `Route::post('/webhooks/xero', [XeroWebhookController::class, 'handle'])`,
pero **la clase nunca existió** en el repo (a diferencia de `Wise`, `Qbo`, `Plaid`, `Revolut`, que sí tienen
su `*WebhookController`).

**Fix:** se creó `app/Http/Controllers/Api/XeroWebhookController.php`:
- Verifica la firma **HMAC-SHA256** del cuerpo crudo con `services.xero.webhook_key` (header `x-xero-signature`, base64),
  igual que el patrón de `QboWebhookController` con `intuit-signature`.
- Reúne los `tenantId` de `events[]`, busca `XeroConnection` activas y ejecuta `XeroService::sync()`.
- Endpoint público sin auth (solo firma), como declara el comentario de la ruta.

**Verificación:** `docker compose exec -T app php artisan route:clear && php artisan route:list` → **exit 0**, **1228 rutas**.

## ⚠️ Gap conocido (NO resuelto en T0.5.2)
`App\Jobs\SyncAccountingProviderConnectionJob` — referenciado por `QboWebhookController::handle()` — **no existe en el repo**.
No rompe `route:list` (la referencia vive dentro de un método, no en un binding de ruta), pero **rompería el webhook QBO en runtime**.
→ Candidato a tarea propia: crear el job o sustituir la llamada por `Service::sync()` directo.

## Notas de verificación
- El autoloader del contenedor es `composer dump-autoload --classmap-authoritative`: **un archivo nuevo NO se autoloada** hasta regenerar el classmap (relevante al validar sin rebuild). El build de Docker lo regenera solo.
- `route:list` con 466 módulos tarda; ejecutar con timeout amplio.