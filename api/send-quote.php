<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=UTF-8');
header('X-Content-Type-Options: nosniff');

session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    'httponly' => true,
    'samesite' => 'Strict',
]);
session_start();

function finish(int $status, bool $ok, string $message): never {
    http_response_code($status);
    echo json_encode(['ok' => $ok, 'message' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    finish(405, false, 'Método não permitido.');
}

// Campo invisível: robôs costumam preenchê-lo.
if (!empty($_POST['website'] ?? '')) {
    finish(200, true, 'Solicitação recebida.');
}

// Limita tentativas repetidas por IP para reduzir disparos automatizados.
function enforceRateLimit(int $limit = 10, int $windowSeconds = 900): void {
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    $path = sys_get_temp_dir().'/bc_quote_'.hash('sha256', $ip).'.json';
    $handle = @fopen($path, 'c+');
    if ($handle === false) return;

    try {
        if (!flock($handle, LOCK_EX)) return;
        $raw = stream_get_contents($handle);
        $attempts = json_decode($raw ?: '[]', true);
        if (!is_array($attempts)) $attempts = [];
        $now = time();
        $attempts = array_values(array_filter($attempts, static fn($time): bool => is_int($time) && $time > $now - $windowSeconds));
        if (count($attempts) >= $limit) {
            flock($handle, LOCK_UN);
            fclose($handle);
            finish(429, false, 'Muitas tentativas. Aguarde alguns minutos e tente novamente.');
        }
        $attempts[] = $now;
        ftruncate($handle, 0);
        rewind($handle);
        fwrite($handle, json_encode($attempts));
        fflush($handle);
        flock($handle, LOCK_UN);
    } finally {
        if (is_resource($handle)) fclose($handle);
    }
}

enforceRateLimit();

// CAPTCHA visual: uso único, válido entre 2 segundos e 30 minutos.
$captchaAnswer = preg_replace('/[^0-9]/', '', (string)($_POST['captcha_answer'] ?? ''));
$captchaHash = (string)($_SESSION['quote_captcha_hash'] ?? '');
$captchaIssuedAt = (int)($_SESSION['quote_captcha_issued_at'] ?? 0);
unset($_SESSION['quote_captcha_hash'], $_SESSION['quote_captcha_issued_at']);

$captchaAge = time() - $captchaIssuedAt;
if ($captchaHash === '' || strlen($captchaAnswer) !== 4 || $captchaAge < 0 || $captchaAge > 1800 || !hash_equals($captchaHash, hash('sha256', $captchaAnswer))) {
    finish(422, false, 'Código de segurança inválido. Atualize a imagem e tente novamente.');
}

function field(string $name, int $max = 500): string {
    $value = trim((string)($_POST[$name] ?? ''));
    return mb_substr($value, 0, $max);
}

$contactName = field('contactName', 150);
$email = filter_var(field('email', 254), FILTER_VALIDATE_EMAIL);
$phone = field('phone', 80);
$companyName = field('companyName', 200);
$companyCountry = field('companyCountry', 120);
$origin = field('origin', 120);
$destination = field('destination', 180);
$productsJson = (string)($_POST['products'] ?? '[]');
$products = json_decode($productsJson, true);

if (!$contactName || !$email || !$phone || !$companyName || !$companyCountry || !$origin || !$destination || !is_array($products) || count($products) < 1) {
    finish(422, false, 'Preencha todos os campos obrigatórios.');
}

$phoneDigits = preg_replace('/\D/', '', $phone);
if (strncmp($phone, '+', 1) !== 0 || strlen($phoneDigits) < 8 || strlen($phoneDigits) > 15) {
    finish(422, false, 'Informe o telefone com DDI, começando pelo sinal de +. Exemplo: +55 11 99999-9999.');
}

if (strlen($productsJson) > 50000 || count($products) > 20) {
    finish(422, false, 'A solicitação excede o limite permitido.');
}

$escape = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$yesNo = static fn($value): string => $value ? 'Sim' : 'Não';

$rows = [
    ['Contato', $contactName],
    ['E-mail', (string)$email],
    ['Telefone / WhatsApp', $phone],
    ['Empresa', $companyName],
    ['CNPJ / Registro fiscal', field('companyId', 100) ?: 'Não informado'],
    ['País da empresa', $companyCountry],
    ['Website', field('companyWebsite', 250) ?: 'Não informado'],
    ['Perfil', field('profile', 40) === 'broker' ? 'Intermediário / broker / corretor' : 'Comprador final'],
    ['Sou mandatário', field('profile', 40) === 'broker' ? field('mandatary', 10) : 'Não se aplica'],
    ['Contato direto com comprador final', field('profile', 40) === 'broker' ? field('direct', 10) : 'Não se aplica'],
    ['Tipo de compra', field('kind', 30)],
    ['Origem do produto', $origin],
    ['Porto ou país de destino', $destination],
    ['Incoterm', field('incoterm', 50)],
    ['Termo de pagamento', field('payment', 100)],
    ['Observações gerais', field('notes', 5000) ?: 'Nenhuma'],
];

$html = '<!doctype html><html><body style="font-family:Arial,sans-serif;color:#243b45">';
$html .= '<h2 style="color:#0b3a57">Nova solicitação de cotação — Brazilian Commodities</h2>';
$html .= '<table cellpadding="8" cellspacing="0" style="border-collapse:collapse;width:100%;max-width:760px">';
foreach ($rows as [$label, $value]) {
    $html .= '<tr><td style="border:1px solid #d8e3dd;background:#f1f7f3;font-weight:bold;width:230px">'.$escape($label).'</td><td style="border:1px solid #d8e3dd">'.nl2br($escape((string)$value)).'</td></tr>';
}
$html .= '</table><h3 style="color:#197753;margin-top:26px">Produtos / commodities</h3>';

foreach ($products as $index => $product) {
    if (!is_array($product)) continue;
    $name = mb_substr(trim((string)($product['name'] ?? '')), 0, 200);
    $qty = mb_substr(trim((string)($product['qty'] ?? '')), 0, 50);
    $unit = mb_substr(trim((string)($product['unit'] ?? '')), 0, 30);
    if ($name === '' || $qty === '') finish(422, false, 'Produto e quantidade são obrigatórios.');
    $html .= '<div style="border:1px solid #d8e3dd;border-radius:8px;padding:15px;margin:0 0 12px">';
    $html .= '<strong>Produto '.($index + 1).': '.$escape($name).'</strong><br>';
    $html .= 'Quantidade: '.$escape($qty.' '.$unit).'<br>';
    $html .= 'Trial: '.$yesNo(!empty($product['trial'])).'<br>';
    $html .= 'Embalagem: '.$escape(mb_substr(trim((string)($product['pack'] ?? 'Não informada')), 0, 500)).'<br>';
    $html .= 'Características: '.nl2br($escape(mb_substr(trim((string)($product['spec'] ?? 'Não informadas')), 0, 3000))).'</div>';
}
$html .= '<p style="font-size:12px;color:#687b83">Enviado pelo formulário de braziliancommodities.com.</p></body></html>';

$language = field('language', 2);
if (!in_array($language, ['pt', 'en', 'es', 'zh'], true)) $language = 'en';

function localizedEmail(string $html, string $language): string {
    $translations = [
        'en' => [
            'Nova solicitação de cotação' => 'New quotation request',
            'Contato' => 'Contact', 'E-mail' => 'Email', 'Telefone / WhatsApp' => 'Phone / WhatsApp',
            'Empresa' => 'Company', 'CNPJ / Registro fiscal' => 'Tax ID / Company registration',
            'País da empresa' => 'Company country', 'Não informado' => 'Not provided',
            'Perfil' => 'Profile', 'Intermediário / broker / corretor' => 'Intermediary / broker',
            'Comprador final' => 'End buyer', 'Sou mandatário' => 'Mandate holder',
            'Contato direto com comprador final' => 'Direct contact with the end buyer',
            'Não se aplica' => 'Not applicable', 'Tipo de compra' => 'Purchase type',
            'Origem do produto' => 'Product origin', 'Porto ou país de destino' => 'Destination port or country',
            'Termo de pagamento' => 'Payment terms', 'Observações gerais' => 'General notes',
            'Nenhuma' => 'None', 'Produtos / commodities' => 'Products / commodities',
            'Produto ' => 'Product ', 'Quantidade:' => 'Quantity:', 'Sim' => 'Yes', 'Não' => 'No',
            'Embalagem:' => 'Packaging:', 'Não informada' => 'Not provided',
            'Características:' => 'Specifications:', 'Não informadas' => 'Not provided',
            'Enviado pelo formulário de' => 'Sent through the form at',
        ],
        'es' => [
            'Nova solicitação de cotação' => 'Nueva solicitud de cotización',
            'Contato' => 'Contacto', 'E-mail' => 'Correo electrónico', 'Telefone / WhatsApp' => 'Teléfono / WhatsApp',
            'Empresa' => 'Empresa', 'CNPJ / Registro fiscal' => 'Identificación fiscal / Registro empresarial',
            'País da empresa' => 'País de la empresa', 'Não informado' => 'No informado',
            'Perfil' => 'Perfil', 'Intermediário / broker / corretor' => 'Intermediario / broker',
            'Comprador final' => 'Comprador final', 'Sou mandatário' => 'Soy mandatario',
            'Contato direto com comprador final' => 'Contacto directo con el comprador final',
            'Não se aplica' => 'No aplica', 'Tipo de compra' => 'Tipo de compra',
            'Origem do produto' => 'Origen del producto', 'Porto ou país de destino' => 'Puerto o país de destino',
            'Termo de pagamento' => 'Condiciones de pago', 'Observações gerais' => 'Observaciones generales',
            'Nenhuma' => 'Ninguna', 'Produtos / commodities' => 'Productos / commodities',
            'Produto ' => 'Producto ', 'Quantidade:' => 'Cantidad:', 'Sim' => 'Sí', 'Não' => 'No',
            'Embalagem:' => 'Embalaje:', 'Não informada' => 'No informado',
            'Características:' => 'Características:', 'Não informadas' => 'No informadas',
            'Enviado pelo formulário de' => 'Enviado mediante el formulario de',
        ],
        'zh' => [
            'Nova solicitação de cotação' => '新询价申请',
            'Contato' => '联系人', 'E-mail' => '电子邮箱', 'Telefone / WhatsApp' => '电话 / WhatsApp',
            'Empresa' => '公司', 'CNPJ / Registro fiscal' => '税号 / 公司注册号',
            'País da empresa' => '公司所在国家', 'Não informado' => '未提供',
            'Perfil' => '身份', 'Intermediário / broker / corretor' => '中介 / 经纪人',
            'Comprador final' => '最终买家', 'Sou mandatário' => '授权代表',
            'Contato direto com comprador final' => '与最终买家直接联系',
            'Não se aplica' => '不适用', 'Tipo de compra' => '采购类型',
            'Origem do produto' => '产品原产地', 'Porto ou país de destino' => '目的港或国家',
            'Termo de pagamento' => '付款条件', 'Observações gerais' => '一般备注',
            'Nenhuma' => '无', 'Produtos / commodities' => '产品 / 大宗商品',
            'Produto ' => '产品 ', 'Quantidade:' => '数量:', 'Sim' => '是', 'Não' => '否',
            'Embalagem:' => '包装:', 'Não informada' => '未提供',
            'Características:' => '规格:', 'Não informadas' => '未提供',
            'Enviado pelo formulário de' => '通过以下网站的表单发送：',
        ],
    ];
    return $language === 'pt' ? $html : strtr($html, $translations[$language] ?? $translations['en']);
}

$teamHtml = localizedEmail($html, 'en');
$senderHtml = localizedEmail($html, $language);

$to = 'contact@braziliancommodities.com';
$teamSubject = 'New quotation request - '.$companyName;
$senderSubjects = [
    'pt' => 'Cópia da sua solicitação de cotação - '.$companyName,
    'en' => 'Copy of your quotation request - '.$companyName,
    'es' => 'Copia de su solicitud de cotización - '.$companyName,
    'zh' => '您的询价申请副本 - '.$companyName,
];
$senderSubject = $senderSubjects[$language];
$from = 'contact@braziliancommodities.com';
$boundary = '=_BrazilianCommodities_'.bin2hex(random_bytes(12));
$headers = [
    'MIME-Version: 1.0',
    'From: Brazilian Commodities <'.$from.'>',
    'Reply-To: '.$contactName.' <'.$email.'>',
    'Content-Type: multipart/mixed; boundary="'.$boundary.'"',
];
$attachmentParts = '';

$allowed = [
    'application/pdf' => 'pdf',
    'application/msword' => 'doc',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
    'application/vnd.ms-excel' => 'xls',
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
    'image/jpeg' => 'jpg',
    'image/png' => 'png',
];
$totalSize = 0;
$attachments = $_FILES['attachments'] ?? null;
if ($attachments && is_array($attachments['name'] ?? null)) {
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    foreach ($attachments['name'] as $i => $originalName) {
        $error = (int)($attachments['error'][$i] ?? UPLOAD_ERR_NO_FILE);
        if ($error === UPLOAD_ERR_NO_FILE) continue;
        if ($error !== UPLOAD_ERR_OK) finish(422, false, 'Falha ao receber um dos anexos.');
        $tmp = (string)$attachments['tmp_name'][$i];
        $size = (int)$attachments['size'][$i];
        $totalSize += $size;
        if ($size > 10 * 1024 * 1024 || $totalSize > 20 * 1024 * 1024) finish(422, false, 'Os anexos excedem o limite permitido.');
        $mime = $finfo->file($tmp) ?: '';
        if (!isset($allowed[$mime])) finish(422, false, 'Tipo de anexo não permitido.');
        $safeBase = preg_replace('/[^A-Za-z0-9._-]/', '_', basename((string)$originalName));
        $safeName = $safeBase ?: 'anexo.'.$allowed[$mime];
        $content = chunk_split(base64_encode((string)file_get_contents($tmp)));
        $attachmentParts .= '--'.$boundary."\r\n";
        $attachmentParts .= 'Content-Type: '.$mime.'; name="'.$safeName."\"\r\n";
        $attachmentParts .= "Content-Transfer-Encoding: base64\r\n";
        $attachmentParts .= 'Content-Disposition: attachment; filename="'.$safeName."\"\r\n\r\n";
        $attachmentParts .= $content."\r\n";
    }
}
function mimeBody(string $html, string $boundary, string $attachmentParts): string {
    return '--'.$boundary."\r\n"
        ."Content-Type: text/html; charset=UTF-8\r\n"
        ."Content-Transfer-Encoding: 8bit\r\n\r\n".$html."\r\n"
        .$attachmentParts.'--'.$boundary."--\r\n";
}

$persistentConfigPath = dirname(__DIR__, 2).'/private/mail-config.php';
$legacyConfigPath = dirname(__DIR__).'/private/mail-config.php';
$configPath = is_file($persistentConfigPath) ? $persistentConfigPath : $legacyConfigPath;
if (!is_file($configPath)) {
    finish(500, false, 'Configuração SMTP não encontrada.');
}
$smtp = require $configPath;
if (!is_array($smtp) || empty($smtp['host']) || empty($smtp['username']) || empty($smtp['password'])) {
    finish(500, false, 'Configuração SMTP incompleta.');
}

function smtpRead($socket, array $expected): string {
    $response = '';
    do {
        $line = fgets($socket, 515);
        if ($line === false) throw new RuntimeException('Sem resposta do servidor SMTP.');
        $response .= $line;
    } while (strlen($line) >= 4 && $line[3] === '-');
    $code = (int)substr($response, 0, 3);
    if (!in_array($code, $expected, true)) throw new RuntimeException('SMTP recusou a operação: '.$code);
    return $response;
}

function smtpCommand($socket, string $command, array $expected): string {
    if (fwrite($socket, $command."\r\n") === false) throw new RuntimeException('Falha ao comunicar com o SMTP.');
    return smtpRead($socket, $expected);
}

$port = (int)($smtp['port'] ?? 465);
$transport = (($smtp['encryption'] ?? 'ssl') === 'ssl' ? 'ssl://' : 'tcp://').$smtp['host'].':'.$port;
$context = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]);
$socket = @stream_socket_client($transport, $errno, $errstr, 20, STREAM_CLIENT_CONNECT, $context);
if (!$socket) finish(500, false, 'Não foi possível conectar ao servidor de e-mail.');
stream_set_timeout($socket, 25);

function smtpSendMessage($socket, array $smtp, string $recipient, string $recipientName, string $subject, string $body, array $headers): void {
    smtpCommand($socket, 'MAIL FROM:<'.$smtp['username'].'>', [250]);
    smtpCommand($socket, 'RCPT TO:<'.$recipient.'>', [250, 251]);
    smtpCommand($socket, 'DATA', [354]);
    $messageHeaders = array_merge([
        'Date: '.date(DATE_RFC2822),
        'To: '.$recipientName.' <'.$recipient.'>',
        'Subject: =?UTF-8?B?'.base64_encode($subject).'?=',
        'Message-ID: <'.bin2hex(random_bytes(12)).'@braziliancommodities.com>',
    ], $headers);
    $message = implode("\r\n", $messageHeaders)."\r\n\r\n".$body;
    $message = preg_replace('/(?m)^\./', '..', $message);
    if (fwrite($socket, $message."\r\n.\r\n") === false) throw new RuntimeException('Falha no envio dos dados.');
    smtpRead($socket, [250]);
}

try {
    smtpRead($socket, [220]);
    smtpCommand($socket, 'EHLO braziliancommodities.com', [250]);
    if (($smtp['encryption'] ?? 'ssl') === 'tls') {
        smtpCommand($socket, 'STARTTLS', [220]);
        if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) throw new RuntimeException('Falha ao iniciar TLS.');
        smtpCommand($socket, 'EHLO braziliancommodities.com', [250]);
    }
    smtpCommand($socket, 'AUTH LOGIN', [334]);
    smtpCommand($socket, base64_encode((string)$smtp['username']), [334]);
    smtpCommand($socket, base64_encode((string)$smtp['password']), [235]);
    smtpSendMessage($socket, $smtp, $to, 'Brazilian Commodities', $teamSubject, mimeBody($teamHtml, $boundary, $attachmentParts), $headers);
    if (strcasecmp((string)$email, $to) !== 0) {
        smtpSendMessage($socket, $smtp, (string)$email, $contactName, $senderSubject, mimeBody($senderHtml, $boundary, $attachmentParts), $headers);
    }
    smtpCommand($socket, 'QUIT', [221]);
} catch (Throwable $exception) {
    fclose($socket);
    error_log('Brazilian Commodities SMTP: '.$exception->getMessage());
    finish(500, false, 'O servidor de e-mail não confirmou o envio.');
}
fclose($socket);

finish(200, true, 'Solicitação enviada com sucesso!');
