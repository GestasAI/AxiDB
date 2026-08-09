# La base de datos desde el navegador

> Guia del nucleo (`axidb/core`). Aqui se deja de escribir PHP.

Hasta ahora AxiDB vivia dentro de tu PHP. El puente HTTP le pone una puerta para
que el navegador hable con el directamente, sin que tengas que escribir
controladores, rutas ni un API por tu cuenta.

Tu backend entero:

```php
require __DIR__ . '/axidb/core/axidb.php';
axidb_http(__DIR__ . '/datos', ['origenes' => ['https://miweb.com']]);
```

Y en la pagina:

```html
<script type="module">
  import { axidb } from './axidb/core/axi.js';

  const db = axidb('/api.php');
  const vaso = await db.insert('productos', { nombre: 'Vaso', centimos: 350 });
  const baratos = await db.find('productos').where('centimos', '<', 500).get();
</script>
```

Eso es todo. Hay un ejemplo completo y funcionando en
[../../examples/04-puente-http/](../../examples/04-puente-http/): un servidor de
**cuatro lineas de PHP** y un cliente que no sabe donde estan los datos.

---

## Lo primero, porque es lo que muerde

**Saca el directorio de datos de la carpeta que sirve el servidor web.**

Si `datos/` esta dentro, cualquiera pide
`https://tusitio.com/datos/clientes/c1.json` y se lleva el documento entero:
sin token, sin pasar por el puente, sin aparecer en ningun registro tuyo.

AxiDB deja dentro un `.htaccess` que lo niega, pero **eso solo lo entiende
Apache**. En nginx y en el servidor de desarrollo de PHP no hace absolutamente
nada. Comprobado, no supuesto.

La estructura correcta:

```
/var/www/misitio/          <- esto es lo que sirve el servidor
    index.html
    api.php
    axidb/                 <- el nucleo, para poder servir axi.js
/var/www/datos/            <- FUERA. Aqui viven los datos.
```

```php
axidb_http('/var/www/datos', ['origenes' => ['https://misitio.com']]);
```

Si no puedes moverlo, al menos niegalo en la configuracion del servidor:

```nginx
location ~ /datos/ { deny all; }
```

---

## Quien puede hacer que

Sin configurar nada, **el puente solo atiende peticiones desde la propia
maquina**. Funciona mientras desarrollas y se niega en cuanto la maquina deja de
ser la tuya. Nadie se encuentra una base de datos abierta por no haber leido un
parrafo.

Para abrirla hay que decidirlo, y hay tres piezas para hacerlo.

### Tokens con ambito

```php
axidb_http('/var/www/datos', [
    'origenes' => ['https://misitio.com'],
    'tokens'   => [
        'e3b0c44298fc1c149afbf4c8996fb924' => ['colecciones' => ['pedidos'], 'escribir' => true],
        '9f86d081884c7d659a2feaa0c55ad015' => ['colecciones' => '*',         'escribir' => false],
    ],
]);
```

El primero solo ve `pedidos`, y puede escribir. El segundo lo lee todo y no
escribe nada. Un token de `pedidos` que pide `usuarios` recibe un 403.

El ambito vale tambien para AxiSQL: el puente analiza la sentencia antes de
ejecutarla, asi que un token de solo lectura no borra una coleccion con un
`DELETE`. Esa puerta de atras esta cerrada.

El token viaja en la cabecera, nunca en la URL:

```js
const db = axidb('/api.php', { token: 'e3b0c44298fc1c149afbf4c8996fb924' });
```

> **Un token en el JavaScript de una web publica lo tiene cualquiera** que abra
> la pestaña de red del navegador. Para una pantalla interna esta bien. Para una
> web abierta, usa `publicas` para lo que puede leer todo el mundo y deja las
> escrituras detras de tu propio login.

### Colecciones publicas

```php
axidb_http('/var/www/datos', ['publicas' => ['catalogo', 'articulos']]);
```

Cualquiera las lee sin token, desde donde sea. **Y nadie las escribe**, ni con
token ni sin el: un catalogo que todos pueden leer no es un catalogo que todos
puedan cambiar. Es lo que quieres para la carta, el catalogo o el blog.

### Solo lectura

```php
axidb_http('/var/www/datos', ['soloLectura' => true, 'tokens' => [/* ... */]]);
```

Ninguna escritura pasa, aunque el token tenga permiso. Util para una replica, un
panel de consulta o mientras haces mantenimiento.

---

## Los origenes

Solo los navegadores de las paginas que declares podran llamar al puente:

```php
axidb_http('/var/www/datos', [
    'origenes' => ['https://misitio.com', 'http://localhost:3000'],
]);
```

La comparacion es exacta sobre esquema, host y puerto. `https://misitio.com` no
autoriza a `https://misitio.com.otrositio.net`, ni a `http://misitio.com`, ni a
un subdominio. Sin origenes declarados no se responde ninguna cabecera CORS, con
lo que solo funciona desde tu propia pagina, que es lo habitual.

---

## El protocolo, si quieres hablar sin el cliente

Un `POST` con JSON. Siempre a la misma direccion.

```json
{ "accion": "get", "coleccion": "productos", "id": "v1" }
```

| accion | que lleva |
|---|---|
| `insert` | `coleccion`, `datos`, `id` opcional |
| `get` | `coleccion`, `id` |
| `update` | `coleccion`, `id`, `datos`, `reemplazar` opcional |
| `delete` | `coleccion`, `id` |
| `find` | `coleccion`, `donde`, `orden`, `limite`, `salto`, `campos` |
| `count` | `coleccion`, `donde` |
| `sql` | `sentencia` |

Y siete son todas: lo que no esta en esa tabla no se puede pedir desde fuera.

La respuesta tiene siempre la misma forma, con el codigo HTTP de verdad:

```json
{ "ok": true,  "dato": { "id": "v1", "nombre": "Vaso" } }
{ "ok": false, "error": "Este token no alcanza a 'usuarios'." }
```

`400` si la peticion viene mal, `401` si falta la llave, `403` si la tienes pero
no llega, `413` si el cuerpo pasa de 256 KB, `405` si no es POST. El `500` queda
para los fallos de verdad, y entonces no cuenta nada: el detalle va al registro
de errores del servidor, no al navegador.

---

## Un aviso sobre los numeros

JSON tiene **un solo tipo numerico**. Un precio de `4.0` viaja como `4` y llega
al servidor como entero. La API embebida en PHP si distingue los dos, el cable no.

Guarda el dinero en centimos enteros, o como cadena. Es la practica correcta de
todas formas, y asi no depende de por donde entro el dato.

---

## Si no quieres escribir ni esas tres lineas

El nucleo trae `core/api.php`, un endpoint hecho. Apunta el servidor ahi y
configura todo en un `axidb.json`.

**Donde va exactamente ese archivo.** Si copiaste el nucleo en
`vendor/axidb/`, cualquiera de estos dos sitios vale:

```
vendor/axidb/axidb.json     <- dentro de la carpeta del nucleo
vendor/axidb.json           <- justo al lado de esa carpeta
```

Tambien se mira el directorio de trabajo del proceso, pero **no lo uses**: en una
peticion web depende del servidor y de como este configurado, y acabas con una
configuracion que funciona en tu maquina y no en el servidor.

Dentro va esto:

```json
{
  "data": "../datos",
  "http": {
    "origenes": ["https://misitio.com"],
    "publicas": ["catalogo"]
  }
}
```

**Sin esa seccion `http`, `core/api.php` contesta 503 y no hace nada.** Es a
proposito: el nucleo se copia dentro de proyectos y los proyectos se despliegan
enteros, asi que ese archivo acaba publicado en sitios donde nadie decidio
publicar una base de datos. Que el archivo exista no es abrir una API; abrirla se
dice.
