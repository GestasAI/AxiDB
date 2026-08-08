# AxiDB — Especificacion de busqueda vectorial

Estado: propuesta de diseño. No implementado.
Fecha: 8 de agosto de 2026
Requisito previo: olas S1 y S2 de [`docs/EVALUACION-2026-08.md`](../EVALUACION-2026-08.md).

---

## 1. Que problema resuelve

Una consulta `WHERE nombre LIKE '%gluten%'` encuentra la palabra "gluten". Una
consulta vectorial encuentra "apto para celiacos", "sin trigo" y "harina de arroz",
porque compara **significado**, no caracteres.

El mecanismo: un modelo de embeddings convierte cada texto en un vector de N
numeros (768 con `nomic-embed-text`, 384 con `all-MiniLM-L6-v2`). Textos con
sentido parecido caen cerca en ese espacio. Buscar es calcular la distancia entre
el vector de la pregunta y los de los documentos, y devolver los mas proximos.

Casos concretos en tu ecosistema:

| Caso | Coleccion | Consulta tipo |
|---|---|---|
| Carta semantica | `productos` | "algo ligero y sin gluten para compartir" |
| RAG sobre legales | `documentos` | el copiloto responde citando la clausula exacta |
| Memoria de agente | `agent_memory` | el agente recuerda conversaciones anteriores relevantes |
| Deduplicacion de reseñas | `resenas` | detectar opiniones repetidas o generadas |
| Busqueda en la web publica | `paginas` | buscador interno que entiende la intencion |

---

## 2. La pregunta de viabilidad

PHP no tiene BLAS, ni SIMD, ni aritmetica vectorial. La duda razonable es si el
lenguaje aguanta. **He medido antes de diseñar.**

### 2.1 Coseno exacto en float32: el suelo

10.000 vectores de 384 dimensiones, `unpack('f*')` y producto escalar en bucle PHP:

```
coseno exacto, escaneo completo : 127 ms   (7,9 consultas/s)
```

Con 50.000 vectores: **642 ms**. Inaceptable para una web, y ademas exige tener
73 MB de floats accesibles.

Conclusion parcial: la fuerza bruta en float no sirve. Hace falta el truco.

### 2.2 El truco: cuantizacion binaria

Un vector normalizado se reduce a **1 bit por dimension**: signo positivo → 1,
negativo → 0. Un vector de 384 dims pasa de 1.536 bytes a **48 bytes**.

Lo interesante no es el ahorro de memoria sino esto: la distancia entre dos
vectores binarios es la **distancia de Hamming**, y en PHP el XOR de dos strings
(`$a ^ $b`) se ejecuta **a velocidad C**, sobre todos los bytes de golpe. Solo
queda contar bits, con una tabla de 256 entradas precalculada.

Medido sobre los mismos 10.000 vectores:

```
A) coseno exacto float32, escaneo completo :  127 ms   (7,9 consultas/s)
B) Hamming 1-bit + tabla lookup            :   14 ms   (70,1 consultas/s)
C) Hamming 1-bit + count_chars             :   19 ms   (51,4 consultas/s)
```

La tabla de lookup gana a `count_chars` (para 48 bytes, montar el array de
frecuencias cuesta mas que recorrerlos). **9x mas rapido que el coseno exacto.**

### 2.3 Dos etapas: velocidad binaria con precision float

La cuantizacion binaria pierde informacion, asi que sola no basta. La solucion
estandar en los motores vectoriales modernos, y que aqui encaja perfecto:

1. **Filtro:** Hamming binario sobre **todos** los vectores. Barato.
2. **Reordenado:** coseno exacto float32 sobre los **K mejores candidatos**. Preciso.

Medido, con vectores aleatorios (el peor caso posible para cuantizacion: los
embeddings reales tienen estructura y dan mejor recall):

**10.000 vectores** — coseno exacto completo: 127 ms

| Profundidad de reordenado | Tiempo | recall@10 | Aceleracion |
|---|---|---|---|
| 100 | 18 ms | 40% | 7,2x |
| 200 | 19 ms | 70% | 6,8x |
| **500** | **22 ms** | **100%** | **5,7x** |
| 1000 | 29 ms | 100% | 4,5x |

**50.000 vectores** — coseno exacto completo: 642 ms

| Profundidad de reordenado | Tiempo | recall@10 | Aceleracion |
|---|---|---|---|
| 100 | 84 ms | 60% | 7,7x |
| 200 | 93 ms | 70% | 6,9x |
| 500 | 86 ms | 90% | 7,4x |
| **1000** | **93 ms** | **100%** | **6,9x** |

### 2.4 Veredicto

**Es viable.** 50.000 documentos, 93 ms por consulta, recall perfecto, PHP puro,
sin extensiones, sin servicios externos.

Y el dato que cambia el diseño: en la primera etapa **solo hace falta el archivo
binario en memoria**. Para 50.000 vectores son **2,3 MB**, no 73 MB. Los floats
se leen del disco por `fseek` unicamente para los ~1.000 candidatos que sobreviven
al filtro. Eso hace que el motor quepa holgadamente en el `memory_limit` de 128 MB
de cualquier hosting compartido.

Rango de operacion recomendado:

| Vectores | Latencia estimada | Veredicto |
|---|---|---|
| < 10.000 | ~20 ms | Comodo |
| 10.000 - 50.000 | 20-95 ms | Objetivo de diseño |
| 50.000 - 200.000 | 95-400 ms | Viable con particionado y cache APCu |
| > 200.000 | > 400 ms | Fuera de alcance. Hace falta un indice HNSW o un motor dedicado |

Para contexto: un restaurante con 300 platos usa 300 vectores. Los 50.000 son
toda la plataforma MyLocal con miles de locales indexados a la vez.

---

## 3. Formato de almacenamiento

### 3.1 Por que los vectores no van dentro del JSON

Tentador y equivocado. Un vector de 768 floats en JSON ocupa unos 15 KB de texto,
frente a 3 KB en binario. Peor aun: `json_decode` tendria que parsear 768 numeros
por documento en **cada** lectura, aunque la consulta no sea vectorial. Un simple
`SELECT nombre FROM productos` se volveria diez veces mas lento.

**Regla: el vector nunca entra en el documento.** Vive en archivos binarios
laterales, con stride fijo, direccionables por `fseek`.

### 3.2 Disposicion en disco

```
STORAGE/<coleccion>/
  <id>.json                    documento normal, sin vector
  _meta.json                   flags.vector = {...}
  _vec/
    manifest.json              dims, metrica, contador, mapa id -> ordinal
    000.bin                    N * (dims/8) bytes   vectores cuantizados a 1 bit
    000.f32                    N * dims * 4 bytes   vectores float32 normalizados
    000.ids                    N * 64 bytes         ids, ancho fijo, ordinal -> id
```

Un **shard** cada 65.536 vectores. Todo con stride fijo: el vector del ordinal
`k` esta siempre en `k * dims * 4` dentro del `.f32`. Acceso O(1) sin indice.

### 3.3 `_meta.json`

```json
{
  "name": "productos",
  "flags": {
    "encrypted": false,
    "vector": {
      "field":    "embedding",
      "dims":     768,
      "metric":   "cosine",
      "quantize": "binary",
      "source":   "ollama:nomic-embed-text",
      "auto":     ["nombre", "descripcion", "alergenos"]
    }
  }
}
```

`auto` es la clave de la ergonomia: los campos de texto a partir de los cuales el
motor genera el embedding solo. El usuario hace un `insert` normal y la busqueda
semantica funciona, sin gestionar vectores a mano.

### 3.4 `_vec/manifest.json`

```json
{
  "version": 1,
  "dims": 768,
  "metric": "cosine",
  "count": 1284,
  "tombstones": 12,
  "shards": [{ "id": "000", "count": 1284, "capacity": 65536 }],
  "map": { "20260808a1b2c3d4": 0, "20260808e5f6a7b8": 1 }
}
```

### 3.5 Bajas y compactacion

Borrar en medio de un archivo de stride fijo desplazaria todo. En su lugar:
se quita el id del `map` y se incrementa `tombstones`. El ordinal queda huerfano
y se ignora al filtrar. Cuando `tombstones / count > 0.2`, una compactacion
reescribe los shards. Es el mismo criterio que usan los motores LSM.

---

## 4. API

### 4.1 Ops nuevas

Cuatro, siguiendo la convencion existente (`engine/Op/Vector/`):

| Op | Entrada | Salida |
|---|---|---|
| `vector.enable` | `collection, field, dims, metric?, source?, auto?` | meta actualizada |
| `vector.upsert` | `collection, id, vector[]` o `text` | `{id, ordinal}` |
| `vector.search` | `collection, vector[]` o `text`, `k?, rerank?, where?, min_score?` | `{items[], scores[], took_ms}` |
| `vector.reindex` | `collection` | reconstruye o compacta los shards |

Cada una con su `HelpEntry`, con lo que entran gratis en `docs/api/`, en el `help`
del CLI y en el catalogo de herramientas del agente. Esa es la ventaja de haber
diseñado bien el modelo Op.

### 4.2 PHP embebido

```php
// Una vez: activar vectores sobre la coleccion
$db->execute([
    'op' => 'vector.enable', 'collection' => 'productos',
    'field' => 'embedding', 'dims' => 768,
    'source' => 'ollama:nomic-embed-text',
    'auto' => ['nombre', 'descripcion'],
]);

// Insert normal: el embedding se genera y se indexa solo
$db->collection('productos')->insert([
    'nombre'      => 'Ensalada de quinoa',
    'descripcion' => 'Sin gluten, vegana, con aguacate y lima',
    'precio'      => 9.5,
]);

// Buscar por significado
$r = $db->execute([
    'op' => 'vector.search', 'collection' => 'productos',
    'text' => 'algo ligero para celiacos',
    'k' => 5,
    'where' => [['field' => 'disponible', 'op' => '=', 'value' => true]],
]);
```

### 4.3 AxiSQL

Extension natural de la gramatica ya existente:

```sql
SELECT nombre, precio FROM productos
WHERE disponible = true
ORDER BY EMBEDDING <-> 'algo ligero para celiacos'
LIMIT 5
```

`<->` es el operador de distancia de pgvector. Reutilizar esa notacion en vez de
inventar otra es gratis y ahorra explicaciones.

El `Planner` detecta `ORDER BY EMBEDDING <-> ...` y compila a `Vector\Search` en
lugar de a `Select`. El `Lexer` necesita un token nuevo (`<->`) y `EMBEDDING` como
palabra reservada.

### 4.4 CLI

```bash
axi vector enable productos --field embedding --dims 768 --auto nombre,descripcion
axi vector search productos "algo ligero para celiacos" --k 5
axi vector reindex productos
```

---

## 5. Algoritmo de busqueda

```
Entrada: q (vector consulta), k (resultados), R (profundidad de reordenado, def. 10*k, min 500)

1. Normalizar q. Cuantizar a q_bin (1 bit por dimension).
2. Cargar _vec/000.bin completo en un string PHP.   [2,3 MB para 50k x 384]
3. Para cada ordinal i:
       xor = q_bin ^ bin[i]                          [nivel C, todos los bytes]
       dist[i] = suma de POPCOUNT[ord(xor[j])]       [tabla de 256 entradas]
4. Si hay clausula where: descartar ordinales cuyo documento no la cumpla.
5. Ordenar por dist ascendente. Quedarse con los R primeros.
6. Para cada candidato:
       fseek(f32, ordinal * dims * 4); leer dims*4 bytes
       score = producto escalar con q                [coseno, ambos normalizados]
7. Ordenar por score descendente. Devolver los k primeros con su documento.
```

Notas de implementacion que importan:

- **Normalizar al escribir, no al leer.** Con ambos vectores normalizados el
  coseno es un producto escalar puro: se ahorran dos raices cuadradas por comparacion.
- **La tabla POPCOUNT se calcula una vez** por proceso (256 enteros) y se guarda
  como estatica.
- **Nunca cargar el `.f32` entero.** Solo los R candidatos, por `fseek`. Es lo que
  mantiene el consumo de memoria plano al crecer la coleccion.
- **Cache del `.bin` entre peticiones** con APCu si esta disponible; si no, la
  lectura de 2,3 MB desde el cache de pagina del sistema operativo cuesta poco.

### 5.1 Filtrado combinado con `where`

Dos estrategias, y hay que elegir segun la selectividad:

- **Pre-filtrado** (paso 4 antes del ordenado): correcto siempre, pero exige saber
  que ordinales cumplen el filtro. Barato **si el campo tiene indice** (ola S2):
  la lista de ids del indice se traduce a un conjunto de ordinales via el `map`.
- **Post-filtrado** (filtrar despues del paso 7): mas rapido, pero si el filtro es
  muy selectivo puede devolver menos de `k` resultados.

Regla: pre-filtrar cuando el campo tenga indice; en caso contrario post-filtrar
con `R` ampliado a `50*k` y avisar en `took_ms` de que se degrado.

**Esto es exactamente por que la ola S2 debe ir antes que los vectores.** Sin
indices reales, el filtrado combinado no tiene una implementacion buena.

---

## 6. Generacion de embeddings

El motor no calcula embeddings: los pide a un backend. Y aqui hay una decision
que encaja con la filosofia de GestasAI.

### 6.1 Backends

Reutilizar la infraestructura de `engine/Agents/Llm/`, que ya tiene el patron
resuelto:

```
engine/Vector/Embedder/
  Embedder.php          interfaz: embed(string|string[]): float[][]
  OllamaEmbedder.php    nomic-embed-text (768d), local, por defecto
  GeminiEmbedder.php    text-embedding-004 (768d), nube
  OpenAiEmbedder.php    text-embedding-3-small (1536d), nube
  HashEmbedder.php      hashing determinista, sin red, para tests
```

`HashEmbedder` no da calidad semantica, pero permite que la suite corra offline
y sea reproducible — igual que `NoopLlm` hace con los agentes hoy.

### 6.2 Por defecto: local

`ollama:nomic-embed-text` como opcion por defecto no es un detalle tecnico. Es
lo que permite decir, sin asteriscos:

> Ni un solo dato de tu carta, tus documentos o tus clientes sale de tu servidor.
> El modelo de embeddings corre en tu maquina.

Con OpenAI o Gemini, cada texto indexado viaja a un tercero. Con Ollama, no sale
nada. Para un producto cuya promesa es la soberania del dato, la eleccion esta
tomada de antemano — y es ademas la que hace que AxiDB tenga un argumento que
Pinecone o Chroma-en-la-nube no pueden replicar.

### 6.3 Coste de indexar

`nomic-embed-text` en CPU tarda entre 20 y 80 ms por texto corto. Indexar una
carta de 300 platos: entre 6 y 24 segundos, una sola vez. Lo relevante es que
**no puede ser sincrono dentro de un `insert`**: un alta de plato no puede tardar
80 ms extra.

Solucion: encolar. Ya existe `plugins/jobs/JobQueue.php`. El `insert` escribe el
documento y encola el trabajo de embedding; el worker lo procesa y llama a
`vector.upsert`. El documento es buscable por texto de inmediato y semanticamente
unos segundos despues.

---

## 7. Plan de implementacion

Cinco fases. Cada una entrega algo que funciona y se puede probar.

### V1 — Nucleo de almacenamiento (3-4 dias)

```
engine/Vector/VectorStore.php      lectura/escritura de shards, stride fijo
engine/Vector/Quantizer.php        float32 -> 1 bit, normalizacion
engine/Vector/Manifest.php         mapa id <-> ordinal, tombstones
tests/vector_store_test.php
```

Criterio de salida: escribir 10.000 vectores, leerlos por ordinal, verificar
integridad byte a byte tras un reinicio del proceso.

### V2 — Motor de busqueda (2-3 dias)

```
engine/Vector/Searcher.php         dos etapas, tabla popcount, fseek de candidatos
engine/Vector/Metric.php           cosine, dot, euclidean
tests/vector_search_test.php       recall frente a fuerza bruta exacta
```

Criterio de salida: recall@10 >= 95% frente al coseno exacto sobre 10.000
vectores, en menos de 50 ms.

### V3 — Ops y superficies (2-3 dias)

Las cuatro Ops de la seccion 4, con `HelpEntry`. Regenerar `docs/api/`. Añadir
el subcomando `axi vector`. Exponerlas en el `Toolbox` de agentes, con
`vector.search` en la allowlist por defecto (es de solo lectura) y `vector.reindex`
fuera de ella.

### V4 — Embeddings (2-3 dias)

Los cuatro embedders, el flag `auto` en `_meta`, y el enganche con `JobQueue`
para el indexado asincrono.

### V5 — AxiSQL y filtrado (2-3 dias)

Token `<->` en el `Lexer`, gramatica en el `Parser`, ruta a `Vector\Search` en el
`Planner`. Pre-filtrado apoyado en los indices de la ola S2.

**Total: 11-16 dias de trabajo efectivo**, sobre un nucleo ya saneado.

---

## 8. Lo que esta fuera de alcance

Conviene decirlo por escrito para no acabar reimplementando Qdrant:

- **Indice HNSW o IVF.** Por encima de ~200.000 vectores haria falta. Construirlo
  en PHP es un proyecto en si mismo, y a esa escala la respuesta correcta es
  delegar en un motor dedicado.
- **Busqueda hibrida BM25 + vectorial.** Deseable y factible mas adelante; requiere
  antes un indice de texto invertido que hoy no existe.
- **Vectores cifrados.** El vault cifra documentos, pero un vector cifrado no se
  puede comparar sin descifrarlo. Una coleccion con `encrypted: true` y `vector`
  a la vez debe **rechazarse explicitamente** en `vector.enable`, no fallar raro
  en tiempo de consulta.
- **Multivector por documento.** Un documento largo troceado en varios embeddings
  es lo correcto para RAG serio. Se puede simular con una coleccion `chunks` que
  referencie al documento padre; el soporte nativo queda para v2.

---

## 9. Resumen

| Pregunta | Respuesta |
|---|---|
| Se puede hacer busqueda vectorial en PHP puro? | Si, medido |
| Hasta que tamaño? | 50.000 vectores en ~93 ms con recall del 100%. Comodo hasta 200.000 |
| Que hace falta en el servidor? | Nada. Sin extensiones, sin servicios, sin Composer |
| Cuanta memoria? | 2,3 MB para 50.000 vectores de 384 dims. Los floats no se cargan |
| Cuanto trabajo? | 11-16 dias, sobre un nucleo saneado |
| Que hay que hacer antes? | Olas S1 y S2: unificar almacenamiento y construir indices reales |
| Por que importa estrategicamente? | Es la pieza que convierte AxiDB en "la base de datos nativa para agentes de IA con soberania del dato" — un hueco que hoy nadie ocupa en PHP |
