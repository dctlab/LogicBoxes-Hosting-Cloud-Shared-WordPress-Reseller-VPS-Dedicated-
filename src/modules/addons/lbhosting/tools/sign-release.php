<?php
/**
 * Release signing tool - run on YOUR machine, never on the WHMCS server.
 *
 *   php sign-release.php keygen
 *       Prints a new Ed25519 key pair. Put the PUBLIC key in
 *       Addons > LogicBoxes Hosting > Updates. Keep the SECRET key
 *       offline (password manager); anyone holding it can push code to every
 *       install that trusts the public key.
 *
 *   php sign-release.php sign <package.zip> <version> <zip-url> <secret-key-file> [notes] [min-php]
 *       Prints the signed manifest JSON. Upload the zip to <zip-url> and the
 *       JSON to the URL set as "Update manifest URL" (both over https).
 *
 * The zip must contain modules/addons/lbhosting/... and the eight modules/servers/lbh... folders
 * exactly as in the release package.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require __DIR__ . '/../src/Update/Manifest.php';

use LbHosting\Update\Manifest;

$cmd = $argv[1] ?? '';
if ($cmd === 'keygen') {
    $kp = sodium_crypto_sign_keypair();
    echo 'PUBLIC  (paste into WHMCS): ' . base64_encode(sodium_crypto_sign_publickey($kp)) . PHP_EOL;
    echo 'SECRET  (keep offline!)   : ' . base64_encode(sodium_crypto_sign_secretkey($kp)) . PHP_EOL;
    exit(0);
}
if ($cmd === 'sign' && $argc >= 6) {
    [, , $zip, $version, $url, $keyFile] = $argv;
    $notes = $argv[6] ?? '';
    $minPhp = $argv[7] ?? '8.1';
    if (!is_file($zip) || !preg_match('/^\d+\.\d+\.\d+$/', $version) || stripos($url, 'https://') !== 0) {
        fwrite(STDERR, "Invalid arguments.\n");
        exit(1);
    }
    $sk = base64_decode(trim((string) file_get_contents($keyFile)), true);
    if ($sk === false || strlen($sk) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
        fwrite(STDERR, "Secret key file must contain the base64 secret key from keygen.\n");
        exit(1);
    }
    $manifest = Manifest::sign([
        'module' => Manifest::MODULE,
        'version' => $version,
        'released' => date('Y-m-d'),
        'zip_url' => $url,
        'sha256' => hash_file('sha256', $zip),
        'min_php' => $minPhp,
        'notes' => $notes,
    ], $sk);
    echo json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit(0);
}
fwrite(STDERR, "Usage:\n  php sign-release.php keygen\n  php sign-release.php sign <package.zip> <version> <zip-url> <secret-key-file> [notes] [min-php]\n");
exit(1);
