# Manage GDN — connexion API Zeendoc reelle

Base API (Swagger Zeendoc REST API Guide 2.1) :
https://armoires.zeendoc.com/gdn_solutions/ws/rest

Authentification : POST /login { login, password } -> cookie de session (~38880 s).
Le cookie est gere cote serveur (proxy /api/zeendoc), le navigateur ne voit jamais les identifiants.

## Variables d'environnement a definir dans Vercel (Settings > Environment Variables > Redeploy)

| Variable | Contenu | Valeur pour l armoire de test |
|---|---|---|
| ZEENDOC_API_BASE | Base de l API REST | https://armoires.zeendoc.com/gdn_solutions/ws/rest |
| ZEENDOC_LOGIN | Compte d application Zeendoc (login) | a renseigner |
| ZEENDOC_PASSWORD | Mot de passe du compte d application | a renseigner |

## Endpoints du proxy

- GET /api/zeendoc?action=test : POST /login et confirme l ouverture de session
- GET /api/zeendoc?action=context : GET /context (classeurs, index, droits, configsets)

## Remarques

- Le cookie expire : le proxy se reconnecte automatiquement si besoin.
- Liste blanche respectee cote proxy : seuls /login et /context sont exposes pour l instant.
- Vercel heberge hors UE : armoire de test gdn_solutions uniquement, jamais de donnees client reelles.
