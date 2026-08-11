# Seguridad

AxiDB se toma la seguridad como parte del producto, no como un añadido. Cada
superficie tiene una suite de ataque que se ejecuta en el mismo gate que el resto
de los tests: **561 ataques que hoy rebotan**, y ninguno puede reabrirse sin que
el gate se ponga en rojo.

Este documento dice qué protege AxiDB, qué **no** —para que nadie lo descubra el
día equivocado— y cómo avisar de un fallo.

## Versiones con soporte

AxiDB está en el ciclo `0.x`: la API todavía puede cambiar y solo la última
versión menor recibe correcciones de seguridad.

| Versión | Soporte |
|---------|---------|
| 0.8.x   | Sí      |
| < 0.8   | No      |

## El modelo, en una frase

AxiDB es una base de datos **embebida**, como SQLite: no tiene red, ni puerto, ni
servidor. Se ejecuta detrás de un `require` en PHP. Por eso **no hay autenticación,
ni roles, ni TLS** dentro de AxiDB: esa mitad —"quién se conecta y qué puede
hacer"— es responsabilidad de la aplicación que la integra. AxiDB responde de lo
que guarda: que no se lea sin permiso, que no se pierda y que no se pueda alterar
sin que se note.

El modelo de atacante de cada suite está escrito en su cabecera. Los cuatro que
importan:

- **RED** — llega por internet, sin credenciales. Solo alcanza el puente HTTP
  (`Http/Bridge`), la única pieza que mira afuera; todo lo demás está detrás de un
  `require`.
- **DISCO ROBADO** — se lleva el disco o una copia de seguridad, pero no la clave.
  Es el caso del portátil perdido, el bucket abierto y el hosting compartido.
- **ESCRITURA AJENA** — puede escribir en el directorio de datos, pero no tiene la
  clave.
- **USO HONESTO** — no hay atacante: un uso normal no debe corromper ni perder
  datos.

## Qué protege (y con qué test)

| Amenaza | Defensa | Suite |
|---|---|---|
| Robo del disco / de una copia | Cifrado en reposo AES-256-GCM (`encrypt()`), opt-in por colección. Índices con nombre HMAC, sin fugas de valores. | `test_sec_cripto` |
| Perder datos o copias | Escritura atómica (temp+rename) con `fsync`, transacciones con recuperación, seguro ante procesos concurrentes y muertes, copias **coherentes** con `sha1` por archivo. | `test_sec_carrera` |
| Devolver un dato ajeno como bueno | El bloque cifrado se ata a su colección+id (AAD); `role`/`id`/fechas no se pueden inyectar por el cuerpo. | `test_sec_integridad`, `test_sec_cripto` |
| Salir del directorio de datos | Validación de nombres (traversal, bytes altos, nombres reservados de Windows, enlaces y junctions). | `test_sec_rutas` |
| Inyección por AxiSQL | Un literal nunca se convierte en sintaxis; sin subconsultas correlacionadas ni evaluación de expresiones ajenas. | `test_sec_sql` |
| Denegación de servicio | Límites de tamaño y profundidad en el parser, sin bombas de descompresión ni recursión sin fondo. | `test_sec_recursos` |
| Superficie de internet | El puente HTTP valida tipos, no filtra rutas del servidor y comprueba permisos por colección. | `test_sec_http` |

## Qué NO protege (fronteras conocidas)

Escritas a propósito, porque una promesa a medias es peor que no prometer:

- **Compromiso total del servidor.** El cifrado en reposo protege de un disco o
  una copia robados. Si el atacante controla el proceso vivo, la clave le es
  alcanzable —la misma frontera que en Postgres/Oracle—.
- **Rollback en reposo.** Un atacante con escritura en el disco puede reponer una
  versión anterior byte a byte de un documento cifrado; es indistinguible de que
  nunca se hubiera actualizado, igual que borrarlo es indistinguible de que nunca
  hubiera existido. Detectarlo pediría un ancla monótona fuera del disco, que
  AxiDB no asume. Sí se detecta el injerto (un bloque viejo bajo una versión nueva).
- **Metadatos en claro.** Aun cifrando, `id`, `_version`, `_createdAt` y
  `_updatedAt` quedan en claro para poder localizar y versionar sin abrir el
  contenido. No metas secretos en el id.
- **Cifrado superado en el log empaquetado.** El driver `packed` es append-only:
  un bloque superado sigue en el log hasta que se compacta (`compact()`).
- **Autenticación, roles, red, TLS.** No son de AxiDB. Los pone la aplicación.

## Cómo avisar de un fallo

Si crees haber encontrado una vulnerabilidad, **no abras un issue público**. Usa
el aviso privado de seguridad de GitHub:

> Repositorio → pestaña **Security** → **Report a vulnerability**.

Incluye la versión, un caso mínimo que lo reproduzca y, si puedes, el impacto
según el modelo de atacante de arriba. Respondemos lo antes posible, acordamos un
plazo de divulgación y publicamos la corrección con crédito si lo deseas.

Cada fallo confirmado entra como un ataque nuevo en la suite correspondiente:
así, una vez cerrado, se queda cerrado.
