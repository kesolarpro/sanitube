# Audit du parcours produit — août 2026

**Baseline : `main` à `6c3b97b`.** Vérifiée, pas reprise d'un rapport : 1676 tests
passés / 14710 assertions, PHPStan level 6 sans baseline `[OK]`, Pint `passed`,
arbre de travail propre, aucun PR ouvert.

Cet audit ne relit pas `production-readiness.md`. Il mesure l'application :
routes réelles, appels réels du frontend, et croisement des deux.

---

## Méthode

1. `php artisan route:list` — 42 écrans (GET) et 46 écritures (POST/PATCH/DELETE).
2. Extraction de **toutes** les URL réellement appelées depuis `resources/js`
   (`router.*`, `useForm(...).post|patch|delete`), y compris les URL interpolées.
3. Croisement.

Une première heuristique par nom de route a produit **16 faux positifs et faux
négatifs** — le frontend interpole ses URL, donc chercher le nom d'une route ne
prouve rien. Le chiffre ci-dessous vient de l'extraction directe des appels.

**34 des 46 écritures sont réellement câblées.** Les manquantes sont ci-dessous.

---

## Le constat qui commande la suite

> **Il n'existe aucun `<input type="file">` dans l'intégralité du frontend.**

Vérifié par recherche sur tout `resources/js`. Conséquence directe : **aucun
fichier — audio ou image — ne peut entrer dans SaniTube depuis un navigateur.**

Ce n'est pas un manque d'écran cosmétique, c'est l'étape 3 du parcours cible, et
tout le reste en dépend :

- L'écran d'ingestion importe depuis un **préfixe de stockage** ou une **liste de
  références**. Il suppose donc que les fichiers sont *déjà* dans le stockage,
  déposés par FTP, rsync ou une console S3 — c'est-à-dire par une intervention
  hors application.
- La pochette d'une release se **choisit parmi les assets `ARTWORK` existants**
  (`POST /releases/{r}/cover` attend un uuid d'asset). Or aucun asset artwork ne
  peut être créé depuis l'interface. Le sélecteur est donc vide sur une
  installation neuve, définitivement.

Le backend de l'upload direct existe pourtant et il est testé : `BeginDirectUpload`,
`DirectUploadController`, `POST assets/uploads`, `POST assets/uploads/{a}/complete`.
**Rien ne les appelle.** C'est le motif « built but not wired » que ce projet a
déjà rencontré plusieurs fois, ici sur le premier pas du parcours.

---

## Cartographie du parcours cible

| # | Étape | État | Ce qui existe / ce qui manque |
|---|---|---|---|
| 1 | Connexion | `WORKING_E2E` | Vues Blade, sessions, rôles. |
| 2 | Dashboard | `WORKING_E2E` | `DashboardQuery` agrège catalogue, ingestion, media, génération, distribution, jobs — **toutes issues de `COUNT` réels**, aucune métrique fabriquée. |
| 3 | **Importer / uploader un audio** | **`BROKEN`** | Backend complet et testé ; **aucun écran**. L'import par préfixe suppose des fichiers déjà déposés hors application. |
| 4 | Stockage | `WORKING_E2E` | Abstraction provider, staging, checksum, vérification. Atteint uniquement via l'ingestion. |
| 5 | Analyse | `WORKING_E2E` | Listener sur candidat, `sanitube:media:analyze` pour le rattrapage. Optionnelle si FFmpeg absent. |
| 6 | Déduplication | `WORKING_E2E` | Écran + `confirm`/`reject` + `trash`/`restore` câblés. |
| 7 | Transcription | **`BACKEND_ONLY`** | `POST assets/{a}/transcription` existe, testé, **aucun bouton**. Rattrapage console uniquement. |
| 8 | Enrichissement IA | **`PARTIALLY_WIRED`** | `accept`, `reject`, `regenerate` câblés. **Demander une première suggestion ne l'est pas** — `POST assets/{a}/enrichment` n'a aucun appelant. |
| 9 | Création du morceau | `WORKING_E2E` | Promotion d'un candidat, avec révision et refus. |
| 10 | Artiste / crédits | `WORKING_E2E` | `POST catalog/tracks/{t}/credits`. |
| 11 | Métadonnées éditoriales | `PARTIALLY_WIRED` | Écrites en acceptant une suggestion. Pas d'édition directe depuis l'écran du morceau. |
| 12 | **Artwork** | **`BACKEND_ONLY`** pour l'entrée | Mesure, validation, refus expliqué : faits (ART-001/002). **Introduire une image : impossible depuis l'UI.** |
| 13 | Validation | `WORKING_E2E` | `POST releases/{r}/ready`, erreurs affichées par code. |
| 14 | Release | `WORKING_E2E` | Création, tracks, ordre, artistes, cover, réouverture. |
| 15 | Package de distribution | **`BACKEND_ONLY`** | `PackageRelease` (REL-003) n'a **aucun appelant de production**, ce qui était assumé et documenté. |
| 16 | État prêt / bloqué | `PARTIALLY_WIRED` | Verdict distributeur et readiness existent ; pas de vue d'ensemble « qu'est-ce qui m'empêche de distribuer ». |
| — | Distribution réelle | `EXTERNALLY_BLOCKED` | `ENVIRONMENT_EGRESS` + `DIST-002`. Hors de portée de cette session. |

---

## Écritures sans appelant frontend

Vérifiées une par une, pas déduites :

| Route | Statut |
|---|---|
| `POST assets/uploads` | orpheline — **chemin critique** |
| `POST assets/uploads/{a}/complete` | orpheline — **chemin critique** |
| `POST assets/{a}/transcription` | orpheline |
| `POST assets/{a}/enrichment` | orpheline (seul `regenerate` est câblé) |
| `POST login`, `logout`, `forgot-password`, `reset-password`, `install` | **non orphelines** — servies par des vues Blade, hors `resources/js`. |

---

## Chemin critique retenu

Le jalon §31 — « un utilisateur prend un fichier audio sur son ordinateur et le
mène jusqu'à une release validée, sans SQL ni Tinker » — est bloqué au premier
pas et à l'artwork. L'ordre suivant ferme des tranches verticales entières
plutôt que d'ajouter des couches :

1. **UPL-001 — l'upload depuis le navigateur.** Débloque les étapes 3 et 12
   simultanément, donc le jalon lui-même. Le backend est déjà là et testé ; ce
   qui manque est le chemin utilisateur.
2. **UPL-002 — déclencher transcription et enrichissement depuis l'interface.**
   Deux routes orphelines, deux boutons, une tranche fermée.
3. **REL-004 — préparer le package depuis l'écran de release**, et montrer ce
   qui bloque la distribution.

Les trois ne demandent aucun credential externe et aucune décision business.

---

## Ce que cet audit ne prétend pas

- Il **n'a pas** exécuté l'application dans un navigateur. Il mesure les routes,
  les appels du frontend et les tests. Une étape marquée `WORKING_E2E` l'est au
  sens « le chemin utilisateur existe et est couvert par un test qui le
  traverse », pas au sens « quelqu'un l'a cliqué aujourd'hui ».
- Il **ne dit rien** de l'ergonomie des écrans existants, seulement de leur
  existence et de leur raccordement.
- `EXTERNALLY_BLOCKED` a été **re-sondé au début de cette session**, pas repris
  d'un rapport :

  | Hôte | Résultat |
  |---|---|
  | `ddex.net`, `kb.ddex.net` | `CONNECT tunnel failed, response 403` |
  | `developer.toolost.com`, `www.tunecore.com` | `CONNECT tunnel failed, response 403` |
  | `api.openai.com` | `CONNECT tunnel failed, response 403` |
  | `raw.githubusercontent.com` *(témoin)* | **`HTTP 200`** |

  Inchangé. Le témoin est ce qui rend le reste lisible — un sondage où tout
  échoue ne prouve que l'échec du sondage. C'est aussi par là que la
  spécification OpenAI avait pu être lue intégralement pour ART-002.
