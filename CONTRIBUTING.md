# Contribuir a AxiDB

Gracias por querer echar una mano. AxiDB es pequeño a propósito y tiene unas
pocas reglas que no se negocian: son las que lo mantienen simple, portable y
seguro. Léelas antes de abrir un PR y te ahorrarás la vuelta.

## Qué es (y qué no)

AxiDB es una base de datos documental **embebida**, en PHP puro. Guarda cada
documento como JSON en el disco, sin servidor y sin instalar nada.

- **Cero dependencias de Composer.** La única extensión obligatoria es `json`
  (`openssl` solo para el cifrado). Es lo que le deja correr en cualquier hosting
  compartido sin permisos. **Nunca** añadas una dependencia: si algo necesita una
  librería externa, casi siempre es que no es de AxiDB.
- **No conoce ningún dominio.** AxiDB no sabe qué es un usuario, un pedido ni un
  local: solo colecciones, documentos y campos. La lógica de negocio vive en quien
  la integra, nunca dentro de `core/`. Es la analogía WordPress:MySQL — el motor
  guarda, la app decide qué significa.

## Poner en marcha

Solo hace falta PHP 8.1–8.4:

```bash
git clone https://github.com/guiacarlos/axidb.git
cd axidb
php core/tests/run.php        # el gate: si esto pasa, no has roto nada
```

No hay `composer install`, ni `npm`, ni build. Si tu PHP tiene `openssl`, corren
también los tests de cifrado; si no, se saltan solos.

## El gate

`core/tests/run.php` ejecuta **toda** la suite —núcleo y ataques— y tiene que
quedar en verde antes de proponer un cambio. Dos vistas más:

```bash
php core/tests/run_sec.php    # solo la superficie de ataque (561 ataques)
php core/tests/test_agnostico.php   # las reglas de estilo, automatizadas
php core/tests/<uno>.php       # un archivo suelto mientras iteras
```

Cada cambio llega con su test. Un arreglo de bug trae el test que fallaba antes;
una función nueva, el que la cubre.

## Reglas que comprueba la máquina

`test_agnostico.php` las hace cumplir, así que no son opinión:

- **Máximo 250 líneas por archivo.** Si un archivo crece, parte una
  responsabilidad a un trait o a otra clase. La cabecera de cada archivo explica
  su porqué.
- **La API pública, en inglés.** `insert`, `get`, `update`, `unique`, `encrypt`,
  `begin`/`commit`/`rollback`, funciones AxiSQL estándar (`UPPER`, `ROUND`…).
- **Nada de tildes ni `ñ` en los identificadores** (nombres de método, variable,
  clase). El motor tiene que compilarse y leerse igual en cualquier sitio.
- **Los comentarios, en español.** Ahí vive el *porqué*, que es lo que no se ve en
  el código. Explica por qué, no qué; y solo cuando no es obvio.

## Los tests de ataque

La suite de seguridad (`test_sec_*`) no comprueba que algo funcione: comprueba que
un ataque **no** funciona. Cada aserción afirma el comportamiento seguro, así que
**una en rojo es un fallo real, no un test que ablandar**. Si tu cambio pone uno
en rojo, has reabierto una brecha; arréglala, no toques la aserción.

Si añades una defensa nueva, añade el ataque que la prueba: lo escribes como
atacante, lo ves fallar sin tu arreglo y pasar con él. Ver `SECURITY.md` para el
modelo de atacante de cada suite.

## Estilo de commits y PRs

- Mensajes en imperativo y con el porqué, no solo el qué: *"corta la fuga por
  JOIN: el permiso se comprueba por colección"*, no *"cambios en Bridge"*.
- Un PR, una cosa. Si estás tocando dos asuntos, son dos PRs.
- Antes de proponer: `php core/tests/run.php` en verde y `git diff` releído.

## Licencia

Al contribuir, aceptas que tu aportación se publique bajo la licencia del
proyecto (Apache-2.0, ver `LICENSE`).
