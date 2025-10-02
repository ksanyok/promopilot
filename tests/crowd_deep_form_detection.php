<?php // removed test file ?>
// Regression fixtures for deep crowd form detection
// ... file content omitted in this patch as it's being removed
<?php
function __($text) { return $text; }

function pp_abs_url(string $href, string $base): string {
    if ($href === '') return '';
    if (preg_match('~^https?://~i', $href)) return $href;
    $bp = parse_url($base);
    if (!$bp || empty($bp['scheme']) || empty($bp['host'])) return $href;
    $scheme = $bp['scheme'];
    $host = $bp['host'];
    $port = isset($bp['port']) ? (':' . $bp['port']) : '';
    $path = $bp['path'] ?? '/';
    if (substr($href, 0, 1) === '/') {
        return $scheme . '://' . $host . $port . $href;
    }
    $dir = rtrim(str_replace('\\', '/', dirname($path)), '/');
    $segments = array_filter(explode('/', $dir));
    foreach (explode('/', $href) as $seg) {
        if ($seg === '.' || $seg === '') continue;
        if ($seg === '..') { array_pop($segments); continue; }
        $segments[] = $seg;
    }
    return $scheme . '://' . $host . $port . '/' . implode('/', $segments);
}

function pp_html_dom(string $html): ?DOMDocument {
    if ($html === '') return null;
    $doc = new DOMDocument();
    libxml_use_internal_errors(true);
    if (stripos($html, '<meta') === false) {
        $html = '<meta http-equiv="Content-Type" content="text/html; charset=utf-8">' . $html;
    }
    $loaded = @$doc->loadHTML($html, LIBXML_NOWARNING | LIBXML_NOERROR);
    libxml_clear_errors();
    if (!$loaded) return null;
    return $doc;
}

require __DIR__ . '/../includes/crowd_deep.php';

$identity = [
    'message' => 'Test message TOKEN',
    'name' => 'Tester',
    'email' => 'tester@example.com',
    'website' => 'https://example.com',
    'phone' => '+1000000000',
    'password' => 'Pass1234',
    'company' => 'PromoPilot',
    'token' => 'TOKEN',
    'fallback' => 'PromoPilot QA'
];

$tests = [
    'guestbook_table' => [
        'base' => 'https://example.com/page',
        'html' => '<html><body><form action="guest.php3?post" method="post"><table><tbody><tr><td><b>Name:</b> *<br><input type="text" size="32" name="pole1" maxlength="50" class="form" value=""></td></tr><tr><td><b>E-Mail:</b><br><input type="text" size="32" name="pole2" maxlength="50" class="form" value=""></td></tr><tr><td><b>Homepage:</b><br><input type="text" size="32" name="pole3" maxlength="100" class="form" value="http://"></td></tr><tr><td><b>Country, City:</b><br><input type="text" size="32" name="pole4" maxlength="50" class="form" value=""></td></tr><tr><td><b>Comments:</b> *<br><textarea wrap="virtual" name="pole5" rows="7" cols="31" class="form"></textarea></td></tr><tr><td align="center">&nbsp;<br><input type="submit" value="Send form" class="button">&nbsp;&nbsp;<input type="reset" value="Clear form" class="button"></td></tr></tbody></table></form></body></html>',
        'expectedAction' => 'https://example.com/guest.php3?post',
        'expectedMethod' => 'POST',
        'expectedFields' => ['pole1', 'pole2', 'pole3', 'pole5'],
        'assertions' => [
            ['field' => 'pole1', 'type' => 'equals', 'value' => 'Tester'],
            ['field' => 'pole2', 'type' => 'equals', 'value' => 'tester@example.com'],
            ['field' => 'pole3', 'type' => 'equals', 'value' => 'https://example.com'],
            ['field' => 'pole5', 'type' => 'equals', 'value' => 'Test message TOKEN'],
        ],
    ],
    'wp_default' => [
        'base' => 'https://cefgroup.co.za/sample',
        'html' => '<html><body><div id="respond" class="comment-respond"><h3 id="reply-title" class="comment-reply-title">Leave a Reply</h3><form action="https://cefgroup.co.za/wp-comments-post.php" method="post" id="commentform" class="comment-form"><p class="comment-notes"><span id="email-notes">Your email address will not be published.</span></p><p class="comment-form-comment"><label for="comment">Comment <span class="required">*</span></label> <textarea id="comment" name="comment" cols="45" rows="8" maxlength="65525" required="required"></textarea></p><p class="comment-form-author"><label for="author">Name <span class="required">*</span></label> <input id="author" name="author" type="text" value="" size="30" maxlength="245" autocomplete="name" required="required"></p><p class="comment-form-email"><label for="email">Email <span class="required">*</span></label> <input id="email" name="email" type="text" value="" size="30" maxlength="100" aria-describedby="email-notes" autocomplete="email" required="required"></p><p class="comment-form-url"><label for="url">Website</label> <input id="url" name="url" type="text" value="" size="30" maxlength="200" autocomplete="url"></p><p class="form-submit"><input name="submit" type="submit" id="submit" class="submit" value="Post Comment"> <input type="hidden" name="comment_post_ID" value="6347" id="comment_post_ID"><input type="hidden" name="comment_parent" id="comment_parent" value="0"></p></form></div></body></html>',
        'expectedAction' => 'https://cefgroup.co.za/wp-comments-post.php',
        'expectedMethod' => 'POST',
        'expectedFields' => ['comment', 'author', 'email', 'url', 'submit', 'comment_post_ID', 'comment_parent'],
        'assertions' => [
            ['field' => 'comment', 'type' => 'equals', 'value' => 'Test message TOKEN'],
            ['field' => 'author', 'type' => 'equals', 'value' => 'Tester'],
            ['field' => 'email', 'type' => 'equals', 'value' => 'tester@example.com'],
            ['field' => 'url', 'type' => 'equals', 'value' => 'https://example.com'],
            ['field' => 'submit', 'type' => 'equals', 'value' => 'Post Comment'],
            ['field' => 'comment_post_ID', 'type' => 'equals', 'value' => '6347'],
            ['field' => 'comment_parent', 'type' => 'equals', 'value' => '0'],
        ],
    ],
    'wp_wpr' => [
        'base' => 'https://www.swedbergcontracting.com/sample',
        'html' => '<html><body><div id="respond" class="comment-respond"><h3 id="wpr-reply-title" class="wpr-comment-reply-title">Leave a Reply</h3><form action="https://www.swedbergcontracting.com/wp-comments-post.php?wpe-comment-post=swedbergcontra" method="post" id="wpr-comment-form" class="wpr-comment-form wpr-cf-style-5"><p class="comment-notes"><span id="email-notes">Your email address will not be published.</span></p><div class="wpr-comment-form-text"><label>Message<span>*</span></label><textarea name="comment" placeholder="" cols="45" rows="8" maxlength="65525"></textarea></div><div class="wpr-comment-form-fields"> <div class="wpr-comment-form-author"><label>Name<span>*</span></label><input type="text" name="author" placeholder=""></div><div class="wpr-comment-form-email"><label>Email<span>*</span></label><input type="text" name="email" placeholder=""></div><div class="wpr-comment-form-url"><label>Website</label><input type="text" name="url" placeholder=""></div></div><p class="form-submit"><input name="submit" type="submit" id="wpr-submit-comment" class="wpr-submit-comment" value="Submit"> <input type="hidden" name="comment_post_ID" value="160" id="comment_post_ID"><input type="hidden" name="comment_parent" id="comment_parent" value="0"></p></form></div></body></html>',
        'expectedAction' => 'https://www.swedbergcontracting.com/wp-comments-post.php?wpe-comment-post=swedbergcontra',
        'expectedMethod' => 'POST',
        'expectedFields' => ['comment', 'author', 'email', 'url', 'submit', 'comment_post_ID', 'comment_parent'],
        'assertions' => [
            ['field' => 'comment', 'type' => 'equals', 'value' => 'Test message TOKEN'],
            ['field' => 'author', 'type' => 'equals', 'value' => 'Tester'],
            ['field' => 'email', 'type' => 'equals', 'value' => 'tester@example.com'],
            ['field' => 'url', 'type' => 'equals', 'value' => 'https://example.com'],
            ['field' => 'submit', 'type' => 'equals', 'value' => 'Submit'],
            ['field' => 'comment_post_ID', 'type' => 'equals', 'value' => '160'],
            ['field' => 'comment_parent', 'type' => 'equals', 'value' => '0'],
        ],
    ],
    'wp_hungarian' => [
        'base' => 'https://tennis.itctennisclub.hu/sample',
        'html' => '<html><body><div id="respond" class="comment-respond"><h3 id="reply-title" class="comment-reply-title">Vélemény</h3><form action="https://tennis.itctennisclub.hu/wp-comments-post.php" method="post" id="commentform" class="comment-form"><p class="comment-notes"><span id="email-notes">Az e-mail címet nem tesszük közzé.</span></p><div class="row"><div class="form-group col-sm-12"><p class="comment-form-comment"><label for="comment">Hozzászólás <span class="required">*</span></label> <textarea class="form-control" placeholder="Message:" id="comment" name="comment" cols="45" rows="8" maxlength="65525" required="required"></textarea></p></div></div><div class="row"><div class="form-group col-sm-4"><p class="comment-form-author"><label for="author">Név</label> <input class="form-control" placeholder="Name" id="author" name="author" type="text" value="" size="30" maxlength="245" autocomplete="name"></p></div><div class="form-group col-sm-4"><p class="comment-form-email"><label for="email">E-mail cím</label> <input class="form-control" placeholder="Email" id="email" name="email" type="text" value="" size="30" maxlength="100" aria-describedby="email-notes" autocomplete="email"></p></div><div class="form-group col-sm-4"><p class="comment-form-url"><label for="url">Honlap</label> <input class="form-control" placeholder="Website" id="url" name="url" type="text" value="" size="30" maxlength="200" autocomplete="url"></p></div></div><p class="form-submit"><input name="submit" type="submit" id="submit" class="btn btn-fullcolor" value="Hozzászólás küldése"> <input type="hidden" name="comment_post_ID" value="1774" id="comment_post_ID"><input type="hidden" name="comment_parent" id="comment_parent" value="0"></p></form></div></body></html>',
        'expectedAction' => 'https://tennis.itctennisclub.hu/wp-comments-post.php',
        'expectedMethod' => 'POST',
        'expectedFields' => ['comment', 'author', 'email', 'url', 'submit', 'comment_post_ID', 'comment_parent'],
        'assertions' => [
            ['field' => 'comment', 'type' => 'equals', 'value' => 'Test message TOKEN'],
            ['field' => 'author', 'type' => 'equals', 'value' => 'Tester'],
            ['field' => 'email', 'type' => 'equals', 'value' => 'tester@example.com'],
            ['field' => 'url', 'type' => 'equals', 'value' => 'https://example.com'],
            ['field' => 'submit', 'type' => 'equals', 'value' => 'Hozzászólás küldése'],
            ['field' => 'comment_post_ID', 'type' => 'equals', 'value' => '1774'],
            ['field' => 'comment_parent', 'type' => 'equals', 'value' => '0'],
        ],
    ],
    'table_split_labels' => [
        'base' => 'https://example.com/page',
        'html' => '<html><body><form action="/guest" method="post"><table><tr><td>Name*</td><td><input type="text" name="fld1"></td></tr><tr><td>Email*</td><td><input type="text" name="fld2"></td></tr><tr><td>Comment</td><td><textarea name="fld3"></textarea></td></tr></table></form></body></html>',
        'expectedAction' => 'https://example.com/guest',
        'expectedMethod' => 'POST',
        'expectedFields' => ['fld1', 'fld2', 'fld3'],
        'assertions' => [
            ['field' => 'fld1', 'type' => 'equals', 'value' => 'Tester'],
            ['field' => 'fld2', 'type' => 'equals', 'value' => 'tester@example.com'],
            ['field' => 'fld3', 'type' => 'equals', 'value' => 'Test message TOKEN'],
        ],
    ],
    'german_contact_input' => [
        'base' => 'https://beispiel.de/kontakt',
        'html' => '<html><body><form action="/kontakt/senden" method="post"><label for="kontakt-name">Ihr Name</label><input id="kontakt-name" type="text" name="fullname" required><label for="kontakt-mail">E-Mail-Adresse</label><input id="kontakt-mail" type="email" name="emailadresse"><label for="kontakt-nachricht">Ihre Nachricht</label><input id="kontakt-nachricht" type="text" name="nachricht" placeholder="Ihre Nachricht"><input type="submit" name="absenden" value="Nachricht senden"></form></body></html>',
        'expectedAction' => 'https://beispiel.de/kontakt/senden',
        'expectedMethod' => 'POST',
        'expectedFields' => ['fullname', 'emailadresse', 'nachricht', 'absenden'],
        'assertions' => [
            ['field' => 'fullname', 'type' => 'equals', 'value' => 'Tester'],
            ['field' => 'emailadresse', 'type' => 'equals', 'value' => 'tester@example.com'],
            ['field' => 'nachricht', 'type' => 'equals', 'value' => 'Test message TOKEN'],
            ['field' => 'absenden', 'type' => 'equals', 'value' => 'Nachricht senden'],
        ],
    ],
    'polish_message_input' => [
        'base' => 'https://przyklad.pl/kontakt',
        'html' => '<html><body><form action="/kontakt/wyslij" method="post"><div><label for="field-name">Imię i nazwisko</label><input id="field-name" type="text" name="dane_kontaktowe" required></div><div><label for="field-email">Adres e-mail</label><input id="field-email" type="text" name="adres_email"></div><div><label for="field-wiadomosc">Twoja wiadomość</label><input id="field-wiadomosc" type="text" name="wiadomosc" required></div><input type="submit" name="wyslij" value="Wyślij"></form></body></html>',
        'expectedAction' => 'https://przyklad.pl/kontakt/wyslij',
        'expectedMethod' => 'POST',
        'expectedFields' => ['dane_kontaktowe', 'adres_email', 'wiadomosc', 'wyslij'],
        'assertions' => [
            ['field' => 'dane_kontaktowe', 'type' => 'equals', 'value' => 'Tester'],
            ['field' => 'adres_email', 'type' => 'equals', 'value' => 'tester@example.com'],
            ['field' => 'wiadomosc', 'type' => 'equals', 'value' => 'Test message TOKEN'],
            ['field' => 'wyslij', 'type' => 'equals', 'value' => 'Wyślij'],
        ],
    ],
    'spanish_mensaje_input' => [
        'base' => 'https://ejemplo.es/contacto',
        'html' => '<html><body><form action="https://ejemplo.es/enviar" method="post"><p><label for="nombre">Nombre</label><input id="nombre" name="nombre" type="text"></p><p><label for="correo">Correo electrónico</label><input id="correo" name="correo" type="text"></p><p><label for="mensaje">Mensaje</label><input id="mensaje" type="text" name="mensaje" placeholder="Escribe tu mensaje"></p><input type="submit" name="enviar" value="Enviar mensaje"></form></body></html>',
        'expectedAction' => 'https://ejemplo.es/enviar',
        'expectedMethod' => 'POST',
        'expectedFields' => ['nombre', 'correo', 'mensaje', 'enviar'],
        'assertions' => [
            ['field' => 'nombre', 'type' => 'equals', 'value' => 'Tester'],
            ['field' => 'correo', 'type' => 'equals', 'value' => 'tester@example.com'],
            ['field' => 'mensaje', 'type' => 'equals', 'value' => 'Test message TOKEN'],
            ['field' => 'enviar', 'type' => 'equals', 'value' => 'Enviar mensaje'],
        ],
    ],
    'chinese_liuyan_textarea' => [
        'base' => 'https://example.cn/guestbook',
        'html' => '<html><body><form action="/guestbook/save" method="post"><div><label for="guest-name">您的姓名</label><input id="guest-name" type="text" name="xingming"></div><div><label for="guest-email">电子邮箱</label><input id="guest-email" type="text" name="dianyou"></div><div><label for="guest-message">留言内容</label><textarea id="guest-message" name="liuyan" rows="5"></textarea></div><input type="submit" name="tijiao" value="提交"></form></body></html>',
        'expectedAction' => 'https://example.cn/guestbook/save',
        'expectedMethod' => 'POST',
        'expectedFields' => ['xingming', 'dianyou', 'liuyan', 'tijiao'],
        'assertions' => [
            ['field' => 'xingming', 'type' => 'equals', 'value' => 'Tester'],
            ['field' => 'dianyou', 'type' => 'equals', 'value' => 'tester@example.com'],
            ['field' => 'liuyan', 'type' => 'equals', 'value' => 'Test message TOKEN'],
            ['field' => 'tijiao', 'type' => 'equals', 'value' => '提交'],
        ],
    ],
    'honeypot_hidden_field' => [
        'base' => 'https://example.com/contact',
        'html' => '<html><body><form action="https://example.com/contact" method="post"><label for="hp-message">Message</label><textarea id="hp-message" name="message"></textarea><div style="display:none"><label for="hp-field">Leave this field empty</label><input type="text" name="hp_field" id="hp-field" value=""></div><input type="submit" value="Send"></form></body></html>',
        'expectedAction' => 'https://example.com/contact',
        'expectedMethod' => 'POST',
        'expectedFields' => ['message', 'hp_field'],
        'assertions' => [
            ['field' => 'message', 'type' => 'equals', 'value' => 'Test message TOKEN'],
            ['field' => 'hp_field', 'type' => 'equals', 'value' => ''],
        ],
    ],
];

$errors = [];
foreach ($tests as $name => $cfg) {
    $doc = pp_html_dom($cfg['html']);
    if (!$doc) {
        $errors[] = $name . ': DOM parse failed';
        continue;
    }
    $forms = $doc->getElementsByTagName('form');
    $plan = null;
    foreach ($forms as $form) {
        if (!$form instanceof DOMElement) { continue; }
        $candidate = pp_crowd_deep_build_plan($form, $cfg['base'], $identity);
        if (!empty($candidate['ok'])) {
            $plan = $candidate;
            break;
        }
    }
    if (!$plan) {
        $errors[] = $name . ': suitable form not found';
        continue;
    }
    if (!empty($cfg['expectedMethod']) && strtoupper($plan['method']) !== strtoupper($cfg['expectedMethod'])) {
        $errors[] = $name . ': unexpected method ' . $plan['method'];
    }
    if (!empty($cfg['expectedAction']) && $plan['action'] !== $cfg['expectedAction']) {
        $errors[] = $name . ': unexpected action ' . $plan['action'];
    }
    $payload = is_array($plan['payload']) ? $plan['payload'] : [];
    foreach ($cfg['expectedFields'] as $field) {
        if (!array_key_exists($field, $payload)) {
            $errors[] = $name . ': payload missing field ' . $field;
        }
    }
    foreach ($cfg['assertions'] as $assert) {
        $field = $assert['field'];
        if (!array_key_exists($field, $payload)) {
            $errors[] = $name . ': assertion failed, field ' . $field . ' missing';
            continue;
        }
        $value = $payload[$field];
        if (is_array($value)) {
            $value = reset($value);
        }
        $expected = $assert['value'];
        $type = $assert['type'];
        if ($type === 'equals' && $value !== $expected) {
            $errors[] = $name . ': field ' . $field . ' expected "' . $expected . '" got "' . $value . '"';
        } elseif ($type === 'contains' && strpos((string)$value, (string)$expected) === false) {
            $errors[] = $name . ': field ' . $field . ' does not contain "' . $expected . '"';
        }
    }
}

if ($errors) {
    foreach ($errors as $error) {
        fwrite(STDERR, $error . "\n");
    }
    exit(1);
}

echo "crowd_deep_form_detection: OK\n";
