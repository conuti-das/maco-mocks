<?php

// Standardwerte. Eigene Werte gehören in config.local.php neben dieser Datei, z. B.:
//
//   <?php return ['updateSecret' => 'langes-zufälliges-geheimnis'];
//
// config.local.php und data/ werden beim Deploy nicht überschrieben.

return [
    // Quelle der Mocks (öffentliches Repo) und Branch, dessen Stand ausgeliefert wird
    'repo' => 'conuti-das/maco-apidog-mocks',
    'branch' => 'main',

    // Lokale Kopie des Repos (Releases, Status). Muss für PHP beschreibbar sein.
    'dataDir' => __DIR__ . '/data',

    // Entwicklungsmodus: direkt aus einem mocks/-Ordner lesen statt aus der Repo-Kopie,
    // z. B. MOCKS_DIR=../mocks php -S localhost:8080 index.php
    'mocksDir' => getenv('MOCKS_DIR') ?: null,

    // Falls der Proxy nicht im Wurzelverzeichnis der Domain liegt, z. B. '/mocks'
    'basePath' => '',

    // Optional: Secret des GitHub-Webhooks. Gesetzt = Signatur (X-Hub-Signature-256) oder
    // "Authorization: Bearer <secret>" nötig. Leer = Updates ohne Nachweis, aber gedrosselt,
    // und es wird immer der echte Stand von GitHub geladen.
    'updateSecret' => '',

    // Optional: Mocks nur mit "Authorization: Bearer <token>" ausliefern
    'token' => '',

    // Optional: GitHub-Token nur gegen das API-Limit (60 Anfragen/Stunde je IP ohne Token)
    'githubToken' => '',

    'minUpdateInterval' => 10, // Sekunden zwischen zwei Update-Versuchen
    'keepReleases' => 3,       // so viele Stände bleiben für ein schnelles Zurück liegen
    'httpTimeout' => 20,

    'archiveUrl' => 'https://codeload.github.com/{repo}/zip/{ref}',
    'shaUrl' => 'https://api.github.com/repos/{repo}/commits/{branch}',
];
