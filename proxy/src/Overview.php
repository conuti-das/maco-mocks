<?php

declare(strict_types=1);

namespace MacoMocks;

/**
 * Übersichtsseite (GET /): alle Methoden mit ihren Varianten, jede Variante direkt aufrufbar.
 *
 * Varianten: eigene Regeln (mock.json), Apidog-Erwartungen, Standard. Übrige Antwortdateien per Auswahl.
 * Jeder Aufruf wird vorab gegen den MockServer geprüft. Liefert er nicht die gemeinte Antwort
 * (z. B. weil eine frühere Regel greift), hängt ?__response=<datei> daran.
 */
final class Overview
{
    private const JSON = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION;

    private MockServer $server;
    private string $base;
    /** @var array<string, list<array>> */
    private array $examples = [];

    public function __construct(private Catalog $catalog, private string $version, private array $config)
    {
        $this->server = new MockServer($catalog, $version);
        $this->base = rtrim((string) ($config['basePath'] ?? ''), '/');
    }

    /**
     * @return list<array{name: string, source: string, condition: string, status: int, file: string, call: array, forced: ?string}>
     */
    public function variants(array $ep): array
    {
        $out = [];
        foreach ($ep['rules'] as $rule) {
            $out[] = $this->variant($ep, $rule['name'], 'Regel', $rule['when'], $rule['then'], 'Regel: ' . $rule['name']);
        }
        foreach ($ep['expectations'] ?? [] as $rule) {
            $out[] = $this->variant($ep, $rule['name'], 'Apidog', $rule['when'], $rule['then'], 'Apidog-Erwartung: ' . $rule['name']);
        }
        if ($ep['default'] !== null) {
            $out[] = $this->variant($ep, 'Standard', 'Standard', [], ['file' => $ep['default']], 'Standard');
        }
        return $out;
    }

    /** Antwortdateien, die keine Variante liefert – erreichbar per Auswahl. */
    public function otherResponses(array $ep, array $variants): array
    {
        $used = array_flip(array_column($variants, 'file'));
        $out = [];
        foreach ($ep['responses'] as $name => $response) {
            if (!isset($used[$name])) {
                $call = $this->call($ep, $this->candidates($ep, [])[0]);
                $call = $this->forceResponse($call, $name);
                $out[] = ['file' => $name, 'status' => $response['status'], 'call' => $call];
            }
        }
        return $out;
    }

    public function html(): string
    {
        $e = static fn (mixed $s): string => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        $index = [];
        $sections = '';
        $variantCount = 0;
        foreach ($this->catalog->endpoints as $ep) {
            $group = str_contains($ep['id'], '/') ? strstr($ep['id'], '/', true) : 'sonstige';
            $anchor = str_replace(['/', '.'], ['-', '-'], $ep['id']);
            $index[$group][] = '<a href="#' . $e($anchor) . '"><span class="m m-' . strtolower($ep['method']) . '">' . $e($ep['method']) . '</span> ' . $e(ltrim($ep['path'], '/'))
                . ($this->pathIsShared($ep) ? ' <small>' . $e($ep['command']) . '</small>' : '') . '</a>';

            $variants = $this->variants($ep);
            $variantCount += count($variants);
            $search = [$ep['id'], $ep['path'], $ep['command'] ?? '', $ep['summary']];
            $rows = '';
            foreach ($variants as $v) {
                $search[] = $v['name'];
                $search[] = $v['condition'];
                $forced = $v['forced'] !== null
                    ? ' <span class="forced" title="' . $e('Ohne Auswahl greift: ' . $v['forced']) . '">per Auswahl</span>'
                    : '';
                $rows .= '<tr><td>' . $e($v['name']) . ' <span class="src src-' . strtolower($v['source']) . '">' . $e($v['source']) . '</span></td>'
                    . '<td>' . $e($v['condition']) . '</td>'
                    . '<td><span class="st st-' . intdiv($v['status'], 100) . '" title="' . $e($v['file']) . '">' . $e($v['status']) . '</span></td>'
                    . '<td>' . $this->callHtml($v['call']) . $forced . '</td></tr>';
            }
            $others = [];
            foreach ($this->otherResponses($ep, $variants) as $o) {
                $others[] = $this->callHtml($o['call'], $o['file']);
            }

            $params = array_map(static fn (array $p): string => $p['name'] . ($p['in'] !== 'query' ? " ({$p['in']})" : ''), $ep['parameters'] ?? []);
            $meta = [];
            if ($ep['command'] !== null) {
                $meta[] = 'command <code>' . $e($ep['command']) . '</code>';
            }
            if ($params) {
                $meta[] = 'Parameter: ' . $e(implode(', ', $params));
            }
            if ($ep['paging']) {
                $meta[] = 'Paging über Header ' . $e($ep['paging']['limitHeader']) . ' / ' . $e($ep['paging']['offsetHeader']);
            }
            $title = $this->isGet($ep)
                ? '<a href="' . $e($this->base . $ep['path']) . '" target="_blank" rel="noopener">' . $e($ep['path']) . '</a>'
                : $e($ep['path']);
            $searchText = implode(' ', $search);
            $searchText = function_exists('mb_strtolower') ? mb_strtolower($searchText) : strtolower($searchText);
            $sections .= '<section class="ep" id="' . $e($anchor) . '" data-search="' . $e($searchText) . '">'
                . '<h2><span class="m m-' . strtolower($ep['method']) . '">' . $e($ep['method']) . '</span> ' . $title . ' <span class="sum">' . $e($ep['summary']) . '</span></h2>'
                . ($meta ? '<p class="meta">' . implode(' · ', $meta) . '</p>' : '')
                . '<div class="wrap"><table><thead><tr><th>Variante</th><th>Bedingung</th><th>Antwort</th><th>Aufruf</th></tr></thead><tbody>' . $rows . '</tbody></table></div>'
                . ($others ? '<p class="more">Weitere Antworten: ' . implode(' ', $others) . '</p>' : '')
                . '<pre class="result" hidden></pre></section>';
        }

        $nav = '';
        foreach ($index as $group => $links) {
            $nav .= '<p><strong>' . $e($group) . '</strong> ' . implode(' ', $links) . '</p>';
        }
        $global = array_map(fn (array $r): string => $e($this->conditionText($r['when'])) . ' → ' . $e($r['then']['file'] ?? ($r['then']['status'] ?? '')), $this->catalog->globalRules);
        $repo = 'https://github.com/' . $this->config['repo'];
        $counts = count($this->catalog->endpoints) . ' Methoden · ' . $variantCount . ' Varianten';

        return <<<HTML
<!doctype html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>MaKo Backend-Mocks</title>
<style>
  :root { color-scheme: light dark; --fg: #1d2330; --bg: #fff; --muted: #5b6475; --line: #d9dde5; --soft: #f3f5f8; --accent: #0b5cad; --get: #1a7f37; --post: #b35900; --warn: #9a6700; }
  @media (prefers-color-scheme: dark) { :root { --fg: #e6e9ef; --bg: #14171d; --muted: #9aa3b2; --line: #2c323d; --soft: #1c2028; --accent: #6fb1ff; --get: #4ac26b; --post: #f0883e; --warn: #d4a72c; } }
  body { font: 15px/1.5 system-ui, sans-serif; color: var(--fg); background: var(--bg); margin: 0 auto; padding: 24px 16px 48px; max-width: 1200px; }
  h1 { font-size: 22px; margin: 0 0 4px; } h2 { font-size: 17px; margin: 0 0 2px; }
  p { margin: 6px 0; } .muted, .meta, .sum, small { color: var(--muted); } a { color: var(--accent); }
  code, pre { font: 12px/1.45 ui-monospace, SFMono-Regular, Menlo, monospace; }
  input[type=search] { width: 100%; box-sizing: border-box; font: inherit; padding: 8px 10px; border: 1px solid var(--line); border-radius: 6px; background: var(--bg); color: var(--fg); margin: 10px 0; }
  nav p { line-height: 1.9; } nav a { text-decoration: none; margin-right: 10px; white-space: nowrap; }
  .m { display: inline-block; min-width: 38px; font: 600 11px/18px ui-monospace, monospace; text-align: center; border-radius: 4px; color: #fff; background: var(--muted); }
  .m-get { background: var(--get); } .m-post { background: var(--post); }
  .ep { border-top: 1px solid var(--line); padding: 16px 0 8px; } .sum { font-weight: 400; font-size: 14px; }
  .wrap { overflow-x: auto; } table { border-collapse: collapse; width: 100%; font-size: 13.5px; margin-top: 6px; }
  th, td { text-align: left; padding: 5px 8px; border-bottom: 1px solid var(--line); vertical-align: top; } th { font-weight: 600; color: var(--muted); }
  td:first-child { min-width: 200px; } td:last-child { overflow-wrap: anywhere; }
  h2, .meta, nav a, code { overflow-wrap: anywhere; }
  .src { font-size: 11px; padding: 0 5px; border-radius: 3px; background: var(--soft); color: var(--muted); white-space: nowrap; }
  .src-regel { color: var(--accent); } .forced { font-size: 11px; color: var(--warn); cursor: help; margin-left: 4px; }
  .st { font-weight: 600; } .st-4, .st-5 { color: #cf222e; }
  button.send { font: 600 12px/1 system-ui, sans-serif; padding: 5px 9px; border-radius: 5px; border: 1px solid var(--line); background: var(--soft); color: var(--fg); cursor: pointer; margin-right: 6px; }
  details { display: inline; } details pre { white-space: pre-wrap; max-height: 280px; overflow: auto; background: var(--soft); padding: 8px; border-radius: 5px; }
  summary { cursor: pointer; color: var(--muted); font-size: 12px; display: inline; }
  .more { font-size: 13px; } .more a, .more button { margin-right: 6px; }
  .result { white-space: pre-wrap; background: var(--soft); border: 1px solid var(--line); padding: 10px; border-radius: 6px; max-height: 420px; overflow: auto; }
  @media (max-width: 700px) {
    thead { display: none; } table, tbody, tr, td { display: block; }
    tr { padding: 8px 0; border-bottom: 1px solid var(--line); } td { border: 0; padding: 2px 0; }
    td:first-child { min-width: 0; font-weight: 600; } nav a { white-space: normal; }
  }
</style>
</head>
<body>
<h1>MaKo Backend-Mocks</h1>
<p class="muted">Stand <code>{$e($this->version)}</code> aus <a href="{$e($repo)}">{$e($this->config['repo'])}</a> · {$counts} · JSON: <a href="{$e($this->base)}/_mocks">/_mocks</a> · Status: <a href="{$e($this->base)}/_status">/_status</a></p>
<p>Reihenfolge je Anfrage: eigene Regeln → globale Testdaten → Apidog-Erwartungen (Sammel-Erwartungen wie „Default“ zuletzt) → Standard. Parameter ohne Regel werden ignoriert. Welche Variante gegriffen hat, steht im Header <code>X-Mock-Reason</code>. Eine Antwort erzwingen: Header <code>X-Mock-Response</code> oder <code>?__response=&lt;datei&gt;</code>.</p>
<p class="muted">Globale Testdaten (für alle Methoden mit passender Antwortdatei): {$this->joinOrDash($global)}</p>
<input type="search" id="filter" placeholder="Filtern nach Methode, Variante oder ID …" autocomplete="off">
<nav>{$nav}</nav>
<main>{$sections}</main>
<script>
(() => {
  const filter = document.getElementById('filter');
  filter.addEventListener('input', () => {
    const q = filter.value.trim().toLowerCase();
    document.querySelectorAll('section.ep').forEach((s) => { s.hidden = q !== '' && !s.dataset.search.includes(q); });
  });
  document.addEventListener('click', async (event) => {
    const button = event.target.closest('button.send');
    if (!button) return;
    const out = button.closest('section').querySelector('.result');
    out.hidden = false;
    out.textContent = button.dataset.method + ' ' + button.dataset.url + ' …';
    try {
      const init = { method: button.dataset.method, headers: JSON.parse(button.dataset.headers || '{}') };
      if (button.dataset.body !== undefined) init.body = button.dataset.body;
      const response = await fetch(button.dataset.url, init);
      let text = await response.text();
      try { text = JSON.stringify(JSON.parse(text), null, 2); } catch (e) {}
      out.textContent = button.dataset.method + ' ' + button.dataset.url + '\\n' + response.status + ' · ' + (response.headers.get('X-Mock-Response') || '') + ' · ' + (response.headers.get('X-Mock-Reason') || '') + '\\n\\n' + text.slice(0, 50000);
    } catch (e) {
      out.textContent = 'Fehler: ' + e;
    }
    out.scrollIntoView({ block: 'nearest' });
  });
})();
</script>
</body>
</html>
HTML;
    }

    // ------------------------------------------------------------------

    /** @param list<array> $when */
    private function variant(array $ep, string $name, string $source, array $when, array $then, string $reason): array
    {
        $file = $then['file'] ?? 'inline';
        $status = $then['status'] ?? ($ep['responses'][$file]['status'] ?? 200);
        $call = null;
        $forced = null;
        $candidates = $this->candidates($ep, $when);
        foreach ($candidates as $candidate) {
            $explained = $this->server->explain($candidate);
            if ($explained !== null && $explained['endpoint'] === $ep['id'] && $explained['reason'] === $reason && $explained['response'] === $file) {
                $call = $this->call($ep, $candidate);
                break;
            }
            $forced ??= $explained === null ? 'keine Antwort' : ($explained['endpoint'] !== $ep['id'] ? 'anderer Endpunkt' : $explained['reason']);
        }
        if ($call === null) {
            $call = $this->forceResponse($this->call($ep, $candidates[0]), $file);
        } else {
            $forced = null;
        }
        return [
            'name' => $name,
            'source' => $source,
            'condition' => $source === 'Standard' ? 'sonst' : $this->conditionText($when),
            'status' => (int) $status,
            'file' => $file,
            'call' => $call,
            'forced' => $forced,
        ];
    }

    /**
     * Anfragen, die die Bedingungen erfüllen: GET nur über Query; sonst Body minimal und mit Anfragebeispielen.
     *
     * @param list<array> $when
     * @return list<array> MockServer-Anfragen
     */
    private function candidates(array $ep, array $when): array
    {
        $bodies = $this->isGet($ep) ? [null] : [[], ...$this->exampleBodies($ep)];
        $out = [];
        foreach ($bodies as $body) {
            $request = $this->request($ep, $when, $body);
            if ($request !== null) {
                $out[] = $request;
                if ($this->pathIsShared($ep) && $ep['command'] !== null && !isset($request['query']['command'])) {
                    $request['query']['command'] = $ep['command'];
                    $out[] = $request;
                }
            }
        }
        return $out ?: [['method' => $ep['method'], 'path' => $ep['path'], 'query' => [], 'headers' => [], 'body' => $this->isGet($ep) ? '' : '{}']];
    }

    private function request(array $ep, array $when, ?array $body): ?array
    {
        $query = [];
        $headers = [];
        $path = $ep['path'];
        foreach ($when as [$source, $field, $op, $expected]) {
            $value = $this->sample($ep, $source, (string) $field, $op, $expected);
            if ($value === false) {
                return null;
            }
            switch ($source) {
                case 'query':
                    if ($value === null) {
                        unset($query[$field]);
                    } else {
                        $query[$field] = $value;
                    }
                    break;
                case 'header':
                    if ($value === null) {
                        unset($headers[$field]);
                    } else {
                        $headers[$field] = $value;
                    }
                    break;
                case 'command':
                    if ($value !== null) {
                        $query['command'] = $value;
                    }
                    break;
                case 'path':
                    $path = str_replace('{' . $field . '}', rawurlencode((string) $value), $path);
                    break;
                case 'body':
                    if ($body === null) {
                        return null; // GET ohne Body
                    }
                    $body = self::withValue($body, MockServer::parsePath((string) $field), $value);
                    break;
            }
        }
        $path = (string) preg_replace_callback('/\{([A-Za-z0-9_]+)\}/', fn (array $m): string => rawurlencode($this->example($ep, 'path', $m[1]) ?? '1'), $path);
        return [
            'method' => $ep['method'],
            'path' => $path,
            'query' => $query,
            'headers' => $headers,
            'body' => $body === null ? '' : (string) json_encode($body === [] ? new \stdClass() : $body, self::JSON),
        ];
    }

    /** Wert, der die Bedingung erfüllt; null = Feld weglassen; false = nicht erzeugbar (z. B. regex). */
    private function sample(array $ep, string $source, string $field, string $op, mixed $expected): string|null|false
    {
        $other = static function (callable $isBad): string {
            foreach (['x', 'y', 'z', '0', 'anders'] as $candidate) {
                if (!$isBad($candidate)) {
                    return $candidate;
                }
            }
            return 'anders-' . bin2hex(random_bytes(2));
        };
        return match ($op) {
            'equals', 'contains' => (string) $expected,
            'in' => (string) (((array) $expected)[0] ?? ''),
            'not' => $other(static fn (string $v): bool => $v === (string) $expected),
            'notIn' => $other(static fn (string $v): bool => in_array($v, array_map('strval', (array) $expected), true)),
            'notContains' => $other(static fn (string $v): bool => (string) $expected !== '' && str_contains($v, (string) $expected)),
            'exists' => $expected ? ($this->example($ep, $source, $field) ?? '1') : null,
            'gt' => (string) ((float) $expected + 1),
            'lt' => (string) ((float) $expected - 1),
            'gte', 'lte' => (string) (float) $expected,
            default => false,
        };
    }

    /** @return list<array> Anfragebeispiele (anfrage*.json) des Endpunkts */
    private function exampleBodies(array $ep): array
    {
        if (!isset($this->examples[$ep['id']])) {
            $this->examples[$ep['id']] = [];
            foreach ($ep['requestExamples'] ?? [] as $file) {
                $text = $this->catalog->requestExample($ep, $file);
                $decoded = $text === null ? null : json_decode($text, true);
                if (is_array($decoded)) {
                    $this->examples[$ep['id']][] = $decoded;
                }
            }
        }
        return $this->examples[$ep['id']];
    }

    private function example(array $ep, string $in, string $name): ?string
    {
        foreach ($ep['parameters'] ?? [] as $p) {
            if ($p['in'] === $in && $p['name'] === $name && isset($p['example']) && $p['example'] !== '') {
                return $p['example'];
            }
        }
        return null;
    }

    private static function withValue(mixed $data, array $keys, ?string $value): mixed
    {
        if (!$keys) {
            return $value;
        }
        $key = array_shift($keys);
        $key = ctype_digit($key) ? (int) $key : $key;
        $data = is_array($data) ? $data : [];
        if ($value === null && !$keys) {
            unset($data[$key]);
            return $data;
        }
        $data[$key] = self::withValue($data[$key] ?? null, $keys, $value);
        return $data;
    }

    /** @return array{method: string, url: string, headers: array, body: ?string} */
    private function call(array $ep, array $request): array
    {
        $query = [];
        foreach ($request['query'] as $k => $v) {
            $query[] = rawurlencode((string) $k) . '=' . rawurlencode((string) $v);
        }
        return [
            'method' => $request['method'],
            'url' => $this->base . $request['path'] . ($query ? '?' . implode('&', $query) : ''),
            'headers' => $request['headers'],
            'body' => $this->isGet($ep) ? null : $request['body'],
        ];
    }

    private function forceResponse(array $call, string $file): array
    {
        $call['url'] .= (str_contains($call['url'], '?') ? '&' : '?') . '__response=' . rawurlencode($file);
        return $call;
    }

    private function callHtml(array $call, ?string $label = null): string
    {
        $e = static fn (mixed $s): string => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        $text = $label ?? rawurldecode(substr($call['url'], strlen($this->base)));
        if ($call['method'] === 'GET' && !$call['headers']) {
            return '<a href="' . $e($call['url']) . '" target="_blank" rel="noopener">' . $e($text) . '</a>';
        }
        $headers = $call['headers'] + ($call['body'] !== null ? ['content-type' => 'application/json'] : []);
        $html = '<button type="button" class="send" data-method="' . $e($call['method']) . '" data-url="' . $e($call['url']) . '"'
            . ' data-headers="' . $e(json_encode((object) $headers, self::JSON)) . '"'
            . ($call['body'] !== null ? ' data-body="' . $e($call['body']) . '"' : '') . '>Senden</button>';
        if ($label !== null) {
            return $html . $e($label);
        }
        $html .= '<code>' . $e($call['method'] . ' ' . $text) . '</code>';
        if ($call['body'] !== null) {
            $pretty = json_encode(json_decode($call['body']), self::JSON | JSON_PRETTY_PRINT);
            $html .= ' <details><summary>Body</summary><pre>' . $e($pretty !== false ? $pretty : $call['body']) . '</pre></details>';
        }
        foreach ($call['headers'] as $name => $value) {
            $html .= ' <small>' . $e($name . ': ' . $value) . '</small>';
        }
        return $html;
    }

    /** @param list<array> $when */
    private function conditionText(array $when): string
    {
        if (!$when) {
            return 'immer';
        }
        $parts = [];
        foreach ($when as [$source, $field, $op, $expected]) {
            $subject = match ($source) {
                'query' => (string) $field,
                'header' => 'Header ' . $field,
                'body' => 'Body ' . $field,
                'path' => 'Pfad ' . $field,
                default => 'command',
            };
            $list = static fn (mixed $v): string => implode(' oder ', array_map('strval', (array) $v));
            $parts[] = $subject . ' ' . match ($op) {
                'equals' => '= ' . $expected,
                'not' => '≠ ' . $expected,
                'in' => '= ' . $list($expected),
                'notIn' => '≠ ' . $list($expected),
                'exists' => $expected ? 'vorhanden' : 'fehlt',
                'contains' => 'enthält ' . $expected,
                'notContains' => 'enthält nicht ' . $expected,
                'regex' => 'passt zu /' . $expected . '/',
                'gt' => '> ' . $expected,
                'gte' => '≥ ' . $expected,
                'lt' => '< ' . $expected,
                'lte' => '≤ ' . $expected,
                default => $op . ' ' . json_encode($expected, self::JSON),
            };
        }
        return implode(' und ', $parts);
    }

    private function isGet(array $ep): bool
    {
        return $ep['method'] === 'GET';
    }

    private function pathIsShared(array $ep): bool
    {
        foreach ($this->catalog->endpoints as $other) {
            if ($other['id'] !== $ep['id'] && $other['method'] === $ep['method'] && $other['path'] === $ep['path']) {
                return true;
            }
        }
        return false;
    }

    private function joinOrDash(array $items): string
    {
        return $items ? implode(' · ', $items) : '–';
    }
}
