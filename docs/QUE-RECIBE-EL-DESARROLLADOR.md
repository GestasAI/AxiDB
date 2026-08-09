# Que recibe exactamente un desarrollador que instala AxiDB

Caso de prueba: **una desarrolladora va a hacer la web de una cristaleria y no
quiere usar SQLite. Prueba AxiDB. Que le pasas?**

Este documento define el entregable. Es la decision de diseño mas importante del
proyecto, porque si la respuesta es "le pasas `lib.php` mas `axidb/` mas
`CAPABILITIES/`", nadie lo usara jamas.

---

## 1. El diagnostico: hoy no hay nada que entregar

Revise `spa/server/lib.php` linea por linea. De sus 487 lineas, esto es lo que
**no** se le puede dar a nadie:

```php
require_once __DIR__ . '/../../CAPABILITIES/LOGIN/LoginSanitize.php';
require_once __DIR__ . '/../../CAPABILITIES/LOGIN/LoginSessions.php';
require_once __DIR__ . '/../../CAPABILITIES/LOGIN/LoginRoles.php';
require_once __DIR__ . '/../../CAPABILITIES/LOCALES/LocalModel.php';
```

```php
// dentro de data_put(), la funcion de guardado generica:
if ($collection === 'locales' && !empty($data['slug'])) {
    local_slug_index_set((string) $data['slug'], $id);
}
```

```php
function operating_local_id(?array $user, array $req): string
function assert_local_access(?array $user, string $localId): void
function local_id_by_slug(string $slug): ?string
```

Una cristaleria no tiene `locales`, ni `slug` de bar, ni `local_id`, ni el sistema
de login de MyLocal. Tiene clientes, presupuestos, medidas y pedidos.

**El reparto real de `lib.php`:**

| Parte | Lineas aprox. | Entregable? |
|---|---|---|
| `data_put`, `data_get`, `data_delete`, `data_all` | ~85 | **Si**, quitando el dominio incrustado |
| Indice por tenant (`data_by_local`, `_idx_*`) | ~70 | **Si**, generalizando `local_id` a un campo cualquiera |
| Indice de slug de `locales` | ~30 | No. Es MyLocal |
| Sesiones, roles, saneado (delegan a CAPABILITIES) | ~60 | No. Es MyLocal |
| Aislamiento multi-tenant por `local_id` | ~90 | No. Es MyLocal |
| `resp()`, config loader, rate limit, cURL | ~90 | No. Son de la aplicacion |

**Unas 150 lineas de 487 son base de datos. El resto es MyLocal.**

Tenias razon: lo que hay hoy no es un sistema de "colocar y funcionar". Es el
motor de MyLocal con el motor mezclado con el coche.

---

## 2. Lo que la desarrolladora recibe

Una carpeta. Una sola.

```
mi-cristaleria/
  axidb/              <- lo que le pasas. No lo abre nunca.
  index.php           <- su aplicacion
  data/               <- se crea solo al primer guardado
```

Y en su codigo:

```php
<?php
require 'axidb/core/axidb.php';

$db = axidb('./datos');
```

Dos lineas. No hay `lib.php`, no hay `CAPABILITIES/`, no hay `local_id`, no hay
login, no hay configuracion obligatoria, no hay migraciones, no hay Composer.

---

## 3. Como es su codigo

### 3.1 Con SQL, que es lo que pide

```php
$db->sql("CREATE COLLECTION presupuestos");

$db->sql("INSERT INTO presupuestos (cliente, tipo, m2, total, estado)
          VALUES ('Ana Ruiz', 'mampara', 3.5, 420, 'pendiente')");

$pendientes = $db->sql("SELECT cliente, total FROM presupuestos
                        WHERE estado = 'pendiente' AND total > 300
                        ORDER BY total DESC LIMIT 20");

$db->sql("UPDATE presupuestos SET estado = 'aceptado' WHERE id = '{$id}'");
$db->sql("DELETE FROM presupuestos WHERE estado = 'caducado'");
```

Esto es exactamente lo que decias: **sentencias SQL directas contra `/axidb`, sin
API por medio**. `$db->sql()` es una llamada a una funcion PHP que analiza la
sentencia y toca ficheros. No hay red, no hay socket, no hay servidor que arrancar.

### 3.2 Sin SQL, si prefiere objetos

```php
$id = $db->insert('presupuestos', [
    'cliente' => 'Ana Ruiz',
    'tipo'    => 'mampara',
    'm2'      => 3.5,
    'total'   => 420,
    'estado'  => 'pendiente',
]);

$p = $db->get('presupuestos', $id);
$db->update('presupuestos', $id, ['estado' => 'aceptado']);
$db->delete('presupuestos', $id);

$caros = $db->find('presupuestos')
            ->where('total', '>', 300)
            ->orderBy('total', 'desc')
            ->limit(20)
            ->get();
```

### 3.3 Indices, cuando le hagan falta

```php
$db->sql("CREATE INDEX ON presupuestos (estado)");
$db->sql("CREATE UNIQUE INDEX ON clientes (email)");
```

> **`UNIQUE` se cumple en cada alta**, no solo al crear el indice. A partir de
> ahi, un documento que repita ese valor se rechaza, y con varios procesos a la
> vez tambien: el valor se reserva bajo su propio cerrojo antes de escribir.
> Un documento SIN ese campo no choca con otro que tampoco lo tenga, igual que
> con NULL en SQL.

El indice por tenant de MyLocal generalizado: en vez de `local_id` fijo, cualquier
campo. Para la cristaleria seria `cliente_id`, y `$db->by('presupuestos',
'cliente_id', $idCliente)` le da los presupuestos de un cliente sin escanear todo.

### 3.4 Vectores, si algun dia quiere buscador semantico

```php
$db->sql("SELECT * FROM productos
          ORDER BY EMBEDDING <-> 'vidrio templado para ducha'
          LIMIT 5");
```

Misma carpeta, misma sintaxis. Solo cambia el perfil en la configuracion.

---

## 4. El punto delicado: el navegador

Aqui hay que ser preciso, porque es donde se mezclan dos cosas distintas.

Si la desarrolladora hace la web con JavaScript, el navegador **no puede tocar
ficheros del servidor**. Eso no es una limitacion de AxiDB: es como funciona la
web. El navegador esta en el ordenador del cliente y los datos estan en tu
servidor. Algo tiene que viajar por la red, siempre. Con SQLite igual. Con
Postgres igual. Con cualquier cosa.

Lo que si se puede eliminar es que **ella tenga que escribir ese puente**.

```
SIN AxiDB:  ella escribe el endpoint PHP, el enrutado, la validacion, el JSON...
CON AxiDB:  el endpoint ya viene en la carpeta
```

Su codigo JavaScript:

```html
<script type="module">
  import { axidb } from '/axidb/core/axi.js';

  const db = axidb('/api.php');

  await db.insert('presupuestos', { cliente: 'Ana Ruiz', total: 420 });
  const pendientes = await db.sql("SELECT * FROM presupuestos WHERE estado='pendiente'");
</script>
```

Cero lineas de backend escritas por ella. El endpoint ya existe, con autenticacion
opcional y CORS resueltos.

**Resumiendo los dos saltos, que es donde estaba la confusion:**

| Salto | Se puede quitar? |
|---|---|
| Navegador → servidor | No. Es HTTP siempre, en toda arquitectura |
| Servidor → base de datos | **Si. AxiDB lo elimina.** Es una llamada a funcion |

Con Postgres tendrias los dos saltos. Con AxiDB solo el primero, y ademas no
tienes que programarlo.

---

## 5. Y entonces que pasa con MyLocal

No se tira nada. Se separa en dos capas:

```
  MyLocal (dominio)
  spa/server/lib.php           auth, roles, multi-tenant, local_id, slug de locales
  CAPABILITIES/LOGIN/          login
  CAPABILITIES/CARTA/          cartas, platos, alergenos
        │
        │  usa
        ▼
  AxiDB (generico)
  axidb/                       data_put/get/all, indices, SQL, atomicidad, vectores
```

`lib.php` **no desaparece**. Adelgaza: le quitas las ~150 lineas de persistencia,
que se van a `axidb/`, y se queda con lo que si es de MyLocal — que es la mayoria
y esta bien donde esta.

El codigo de MyLocal apenas cambia:

```php
// antes
$saved = data_put('carta_productos', $id, $doc, true);

// despues
$saved = $db->put('carta_productos', $id, $doc, true);
```

Y MyLocal gana algo importante: **hereda las correcciones**. La escritura atomica
y el indice sin condicion de carrera se arreglan **una vez, en AxiDB**, y los
disfrutan MyLocal, la cristaleria y cualquier otro proyecto. Hoy, arreglarlo en
`lib.php` no le sirve a nadie mas.

---

## 6. Lo que hace falta construir

No es rehacer nada. Es **extraer y generalizar**:

| Paso | Trabajo |
|---|---|
| 1 | Sacar las ~150 lineas de persistencia de `lib.php` a `axidb/` |
| 2 | Quitarles el dominio: fuera `locales`, `slug`, `local_id` fijo. El indice pasa a ser por campo configurable |
| 3 | Romper la dependencia de `CAPABILITIES/LOGIN` (saneado propio, sin login obligatorio) |
| 4 | Aplicar tmp+rename y `fsync` (fallo F1) y el lock correcto (fallo F2) |
| 5 | Fachada unica `Axi`: `insert/get/update/delete/find/sql` |
| 6 | Endpoint HTTP y cliente `axi.js` que ya vienen hechos |
| 7 | Cambiar las llamadas de MyLocal de `data_put()` a `$db->put()` |
| 8 | Archivar `axidb/engine/` (el motor viejo: CMS, temas, Gmail, Three.js) |

Los pasos 1 a 4 son los mismos que el plan de
[`claude/planes/endurecimiento-datos.md`](../../claude/planes/endurecimiento-datos.md),
solo que escribiendo el resultado en `axidb/` en vez de en `lib.php`.

**El trabajo urgente y el trabajo del producto son el mismo trabajo.**

---

## 7. La prueba de que esta terminado

Un unico criterio, y es muy concreto:

> Copias la carpeta `axidb/` en un directorio vacio, creas un `index.php` con
> cinco lineas, y la cristaleria funciona. Sin tocar nada de MyLocal. Sin
> `CAPABILITIES/`. Sin configurar nada.

El dia que eso funcione, tienes un producto. Hasta ese dia, tienes el motor de
MyLocal.
