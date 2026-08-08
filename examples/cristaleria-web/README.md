# Cristaleria Los Arcos, desde el navegador

La misma aplicacion que [../cristaleria/](../cristaleria/), que corre en PHP por
la terminal, pero ahora con pantalla y funcionando en el navegador.

**PHP escrito por el desarrollador: 3 lineas** ([api.php](api.php)). Todo lo
demas es HTML y JavaScript.

## Arrancarlo

```bash
cd axidb/examples/cristaleria-web
php -S localhost:8000 servidor.php
```

Y abrir <http://localhost:8000>. El boton "Cargar ejemplo" mete unos datos para
ver la pantalla con algo dentro.

No hay paso de compilacion, ni `npm install`, ni empaquetador. `axi.js` es un
modulo que el navegador carga tal cual, igual que el nucleo es PHP que se copia
tal cual.

> El `servidor.php` del final del comando no es decorativo: niega `/datos/`. Sin
> el, `http://localhost:8000/datos/clientes/xxx.json` devuelve el documento
> entero y la base de datos se descarga saltandose el puente. Esta explicado
> dentro del archivo, con la regla equivalente para nginx.

## Que hay dentro

```
api.php       las 3 lineas de backend
index.html    la aplicacion entera: pantalla y logica
servidor.php  enrutador del servidor de desarrollo: niega /datos/, sirve axi.js
datos/        lo crea AxiDB al primer guardado
```

Merece la pena mirar [api.php](api.php) antes que nada: no hay controladores, ni
rutas, ni modelos, ni un ORM. El navegador pide, el puente comprueba quien es y
que puede hacer, y el motor guarda.

## Antes de publicarlo en internet

Tal como esta, **solo funciona en tu maquina**: sin tokens declarados el puente
unicamente atiende peticiones desde localhost. Es deliberado, para que nadie se
encuentre con una base de datos abierta por no haber leido un parrafo.

Para publicarlo hay que decidir quien entra:

```php
axidb_http(__DIR__ . '/datos', [
    'origenes' => ['https://cristaleriaslosarcos.es'],
    'tokens'   => [
        'el-token-del-taller' => ['colecciones' => '*', 'escribir' => true],
    ],
]);
```

Y en el HTML, `axidb('./api.php', { token: '...' })`.

Un aviso que conviene entender: **un token que llega al navegador lo tiene
cualquiera que abra la pestaña de red.** Para una pantalla interna del taller,
detrás de la clave del wifi o de una autenticacion propia, es razonable. Para
una web publica no: ahi lo que se hace es declarar `publicas` las colecciones que
puede leer todo el mundo —solo lectura, siempre— y dejar las escrituras detras de
tu propio login.

Y lo mas importante: **saca `datos/` del directorio que sirve el servidor.**
AxiDB deja dentro un `.htaccess` que lo niega, pero eso solo lo entiende Apache.
En nginx no hace nada, y entonces `https://tusitio.com/datos/clientes/xxx.json`
es un archivo que cualquiera descarga.

Los detalles estan en [../../docs/guide/08-http.md](../../docs/guide/08-http.md).
