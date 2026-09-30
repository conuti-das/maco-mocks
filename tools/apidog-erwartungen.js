// Exportiert die Mock-Erwartungen (Apidog → Endpunkt → Mock) für den Import.
//
// Der OpenAPI-Export von Apidog enthält die Erwartungen nicht. Apidog hält sie aber im Browser-Cache.
//   1. app.apidog.com öffnen, Projekt laden (Branch main) und einmal neu laden, damit der Cache aktuell ist
//   2. Entwicklertools → Konsole, diesen Code einfügen und ausführen
//   3. Heruntergeladen wird apidog-erwartungen-<projekt>.json, dann:
//      php tools/import-apidog.php <openapi.json> --erwartungen <datei> [--stand <commit>]
(async () => {
  const projectId = Number((location.pathname.match(/project\/(\d+)/) || [])[1]);
  if (!projectId) {
    throw new Error('Bitte zuerst ein Projekt in app.apidog.com öffnen');
  }
  const db = await new Promise((resolve, reject) => {
    const request = indexedDB.open('AppDatabase');
    request.onsuccess = () => resolve(request.result);
    request.onerror = () => reject(request.error);
  });
  const all = (store) => new Promise((resolve, reject) => {
    const request = db.transaction(store, 'readonly').objectStore(store).getAll();
    request.onsuccess = () => resolve(request.result);
    request.onerror = () => reject(request.error);
  });
  const mocks = await all('ApiMock');
  const details = await all('ApiDetail');
  db.close();

  const apis = new Map(details.filter((d) => d.projectId === projectId).map((d) => [d.id, d]));
  const erwartungen = mocks
    // Branch 0 = main
    .filter((m) => m.projectId === projectId && m.projectBranchId === 0 && apis.has(m.apiDetailId))
    .map((m) => {
      const api = apis.get(m.apiDetailId);
      return {
        id: m.id,
        name: m.name,
        ordering: m.ordering,
        api: { id: api.id, name: api.name, method: String(api.method).toUpperCase(), path: api.path, operationId: api.operationId || null },
        conditions: m.conditions,
        response: m.response,
        updatedAt: m.updatedAt,
      };
    })
    .sort((a, b) => (a.api.path + ' ' + a.api.method).localeCompare(b.api.path + ' ' + b.api.method) || a.ordering - b.ordering || a.id - b.id);

  const data = { quelle: 'apidog', projekt: projectId, exportiert: new Date().toISOString(), anzahl: erwartungen.length, erwartungen };
  const blob = new Blob([JSON.stringify(data, null, 1)], { type: 'application/json' });
  const link = Object.assign(document.createElement('a'), { href: URL.createObjectURL(blob), download: `apidog-erwartungen-${projectId}.json` });
  document.body.appendChild(link);
  link.click();
  link.remove();
  console.log(`${erwartungen.length} Mock-Erwartungen exportiert`);
})();
