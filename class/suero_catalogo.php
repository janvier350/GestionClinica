<?php
/**
 * class/suero_catalogo.php — Catálogo de sueros de la clínica (nombre, aplicación, composición, laboratorio, precio).
 * Se crea y se llena solo (idempotente) la primera vez; sirve de lista de sugerencias al ordenar un suero
 * (derivación desde la consulta y mesa de Sueros). El texto sigue siendo editable en cada orden.
 */
function suero_catalogo_asegurar($c) {
    static $hecho = false;
    if ($hecho) return; $hecho = true;
    @$c->query("CREATE TABLE IF NOT EXISTS suero_catalogo (
        IDSUERO INT AUTO_INCREMENT PRIMARY KEY,
        NOMBRE VARCHAR(150) NOT NULL,
        DOSIS VARCHAR(80) NULL,
        INDICACIONES TEXT NULL,
        LABORATORIO VARCHAR(60) NULL,
        PRECIO DECIMAL(10,2) NOT NULL DEFAULT 0,
        PROMO TINYINT NOT NULL DEFAULT 0,
        ACTIVO TINYINT NOT NULL DEFAULT 1,
        UNIQUE KEY uq_nombre (NOMBRE)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $r = @$c->query("SELECT COUNT(*) n FROM suero_catalogo");
    if (!$r || (int)$r->fetch_assoc()['n'] > 0) return;      // ya sembrado: no se pisan cambios del usuario
    // [nombre, aplica, base, jeringa, laboratorio, precio, promo]
    $L = [
        ['SUERO DSMO (50ML)', '10 ml de DSMO', 'Cloruro 250 ml', '10 ml', 'Rocabpharma', 30, 0],
        ['PROMO SUERO DSMO (50ML)', '10 ml de DSMO', 'Cloruro 250 ml', '10 ml', 'Rocabpharma', 120, 1],
        ['SUERO B12 PLUSS (50ML)', '10 ml de B12', 'Cloruro 250 ml', '10 ml', 'Rocabpharma', 30, 0],
        ['PROMO SUERO B12 PLUSS (50ML)', '10 ml de Ascornad', 'Cloruro 250 ml', '10 ml', 'Rocabpharma', 120, 1],
        ['SUERO OLIGOMAX (10ML)', '5 ml de Oligomax', 'Cloruro 100 ml', '5 ml', 'LHA', 30, 0],
        ['SUERO OX MEGA C (30ML)', '30 ml de Ox Mega', 'Cloruro 250 ml', '20 ml', 'Escollanos', 29, 0],
        ['SUERO PLASMA MARINO (100ML)', '20 ml de Plasma marino', 'Cloruro 100 ml', '20 ml', 'Rocabpharma', 33, 0],
        ['PROMO SUERO PLASMA MARINO (100ML)', '20 ml de Plasma marino', 'Cloruro 100 ml', '10 ml', 'Rocabpharma', 120, 1],
        ['SUERO GASTRICO (2ML)', '1 ampolla de Gastreu (2 ml)', 'Cloruro 100 ml', '3 ml', 'Escollanos', 30, 0],
        ['SUERO ANEMIA (5ML)', '1 ampolla de Venofer (5 ml)', 'Cloruro 250 ml', '5 ml', 'Vifor Pharma', 30, 0],
        ['SUERO MITOCONDRIAL (2.2ML)', '1 ampolla de Ubichinon (2.2 ml)', 'Cloruro 100 ml', '3 ml', '', 30, 0],
        ['SUERO AZUL DE METILENO (10ML)', '5 ml de Azul de metileno', 'Cloruro 250 ml', '5 ml', 'Bionova', 30, 0],
        ['PROMO SUERO AZUL DE METILENO (10ML)', '2.5 ml de Azul de metileno', 'Cloruro 100 ml', '3 ml', 'Bionova', 100, 1],
        ['SUERO ASCORNAD (50ML)', '10 ml de Ascornad', 'Cloruro 250 ml', '10 ml', 'Rocabpharma', 30, 0],
        ['PROMO SUERO ASCORNAD (50ML)', '10 ml de Ascornad', 'Cloruro 250 ml', '10 ml', 'Rocabpharma', 120, 1],
        ['SUERO ANTIOX (50ML)', '10 ml de Antiox', 'Cloruro 250 ml', '10 ml', 'Rocabpharma', 30, 0],
        ['PROMO SUERO ANTIOX (50ML)', '10 ml de Antiox', 'Cloruro 250 ml', '10 ml', 'Rocabpharma', 120, 1],
        ['SUERO CELLVITALIS (10ML)', '5 ml de Cellvitalis', 'Cloruro 250 ml', '5 ml', 'Bionova', 33, 0],
        ['PROMO MOLÉCULA COMPLETA CELLVITALIS (10ML)', '2.5 ml de Cellvitalis', 'Cloruro 100 ml', '3 ml', 'Bionova', 110, 1],
        ['SUERO NERVIOSA (2ML)', '1 ampolla de Quieta (2 ml)', 'Cloruro 100 ml', '3 ml', 'Escollanos', 30, 0],
        ['SUERO PROSTÁTICA (2ML)', '1 ampolla de Prostatan (2 ml)', 'Cloruro 100 ml', '3 ml', 'Escollanos', 30, 0],
        ['SUERO VENOSO (2ML)', '1 ampolla de Haemovenin (2 ml)', 'Cloruro 100 ml', '3 ml', 'Escollanos', 30, 0],
        ['SUERO TIROIDES (2ML)', '1 ampolla de Glanthy (2 ml)', 'Cloruro 100 ml', '3 ml', 'Escollanos', 30, 0],
        ['SUERO NAC (2ML)', '1 ampolla de Fluimucil (3 ml)', 'Cloruro 250 ml', '5 ml', 'Zambon', 30, 0],
        ['SUERO DOLOR (2ML)', '1 ampolla de Discolver (2 ml)', 'Cloruro 100 ml', '3 ml', 'LHA', 30, 0],
        ['SUERO HORMONAL FEMENINA (2ML)', '1 ampolla de Ovafen (2 ml)', 'Cloruro 100 ml', '3 ml', 'LHA', 30, 0],
    ];
    $st = $c->prepare("INSERT IGNORE INTO suero_catalogo (NOMBRE, DOSIS, INDICACIONES, LABORATORIO, PRECIO, PROMO) VALUES (?, ?, ?, NULLIF(?,''), ?, ?)");
    if (!$st) return;
    foreach ($L as [$n, $aplica, $base, $jer, $lab, $precio, $promo]) {
        $ind = $base . ' + equipo de venoclisis + pericraneal 22 + jeringa ' . $jer . ($lab !== '' ? '. Laboratorio ' . $lab : '');
        $st->bind_param('ssssdi', $n, $aplica, $ind, $lab, $precio, $promo);
        $st->execute();
    }
    $st->close();
}

/** Filas activas: [NOMBRE, DOSIS, INDICACIONES, PRECIO, PROMO]. */
function suero_catalogo_lista($c) {
    suero_catalogo_asegurar($c);
    $out = []; $q = @$c->query("SELECT NOMBRE, DOSIS, INDICACIONES, PRECIO, PROMO FROM suero_catalogo WHERE ACTIVO=1 ORDER BY PROMO, NOMBRE");
    if ($q) while ($r = $q->fetch_assoc()) $out[] = $r;
    return $out;
}

/** <datalist id="listaSueros"> con las sugerencias. */
function suero_catalogo_datalist($c) {
    $h = '<datalist id="listaSueros">';
    foreach (suero_catalogo_lista($c) as $r) $h .= '<option value="' . htmlspecialchars($r['NOMBRE'], ENT_QUOTES, 'UTF-8') . '">$' . number_format((float)$r['PRECIO'], 2) . '</option>';
    return $h . '</datalist>';
}

/** JS: mapa nombre → {d,i} para autollenar dosis/indicaciones al elegir una sugerencia. */
function suero_catalogo_js($c) {
    $m = [];
    foreach (suero_catalogo_lista($c) as $r) $m[$r['NOMBRE']] = ['d' => $r['DOSIS'], 'i' => $r['INDICACIONES']];
    return 'window.SUEROS_CAT=' . json_encode($m, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) . ';';
}
