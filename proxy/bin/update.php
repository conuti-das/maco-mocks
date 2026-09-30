<?php

declare(strict_types=1);

// Lokale Kopie von der Kommandozeile aktualisieren (z. B. per SSH oder Cronjob):
//   php bin/update.php [--force]

use MacoMocks\Config;
use MacoMocks\RepoSync;

require __DIR__ . '/../src/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$result = (new RepoSync(Config::load(dirname(__DIR__))))->update('cli', in_array('--force', $argv, true));
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), PHP_EOL;
exit($result['status'] === 'error' ? 1 : 0);
