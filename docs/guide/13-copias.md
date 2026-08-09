# Copias de seguridad

```php
$hecho = $db->copiar('./copias');
// ['id' => '20260809-121321-004512-c-6acae0', 'tipo' => 'completa',
//  'archivos' => 8, 'guardados' => 8, 'bytes' => 2272, 'archivo' => '...']
```

Se copia el directorio de datos entero: documentos, ajustes, indices y vectores.
Todo, porque el criterio de "esto ya se puede reconstruir" es justamente el que
falla el dia que hace falta la copia.

## Incrementales

```php
$db->copiar('./copias', incremental: true);
```

Guarda solo los archivos que han cambiado desde la ultima copia de esa carpeta.
Si no hay ninguna, sale completa: es lo unico que puede salir, y dar un error
solo serviria para obligarte a escribir un `if`.

## Restaurar

```php
$hecho = $db->copiar('./copias');

$db->restaurar($hecho['archivo']);
```

**Restaurar SUSTITUYE.** Lo que la copia no tiene se borra. No es mezclar dos
momentos: es volver a uno. Un documento creado despues de la copia desaparece,
que es exactamente lo que significa restaurar.

Con una incremental, la cadena hasta su copia completa se resuelve sola. Si
falta un eslabon, se dice y no se restaura nada.

### Una copia dañada no hace daño

El orden de los pasos esta pensado para eso:

```
1. resolver la cadena       de la incremental hasta su completa
2. LEER Y COMPROBAR todo    los sha1 de todas las entradas, sin escribir nada
3. escribir en temporales   al lado del destino, no encima
4. cambiarlos de sitio
```

Hasta el paso 4 no se ha tocado un solo archivo de los datos vivos. Si la copia
esta dañada se descubre en el 2:

```
Copia: 'clientes/c1.json' no cuadra con su huella.
El archivo de copia esta dañado y no se restaura nada.
```

Restaurar medio conjunto de datos corrupto encima de los buenos es peor que no
tener copia, porque destruye lo unico que quedaba.

## Ver que hay guardado

```php
foreach ($db->copias('./copias') as $c) {
    echo $c['momento'], ' ', $c['tipo'], ' (', $c['archivos'], " archivos)\n";
}
```

De la mas reciente a la mas antigua. Lee solo las cabeceras, asi que listar
veinte copias de un gigabyte cuesta lo mismo que listar veinte archivos vacios.

Un archivo ilegible entre las copias no rompe el listado: sale marcado como
`ilegible` y las demas se siguen viendo, que es justo cuando mas falta hacen.

## El formato, por si algun dia no hay AxiDB

Formato propio, a proposito. La copia del motor anterior usaba `ZipArchive`, que
es una extension **opcional** de PHP: en la maquina donde se escribio esto no
esta instalada, asi que aquel codigo no habria podido hacer una sola copia. Una
funcion de respaldo que depende de algo que puede faltar es precisamente la que
no puede faltar.

Es deliberadamente tonto, para poder abrirlo a mano:

```
AXIDB-COPIA-1\n
{"id":"...","momento":"...","tipo":"completa","huellas":{...}}\n
{"ruta":"clientes/c1.json","bytes":132,"sha1":"..."}\n
<132 bytes tal cual>\n
{"ruta":"clientes/_axidb.json","bytes":97,"sha1":"..."}\n
<97 bytes tal cual>\n
```

Los bytes van crudos, sin base64: un indice vectorial son megabytes binarios y
codificarlos en texto los haria un tercio mas grandes a cambio de nada, porque
la longitud ya va delante.

Cada entrada lleva su `sha1`. Una copia que no puede comprobar si esta intacta
solo sirve para tener la conciencia tranquila.

## Lo que NO entra en la copia

```
_tx/, *.lock, *.tmp.*
```

Los cerrojos y los temporales son estado del proceso vivo, no datos. Las
transacciones a medias se resuelven ANTES de copiar: guardar un diario sin
resolver lo reaplicaria al restaurar, en un momento que ya no tiene nada que ver.

---

# Llevarse los datos

Esto es otra cosa, y conviene no confundirla con una copia. Una copia guarda el
directorio entero para volver a un momento. Esto saca los **documentos** de una
coleccion para llevarlos a otro sitio.

```php
$db->insert('proveedores', ['nombre' => 'Maderas, S.L.', 'saldo' => 120.5]);

$db->exportar('proveedores', './proveedores.csv');
$db->exportar('proveedores', './proveedores.json');

$db->importar('copia_proveedores', './proveedores.csv');
```

El formato sale de la extension, o se dice a mano con un tercer argumento.

El CSV se escribe y se lee con las funciones del nucleo de PHP, que ya saben de
comillas, comas dentro del valor y saltos de linea dentro de una celda. La
cabecera es la union de **todos** los campos que aparecen, no los del primer
documento: mirar solo el primero es el error clasico y pierde en silencio
cualquier campo que aparezca mas abajo.

## Importar no es una puerta trasera

Los documentos entran por el mismo sitio que si los escribieras a mano, asi que
pasan por el esquema, la unicidad, los indices y los vectores. Un CSV con dos
correos repetidos en una coleccion con `UNIQUE` se rechaza, igual que si los
insertaras uno a uno.
