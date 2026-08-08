# Como se guardan los datos: elegir formato por coleccion

> Guia del nucleo (`axidb/core`). Se aplica a lo que devuelve `axidb(...)`.
> Si vienes de [00-cinco-minutos.md](00-cinco-minutos.md), esto es el paso
> siguiente: hasta ahora no habias tenido que elegir nada.

AxiDB guarda cada coleccion de una de dos maneras. La eleccion es **por
coleccion**, no global, y se puede cambiar cuando quieras sin perder nada.

| | `fs` (por defecto) | `packed` |
|---|---|---|
| En disco | un archivo JSON por documento | un archivo por coleccion |
| Se lee con un editor | si | no |
| Diff util en git | si | no |
| Alta de 10.000 documentos | 15 s | 0,4 s |
| Se repara a mano | si | con la herramienta de compactacion |

Numeros medidos con `php bench/comparativa.php` en un portatil con Windows y SSD.
La cifra que importa: **packed escribe unas 40 veces mas rapido**, porque un alta
son dos añadidos al final de un archivo abierto y no la creacion de un archivo
nuevo con su temporal y su renombrado.

---

## Cual elegir

Empieza siempre con `fs`. Es el defecto por un motivo: cuando algo va mal, poder
abrir `datos/pedidos/p-1042.json` con el bloc de notas vale mas que unos
milisegundos.

Pasa una coleccion a `packed` cuando se cumpla alguna de estas:

- Escribes miles de documentos de golpe (importaciones, registros de actividad,
  eventos, metricas).
- La coleccion pasa de unas decenas de miles de documentos y notas que dar de
  alta se ha vuelto lento.
- Nunca vas a mirar esos archivos a mano.

Y dejalo en `fs` para lo que quieras poder leer o corregir a ojo: configuracion,
usuarios, catalogos, cualquier cosa de la que haya pocos documentos.

Mezclar es lo normal. En la misma base de datos:

```php
$db = axidb(__DIR__ . '/datos');

$db->storage()->declararDriver('eventos', 'packed');   // millones, nunca se miran
// 'ajustes' y 'usuarios' se quedan en fs, que es el defecto
```

`declararDriver()` es para colecciones **vacias**, y no mueve nada. Si la
coleccion ya tiene documentos, se niega y te manda a `migrarA()`: cambiar la
declaracion a secas dejaria los documentos viejos en el disco pero invisibles, y
eso no debe poder pasar por despiste.

---

## Cambiar de formato

```php
$movidos = $db->storage()->migrarA('eventos', 'packed');
echo "{$movidos} documentos";
```

Funciona en los dos sentidos y **no toca el contenido**: ni la version, ni la
fecha de alta, ni la de modificacion. Cambiar como se guarda el dato no debe
notarse en el dato.

El orden de los pasos es lo que la hace segura: primero se lee todo con el
formato viejo, luego se escribe entero en el nuevo, y solo entonces se declara el
cambio y se retiran los archivos antiguos. Si falla a mitad, tus datos siguen
donde estaban y se siguen leyendo como siempre.

Los indices no se tocan: son independientes del formato y siguen valiendo.

Para saber en que esta una coleccion:

```php
$db->storage()->driverDe('eventos');   // 'fs' o 'packed'
```

---

## Durabilidad: `safe` o `fast`

Aparte del formato, cada coleccion decide cuanto insiste en que el dato llegue al
disco fisico:

| | Que hace | Cuando |
|---|---|---|
| `safe` (por defecto) | pide al sistema operativo que vacie su cache en cada escritura | datos que no puedes perder |
| `fast` | deja que el sistema escriba cuando le convenga | datos que puedes rehacer |

```php
$db->storage()->declararDurabilidad('metricas', 'fast');
```

Con `safe`, un corte de corriente justo despues de guardar no te quita el
documento. Con `fast`, podrias perder los ultimos segundos. En los dos casos el
documento nunca queda **a medias**: eso lo garantiza el formato, no la
durabilidad.

Tambien se puede cambiar el defecto de toda la base al construirla:

```php
$db = axidb(__DIR__ . '/datos', ['durable' => false]);   // todo en fast
```

---

## Que aparece en disco

En la raiz del directorio de datos, sea cual sea el formato:

```
datos/
  .htaccess           niega el acceso por HTTP. Solo lo entiende Apache
  index.html          vacio, para que un servidor con el listado de directorios
                      abierto no enseñe la lista de colecciones
```

Los pone AxiDB al crear el directorio y no hace falta tocarlos. Son la ultima
defensa por si los datos acaban dentro de la carpeta que sirve el servidor web,
que es justo lo que no hay que hacer.

Con `fs`:

```
datos/pedidos/
  _axidb.json         driver y durabilidad de esta coleccion
  p-1042.json         un documento
  p-1042.json.lock    su cerrojo (ver abajo)
  p-1043.json
  p-1043.json.lock
  _idx/               indices
```

**Los archivos `.lock`.** Cada documento tiene al lado uno vacio con el mismo
nombre y `.lock` al final. Es el cerrojo que impide que dos procesos escriban a
la vez sobre el mismo documento, y esta en un archivo aparte porque el `.json` se
reemplaza entero al guardar: mantener el cerrojo sobre algo que se sustituye no
serviria de nada.

Se quedan ahi despues de escribir, y es correcto que se queden: crearlos y
borrarlos en cada operacion costaria mas que la propia escritura y abriria una
ventana en la que dos procesos podrian cerrar sobre archivos distintos. Ocupan
cero bytes. Al borrar el documento se borra tambien su cerrojo.

Si te molestan a la vista, esa es una razon mas para usar `packed`, que lleva un
unico `_write.lock` por coleccion.

Con `packed`:

```
datos/eventos/
  _axidb.json
  data.axi            los documentos, solo se añade al final
  offsets.log         donde esta cada uno, tambien solo añadiendo
  offsets.idx         instantanea del mapa, para arrancar rapido
  _write.lock
  _idx/
```

`_axidb.json` vive dentro de la propia coleccion a proposito: quien reciba la
carpeta puede saber como esta guardada sin que nadie se lo cuente.

---

## Mantenimiento de `packed`

Como solo se añade al final, modificar o borrar un documento no libera su sitio:
deja hueco muerto. Cuando el hueco pasa del 30% del archivo, la limpieza
periodica lo compacta sola.

```php
$db->storage()->proporcionMuerta('eventos');   // 0.0 a 1.0, para mirarlo
$db->storage()->compactar('eventos');          // compactar ahora, pase lo que pase
```

Compactar reescribe el archivo dejando solo los documentos vivos, trabajando
sobre un temporal: si se interrumpe, no se pierde nada. Merece la pena llamarlo a
mano despues de una purga grande.

Si otro proceso tiene el archivo abierto, la compactacion se salta sin quejarse y
lo intentara la proxima vez. Es mantenimiento oportunista, nunca una correccion
urgente.

---

## Lo que NO cambia al cambiar de driver

Todo lo demas. Los dos formatos pasan exactamente la misma bateria de pruebas y
tienen que dar el mismo resultado en cada una —eso es
`core/tests/test_drivers_paridad.php`, y es la razon por la que puedes cambiar de
formato sin releer tu codigo—:

- La misma API: `insert`, `get`, `update`, `delete`, `all`, `count`.
- Las mismas consultas y los mismos indices.
- El mismo AxiSQL.
- Los mismos metadatos: `id`, `_version`, `_createdAt`, `_updatedAt`.
- Las mismas garantias: nadie lee un documento a medias, y varios procesos
  escribiendo a la vez no se pisan.
