<?php

declare(strict_types=1);

// Übernimmt Endpunkte und Beispiele aus dem Apidog-Backup (OpenAPI-Export) nach mocks/.
//
//   php tools/import-apidog.php <openapi.json> [--stand <commit>] [--dry-run]
//
// Auswahl und Gruppen: tools/import-apidog.json. Der Import schreibt nur
//   mocks/<gruppe>/<endpunkt>/apidog/*    Antworten (<status>[-name].json) und Anfragebeispiele (anfrage*.json)
//   mocks/<gruppe>/<endpunkt>/mock.json   Felder summary, method, path, command, parameters, apidog
// Regeln, default, set, paging und eigene Antwortdateien im Endpunkt-Ordner bleiben unangetastet.
// Mit "import": false in mock.json wird ein Endpunkt beim Import übersprungen.

ini_set('memory_limit', '1G');

$args = parseArgs(array_slice($argv, 1));
$openapiFile = $args['_'][0] ?? null;
if ($openapiFile === null || !is_file($openapiFile)) {
    fwrite(STDERR, "Aufruf: php tools/import-apidog.php <openapi.json> [--stand <commit>] [--dry-run]\n");
    exit(1);
}
$root = dirname(__DIR__);
$mocksDir = $root . '/mocks';
$config = json_decode((string) file_get_contents(__DIR__ . '/import-apidog.json'), true);
$dryRun = isset($args['dry-run']);
$stand = isset($args['stand']) && is_string($args['stand']) ? $args['stand'] : null;

$oas = json_decode((string) file_get_contents($openapiFile), false);
if (!$oas instanceof stdClass || !isset($oas->paths)) {
    fwrite(STDERR, "Keine OpenAPI-Datei: {$openapiFile}\n");
    exit(1);
}
$methods = ['get', 'post', 'put', 'patch', 'delete'];

// 1. Operationen einsammeln ----------------------------------------------------------
$all = [];
foreach (get_object_vars($oas->paths) as $exportPath => $item) {
    foreach ($methods as $method) {
        if (isset($item->{$method}) && $item->{$method} instanceof stdClass) {
            $all[] = ['exportPath' => (string) $exportPath, 'method' => strtoupper($method), 'op' => $item->{$method}];
        }
    }
}

// Beispiele je operationId + Statuscode – für Kopien ohne eigenes Beispiel
$examplesByOperation = [];
foreach ([true, false] as $selectedFirst) {
    foreach ($all as $entry) {
        if (isSelected($entry, $config['select']) !== $selectedFirst) {
            continue;
        }
        foreach (get_object_vars($entry['op']->responses ?? new stdClass()) as $code => $response) {
            $examples = collectExamples(pickContent($response->content ?? null));
            $key = ($entry['op']->operationId ?? '') . '|' . $code;
            if ($examples && !isset($examplesByOperation[$key])) {
                $examplesByOperation[$key] = $examples;
            }
        }
    }
}

// Auswahl, Apidog-Suffixe (…_ / …__) auflösen, Doppelte zusammenführen
$endpoints = [];
foreach ($all as $entry) {
    if (!isSelected($entry, $config['select'])) {
        continue;
    }
    $path = $entry['exportPath'];
    $base = rtrim($path, '_');
    if ($base !== $path && isset($oas->paths->{$base})) {
        $path = $base;
    }
    $key = $entry['method'] . ' ' . $path . ' ' . ($entry['op']->operationId ?? '');
    $isPrimary = $path === $entry['exportPath'];
    if (!isset($endpoints[$key]) || ($isPrimary && !$endpoints[$key]['primary'])) {
        $endpoints[$key] = $entry + ['path' => $path, 'primary' => $isPrimary];
    }
}

// 2. Vorhandene Endpunkt-Ordner (dürfen umbenannt/verschoben sein) --------------------
$existing = [];
foreach (findMockJson($mocksDir) as $relDir) {
    $mock = json_decode((string) file_get_contents("{$mocksDir}/{$relDir}/mock.json"), false);
    if ($mock instanceof stdClass) {
        $existing[strtoupper((string) ($mock->method ?? '')) . ' ' . ($mock->path ?? '') . ' ' . ($mock->command ?? '')] = $relDir;
    }
}

// 3. Schreiben ------------------------------------------------------------------------
$report = ['neu' => [], 'aktualisiert' => [], 'übersprungen' => [], 'ausSchema' => []];
$usedDirs = array_flip($existing);
foreach ($endpoints as $key => $entry) {
    $op = $entry['op'];
    $command = (string) ($op->operationId ?? '');
    $relDir = $existing[$key] ?? null;
    if ($relDir === null) {
        $slug = trim(preg_replace('/[^A-Za-z0-9._-]+/', '_', $entry['path']) ?? '', '_') ?: 'root';
        $relDir = groupOf($op, $config['groups']) . '/' . $slug;
        if (isset($usedDirs[$relDir])) {
            $relDir .= '.' . $command;
        }
        $usedDirs[$relDir] = $key;
    }
    $dir = "{$mocksDir}/{$relDir}";
    $mockFile = "{$dir}/mock.json";
    $mock = is_file($mockFile) ? json_decode((string) file_get_contents($mockFile), false) : null;
    if ($mock instanceof stdClass && ($mock->import ?? true) === false) {
        $report['übersprungen'][] = $relDir;
        continue;
    }
    $isNew = !$mock instanceof stdClass;

    // Antworten und Anfragebeispiele nach apidog/
    $files = [];
    $fromSchema = [];
    foreach (get_object_vars($op->responses ?? new stdClass()) as $code => $response) {
        $code = (string) $code;
        if (!preg_match('/^[1-5]\d\d$/', $code)) {
            continue;
        }
        $content = pickContent($response->content ?? null);
        $examples = collectExamples($content);
        if (!$examples && (int) $code < 300) {
            $examples = $examplesByOperation[$command . '|' . $code] ?? [];
        }
        if (count($examples) === 1) {
            $files["{$code}.json"] = $examples[0]['value'];
        } elseif ($examples) {
            foreach ($examples as $example) {
                $files["{$code}-{$example['name']}.json"] = $example['value'];
            }
        } else {
            $files["{$code}.json"] = isset($content->schema) ? sampleSchema($content->schema, $oas) : new stdClass();
            if ((int) $code < 300) {
                $fromSchema[] = "{$code}.json"; // Fehlerantworten sind in Apidog meist bewusst {}
            }
        }
    }
    $requestExamples = collectExamples(pickContent($op->requestBody->content ?? null));
    foreach ($requestExamples as $example) {
        $files[count($requestExamples) === 1 ? 'anfrage.json' : "anfrage-{$example['name']}.json"] = $example['value'];
    }

    // mock.json: nur Apidog-Felder setzen
    $mock = $isNew ? new stdClass() : $mock;
    $fresh = new stdClass();
    $fresh->summary = trim((string) ($op->summary ?? ''));
    $fresh->method = $entry['method'];
    $fresh->path = $entry['path'];
    $fresh->command = $command;
    if ($isNew) {
        $fresh->rules = [];
    }
    foreach (get_object_vars($fresh) as $k => $v) {
        if (!$isNew && $k === 'rules') {
            continue;
        }
        $mock->{$k} = $v;
    }
    $mock->parameters = array_map('describeParameter', $op->parameters ?? []);
    $meta = new stdClass();
    $meta->operationId = $command;
    if ($stand !== null) {
        $meta->stand = $stand;
    }
    if ($fromSchema) {
        $meta->ausSchema = $fromSchema;
    }
    $mock->apidog = $meta;

    if ($fromSchema) {
        $report['ausSchema'][] = "{$relDir}: " . implode(', ', $fromSchema);
    }
    $report[$isNew ? 'neu' : 'aktualisiert'][] = $relDir;
    if ($dryRun) {
        continue;
    }
    if (!is_dir("{$dir}/apidog")) {
        mkdir("{$dir}/apidog", 0775, true);
    }
    foreach (scandir("{$dir}/apidog") ?: [] as $old) {
        if (str_ends_with($old, '.json') && !isset($files[$old])) {
            unlink("{$dir}/apidog/{$old}"); // apidog/ gehört dem Import
        }
    }
    foreach ($files as $name => $value) {
        file_put_contents("{$dir}/apidog/{$name}", prettyJson($value));
    }
    file_put_contents($mockFile, prettyJson($mock));
}

$imported = array_flip(array_map(static fn ($k) => $k, array_keys($endpoints)));
$gone = array_values(array_diff_key($existing, $imported));

echo ($dryRun ? '[Probelauf] ' : '') . count($endpoints) . " Endpunkte aus Apidog\n";
echo '  neu: ' . count($report['neu']) . ', aktualisiert: ' . count($report['aktualisiert']) . ', übersprungen: ' . count($report['übersprungen']) . "\n";
foreach ($report['ausSchema'] as $line) {
    echo "  ohne Apidog-Beispiel (aus Schema erzeugt): {$line}\n";
}
foreach ($gone as $relDir) {
    echo "  nicht mehr in Apidog (bleibt stehen, bitte prüfen): {$relDir}\n";
}

// -------------------------------------------------------------------------------------

function isSelected(array $entry, array $sel): bool
{
    $op = $entry['op'];
    $tags = array_map('strval', $op->tags ?? []);
    $oid = (string) ($op->operationId ?? '');
    foreach ($tags as $tag) {
        foreach ($sel['excludeTagsContaining'] ?? [] as $needle) {
            if (str_contains($tag, $needle)) {
                return false;
            }
        }
    }
    if (!empty($sel['excludePathPattern']) && preg_match('#' . $sel['excludePathPattern'] . '#', $entry['exportPath'])) {
        return false;
    }
    if (in_array($oid, $sel['excludeOperationIds'] ?? [], true)) {
        return false;
    }
    if (array_intersect($tags, $sel['tags'] ?? []) || in_array($oid, $sel['operationIds'] ?? [], true)) {
        return true;
    }
    foreach ($sel['operationIdPrefixes'] ?? [] as $prefix) {
        if (str_starts_with($oid, $prefix)) {
            return true;
        }
    }
    return false;
}

function groupOf(stdClass $op, array $groups): string
{
    $oid = (string) ($op->operationId ?? '');
    $tags = array_map('strval', $op->tags ?? []);
    foreach ($groups as $group) {
        foreach ($group['operationIdPrefixes'] ?? [] as $prefix) {
            if (str_starts_with($oid, $prefix)) {
                return $group['name'];
            }
        }
        if (array_intersect($tags, $group['tags'] ?? [])) {
            return $group['name'];
        }
    }
    foreach ($groups as $group) {
        if (!empty($group['default'])) {
            return $group['name'];
        }
    }
    return 'sonstige';
}

function describeParameter(stdClass $p): stdClass
{
    $out = new stdClass();
    $out->in = (string) ($p->in ?? '');
    $out->name = (string) ($p->name ?? '');
    $out->required = (bool) ($p->required ?? false);
    $description = firstLine((string) ($p->description ?? ''));
    if ($description !== '') {
        $out->description = $description;
    }
    $schema = $p->schema ?? new stdClass();
    foreach (['type', 'format', 'enum'] as $k) {
        if (isset($schema->{$k})) {
            $out->{$k} = $schema->{$k};
        }
    }
    foreach ([$p->example ?? null, $schema->example ?? null, $schema->default ?? null] as $example) {
        if ($example !== null && $example !== '' && !(is_string($example) && str_contains($example, '{{'))) {
            $out->example = $example;
            break;
        }
    }
    return $out;
}

function pickContent(mixed $content): ?stdClass
{
    if (!$content instanceof stdClass) {
        return null;
    }
    foreach (['application/json', '*/*'] as $type) {
        if (isset($content->{$type}) && $content->{$type} instanceof stdClass) {
            return $content->{$type};
        }
    }
    foreach (get_object_vars($content) as $value) {
        return $value instanceof stdClass ? $value : null;
    }
    return null;
}

/** @return list<array{name: string, value: mixed}> */
function collectExamples(?stdClass $content): array
{
    if ($content === null) {
        return [];
    }
    $out = [];
    if (isset($content->examples) && $content->examples instanceof stdClass) {
        foreach (get_object_vars($content->examples) as $key => $example) {
            if (!$example instanceof stdClass || !property_exists($example, 'value')) {
                continue;
            }
            $label = trim((string) ($example->summary ?? ($example->description ?? $key)));
            $out[] = ['name' => slug($label) ?: slug((string) $key) ?: 'beispiel', 'value' => $example->value];
        }
    }
    if (!$out && property_exists($content, 'example')) {
        $out[] = ['name' => 'beispiel', 'value' => $content->example];
    }
    return $out;
}

// Beispiele wie {"$ref": "https://…"} verweisen auf externe Dateien – dann aus den Properties erzeugen.
function usable(mixed $value): bool
{
    if ($value === null) {
        return false;
    }
    return !($value instanceof stdClass && array_keys(get_object_vars($value)) === ['$ref']);
}

function sampleSchema(mixed $schema, stdClass $oas, int $depth = 0, array $seen = []): mixed
{
    if (!$schema instanceof stdClass || $depth > 8) {
        return null;
    }
    // Geschwister von $ref (z. B. boTyp: {$ref: BOTyp, default: "ENERGIEMENGE"}) haben Vorrang
    if (property_exists($schema, 'example') && usable($schema->example)) {
        return $schema->example;
    }
    if (isset($schema->examples) && is_array($schema->examples) && $schema->examples && usable($schema->examples[0])) {
        return $schema->examples[0];
    }
    if (property_exists($schema, 'default') && usable($schema->default)) {
        return $schema->default;
    }
    if (isset($schema->{'$ref'})) {
        $ref = (string) $schema->{'$ref'};
        $name = rawurldecode(substr($ref, strrpos($ref, '/') + 1));
        if (!str_starts_with($ref, '#/') || isset($seen[$name])) {
            return null;
        }
        $target = $oas->components->schemas->{$name} ?? null;
        return sampleSchema($target, $oas, $depth + 1, $seen + [$name => true]);
    }
    if (isset($schema->enum) && is_array($schema->enum) && $schema->enum) {
        return $schema->enum[0];
    }
    if (isset($schema->allOf) && is_array($schema->allOf)) {
        $merged = new stdClass();
        foreach ($schema->allOf as $part) {
            $value = sampleSchema($part, $oas, $depth + 1, $seen);
            if ($value instanceof stdClass) {
                foreach (get_object_vars($value) as $k => $v) {
                    $merged->{$k} = $v;
                }
            }
        }
        return $merged;
    }
    foreach (['oneOf', 'anyOf'] as $k) {
        if (isset($schema->{$k}) && is_array($schema->{$k}) && $schema->{$k}) {
            return sampleSchema($schema->{$k}[0], $oas, $depth + 1, $seen);
        }
    }
    $type = $schema->type ?? (isset($schema->properties) ? 'object' : (isset($schema->items) ? 'array' : null));
    if (is_array($type)) {
        $type = array_values(array_diff($type, ['null']))[0] ?? null;
    }
    switch ($type) {
        case 'object':
            $out = new stdClass();
            foreach (get_object_vars($schema->properties ?? new stdClass()) as $k => $v) {
                $out->{$k} = sampleSchema($v, $oas, $depth + 1, $seen);
            }
            return $out;
        case 'array':
            return isset($schema->items) ? [sampleSchema($schema->items, $oas, $depth + 1, $seen)] : [];
        case 'string':
            return match ($schema->format ?? null) {
                'date-time' => '2024-06-28T12:18:00Z',
                'date' => '2024-06-28',
                'uuid' => '00000000-0000-0000-0000-000000000000',
                default => 'string',
            };
        case 'integer':
        case 'number':
            return $schema->minimum ?? 0;
        case 'boolean':
            return true;
        default:
            return null;
    }
}

/** @return list<string> */
function findMockJson(string $root): array
{
    $found = [];
    if (!is_dir($root)) {
        return $found;
    }
    $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    foreach ($items as $item) {
        if ($item->getFilename() === 'mock.json') {
            $found[] = substr($item->getPath(), strlen($root) + 1);
        }
    }
    sort($found);
    return $found;
}

function prettyJson(mixed $value): string
{
    $json = json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
    // 2 statt 4 Leerzeichen einrücken
    return preg_replace_callback('/^(?: {4})+/m', static fn ($m) => str_repeat('  ', intdiv(strlen($m[0]), 4)), (string) $json) . "\n";
}

function firstLine(string $text): string
{
    foreach (explode("\n", $text) as $line) {
        $line = trim(str_replace(['`', '*', '#', '>'], '', $line));
        if ($line !== '') {
            return mb_substr($line, 0, 200);
        }
    }
    return '';
}

function slug(string $text): string
{
    $text = strtr(mb_strtolower($text), ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss']);
    return substr(trim((string) preg_replace('/[^a-z0-9]+/', '-', $text), '-'), 0, 60);
}

function parseArgs(array $list): array
{
    $out = ['_' => []];
    for ($i = 0; $i < count($list); $i++) {
        if (str_starts_with($list[$i], '--')) {
            $key = substr($list[$i], 2);
            $out[$key] = isset($list[$i + 1]) && !str_starts_with($list[$i + 1], '--') ? $list[++$i] : true;
        } else {
            $out['_'][] = $list[$i];
        }
    }
    return $out;
}
