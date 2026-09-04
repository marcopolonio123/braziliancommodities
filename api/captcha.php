<?php
declare(strict_types=1);

header('Content-Type: image/png');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');

session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    'httponly' => true,
    'samesite' => 'Strict',
]);
session_start();

if (!function_exists('imagecreatetruecolor')) {
    http_response_code(500);
    exit;
}

$code = '';
for ($i = 0; $i < 4; $i++) {
    $code .= (string)random_int(0, 9);
}

$_SESSION['quote_captcha_hash'] = hash('sha256', $code);
$_SESSION['quote_captcha_issued_at'] = time();
session_write_close();

$width = 230;
$height = 78;
$image = imagecreatetruecolor($width, $height);
$background = imagecolorallocate($image, 242, 247, 244);
$navy = imagecolorallocate($image, 11, 58, 87);
$green = imagecolorallocate($image, 25, 119, 83);
$blue = imagecolorallocate($image, 39, 112, 154);
$gray = imagecolorallocate($image, 150, 174, 165);
imagefill($image, 0, 0, $background);

for ($i = 0; $i < 9; $i++) {
    $color = [$gray, $green, $blue][random_int(0, 2)];
    imageline(
        $image,
        random_int(0, $width),
        random_int(0, $height),
        random_int(0, $width),
        random_int(0, $height),
        $color
    );
}

for ($i = 0; $i < 350; $i++) {
    imagesetpixel(
        $image,
        random_int(0, $width - 1),
        random_int(0, $height - 1),
        random_int(0, 1) ? $gray : $green
    );
}

$colors = [$navy, $green, $blue];
for ($i = 0; $i < 4; $i++) {
    imagestring(
        $image,
        5,
        50 + ($i * 34) + random_int(-3, 3),
        27 + random_int(-9, 9),
        $code[$i],
        $colors[random_int(0, count($colors) - 1)]
    );
}

imagepng($image);
imagedestroy($image);
