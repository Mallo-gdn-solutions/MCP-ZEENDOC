# Plateforme Zeendoc GDN — étape 1 : noyau générique

Noyau PHP réutilisable qui parle à l'API Zeendoc v4. Aucun réglage client dans le code :
tout passe par la configuration de l'armoire (`module-config.json`, voir `module-config.example.json`).

## Arborescence

- `noyau/Noyau.php` — point d'entrée unique : `Noyau::depuisConfig($cheminConfig)` branche config, stockage, journal, registre et client.
- `noyau/ZeendocClient.php` — client API v4, liste blanche des routes, auth cookie ou OAuth 2.0, écriture ouverte usage par usage.
- `noyau/ConfigArmoire.php` — lecture/validation de la configuration de l'armoire.
- `noyau/StockageLocal.php` — fichiers locaux sensibles (`oauth-jetons.json`, `dernier-releve.csv`, `validations.json`), exclus du git.
- `noyau/JournalEcritures.php` — journal JSONL de chaque écriture Zeendoc (`ecritures-journal.jsonl`).
- `noyau/ContratModule.php` — interface `ModuleMetier` + `RegistreUsages` (lecture seule par défaut).
- `modules/` — modules métier (le rapprochement GDN y sera branché, inchangé).
- `tests/run-tests.php` — tests du noyau avec transport simulé.

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

Les secrets ne sont jamais en dur dans le code ni dans git (voir `.gitignore`).
Toute écriture dans Zeendoc exige un usage déclaré avec la route autorisée dans `module-config.json`,
et est journalisée avec les valeurs avant/après.
