# Cruzar colecciones

```php
$db->sql("INSERT INTO socios (id, nombre) VALUES ('c1', 'Ana')");
$db->sql("INSERT INTO pedidos (cli, total) VALUES ('c1', 100.0)");

$db->sql("SELECT total, socios.nombre
          FROM pedidos JOIN socios ON pedidos.cli = socios.id");
```

`INNER JOIN` y `LEFT JOIN`, con alias:

```php
$db->sql("SELECT total, c.nombre FROM pedidos p LEFT JOIN socios c ON p.cli = c.id");
```

## Como quedan los nombres

Esto es lo unico que hay que tener claro:

```
izquierda   tal cual, y ademas con su prefijo:   total   y   pedidos.total
derecha     SOLO con su prefijo:                 clientes.nombre
```

Asi no hay ambiguedad posible. Si las dos colecciones tienen un campo `nombre`,
`nombre` es el de la izquierda y el otro es `clientes.nombre`, siempre, sin
reglas que recordar ni sorpresas segun el orden.

## Que hace cada uno

- **INNER** descarta los documentos que no casan con nada.
- **LEFT** los conserva, con la parte de la derecha a `null`.

Un `null` **nunca casa**, ni siquiera con otro `null`. Es lo que hace SQL y lo
unico razonable: "no se sabe" no es igual a otro "no se sabe". Si casaran, dos
documentos incompletos apareceran emparejados y nadie entenderia por que.

## Desde la API

```php
$db->find('pedidos')->join('socios', 'cli', 'id')->orderBy('total')->get();
$db->find('pedidos')->join('socios', 'cli', 'id', izquierdo: true)->get();
```

Lo mismo que el JOIN de AxiSQL, con los mismos nombres de campo.

## Lo que cuesta

Es un **hash join**: la coleccion de la derecha se recorre una vez y se mete en
un mapa; despues cada documento de la izquierda busca el suyo ahi. Coste
`izquierda + derecha`, no `izquierda × derecha`.

Dos cosas que hay que saber:

1. **La derecha entra entera en memoria.** Cruzar contra una coleccion de un
   millon de documentos carga un millon de documentos. Pon la pequeña a la
   derecha.
2. **Con JOIN, el filtro no usa indices.** Tiene que ser asi: la condicion puede
   hablar de un campo de la coleccion unida, y filtrar antes con un indice de la
   izquierda descartaria documentos que la union habria traido. `EXPLAIN` lo
   dice: la estrategia sale como `cruce`.

No hay JOIN de tres o mas colecciones en una sola pasada optimizada, pero si se
pueden encadenar: cada `JOIN` adicional cruza sobre el resultado del anterior.

---

# Subconsultas

```php
$db->sql("SELECT total FROM pedidos
          WHERE cli IN (SELECT id FROM socios WHERE ciudad = 'Murcia')");

$db->sql("SELECT COUNT(*) FROM pedidos
          WHERE EXISTS (SELECT id FROM socios WHERE ciudad = 'Lorca')");
```

Se resuelven **una sola vez**, antes de filtrar, no una por documento. Con mil
documentos, ejecutarla dentro del bucle serian mil consultas para obtener
siempre lo mismo.

La subconsulta admite exactamente lo mismo que una consulta suelta —agregados,
JOIN, funciones— porque se ejecuta con el mismo motor.

## Lo que NO hay: subconsultas correlacionadas

Las que miran el documento de fuera:

```sql
-- Esto no se puede escribir
WHERE EXISTS (SELECT * FROM lineas WHERE lineas.pedido = pedidos.id)
```

Obligan a ejecutar la subconsulta una vez por documento, y en un motor de
archivos eso es una consulta completa por documento. Se rechaza con un mensaje
claro en vez de aceptarlo y que alguien descubra el coste en produccion.

Para lo que se querria un EXISTS correlacionado, casi siempre sirve un `JOIN`.
