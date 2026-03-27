# E2E Smoke Checklist

1. Activar el plugin sin errores fatales ni warnings evitables con `WP_DEBUG`.
2. Guardar ajustes en Search Appearance, Bots, Integrations y Tools sin perdida de datos no enviados.
3. Cargar Dashboard de Open Growth SEO y confirmar estados, acciones rapidas y chequeos live.
4. Abrir editor de entradas (Gutenberg y Classic) y verificar guardado de panel SEO.
5. Validar salida frontend de title/meta/canonical/robots/schema sin duplicaciones no controladas.
6. Abrir `/ogs-sitemap.xml` y validar XML correcto e indexabilidad coherente.
7. Abrir `/robots.txt` y validar reglas gestionadas + simulacion por bot.
8. Ejecutar auditoria por REST y por CLI (`wp ogs-seo audit run`).
9. Ejecutar compatibilidad/importacion en dry-run y validar rollback.
10. Ejecutar developer tools: diagnostics/export/import/reset/logs (admin, REST y CLI).
11. Provisionar Classic Editor de forma deterministica cuando se requiera cobertura Classic (`tests/runtime/setup-classic-editor.ps1`) y correr `test:e2e:editor-classic` con `OGS_E2E_REQUIRE_CLASSIC=1` para fallo duro si falta provisioning.
12. Ejecutar cobertura dedicada de editor (`test:e2e:editor-runtime`) para guardar/recargar controles SEO en Gutenberg, validar persistencia Classic y assertions runtime de breadcrumbs.
13. Ejecutar matriz de conflictos SEO (`test:e2e:seo-conflicts`) para canonical/redirect/noindex/schema/social/breadcrumb y coherencia admin->frontend.
14. Validar limpieza de uninstall segun `keep_data_on_uninstall`.
