<?php
declare(strict_types=1);

/*
 * Agoraform – point d'entrée public (seul fichier à placer dans www/).
 * Le dossier privé « agoraform-private » (code, descripteurs, saisies) doit être
 * HORS du dossier public. Par défaut : à côté de www/ (ex. /home/compte/agoraform-private).
 * Modifiez la ligne ci-dessous pour un autre emplacement.
 */
define('AF_PRIVATE', dirname($_SERVER['DOCUMENT_ROOT'] ?? __DIR__) . '/agoraform-private');

try {
    require AF_PRIVATE . '/src/agoraform.php';
    require AF_PRIVATE . '/src/view.php';
    $cfg = af_config();
} catch (Throwable $e) {
    error_log('Agoraform: ' . $e->getMessage());
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Agoraform : configuration invalide (dossier privé introuvable, ou forms/data dans le dossier public).\n"
        . "Agoraform: invalid setup (private folder not found, or forms/data inside the public folder).\n";
    exit;
}

$lang = af_pick_lang();
af_load_strings($lang);

session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax', 'secure' => !empty($_SERVER['HTTPS'])]);
session_start();
if (empty($_SESSION['af_csrf'])) {
    $_SESSION['af_csrf'] = bin2hex(random_bytes(16));
}

function af_fail(int $status, string $lang, string $msg, ?string $detail = null): void
{
    http_response_code($status);
    af_head(af_t('app_name'), $lang);
    echo '<h1>' . af_e($msg) . '</h1>';
    if ($detail !== null && af_config()['debug']) {
        echo '<pre>' . af_e($detail) . '</pre>';
    }
    echo '<p><a class="btn" href="?lang=' . af_e($lang) . '">' . af_e(af_t('back_to_list')) . '</a></p>';
    af_foot();
    exit;
}

$fid = $_GET['f'] ?? '';
$fid = is_string($fid) ? $fid : '';

/* ---------- Liste des formulaires ---------- */
if ($fid === '') {
    af_head(af_t('app_name'), $lang);
    af_lang_switch($lang, []);
    echo '<h1>' . af_e(af_t('choose_form')) . '</h1>';
    $forms = af_list_forms($lang);
    if (!$forms) {
        echo '<p>' . af_e(af_t('no_forms')) . '</p>';
    } else {
        echo '<ul class="forms">';
        foreach ($forms as $id => $title) {
            echo '<li><a href="?' . af_e(http_build_query(['f' => $id, 'lang' => $lang])) . '">' . af_e($title) . '</a></li>';
        }
        echo '</ul>';
    }
    af_foot();
    exit;
}

/* ---------- Un formulaire ---------- */
try {
    $form = af_load_form($fid);
} catch (Throwable $e) {
    error_log('Agoraform: ' . $e->getMessage());
    af_fail(404, $lang, af_t('notfound'), $e->getMessage());
}

$raw = [];
$errors = [];
$csrfError = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf'] ?? '';
    if (!is_string($token) || !hash_equals($_SESSION['af_csrf'], $token)) {
        $csrfError = true;
        $raw = af_validate($form, $_POST)['raw'];
    } elseif (($_POST['website'] ?? '') !== '') {
        // Pot de miel rempli : on fait semblant que tout va bien (rien n'est enregistré).
        header('Location: ?' . http_build_query(['f' => $fid, 'lang' => $lang, 'ok' => 1]), true, 303);
        exit;
    } else {
        $res = af_validate($form, $_POST);
        $raw = $res['raw'];
        $errors = $res['errors'];
        if (!$errors) {
            try {
                $entryId = af_save($form, $lang, $res['row']);
            } catch (Throwable $e) {
                error_log('Agoraform: ' . $e->getMessage());
                af_fail(500, $lang, af_t('save_error'), $e->getMessage());
            }
            $_SESSION['af_csrf'] = bin2hex(random_bytes(16));
            $_SESSION['af_last'] = ['f' => $fid, 'id' => $entryId];
            header('Location: ?' . http_build_query(['f' => $fid, 'lang' => $lang, 'ok' => 1]), true, 303);
            exit;
        }
    }
}

$title = af_loc($form['title'], $lang) ?: af_t('app_name');
af_head($title, $lang);
af_lang_switch($lang, ['f' => $fid]);

if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['ok'])) {
    echo '<h1>' . af_e(af_t('thanks_title')) . '</h1><p>' . af_e(af_t('thanks_msg')) . '</p>';
    $last = $_SESSION['af_last'] ?? null;
    if (is_array($last) && ($last['f'] ?? null) === $fid) {
        echo '<div class="receipt"><div class="note">' . af_e(af_t('your_id')) . '</div><code>'
            . af_e((string)$last['id']) . '</code><div class="note">' . af_e(af_t('keep_id')) . '</div></div>';
    }
    echo '<p><a class="btn" href="?' . af_e(http_build_query(['f' => $fid, 'lang' => $lang])) . '">' . af_e(af_t('fill_again')) . '</a></p>';
} else {
    if ($csrfError) {
        echo '<div class="alert" role="alert">' . af_e(af_t('err_csrf')) . '</div>';
    }
    af_render_form($form, $lang, $raw, $errors, $_SESSION['af_csrf']);
}
af_foot();
