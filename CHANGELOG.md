# CHANGELOG

## v0.4.0-dev — étape 1 « Noyau générique » (en cours)

- Ajout du noyau générique `noyau/` realigné sur le connecteur v0.3.0 : base `{host}/{urlClient}/api/v4`,
  liste blanche des routes (lecture + écritures par usage), auth cookie `/login` ou OAuth 2.0
  `_Login_OAuth` (PKCE, jetons `oauth-jetons.json`), config `module-config.json` au schéma réel.
- Ajout de `tests/run-tests.php` (transport simulé) et de `module-config.example.json`.
- Ajout des fichiers de référence v0.3.0 : `zeendoc-mcp.php`, `rapprochement-web.php`, `module-config.json`.
- Aucun fichier supprimé ni modifié côté v0.3.0.
- Critère de fin : rapprochement d'octobre 2026 sur l'armoire GDN identique avant/après.

## v0.3.0 — connecteur local de référence

- Connecteur Zeendoc local (armoire gdn_solutions), rapprochement bancaire, connecteur MCP.
