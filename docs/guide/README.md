# Guias de AxiDB

Tres documentos, en orden. Los ejemplos de codigo de todos ellos **se ejecutan de
verdad** en `core/tests/test_readme.php`: si alguno deja de funcionar, la suite
se pone roja. Una guia que miente es peor que no tener guia.

| | |
|---|---|
| [**00-cinco-minutos.md**](00-cinco-minutos.md) | De carpeta vacia a CRUD funcionando. Empieza aqui |
| [**07-drivers.md**](07-drivers.md) | Como se guarda cada coleccion: `fs` legible o `packed` rapido, durabilidad y compactacion |
| [**08-http.md**](08-http.md) | La base de datos desde el navegador: endpoint, `axi.js`, tokens y CORS |

Los numeros saltan de 00 a 07: los intermedios documentaban un motor anterior que
ya no forma parte de AxiDB. Se conservan los numeros que tenian estos para no
romper enlaces que anden por ahi.

## Ademas

- [../../examples/](../../examples/) — una cristaleria, un blog, y la misma
  cristaleria funcionando en el navegador.
- [../standard/vector-search.md](../standard/vector-search.md) — como va a
  funcionar la busqueda vectorial. **Es una especificacion, todavia no esta
  construido.**
- [../../README.md](../../README.md) — que es AxiDB, cuando no usarlo y los
  numeros frente a SQLite.
