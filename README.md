# AxiDB

**Una base de datos de documentos que es una carpeta.** La copias en tu proyecto,
la usas, y ya esta. Sin servidor que instalar, sin extensiones de PHP, sin
Composer, sin paso de compilacion.

```php
require 'axidb/core/axidb.php';

$db = axidb(__DIR__ . '/datos');

$db->insert('clientes', ['nombre' => 'Ana Ruiz', 'ciudad' => 'Murcia']);

foreach ($db->find('clientes')->where('ciudad', '=', 'Murcia')->get() as $c) {
    echo $c['nombre'], "\n";
}
```

Eso es la instalacion entera. No hay un paso dos.

---

## Por que existe

Para un proyecto pequeño o mediano, montar MySQL o PostgreSQL es traer un
servidor, un usuario, una contraseña, un backup y un sitio donde alojarlo, para
guardar unos miles de documentos. SQLite resuelve parte del problema pero sigue
siendo relacional, con su esquema y sus migraciones.

AxiDB va por otro lado: **documentos sin esquema, en archivos que puedes abrir
con el bloc de notas**, con indices, consultas y SQL cuando hacen falta.

```
datos/
  clientes/
    c-1042.json          <- esto es un documento. Lo puedes leer, y arreglar a mano
    c-1043.json
    _idx/ciudad/         <- indice por ciudad
  presupuestos/
    data.axi             <- o el formato empaquetado, si la coleccion es grande
```

---

## Que trae

| | |
|---|---|
| **CRUD** | `insert`, `get`, `update`, `delete`, `all`, `count` |
| **Consultas** | `where / orderBy / limit / offset / select`, encadenadas |
| **Indices** | por cualquier campo; se usan solos cuando la consulta encaja |
| **AxiSQL** | `SELECT`, `INSERT`, `UPDATE`, `DELETE`, indices y `EXPLAIN` |
| **Dos formatos** | `fs` legible archivo a archivo, o `packed` unas 40 veces mas rapido escribiendo |
| **Puente HTTP** | la base de datos desde el navegador, con tokens y CORS |
| **Cliente JavaScript** | `axi.js`, un modulo ES sin dependencias ni empaquetador |

Escritura atomica siempre, `fsync` opcional, y bloqueo para que varios procesos
escriban a la vez sin pisarse. Eso no es una caracteristica: es el minimo para
poder llamarse base de datos, y tiene un test que mata el proceso mas de mil
veces para demostrarlo.

---

## Cuando NO usarlo

Conviene decirlo antes que las virtudes:

- **Si necesitas transacciones sobre varias colecciones.** No las hay. Cada
  documento se escribe entero o no se escribe; dos documentos a la vez, no.
- **Si tus consultas cruzan colecciones.** No hay `JOIN`. Se resuelve leyendo dos
  veces desde tu codigo, y a partir de cierto tamaño eso duele.
- **Si necesitas el maximo rendimiento bruto.** SQLite escribe unas 17 veces mas
  rapido. Los numeros estan mas abajo, sin maquillar.
- **Si vas a tener decenas de millones de documentos.** Esto es un motor de
  archivos; a esa escala quieres otra cosa.

Para una web, una tienda pequeña, un panel interno, un blog o el estado de un
agente de IA, encaja bien. Para el sistema de una aerolinea, no.

---

## Rendimiento, sin maquillar

10.000 documentos, en un portatil con Windows y SSD:

```
                        AxiDB fs   AxiDB packed      SQLite
alta masiva (ms)          14.938            375          22
1000 lecturas (ms)            44             21           3
tamaño en disco (KB)       2.312          1.844       1.424
```

Reproducible con `php bench/comparativa.php`, que ademas compara con su propia
linea base y avisa si algo empeora.

**AxiDB no alcanza a SQLite y no lo promete.** Lo que ofrece a cambio es cero
instalacion, documentos sin esquema y una carpeta que se copia.

---

## Requisitos

- PHP 8.1 o superior
- La extension `json`, que viene con PHP

Nada mas. **Cero dependencias de terceros**, ni en produccion ni en desarrollo.
No hay `vendor/`, ni nada que descargar.

---

## Por donde seguir

| Documento | Que cuenta |
|---|---|
| [docs/guide/00-cinco-minutos.md](docs/guide/00-cinco-minutos.md) | De carpeta vacia a CRUD funcionando |
| [docs/guide/07-drivers.md](docs/guide/07-drivers.md) | Elegir como se guarda cada coleccion |
| [docs/guide/08-http.md](docs/guide/08-http.md) | La base de datos desde el navegador |
| [examples/](examples/) | Una cristaleria, un blog, y la misma cristaleria en web |

Con Composer, si lo prefieres:

```bash
composer require gestasai/axidb
```

Pero no hace falta, y ese es el punto.

---

## Estado

**Version 0.2.0.** El 0 va en serio: la API todavia puede cambiar. Al llegar a
1.0.0 dejara de poder hacerlo sin subir el numero mayor. Preferimos decirlo asi a
poner un 1.0 y romperlo en la siguiente version.

Lo que si esta asentado son las garantias de datos: escritura atomica,
concurrencia y durabilidad tienen tests que los demuestran matando el proceso, no
comentarios que lo afirman.

```bash
php core/tests/run.php       # la suite entera
php bench/comparativa.php    # los numeros de arriba, en tu maquina
```

---

## Licencia

[Apache 2.0](LICENSE). Uso libre, tambien comercial y en producto cerrado, con
concesion expresa de patentes. Ver [NOTICE](NOTICE).

Copyright 2026 GestasAI.

---

> **Sobre las versiones anteriores.** Hasta agosto de 2026, AxiDB era un motor de
> 27.000 lineas que llevaba dentro un CMS, temas y un panel web. Aquello se
> reescribio entero: lo que hay aqui son las ~4.700 lineas de `core/`, y nada mas.
> Si vienes de la version antigua, el CHANGELOG cuenta que cambio.
