
# Cahier des charges — Plateforme Zeendoc GDN

Nov 6, 2026 · @Mallory

## Contexte et objectifs

GDN transforme son connecteur Zeendoc maison en plateforme réutilisable chez ses clients : un « Manage GDN » où le technicien paramètre les usages, et un espace client où le client consulte les résultats.

**Ce qui existe déjà** (connecteur local v0.3.0, armoire gdn\\\_solutions) :

- client API Zeendoc v4 avec liste blanche des routes, authentification par cookie ou OAuth 2.0 ;

- configuration par armoire dans `module-config.json` (classeurs, index, banque, exclusions) ;

- module de rapprochement bancaire fournisseurs, clients et notes de frais, avec interface web locale ;

- écriture des règlements dans Zeendoc, via un compte séparé ;

- connecteur MCP utilisable depuis Claude Desktop.

**Objectifs**

1. Diviser par deux le temps de paramétrage d'un nouveau client, sans erreur de saisie.

2. Proposer des usages récurrents facturables en abonnement : rapprochement, pièces manquantes, anti-fraude RIB, relance des validations.

3. Rendre GDN autonome sur les passerelles comptables, achetées aujourd'hui plus de 1 500 € l'unité, à raison d'une dizaine par an.

4. Garder une maintenance tenable par une équipe qui ne code pas, avec Claude Code.

## Périmètre et principes

La plateforme a deux étages : le technicien paramètre, le client consulte et valide. Le client ne paramètre jamais rien.

| Étage | Utilisateurs | Rôle | Mode au départ |

| --- | --- | --- | --- |

| Manage GDN | Techniciens GDN | Connecter une armoire, activer et régler les usages, gérer les droits | Local, sur le poste du technicien |

| Espace client | Clients (dirigeant, comptable, valideurs) | Consulter les résultats, valider les propositions, recevoir les alertes | Hébergé (étape 3) |

**Principes**

1. **Les calculs sont du code, pas de l'IA.** Montants, IBAN, rapprochements : modules PHP déterministes, exécutés à heure fixe. L'IA sert à proposer (mapping des index, lecture de documents) et à dialoguer.

2. **Lecture seule par défaut.** Toute écriture dans Zeendoc est activée usage par usage, route par route, et passe par une validation humaine.

3. **Le MCP est un canal parmi d'autres.** Le même moteur alimente l'espace client, les alertes et le connecteur MCP.

4. **Une armoire = une configuration.** Aucun réglage client dans le code ; tout est dans la configuration de l'armoire.

5. **Aucune donnée client chez un fournisseur d'IA sans accord écrit.** Le mode par défaut fonctionne sans LLM.

**Hors périmètre** : la création des classeurs, du plan d'indexation et des workflows (non disponible dans l'API Zeendoc) reste faite à la main dans le Manage Zeendoc.

## Architecture

Un noyau générique parle à Zeendoc, des modules métier calculent les résultats, et trois canaux les restituent. Le technicien ne touche qu'à la configuration.

&amp;#91;embedded content: architecture · noyau, modules, configuration, trois canaux\\\]

L'IA n'intervient qu'à deux endroits : dans le Manage, pour proposer le mapping des index, et dans le connecteur MCP, pour dialoguer. Les modules ne dépendent d'aucun LLM.

## Manage GDN (techniciens)

Le Manage est un assistant pas à pas : un technicien met en service une armoire client sans ouvrir un fichier de configuration.

**Parcours de mise en service**

1. **Armoire** : nom du client, Url\\\_Client, N\\\_Client, plateforme (France, Suisse, Santé).

2. **Connexion** : client\\\_id, client\\\_secret et compte d'application (OAuth). Bouton « Tester la connexion ». Les secrets ne sont jamais réaffichés.

3. **Détection** : lecture automatique des classeurs, des index et des listes de valeurs via `/context`.

4. **Mapping** : pour chaque classeur, l'IA propose le rôle de chaque index (tiers, date, référence, TTC, échéance, statut, type…). Le technicien valide ou corrige. Les valeurs de liste sensibles sont confirmées explicitement (ex. quel identifiant veut dire « Payé »).

5. **Usages** : activation des modules du catalogue, avec leurs réglages (banque, alias fournisseurs, exclusions, tolérance de jours, fréquence de calcul, destinataires des alertes).

6. **Droits** : lecture seule par défaut ; chaque écriture est cochée usage par usage, avec le compte qui écrit.

7. **Test** : exécution à blanc de chaque usage sur un mois passé, résultat affiché avant mise en service.

8. **Mise en service** : génération de la configuration, du kit d'installation local ou publication sur l'hébergement.

**Écrans permanents**

- Liste des armoires suivies, avec l'état de chaque usage (dernière exécution, erreurs).

- Journal : qui a changé quoi dans la configuration, et chaque écriture faite dans Zeendoc.

- Historique des versions de configuration, avec retour arrière.

## Espace client

L'espace client montre les résultats calculés par les usages activés, et laisse le client valider ce qui doit l'être. La page locale `rapprochement-web.php` en est le prototype.

**Écrans**

- **Accueil** : un bloc par usage actif, avec le chiffre qui compte (débits sans facture, factures échues, alertes ouvertes).

- **Rapprochement** : propositions de pointage par mois, à valider ou rejeter ; les virements groupés sont toujours validés par un humain.

- **Pièces manquantes** : liste des débits sans justificatif, avec brouillon de demande au fournisseur et export pour l'expert-comptable.

- **Échéancier** : factures fournisseurs et clients échues ou à venir sur 30, 60 et 90 jours.

- **Alertes** : changement d'IBAN fournisseur, doublon probable, validation bloquée.

- **Lien direct** vers chaque document dans Zeendoc.

**Profils**

| Profil | Voit | Peut |

| --- | --- | --- |

| Dirigeant | Tout | Valider, exporter |

| Comptable (interne ou cabinet) | Rapprochement, pièces manquantes, échéancier | Valider, exporter |

| Valideur | Ses validations en attente | Consulter |

**Notifications** : résumé par mail à fréquence choisie, alerte immédiate pour un changement d'IBAN.

## Catalogue des usages

Le rapprochement existe déjà ; les autres usages réutilisent le même noyau. Ceux qui lisent le texte des PDF attendent les droits de consultation et l'extraction du texte.

| Usage | Ce qu'il fait | Données Zeendoc | Écrit dans Zeendoc | Prérequis | État |

| --- | --- | --- | --- | --- | --- |

| Rapprochement bancaire | Pointe factures fournisseurs, clients et notes de frais avec le relevé | Index des factures + relevé CSV | Oui, en option (règlement) | Aucun | Existant |

| Passerelle comptable | Exporte les factures validées et leurs imputations vers le logiciel comptable (Sage, EBP, Pennylane, ADFI), puis les marque exportées ; synchronise le plan comptable du logiciel vers Zeendoc | Imputations, compte, code journal, statut d'export ; listes de valeurs | Oui (statut d'export, n° de pièce, listes de comptes) | Format d'import de chaque logiciel comptable | En cours (passerelle ADFI) |

| Pièces manquantes | Liste les débits sans facture, prépare la demande au fournisseur et l'export comptable | Résultat du rapprochement | Non | Rapprochement | À faire |

| Échéancier | Factures échues et à venir à 30, 60, 90 jours | Index échéance + statut | Non | Aucun | À faire |

| Bilan de santé de l'armoire | Index vides, documents non indexés, doublons, validations bloquées | Index, `N_Status`, `Duplicate_Documents` | Non | Aucun | À faire |

| Relance des validations | Message au valideur d'une facture bloquée depuis X jours | Index de validation | Non | Aucun | À faire |

| Anti-fraude RIB | Alerte quand l'IBAN d'une facture diffère de l'historique du fournisseur | Texte du PDF | Non | Extraction du texte | À faire |

| Veille contrats | Repère tacite reconduction et préavis, remplit la date de rappel | Texte du PDF | Oui (date de rappel) | Extraction du texte | À faire |

| Alimentation des listes | Importe fournisseurs, codes journal et autres listes de valeurs à la mise en service | Listes de valeurs | Oui | Aucun | À faire |

| Recherche en langage naturel | Questions libres sur l'armoire, via le connecteur MCP | Tout ce que l'usage autorise | Non | LLM accepté par le client | Existant (Claude) |

L'ordre de développement proposé est dans la feuille de route.

## API Zeendoc

La plateforme s'appuie sur l'API REST v4 (49 routes, documentation technique du 02/11/2026). Elle n'en autorise qu'une partie, par une liste blanche du noyau.

| Route | Méthode | Utilisée pour | Accès |

| --- | --- | --- | --- |

| `/context` | GET | Classeurs, index, droits du compte | Lecture |

| `/binders/{collId}/documents/search` et `/count` | POST | Recherches, statistiques | Lecture |

| `/binders/{collId}/documents` | GET | Fiche document, liens `PDF_Url` / `Original_Url` | Lecture |

| `/binders/{collId}/documents/{resId}/linked-docs` et `/folders` | GET | Documents liés, dossiers | Lecture |

| `/binders/{collId}/saved-queries` | GET | Recherches enregistrées | Lecture |

| `/binders/{collId}/fields/{champ}/autocomplete-search` et `/item-list` | GET | Valeurs des index et des listes | Lecture |

| `/history-document/search` | POST | Synchronisation : ajouts, modifications, suppressions | Lecture |

| `/history-system/search` | POST | Audit : connexions, changements de droits | Lecture |

| `/binders/{collId}/documents/{resId}/indexes` | PATCH | Règlement, date de rappel, statut d'export comptable | Écriture, par usage |

| `/binders/{collId}/fields/{champ}/item-list` | POST, PATCH | Listes de valeurs, dont le plan comptable (ajout et mise à jour, jamais de suppression) | Écriture, par usage |

**Authentification** : OAuth 2.0 (client\\\_id et client\\\_secret fournis par Zeendoc, compte d'application, `N_Client`). Jeton d'accès d'une heure, jeton de renouvellement de trois mois. Le cookie de session reste possible ; il expire après 30 minutes sans appel sur la plateforme France.

**Limites connues**

- Pas de route pour récupérer le texte OCR : il faut télécharger le PDF et en extraire le texte.

- Pas de création de classeur, de plan d'indexation, de workflow ni de recherche enregistrée.

- Les routes de suppression, de gestion des utilisateurs, des mots de passe et des rôles existent : elles ne sont jamais ouvertes par défaut.

## Sécurité, droits et RGPD

Chaque armoire a sa propre identité technique, ses droits minimaux et son journal : une fuite chez un client ne touche jamais les autres.

**Identités et secrets**

- Un compte d'application Zeendoc dédié par armoire, jamais le compte d'une personne.

- Droits Zeendoc minimaux : recherche, consultation, accès à l'original ; écriture seulement si un usage l'exige.

- Secrets (client\\\_secret, mot de passe du compte d'application, jetons) chiffrés, jamais affichés après saisie, jamais dans un kit d'installation ni dans Git.

- Révocation d'une armoire en un clic : jetons supprimés, usages arrêtés.

**Droits dans la plateforme**

- Techniciens GDN : accès au Manage, par armoire attribuée.

- Clients : accès à leur seul espace, selon leur profil.

- Double authentification pour les techniciens et pour les comptes clients qui valident.

**Garde-fous techniques**

- Liste blanche des routes dans le noyau, écriture ouverte usage par usage.

- Tout contenu de document est traité comme une donnée, jamais comme une instruction (protection contre l'injection de consignes dans un PDF).

- Journal de chaque écriture dans Zeendoc : qui, quand, quel document, quelle valeur avant et après.

**RGPD**

- GDN est sous-traitant de ses clients : contrat de sous-traitance, registre de traitement, durées de conservation des résultats.

- L'historique Zeendoc révèle qui a consulté quoi : données personnelles des salariés, à n'exploiter qu'avec une finalité déclarée.

- Tout recours à un LLM est déclaré au client, avec le fournisseur et le lieu de traitement ; le mode par défaut n'en utilise pas.

## Hébergement et déploiement

On démarre en local, puis on héberge l'espace client chez un hébergeur français. Le choix de l'hébergeur reste ouvert.

| Critère | Local (poste du client ou du technicien) | Hébergé par GDN |

| --- | --- | --- |

| Accès client | Sur le poste où c'est installé | Depuis n'importe où, navigateur |

| Calculs à heure fixe | Seulement si le PC est allumé | Oui |

| Responsabilité de GDN | Faible | Sous-traitant RGPD, sécurité, disponibilité, sauvegardes |

| Coût pour GDN | Installation par client | Serveur, supervision, astreinte à intégrer au prix |

| Mises à jour | Poste par poste | Une seule fois pour tous |

**Contraintes d'hébergement**

- Hébergeur français ou européen (OVH, Scaleway ou équivalent), à arbitrer selon le niveau d'exigence des clients.

- Pas d'exposition du NAS Synology de GDN sur Internet.

- Stack PHP, cohérente avec le code existant, sans Python ni Node.js côté serveur.

- Sauvegardes chiffrées de la configuration et des résultats ; aucune copie des documents eux-mêmes.

**Souveraineté et IA**

- Mode standard : aucun LLM dans les calculs.

- Mode conversation : LLM au choix du client, déclaré au contrat.

- Premier essai de Mistral Large sur le connecteur (06/11/2026) : outil de rapprochement non appelé, filtre de statut inversé, classeurs inventés. À refaire avec un banc corrigé avant toute offre « souveraine » conversationnelle.

## Feuille de route

On commence par le noyau, sans rien changer chez GDN, et on n'héberge qu'après avoir validé les usages chez un client pilote. Aucune date n'est fixée à ce stade.

&amp;#91;embedded content: feuille de route · 5 étapes, 4 validations\\\]

Chaque étape est construite avec Claude Code, testée sur l'armoire GDN (bac à sable coll\\\_27) avant tout client, et versionnée dans le CHANGELOG.

## Questions ouvertes

**À valider avec Zeendoc**

- \[ \] Identifiants OAuth (client\\\_id, client\\\_secret, N\\\_Client) pour l'armoire gdn\\\_solutions — demandés le 06/11/2026.

- \[ \] Prérequis du compte d'application : type de compte, droits minimaux, compte de service.

- \[ \] Une route pour récupérer le texte OCR est-elle prévue ? La méthode de la visionneuse est-elle utilisable de façon pérenne ?

- \[ \] Limites de `history-document/search` : profondeur d'historique, volumes, fréquence d'appel.

- \[ \] Règles pour un intégrateur qui lit en volume le contenu des armoires de ses clients (quotas, conditions contractuelles).

- \[ \] Zeendoc prévoit-il un connecteur MCP ou une recherche en langage naturel ? Possibilité de partenariat.

**À décider chez GDN**

- \[ \] Hébergeur de l'espace client et niveau d'exigence visé.

- \[ \] Modèle de prix : par armoire, par usage activé, inclus dans la maintenance Grenke ou en abonnement séparé.

- \[ \] Client pilote pour l'étape 2.

- \[ \] Qui, chez GDN, est responsable de la plateforme (mises à jour, incidents, sécurité).

- \[ \] Nom commercial de l'offre.
