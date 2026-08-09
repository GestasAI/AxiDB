# AxiDB en cinco minutos

De carpeta vacia a base de datos funcionando. Sin Composer, sin Docker, sin
servidor de base de datos, sin migraciones.

Requisito unico: **PHP 8.1 o superior**. Ninguna extension mas alla de `json`,
que viene siempre.

---

## 1. Instalar

Copia la carpeta `axidb/core/` dentro de tu proyecto:

```
mi-proyecto/
  vendor/axidb/      <- la carpeta copiada
  index.php          <- tu aplicacion
```

Eso es todo. No hay paso 2 de instalacion.

---

## 2. Abrir la base de datos

```php
<?php
require __DIR__ . '/vendor/axidb/axidb.php';

$db = axidb(__DIR__ . '/datos');
```

El directorio `datos/` se crea solo la primera vez.

---

## 3. Guardar y leer

```php
$id = $db->insert('presupuestos', [
    'cliente' => 'Ana Ruiz',
    'tipo'    => 'mampara',
    'total'   => 421.20,
])['id'];

$p = $db->get('presupuestos', $id);
echo $p['total'];                    // 421.2
```

Cada documento es un archivo JSON en `datos/presupuestos/<id>.json`. Puedes
abrirlo, leerlo y hasta corregirlo a mano.

AxiDB añade cuatro campos por su cuenta:

| Campo | Que es |
|---|---|
| `id` | el identificador, ordenable por antigüedad si lo genera el motor |
| `_version` | sube uno en cada escritura |
| `_createdAt` | fecha de alta, no cambia nunca |
| `_updatedAt` | fecha de la ultima escritura |

---

## 4. Modificar y borrar

```php
$db->update('presupuestos', $id, ['estado' => 'aceptado']);   // fusiona
$db->update('presupuestos', $id, ['estado' => 'x'], true);    // reemplaza entero
$db->delete('presupuestos', $id);
```

---

## 5. Consultar

```php
$caros = $db->find('presupuestos')
            ->where('estado', 'pendiente')
            ->where('total', '>', 300)
            ->orderBy('total', 'desc')
            ->limit(10)
            ->get();
```

Operadores disponibles:

```
=   !=   >   >=   <   <=   IN   NOT IN   LIKE   CONTAINS   IS NULL   IS NOT NULL
```

Atajos utiles:

```php
$db->find('p')->where('estado', 'pendiente')->first();   // uno solo, o null
$db->find('p')->where('estado', 'pendiente')->count();   // cuantos hay
$db->find('p')->select(['cliente', 'total'])->get();     // solo esos campos
$db->all('p');                                           // todos
$db->count('p');                                         // cuantos en total
```

---

## 6. Indices, cuando la coleccion crece

Sin indice, filtrar recorre la coleccion entera. Con indice, va directo:

```php
$db->index('presupuestos', 'cliente_id');
```

Se declara **una vez**. A partir de ahi se mantiene solo en cada alta,
modificacion y borrado. Las consultas de igualdad sobre ese campo lo usan sin
que tengas que pedirlo:

```php
$ana = $db->insert('clientes', ['nombre' => 'Ana Ruiz']);

$db->by('presupuestos', 'cliente_id', $ana['id']);                  // por indice
$db->find('presupuestos')->where('cliente_id', $ana['id'])->get();  // tambien
```

Si un indice se estropea, se repara reconstruyendolo:

```php
$db->index('presupuestos', 'cliente_id');   // idempotente
$db->reindex('presupuestos');               // todos los de la coleccion
```

---

## 7. AxiSQL, si prefieres escribir sentencias

Lo mismo que hace `find()`, en SQL. No es un motor aparte: la sentencia se
analiza y se traduce a las mismas llamadas, asi que las dos vias dan siempre el
mismo resultado.

```php
$db->sql("INSERT INTO presupuestos (cliente, tipo, total) VALUES ('Ana', 'mampara', 421.20)");

$caros = $db->sql("SELECT cliente, total FROM presupuestos
                   WHERE total > 300 AND estado != 'rechazado'
                   ORDER BY total DESC
                   LIMIT 10");

$cuantos = $db->sql("SELECT COUNT(*) FROM presupuestos WHERE total > 300");

$db->sql("UPDATE presupuestos SET estado = 'aceptado' WHERE cliente = 'Ana'");
$db->sql("DELETE FROM presupuestos WHERE estado = 'caducado'");
```

Y la estructura:

```php
$db->sql('CREATE COLLECTION clientes');
$db->sql('CREATE INDEX ON presupuestos (cliente)');
$db->sql('CREATE UNIQUE INDEX ON clientes (email)');
$db->sql('DROP INDEX ON presupuestos (cliente)');
```

> **`UNIQUE` se cumple en cada alta**, no solo al crear el indice. A partir de
> ahi, un documento que repita ese valor se rechaza, y con varios procesos a la
> vez tambien: el valor se reserva bajo su propio cerrojo antes de escribir.
> Un documento SIN ese campo no choca con otro que tampoco lo tenga, igual que
> con NULL en SQL.

`CREATE INDEX` **construye** el indice, no solo lo apunta. Para comprobar que una
consulta se apoya en el, antepon `EXPLAIN`:

```php
$db->sql('CREATE INDEX ON presupuestos (cliente)');
$plan = $db->sql("EXPLAIN SELECT * FROM presupuestos WHERE cliente = 'Ana'");
echo $plan['estrategia'];   // 'index' o 'scan'
echo $plan['detalle'];      // "Resuelto por el indice de 'cliente': leidos 2 documentos."
```

`EXPLAIN` nunca ejecuta: con `DELETE` o `UPDATE` te dice a cuantos documentos
afectaria y no toca ninguno.

### Lo que conviene saber

- **Una sentencia por llamada.** Dos separadas por punto y coma se rechazan.
- **Las palabras clave no distinguen mayusculas; los nombres si.** `SELECT` y
  `select` son lo mismo; el campo `Total` y el campo `total`, no.
- **Un literal entre comillas es siempre un valor**, nunca sintaxis. Un punto y
  coma o un `DROP` dentro de una cadena viajan como texto.
- **Aun asi, no pegues entrada de usuario dentro de una sentencia.** Para eso
  esta `find()->where()`, que recibe el valor como valor y no como texto que hay
  que analizar. La regla es la de siempre.

## 8. Durabilidad

Por defecto cada escritura llama a `fsync()`: el dato esta en el disco antes de
que la funcion devuelva. Cuesta unos 6 ms y es lo que hace que un corte de
corriente no te quite trabajo confirmado.

Si prefieres velocidad sobre garantia:

```php
$db = axidb(__DIR__ . '/datos', ['durable' => false]);
```

En cualquiera de los dos modos, **una escritura nunca deja un documento a
medias**: se escribe en un temporal y se renombra, que es una operacion atomica.
Quien lea a la vez vera el contenido viejo o el nuevo, jamas uno roto.

---

## 9. Configuracion opcional

Si prefieres no repetir la ruta, pon un `axidb.json` en la raiz del proyecto:

```json
{
  "data": "./datos",
  "durable": true
}
```

Y entonces basta con `$db = axidb();`.

---

## Ejemplos completos

- [`examples/cristaleria/`](../../examples/cristaleria/) — clientes, presupuestos, metros cuadrados
- [`examples/blog/`](../../examples/blog/) — entradas, categorias, comentarios

Dos dominios sin nada en comun, el mismo motor, cero cambios en el nucleo.
