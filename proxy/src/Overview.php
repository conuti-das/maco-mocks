<?php

declare(strict_types=1);

namespace MacoMocks;

/**
 * Übersichtsseite (GET /) im CONUTI-Stil: alle Methoden mit ihren Varianten, jede Variante direkt aufrufbar.
 *
 * Varianten: eigene Regeln (mock.json), importierte Erwartungen, Standard. Übrige Antwortdateien per Auswahl.
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
            $out[] = $this->variant($ep, $rule['name'], 'Erwartung', $rule['when'], $rule['then'], 'Erwartung: ' . $rule['name']);
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
            $method = strtolower($ep['method']);
            $index[$group][] = '<a class="chip" href="#' . $e($anchor) . '"><span class="m m-' . $method . '">' . $e($ep['method']) . '</span>'
                . $e(ltrim($ep['path'], '/')) . ($this->pathIsShared($ep) ? ' <small>' . $e($ep['command']) . '</small>' : '') . '</a>';

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
                $rows .= '<tr><td class="v-name">' . $e($v['name']) . ' <span class="src src-' . strtolower($v['source']) . '">' . $e($v['source']) . '</span></td>'
                    . '<td class="v-cond">' . $e($v['condition']) . '</td>'
                    . '<td class="v-status"><span class="st st-' . intdiv($v['status'], 100) . '" title="' . $e($v['file']) . '">' . $e($v['status']) . '</span></td>'
                    . '<td class="v-call">' . $this->callHtml($v['call']) . $forced . '</td>'
                    . '<td class="v-send">' . $this->sendButton($v['call']) . '</td></tr>';
            }
            $others = [];
            foreach ($this->otherResponses($ep, $variants) as $o) {
                $others[] = $this->sendButton($o['call']) . $this->callHtml($o['call'], $o['file']);
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
            $sections .= '<section class="card ep" id="' . $e($anchor) . '" data-search="' . $e($searchText) . '">'
                . '<div class="ep-head"><span class="m m-' . $method . '">' . $e($ep['method']) . '</span><h2>' . $title . '</h2><span class="sum">' . $e($ep['summary']) . '</span></div>'
                . ($meta ? '<p class="meta">' . implode(' · ', $meta) . '</p>' : '')
                . '<div class="wrap"><table><thead><tr><th>Variante</th><th>Bedingung</th><th>Antwort</th><th>Aufruf</th><th><span class="sr">Senden</span></th></tr></thead><tbody>' . $rows . '</tbody></table></div>'
                . ($others ? '<p class="more">Weitere Antworten: ' . implode(' ', $others) . '</p>' : '')
                . '<pre class="result" hidden></pre></section>';
        }

        $nav = '';
        foreach ($index as $group => $links) {
            $nav .= '<div class="group"><h3>' . $e($group) . '</h3><div class="chips">' . implode('', $links) . '</div></div>';
        }
        $global = array_map(fn (array $r): string => '<code>' . $e($this->conditionText($r['when'])) . '</code> → ' . $e($r['then']['file'] ?? ($r['then']['status'] ?? '')), $this->catalog->globalRules);
        $repo = 'https://github.com/' . $this->config['repo'];
        $base = $e($this->base);
        $version = $e($this->version);
        $methods = count($this->catalog->endpoints);

        return <<<HTML
<!doctype html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>MaKo Backend-Mocks · CONUTI</title>
<link rel="preconnect" href="https://api.fontshare.com" crossorigin>
<link rel="stylesheet" href="https://api.fontshare.com/v2/css?f[]=cabinet-grotesk@300,400,500,700,800&amp;display=swap">
<style>
  :root {
    color-scheme: light dark;
    --azure: #061d95; --pulse: #7cb5f7; --black: #171717; --stone: #debe9e; --earth: #4f2b1e; --gray: #c2c5ca;
    --bg: #f4f5f7; --card: #fff; --fg: #171717; --muted: #5d6270; --line: #e3e5ea; --soft: #f1f3f7;
    --link: #061d95; --get-bg: rgba(124, 181, 247, .3); --get-fg: #061d95; --post-bg: #efe1d2; --post-fg: #4f2b1e;
    --radius: 14px; --control: 8px;
    --font: "Cabinet Grotesk", ui-sans-serif, system-ui, -apple-system, "Segoe UI", Helvetica, Arial, sans-serif;
    --mono: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
  }
  @media (prefers-color-scheme: dark) {
    :root { --bg: #0b0e16; --card: #131925; --fg: #eef0f4; --muted: #a3aab8; --line: #242b3a; --soft: #1a2130; --link: #7cb5f7;
      --get-bg: rgba(124, 181, 247, .18); --get-fg: #a9cdfa; --post-bg: rgba(222, 190, 158, .18); --post-fg: #debe9e; }
  }
  * { box-sizing: border-box; }
  body { margin: 0; font: 400 16px/1.55 var(--font); color: var(--fg); background: var(--bg); -webkit-font-smoothing: antialiased; }
  a { color: var(--link); text-underline-offset: 3px; }
  code { font: 13px var(--mono); }

  .hero { color: #fff; padding: 16px 16px 72px; background:
      radial-gradient(38rem 26rem at 80% 12%, rgba(124, 181, 247, .6), transparent 70%),
      radial-gradient(28rem 22rem at 6% 0%, rgba(255, 255, 255, .2), transparent 70%),
      radial-gradient(44rem 30rem at 45% 130%, rgba(124, 181, 247, .3), transparent 70%),
      linear-gradient(165deg, #0b2cc4 0%, #061d95 42%, #04126a 76%, #020a3d 100%); }
  .bar { max-width: 1200px; margin: 0 auto; display: flex; align-items: center; justify-content: space-between; gap: 16px;
    background: #fff; color: #171717; border-radius: var(--radius); padding: 10px 10px 10px 22px; box-shadow: 0 10px 30px rgba(2, 10, 61, .25); }
  .brand { font-weight: 800; font-size: 18px; letter-spacing: .14em; white-space: nowrap; }
  .brand small { font-weight: 500; font-size: 14px; letter-spacing: .02em; color: #5d6270; margin-left: 12px; }
  .bar nav { display: flex; align-items: center; gap: 24px; font-size: 15px; font-weight: 500; }
  .bar nav a { color: #171717; text-decoration: none; }
  .bar nav a:hover { color: #061d95; }
  .bar nav a.pill { background: var(--stone); color: var(--earth); border-radius: var(--radius); padding: 10px 16px; letter-spacing: .08em; }
  .bar nav a.pill:hover { color: var(--earth); filter: brightness(.96); }
  .hero-inner { max-width: 1200px; margin: 0 auto; padding: 72px 6px 0; }
  .eyebrow { margin: 0 0 16px; font-size: 13px; font-weight: 500; letter-spacing: .16em; text-transform: uppercase; opacity: .85; }
  h1 { margin: 0 0 18px; font-size: clamp(36px, 5.2vw, 64px); line-height: 1.06; font-weight: 700; letter-spacing: -.01em; }
  .lead { margin: 0 0 30px; max-width: 760px; font-size: 20px; line-height: 1.5; font-weight: 300; opacity: .92; }
  .stats { display: flex; flex-wrap: wrap; gap: 12px; margin: 0 0 24px; }
  .stat { padding: 10px 18px; border: 1px solid rgba(255, 255, 255, .24); border-radius: var(--radius); background: rgba(255, 255, 255, .08); font-size: 15px; }
  .stat b { font-size: 22px; font-weight: 700; margin-right: 6px; }
  .search { width: 100%; max-width: 660px; padding: 15px 20px; border: 0; border-radius: var(--radius); background: #fff; color: #171717;
    font: 400 17px var(--font); box-shadow: 0 12px 32px rgba(2, 10, 61, .35); }
  .search:focus { outline: 3px solid var(--pulse); outline-offset: 2px; }

  main { position: relative; max-width: 1200px; margin: -36px auto 0; padding: 0 16px 56px; }
  .card { background: var(--card); border: 1px solid var(--line); border-radius: var(--radius); padding: 28px; }
  .facts { display: grid; grid-template-columns: repeat(auto-fit, minmax(270px, 1fr)); gap: 16px; }
  .facts .card { padding: 22px 24px; }
  h3 { margin: 0 0 10px; font-size: 12px; font-weight: 700; letter-spacing: .14em; text-transform: uppercase; color: var(--link); }
  .facts p { margin: 0; color: var(--muted); font-size: 15px; }
  .facts code { white-space: nowrap; }
  .index { margin-top: 16px; }
  .group + .group { margin-top: 18px; }
  .chips { display: flex; flex-wrap: wrap; gap: 8px; }
  .chip { display: inline-flex; align-items: center; gap: 9px; padding: 6px 13px 6px 6px; border: 1px solid var(--line); border-radius: var(--radius);
    color: var(--fg); background: var(--card); text-decoration: none; font-size: 14px; font-weight: 500; overflow-wrap: anywhere; }
  .chip:hover { border-color: var(--link); color: var(--link); }
  .chip small { color: var(--muted); font-weight: 400; }
  .m { display: inline-block; min-width: 46px; padding: 0 8px; border-radius: var(--control); font: 700 11px/22px var(--font); letter-spacing: .08em; text-align: center; }
  .m-get { background: var(--get-bg); color: var(--get-fg); }
  .m-post { background: var(--post-bg); color: var(--post-fg); }

  .ep { margin-top: 16px; scroll-margin-top: 16px; }
  .ep-head { display: flex; align-items: center; flex-wrap: wrap; gap: 8px 14px; }
  .ep h2 { margin: 0; font-size: 24px; font-weight: 700; overflow-wrap: anywhere; }
  .ep h2 a { color: var(--fg); text-decoration: none; }
  .ep h2 a:hover { color: var(--link); }
  .sum { color: var(--muted); font-size: 16px; }
  .meta { margin: 10px 0 0; color: var(--muted); font-size: 14px; overflow-wrap: anywhere; }
  .wrap { margin-top: 18px; overflow-x: auto; }
  table { width: 100%; border-collapse: collapse; table-layout: fixed; font-size: 15px; }
  th:nth-child(1) { width: 27%; } th:nth-child(2) { width: 23%; } th:nth-child(3) { width: 96px; } th:nth-child(5) { width: 116px; }
  .sr { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0 0 0 0); white-space: nowrap; }
  th { padding: 10px 12px; border-bottom: 1px solid var(--line); text-align: left; font-size: 12px; font-weight: 700; letter-spacing: .12em; text-transform: uppercase; color: var(--muted); }
  td { padding: 11px 12px; border-bottom: 1px solid var(--line); vertical-align: top; }
  tbody tr:last-child td { border-bottom: 0; }
  tbody tr:hover td { background: var(--soft); }
  td.v-name { font-weight: 500; overflow-wrap: anywhere; }
  td.v-cond { color: var(--muted); overflow-wrap: anywhere; }
  td.v-call { overflow-wrap: anywhere; }
  td.v-call a { font: 500 13.5px var(--mono); text-decoration: none; }
  td.v-call a:hover { text-decoration: underline; }
  td.v-send { text-align: right; white-space: nowrap; }
  td.v-send button.send { margin: 0; }
  tr.active td { background: var(--soft); }
  .src { display: inline-block; margin-left: 6px; padding: 1px 7px; border-radius: 6px; font-size: 10.5px; font-weight: 700; letter-spacing: .1em; text-transform: uppercase; vertical-align: 1px; white-space: nowrap; }
  .src-regel { background: var(--azure); color: #fff; }
  .src-erwartung { background: var(--soft); color: var(--muted); border: 1px solid var(--line); }
  .src-standard { border: 1px solid var(--fg); color: var(--fg); }
  .st { font-weight: 700; }
  .st-2 { color: var(--link); }
  .st-4, .st-5 { padding: 1px 8px; border-radius: 6px; background: var(--stone); color: var(--earth); }
  .forced { margin-left: 6px; color: var(--earth); font-size: 12px; font-weight: 500; cursor: help; white-space: nowrap; }
  @media (prefers-color-scheme: dark) { .forced { color: var(--stone); } .src-regel { background: var(--pulse); color: var(--azure); } }
  button.send { margin-right: 8px; padding: 8px 13px; border: 1px solid var(--fg); border-radius: 10px; background: transparent; color: var(--fg); cursor: pointer; font: 500 13px/1 var(--font); letter-spacing: .04em; }
  button.send::after { content: " →"; }
  button.send:hover { background: var(--azure); border-color: var(--azure); color: #fff; }
  details { display: inline; }
  summary { display: inline; cursor: pointer; color: var(--muted); font-size: 13px; }
  details pre { max-height: 280px; overflow: auto; margin: 8px 0 0; padding: 12px 14px; border-radius: 10px; background: var(--soft); white-space: pre-wrap; font: 12.5px/1.5 var(--mono); }
  .more { margin: 16px 0 0; color: var(--muted); font-size: 14px; }
  .more a, .more button { margin-right: 10px; }
  .result { max-height: 460px; overflow: auto; margin: 18px 0 0; padding: 18px 20px; border-radius: var(--radius); background: #171717; color: #f1f2f4; white-space: pre-wrap; font: 13px/1.55 var(--mono); }
  footer { max-width: 1200px; margin: 0 auto; padding: 0 22px 48px; color: var(--muted); font-size: 14px; }

  @media (max-width: 760px) {
    .bar { padding-left: 16px; } .brand small { display: none; }
    .bar nav { gap: 14px; } .bar nav a:not(.pill) { display: none; }
    .hero-inner { padding-top: 44px; } .lead { font-size: 18px; }
    .card { padding: 20px; }
    .ep h2 { font-size: 20px; }
    thead { display: none; } table, tbody, tr, td { display: block; }
    tr { padding: 12px 0; border-bottom: 1px solid var(--line); } tbody tr:last-child { border-bottom: 0; }
    td { padding: 3px 0; border: 0; } tbody tr:hover td, tr.active td { background: transparent; }
    td.v-send { padding-top: 8px; text-align: left; }
  }
</style>
</head>
<body>
<header class="hero">
  <div class="bar">
    <span class="brand">CONUTI<small>MaKo Backend-Mocks</small></span>
    <nav><a href="#methoden">Methoden</a><a href="{$base}/_mocks">JSON</a><a href="{$e($repo)}">GitHub</a><a class="pill" href="{$base}/_status">Status →</a></nav>
  </div>
  <div class="hero-inner">
    <p class="eyebrow">Mock-Server · Schnittstellen lesen und aktualisieren</p>
    <h1>MaKo Backend-Mocks</h1>
    <p class="lead">Alle Methoden mit ihren Varianten. „Senden“ schickt jede Variante direkt ab und zeigt die Antwort in der Vorschau, GET-Aufrufe öffnen sich zusätzlich als Link.</p>
    <div class="stats"><span class="stat"><b>{$methods}</b>Methoden</span><span class="stat"><b>{$variantCount}</b>Varianten</span><span class="stat">Stand <b>{$version}</b></span></div>
    <input type="search" id="filter" class="search" placeholder="Methode oder Variante suchen …" autocomplete="off">
  </div>
</header>
<main>
  <div class="facts">
    <div class="card"><h3>Reihenfolge</h3><p>Eigene Regeln → globale Testdaten → Erwartungen → Standard. Sammel-Erwartungen wie „Default“ kommen zuletzt. Parameter ohne Regel werden ignoriert.</p></div>
    <div class="card"><h3>Testdaten</h3><p>{$this->joinOrDash($global)}, für alle Methoden mit passender Antwortdatei.</p></div>
    <div class="card"><h3>Antwort erzwingen</h3><p>Header <code>X-Mock-Response</code> oder <code>?__response=&lt;datei&gt;</code>. Welche Variante gegriffen hat, steht in <code>X-Mock-Reason</code>.</p></div>
  </div>
  <nav class="card index" id="methoden">{$nav}</nav>
  {$sections}
</main>
<footer>Stand <code>{$version}</code> aus <a href="{$e($repo)}">{$e($this->config['repo'])}</a> · <a href="{$base}/_mocks">/_mocks</a> · <a href="{$base}/_status">/_status</a></footer>
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
    const section = button.closest('section');
    const out = section.querySelector('.result');
    section.querySelectorAll('tr.active').forEach((row) => row.classList.remove('active'));
    const row = button.closest('tr');
    if (row) row.classList.add('active');
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

    /** Knopf „Senden“: schickt den Aufruf per fetch ab, die Antwort erscheint in der Vorschau des Abschnitts. */
    private function sendButton(array $call): string
    {
        $e = static fn (mixed $s): string => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        $headers = $call['headers'] + ($call['body'] !== null ? ['content-type' => 'application/json'] : []);
        return '<button type="button" class="send" data-method="' . $e($call['method']) . '" data-url="' . $e($call['url']) . '"'
            . ' data-headers="' . $e(json_encode((object) $headers, self::JSON)) . '"'
            . ($call['body'] !== null ? ' data-body="' . $e($call['body']) . '"' : '') . '>Senden</button>';
    }

    /** Aufruf als Text: GET ohne Header als Link, sonst Methode und Pfad mit Body und Headern. */
    private function callHtml(array $call, ?string $label = null): string
    {
        $e = static fn (mixed $s): string => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        $text = $label ?? rawurldecode(substr($call['url'], strlen($this->base)));
        if ($call['method'] === 'GET' && !$call['headers']) {
            return '<a href="' . $e($call['url']) . '" target="_blank" rel="noopener">' . $e($text) . '</a>';
        }
        if ($label !== null) {
            return $e($label);
        }
        $html = '<code>' . $e($call['method'] . ' ' . $text) . '</code>';
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
