<?php
declare(strict_types=1);

/**
 * Agoraform – bibliothèque : lecture du descripteur CSV, visibilité conditionnelle,
 * validation, enregistrement CSV avec identifiant unique.
 * PHP 7.4+, aucune dépendance. Nécessite la constante AF_PRIVATE (dossier privé).
 */

const AF_TYPES = ['text', 'textarea', 'radio', 'checkbox'];
const AF_RESERVED = ['id', 'date', 'lang']; // colonnes ajoutées automatiquement

function af_config(): array
{
    static $cfg = null;
    if ($cfg === null) {
        $c = require AF_PRIVATE . '/config.php';
        af_assert_private($c['forms_dir']);
        af_assert_private($c['data_dir']);
        $cfg = $c;
    }
    return $cfg;
}

/** Refuse un dossier situé dans la racine web (DOCUMENT_ROOT) : les CSV ne doivent pas être publics. */
function af_assert_private(string $dir): void
{
    $root = realpath($_SERVER['DOCUMENT_ROOT'] ?? '');
    if ($root === false || $root === '') {
        return; // exécution en ligne de commande
    }
    $p = $dir;
    while (!file_exists($p) && dirname($p) !== $p) {
        $p = dirname($p);
    }
    $real = realpath($p);
    if ($real !== false && strpos($real . '/', rtrim($root, '/') . '/') === 0) {
        throw new RuntimeException("'$dir' is inside the public web root");
    }
}

function af_e(?string $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/* ------------------------------------------------------------------ i18n */

function af_pick_lang(): string
{
    $cfg = af_config();
    $req = $_GET['lang'] ?? $_POST['lang'] ?? null;
    if (is_string($req) && in_array($req, $cfg['langs'], true)) {
        return $req;
    }
    foreach (preg_split('/\s*,\s*/', $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '') as $part) {
        $code = strtolower(substr($part, 0, 2));
        if (in_array($code, $cfg['langs'], true)) {
            return $code;
        }
    }
    return $cfg['default_lang'];
}

function af_load_strings(?string $lang = null): array
{
    static $strings = [];
    if ($lang !== null) {
        $default = require AF_PRIVATE . '/lang/' . af_config()['default_lang'] . '.php';
        $file = AF_PRIVATE . '/lang/' . $lang . '.php';
        $strings = (is_file($file) ? require $file : []) + $default;
    }
    return $strings;
}

function af_t(string $key, array $args = []): string
{
    $s = af_load_strings()[$key] ?? $key;
    return $args ? vsprintf($s, $args) : $s;
}

/** Valeur dans la langue demandée, sinon langue par défaut, sinon la première disponible. */
function af_loc(array $map, string $lang): string
{
    $default = af_config()['default_lang'];
    foreach ([$lang, $default] as $l) {
        if (($map[$l] ?? '') !== '') {
            return $map[$l];
        }
    }
    foreach ($map as $v) {
        if ($v !== '') {
            return $v;
        }
    }
    return '';
}

/* ------------------------------------------------------------ CSV helpers */

function af_read_csv(string $path): array
{
    $h = fopen($path, 'rb');
    if (!$h) {
        throw new RuntimeException("Cannot open $path");
    }
    $rows = [];
    $first = true;
    $delim = af_config()['delimiter'];
    while (($r = fgetcsv($h, 0, $delim, '"', '')) !== false) {
        if ($first && isset($r[0])) {
            $r[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string)$r[0]); // BOM Excel
        }
        $first = false;
        if ($r === [null]) {
            continue; // ligne vide
        }
        $rows[] = array_map(static fn($c) => (string)$c, $r);
    }
    fclose($h);
    return $rows;
}

/** Neutralise l'injection de formules (=, +, -, @) à l'ouverture dans Excel/LibreOffice. */
function af_csv_safe(string $s): string
{
    return preg_match('/^[=+\-@\t\r]/', $s) ? "'" . $s : $s;
}

/* ------------------------------------------------------------ Descripteur */

function af_valid_id(string $id): bool
{
    return (bool)preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/', $id);
}

function af_list_forms(string $lang): array
{
    $out = [];
    foreach (glob(af_config()['forms_dir'] . '/*.csv') ?: [] as $file) {
        $id = basename($file, '.csv');
        if (!af_valid_id($id)) {
            continue;
        }
        try {
            $form = af_load_form($id);
            $out[$id] = af_loc($form['title'], $lang) ?: $id;
        } catch (Throwable $e) {
            error_log('Agoraform: ' . $e->getMessage());
        }
    }
    asort($out, SORT_NATURAL | SORT_FLAG_CASE);
    return $out;
}

/**
 * Colonnes du descripteur :
 *   name;type;required;visible_if;label_fr;label_en;options_fr;options_en
 * type       : title | intro | text | textarea | radio | checkbox
 * options_xx : choix séparés par « | » (radio : obligatoire ; checkbox : vide = case unique)
 * visible_if : conditions sur des champs radio/checkbox PRÉCÉDENTS, ex.
 *                profil=Collectif|Média        (l'une des valeurs est choisie)
 *                profil!=Bénévole              (aucune de ces valeurs n'est choisie)
 *                arrivee=Vendredi soir & rgpd=1   (« & » = ET)
 *              Les valeurs sont les options dans la langue par défaut ; case unique : 1 ou 0.
 */
function af_load_form(string $id): array
{
    $cfg = af_config();
    $path = $cfg['forms_dir'] . '/' . $id . '.csv';
    if (!af_valid_id($id) || !is_file($path)) {
        throw new RuntimeException("Unknown form: $id");
    }
    $rows = af_read_csv($path);
    if (!$rows) {
        throw new RuntimeException("$id.csv is empty");
    }
    $header = array_map('trim', array_shift($rows));
    foreach (['name', 'type'] as $col) {
        if (!in_array($col, $header, true)) {
            throw new RuntimeException("$id.csv: missing column '$col'");
        }
    }

    $def = $cfg['default_lang'];
    $form = ['id' => $id, 'title' => [], 'intro' => [], 'fields' => []];
    $known = [];
    $n = count($header);

    foreach ($rows as $i => $r) {
        $where = "$id.csv line " . ($i + 2);
        $row = array_combine($header, array_slice(array_pad($r, $n, ''), 0, $n));
        $type = strtolower(trim($row['type']));

        $labels = $options = [];
        foreach ($cfg['langs'] as $l) {
            $labels[$l] = trim($row["label_$l"] ?? '');
            $options[$l] = array_values(array_filter(
                array_map('trim', explode('|', $row["options_$l"] ?? '')),
                static fn($o) => $o !== ''
            ));
        }

        if ($type === 'title' || $type === 'intro') {
            $form[$type] = $labels;
            continue;
        }
        if (!in_array($type, AF_TYPES, true)) {
            throw new RuntimeException("$where: unknown type '$type'");
        }
        $name = trim($row['name']);
        if (!preg_match('/^[a-z][a-z0-9_]{0,40}$/i', $name)) {
            throw new RuntimeException("$where: invalid field name '$name'");
        }
        if (in_array(strtolower($name), AF_RESERVED, true)) {
            throw new RuntimeException("$where: field name '$name' is reserved");
        }
        if (isset($known[$name])) {
            throw new RuntimeException("$where: duplicate field name '$name'");
        }
        if ($type === 'radio' && count($options[$def]) < 2) {
            throw new RuntimeException("$where: radio '$name' needs at least 2 options_$def");
        }
        // Une traduction dont le nombre de choix ne correspond pas retombe sur la langue par défaut.
        foreach ($cfg['langs'] as $l) {
            if (count($options[$l]) !== count($options[$def])) {
                $options[$l] = $options[$def];
            }
        }

        $field = [
            'name'       => $name,
            'type'       => $type,
            'required'   => in_array(strtolower(trim($row['required'] ?? '')), ['1', 'x', 'o', 'y', 'oui', 'yes', 'true'], true),
            'label'      => $labels,
            'options'    => $options,
            'visible_if' => af_parse_conditions(trim($row['visible_if'] ?? ''), $known, $def, $where),
        ];
        $known[$name] = $field;
        $form['fields'][] = $field;
    }
    if (!$form['fields']) {
        throw new RuntimeException("$id.csv: no field defined");
    }
    return $form;
}

/**
 * Transforme « champ=a|b & autre!=c » en conditions résolues sur des indices d'options :
 * [['f' => champ, 'op' => 'in'|'notin', 'v' => ['0','2']], ...]
 */
function af_parse_conditions(string $expr, array $known, string $def, string $where): array
{
    if ($expr === '') {
        return [];
    }
    $out = [];
    foreach (preg_split('/\s+&\s+/', $expr) as $part) {
        if (!preg_match('/^([a-z][a-z0-9_]*)\s*(!=|=)\s*(.+)$/i', trim($part), $m)) {
            throw new RuntimeException("$where: invalid visible_if '$part'");
        }
        [, $dep, $op, $values] = $m;
        if (!isset($known[$dep])) {
            throw new RuntimeException("$where: visible_if refers to '$dep', which must be defined above");
        }
        $f = $known[$dep];
        if ($f['type'] !== 'radio' && $f['type'] !== 'checkbox') {
            throw new RuntimeException("$where: visible_if can only depend on radio or checkbox fields ('$dep')");
        }
        $opts = $f['options'][$def];
        $wanted = array_map('trim', explode('|', $values));

        if (!$opts) { // case unique : 1 = cochée, 0 = non cochée
            foreach ($wanted as $w) {
                if ($w !== '0' && $w !== '1') {
                    throw new RuntimeException("$where: checkbox '$dep' only accepts 0 or 1 in visible_if");
                }
            }
            $checked = in_array('1', $wanted, true);
            if ($checked && in_array('0', $wanted, true)) {
                continue; // toujours vrai
            }
            $in = ($op === '=') ? $checked : !$checked;
            $out[] = ['f' => $dep, 'op' => $in ? 'in' : 'notin', 'v' => ['1']];
            continue;
        }

        $vals = [];
        foreach ($wanted as $w) {
            $idx = null;
            foreach ($opts as $k => $o) {
                if (mb_strtolower($o) === mb_strtolower($w)) {
                    $idx = $k;
                    break;
                }
            }
            if ($idx === null) {
                throw new RuntimeException("$where: '$w' is not an option of '$dep'");
            }
            $vals[] = (string)$idx;
        }
        $out[] = ['f' => $dep, 'op' => $op === '=' ? 'in' : 'notin', 'v' => $vals];
    }
    return $out;
}

/* ------------------------------------------------------------- Validation */

function af_field_key(array $field): string
{
    return 'f_' . $field['name'];
}

function af_empty_raw(array $f)
{
    if ($f['type'] === 'radio') {
        return null;
    }
    if ($f['type'] === 'checkbox') {
        return $f['options'][af_config()['default_lang']] ? [] : false;
    }
    return '';
}

/** Valeurs sélectionnées (indices d'options en texte, ou '1' pour une case unique cochée). */
function af_selected(array $f, $raw): array
{
    if ($f['type'] === 'radio') {
        return $raw === null ? [] : [(string)$raw];
    }
    if ($f['type'] === 'checkbox') {
        return is_array($raw) ? array_map('strval', $raw) : ($raw ? ['1'] : []);
    }
    return [];
}

/**
 * @return array{raw: array, row: array, errors: array}
 *   raw    : valeurs brutes pour réafficher le formulaire
 *   row    : valeurs à enregistrer (options stockées dans la langue par défaut)
 *   errors : [nom_champ => code]
 * Un champ masqué par visible_if est ignoré : jamais obligatoire, jamais enregistré.
 */
function af_validate(array $form, array $post): array
{
    $cfg = af_config();
    $def = $cfg['default_lang'];
    $raw = $row = $errors = $vis = $sel = [];

    foreach ($form['fields'] as $f) {
        $name = $f['name'];
        $key = af_field_key($f);
        $in = $post[$key] ?? null;
        $opts = $f['options'][$def];

        $show = true;
        foreach ($f['visible_if'] as $c) {
            $hit = (bool)array_intersect($sel[$c['f']] ?? [], $c['v']);
            if (empty($vis[$c['f']]) || ($c['op'] === 'in' ? !$hit : $hit)) {
                $show = false;
                break;
            }
        }
        $vis[$name] = $show;
        if (!$show) {
            $raw[$name] = af_empty_raw($f);
            $row[$name] = '';
            $sel[$name] = [];
            continue;
        }

        switch ($f['type']) {
            case 'text':
            case 'textarea':
                $v = is_string($in) ? $in : '';
                if (!mb_check_encoding($v, 'UTF-8')) {
                    $v = '';
                }
                $v = trim(str_replace(["\r\n", "\r"], "\n", $v));
                $v = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $v) ?? '';
                if ($f['type'] === 'text') {
                    $v = str_replace("\n", ' ', $v);
                }
                $raw[$name] = $row[$name] = $v;
                if ($v === '' && $f['required']) {
                    $errors[$name] = 'required';
                } elseif (mb_strlen($v) > $cfg['max_text_length']) {
                    $errors[$name] = 'too_long';
                }
                break;

            case 'radio':
                $idx = (is_string($in) && ctype_digit($in) && (int)$in < count($opts)) ? (int)$in : null;
                $raw[$name] = $idx;
                $row[$name] = $idx === null ? '' : $opts[$idx];
                if ($idx === null) {
                    if ($f['required']) {
                        $errors[$name] = 'required';
                    } elseif ($in !== null && $in !== '') {
                        $errors[$name] = 'invalid';
                    }
                }
                break;

            case 'checkbox':
                if (!$opts) { // case unique
                    $checked = ($in === '1');
                    $raw[$name] = $checked;
                    $row[$name] = $checked ? '1' : '0';
                    if (!$checked && $f['required']) {
                        $errors[$name] = 'required';
                    }
                    break;
                }
                $idxs = [];
                foreach ((array)$in as $x) {
                    if (is_string($x) && ctype_digit($x) && (int)$x < count($opts)) {
                        $idxs[(int)$x] = (int)$x;
                    }
                }
                ksort($idxs);
                $raw[$name] = array_values($idxs);
                $row[$name] = implode(' | ', array_map(static fn($i) => $opts[$i], $idxs));
                if (!$idxs && $f['required']) {
                    $errors[$name] = 'required';
                }
                break;
        }
        $sel[$name] = af_selected($f, $raw[$name]);
    }
    return ['raw' => $raw, 'row' => $row, 'errors' => $errors];
}

/* ----------------------------------------------------------- Enregistrement */

/** Identifiant aléatoire (60 bits), alphabet sans ambiguïté : XXXX-XXXX-XXXX. */
function af_new_id(): string
{
    $alphabet = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';
    $s = '';
    for ($i = 0; $i < 12; $i++) {
        $s .= $alphabet[random_int(0, 31)];
    }
    return implode('-', str_split($s, 4));
}

/**
 * Ajoute une ligne au fichier de saisie et retourne son identifiant unique.
 * Colonnes : id;date;lang;<champs du descripteur>
 */
function af_save(array $form, string $lang, array $row): string
{
    $cfg = af_config();
    $dir = $cfg['data_dir'];
    if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
        throw new RuntimeException("Cannot create $dir");
    }
    $names = array_map(static fn($f) => $f['name'], $form['fields']);
    $header = array_merge(AF_RESERVED, $names);

    $lock = fopen($dir . '/.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX)) {
        throw new RuntimeException('Cannot lock data directory');
    }
    try {
        $file = $dir . '/' . $form['id'] . '.csv';
        $delim = $cfg['delimiter'];

        // Si le descripteur a changé depuis les premières saisies, on archive l'ancien fichier.
        if (is_file($file) && filesize($file) > 0 && af_read_csv_header($file) !== $header) {
            rename($file, $dir . '/' . $form['id'] . '.' . date('YmdHis') . '.csv');
        }
        $new = !is_file($file) || filesize($file) === 0;

        $taken = $new ? [] : af_existing_ids($file);
        do {
            $id = af_new_id();
        } while (isset($taken[$id]));

        $line = [$id, date('Y-m-d H:i:s'), $lang];
        foreach ($names as $n) {
            $line[] = af_csv_safe($row[$n] ?? '');
        }

        $h = fopen($file, 'ab');
        if (!$h) {
            throw new RuntimeException("Cannot write $file");
        }
        if ($new) {
            fwrite($h, "\xEF\xBB\xBF"); // BOM pour qu'Excel lise l'UTF-8
            fputcsv($h, $header, $delim, '"', '');
        }
        fputcsv($h, $line, $delim, '"', '');
        fflush($h);
        fclose($h);
        return $id;
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

function af_existing_ids(string $file): array
{
    $ids = [];
    foreach (array_slice(af_read_csv($file), 1) as $r) {
        $ids[$r[0]] = true;
    }
    return $ids;
}

function af_read_csv_header(string $file): array
{
    $h = fopen($file, 'rb');
    $r = $h ? fgetcsv($h, 0, af_config()['delimiter'], '"', '') : false;
    if ($h) {
        fclose($h);
    }
    if (!$r) {
        return [];
    }
    $r[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string)$r[0]);
    return $r;
}
