/**
 * AxiDB - el cliente de navegador, ejercitado de verdad.
 *
 *   node _cliente.mjs http://127.0.0.1:PUERTO/api.php [token]
 *
 * Corre en Node, no en un navegador, pero usa el mismo fetch global y el mismo
 * modulo ES sin tocar una linea. Lo que se prueba es lo unico que no puede
 * probar PHP: que el cliente y el puente se entienden por el cable.
 *
 * Imprime una linea por comprobacion, 'ok ' o 'ko ', que lee el test de PHP.
 */

import { axidb, AxiError } from '../axi.js';

const [url, token] = process.argv.slice(2);
let fallos = 0;

function ok(etiqueta, condicion) {
  console.log(`${condicion ? 'ok ' : 'ko '}${etiqueta}`);
  if (!condicion) fallos++;
}

function eq(etiqueta, esperado, obtenido) {
  const igual = JSON.stringify(esperado) === JSON.stringify(obtenido);
  ok(etiqueta + (igual ? '' : ` (esperaba ${JSON.stringify(esperado)}, dio ${JSON.stringify(obtenido)})`), igual);
}

const db = axidb(url, token ? { token } : {});

// --- alta y lectura -------------------------------------------------------
const vaso = await db.insert('productos', { nombre: 'Vaso', centimos: 350 }, 'v1');
eq('insert devuelve el id', 'v1', vaso.id);
eq('con su version', 1, vaso._version);

const leido = await db.get('productos', 'v1');
eq('get trae el documento', 'Vaso', leido.nombre);
eq('con el numero intacto', 350, leido.centimos);

eq('un id inexistente da null', null, await db.get('productos', 'nada'));

// --- modificacion ---------------------------------------------------------
const tocado = await db.update('productos', 'v1', { centimos: 400 });
eq('update cambia el campo', 400, tocado.centimos);
eq('y conserva los demas', 'Vaso', tocado.nombre);
eq('subiendo la version', 2, tocado._version);

// --- consultas encadenadas ------------------------------------------------
for (let i = 1; i <= 10; i++) {
  await db.insert('piezas', { n: i, grupo: `g${i % 2}` }, `p${i}`);
}

const pares = await db.find('piezas').where('grupo', '=', 'g0').get();
eq('find filtra', 5, pares.length);

const top = await db.find('piezas').where('n', '>', 5).orderBy('n', 'desc').limit(2).get();
eq('ordena y limita', 2, top.length);
eq('el primero es el mayor', 10, top[0].n);

const uno = await db.find('piezas').where('n', '=', 7).first();
eq('first devuelve un solo documento', 7, uno.n);
eq('first sin resultados da null', null, await db.find('piezas').where('n', '=', 999).first());

eq('count por consulta', 5, await db.find('piezas').where('grupo', '=', 'g1').count());
eq('count de la coleccion', 10, await db.count('piezas'));

const proyectado = await db.find('piezas').where('n', '=', 3).select(['n']).get();
eq('select proyecta', [{ n: 3 }], proyectado);

// --- SQL ------------------------------------------------------------------
const filas = await db.sql("SELECT n FROM piezas WHERE n = 4");
eq('sql devuelve filas', 4, filas[0].n);

// --- borrado --------------------------------------------------------------
eq('delete confirma', true, await db.delete('productos', 'v1'));
eq('y ya no esta', null, await db.get('productos', 'v1'));

// --- errores --------------------------------------------------------------
try {
  await db.get('../fuera', 'x');
  ok('un nombre invalido lanza', false);
} catch (e) {
  ok('un nombre invalido lanza', e instanceof AxiError);
  ok('con el codigo HTTP dentro', e.codigo === 400);
  ok('y un mensaje que se entiende', typeof e.message === 'string' && e.message.length > 5);
}

try {
  await db.sql('ESTO NO ES SQL');
  ok('una sentencia rota lanza', false);
} catch (e) {
  ok('una sentencia rota lanza', e instanceof AxiError && e.codigo === 400);
}

// --- se puede inyectar un fetch propio ------------------------------------
let llamadas = 0;
const espia = axidb(url, {
  fetch: (u, opciones) => {
    llamadas++;
    return globalThis.fetch(u, opciones);
  },
  ...(token ? { token } : {}),
});
await espia.count('piezas');
eq('el fetch inyectado se usa', 1, llamadas);

console.log(fallos === 0 ? 'FIN ok' : `FIN ko ${fallos}`);
process.exit(fallos === 0 ? 0 : 1);
