<?php

declare(strict_types=1);

namespace LbHosting\Update;

use LbHosting\Support\Logger;
use LbHosting\Support\Settings;
use LbHosting\WHMCS\Database;

/**
 * "Module Auto Install and Update" - signed self-update.
 *
 * check():   fetch manifest (https) -> verify Ed25519 signature -> compare version
 * install(): re-verify -> download zip (https, size cap) -> SHA-256 must equal the
 *            signed value -> every zip entry must live under modules/addons/lbhosting/
 *            or the eight modules/servers/lbh... folders (no "..", no
 *            absolute paths, never an overrides/ folder) -> all targets
 *            writable -> backup current files -> copy -> run migrations.
 *            Any failure while copying restores the backup.
 */
final class Updater
{
    private const MAX_MANIFEST = 65536;
    private const MAX_ZIP = 20 * 1024 * 1024;
    private const MAX_UNPACKED = 60 * 1024 * 1024;
    private const MAX_ENTRIES = 3000;
    public const SERVER_MODULES = ['lbhcloud', 'lbhmultidomain', 'lbhsingledomain', 'lbhreseller', 'lbhwordpress', 'lbhbusiness', 'lbhvps', 'lbhdedicated'];
    private const ALLOWED = ['modules/addons/lbhosting/', 'modules/servers/lbhcloud/', 'modules/servers/lbhmultidomain/', 'modules/servers/lbhsingledomain/', 'modules/servers/lbhreseller/', 'modules/servers/lbhwordpress/', 'modules/servers/lbhbusiness/', 'modules/servers/lbhvps/', 'modules/servers/lbhdedicated/'];
    private const PROTECTED = ['modules/addons/lbhosting/overrides/', 'modules/servers/lbhcloud/overrides/', 'modules/servers/lbhmultidomain/overrides/', 'modules/servers/lbhsingledomain/overrides/', 'modules/servers/lbhreseller/overrides/', 'modules/servers/lbhwordpress/overrides/', 'modules/servers/lbhbusiness/overrides/', 'modules/servers/lbhvps/overrides/', 'modules/servers/lbhdedicated/overrides/'];

    /** @var callable fn(string $url, int $maxBytes): string  (test seam) */
    private $fetch;

    private string $root;

    public function __construct(?callable $fetch = null, ?string $whmcsRoot = null)
    {
        $this->fetch = $fetch ?? [self::class, 'httpGet'];
        $this->root = rtrim($whmcsRoot ?? dirname(LBH_ROOT, 3), '/\\');
    }

    public static function currentVersion(): string
    {
        return defined('LBH_VERSION') ? LBH_VERSION : '0.0.0';
    }

    /** @return array<string,mixed> state saved in settings */
    public function check(): array
    {
        $state = ['checked_at' => date('Y-m-d H:i:s'), 'current' => self::currentVersion(), 'ok' => false, 'available' => false];
        try {
            $url = trim(Settings::get('update_url'));
            if ($url === '' || stripos($url, 'https://') !== 0) {
                throw new \RuntimeException('Set an https update URL under Settings > Updates.');
            }
            $m = Manifest::verify(($this->fetch)($url, self::MAX_MANIFEST), Settings::get('update_pubkey'));
            $state['ok'] = true;
            $state['latest'] = $m->version();
            $state['notes'] = mb_substr((string) ($m->data['notes'] ?? ''), 0, 4000);
            $state['released'] = (string) ($m->data['released'] ?? '');
            $state['available'] = $m->isNewerThan(self::currentVersion());
            $state['php_ok'] = $m->phpOk();
        } catch (\Throwable $e) {
            $state['error'] = $e->getMessage();
            Logger::log('update', 'Update check failed: ' . $e->getMessage(), 'warning');
        }
        Settings::set('update_state', (string) json_encode($state));

        return $state;
    }

    /** @return array<string,mixed> */
    public static function state(): array
    {
        $s = json_decode(Settings::get('update_state'), true);

        return is_array($s) ? $s : [];
    }

    /** @return string installed version */
    public function install(): string
    {
        $url = trim(Settings::get('update_url'));
        $m = Manifest::verify(($this->fetch)($url, self::MAX_MANIFEST), Settings::get('update_pubkey'));
        if (!$m->isNewerThan(self::currentVersion())) {
            throw new \RuntimeException('No newer version available (latest ' . $m->version() . ').');
        }
        if (!$m->phpOk()) {
            throw new \RuntimeException('Version ' . $m->version() . ' needs PHP ' . $m->data['min_php'] . ' or newer.');
        }
        if (!class_exists(\ZipArchive::class)) {
            throw new \RuntimeException('PHP zip extension is required to install updates.');
        }

        $zipBytes = ($this->fetch)((string) $m->data['zip_url'], self::MAX_ZIP);
        if (!hash_equals((string) $m->data['sha256'], hash('sha256', $zipBytes))) {
            throw new \RuntimeException('Downloaded package does not match the signed SHA-256 - update refused.');
        }

        $lock = $this->lock();
        $work = $this->tempDir();
        try {
            $zipFile = $work . '/package.zip';
            file_put_contents($zipFile, $zipBytes);
            $files = $this->readPackage($zipFile);
            // The package must BE the version the manifest announces, or it would be offered again forever.
            if (!preg_match("/define\\('LBH_VERSION',\\s*'" . preg_quote($m->version(), '/') . "'\\)/", $files['modules/addons/lbhosting/autoload.php'])) {
                throw new \RuntimeException('The package is not version ' . $m->version() . ' (its autoload.php says otherwise) - update refused.');
            }
            $this->assertWritable(array_keys($files));
            $backup = $this->backup();
            $written = [];
            $created = [];
            try {
                foreach ($files as $rel => $content) {
                    $target = $this->root . '/' . $rel;
                    if (!file_exists($target)) {
                        $created[] = $target;
                    }
                    if (!is_dir(dirname($target)) && !mkdir(dirname($target), 0755, true) && !is_dir(dirname($target))) {
                        throw new \RuntimeException('Cannot create directory ' . dirname($rel));
                    }
                    $tmp = $target . '.lbh-new';
                    if (file_put_contents($tmp, $content) === false || !@rename($tmp, $target)) {
                        @unlink($tmp);
                        throw new \RuntimeException('Cannot write ' . $rel);
                    }
                    $written[] = $target;
                    if (function_exists('opcache_invalidate') && substr($target, -4) === '.php') {
                        @opcache_invalidate($target, true);
                    }
                }
            } catch (\Throwable $e) {
                // Files this update added (a new migration, say) must not stay behind next to the old code.
                foreach ($created as $f) {
                    if (is_file($f)) {
                        @unlink($f);
                    }
                }
                try {
                    $this->restore($backup);
                } catch (\Throwable $r) {
                    Logger::log('update', 'Update to ' . $m->version() . ' failed (' . $e->getMessage() . ') AND the automatic restore failed (' . $r->getMessage() . '). Restore by hand from ' . $backup, 'error');
                    throw new \RuntimeException('Update failed (' . $e->getMessage() . ') and the automatic restore also failed: ' . $r->getMessage() . '. Unzip ' . $backup . ' over the WHMCS folder to restore the previous version.');
                }
                Logger::log('update', 'Update to ' . $m->version() . ' failed while copying (' . $e->getMessage() . '); previous version restored from ' . $backup, 'error');
                throw new \RuntimeException('Update failed and was rolled back: ' . $e->getMessage());
            }
        } finally {
            $this->rrmdir($work);
            if (is_resource($lock)) {
                @flock($lock, LOCK_UN);
                @fclose($lock);
            }
        }

        try {
            Database::migrate();
        } catch (\Throwable $e) {
            Logger::log('update', 'Files updated to ' . $m->version() . ' but migrations failed: ' . $e->getMessage() . ' - open Setup > Addon Modules to retry.', 'error');
        }
        Logger::log('update', sprintf('Updated from %s to %s (%d files, backup %s)', self::currentVersion(), $m->version(), count($written), $backup));
        Settings::set('update_state', (string) json_encode(['checked_at' => date('Y-m-d H:i:s'), 'current' => $m->version(), 'latest' => $m->version(), 'ok' => true, 'available' => false, 'installed_at' => date('Y-m-d H:i:s')]));

        return $m->version();
    }

    /**
     * Validate every entry, return path => content.
     *
     * @return array<string,string>
     */
    public function readPackage(string $zipFile): array
    {
        $zip = new \ZipArchive();
        if ($zip->open($zipFile) !== true) {
            throw new \RuntimeException('Package is not a valid zip file.');
        }
        try {
            if ($zip->numFiles > self::MAX_ENTRIES) {
                throw new \RuntimeException('Package has too many files.');
            }
            $files = [];
            $total = 0;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $st = $zip->statIndex($i);
                if ($st === false) {
                    throw new \RuntimeException('Package is corrupt (entry ' . $i . ').');
                }
                $name = (string) $st['name'];
                if (substr($name, -1) === '/') {
                    continue; // directory entry
                }
                self::assertSafePath($name);
                if (self::isProtected($name)) {
                    continue; // never overwrite the admin's own overrides
                }
                $total += (int) $st['size'];
                if ($total > self::MAX_UNPACKED) {
                    throw new \RuntimeException('Package is too large when unpacked.');
                }
                $content = $zip->getFromIndex($i);
                if ($content === false) {
                    throw new \RuntimeException('Cannot read ' . $name . ' from package.');
                }
                $files[$name] = $content;
            }
        } finally {
            $zip->close();
        }
        foreach (array_merge(['modules/addons/lbhosting/lbhosting.php', 'modules/addons/lbhosting/autoload.php'], array_map(static fn ($m) => 'modules/servers/' . $m . '/' . $m . '.php', self::SERVER_MODULES)) as $required) {
            if (!isset($files[$required])) {
                throw new \RuntimeException('Package is incomplete (missing ' . $required . ').');
            }
        }

        return $files;
    }

    /** Case-insensitive: on Windows / macOS file systems "Overrides/" is the same folder. */
    public static function isProtected(string $name): bool
    {
        $lower = strtolower($name);
        foreach (self::PROTECTED as $p) {
            if (strpos($lower, $p) === 0) {
                return true;
            }
        }

        return false;
    }

    public static function assertSafePath(string $name): void
    {
        $ok = strpos($name, "\0") === false
            && strpos($name, '\\') === false
            && $name[0] !== '/'
            && !preg_match('#(^|/)\.{1,2}(/|$)#', $name)   // no "." or ".." segment
            && strpos($name, '//') === false                // no empty segment: "x/./overrides" and "x//overrides" would dodge the overrides check
            && !preg_match('#^[a-zA-Z]:#', $name);
        $allowed = false;
        foreach (self::ALLOWED as $prefix) {
            if (strpos($name, $prefix) === 0) {
                $allowed = true;
            }
        }
        if (!$ok || !$allowed) {
            throw new \RuntimeException('Package contains a file outside the module folders (' . $name . ') - update refused.');
        }
    }

    /** @param string[] $relPaths */
    private function assertWritable(array $relPaths): void
    {
        foreach ($relPaths as $rel) {
            $target = $this->root . '/' . $rel;
            $probe = $target;
            while (!file_exists($probe)) {
                $probe = dirname($probe);
            }
            if (!is_writable($probe)) {
                throw new \RuntimeException('Not writable by PHP: ' . substr($probe, strlen($this->root) + 1) . ' - fix file ownership (e.g. RunCloud app user) and retry. Nothing was changed.');
            }
        }
    }

    private function backup(): string
    {
        $dir = $this->backupDir();
        $file = $dir . '/lbhosting-' . self::currentVersion() . '-' . date('Ymd-His') . '.zip';
        $zip = new \ZipArchive();
        if ($zip->open($file, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Cannot create backup ' . $file . '. Nothing was changed.');
        }
        foreach (self::ALLOWED as $prefix) {
            $base = $this->root . '/' . rtrim($prefix, '/');
            if (!is_dir($base)) {
                continue;
            }
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $f) {
                if ($f->isFile()) {
                    $zip->addFile($f->getPathname(), substr($f->getPathname(), strlen($this->root) + 1));
                }
            }
        }
        if (!$zip->close() || !is_file($file) || filesize($file) < 100) {
            @unlink($file);
            throw new \RuntimeException('The backup could not be written (' . $file . '). Nothing was changed.');
        }
        @chmod($file, 0600);

        // Keep the five most recent backups.
        $old = glob($dir . '/lbhosting-*.zip') ?: [];
        rsort($old);
        usort($old, static fn ($x, $y) => filemtime($y) <=> filemtime($x));
        foreach (array_slice($old, 5) as $f) {
            if ($f !== $file) {
                @unlink($f);
            }
        }

        return $file;
    }

    /** Every entry is path-checked again: the backup file is never trusted blindly. */
    private function restore(string $backup): void
    {
        $zip = new \ZipArchive();
        if ($zip->open($backup) !== true) {
            throw new \RuntimeException('backup cannot be opened');
        }
        try {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = (string) $zip->getNameIndex($i);
                if ($name === '' || substr($name, -1) === '/') {
                    continue;
                }
                self::assertSafePath($name);
                $content = $zip->getFromIndex($i);
                $target = $this->root . '/' . $name;
                if ($content === false || (!is_dir(dirname($target)) && !@mkdir(dirname($target), 0755, true) && !is_dir(dirname($target))) || file_put_contents($target, $content) === false) {
                    throw new \RuntimeException('cannot restore ' . $name);
                }
                if (function_exists('opcache_invalidate') && substr($target, -4) === '.php') {
                    @opcache_invalidate($target, true);
                }
            }
        } finally {
            $zip->close();
        }
    }

    /** One install at a time (daily cron and an admin click must not interleave). @return resource|null */
    private function lock()
    {
        $h = @fopen($this->backupDir() . '/install.lock', 'c');
        if ($h === false) {
            return null;
        }
        if (!flock($h, LOCK_EX | LOCK_NB)) {
            fclose($h);
            throw new \RuntimeException('Another update is being installed right now.');
        }

        return $h;
    }

    /** Backups go OUTSIDE the web root when possible (WHMCS attachments dir), never inside the module. */
    private function backupDir(): string
    {
        $base = '';
        if (!empty($GLOBALS['attachments_dir'])) {
            $base = (string) $GLOBALS['attachments_dir'];
        } else {
            try {
                if (class_exists('\\App') && method_exists('\\App', 'getApplicationConfig')) {
                    $base = (string) (\App::getApplicationConfig()->attachments_dir ?? '');
                }
            } catch (\Throwable $e) {
            }
        }
        if ($base !== '') {
            $dir = rtrim($base, '/\\') . '/lbhosting-backups';
            if ((is_dir($dir) || @mkdir($dir, 0700, true)) && is_writable($dir)) {
                return $dir;
            }
        }
        // No shared, guessable temp folder: a backup is restored into the WHMCS tree, so it must live somewhere only WHMCS writes.
        throw new \RuntimeException('No writable backup directory: the WHMCS attachments folder is not writable or not configured. Nothing was changed.');
    }

    private function tempDir(): string
    {
        $dir = sys_get_temp_dir() . '/lbh-update-' . bin2hex(random_bytes(6));
        if (!mkdir($dir, 0700, true)) {
            throw new \RuntimeException('Cannot create a temporary directory.');
        }

        return $dir;
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($dir);
    }

    public static function httpGet(string $url, int $maxBytes): string
    {
        if (stripos($url, 'https://') !== 0) {
            throw new \RuntimeException('Only https URLs are allowed.');
        }
        $buf = '';
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 120,
            CURLOPT_USERAGENT => 'LbHosting-Updater/' . self::currentVersion(),
            CURLOPT_WRITEFUNCTION => static function ($ch, string $chunk) use (&$buf, $maxBytes): int {
                if (strlen($buf) + strlen($chunk) > $maxBytes) {
                    return 0; // abort: too large
                }
                $buf .= $chunk;

                return strlen($chunk);
            },
        ]);
        $ok = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($ok === false || $status >= 400) {
            throw new \RuntimeException('Download failed (' . ($err !== '' ? $err : 'HTTP ' . $status) . ').');
        }

        return $buf;
    }
}
