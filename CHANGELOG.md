# Changelog

Todo cambio que se note desde fuera queda escrito aqui.

Formato [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), versionado
[SemVer](https://semver.org/spec/v2.0.0.html).

**Que significa el 0.x:** la API todavia puede cambiar. Al llegar a 1.0.0 deja de
poder hacerlo sin subir el numero mayor. Preferimos decirlo asi a poner un 1.0 y
romperlo en la version siguiente.

---

## [0.2.0] — 2026-08-08

Formato empaquetado y puente HTTP. El nucleo pasa de 745 a 1.354 comprobaciones
en verde.

### Añadido

- **Segundo formato de almacenamiento, elegible por coleccion.** `fs` deja un
  archivo JSON por documento —legible, comparable con git, reparable a mano— y
  `packed` guarda la coleccion entera en un archivo al que solo se añade.
  Empaquetado escribe unas 40 veces mas rapido. El defecto sigue siendo `fs`.
- `Storage::migrarA()` cambia el formato de una coleccion en los dos sentidos
  sin tocar el contenido: ni la version, ni las fechas.
- Durabilidad por coleccion, `safe` o `fast`. La que no necesita `fsync` no lo paga.
- Compactacion del formato empaquetado por umbral de espacio muerto (30%).
- **Puente HTTP.** `axidb_http()` y el endpoint hecho `core/api.php`: la base de
  datos responde al navegador sin escribir un backend.
- **`core/axi.js`**, cliente de navegador sin dependencias ni empaquetador:
  `insert / get / update / delete / find / count / sql` y consultas encadenadas.
- Permisos del puente: tokens con ambito por coleccion, colecciones publicas de
  solo lectura, modo solo lectura global y CORS por origen exacto.
- `Blindaje`: el directorio de datos nace con un `.htaccess` que niega el acceso
  por HTTP. Solo lo entiende Apache; la guia explica que hacer en los demas.
- `NombreInvalido`, para poder responder 400 con el motivo sin exponer rutas del
  disco en un 500.
- Guias [07-drivers](docs/guide/07-drivers.md) y [08-http](docs/guide/08-http.md),
  con sus ejemplos ejecutandose dentro de la suite.
- Ejemplo [cristaleria-web](examples/cristaleria-web/): la aplicacion entera desde
  el navegador con cuatro lineas de PHP.
- `LICENSE` (Apache 2.0), `NOTICE` y `composer.json`.

### Corregido

- Migrar entre formatos reescribia `_updatedAt` y reiniciaba `_version` a 1.
  Ahora se copia literal.
- `declararDriver()` sobre una coleccion con documentos los dejaba en disco pero
  invisibles. Ahora se niega y remite a `migrarA()`.
- En Windows, `ftell()` sobre un descriptor recien abierto en modo añadir
  devuelve 0 y no el final del archivo: todos los desplazamientos a partir del
  segundo eran erroneos.
- `core/api.php` contesta 503 si nadie declaro la seccion `http`. El nucleo se
  copia dentro de proyectos que se despliegan enteros; que el archivo exista no
  puede significar que haya una API abierta.
- **En Windows, un lector podia tumbar una escritura.** `rename()` sobre un
  archivo que otro proceso tiene abierto falla ahi, aunque lo tenga abierto solo
  para leer y por microsegundos. Medido: con un lector insistente delante,
  **37 de cada 150 escrituras fallaban**. Ahora se reintenta durante unos 30 ms
  y pasan las 150. En Linux nunca ocurrio: POSIX permite renombrar sobre un
  archivo abierto.
- `migrarA()` dejaba atras el `_write.lock` del formato empaquetado al volver a `fs`.
- `axidb.json` se busca ahora tambien dentro de la propia carpeta del nucleo. Un
  evaluador externo lo dejo donde le parecio razonable y el endpoint no lo
  encontraba.

### Rendimiento

```
10.000 documentos       AxiDB fs   AxiDB packed   SQLite
  alta masiva (ms)        14.938            375       22
  1000 lecturas (ms)          44             21        3
```

Reproducible con `php bench/comparativa.php`. AxiDB no alcanza a SQLite en
rendimiento bruto y no lo promete: lo que ofrece es cero instalacion, documentos
sin esquema y una carpeta que se copia.

---

## [0.1.0] — 2026-08-08

Nucleo autonomo (`core/`), reescrito desde cero. Sustituye a `engine/`.

### Añadido

- Escritura atomica con temporal y renombrado, `fsync` opcional.
- Indices secundarios con lectura-modificacion-escritura bajo un unico lock.
- Consultas con `where / orderBy / limit / offset / select`, usando indice cuando
  lo hay.
- AxiSQL: `SELECT`, `INSERT`, `UPDATE`, `DELETE`, DDL de indices y `EXPLAIN`.
- Nombres portables entre Windows y Linux: la misma coleccion da el mismo archivo
  en los dos sistemas.
- Cero dependencias: sin Composer, sin extensiones mas alla de `json`.

### Corregido, respecto a lo que habia antes

- **Escritura no atomica.** Un corte a mitad dejaba el documento truncado. Medido:
  el 46,3% de las lecturas concurrentes devolvia un documento roto.
- **Carrera en el indice.** Dos procesos escribiendo perdian entre el 76% y el 98%
  de las entradas, de forma permanente.

---

## Historico del motor antiguo (`engine/`)

Lo que sigue es el registro de `axidb/engine`, el motor anterior: 27.000 lineas
con CMS, temas y panel web. **Se archiva y no recibe mas cambios.** Su numeracion
es independiente de la del nucleo; que aquel llegara a 1.0.0 no dice nada del
nucleo actual, que empieza en 0.1.0 por lo dicho arriba.

---

## [engine 1.0.0] — 2026-04-27

Primera version estable. Cierra el plan v1 (Fases 1-7).

### Added

#### Op model unificado (Fase 1)
- `Axi\Engine\Op\Operation` clase base con `validate()`, `execute()`, `help()`,
  `toArray()` / `fromArray()` para serializacion canonica.
- 45 Ops del catalogo (CRUD, schema, vault, backup, auth, AI, system).
- `Axi::opRegistry()` como fuente unica de la verdad para resolver `op_name → class`.
- `Result::ok()` / `Result::fail()` con `code` semantico y `duration_ms`.
- `AxiException` con codigos estables (`VALIDATION_FAILED`, `OP_UNKNOWN`,
  `DOCUMENT_NOT_FOUND`, `FORBIDDEN`, `CONFLICT`, ...).

#### Storage (Fase 1.4)
- `FsJsonDriver` con escrituras atomicas (tmp + rename), `_index.json`
  reconstruido automaticamente, `.versions/<col>/<id>/` para historial.
- Multi-tenant via `STORAGE/system/active_project.json` (rebase a
  `PROJECTS/<slug>/STORAGE`); colecciones `master` (users/roles/projects/
  system_logs) siempre globales.

#### AxiSQL (Fase 2)
- Parser completo: `SELECT INSERT UPDATE DELETE COUNT` con
  `WHERE = != < <= > >= LIKE IN NOT IN BETWEEN IS NULL IS NOT NULL`
  + `AND OR ()` anidado, `ORDER BY`, `LIMIT`, `OFFSET`.
- Op `sql` ejecuta cualquier query y delega a la Op CRUD correspondiente.
- SDK fluent `Collection::where()->orderBy()->get()` mapea a las mismas Ops.

#### Vault + Backups (Fase 3)
- `Vault\Vault` con AES-256-GCM por documento, master key derivada con PBKDF2,
  canary cifrado para detectar passwords incorrectas.
- Cifrado transparente por coleccion: solo activar `_meta.flags.encrypted=true`
  y los inserts/updates futuros pasan por `encryptDoc/decryptDoc`.
- `Backup\SnapshotStore` con full e incremental (basado en `_updatedAt`),
  formato ZIP con `manifest.json` + `data.zip`.
- 4 Ops: `vault.unlock/lock/status` y `backup.create/restore/list/drop`.

#### CLI (Fase 3 + Fase 6)
- `bin/axi` (Unix) / `bin/axi.cmd` (Windows).
- `axi help [op]`, `axi <op-name> [args]` con flags `--key=value` / `--key value`.
- `axi sql "..."`, `axi vault <unlock|lock|status>`,
  `axi backup <create|restore|list|drop>`.
- `axi docs <build|check|clean>` regenera `docs/api/*.md` desde `HelpEntry`.
- **Fase 6**: `axi ai <list-agents|new-agent|ask|run|spawn|kill|kill-all|
  attach|broadcast|audit>` y `axi console` (REPL TTY con 4 modos).

#### Dashboard web vanilla (Fase 4 + Fase 6)
- `axidb/web/index.html` (sidebar de colecciones + editor JSON inline).
- Tab Console con modos `AxiSQL` / `Op JSON` / `ai:` (Fase 6).
- **Fase 6**: tab Agents con arbol padres/hijos, status colorado por estado,
  botones run / kill / Kill all / + New agent.
- Consola REPL dedicada `axidb/web/console.html` (Fase 6) con atajos
  JetBrains-like: `Ctrl+Enter`, `Ctrl+/`, `Ctrl+Space`, `Ctrl+P`,
  `Ctrl+Shift+A`, `F1`, `Alt+1..4`, paleta de Ops y help overlay.
- 100% vanilla JS y CSS, sin frameworks ni build step.

#### Demo apps (Fase 4)
- `examples/notas/` — CRUD vanilla en 4 archivos PHP.
- `examples/portfolio/` — listado de proyectos.
- `examples/remote-client/` — consume AxiDB remoto via `AXI_REMOTE_URL`.

#### Migracion Socola (Fase 5)
- Coexistencia funcional `/acide/` legacy y `/axidb/api/`.
- `class_alias` para compatibilidad con `QueryEngine` legacy.
- 14 paridades validadas en `parity_test.php`.
- Op `legacy.action` como wrapper formal del bridge `{action:...} → ACIDE`.
- `migration/release_adapter.php` post-procesa `release/` tras `build_site`.
- `migration/htaccess.patch` con plan de flip atomico (`<5 min` rollback).
- `migration/build-axidb-zip.sh` / `.ps1` empaqueta zip de ~1.4 MB
  autocontenido (motor + SDK + docs + ejemplos).

#### Capacidad agentica (Fase 6)
- `Agent` (entidad persistible), `AgentStore` (CRUD `_system/agents/<id>/`),
  `AgentKernel` (loop receive→think→act→observe), `Toolbox` (sandbox por Op),
  `Mailbox` (inbox/outbox JSONL append-only), `Manager` (fachada).
- `MicroAgent` colapsado en `Agent` con `parent_id != null && ephemeral=true`,
  profundidad maxima 3, `max_children` por parent.
- 9 Ops AI: `ai.new_agent`, `ai.new_micro_agent`, `ai.run_agent`, `ai.ask`,
  `ai.kill_agent`, `ai.list_agents`, `ai.broadcast`, `ai.attach`, `ai.audit`.
- 5 backends LLM via `LlmRegistry`:
  - `noop` — deterministico offline (default).
  - `groq:<m>` — Llama, Mixtral via Groq Cloud.
  - `ollama:<m>` — modelos locales.
  - `gemini:<m>` — Google Generative Language v1beta.
  - `claude:<m>` — Anthropic Messages API.
- **Auditoria**: `AuditLog` NDJSON append-only con `actor=agent:<id>`,
  `op`, `params` (sanitizado), `success`, `code`, `duration_ms`, `snapshot?`.
- **Auto-snapshot pre-batch**: si un agente ejecuta `op=batch` con >10
  escrituras, dispara `backup.create` automatico con name
  `auto-pre-batch-<YYYYmmdd-HHmmss>-<id6>` antes del batch.
- **Kill switch**: individual (`status=killed`) y global
  (`_global.json` con `kill_switch=true`); con switch activo, cualquier
  `RunAgent` lanza `FORBIDDEN`.
- **Sandbox**: cada agente declara `tools[]` con nombres de Ops permitidas;
  `Toolbox::call` rechaza con `FORBIDDEN` cualquier Op fuera del sandbox.
- **Budget duro**: `max_steps` y `max_tokens` cuentan en cada vuelta;
  agotamiento → `status=waiting` (no rescate automatico).

#### HTTP API (Fase 1.5)
- `axidb/api/axi.php` endpoint unico `POST` con `{op, ...}`.
- CORS abierto para acciones publicas (`ping`, `describe`, `schema`, `help`,
  `auth.login`, `auth.logout`).
- Cookie `acide_session` (HttpOnly, SameSite=Lax) o `Authorization: Bearer
  <token>`.

#### SDK PHP (Fase 1.6)
- `Axi\Sdk\Php\Client` con dos transports (`embedded` y `http`).
- `Collection` fluent: `where`, `orderBy`, `limit`, `offset`, `fields`,
  `get`, `count`, `first`, `insert`, `update`, `delete`.
- Mismo codigo de aplicacion sirve embebido y remoto — solo cambia el
  constructor.

#### Auth (Fase 3)
- 5 Ops: `auth.login`, `auth.logout`, `auth.create_user`, `auth.grant_role`,
  `auth.revoke_role`.
- `Auth::validateRequest()` con allowlist publica.
- `RoleManager` con permisos `<recurso>:<accion>`.
- Bootstrap del primer admin via `auth/setup.php` o
  `auth/create_superadmin.php`.

#### Documentacion
- `docs/guide/` (8 archivos): quickstart, embedded-vs-remote, AxiSQL,
  vault-snapshots, agentes, dashboard, console, README.
- `docs/standard/` (6 archivos): op-model, wire-protocol, storage-format,
  migration-socola, **agent-protocol** (nuevo Fase 6), **auth** (nuevo
  Fase 7).
- `docs/api/` (45 + 1 README): generado desde `HelpEntry` de cada Op,
  regenerable con `axi docs build`.

### Tests

- **745 checks** en 13 archivos (~9 s con `tests/run.php`):
  - `agents_test.php` 52 (Fase 6: persistencia, sandbox, kernel, microagent,
    mailbox, broadcast, kill switch, audit log, auto-snapshot pre-batch).
  - `axisql_test.php` 92 (Fase 2: parser y ejecucion).
  - `backup_test.php` 36 (Fase 3: full + incremental + restore).
  - `dashboard_test.php` 40 (Fase 4 + Fase 6: gate, demo Notas, console.html).
  - `full_catalog_test.php` 317 (cobertura completa de las 45 Ops).
  - `http_routing_test.php` 21, `op_model_test.php` 44, `parity_test.php` 14,
    `sdk_test.php` 32, `storage_driver_test.php` 41, `sugar_test.php` 22,
    `vault_test.php` 33, `test_axidb.php` 1.

### Migracion desde ACIDE

El motor original ACIDE (Socola CMS) sigue funcionando intacto. AxiDB
coexiste como un transport alternativo que tunela al mismo storage.

**Para migrar una app ACIDE a AxiDB**:
1. `require 'axidb/axi.php'` en lugar de `require 'CORE/core/ACIDE.php'`.
2. Reemplazar `$acide->execute(['action' => '...'])` por
   `$db->execute(['op' => '...'])`. La forma `{action,...}` legacy sigue
   soportada via `Op\System\LegacyAction`.
3. Opcionalmente, mover handlers privativos a Ops formales.

Ver [`docs/standard/migration-socola.md`](docs/standard/migration-socola.md).

### Excluded from v1.0 (planned for P2)

- Driver MySQL-compatible para clientes existentes.
- Adapter WordPress.
- Vector search.
- Video tutoriales (sustituidos por scripts asciinema-style en
  `docs/demos/`).
- Hosting de docs en GitHub Pages (config preparada en
  `.github/workflows/pages.yml`; activar pages requiere paso manual).

---

## [Unreleased]

Sin cambios todavia. Proximo hito: P2 (drivers compatibilidad).
