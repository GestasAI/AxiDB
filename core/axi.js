/**
 * AxiDB - cliente de navegador.
 *
 *   <script type="module">
 *     import { axidb } from './axidb/core/axi.js';
 *     const db = axidb('/api.php');
 *     const vaso = await db.insert('productos', { nombre: 'Vaso', precio: 350 });
 *     const baratos = await db.find('productos').where('precio', '<', 500).get();
 *   </script>
 *
 * Sin dependencias, sin empaquetador, sin paso de compilacion. Un modulo ES que
 * el navegador carga tal cual, igual que el nucleo es PHP que se copia tal cual.
 *
 * AVISO sobre los numeros: JSON tiene un unico tipo numerico. Un precio de 4.0
 * viaja como 4 y llega al servidor como entero. Si manejas dinero, guarda
 * centimos enteros o cadenas; es la practica correcta de todos modos.
 */

/** Error con el codigo HTTP y el motivo que devolvio el puente. */
export class AxiError extends Error {
  constructor(codigo, mensaje) {
    super(mensaje);
    this.name = 'AxiError';
    this.codigo = codigo;
  }
}

/**
 * Consulta que se va construyendo. No viaja hasta que se pide el resultado, asi
 * que encadenar condiciones no cuesta una peticion por cada una.
 */
class Consulta {
  constructor(enviar, coleccion) {
    this._enviar = enviar;
    this._c = coleccion;
    this._donde = [];
    this._orden = null;
    this._limite = null;
    this._salto = null;
    this._campos = null;
  }

  where(campo, operador, valor = null) {
    this._donde.push([campo, operador, valor]);
    return this;
  }

  orderBy(campo, direccion = 'asc') {
    this._orden = [campo, direccion];
    return this;
  }

  limit(n) {
    this._limite = n;
    return this;
  }

  offset(n) {
    this._salto = n;
    return this;
  }

  select(campos) {
    this._campos = campos;
    return this;
  }

  _cuerpo(accion) {
    const c = { accion, coleccion: this._c, donde: this._donde };
    if (this._orden) c.orden = this._orden;
    if (this._limite !== null) c.limite = this._limite;
    if (this._salto !== null) c.salto = this._salto;
    if (this._campos) c.campos = this._campos;
    return c;
  }

  get() {
    return this._enviar(this._cuerpo('find'));
  }

  count() {
    return this._enviar(this._cuerpo('count'));
  }

  async first() {
    const filas = await this.limit(1).get();
    return filas.length ? filas[0] : null;
  }
}

/**
 * Abre un cliente contra un endpoint de AxiDB.
 *
 * @param {string} url      ruta del endpoint, por ejemplo '/api.php'
 * @param {object} opciones token: cadena Bearer. fetch: implementacion propia,
 *                          util para pruebas y para entornos sin fetch global.
 */
export function axidb(url, opciones = {}) {
  const traer = opciones.fetch || globalThis.fetch;
  if (typeof traer !== 'function') {
    throw new AxiError(0, 'No hay fetch disponible: pasa uno en opciones.fetch.');
  }

  async function enviar(cuerpo) {
    const cabeceras = { 'Content-Type': 'application/json' };
    if (opciones.token) cabeceras.Authorization = `Bearer ${opciones.token}`;

    const respuesta = await traer(url, {
      method: 'POST',
      headers: cabeceras,
      body: JSON.stringify(cuerpo),
    });

    let datos;
    try {
      datos = await respuesta.json();
    } catch {
      // Un cuerpo que no es JSON casi siempre es una pagina de error del
      // servidor web: decirlo asi ahorra mirar la pestaña de red.
      throw new AxiError(respuesta.status, `El endpoint no devolvio JSON (HTTP ${respuesta.status}).`);
    }
    if (!respuesta.ok || datos.ok === false) {
      throw new AxiError(respuesta.status, datos.error || `HTTP ${respuesta.status}`);
    }
    return datos.dato;
  }

  return {
    insert: (coleccion, datos, id = null) => enviar({ accion: 'insert', coleccion, datos, ...(id ? { id } : {}) }),
    get: (coleccion, id) => enviar({ accion: 'get', coleccion, id }),
    update: (coleccion, id, datos, reemplazar = false) =>
      enviar({ accion: 'update', coleccion, id, datos, reemplazar }),
    delete: (coleccion, id) => enviar({ accion: 'delete', coleccion, id }),
    find: (coleccion) => new Consulta(enviar, coleccion),
    count: (coleccion) => enviar({ accion: 'count', coleccion, donde: [] }),
    sql: (sentencia) => enviar({ accion: 'sql', sentencia }),
  };
}

export default axidb;
