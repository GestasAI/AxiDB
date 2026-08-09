# El puente HTTP

La base de datos atendiendo peticiones de otro proceso, que puede estar en otra
maquina y escrito en otro lenguaje.

## Levantarlo

En una terminal:

```bash
php -S localhost:8000 -t examples/04-puente-http examples/04-puente-http/servidor.php
```

En otra:

```bash
php examples/04-puente-http/cliente.php
```

## Lo que hay que mirar

`api.php` entero, que es el servidor completo:

```php
require __DIR__ . '/../../core/axidb.php';

axidb_http(__DIR__ . '/datos', [
    'origenes' => ['http://localhost:8000', 'http://127.0.0.1:8000'],
]);
```

No hay controladores, ni rutas, ni modelos, ni un ORM. La base de datos se
defiende sola.

Y `cliente.php` no hace `require` del nucleo ni sabe donde estan los datos: solo
manda JSON. Esta en PHP para no pedir nada mas instalado, pero podria ser
`curl`, Python o un navegador. Para el navegador hay un cliente hecho,
`core/axi.js`, que habla este mismo protocolo:
[docs/guide/08-http.md](../../docs/guide/08-http.md).

## El protocolo

Un POST con JSON. Siempre la misma forma:

```json
{ "accion": "insert", "coleccion": "sensores", "datos": { "grados": 21.4 } }
```

| Accion | Que necesita |
|---|---|
| `insert` | `coleccion`, `datos`, opcionalmente `id` |
| `get` | `coleccion`, `id` |
| `update` | `coleccion`, `id`, `datos` |
| `delete` | `coleccion`, `id` |
| `find` | `coleccion`, opcionalmente `donde`, `orden`, `limite` |
| `count` | `coleccion` |
| `sql` | `sentencia` |

La respuesta es `{"ok": true, "dato": ...}` o `{"ok": false, "error": "..."}`.

Y no hay mas acciones. `dropCollection`, borrar la base entera o tocar los
indices no se puede desde fuera, aunque se pida bien: el ultimo apartado del
cliente lo intenta a proposito para que se vea.

## Dos cosas antes de publicarlo

**Tokens.** En local el puente atiende sin token porque solo escucha desde esta
maquina. En cuanto sea accesible desde fuera hay que declararlos:

```php
axidb_http(__DIR__ . '/datos', [
    'origenes' => ['https://tu-dominio.example'],
    'tokens'   => ['lectura' => '...', 'escritura' => '...'],
]);
```

**Los datos fuera del directorio publicado.** Si `datos/` cae dentro de lo que
sirve el servidor web, cualquiera se descarga los documentos por su URL
saltandose el puente, los tokens y todo lo demas. AxiDB deja un `.htaccess`
dentro, pero eso solo lo entiende Apache: el servidor de desarrollo de PHP y
nginx lo ignoran. Por eso `servidor.php` niega `/datos` a mano, y por eso lo
mejor es que ni siquiera este ahi.
