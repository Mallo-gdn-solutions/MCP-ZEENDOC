# Plateforme Zeendoc GDN — étape 1 : noyau générique

Noyau PHP réutilisable qui parle à l'API Zeendoc v4. Aucun réglage client dans le code :
tout passe par la configuration de l'armoire (`module-config.json`, voir `module-config.example.json`).

## Arborescence

- `noyau/Noyau.php` — point d'entrée unique : `Noyau::depuisConfig($cheminConfig)` branche config, stockage, journal, registre et client.
- `noyau/ZeendocClient.php` — client API v4 (`{host}/{urlClient}/api/v4`), liste blanche des routes, auth cookie (`/login`) ou OAuth 2.0 (`_Login_OAuth`, jetons dans `oauth-jetons.json`), écriture ouverte usage par usage.
- `noyau/ConfigArmoire.php` — lecture/validation de `module-config.json` (schéma v0.3.0 : zeendoc, classeurs, banque, exclusions, ecriture).
- `noyau/StockageLocal.php` — fichiers locaux sensibles (`oauth-jetons.json`, `dernier-releve.csv`, `validations.json`), exclus du git.
- `noyau/JournalEcritures.php` — journal JSONL de chaque écriture Zeendoc (`ecritures-journal.jsonl`).
- `noyau/ContratModule.php` — interface `ModuleMetier` + `RegistreUsages` (lecture seule par défaut).
- `tests/run-tests.php` — tests du noyau avec transport simulé.

## Fichiers de référence v0.3.0 (inchangés)

- `zeendoc-mcp.php` — connecteur MCP local v0.3.0 (stdio, Claude Desktop).
- `rapprochement-web.php` — interface web locale du rapprochement.
- `module-config.json` — configuration réelle de l'armoire gdn_solutions.

Identifiants jamais en dur : variables d'environnement `ZEENDOC_HOST`, `ZEENDOC_URL_CLIENT`,
`ZEENDOC_LOGIN`, `ZEENDOC_PASSWORD`, et OAuth `ZEENDOC_CLIENT_ID`, `ZEENDOC_CLIENT_SECRET`, `ZEENDOC_N_CLIENT`.

Fichiers attendus à côté du connecteur v0.3.0 (non versionnés ici) : `config.php`, `rapprochement.php`,
`ecriture.php`, `justificatifs.php`, `lots.php`, `remboursements.php`, `sources-paiements.php`.

## Utilisation

```php
require __DIR__ . '/noyau/Noyau.php';
$noyau = Noyau::depuisConfig(__DIR__ . '/module-config.json');
$context = $noyau->zeendoc()->contexte();
```

## Tests

```sh
php tests/run-tests.php
```

## Sécurité

Les secrets ne sont jamais dans le code ni dans git (voir `.gitignore`).
Toute écriture dans Zeendoc exige un usage déclaré avec la route autorisée
(`ecriture.active` dans `module-config.json` ouvre la route règlement au rapprochement),
et est journalisée avec les valeurs avant/après.
