<?php

declare(strict_types=1);

namespace MacoMocks;

/**
 * Lokale Kopie des Mock-Repos unter data/ – damit nicht jede Anfrage gegen GitHub läuft.
 *
 *   data/current.json            aktiver Stand (Commit, Zeitpunkt, Anzahl Endpunkte)
 *   data/state.json              letzter Update-Versuch und Ergebnis
 *   data/releases/<sha>/mocks/   entpackter mocks/-Ordner eines Commits
 *   data/releases/<sha>/index.json  vorab geprüfter Katalog
 *
 * update() holt immer den echten Stand des Branches von GitHub (nie Inhalte aus dem Webhook),
 * prüft ihn und schaltet erst dann um. Ein fehlerhafter Stand bleibt draußen.
 */
final class RepoSync
{
    private string $dataDir;

    public function __construct(private array $config)
    {
        $this->dataDir = rtrim((string) $config['dataDir'], '/');
    }

    public function current(): ?array
    {
        return $this->readJson('current.json');
    }

    public function state(): array
    {
        return $this->readJson('state.json') ?? [];
    }

    public function catalog(): ?Catalog
    {
        $current = $this->current();
        if ($current === null) {
            return null;
        }
        $release = $this->dataDir . '/releases/' . $current['release'];
        $index = is_file($release . '/index.json') ? json_decode((string) file_get_contents($release . '/index.json'), true) : null;
        if (is_array($index) && ($index['format'] ?? 1) === Catalog::FORMAT) {
            return Catalog::fromArray($index, $release . '/mocks');
        }
        // Index fehlt oder stammt von einer älteren Proxy-Version: aus dem Release neu aufbauen
        if (!is_dir($release . '/mocks')) {
            return null;
        }
        $catalog = Catalog::fromDirectory($release . '/mocks', false);
        if ($catalog->errors) {
            return is_array($index) ? Catalog::fromArray($index, $release . '/mocks') : null;
        }
        @file_put_contents($release . '/index.json', json_encode($catalog->toArray(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
        return $catalog;
    }

    /** @return array{status: string, message: string, sha?: string} */
    public function update(string $trigger, bool $force = false): array
    {
        $this->ensureDataDir();
        $lock = fopen($this->dataDir . '/update.lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
            if ($lock !== false) {
                fclose($lock);
            }
            return ['status' => 'busy', 'message' => 'Update läuft bereits'];
        }
        $zipFile = null;
        $startedAt = time();
        try {
            $state = $this->state();
            $interval = (int) ($this->config['minUpdateInterval'] ?? 10);
            if (!$force && isset($state['lastAttempt']) && time() - (int) $state['lastAttempt'] < $interval) {
                return ['status' => 'skipped', 'message' => "Letzter Versuch vor weniger als {$interval} s"];
            }
            $state['lastAttempt'] = time();
            $this->writeJson('state.json', $state);

            $current = $this->current();
            $sha = $this->remoteSha();
            if (!$force && $sha !== null && $current !== null && $current['sha'] === $sha) {
                return $this->finish($state, $trigger, $startedAt, ['status' => 'unchanged', 'message' => 'Stand ist aktuell', 'sha' => $sha]);
            }

            $ref = $sha ?? 'refs/heads/' . $this->config['branch'];
            $zipFile = $this->dataDir . '/download-' . bin2hex(random_bytes(4)) . '.zip';
            $this->download($this->url((string) $this->config['archiveUrl'], $ref), $zipFile);

            if (!class_exists(\ZipArchive::class)) {
                throw new \RuntimeException('PHP-Erweiterung zip fehlt');
            }
            $zip = new \ZipArchive();
            if ($zip->open($zipFile) !== true) {
                throw new \RuntimeException('Archiv von GitHub ist kein gültiges ZIP');
            }
            $comment = (string) $zip->getArchiveComment();
            $sha ??= preg_match('/^[0-9a-f]{40}$/', $comment) ? $comment : null;
            if ($sha === null) {
                $zip->close();
                throw new \RuntimeException('Commit des Archivs nicht ermittelbar');
            }
            if (!$force && $current !== null && $current['sha'] === $sha) {
                $zip->close();
                return $this->finish($state, $trigger, $startedAt, ['status' => 'unchanged', 'message' => 'Stand ist aktuell', 'sha' => $sha]);
            }

            $releases = $this->dataDir . '/releases';
            $tmp = $releases . '/.tmp-' . bin2hex(random_bytes(4));
            $this->extractMocks($zip, $tmp);
            $zip->close();

            $catalog = Catalog::fromDirectory($tmp . '/mocks', true);
            if ($catalog->errors) {
                self::removeDir($tmp);
                $shown = array_slice($catalog->errors, 0, 5);
                return $this->finish($state, $trigger, $startedAt, [
                    'status' => 'error',
                    'message' => 'Stand ' . substr($sha, 0, 7) . ' abgelehnt, alter Stand bleibt aktiv: ' . implode(' | ', $shown),
                    'sha' => $sha,
                ]);
            }
            file_put_contents($tmp . '/index.json', json_encode($catalog->toArray(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            $target = $releases . '/' . $sha;
            if (is_dir($target)) {
                self::removeDir($target);
            }
            if (!rename($tmp, $target)) {
                throw new \RuntimeException('Release-Ordner konnte nicht aktiviert werden');
            }
            $this->writeJson('current.json', [
                'sha' => $sha,
                'release' => $sha,
                'updatedAt' => gmdate('c'),
                'endpoints' => count($catalog->endpoints),
                'trigger' => $trigger,
            ]);
            $this->cleanup($sha);
            return $this->finish($state, $trigger, $startedAt, [
                'status' => 'updated',
                'message' => count($catalog->endpoints) . ' Endpunkte aktiv',
                'sha' => $sha,
            ]);
        } catch (\Throwable $e) {
            return $this->finish($state ?? [], $trigger, $startedAt, ['status' => 'error', 'message' => $e->getMessage()]);
        } finally {
            if ($zipFile !== null && is_file($zipFile)) {
                unlink($zipFile);
            }
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    // ------------------------------------------------------------------

    /** Nach einem Webhook, der wegen Drossel/Sperre nicht sofort laufen konnte: mit der nächsten Anfrage nachholen. */
    public function markPending(): void
    {
        $this->ensureDataDir();
        touch($this->dataDir . '/pending');
    }

    public function isPending(): bool
    {
        return is_file($this->dataDir . '/pending');
    }

    private function finish(array $state, string $trigger, int $startedAt, array $result): array
    {
        $state['lastResult'] = $result + ['trigger' => $trigger, 'at' => gmdate('c')];
        $this->writeJson('state.json', $state);
        $pending = $this->dataDir . '/pending';
        clearstatcache(true, $pending);
        if (is_file($pending) && filemtime($pending) < $startedAt) {
            unlink($pending); // erledigt; ein während des Updates gesetzter Merker bleibt stehen
        }
        return $result;
    }

    /** Nur mocks/** aus dem GitHub-Archiv (<repo>-<sha>/mocks/...) übernehmen, mit Pfadprüfung. */
    private function extractMocks(\ZipArchive $zip, string $target): void
    {
        $count = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);
            $parts = explode('/', $name, 2);
            if (count($parts) < 2 || !str_starts_with($parts[1], 'mocks/')) {
                continue;
            }
            $rel = $parts[1];
            $segments = explode('/', rtrim($rel, '/'));
            foreach ($segments as $segment) {
                if ($segment === '' || $segment === '.' || $segment === '..' || !preg_match('/^[A-Za-z0-9._@-]+$/', $segment)) {
                    throw new \RuntimeException("Unzulässiger Pfad im Archiv: {$name}");
                }
            }
            if (str_ends_with($name, '/')) {
                continue;
            }
            if (!preg_match('/\.(json|md)$/', $rel)) {
                continue;
            }
            $file = $target . '/' . $rel;
            if (!is_dir(dirname($file)) && !mkdir(dirname($file), 0775, true) && !is_dir(dirname($file))) {
                throw new \RuntimeException('Ordner konnte nicht angelegt werden: ' . dirname($rel));
            }
            $stat = $zip->statIndex($i);
            if ($stat !== false && $stat['size'] > 20 * 1024 * 1024) {
                throw new \RuntimeException("Datei zu groß: {$rel}");
            }
            $in = $zip->getStream($name);
            $out = fopen($file, 'wb');
            if ($in === false || $out === false) {
                throw new \RuntimeException("Datei konnte nicht entpackt werden: {$rel}");
            }
            stream_copy_to_stream($in, $out);
            fclose($in);
            fclose($out);
            $count++;
        }
        if ($count === 0) {
            throw new \RuntimeException('Archiv enthält keinen mocks/-Ordner');
        }
    }

    private function remoteSha(): ?string
    {
        $template = (string) ($this->config['shaUrl'] ?? '');
        if ($template === '') {
            return null;
        }
        try {
            $body = $this->httpGet($this->url($template, ''), ['Accept: application/vnd.github.sha']);
        } catch (\Throwable) {
            return null; // z. B. API-Limit auf geteilter IP: dann entscheidet der ZIP-Kommentar
        }
        $sha = trim($body);
        return preg_match('/^[0-9a-f]{40}$/', $sha) ? $sha : null;
    }

    private function download(string $url, string $file): void
    {
        $body = $this->httpGet($url, []);
        if (strlen($body) > 50 * 1024 * 1024) {
            throw new \RuntimeException('Archiv größer als 50 MB');
        }
        file_put_contents($file, $body);
    }

    private function httpGet(string $url, array $headers): string
    {
        if (str_starts_with($url, 'file://')) {
            $data = @file_get_contents(substr($url, 7));
            if ($data === false) {
                throw new \RuntimeException("Datei nicht lesbar: {$url}");
            }
            return $data;
        }
        $headers[] = 'User-Agent: maco-apidog-mocks-proxy';
        if (!empty($this->config['githubToken']) && str_contains($url, 'github.com')) {
            $headers[] = 'Authorization: Bearer ' . $this->config['githubToken'];
        }
        $timeout = (int) ($this->config['httpTimeout'] ?? 20);
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 5,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_TIMEOUT => $timeout,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_FAILONERROR => true,
            ]);
            $data = curl_exec($ch);
            $error = curl_error($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            curl_close($ch);
            if (!is_string($data)) {
                throw new \RuntimeException("Download fehlgeschlagen ({$code}): {$error}");
            }
            return $data;
        }
        $context = stream_context_create(['http' => [
            'method' => 'GET',
            'header' => implode("\r\n", $headers),
            'timeout' => $timeout,
            'follow_location' => 1,
        ]]);
        $data = @file_get_contents($url, false, $context);
        if ($data === false) {
            throw new \RuntimeException("Download fehlgeschlagen: {$url}");
        }
        return $data;
    }

    private function url(string $template, string $ref): string
    {
        return strtr($template, [
            '{repo}' => (string) $this->config['repo'],
            '{branch}' => rawurlencode((string) $this->config['branch']),
            '{ref}' => $ref,
        ]);
    }

    private function cleanup(string $currentSha): void
    {
        $keep = max(1, (int) ($this->config['keepReleases'] ?? 3));
        $releases = $this->dataDir . '/releases';
        $dirs = [];
        foreach (scandir($releases) ?: [] as $entry) {
            if ($entry[0] === '.' && !str_starts_with($entry, '.tmp-')) {
                continue;
            }
            $path = $releases . '/' . $entry;
            if (!is_dir($path)) {
                continue;
            }
            if (str_starts_with($entry, '.tmp-')) {
                if (filemtime($path) < time() - 3600) {
                    self::removeDir($path); // Reste abgebrochener Updates
                }
                continue;
            }
            $dirs[$entry] = filemtime($path);
        }
        unset($dirs[$currentSha]);
        arsort($dirs);
        foreach (array_slice(array_keys($dirs), $keep - 1) as $old) {
            self::removeDir($releases . '/' . $old);
        }
    }

    private function ensureDataDir(): void
    {
        foreach ([$this->dataDir, $this->dataDir . '/releases'] as $dir) {
            if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
                throw new \RuntimeException("Datenordner nicht anlegbar: {$dir}");
            }
        }
        // Schutz, falls data/ im Webroot liegt
        if (!is_file($this->dataDir . '/.htaccess')) {
            file_put_contents(
                $this->dataDir . '/.htaccess',
                "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n    Deny from all\n</IfModule>\n",
            );
        }
    }

    private function readJson(string $name): ?array
    {
        $file = $this->dataDir . '/' . $name;
        if (!is_file($file)) {
            return null;
        }
        $data = json_decode((string) file_get_contents($file), true);
        return is_array($data) ? $data : null;
    }

    private function writeJson(string $name, array $data): void
    {
        $file = $this->dataDir . '/' . $name;
        $tmp = $file . '.tmp-' . bin2hex(random_bytes(3));
        file_put_contents($tmp, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
        rename($tmp, $file); // atomar
    }

    private static function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            $item->isDir() && !$item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($dir);
    }
}
