# Notas — App demo de AxiDB embebido

CRUD completo (crear, listar, editar, borrar, buscar) en ~470 líneas
totales. Persistencia con AxiDB embebido. Sin red, sin DB, sin framework.

## Arrancar (en <2 minutos)

```bash
# 1. Sirve el repo
php -S localhost:8000 -t .

# 2. Abre en el navegador
#    http://localhost:8000/axidb/examples/notas/index.php

# 3. Crea, edita, busca. Los datos viven en STORAGE/notas_demo/
```

Si tu hosting es Apache/nginx, sube el directorio entero y la URL queda
`https://tu-dominio/axidb/examples/notas/index.php`. Cero `composer install`.

## Estructura

```
examples/notas/
├── index.php       Lista + buscador + form de creacion (~125 lineas)
├── editor.php      Editar / borrar / metadata (~106 lineas)
├── notas.css       Estilos vanilla (~125 lineas)
├── notas.js        Progressive enhancement: live search, autosave hint, atajos (~115 lineas)
└── README.md       Esto
```

**Cronometro objetivo del plan**: un dev externo replica desde cero en
<2 horas siguiendo solo este README. La medicion humana queda como
exit-gate operativo post-release.

## Que hace cada archivo

### `index.php`

- `GET /index.php` — lista las 100 notas mas recientes en grid de tarjetas.
- `GET /index.php?q=foo` — busca con AxiSQL `WHERE title CONTAINS 'foo' OR body CONTAINS 'foo'`.
- `POST /index.php` con `action=create` — crea una nota nueva, redirige
  a `?ok=<id>`.

### `editor.php`

- `GET /editor.php?id=<id>` — formulario con la nota cargada para editar.
- `POST /editor.php` con `action=update` — guarda cambios.
- `POST /editor.php` con `action=delete` — borra hard la nota.

## Como esta cableado AxiDB

El SDK se carga con `require __DIR__ . '/../../axi.php'`. Eso registra el
autoloader PSR-4 (`Axi\*`). Despues:

```php
$db  = new Axi\Sdk\Php\Client();      // embebido, cero red
$col = $db->collection('notas_demo');

// CRUD fluido
$col->insert(['title' => 'Hola', 'body' => '...']);
$col->orderBy('_updatedAt', 'desc')->limit(100)->get();
$col->update($id, ['body' => 'cambiado']);
$col->delete($id, hard: true);

// AxiSQL para busquedas
$db->sql("SELECT * FROM notas_demo WHERE title CONTAINS '...'");
```

## Atajos de teclado (notas.js)

- `/` enfoca la barra de busqueda (estilo GitHub).
- `Ctrl+Enter` envia el formulario de "Nueva nota".
- `Ctrl+S` guarda en el editor.
- `?` muestra la cheatsheet.

Si JS esta desactivado, la app sigue funcionando — todo es server-rendered;
`notas.js` solo añade progressive enhancement.

## Que NO hace este demo (para mantenerlo en <500 lineas)

- Login/auth: el demo asume usuario unico. Para multi-usuario, usa los
  Ops `auth.create_user`/`auth.login` y cambia la coleccion a
  `notas_<user_id>`.
- Cifrado: las notas estan en claro. Para activar vault:

  ```sql
  CREATE COLLECTION notas_demo WITH (encrypted = true)
  ```

  y `axi vault unlock --password "..."` antes de ejecutar.

- Markdown render: `body` se muestra como texto plano. Anade `Parsedown`
  o similar si quieres markdown. (Eso ya seria framework — no lo cubre
  este demo).
- Paginacion: `LIMIT 100` fijo. Para mas anade `?page=N` y `OFFSET`.

## Migrar a remoto sin tocar la logica

Misma app, AxiDB en otra maquina:

```php
$db = new Axi\Sdk\Php\Client('http://mi-host/axidb/api/axi.php', 'bearer-token');
// El resto del codigo es identico.
```

## Ver tambien

- [`../portfolio/`](../portfolio/) — plantilla mas minimal (un solo archivo).
- [`../remote-client/`](../remote-client/) — mismo flujo via HTTP.
- [`../../docs/guide/01-quickstart.md`](../../docs/guide/01-quickstart.md).
- [`../../docs/guide/03-axisql.md`](../../docs/guide/03-axisql.md) — sintaxis AxiSQL.
