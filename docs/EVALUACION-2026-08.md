# AxiDB — Evaluacion tecnica y hoja de crecimiento

Fecha: 8 de agosto de 2026
Alcance: 239 archivos PHP, 27.602 lineas, 42 Ops, 13 archivos de test.
Metodo: lectura del nucleo, ejecucion de la suite completa y benchmarks propios.

---

## 1. Que es AxiDB

### 1.1 Tu definicion

> "Una base de datos de documentos con las mismas reglas de SQL, programada en
> PHP, segura y rapida."

Es correcta en intencion pero se queda corta en lo que de verdad diferencia al
proyecto, y hoy es optimista en dos adjetivos. Vamos por partes.

### 1.2 Definicion afilada

AxiDB no es "otra base de datos de documentos". Bases de datos documentales en
PHP hay muchas. Lo que AxiDB tiene y casi nadie tiene es esto:

> **AxiDB es un motor de datos documental embebible cuyo contrato de operacion
> es identico en cinco superficies distintas: PHP embebido, HTTP JSON, SQL,
> CLI y agente de IA.**

Una sola operacion — `{op, ...params}` → `{success, data, error, code, duration_ms}` —
se ejecuta igual la invoque un `require`, un `curl`, una consola SQL, un script
bash o un modelo de lenguaje. Eso no es un detalle de implementacion: es la tesis
del producto.

De ahi se derivan tres propiedades que si son unicas:

| Propiedad | Por que importa |
|---|---|
| **Cero instalacion** | Copias `axidb/`, haces `require`, funciona. Sin Composer, sin Docker, sin Node, sin servidor de BD. Corre en hosting compartido de 3 EUR/mes |
| **Auto-documentado por construccion** | Cada Op declara su `HelpEntry`; de ahi salen `docs/api/*.md`, el `help` del CLI y el catalogo que consume el agente. La documentacion no puede desincronizarse del codigo porque es el codigo |
| **Nativo para agentes** | Sandbox por Op, allowlist de herramientas, budget de pasos, audit log con `actor=agent:<id>` y kill switch. Un LLM puede conducir la base de datos y queda trazado |

Esa tercera propiedad es, en mi opinion, la mas valiosa y la que menos estas
explotando en el discurso del proyecto.

### 1.3 Con que se compara honestamente

No compite con PostgreSQL ni con MongoDB. La comparacion util es:

- **SQLite** — mismo espiritu (embebido, un archivo, cero servidor), pero SQLite
  es relacional y AxiDB es documental. SQLite gana en todo lo tecnico; AxiDB gana
  en que el documento JSON es el formato nativo de una app PHP moderna y en que
  el dato es legible e inspeccionable sin herramientas.
- **RedBeanPHP / Flat file DBs (SleekDB, Filebase)** — AxiDB esta muy por encima
  en arquitectura. Ninguna tiene modelo de Op unificado, vault, snapshots ni agentes.
- **Chroma / Qdrant embebidos** — no compiten hoy, pero es exactamente el hueco
  donde AxiDB podria colocarse (seccion 5).

**Posicionamiento en una frase:** *el SQLite documental de PHP, con protocolo
nativo para agentes de IA y soberania de datos por defecto.*

---

## 2. Estado real, verificado

Tu intuicion es correcta: **esta verde**. Pero conviene ser preciso sobre en que
esta verde, porque no es homogeneo.

| Capa | Madurez | Comentario |
|---|---|---|
| Diseno y abstracciones | **v1.0 real** | `Driver`, `Result`, `AxiException`, `HelpEntry`, modelo Op. Es arquitectura por encima de la media |
| Implementacion del nucleo | **v0.6** | Dos capas de almacenamiento divergentes, indices que no existen, escritura cuadratica |
| Empaquetado y adopcion | **v0.3** | El 40% del repo no es una base de datos. Suite en rojo. `php.ini` con rutas de otra maquina |

El tag `v1.0` del README es prematuro. Un `v0.6.0` honesto valdria mas que un
`v1.0` que no sobrevive a la primera auditoria.

### 2.1 La suite de tests no esta verde

El README declara **745 checks / 0 failures / ~9 s**. La ejecucion real de hoy:

```
Files:  2 ok / 11 ko
Checks: 465 passed, 96 failed
Time:   169.301 ms
```

Solo pasan `storage_driver_test.php` y `sugar_test.php`. Esto no es cosmetico:
la suite es la unica red de seguridad del proyecto y ahora mismo no protege nada,
porque un fallo nuevo se pierde entre 96 fallos viejos.

Las causas, ya clasificadas (ver 2.2, B4 y B6).

### 2.2 Hallazgos, por severidad

---

#### B1 — Dos capas de almacenamiento que divergen (correccion)

Coexisten dos implementaciones de persistencia:

- `engine/StorageManager.php` — legacy, con `_index.json`. **La usan todas las Ops.**
- `engine/Storage/FsJsonDriver.php` — nueva, limpia, escritura atomica tmp+rename,
  flock con timeout, validacion anti-traversal. **No la usa ninguna Op del camino caliente.**

`Axi.php` registra las dos como servicios (`storage` y `driver`). `Insert`, `Update`,
`Delete` y `Select` llaman siempre a `storage`. El driver bueno es codigo muerto.

Y no solo es duplicacion: **divergen**. Verificado:

```
1) list() tras escribir con StorageManager: 1 docs
2) doc 'b' escrito con FsJsonDriver existe en disco: SI
3) list() lo ve: 1 docs  -> INDICE OBSOLETO, el doc b es invisible
```

Un documento correctamente persistido en disco es invisible para las consultas.
Cualquier codigo que use el `Driver` (el que la propia documentacion presenta
como el futuro) produce datos fantasma.

**Impacto:** perdida silenciosa de datos a ojos de la aplicacion. Es el fallo
mas grave del repositorio.

---

#### B2 — La escritura es cuadratica (rendimiento)

`StorageManager::update()` y `::delete()` terminan llamando a `rebuildIndex()`,
que **relee y reescribe la coleccion entera** en `_index.json` en cada operacion.

Escribir N documentos cuesta O(N²). Medido en esta maquina:

| Documentos | Tiempo acumulado | Coste de los ultimos 100 |
|---|---|---|
| 100 | 4,65 s | 4,65 s |
| 200 | 12,12 s | 7,47 s |
| 300 | 19,85 s | 7,73 s |
| 400 | 29,66 s | 9,81 s |
| 500 | 40,84 s | 11,19 s |

El coste por documento crece linealmente. Una escritura suelta con 500 documentos
en la coleccion tarda **67 ms**. Extrapolando: con 5.000 documentos, ~0,7 s por
escritura; con 20.000, varios segundos. Un intento de cargar 2.000 documentos
supero los **dos minutos** y hubo que abortarlo.

Ademas `_index.json` es una **copia completa** de la coleccion: 194 KB para 500
documentos de 200 bytes. Duplica el disco, y `list()` lo parsea entero en cada
consulta aunque solo se pida un documento.

**Impacto:** el adjetivo "rapida" no se sostiene por encima de ~1.000 documentos
por coleccion. Es el techo duro del motor.

---

#### B3 — Los indices se declaran pero no existen

`Op\Alter\CreateIndex` escribe una entrada en `_meta.indexes` y termina. No
construye nada. `QueryEngine` nunca lee `_meta.indexes`. El propio codigo lo
admite en su cabecera:

```
Nota v1: el indice se registra en _meta.indexes pero la construccion
         fisica (_index/<field>.idx) llega con StorageDriver en Fase 1.4.
```

Consecuencia: **todo WHERE es un escaneo completo**, siempre. Y `CREATE INDEX`
en AxiSQL devuelve `success: true` sin haber creado nada.

**Impacto:** mas alla del rendimiento, es un problema de contrato: la API afirma
haber hecho algo que no hizo. Un usuario externo optimizaria contra un indice
inexistente.

---

#### B4 — AxiSQL esta roto de extremo a extremo desde el SDK embebido

El caso C del README, una de las cinco demostraciones de portada:

```php
$db->sql("SELECT title, body FROM notas WHERE _version > 0 ORDER BY title LIMIT 10");
```

Ejecutado tal cual:

```
INSERT -> {"success":false,"error":"No autorizado: Se requiere una sesion valida.","code":"UNAUTHORIZED"}
SELECT -> {"success":false,"error":"No autorizado: Se requiere una sesion valida.","code":"UNAUTHORIZED"}
```

Causa: en `Axi::execute()`, una peticion que llega como **objeto** `Op\Operation`
se marca publica automaticamente, pero la que llega como **array** `{op:'sql'}`
pasa por el escudo de autenticacion. El SDK embebido no tiene sesion, asi que
`sql()` siempre falla. Por eso `sugar_test` (objetos) pasa y `axisql_test`
(arrays) revienta.

**Impacto:** una funcionalidad de portada no funciona segun se documenta.

---

#### B5 — El modelo de autorizacion es incoherente

Dos problemas en `Axi::execute()`:

```php
if ($request instanceof Op\Operation) {
    $isPublic = true;          // cualquier objeto Op salta auth y RBAC completos
}
```

La confianza se decide por **el tipo PHP del argumento**, no por la identidad
de quien llama. Hoy no es explotable desde HTTP (ese camino siempre construye
arrays), pero es una invariante fragil: el dia que un handler acepte un objeto
Op construido a partir de entrada de usuario, el escudo entero desaparece.

Y el RBAC granular es codigo muerto:

```php
if (!$auth->hasPermission($user, $resource, $opType)) {
    // El sistema asume permisos por defecto para algunas operaciones si no se deniegan explicitamente
}
```

Se calcula el permiso y se descarta el resultado. El `RoleManager` y
`auth/schemas/role.schema.json` no gobiernan nada. Lo unico que protege de verdad
es la lista dura de `masterCollections`.

Lo que si esta bien, y conviene reconocerlo: los tests de seguridad por fase pasan,
la validacion anti-traversal existe en las dos capas, CORS usa `parse_url()` con
comparacion exacta (no `strpos`), y las escrituras del driver nuevo son atomicas.
El adjetivo "segura" se sostiene mucho mejor que el de "rapida".

---

#### B6 — La suite esta desincronizada y clavada a otra maquina

Tres causas distintas detras de los 96 fallos:

1. **Entorno ajeno.** `tests/php.ini` contiene:
   ```
   extension_dir=C:\Users\K2\AppData\Local\...\PHP.PHP.8.3_...\ext
   ```
   Es la ruta de otro usuario de otro equipo. `openssl` y `zip` no cargan, y con
   ellos caen enteros `vault_test` y `backup_test`. Nada relacionado con el codigo.

2. **Tests no actualizados tras el escudo de auth.** Los 41 fallos de
   `full_catalog_test` son todos del mismo molde:
   ```
   - select con params invalidos -> VALIDATION_FAILED  --  code=UNAUTHORIZED
   ```
   El test espera un error de validacion y recibe uno de autorizacion, porque el
   escudo se añadio delante sin revisar la suite.

3. **Fallos reales.** `axisql_test` muere con fatal error (B4), y `agents_test`
   acumula 9 fallos propios.

También: `vault_test.php` referencia `Axi\Engine\Vault\Crypto`, una clase que no
existe en el repositorio.

---

#### B7 — El alcance esta difuso, y es el mayor freno a la adopcion

Dentro de `engine/`, que deberia ser un motor de datos, hay:

```
engine/ThemeManager.php            engine/handlers/CMSHandler.php
engine/StaticGenerator.php         engine/handlers/QRHandler.php
engine/ElementRenderer.php         engine/handlers/ReservasHandler.php
engine/MarketplaceManager.php      engine/handlers/AcademyHandler.php
engine/engine/SitemapGenerator.php engine/handlers/GitHandler.php
engine/engine/VisualComposer.php   engine/handlers/TerminalHandler.php
engine/glands/google/{gmail,drive,calendar,sheets,contacts}/
engine/vendor/fonts/*.ttf          engine/vendor/js/three/*
```

Un generador de sitemaps, un compositor visual, un cliente de Gmail, tipografias
Inter y Lora, y Three.js. Nada de eso es una base de datos. Es ACIDE/Socola
viviendo dentro del motor.

**Impacto:** hoy nadie de fuera puede adoptar AxiDB. No por calidad, sino porque
para usar la base de datos hay que tragarse un CMS, un cliente de Google y un
motor 3D. Es tambien lo que hace que `TerminalHandler` y `GitHandler` — ejecucion
de comandos — vivan detras del mismo `execute()` que un `SELECT`.

---

### 2.3 Lo que esta bien y hay que proteger

No todo es deuda. Estas decisiones son buenas y conviene no perderlas en ninguna
refactorizacion:

- **El modelo Op.** Un contrato, cinco superficies. Es la idea del proyecto y esta
  bien ejecutada.
- **`HelpEntry`.** Cada Op declara sinopsis, parametros, ejemplos en cuatro lenguajes,
  errores y Ops relacionadas. De ahi salen los docs y el CLI. Elegante y raro de ver.
- **La interfaz `Driver`.** Correctamente diseñada, con invariantes documentadas.
  Solo falta usarla.
- **`FsJsonDriver`.** tmp+rename atomico, flock con timeout, validacion de nombres.
  Es el codigo de mejor calidad del repositorio.
- **El sandbox de agentes.** Allowlist por Op, budget de pasos, audit log con actor,
  kill switch. Va por delante de lo que ofrece casi todo el mercado en PHP.
- **`Result` y `AxiException` con codigos.** Errores tipados y uniformes.

---

## 3. Diagnostico en una frase

> AxiDB tiene la arquitectura de un producto v1 y las tripas de un prototipo v0.6.
> La distancia entre ambas no es conceptual: son cinco o seis bugs concretos y
> una amputacion de alcance.

Eso es una buena noticia. Los problemas de este repositorio son **de ejecucion,
no de diseño**. Los de diseño son los que matan un proyecto; los de ejecucion se
arreglan con una lista.

---

## 4. Plan de saneamiento, por orden

No empieces por los vectores. Un motor vectorial montado sobre B1 y B2 hereda
los dos problemas y multiplica el coste de arreglarlos.

### Ola S1 — Unificar el almacenamiento (bloqueante)

**Objetivo:** una sola capa de persistencia, sin `_index.json`.

1. Que todas las Ops usen `Storage\Driver` en vez de `StorageManager`.
2. Eliminar `rebuildIndex()` y `_index.json`. `listIds()` con `scandir` ya es
   O(n) en lectura, pero sin el O(n) en **cada escritura**.
3. Migrar el versionado (`.versions/`) al driver como flag `keep_versions`.
4. `StorageManager` queda como shim de compatibilidad delegando en el driver,
   o desaparece.

**Resultado esperado:** la escritura pasa de O(N²) a O(1). Corrige B1 y B2 de golpe.
**Esfuerzo:** 2-3 dias. **Es el trabajo de mayor retorno del proyecto.**

### Ola S2 — Indices reales

**Objetivo:** que `create_index` cree algo y que `QueryEngine` lo use.

Formato propuesto, un archivo por indice:

```
STORAGE/<coleccion>/_idx/<campo>.json     { "valor": ["id1","id2"], ... }
```

Mantenimiento incremental en `writeDoc`/`deleteDoc` (solo el delta del documento
tocado, nunca la coleccion entera). `QueryEngine` consulta `_meta.indexes` y, si
el campo del WHERE tiene indice con operador `=` o `IN`, resuelve por interseccion
de listas de ids y solo lee esos documentos.

**Esfuerzo:** 3-4 dias. Convierte el "rapida" en cierto.

### Ola S3 — Coherencia de auth y suite verde

1. Sustituir `instanceof Operation ⇒ publico` por un contexto explicito:
   `$engine->asSystem()` para el modo embebido de confianza, sesion real para HTTP.
2. Hacer que `hasPermission()` deniegue de verdad, o borrar el `RoleManager` si
   no se va a usar. Lo que no puede quedarse es a medias.
3. Arreglar B4: `sql` accesible desde el SDK embebido.
4. `tests/php.ini`: detectar `extension_dir` en runtime, nunca hardcodear una ruta.
5. Actualizar las expectativas de los 41 tests de catalogo.
6. Escribir `Vault\Crypto` o borrar el test que la referencia.

**Criterio de salida:** `php tests/run.php` en verde, en menos de 30 s, en una
maquina limpia. **Esfuerzo:** 3-4 dias.

### Ola S4 — Amputacion de alcance

Separar en dos repositorios:

```
axidb/          engine/{Op,Storage,Schema,Backup,Vault,Agents}, sql/, sdk/, cli/, api/, auth/
acide/          themes, CMS, glands Google, handlers de negocio, vendor/fonts, three.js
```

`acide` depende de `axidb`; `axidb` no sabe que `acide` existe. Un `TerminalHandler`
no puede compartir dispatcher con un `SELECT`.

**Esfuerzo:** 1 semana. Es lo que convierte un proyecto personal en algo adoptable.

---

## 5. Posibilidades de crecimiento

Tres caminos. **No son compatibles entre si a corto plazo**; elegir es la decision
estrategica del proyecto.

### Camino A — Motor interno de MyLocal

Dejar de perseguir "producto" y ser la mejor capa de datos posible para tus
verticales. El alcance difuso (B7) deja de ser un problema porque nadie externo
lo va a ver.

- **Trabajo:** solo S1, S2 y S3. Unas tres semanas.
- **Riesgo:** bajo. **Retorno:** MyLocal deja de tener techo a 1.000 documentos.
- **Coste de oportunidad:** AxiDB nunca es un activo por si mismo.

### Camino B — "El SQLite documental de PHP", open source

Requiere S1 a S4 completas, mas licencia, CI publico, semver y un `composer.json`
(aunque siga funcionando sin Composer).

- **Trabajo:** 3-4 meses a ritmo sostenible.
- **Riesgo:** medio-alto. El nicho existe pero esta poco monetizado, y compites
  contra "usa SQLite".
- **Retorno:** reputacion, no ingresos directos.

### Camino C — Base de datos nativa para agentes de IA

El mas diferenciado y el que mejor encaja con lo que ya tienes construido y con
la filosofia de GestasAI.

Nadie ofrece hoy, en PHP: **almacen documental + busqueda vectorial + sandbox por
operacion + audit log por actor + embeddings locales, en una carpeta que copias
a un hosting compartido.** El discurso se escribe solo:

> La base de datos que un modelo de lenguaje puede conducir sin que te juegues
> los datos. Memoria y busqueda semantica para tu agente, en tu servidor, sin
> que un solo vector salga de tu maquina.

Eso conecta directo con "IA al servicio de las personas manteniendo la soberania
de los datos". Y el trabajo vectorial (seccion siguiente) es la pieza que falta.

- **Trabajo:** S1-S4 mas el motor vectorial. 4-5 meses.
- **Riesgo:** alto. **Retorno potencial:** el unico de los tres que puede ser un
  producto con nombre propio.

### Mi recomendacion

**A ahora, C despues.** Haz S1, S2 y S3 para desbloquear MyLocal — es trabajo que
necesitas igual, tenga AxiDB futuro comercial o no. Con el motor sano, S4 y el
vectorial se vuelven decisiones baratas y reversibles. Empezar por C sin sanear
el nucleo es construir sobre B1 y B2.

Sobre el corto plazo: tienes L1 el 1 de septiembre de 2026, dentro de tres semanas
y media. Nada de esto deberia tocar la ruta critica de ese lanzamiento. S1 solo
tiene sentido antes de L1 si MyLocal ya esta rozando el techo de escritura; si no,
va justo despues.

---

## 6. Busqueda vectorial

La especificacion completa, con el formato de almacenamiento, la API, el algoritmo
y los benchmarks que he ejecutado, esta en un documento aparte:

**[`docs/standard/vector-search.md`](standard/vector-search.md)**

Resumen del hallazgo principal: **si es viable en PHP puro y sin extensiones.**
Medido sobre 50.000 vectores de 384 dimensiones, con cuantizacion binaria y
reordenado en dos etapas: **93 ms por consulta con recall@10 del 100%**, frente
a 642 ms del coseno exacto. Y el indice que hay que tener en memoria ocupa
2,3 MB en vez de 73 MB.
