# Ejemplos de AxiDB

Dos aplicaciones de dominios que no tienen nada que ver entre si, sobre el mismo
motor y sin una sola modificacion en `core/`. Esa es toda la demostracion.

```bash
php examples/cristaleria/index.php
php examples/blog/index.php
```

| Ejemplo | Dominio | Que enseña |
|---|---|---|
| [`cristaleria/`](cristaleria/) | clientes, presupuestos, medidas | CRUD completo, dos indices, consultas encadenadas, proyeccion, totales |
| [`blog/`](blog/) | entradas, categorias, comentarios | Relacion entre colecciones, portada ordenada, busqueda por texto |
| [`cristaleria-web/`](cristaleria-web/) | la misma cristaleria, con pantalla | El puente HTTP y `axi.js`: la aplicacion entera con **cuatro lineas de PHP** |

El de la cristaleria esta a proposito dos veces, en PHP y en navegador: son el
mismo dominio resuelto por los dos caminos que ofrece AxiDB, y compararlos dice
mas que cualquier explicacion.

Cada uno crea su propio directorio `datos/` al ejecutarse y lo deja limpio al
empezar, asi que puedes lanzarlos las veces que quieras.

## Lo que hay que mirar

Las dos primeras lineas de `cristaleria/index.php`:

```php
require __DIR__ . '/../../core/axidb.php';
$db = axidb(__DIR__ . '/datos');
```

No hay mas instalacion. Ni configuracion, ni esquema que declarar, ni servidor
que arrancar, ni `composer install`.

Y en `blog/index.php`, exactamente las mismas dos lineas para un dominio
completamente distinto.

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
