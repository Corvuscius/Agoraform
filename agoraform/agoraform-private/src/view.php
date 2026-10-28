<?php
declare(strict_types=1);

const AF_CSS = <<<'CSS'
:root{color-scheme:light dark;--fg:#1d2433;--bg:#f5f6f8;--card:#fff;--muted:#5b6475;--line:#cfd4de;--accent:#1b5fbf;--err:#b3261e}
@media (prefers-color-scheme:dark){:root{--fg:#e8ebf2;--bg:#15181f;--card:#1e222b;--muted:#a3abbb;--line:#3a4152;--accent:#7fb0ff;--err:#ff8a80}}
*{box-sizing:border-box}
[hidden]{display:none!important}
body{margin:0;background:var(--bg);color:var(--fg);font:16px/1.5 system-ui,-apple-system,"Segoe UI",sans-serif}
main{max-width:40rem;margin:2rem auto;padding:1.5rem;background:var(--card);border:1px solid var(--line);border-radius:12px}
@media (max-width:42rem){main{margin:0;border-radius:0;border-width:0}}
h1{font-size:1.5rem;margin:0 0 .5rem}
.intro{color:var(--muted);margin:0 0 1.25rem}
.lang{display:flex;gap:.5rem;justify-content:flex-end;margin:0 0 1rem;font-size:.9rem}
.lang a{color:var(--accent);padding:.1rem .4rem;border-radius:4px;text-decoration:none;text-transform:uppercase}
.lang a[aria-current]{background:var(--accent);color:var(--card)}
.field{margin:0 0 1.25rem;border:0;padding:0}
label.title,legend{display:block;font-weight:600;margin:0 0 .35rem;padding:0}
input[type=text],textarea{width:100%;padding:.55rem .65rem;font:inherit;color:inherit;background:var(--bg);border:1px solid var(--line);border-radius:6px}
textarea{resize:vertical}
input:focus-visible,textarea:focus-visible{outline:2px solid var(--accent);outline-offset:1px}
.choice{display:flex;gap:.5rem;align-items:flex-start;margin:.25rem 0}
.choice input{margin-top:.35rem}
.req{color:var(--err)}
.note{color:var(--muted);font-size:.9rem}
.err{color:var(--err);font-size:.9rem;margin:.3rem 0 0}
.alert{border:1px solid var(--err);color:var(--err);padding:.6rem .8rem;border-radius:6px;margin:0 0 1.25rem}
.receipt{border:1px dashed var(--line);border-radius:8px;padding:.8rem 1rem;margin:1rem 0}
.receipt code{font-size:1.3rem;font-weight:700;letter-spacing:.05em;user-select:all}
.hp{position:absolute;left:-9999px;height:0;overflow:hidden}
button,.btn{display:inline-block;font:inherit;padding:.6rem 1.4rem;border:0;border-radius:6px;background:var(--accent);color:var(--card);cursor:pointer;text-decoration:none}
ul.forms{padding-left:1.2rem}ul.forms a{color:var(--accent)}
CSS;

/** Affiche/masque les champs selon data-vis (mêmes règles que af_validate côté serveur). */
const AF_JS = <<<'JS'
(function(){
  var form=document.querySelector('form[data-af]'); if(!form)return;
  var fields=[].slice.call(form.querySelectorAll('[data-name]'));
  function sel(name){
    var out=[];
    [].forEach.call(form.querySelectorAll('input'),function(i){
      if((i.name==='f_'+name||i.name==='f_'+name+'[]')&&i.checked)out.push(i.value);
    });
    return out;
  }
  function update(){
    var vis={};
    fields.forEach(function(el){
      var ok=true,c=el.getAttribute('data-vis');
      if(c){JSON.parse(c).forEach(function(k){
        if(!ok)return;
        if(!vis[k.f]){ok=false;return;}
        var s=sel(k.f),hit=k.v.some(function(v){return s.indexOf(v)>-1;});
        if(k.op==='in'?!hit:hit)ok=false;
      });}
      vis[el.getAttribute('data-name')]=ok;
      el.hidden=!ok;
      [].forEach.call(el.querySelectorAll('input,textarea'),function(i){i.disabled=!ok;});
    });
  }
  form.addEventListener('change',update); update();
})();
JS;

function af_head(string $title, string $lang): void
{
    header('Content-Type: text/html; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
    echo '<!doctype html><html lang="' . af_e($lang) . '"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>' . af_e($title) . '</title><style>' . AF_CSS . '</style></head><body><main>';
}

function af_foot(): void
{
    echo '</main></body></html>';
}

function af_lang_switch(string $current, array $params): void
{
    echo '<nav class="lang" aria-label="' . af_e(af_t('language')) . '">';
    foreach (af_config()['langs'] as $l) {
        $q = http_build_query(array_merge($params, ['lang' => $l]));
        echo '<a href="?' . af_e($q) . '" hreflang="' . af_e($l) . '"'
            . ($l === $current ? ' aria-current="true"' : '') . '>' . af_e($l) . '</a>';
    }
    echo '</nav>';
}

function af_render_form(array $form, string $lang, array $raw, array $errors, string $csrf): void
{
    $def = af_config()['default_lang'];
    $action = '?' . http_build_query(['f' => $form['id'], 'lang' => $lang]);
    $title = af_loc($form['title'], $lang);
    $intro = af_loc($form['intro'], $lang);

    if ($title !== '') {
        echo '<h1>' . af_e($title) . '</h1>';
    }
    if ($intro !== '') {
        echo '<p class="intro">' . af_e($intro) . '</p>';
    }
    if ($errors) {
        echo '<div class="alert" role="alert">' . af_e(af_t('err_summary')) . '</div>';
    }

    echo '<form method="post" action="' . af_e($action) . '" data-af novalidate>';
    echo '<input type="hidden" name="csrf" value="' . af_e($csrf) . '">';
    echo '<input type="hidden" name="lang" value="' . af_e($lang) . '">';
    echo '<div class="hp" aria-hidden="true"><label>Website <input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>';

    $anyRequired = false;
    foreach ($form['fields'] as $f) {
        $name = $f['name'];
        $key = af_field_key($f);
        $label = af_loc($f['label'], $lang) ?: $name;
        $opts = $f['options'][$lang] ?? $f['options'][$def];
        $req = $f['required'];
        $star = $req ? ' <span class="req" aria-hidden="true">*</span>' : '';
        $anyRequired = $anyRequired || $req;
        $err = $errors[$name] ?? null;
        $errId = 'err_' . $name;
        $described = $err ? ' aria-describedby="' . af_e($errId) . '" aria-invalid="true"' : '';
        $reqAttr = $req ? ' required' : '';
        $data = ' data-name="' . af_e($name) . '"'
            . ($f['visible_if'] ? ' data-vis="' . af_e((string)json_encode($f['visible_if'])) . '"' : '');

        if ($f['type'] === 'text' || $f['type'] === 'textarea') {
            echo '<div class="field"' . $data . '><label class="title" for="' . af_e($key) . '">' . af_e($label) . $star . '</label>';
            $max = (int)af_config()['max_text_length'];
            $val = (string)($raw[$name] ?? '');
            if ($f['type'] === 'text') {
                echo '<input type="text" id="' . af_e($key) . '" name="' . af_e($key) . '" value="' . af_e($val)
                    . '" maxlength="' . $max . '"' . $reqAttr . $described . '>';
            } else {
                echo '<textarea id="' . af_e($key) . '" name="' . af_e($key) . '" rows="4" maxlength="' . $max . '"'
                    . $reqAttr . $described . '>' . af_e($val) . '</textarea>';
            }
            echo af_field_error($err, $errId) . '</div>';
        } elseif ($f['type'] === 'checkbox' && !$opts) {
            $checked = !empty($raw[$name]) ? ' checked' : '';
            echo '<div class="field"' . $data . '><div class="choice"><input type="checkbox" id="' . af_e($key) . '" name="' . af_e($key)
                . '" value="1"' . $checked . $reqAttr . $described . '><label for="' . af_e($key) . '">' . af_e($label) . $star . '</label></div>';
            echo af_field_error($err, $errId) . '</div>';
        } else {
            $isRadio = $f['type'] === 'radio';
            echo '<fieldset class="field"' . $data . $described . '><legend>' . af_e($label) . $star . '</legend>';
            foreach ($opts as $i => $opt) {
                $id = $key . '_' . $i;
                $sel = $isRadio ? ($raw[$name] ?? null) === $i : in_array($i, (array)($raw[$name] ?? []), true);
                echo '<div class="choice"><input type="' . ($isRadio ? 'radio' : 'checkbox') . '" id="' . af_e($id) . '" name="'
                    . af_e($key) . ($isRadio ? '' : '[]') . '" value="' . $i . '"' . ($sel ? ' checked' : '')
                    . ($isRadio ? $reqAttr : '') . '><label for="' . af_e($id) . '">' . af_e($opt) . '</label></div>';
            }
            echo af_field_error($err, $errId) . '</fieldset>';
        }
    }
    if ($anyRequired) {
        echo '<p class="note">' . af_e(af_t('required_note')) . '</p>';
    }
    echo '<button type="submit">' . af_e(af_t('submit')) . '</button></form>';
    echo '<script>' . AF_JS . '</script>';
}

function af_field_error(?string $code, string $id): string
{
    return $code ? '<p class="err" id="' . af_e($id) . '">' . af_e(af_t('err_' . $code)) . '</p>' : '';
}
