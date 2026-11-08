# Manage GDN — connexion API Zeendoc reelle

## Variables d'environnement a definir dans Vercel

(Settings > Environment Variables, puis Redeploy)

| Variable | Contenu | Exemple |
|---|---|---|
| ZEENDOC_TOKEN_URL | URL d'obtention du jeton OAuth (doc technique Zeendoc) | a confirmer dans la doc du 02/11/2026 |
| ZEENDOC_API_BASE | Base de l'API v4 | a confirmer dans la doc |
| ZEENDOC_CLIENT_ID | client_id fourni par Zeendoc | ... |
| ZEENDOC_CLIENT_SECRET | client_secret fourni par Zeendoc | ... |
| ZEENDOC_N_CLIENT | N_Client de l'armoire gdn_solutions | 27 |

## Endpoints du proxy

- GET /api/zeendoc?action=test : obtient un jeton OAuth et confirme la connexion
- GET /api/zeendoc?action=context : appelle /context (classeurs, index, droits)

## Remarques

- Les secrets ne sortent jamais du serveur : le navigateur ne parle qu'au proxy.
- Les parametres exacts de la demande de jeton (nom des champs, scope) doivent etre
  confirmes avec la documentation technique Zeendoc v4 du 02/11/2026.
- Vercel heberge hors UE : a n'utiliser que sur l'armoire de test gdn_solutions,
  jamais sur des donnees client reelles.
