# Transacciones

Varias escrituras que ocurren enteras o no ocurren. Tambien entre colecciones
distintas, y tambien si se va la luz en mitad.

```php
$db->insert('cuentas', ['saldo' => 500], 'a');
$db->insert('cuentas', ['saldo' => 500], 'b');

$db->transaction(function ($tx) {
    $tx->update('cuentas', 'a', ['saldo' => 470]);
    $tx->update('cuentas', 'b', ['saldo' => 530]);
});
```

Si la segunda linea falla, la primera tampoco pasa. Sin esto, el dinero sale de
una cuenta y no llega a la otra, y despues no hay forma de saber que ocurrio.

## Dentro se lee lo propio

```php
$db->insert('cuentas', ['saldo' => 500], 'a');

$db->transaction(function ($tx) {
    $antes = $tx->get('cuentas', 'a')['saldo'];
    $tx->update('cuentas', 'a', ['saldo' => $antes - 30]);

    echo $tx->get('cuentas', 'a')['saldo'];   // ya vale 30 menos
});
```

`$tx` tiene `get`, `exists`, `all`, `count`, `find`, `insert`, `update`, `put` y
`delete`, con las mismas firmas que `$db`.

Las consultas tambien ven lo pendiente, con todo lo de siempre —filtros, orden,
`limit`, campos elegidos—:

```php
$db->insert('cuentas', ['saldo' => 500, 'titular' => 'Ana'], 'a');
$db->insert('cuentas', ['saldo' => 100, 'titular' => 'Eva'], 'b');

$db->transaction(function ($tx) {
    $tx->update('cuentas', 'a', ['saldo' => 50]);

    $pobres = $tx->find('cuentas')->where('saldo', '<', 200)->orderBy('saldo')->get();
    // Ana sale, con 50. Sin esto habria salido solo Eva.
});
```

**Lo unico que cambia dentro de una transaccion es que no se usan indices.** El
indice vive en el disco y no sabe nada de lo que aun no se ha confirmado, asi
que resolver por indice devolveria el conjunto de antes. Se paga un recorrido
completo a cambio de que el resultado sea el correcto, y `EXPLAIN` lo dice:
la estrategia sale como `transaccion`.

## Lo que da y lo que no

**Da atomicidad.** Ante un error tuyo, ante una excepcion y ante un corte de
corriente: todo o nada. Hay un test que mata el proceso doce veces en mitad de
una transferencia y comprueba que la suma de las dos cuentas nunca cambia.

**Da proteccion contra la actualizacion perdida.** Si alguien modifica por
debajo un documento que la transaccion habia leido, se aborta con un error en
vez de escribir encima:

```
Tx: 'cuentas/a' cambio mientras la transaccion estaba en curso (version 4 -> 5).
No se ha escrito nada; vuelve a intentarlo leyendo de nuevo.
```

Es el fallo de concurrencia mas facil de no ver: dos procesos leen 10, cada uno
resta 3, los dos escriben 7. Faltan tres unidades y no hay ningun error.

**No da aislamiento.** Mientras los cambios se aplican —milisegundos— otro
proceso que lea puede ver la mitad. El aislamiento de verdad necesita MVCC, que
es otro motor. Se dice aqui en vez de dejar que se suponga.

## Como sobrevive a un corte

Una transaccion no puede ser atomica sobre varios archivos si se escribe
directamente: siempre hay un instante en el que van dos de cinco. Lo que si se
puede hacer atomico es **crear un archivo**, y todo se apoya en eso.

```
1. cerrojo de confirmacion    dos transacciones no se entrelazan
2. comprobar versiones        nadie toco por debajo lo que se leyo
3. reservar valores unicos    si va a chocar, que choque antes de escribir
4. escribir el diario         y bajarlo al disco con fsync
5. MARCA DE CONFIRMACION      la frontera: a partir de aqui, ocurrio
6. aplicar                    documento a documento
7. borrar el diario
```

Al abrir la base se miran los diarios que quedaran a medias. La marca decide,
sin ambiguedad:

- **sin marca** — la transaccion no ocurrio. Se tira el diario.
- **con marca** — la transaccion ocurrio. Se termina de aplicar.

Aplicar dos veces el mismo plan deja el mismo resultado, porque se escribe el
documento entero y no un incremento. Por eso repetir la aplicacion tras un corte
es seguro y no hace falta saber por donde se quedo.

> Un detalle honesto: si un corte obliga a repetir la aplicacion, `_version`
> puede haber subido dos en vez de una. El contenido es el correcto; el numero
> de version no cuenta cortes.

## En AxiSQL

```php
$db->insert('cuentas', ['saldo' => 500], 'a');
$db->insert('cuentas', ['saldo' => 500], 'b');

$db->sql('BEGIN');
$db->sql("UPDATE cuentas SET saldo = 470 WHERE id = 'a'");
$db->sql("UPDATE cuentas SET saldo = 530 WHERE id = 'b'");
$db->sql('COMMIT');       // o ROLLBACK
```

`BEGIN TRANSACTION` tambien vale. No se anidan: un `BEGIN` dentro de otro se
rechaza.

`SELECT`, `UPDATE` y `DELETE` dentro de un `BEGIN` ven lo pendiente, igual que
con la version de funcion. Dos `UPDATE` sobre la misma coleccion funcionan —que
es la transferencia de toda la vida— y un `SELECT` posterior devuelve el estado
nuevo, no el de antes.

Aun asi, **la version de funcion es preferible siempre que se pueda**: confirma
o descarta sola, y no hay forma de dejarse una transaccion abierta por un
`return` temprano o una excepcion a medio camino.
