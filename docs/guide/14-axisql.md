# AxiSQL completo

Lo que se puede escribir desde la ola A8. Todo lo de antes sigue igual: esto
suma, no cambia.

## Agregados y GROUP BY

```php
$db->sql("INSERT INTO ventas (ciudad, total) VALUES ('Murcia', 100.0), ('Lorca', 250.0)");

$db->sql("SELECT ciudad, COUNT(*) AS cuantas, SUM(total) AS suma
          FROM ventas GROUP BY ciudad ORDER BY suma DESC");
```

`COUNT`, `SUM`, `AVG`, `MIN`, `MAX`, con `GROUP BY` y `HAVING`. Sin `GROUP BY`,
un agregado devuelve una sola fila con el total de todo.

**Con los nulos por medio**, que es donde se equivocan las implementaciones
caseras:

- `COUNT(*)` cuenta filas, incluidas las que tienen el campo vacio.
- `COUNT(campo)` cuenta solo las que tienen valor. **No es lo mismo.**
- `SUM` y `AVG` ignoran los nulos, y `AVG` divide entre los que habia, no entre
  todos. Dividir entre todos daria una media falsa en cuanto un documento no
  tuviera el campo.
- La suma de nada es `null`, no cero. `COUNT(*)` de nada si es cero.

`AVG` siempre devuelve decimal. En PHP `10/2` da un entero, y un campo que unas
veces llega como `5` y otras como `5.5` rompe a quien lo recibe.

## Expresiones, alias y DISTINCT

```php
$db->sql("SELECT nombre, precio * 1.21 AS conIva FROM articulos");
$db->sql("SELECT DISTINCT ciudad FROM ventas ORDER BY ciudad");
$db->sql("SELECT *, precio * 2 AS doble FROM articulos");
```

Aritmetica con `+ - * / %` y la precedencia de siempre: `2 + 3 * 4` son 14.
El `AS` se puede omitir. Sin alias, la columna se llama como la expresion.

**Los nulos se propagan y no revientan.** Sumar a un campo que no existe da
`null`, no un cero disfrazado de dato. Dividir por cero, tambien `null`.

`ORDER BY` entiende tanto un alias del `SELECT` como un campo que no se ha
pedido.

## Funciones

```
texto    MAYUS  MINUS  LARGO  RECORTA  UNIR  TROZO  REEMPLAZA
numero   REDONDEA  ABS  TECHO  SUELO
fecha    AHORA  HOY  FECHA  AÑO  MES  DIA  HORA  DIAS_ENTRE
otras    SI_NULO  LONGITUD
```

Las de fecha son las que mas falta hacian. Una fecha en AxiDB es una cadena ISO,
asi que "los pedidos de abril" habia que calcularlo sacando los documentos a PHP:

```php
$db->sql("SELECT cliente FROM ventas WHERE MES(fecha) = 4");
```

`AÑO` con eñe funciona, y `ANIO` tambien.

## Filtros nuevos

```php
$db->sql("SELECT cliente FROM ventas WHERE total BETWEEN 100 AND 250");
$db->sql("SELECT cliente FROM ventas WHERE cliente NOT LIKE 'A%'");
```

`BETWEEN` incluye los dos extremos. Y los dos lados de una comparacion pueden
ser expresiones, asi que se pueden comparar dos campos entre si:

```php
$db->sql("SELECT art FROM margen WHERE precio > coste * 2");
```

> Una nota de rendimiento: `WHERE ciudad = 'Murcia'` usa el indice; `WHERE
> MES(fecha) = 4` no puede, porque ningun indice guarda el mes de una fecha.
> `EXPLAIN` lo dice.

## Escribir

```php
$db->sql("INSERT INTO p (nombre, n) VALUES ('a', 1), ('b', 2), ('c', 3)");
$db->sql("INSERT INTO p (id, n) VALUES ('fijo', 9) ON DUPLICATE UPDATE");

$db->sql("UPDATE p SET n = n + 1");
$db->sql("UPDATE p SET n = 0 LIMIT 100");
$db->sql("DELETE FROM p LIMIT 100");
```

`SET n = n + 1` se calcula **sobre cada documento**, asi que cada uno sube desde
su propio valor. `LIMIT` en `UPDATE` y `DELETE` sirve para ir por tandas.

Un `INSERT` que trae la columna `id` usa ese id.

## Ver la forma de los datos

```php
$db->sql("INSERT INTO empresas (nombre, ciudad) VALUES ('Maderas', 'Murcia')");
$db->sql('CREATE INDEX ON empresas (ciudad)');

$db->sql('SHOW COLLECTIONS');
$db->sql('SHOW INDEXES ON empresas');
$db->sql('DESCRIBE empresas');
```

`DESCRIBE` merece una explicacion: AxiDB no exige esquema, asi que no hay una
lista de columnas que consultar. Lo que hace es **mirar los documentos** y
contar que campos aparecen, con que tipo y **en cuantos de cuantos**:

```
campo      tipo             documentos  de
ciudad     texto            900         900
telefono   texto            3           900
saldo      decimal|entero   890         900
```

En una coleccion sin esquema, saber que `telefono` esta en 3 de 900 vale mas que
saber que existe. Y un tipo que sale como `decimal|entero` avisa de que ese campo
se ha guardado de dos maneras.

## Cambiar la forma

```php
$db->sql("INSERT INTO empresas (nombre, tlf) VALUES ('Maderas', '600123123')");

$db->sql('ALTER COLLECTION empresas ADD FIELD activo = true');
$db->sql('ALTER COLLECTION empresas RENAME FIELD tlf TO telefono');
$db->sql('ALTER COLLECTION empresas DROP FIELD activo');
$db->sql('ALTER COLLECTION empresas RENAME TO companias');
```

Renombrar la **coleccion** mueve su directorio: no reescribe ni un documento y
cuesta lo mismo con diez que con un millon.

Tocar un **campo** es otra cosa. Aqui cada documento lleva sus campos, asi que
hay que reescribirlos todos: sobre un millon de documentos, eso es un millon de
escrituras. Se dice porque cambia lo que uno espera del coste.

Los campos del motor —`id`, `_version`, `_createdAt`, `_updatedAt`— no se pueden
tocar.

## Vistas

```php
$db->sql("INSERT INTO facturas (cliente, total) VALUES ('Ana', 250.0), ('Eva', 800.0)");

$db->sql("CREATE VIEW grandes AS SELECT cliente, total FROM facturas WHERE total > 200");

$db->sql("SELECT cliente FROM grandes ORDER BY cliente");
$db->sql("SELECT cliente FROM grandes WHERE total > 500");
```

Una vista es **una consulta con nombre**, y nada mas: no guarda datos ni los
duplica. Al usarla se ejecuta su consulta en ese momento, asi que siempre
refleja lo que hay ahora. Se le puede filtrar y ordenar encima.

Se guarda el texto del `SELECT`, no su arbol interno: una vista creada hoy tiene
que seguir significando lo mismo dentro de dos años, y ademas asi se puede leer.
