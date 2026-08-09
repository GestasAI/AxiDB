# Esquema y caducidad

Dos cosas que una coleccion puede declarar sobre si misma, y que el motor hace
cumplir sin que haya que acordarse en cada alta. Las dos son opcionales: una
coleccion que no declara nada se comporta como si esto no existiera.

## Esquema

```php
$db->declararEsquema('clientes', [
    'correo' => ['tipo' => 'texto', 'obligatorio' => true],
    'edad'   => ['tipo' => 'entero'],
    'activo' => ['tipo' => 'bool', 'defecto' => true],
]);

$db->insert('clientes', ['correo' => 'ana@ejemplo.com'], 'c1');
// activo queda en true, sin escribirlo
```

Lo que pasa si no se cumple:

```
'clientes/c3': falta el campo obligatorio 'correo'.
'clientes/c4': el campo 'edad' tiene que ser entero y llego texto.
```

**Un campo que no se declara se guarda igual.** Esto no cierra la coleccion:
declaras reglas donde hacen falta y el resto pasa. Si quieres una coleccion
cerrada, eso es otra cosa y AxiDB no la tiene.

### Los tipos

```
texto   entero   decimal   numero   bool   lista   mapa
```

Son pocos a proposito: con seis se adivinan, con treinta hay que recordar cual
era el nombre exacto.

Un entero vale donde se pide `decimal` —3 es un decimal perfectamente valido—
pero un decimal no vale donde se pide `entero`. La asimetria es la de las
matematicas, no un descuido.

### Se valida al declararlo

```php
try {
    $db->declararEsquema('x', ['a' => ['tipo' => 'entergo']]);
} catch (Axi\Core\Exception $e) {
    echo $e->getMessage();
    // Esquema: el tipo 'entergo' de 'a' no existe. Hay: texto, entero, ...
}
```

Un tipo mal escrito no espera a la primera alta para fallar. Si esperara, el
error hablaria del documento y el problema estaria en la declaracion, que es
donde hay que mirar.

### La actualizacion parcial se valida entera

```php
try {
    $db->update('clientes', 'c1', ['correo' => '']);
} catch (Axi\Core\Exception $e) {
    echo $e->getMessage();
    // 'clientes/c1': falta el campo obligatorio 'correo'.
}
```

Se comprueba como queda el documento, no lo que cambia. Vaciar un campo
obligatorio en un `update` parcial no se veria mirando solo el cambio.

### No se comprueba lo que ya hay

A diferencia de `unico()`, declarar un esquema no revisa los documentos
existentes. Un esquema que rechazara la coleccion entera por un documento
antiguo seria imposible de adoptar en algo que ya esta en marcha. Los documentos
viejos se validan cuando se vuelvan a escribir.

---

## Caducidad

```php
$db->declararCaducidad('sesiones', 3600);   // una hora
```

Pasada esa hora desde su ultima escritura, el documento **deja de existir**.

```php
$db->get('sesiones', 's1');        // null
$db->exists('sesiones', 's1');     // false
$db->count('sesiones');            // no lo cuenta
$db->all('sesiones');              // no lo incluye
$db->find('sesiones')->get();      // tampoco
$db->ids('sesiones');              // tampoco
```

Por ninguna puerta, no solo por la que uno se acuerde de mirar. Una caducidad
que se salta por `find()` no es una caducidad.

### Vencer no es borrar

El archivo sigue en el disco hasta el proximo barrido:

```php
$db->storage()->sweep('sesiones', 0);   // ahora si se borra
```

Se hizo asi y no al reves —borrar en una limpieza periodica y devolverlos
mientras tanto— porque entonces "caduca en una hora" significaria "caduca en una
hora, o cuando pase el barrendero, lo que ocurra despues". Eso no es una
caducidad: es una promesa con letra pequeña.

El precio: `count()` deja de ser instantaneo en el formato empaquetado, porque
hay que mirar documento a documento quien sigue vivo. Solo se paga en las
colecciones que declaran caducidad.

### Escribir da cuerda

Se cuenta desde `_updatedAt`, la ultima escritura. Para algo que se escribe una
vez —un token, una entrada de cache— es lo mismo que contar desde el alta. Para
una sesion, cada modificacion la renueva, que es lo que se espera de una sesion.

`declararCaducidad($col, 0)` la desactiva.
