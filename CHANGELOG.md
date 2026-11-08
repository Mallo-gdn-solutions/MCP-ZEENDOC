# CHANGELOG

## v0.4.0-dev — étape 1 « Noyau générique » (en cours)

- Noyau générique procédural `noyau/` : `noyau.php` (liste blanche des routes par profil),
  `armoire.php` (lecture/contrôle de module-config.json), `journal.php` (ecritures-journal.jsonl),
  `http.php` (noyau_http), `outils.php` (rb_norm, rb_date, rb_cents, rb_eur, rb_ajout, rb_jours, rb_compact, rb_utf8).
- Premier jet en classes conservé en `noyau/*.avant-classes.php`.
- Code v0.3.0 complet versionné tel quel, non modifié.
- `tests/run-tests.php` couvre outils, armoire, liste blanche et journal.
- Critère de fin : rapprochement d'octobre 2026 sur l'armoire GDN identique avant/après.

## v0.3.0 — connecteur local de référence

- Connecteur Zeendoc local (armoire gdn_solutions), rapprochement bancaire, connecteur MCP.
