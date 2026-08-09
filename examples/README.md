# Ejemplos de AxiDB

Cuatro programas que se ejecutan y enseñan lo que hace el motor. Tres son de
consola y uno levanta el puente HTTP.

```bash
php examples/01-almacen/index.php
php examples/02-empleados/index.php
php examples/03-pedidos/index.php
```

| Ejemplo | Datos | Que enseña |
|---|---|---|
| [`01-almacen/`](01-almacen/) | articulos y movimientos | Altas, indices, `UNIQUE`, consultas encadenadas y agregados con `GROUP BY` |
| [`02-empleados/`](02-empleados/) | empleados y departamentos | Esquema obligatorio, `JOIN` entre colecciones, medias por grupo y `HAVING` |
| [`03-pedidos/`](03-pedidos/) | clientes, pedidos y lineas | Transacciones todo-o-nada, `LEFT JOIN` y subconsultas `IN (SELECT ...)` |
| [`04-puente-http/`](04-puente-http/) | lecturas de sensores | La base de datos desde otro proceso, por HTTP, sin escribir backend |

Cada uno crea su propio directorio `datos/` al ejecutarse y lo deja limpio al
empezar, asi que se pueden lanzar las veces que haga falta.

## Lo que hay que mirar

Las dos primeras lineas de cualquiera de ellos:

```php
require __DIR__ . '/../../core/axidb.php';
$db = axidb(__DIR__ . '/datos');
```

No hay mas instalacion. Ni configuracion, ni esquema que declarar, ni servidor
que arrancar, ni `composer install`.

Y son las mismas dos lineas en los tres, para tres conjuntos de datos que no
tienen nada que ver entre si. El nucleo no sabe que existe un albaran.

## Empezar el tuyo

```bash
mkdir mi-proyecto && cd mi-proyecto
cp -r /ruta/a/axidb/core vendor/axidb
```

```php
<?php
require __DIR__ . '/vendor/axidb/axidb.php';
$db = axidb(__DIR__ . '/datos');

$db->insert('lo_que_sea', ['campo' => 'valor']);
```

Guia completa: [`docs/guide/00-cinco-minutos.md`](../docs/guide/00-cinco-minutos.md).
