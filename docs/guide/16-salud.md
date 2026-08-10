# Saber que esta pasando

Lo que hace falta para poner esto en produccion y no enterarse de los problemas
por un cliente.

## Que forma tienen los datos

```php
$db->insert('socios', ['nombre' => 'Ana', 'ciudad' => 'Murcia']);

foreach ($db->describe('socios') as $campo) {
    echo $campo['campo'], ' ', $campo['tipo'],
         ' (', $campo['documentos'], '/', $campo['de'], ")\n";
}
```

```
nombre     texto            900/900
telefono   texto              3/900
saldo      decimal|entero   890/900
```

Aqui no hay esquema obligatorio, asi que esto no es una declaracion: es una
**foto de lo que hay**. Por eso dice en cuantos documentos aparece cada campo.

Y por eso importa: saber que `telefono` esta en 3 de 900 vale mas que saber que
existe. Un tipo que sale como `decimal|entero` avisa de que ese campo se ha
guardado de dos maneras, que es la clase de cosa que rompe una consulta seis
meses despues.

Lo mismo con `DESCRIBE clientes` en AxiSQL.

## Cuanto ocupa una coleccion

```php
$db->stats('socios');
```

```
[
  'documentos'       => 900,
  'driver'           => 'packed',
  'durabilidad'      => 'safe',
  'cifrada'          => false,
  'caducidad'        => 0,
  'unicos'           => ['correo'],
  'indices'          => ['correo', 'ciudad'],
  'vectores'         => false,
  'bytes'            => 481_204,
  'proporcionMuerta' => 0.31,
]
```

`proporcionMuerta` es cuanto del archivo es espacio que ya no sirve. Solo pasa
con el formato empaquetado, que escribe añadiendo; se limpia con
`$db->storage()->compact()`.

## Una revision de todo

```php
$revision = $db->checkup();
```

Pensada para un cron o un panel:

```
[
  'colecciones' => 7,
  'documentos'  => 12_430,
  'bytes'       => 8_940_112,
  'avisos'      => [
      [
        'coleccion' => 'clientes',
        'gravedad'  => 'grave',
        'que'       => "al indice de 'correo' le faltan 3 documentos, asi que by() no los encuentra",
        'hacer'     => "reindex('clientes')",
      ],
  ],
]
```

**Cada aviso dice que pasa y que hacer.** Un diagnostico que solo dice que hay
un problema no sirve de nada a las tres de la mañana.

### Que vigila

| Gravedad | Que | Por que importa |
|---|---|---|
| grave | a un indice le faltan entradas | `by()` no encuentra documentos que existen, y no da ningun error |
| grave | un indice de una version anterior sin nombre de campo | no se puede mantener; hay que rehacerlo |
| atencion | a un indice le sobran entradas | si el campo es unico, esos valores estan bloqueados sin estarlo |
| atencion | mas de un 25% de espacio muerto | el archivo crece sin motivo |

Un panel de salud que siempre dice "todo bien" es peor que no tener panel,
porque da confianza sin motivo. Por eso el test de esto **rompe cosas a
proposito** y exige que salgan.
