# Changelog

Todo cambio que se note desde fuera queda escrito aqui.

Formato [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), versionado
[SemVer](https://semver.org/spec/v2.0.0.html).

**Que significa el 0.x:** la API todavia puede cambiar. Al llegar a 1.0.0 deja de
poder hacerlo sin subir el numero mayor. Preferimos decirlo asi a poner un 1.0 y
romperlo en la version siguiente.

---

## [0.6.1] — 2026-08-09

Lo que recibe quien clona el repositorio.

### Corregido

- **`HAVING` con un alias del `SELECT` devolvia cero filas, sin error.**

  ```sql
  SELECT depto, SUM(salario) AS coste FROM empleados
  GROUP BY depto HAVING coste > 60000     -- antes: vacio. Ahora: los grupos caros
  ```

  Lo peor no era la limitacion, sino que el mismo alias SI funcionaba en
  `ORDER BY`: la respuesta cambiaba segun donde escribieras el nombre. Se
  resuelve como en MySQL y SQLite —primero el alias, despues el campo del
  documento— y un resultado vacio vuelve a significar "no hay datos" y no "no te
  he entendido".

- **Cuatro de los siete ejemplos no arrancaban.** `hello.php`, `portfolio`,
  `notas` y `remote-client` pedian un archivo del motor anterior que ya no
  existe. Se entregaban rotos porque ningun test los ejecutaba.

- **Enlaces de la documentacion que no llevaban a ninguna parte**, incluido uno
  que solo resolvia dentro del proyecto donde nacio AxiDB.

### Cambiado

- **Los ejemplos, rehechos.** Cuatro, numerados, y cada uno enseña una parte del
  motor: [`01-almacen`](examples/01-almacen/) (indices, `UNIQUE`, agregados),
  [`02-empleados`](examples/02-empleados/) (esquema, `JOIN`, `HAVING`),
  [`03-pedidos`](examples/03-pedidos/) (transacciones, `LEFT JOIN`,
  subconsultas) y [`04-puente-http`](examples/04-puente-http/) (la base de datos
  desde otro proceso). Sin paginas ni hojas de estilo: lo que se enseña son los
  datos.

- **Fuera tres documentos internos** que hablaban del proyecto de origen y de
  estrategia de empresa. No son parte de un motor de base de datos.

### Añadido

- **`test_ejemplos.php`**: ejecuta cada ejemplo como lo haria quien clona el
  repositorio y comprueba que los numeros que imprime son los correctos. Es lo
  que faltaba para que los cuatro rotos no hubieran llegado nunca a entregarse.

- El test de instalacion limpia y el de documentacion **descubren** los ejemplos
  y las guias en vez de leerlos de una lista escrita a mano. Tres listas de esas
  se habian quedado viejas sin que nadie se enterara.

---

## [0.6.0] — 2026-08-09

Perfiles: declarar para que es esta base de datos.

### Añadido

- **Tres perfiles: `core`, `docs` y `ai`.** Guia:
  [17-perfiles](docs/guide/17-perfiles.md).

  ```php
  $db = new Axi\Core\Db('./datos', ['perfil' => 'core']);
  ```

  Acumulativos, y sin declarar ninguno esta todo disponible: una instalacion que
  actualiza no puede empezar a fallar por una funcion que ya usaba.

  Un perfil **no carga menos codigo** —el autoloader ya es perezoso, asi que eso
  seria teatro— sino que reduce la superficie que hay que aprender y avisa
  cuando el proyecto se sale de lo que dijo ser. El error trae las tres cosas
  para resolverlo sin buscar nada: que se ha intentado, en que perfil vive y la
  linea exacta que hay que cambiar.

  Cambiar de perfil es cambiar una linea. Hay un test que lleva un blog a tienda
  y luego a busqueda por significado comprobando en cada salto que los datos
  siguen intactos.

  El plan listaba tambien "colas" en el perfil `docs`. **No existen**, y por eso
  no estan en la tabla.
- `axidb.json` acepta `perfil` y `clave`, ademas de `data` y `durable`.

### Corregido

- **Un `is_file` cacheado hacia creer que una coleccion no tenia vectores.**
  `Files::tamaño()` limpiaba la cache de stat de PHP —con un comentario que
  explicaba por que— y `Files::hay()` no. Y `hay()` es justo lo que decide si
  una coleccion tiene vectores activados.

  Solo fallaba con la maquina cargada, y siempre igual: "esta coleccion no tiene
  vectores activados" sobre una que si los tenia. Lo caza el gate, no la suite
  suelta: cinco ejecuciones seguidas del test en verde y la sexta, bajo carga, en
  rojo. Un fallo que depende de la cache del interprete y no de los datos.
- **`similar()` no estaba protegido por el perfil.** Solo lo estaba `vectores()`,
  la activacion: con perfil `core` se podia seguir buscando por significado. Lo
  destapo probar el camino de vuelta —abrir con `core` una base montada en `ai`—,
  que era el que faltaba por probar. Ahora estan protegidas las cuatro puertas:
  `vectores()`, `similar()`, `vectorial()` y `hibrida()`, mas `agente()`.
- **Activar vectores sobre una coleccion con documentos no indexaba los que ya
  habia.** Solo entraban los escritos despues: `similar()` no encontraba los
  anteriores y no habia ningun error que lo dijera. El mismo fallo silencioso
  que tuvo `cifrar()`. Ahora se indexan al activar, y reactivar reindexa, que es
  tambien la forma de reparar un indice vectorial incompleto.
- **Las constantes en traits son de PHP 8.2** y aqui se soporta desde 8.1. En la
  maquina de desarrollo hay 8.2, asi que compilaba sin decir nada; el motor no
  arrancaba en 8.1. Lo caza la CI, que corre las cuatro versiones.

### Tests que evitan repetir errores

- Las guias se descubren solas en `test_readme`, ya no van en una lista escrita a
  mano: añadir una guia y olvidarse de apuntarla dejaba sus ejemplos sin ejecutar,
  y una guia sin comprobar es justo la que se queda desfasada.
- **Ningun byte de control en el nucleo.** Un `	rim` convertido en tabulador o un
  `rray_map` en 0x07 al editar con herramientas. Cuando rompe la sintaxis, PHP
  protesta; lo peligroso es cuando no la rompe, porque cambia una cadena en
  silencio. Va lo primero del test: colocado al final no se ejecutaba, porque el
  byte corrupto mata el autoloader antes de llegar.
- Bajar de perfil tiene su propia seccion: los datos siguen, **lo ya declarado se
  sigue cumpliendo** —apagar la unicidad al bajar de perfil dejaria entrar
  duplicados sin que nadie lo pidiera— y lo que ya no esta en el perfil se niega
  con un error, no se ignora.
- Y que el perfil solo se consulte en las puertas de entrada: si alguien mete un
  `if` de perfil dentro de Storage o Query, salta. Un motor, no tres.

- `test_agnostico` comprueba que el nucleo no use construcciones posteriores a
  lo que promete `composer.json`: constantes en traits, clases `readonly`,
  `true`/`false` como tipo suelto, `#[Override]`, `json_validate`, `array_find`.
- Y que **ningun tipo declarado apunte a una clase inexistente**. Un metodo
  dentro de un trait resuelve sus tipos en el espacio de nombres del trait: poner
  `: Index` en `Axi\Core\Fachada\ConIndices` sin importarlo apunta a
  `Axi\Core\Fachada\Index`, que no existe. `php -l` no lo ve; solo revienta al
  llamar al metodo.

---

## [0.5.0] — 2026-08-09

La ola A8: completitud. Transacciones, JOIN, AxiSQL entero, esquema,
caducidad, copias, cifrado y observabilidad. Y siete fallos que estaban
ahi desde antes, encontrados por el camino.

### Añadido

- **Cifrado por coleccion con AES-256-GCM.** `new Db($dir, ['clave' => '...'])` y
  `$db->encrypt('clientes')`. Va por encima de los drivers, asi que funciona igual
  en `fs` y en `packed`, y las migraciones no necesitan la clave.
  Guia: [10-cifrado](docs/guide/10-cifrado.md).
- La clave se deriva con PBKDF2-SHA256 y 210.000 vueltas. Una contraseña
  equivocada se detecta al abrir y lo dice, en vez de hablar de datos corruptos.
- Cada bloque va atado a su coleccion y a su id: copiar el archivo de otro
  documento encima del propio no cuela aunque la clave sea la misma.
- En una coleccion cifrada los valores indexados se guardan como hash. Antes, un
  indice por `email` habria escrito `_idx/email/ana@ejemplo.com.json` y publicado
  como nombre de archivo el dato recien cifrado.
- Se rechaza activar vectores sobre una coleccion cifrada, y cifrar una que ya
  tiene vectores: de un embedding se reconstruye aproximadamente el texto.

- **Tres modos de precision en la busqueda vectorial.**
  `$db->enableVectors('articulos', ['precision' => 'equilibrada'])`, y tambien por
  consulta suelta: `$db->similar($col, $texto, 10, null, 'exacta')`.

  Medido con 10.000 vectores de 768 dimensiones, recall@10 sobre el caso feo
  —vectores uniformemente aleatorios, que ningun modelo genera—:

  | modo | embeddings reales | aleatorios | ms |
  |---|---|---|---|
  | `rapida` (200 candidatos, por defecto) | 100% | 84% | 41 |
  | `equilibrada` (2000) | 100% | 100% | 107 |
  | `exacta` (sin criba) | 100% | 100% | 373 |

  `rapida` sigue siendo el defecto y una coleccion que ya existia no cambia de
  comportamiento: un manifiesto sin el campo se lee como `rapida`.

  No hay modo int8. Se penso y se descarto al medir: subir candidatos sobre la
  criba binaria que ya existe llega al mismo 100% sin un byte de disco nuevo, y
  en PHP un producto escalar de 768 enteros por vector habria salido mas lento
  que el Hamming, que se resuelve con tabla de consulta.

- **JOIN y subconsultas.** `INNER JOIN`, `LEFT JOIN` con alias, y
  `$db->find('pedidos')->join('clientes', 'cli', 'id')` desde la API. Mas
  `IN (SELECT ...)`, `NOT IN (SELECT ...)` y `EXISTS`. Guia:
  [15-relaciones](docs/guide/15-relaciones.md).

  Hash join: coste `izquierda + derecha`, no `izquierda x derecha`. Los campos
  de la derecha llevan siempre su prefijo —`clientes.nombre`— asi que dos
  colecciones con un campo del mismo nombre no se pisan nunca.

  Dos cosas dichas por adelantado: la coleccion de la derecha entra entera en
  memoria, y con JOIN el filtro no usa indices —podria descartar documentos que
  la union habria traido—. `EXPLAIN` lo dice.

  No hay subconsultas correlacionadas: obligarian a una consulta completa por
  documento. Se rechazan con un mensaje claro en vez de aceptarlas y que alguien
  descubra el coste en produccion.
- **Observabilidad.** `$db->describe()`, `$db->stats()` y
  `$db->checkup()`. Guia: [16-salud](docs/guide/16-salud.md).

  `revision()` esta pensada para un cron o un panel: devuelve avisos con su
  gravedad y con QUE HACER. Vigila indices a los que les faltan entradas —el
  fallo invisible: `by()` no encuentra documentos que existen—, reservas de
  unicidad sin dueño, indices heredados que no se pueden mantener y espacio
  muerto en el formato empaquetado.
- **AxiSQL completo.** Guia: [14-axisql](docs/guide/14-axisql.md).

  Agregados (`COUNT`, `SUM`, `AVG`, `MIN`, `MAX`) con `GROUP BY` y `HAVING`;
  `DISTINCT`; alias con y sin `AS`; expresiones aritmeticas con la precedencia
  de siempre; `BETWEEN`, `NOT BETWEEN` y `NOT LIKE`; comparar dos campos entre
  si (`WHERE precio > coste * 2`).

  Funciones de texto, numero y fecha. Las de fecha eran las que mas falta
  hacian: `WHERE MES(fecha) = 4` antes obligaba a sacar los documentos a PHP.

  `INSERT` de varias filas, `ON DUPLICATE UPDATE`, `SET n = n + 1` —calculado
  sobre CADA documento— y `LIMIT` en `UPDATE` y `DELETE`.

  `SHOW COLLECTIONS`, `SHOW INDEXES`, `DESCRIBE`, `ALTER COLLECTION` (renombrar
  la coleccion o añadir, quitar y renombrar un campo) y `CREATE VIEW`.

  Con los nulos se hace lo que hace SQL, y no por imitarlo: `COUNT(campo)` no
  cuenta los vacios, `AVG` divide entre los que tenian valor, y sumar a un campo
  que no existe da null en vez de un cero disfrazado de dato.

### Corregido

- **Un `INSERT` con columna `id` no usaba ese id.** Se guardaba como un campo
  mas y el documento se creaba con un id aleatorio. Y la version con
  `ON DUPLICATE UPDATE` si lo respetaba, asi que el mismo SQL hacia dos cosas
  distintas segun como acabara la frase.
- **Filtrar sobre una vista se ignoraba.** `SELECT * FROM vista WHERE total > 260`
  devolvia tambien los de 250. Una vista no pasa por el motor de consultas, y el
  WHERE de fuera se estaba perdiendo por el camino.
- **El test de rendimiento vectorial media la cache del disco, no el motor.**
  545 ms la primera ejecucion y 143 las siguientes, en la misma maquina: se
  ponia rojo o verde segun lo que hubiera corrido antes. Ahora calienta antes de
  medir y enseña el numero en frio aparte, sin convertirlo en un rojo.

### Añadido

- **Copias de seguridad, completas e incrementales.** `$db->copiar('./copias')`,
  `$db->backups('./copias')` y `$db->restore($archivo)`. Guia:
  [13-copias](docs/guide/13-copias.md).

  Se copia el directorio entero —documentos, ajustes, indices y vectores— porque
  el criterio de "esto ya se puede reconstruir" es justamente el que falla el dia
  que hace falta la copia.

  Restaurar SUSTITUYE: lo que la copia no tiene se borra. Y una copia dañada se
  detecta ANTES de tocar los datos vivos: se leen y comprueban todos los sha1, y
  solo despues se escribe. Restaurar medio conjunto corrupto encima de los buenos
  es peor que no tener copia.

  **Formato propio, sin `ext-zip`.** El del motor anterior usaba `ZipArchive`,
  que es una extension opcional y que en la maquina de desarrollo NO esta
  instalada: aquel codigo no habria podido hacer una sola copia. El formato nuevo
  esta documentado y se puede abrir a mano.
- **Exportar e importar en JSON y CSV.** `$db->export('clientes', './c.csv')` y
  `$db->import('clientes', './c.csv')`. La cabecera del CSV es la union de
  todos los campos, no los del primer documento. Importar pasa por el esquema,
  la unicidad y los indices: no es una puerta trasera.
- **Esquema opcional por coleccion.** `$db->defineSchema('clientes', [...])`
  con campos obligatorios, tipos y valores por defecto. Guia:
  [12-reglas](docs/guide/12-reglas.md).

  Un campo que no se declara se guarda igual: esto pone reglas donde hacen
  falta, no cierra la coleccion. Las actualizaciones parciales se validan
  ENTERAS —vaciar un obligatorio no se veria mirando solo lo que cambia— y el
  esquema se valida al declararlo, no al usarlo.
- **Caducidad por coleccion.** `$db->defineTtl('sesiones', 3600)`.

  Un documento vencido deja de existir por TODAS las puertas: `get`, `exists`,
  `count`, `all`, `find` e `ids`. No se devuelve aunque su archivo siga en el
  disco hasta el proximo `sweep()`. Se hizo asi y no al reves porque borrar en
  una limpieza periodica convertiria "caduca en una hora" en "caduca en una
  hora, o cuando pase el barrendero".

  Se cuenta desde la ultima escritura, asi que modificar un documento le da
  cuerda. `count()` deja de ser instantaneo en `packed` solo en las colecciones
  que declaran caducidad.
- **Transacciones.** `$db->transaction(function ($tx) { ... })`: varias
  escrituras que ocurren enteras o no ocurren, tambien entre colecciones y
  tambien si se va la luz en mitad. Guia:
  [11-transacciones](docs/guide/11-transacciones.md).

  Diario de intenciones con fsync, marca de confirmacion y recuperacion al
  abrir. La marca decide sin ambiguedad: sin ella la transaccion no ocurrio y se
  descarta; con ella ocurrio y se termina de aplicar. Hay un test que mata el
  proceso doce veces en mitad de una transferencia y comprueba que la suma de
  las dos cuentas nunca cambia.

  Dentro se lee lo propio: `$tx->get()` ve lo que `$tx->update()` acaba de
  escribir. **Aborta la actualizacion perdida**: si alguien toca por debajo un
  documento que la transaccion habia leido, se para con un error en vez de
  escribir encima.

  Lo que NO da: aislamiento. Mientras se aplican los cambios, un lector puede
  ver la mitad. Se dice en la guia en vez de dejar que se suponga.
- **`BEGIN` / `COMMIT` / `ROLLBACK` en AxiSQL**, con `BEGIN TRANSACTION` tambien
  valido. `SELECT`, `UPDATE` y `DELETE` dentro de una transaccion ven lo
  pendiente, con filtros, orden, `LIMIT` y campos elegidos: se resolvio dandole
  a `Query` una fuente alternativa de documentos, no reimplementandola. Lo unico
  que cambia es que ahi dentro no se usan indices —viven en el disco y no saben
  nada de lo que no se ha confirmado— y `EXPLAIN` lo dice.
- `$db->indice()` da acceso al indice secundario, como ya lo daban `storage()` y
  `vectorial()`. `verifyIndexes()` avisa ademas de los indices heredados cuyo
  campo no se puede saber, en vez de saltarselos en silencio.
- **`UNIQUE` que se cumple de verdad.** `CREATE UNIQUE INDEX ON clientes (email)`
  —o `$db->unique('clientes', 'email')`— rechaza el duplicado en cada alta y cada
  modificacion, no solo al crear el indice.

  El valor se reserva bajo el cerrojo de su propio archivo ANTES de escribir el
  documento, asi que dos procesos que insertan el mismo correo a la vez no pasan
  los dos: hay un test con ocho procesos simultaneos y entra exactamente uno.

  Sin valor no es compartir valor: varios documentos sin ese campo conviven,
  igual que con NULL en SQL. Quitar el indice quita la unicidad.
- `$db->indice()` da acceso al indice secundario, como ya lo daban `storage()` y
  `vectorial()` a los otros dos subsistemas.
- `verifyIndexes()` cuenta ahora las entradas que **sobran**, no solo las que
  faltan. Hacen falta porque una reserva de un campo unico cuyo documento nunca
  llego a escribirse deja el valor cogido sin dueño.

### Corregido

- **El indice de un campo con mayusculas dejaba de mantenerse.** El peor fallo
  encontrado hasta ahora, y llevaba ahi desde el principio.

  El directorio del indice va en minusculas y con una marca —`createdAt` se
  guarda como `createdat~4f2a1c9b`— para que la carpeta sea portable entre
  sistemas de archivos que no distinguen mayusculas. Pero `fields()` devolvia el
  nombre del DIRECTORIO, y `put()` lo usa para mantener el indice al dia:
  buscaba `$documento['createdat~4f2a1c9b']`, que no existe en ningun documento.

  Resultado: el indice de cualquier campo con una mayuscula se construia una vez
  y no se actualizaba nunca mas. Los documentos nuevos no entraban y `by()` no
  los encontraba, sin un solo error por el camino. Y `verifyIndexes()` decia que
  todo estaba bien, porque contaba cero documentos con ese campo.

  Afecta a nombres corrientes: `createdAt`, `userID`, `localID`. Ahora el indice
  anota su campo real y `fields()` lo devuelve. Los indices que ya existan de un
  campo con mayusculas hay que reconstruirlos —`reindex()`— porque su nombre
  original no se puede recuperar del directorio; se listan aparte en vez de
  darlos por buenos.
- **`storage()->ids()` devolvia mal el orden.** Ordenaba con `sort()` a secas, y
  PHP compara como numeros dos cadenas que lo parezcan: un id de 24 digitos no
  cabe en un float, asi que ids distintos salian iguales, y uno cuyo sufijo
  empezara por `e` —`2026...00955e8814`— se leia como notacion cientifica y valia
  infinito. Ahora `SORT_STRING`. Lo destapo un test que fallaba una de cada seis
  veces.
- **Cifrar una coleccion `packed` que ya tenia datos dejaba el texto en claro en
  el log.** El formato empaquetado solo añade, asi que la version sin cifrar
  seguia unos bytes por detras de la cifrada. Ahora se compacta al cifrar.
- El escaner de credenciales de `test_publicacion` saltaba con la palabra
  "secreto" en un comentario en castellano. Ahora exige la forma de una
  credencial de verdad: la palabra, un igual y un literal entrecomillado.

### Sabido y dicho

- El cifrado necesita la extension `openssl`, la unica parte de AxiDB que no
  funciona solo con `json`. Sin ella, el resto sigue igual y `cifrar()` lo dice.
- `id`, `_version`, `_createdAt` y `_updatedAt` quedan en claro: el motor los
  necesita para localizar y versionar sin abrir el contenido.

---

## [0.3.0] — 2026-08-09

Busqueda por significado y agentes con permisos.

### Añadido

- **Busqueda vectorial.** `$db->enableVectors('articulos', ['auto' => ['titulo']])` y
  a partir de ahi cada `insert` genera su vector solo. `$db->similar()` devuelve
  los mas parecidos por significado, no por palabras.
- Dos pasadas: criba binaria de 1 bit por dimension sobre todos, coseno exacto
  sobre los doscientos candidatos. **45 ms sobre 10.000 vectores con recall@10
  del 100%**, y 6 MB de memoria.
- Cinco generadores de embeddings: `Hash` (local, sin red, por defecto), y
  `ollama`, `openai`, `gemini` y `voyage`. **Anthropic no publica API de
  embeddings; para su ecosistema, Voyage.**
- `ORDER BY EMBEDDING <-> 'texto'` en AxiSQL, con `EXPLAIN` y con el `WHERE`
  aplicado ANTES de buscar, aprovechando los indices de siempre.
- Bajas sin mover datos y compactacion por umbral, igual que en el formato
  empaquetado.
- **Agentes**: una vista de la base de datos con lista de operaciones y
  colecciones permitidas, rastro de todo lo que intentan —tambien lo rechazado—
  y un boton de parada que funciona entre procesos.
- Las sentencias SQL de un agente se analizan antes de ejecutarse: un agente de
  solo lectura no borra una coleccion con un `DELETE`.
- Guia [09-vectores](docs/guide/09-vectores.md), con sus ejemplos ejecutandose
  dentro de la suite.

### Corregido

- **Indexar era cuadratico.** Cada alta releia el archivo de ids entero para ver
  si el documento ya tenia vector: 19,7 ms por vector, 196 segundos para 10.000.
  No era el tamaño del archivo sino que leerlo mientras el propio proceso lo
  tiene abierto para escribir cuesta carisimo en Windows. Con el mapa en memoria:
  **0,27 ms, 80 veces mas rapido.**
- `Storage::poner()` no comprobaba las dimensiones: un vector corto descolocaba
  todos los registros posteriores y no se notaba hasta la siguiente busqueda.
- `Sweeper::rmrf()` no podia borrar archivos de solo lectura, asi que borrar una
  coleccion que tuviera alguno fallaba a medias y en silencio.

### Nota sobre el recall

El 100% se mide con embeddings realistas, que se agrupan por significado. Con
vectores uniformemente aleatorios —que ningun modelo genera— la criba binaria
baja al 74%. Se publica el numero malo tambien.

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
- Ejemplo del puente HTTP: la aplicacion entera desde el navegador con cuatro
  lineas de PHP. Hoy vive en [examples/04-puente-http](examples/04-puente-http/),
  reescrito sin pantalla.
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

La guia `docs/standard/migration-socola.md` que se citaba aqui describia la
migracion desde el motor anterior y dejo de entregarse cuando ese motor se
retiro. Se conserva junto a el, fuera del paquete.

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
