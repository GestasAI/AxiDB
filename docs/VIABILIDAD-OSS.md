# AxiDB como proyecto independiente open source

Pregunta: se puede convertir AxiDB en un proyecto propio, open source, que compita
con SQLite, MongoDB y Postgres, con tres ediciones (basica, documental, IA+vectores)?

Respuesta corta: **si, y la tesis es mejor de lo que parece — pero hay que cambiar
tres cosas del plan, y una de ellas es el formato de almacenamiento.**

Fecha: 8 de agosto de 2026. Todos los numeros son mediciones propias en PHP 8.2.28.

---

## 1. La pregunta de fondo: es PHP el techo?

Antes de hablar de estrategia hay que saber si el lenguaje aguanta. Compare la
arquitectura objetivo de AxiDB contra SQLite con el mismo dato: 10.000 documentos.

### 1.1 Un archivo por documento (lo que hace AxiDB hoy)

| Operacion | AxiDB | SQLite | Diferencia |
|---|---|---|---|
| Alta masiva de 10.000 | 10.756 ms | 23 ms | **468x** |
| 1 escritura suelta | 2,41 ms | 0,04 ms | 60x |
| 1.000 lecturas por id | 95 ms | 4 ms | 24x |
| Consulta con indice | 879 ms | 1 ms | **1.419x** |

Con estos numeros no hay proyecto. Ni open source ni de ningun tipo.

**Pero el diagnostico importante es otro:** el cuello de botella no es PHP, son
las llamadas al sistema de archivos. Diez mil `fopen`/`fwrite`/`rename` cuestan
diez mil viajes al kernel.

### 1.2 Formato empaquetado (un solo archivo, append-only)

Repeti exactamente el mismo test guardando los documentos en **un unico archivo**
con indice de desplazamientos, que es justo lo que el propio repositorio ya tiene
esbozado en `engine/Storage/PackedDriver.php`:

| Operacion | AxiDB empaquetado | SQLite | Diferencia |
|---|---|---|---|
| Alta masiva de 10.000 | **26 ms** | 23 ms | **1,1x — paridad** |
| 1 escritura suelta | **0,19 ms** | 0,04 ms | 5x |
| 1 escritura con `fsync` durable | 2,51 ms | (equivalente) | comparable |
| 1.000 lecturas por id | **2 ms** | 4 ms | **0,5x — AxiDB gana** |
| Escaneo completo + filtro | 12 ms | 1 ms | 12x |
| Tamaño en disco | 2,1 MB | similar | — |

### 1.3 Conclusion

> **PHP no es el techo. El formato "un archivo por documento" lo era.**

Con almacenamiento empaquetado, AxiDB queda en el mismo orden de magnitud que
SQLite, empata en escritura masiva y **es el doble de rapido leyendo por id**.
Solo pierde de forma clara en escaneos completos — que es exactamente lo que los
indices secundarios existen para evitar.

Eso convierte la pregunta "es posible?" en un si tecnico. El resto es estrategia.

Detalle no menor: `fsync()` existe en PHP desde la version 8.1. Se puede ofrecer
durabilidad real, garantizada ante corte de corriente, por 2,5 ms. Sin eso, un
proyecto que se llame "base de datos" no es creible.

---

## 2. Correccion 1: las tres versiones no pueden ser tres productos

Tu planteamiento — basica para blogs, media para comercio, max para IA — es
**acertado como experiencia de usuario y letal como ingenieria** si se implementa
como tres codigos.

Tres versiones significan tres bases de codigo, tres suites de tests, tres juegos
de documentacion y tres caminos de migracion entre ellas. Para un desarrollador
en solitario eso no es un plan, es la forma mas rapida de abandonar el proyecto.

Mira lo que hacen los que ya ganaron:

| Motor | Como ofrece "tamaños" distintos |
|---|---|
| SQLite | **Un** codigo. Opciones de compilacion (`SQLITE_OMIT_*`) |
| PostgreSQL | **Un** codigo. Extensiones (`pgvector`, `postgis`) |
| Redis | **Un** codigo. Modulos cargables |

Ninguno mantiene tres bases de datos distintas.

### El modelo correcto: un motor, tres perfiles

```
axidb/
  core/        Op model, Packed storage, indices, AxiSQL, backups   [siempre]
  modules/
    docs/      schema, relaciones, transacciones, vault, jobs       [perfil docs]
    ai/        vectores, embeddings, agentes, sandbox, audit        [perfil ai]
```

Y un solo archivo de configuracion:

```json
{ "profile": "core" }
```

Un perfil es un **preset de modulos activos, defaults y documentacion**. No una
bifurcacion del codigo. Misma suite de tests, mismo release, mismo semver.

### Por que esto es justamente lo que pedias

Escribiste algo que es, sin que suene exagerado, la mejor idea del mensaje:

> "utilizaria siempre postgres si pudiera estar en la misma aplicacion... y la
> usaria si se adaptara en tamaño o necesidad de proyecto"

Ese es el hueco real del mercado, y nadie lo ocupa:

- **Postgres no sabe encogerse.** Es potentisimo y necesita un servidor. No lo
  pones en un blog de hosting compartido.
- **SQLite no sabe crecer.** Perfecto para el blog, pero para vectores necesitas
  cargar `sqlite-vec`, una extension en C que en hosting compartido suele estar
  prohibida. Y para agentes no hay nada.
- **Mongo esta en medio** y tambien exige servidor mas una extension de PHP en C.

Un motor que va del blog a la IA **cambiando una linea de configuracion, sin volcado,
sin migracion, sin cambiar una sola consulta**, es una propuesta que hoy no existe.

Eso no es "otra base de datos". Es *la base de datos que se adapta al proyecto en
vez de obligar al proyecto a adaptarse*. Ese es el titular, y es defendible.

Con tres productos separados, esa promesa desaparece: pasar de basica a media seria
una migracion, que es exactamente el dolor que dices querer evitar.

---

## 3. Correccion 2: el formato empaquetado va primero

La seccion 1 lo demuestra. `PackedDriver` deja de ser un stub reservado para "v2"
y pasa a ser **la pieza que hace viable el proyecto entero**. Sin el, no hay nada
que discutir.

Diseño minimo:

```
STORAGE/<coleccion>/
  data.axi        log append-only, un documento JSON por linea
  data.ixlog      indice append-only: id,offset,longitud
  data.idx        snapshot compactado del indice (se regenera del ixlog)
  _idx/<campo>    indices secundarios
  _vec/           vectores (perfil ai)
```

Decisiones que los numeros ya respaldan:

- **Append-only.** Escribir es añadir al final. Nunca reescribir el archivo. Ese
  es el salto de 2,41 ms a 0,19 ms.
- **El indice tambien es un log.** Reescribir el indice entero en cada alta costaba
  32 ms; como log append-only cuesta 0,19 ms. Se compacta cuando crece.
- **Compactacion por umbral.** Cuando el espacio muerto supera el 30%, se reescribe.
  Mismo criterio que cualquier motor LSM.
- **`fsync` opcional por coleccion.** `durability: "fast" | "safe"`. El usuario
  elige entre 0,19 ms y 2,51 ms sabiendo lo que compra.

Y algo que ganas gratis y que ninguno de tus competidores tiene: **el archivo sigue
siendo texto legible**. Un `data.axi` se puede abrir, leer, diff-ear en git y
reparar a mano. Para un blog o un comercio pequeño eso vale mas de lo que parece.

---

## 4. Correccion 3: contra quien compites de verdad

Aqui toca ser franco, porque de esto depende que no dediques un año a una pelea
que no se puede ganar.

### Contra SQLite, de frente: se pierde

SQLite lleva 25 años, tiene una relacion de ~600 lineas de test por linea de codigo,
esta en dominio publico y corre en miles de millones de dispositivos. Es probablemente
el software mas probado del mundo. No vas a ganar en fiabilidad, ni en rendimiento,
ni en confianza.

**Nunca posiciones AxiDB como "mejor que SQLite".** Te desmontan el primer dia.

### Pero SQLite tiene tres huecos reales

1. **Es relacional.** Guardar documentos exige gimnasia con `json_extract()` y
   columnas generadas. En una app PHP moderna el dato ya es un array asociativo.
2. **Los vectores requieren cargar una extension en C.** `sqlite-vec` es excelente,
   pero `PDO::loadExtension` esta desactivado en la mayoria del hosting compartido.
3. **No tiene nada para agentes.** Ni sandbox por operacion, ni audit por actor,
   ni catalogo de herramientas autodescrito.

### El nicho, definido con precision

> Aplicaciones PHP que necesitan documentos, busqueda semantica y agentes de IA
> **sin poder instalar nada en el servidor**.

Ese nicho no es teorico: es la forma del hosting donde vive la mayor parte de
WordPress y de las pymes españolas. Es tu propio mercado de MyLocal.

Tabla honesta:

| | Necesita servidor | Necesita extension C | Documentos nativos | Vectores | Agentes |
|---|---|---|---|---|---|
| SQLite | No | Si, para vectores | No | Con extension | No |
| MongoDB | **Si** | **Si** | Si | Si | No |
| Postgres | **Si** | No (pgvector es SQL) | Parcial (JSONB) | Si | No |
| **AxiDB** | **No** | **No** | **Si** | **Si** | **Si** |

Esa ultima fila es un argumento de venta real. No dice "somos mejores", dice
"somos los unicos que funcionan donde tu estas".

### Expectativa realista de adopcion

No va a destronar a nadie. Puede, con trabajo sostenido y suerte, ser la eleccion
obvia para una clase concreta de proyectos y acumular unos miles de estrellas.
Eso ya seria un exito notable para un proyecto en solitario, y un activo de
reputacion real para GestasAI.

---

## 5. Las tres ediciones, diseñadas

### Perfil `core` — "para un blog"

- Modelo Op, CRUD, PackedDriver, indices secundarios, AxiSQL basico, backups, CLI
- Objetivo: hasta ~50.000 documentos
- Frase: *el SQLite de los documentos JSON, sin instalar nada*

### Perfil `docs` — "para un comercio"

- Todo lo anterior mas: validacion de esquema, relaciones y joins, transacciones
  multi-documento, cifrado por coleccion (vault), multi-tenancy, cola de trabajos
- Objetivo: hasta ~500.000 documentos
- Frase: *Mongo sin servidor*

### Perfil `ai` — "para IA"

- Todo lo anterior mas: busqueda vectorial, embeddings locales, kernel de agentes,
  sandbox por Op, audit log por actor
- Objetivo: hasta ~200.000 vectores (ver [`standard/vector-search.md`](standard/vector-search.md))
- Frase: *la base de datos que un modelo puede conducir sin que te juegues los datos*

**El camino entre perfiles es cambiar una linea.** Sin volcado, sin reimportar,
sin tocar una consulta. Esa frase es el producto.

---

## 6. El listado de credibilidad

La gente no confia sus datos a un proyecto de una persona. Es racional. Estos son
los requisitos de entrada, no adornos, y hoy **ninguno esta cumplido** (verificado
en el repositorio):

| Requisito | Estado hoy |
|---|---|
| `LICENSE` | **No existe.** Sin licencia, legalmente nadie puede usarlo |
| `composer.json` | No existe (aunque siga funcionando sin Composer) |
| CI publico | No hay `.github/workflows` |
| Suite en verde | 465 pasan / **96 fallan** |
| Test de consistencia ante fallo | No existe |
| Semver y changelog | Changelog si, disciplina de version por validar |
| Benchmark publicado y reproducible | No existe |

El critico de verdad es el quinto: **que pasa si se va la luz a mitad de una
escritura?** Toda base de datos seria responde a eso con un banco de pruebas que
mata el proceso en momentos aleatorios y verifica que el archivo sobrevive. Sin
esa prueba, "base de datos" es una palabra grande.

La buena noticia: con formato append-only y `fsync`, la respuesta es facil de
construir. Un log al que solo se añade es lo mas robusto que existe ante un corte:
en el peor caso la ultima linea queda incompleta y se descarta al arrancar.

---

## 7. Esfuerzo real y conflicto de calendario

| Fase | Trabajo | Duracion |
|---|---|---|
| S1-S3 | Sanear el nucleo (ver [`EVALUACION-2026-08.md`](EVALUACION-2026-08.md)) | 3 semanas |
| P1 | PackedDriver como motor por defecto | 2 semanas |
| P2 | Separar AxiDB de ACIDE/Socola (ola S4) | 1 semana |
| P3 | Modulos y perfiles | 2 semanas |
| P4 | Listado de credibilidad completo | 2 semanas |
| P5 | Perfil `ai`: vectores y embeddings | 3 semanas |
| **Total** | **hasta un 1.0 defendible** | **~13 semanas a dedicacion completa** |

A tiempo parcial, que es lo realista: **de 8 a 12 meses**.

### El conflicto que hay que nombrar

Tienes L1 el 1 de septiembre de 2026 — dentro de tres semanas y media —, L2 el
1 de diciembre y L3 el 1 de marzo de 2027 con una obligacion legal detras
(Verifactu, 1 de julio de 2027). Eso son compromisos con fecha. AxiDB open source
es un proyecto que te apetece. No compiten en importancia, pero si compiten por
las mismas horas.

Ahora bien, hay una coincidencia afortunada: **S1, S2 y P1 los necesitas igualmente
para MyLocal.** Hoy MyLocal tiene un techo de mil documentos por coleccion. Ese
trabajo no es del proyecto open source, es tuyo, y hay que hacerlo tenga AxiDB
futuro publico o no.

### Secuencia recomendada

1. **Ahora hasta L1 (1 sep):** no tocar AxiDB. Cerrar el lanzamiento.
2. **Septiembre-octubre:** S1, S2, S3 y P1. Es mantenimiento de MyLocal, y de paso
   deja el nucleo listo. Al terminar, mide otra vez y comprueba la paridad con SQLite
   en tu propio dato real.
3. **Noviembre en adelante, en paralelo a L2:** P2 y P3, la separacion y los perfiles.
   Aqui es donde decides de verdad si sigues, y lo decides con un motor ya sano y
   con datos en la mano en vez de con una intuicion.
4. **2027, tras L3:** publicacion, credibilidad y perfil `ai`.

El punto 3 es importante: la decision de lanzar open source no hay que tomarla hoy.
Todo el trabajo previo es util en ambos escenarios. Es una opcion que compras barata.

---

## 8. Respuesta directa a tu pregunta

**Se puede crear AxiDB como proyecto independiente open source?**
Si. Tecnicamente esta demostrado en la seccion 1: con formato empaquetado, PHP puro
alcanza a SQLite en escritura masiva y lo supera en lectura por id.

**Puede competir con SQLite, Mongo y Postgres?**
No de frente, y no hace falta. Puede ganar en un eje donde los tres pierden:
funcionar sin servidor y sin extensiones en C, con documentos, vectores y agentes
en la misma carpeta.

**Sirven las tres ediciones basica / media / max?**
La idea si; tres productos no. Un motor, tres perfiles, y el paso entre ellos
como cambio de configuracion. Asi ademas cumples lo que de verdad querias: un
motor que se adapta al tamaño del proyecto en vez de obligarte a migrar.

**Con IA, vectores y agentes?**
Si, medido y especificado. 50.000 vectores en 93 ms con recall del 100%, en PHP
puro y sin extensiones.

**Cuanto cuesta?**
8 a 12 meses a tiempo parcial hasta un 1.0 defendible. Las primeras 5 semanas las
necesitas igual para MyLocal, asi que el riesgo inicial es practicamente cero.

**Lo mas importante que hay que corregir del plan?**
El formato de almacenamiento. Todo lo demas se puede decidir despues; eso no.
