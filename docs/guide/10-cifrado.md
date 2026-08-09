# Cifrado en reposo

Una coleccion cifrada guarda su contenido cerrado con AES-256-GCM. Quien copie
la carpeta se lleva bloques ilegibles: sin la contraseña no hay forma de abrirlos.

```php
$dir = \sys_get_temp_dir() . '/mi-negocio';
$db  = new Axi\Core\Db($dir, ['clave' => 'la contraseña del negocio']);

$db->cifrar('clientes');
$db->insert('clientes', ['email' => 'ana@ejemplo.com', 'iban' => 'ES91...'], 'c1');

echo $db->get('clientes', 'c1')['iban'];   // ES91... — de puertas adentro, igual
```

De puertas afuera no cambia nada: consultas, indices, `find()`, los dos drivers
y las migraciones funcionan como siempre.

## Lo que hace falta

La extension `openssl` de PHP. **Es la unica parte de AxiDB que no funciona solo
con `json`**, y por eso es opcional: si no la tienes, todo lo demas sigue igual y
`cifrar()` te lo dice con claridad en vez de fallar de forma rara.

## Lo que NO tapa, y conviene saberlo antes

En claro se quedan cuatro cosas, porque el motor las necesita para localizar,
versionar y barrer documentos sin abrirlos:

```
id      _version      _createdAt      _updatedAt
```

Es lo mismo que hace un disco cifrado, que oculta el contenido de los archivos y
no sus nombres. Consecuencias concretas:

- **No metas el secreto en el id.** Un id `pedido-de-juan-perez` se lee entero.
- Se sabe **cuando** se escribio cada documento.
- Se sabe **cuantos** documentos hay.

Si eso ya es demasiado, el cifrado por coleccion no te sirve: cifra el volumen.

## Lo que si protege

- **Manipulacion.** GCM autentica. Si alguien cambia un byte del archivo, abrir
  falla; no devuelve un dato distinto haciendose pasar por bueno.
- **Suplantacion entre documentos.** Cada bloque esta atado a su coleccion y a su
  id, asi que copiar el archivo del administrador encima del tuyo no cuela: misma
  clave, y aun asi no abre.
- **El indice.** En una coleccion cifrada los valores indexados se guardan como
  hash. Sin eso, un indice por `email` escribiria `_idx/email/ana@ejemplo.com.json`
  y publicaria como nombre de archivo justo el dato que se acababa de cifrar.
- **El formato empaquetado.** Al cifrar una coleccion que ya tenia datos, el log
  se compacta. Como solo sabe añadir, si no se compactara la version en claro se
  quedaria fisicamente en el archivo, unos bytes por detras de la cifrada.

## Cifrar y vectores no se mezclan

```php
$dir = \sys_get_temp_dir() . '/mi-negocio';
$db  = new Axi\Core\Db($dir, ['clave' => 'la contraseña del negocio']);

$db->cifrar('notas');

try {
    $db->vectores('notas');
} catch (Axi\Core\Exception $e) {
    echo $e->getMessage();   // se niega, y dice por que
}
```

No es una limitacion tecnica: de un embedding se puede reconstruir
aproximadamente el texto que lo genero. Guardar el documento cerrado y su vector
en claro al lado dejaria el contenido accesible por la puerta de atras mientras
la coleccion dice estar cifrada. Se rechaza en vez de avisarlo en un parrafo,
porque una promesa de cifrado a medias es peor que no cifrar: el que la usa cree
estar protegido.

## Contraseña equivocada

Se dice claro y de inmediato, en vez de devolver basura o hablar de un documento
corrupto:

```
Cifrado: la contraseña no abre esta base de datos.
Los datos estan intactos; la clave es otra.
```

La clave nunca se guarda. De la contraseña se deriva con PBKDF2-SHA256 y 210.000
vueltas —lo que recomienda OWASP—, con una sal por base de datos en `_cifrado.json`.
Si pierdes la contraseña, los datos no se recuperan. Eso es lo que significa
cifrar.
