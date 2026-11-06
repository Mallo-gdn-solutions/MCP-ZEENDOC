# Plateforme Zeendoc GDN — étape 1 : noyau générique

Le noyau (`noyau/`) est la partie générique, sans aucun réglage client en dur :
lecture/contrôle de l'armoire, journal des écritures, HTTP, liste blanche des routes, outils communs.
Tout ce qui est propre à l'armoire gdn_solutions vit dans `module-config.json`.

## Arborescence

- `noyau/noyau.php` — point d'entrée : charge le noyau et expose la liste blanche (`noyau_route_autorisee`).
- `noyau/armoire.php` — `armoire_lire`, `armoire_a_remplir`, `armoire_verifier_classeur`, `armoire_url_client`, `armoire_host`, `armoire_classeur`.
- `noyau/journal.php` — journal JSONL des écritures Zeendoc (`ecritures-journal.jsonl`, `NOYAU_JOURNAL`).
- `noyau/http.php` — `noyau_http` : unique point de passage des appels sortants (curl).
- `noyau/outils.php` — `rb_utf8`, `rb_norm`, `rb_date`, `rb_ajout`, `rb_jours`, `rb_cents`, `rb_eur`, `rb_compact`.
- `noyau/*.avant-classes.php` — premier jet du noyau (classes), conservé pour référence.

## Connecteur v0.3.0 (inchangé, fonctionne exactement comme avant)

`zeendoc-mcp.php`, `rapprochement-web.php`, `config.php`, `rapprochement.php`, `ecriture.php`,
`justificatifs.php`, `lots.php`, `remboursements.php`, `sources-paiements.php`, `module-config.json`.

Identifiants jamais en dur : variables d'environnement `ZEENDOC_HOST`, `ZEENDOC_URL_CLIENT`,
`ZEENDOC_LOGIN`, `ZEENDOC_PASSWORD`, OAuth `ZEENDOC_CLIENT_ID` / `ZEENDOC_CLIENT_SECRET` / `ZEENDOC_N_CLIENT`,
compte d'écriture `ZEENDOC_ECRITURE_LOGIN` / `ZEENDOC_ECRITURE_PASSWORD`.

## Tests

```sh
php tests/run-tests.php
```

## Sécurité

Secrets exclus du git (voir `.gitignore`). Toute écriture Zeendoc passe par le profil « ecriture »
de la liste blanche, limitée aux classeurs du module, et est journalisée (avant/après).
