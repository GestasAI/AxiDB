<?php
/**
 * AxiDB - personal: empleados, departamentos y nomina.
 *
 * Aqui lo que se enseña es cruzar dos colecciones y agrupar: JOIN, medias por
 * departamento y un esquema que impide que entre un empleado a medio rellenar.
 *
 *   php examples/02-empleados/index.php
 */

declare(strict_types=1);

// ─── Las unicas dos lineas de instalacion ───────────────────────────────────
require __DIR__ . '/../../core/axidb.php';
$db = axidb(__DIR__ . '/datos');
// ────────────────────────────────────────────────────────────────────────────

foreach (['empleados', 'departamentos'] as $c) {
    $db->dropCollection($c);
}

echo "=== Personal ===\n\n";

/*
 * El esquema es opcional en AxiDB, y aqui viene bien: un empleado sin nombre o
 * sin departamento no es un empleado incompleto, es un error de captura. Que lo
 * pare la base de datos y no cada formulario que escriba alguien.
 */
$db->declararEsquema('empleados', [
    'nombre'   => ['tipo' => 'texto',  'obligatorio' => true],
    'email'    => ['tipo' => 'texto',  'obligatorio' => true],
    'depto'    => ['tipo' => 'texto',  'obligatorio' => true],
    'salario'  => ['tipo' => 'numero', 'obligatorio' => true],
]);
$db->unico('empleados', 'email');
$db->index('empleados', 'depto');

/* ─── Departamentos ─────────────────────────────────────────────────────── */

$departamentos = [
    ['id' => 'tec', 'nombre' => 'Tecnico',      'centro' => 'Murcia'],
    ['id' => 'com', 'nombre' => 'Comercial',    'centro' => 'Murcia'],
    ['id' => 'adm', 'nombre' => 'Administracion', 'centro' => 'Cartagena'],
];
foreach ($departamentos as $d) {
    $db->insert('departamentos', $d, $d['id']);
}

/* ─── Empleados ─────────────────────────────────────────────────────────── */

$empleados = [
    ['nombre' => 'Ana Ruiz',      'email' => 'ana@ejemplo.es',    'depto' => 'tec', 'salario' => 34000, 'alta' => '2021-03-01'],
    ['nombre' => 'Luis Mendez',   'email' => 'luis@ejemplo.es',   'depto' => 'tec', 'salario' => 31000, 'alta' => '2022-09-15'],
    ['nombre' => 'Marta Gil',     'email' => 'marta@ejemplo.es',  'depto' => 'tec', 'salario' => 38500, 'alta' => '2019-01-20'],
    ['nombre' => 'Pedro Soler',   'email' => 'pedro@ejemplo.es',  'depto' => 'com', 'salario' => 29000, 'alta' => '2023-02-06'],
    ['nombre' => 'Rosa Vidal',    'email' => 'rosa@ejemplo.es',   'depto' => 'com', 'salario' => 30500, 'alta' => '2020-11-02'],
    ['nombre' => 'Jorge Nieto',   'email' => 'jorge@ejemplo.es',  'depto' => 'adm', 'salario' => 27500, 'alta' => '2024-05-13'],
];
foreach ($empleados as $e) {
    $db->insert('empleados', $e);
}
echo 'Empleados dados de alta: ' . $db->count('empleados') . "\n";

// El esquema para lo que no esta completo.
try {
    $db->insert('empleados', ['nombre' => 'Sin departamento', 'email' => 'x@ejemplo.es']);
    echo "ERROR: ha entrado un empleado incompleto.\n";
} catch (\Axi\Core\Exception $e) {
    echo "Alta incompleta rechazada: {$e->getMessage()}\n";
}

// Y el UNIQUE para el correo repetido.
try {
    $db->insert('empleados', ['nombre' => 'Otra Ana', 'email' => 'ana@ejemplo.es', 'depto' => 'com', 'salario' => 1]);
    echo "ERROR: ha entrado un correo repetido.\n";
} catch (\Axi\Core\Exception $e) {
    echo "Correo repetido rechazado.\n";
}

/* ─── Cruce de las dos colecciones ──────────────────────────────────────── */

echo "\n-- Plantilla, con su departamento y su centro (JOIN) --\n";
$filas = $db->sql(
    'SELECT nombre, salario, departamentos.nombre AS departamento, departamentos.centro AS centro
     FROM empleados JOIN departamentos ON empleados.depto = departamentos.id
     ORDER BY salario DESC'
);
foreach ($filas as $f) {
    \printf("   %-14s %-16s %-11s %7d EUR\n",
        $f['nombre'], $f['departamento'], $f['centro'], $f['salario']);
}

/* ─── Agrupaciones ──────────────────────────────────────────────────────── */

echo "\n-- Coste y media por departamento --\n";
$porDepto = $db->sql(
    'SELECT depto, COUNT(*) AS personas, SUM(salario) AS coste, REDONDEA(AVG(salario), 0) AS media
     FROM empleados GROUP BY depto ORDER BY coste DESC'
);
foreach ($porDepto as $d) {
    $nombre = $db->get('departamentos', $d['depto'])['nombre'];
    \printf("   %-16s %d personas  %8d EUR  media %7d EUR\n",
        $nombre, $d['personas'], $d['coste'], $d['media']);
}

echo "\n-- Departamentos que cuestan mas de 60.000 al año (HAVING) --\n";
foreach ($db->sql('SELECT depto, SUM(salario) AS coste FROM empleados GROUP BY depto HAVING coste > 60000') as $d) {
    \printf("   %-6s %8d EUR\n", $d['depto'], $d['coste']);
}

echo "\n-- Los del departamento tecnico, por antiguedad (por indice) --\n";
$tecnicos = $db->find('empleados')->where('depto', 'tec')->orderBy('alta')->get();
foreach ($tecnicos as $e) {
    \printf("   %-14s desde %s\n", $e['nombre'], $e['alta']);
}

/* ─── Una subida de sueldo ──────────────────────────────────────────────── */

$masAntiguo = $tecnicos[0];
$db->update('empleados', $masAntiguo['id'], ['salario' => (int) \round($masAntiguo['salario'] * 1.05)]);
\printf("\nSubida del 5%% a %s: %d -> %d EUR\n",
    $masAntiguo['nombre'], $masAntiguo['salario'], $db->get('empleados', $masAntiguo['id'])['salario']);

$coste = $db->sql('SELECT SUM(salario) AS t FROM empleados')[0]['t'];
\printf("Coste total de la plantilla: %d EUR al año.\n", $coste);
