# Perfiles

Para que es esta base de datos.

```php
$db = new Axi\Core\Db('./datos', ['profile' => 'core']);
```

| Perfil | Trae, ademas de lo anterior | Para |
|---|---|---|
| `core` | documentos, indices, consultas, AxiSQL, copias, salud | un blog |
| `docs` | esquema, caducidad, unicidad, transacciones, JOIN, cifrado | un comercio |
| `ai` | vectores y agentes | busqueda por significado |

Son acumulativos: `ai` trae todo lo de `docs`, y `docs` todo lo de `core`.

**Sin declarar perfil esta todo disponible.** Es lo que habia antes de que los
perfiles existieran, y una instalacion que actualiza no puede empezar a fallar
por una funcion que ya usaba.

## Que hace un perfil, y que no

**Lo que NO hace: cargar menos codigo.** El autoloader ya es perezoso —una clase
que no se usa no se lee del disco— asi que un "cargador de modulos por perfil"
no ahorraria ni un byte. Seria teatro, y aqui no se hace teatro.

**Lo que si hace: reducir superficie y avisar.** Menos cosas que aprender
cuando empiezas, y un error claro cuando el proyecto se sale de lo que dijo ser:

```php
$blog = new Axi\Core\Db('./datos', ['profile' => 'core']);

try {
    $blog->transaction(function ($tx) { /* ... */ });
} catch (Axi\Core\Exception $e) {
    echo $e->getMessage();
}
```

```
transaccion() y BEGIN necesita el perfil 'docs' y esta base usa 'core'.
Cambialo al abrirla: new Db($dir, ['profile' => 'docs']).
Los datos no se tocan: un perfil solo dice que partes del motor se usan.
```

El mensaje lleva las tres cosas que hacen falta para resolverlo sin ir a buscar
la documentacion: que se ha intentado, donde vive, y la linea exacta que hay que
cambiar.

## Cambiar de perfil es cambiar una linea

No hay volcado, ni migracion, ni una consulta que reescribir. Un blog que empieza
a vender:

```php
// Antes
$db = new Axi\Core\Db('./datos', ['profile' => 'core']);

// Despues
$db = new Axi\Core\Db('./datos', ['profile' => 'docs']);
```

Los mismos archivos, los mismos documentos, los mismos indices. Lo unico que
cambia es que ahora `transaccion()`, `declararEsquema()`, `unico()`, `cifrar()`
y `JOIN` dejan de negarse.

Hay un test que lo hace de verdad —`test_perfiles.php`— llevando un blog a
tienda y luego a busqueda por significado, comprobando en cada salto que los
datos siguen intactos y que las consultas de antes valen igual.

## Y desde axidb.json

```json
{ "data": "./datos", "perfil": "core" }
```

Igual que `durable` o `http`: lo que se pasa al abrir manda sobre el archivo.

## Una nota sobre lo que no hay

El plan de esta ola listaba tambien "colas" en el perfil `docs`. **No existen**,
y por eso no aparecen en la tabla. Un perfil que promete algo que el motor no
tiene es peor que un perfil pequeño.
