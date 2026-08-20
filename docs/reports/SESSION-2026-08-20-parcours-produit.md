# Rapport de session — mandat « version réellement utilisable »

## A. Point de départ et point d'arrivée

| | |
|---|---|
| SHA de base (`main` au début) | `6c3b97b` |
| SHA final (`main` à la fin) | `71325d2`, plus PR #96 en attente de fusion |
| Branche de développement | `claude/sanitube-autonomous-v1-5pycx0` |

## B. Tickets, PR, CI, fusion

| Ticket | PR | CI | Fusion |
|---|---|---|---|
| Audit du parcours produit | #91 | 10/10 | `cef3582` |
| UPL-001 — un fichier entre depuis un navigateur | #92 | 10/10 | `3bf6b12` |
| UPL-002 — transcription et enrichissement demandables | #93 | 10/10 | `198d60d` |
| REL-004 — préparer le package depuis l'écran | #94 | 10/10 (après correction) | `83ec1b9` |
| CAT-005 — un identifiant devient saisissable | #95 | 10/10 | `71325d2` |
| UPL-003 — un fichier déposé devient un morceau | #96 | en cours | en attente |

## C. Le parcours produit, mesuré

L'audit (#91) a testé l'application plutôt que relire sa documentation. Voici
l'état de chaque étape **avant** et **après** la session.

| Étape | Backend | UI | Chemin de production | Tests | Avant → Après |
|---|---|---|---|---|---|
| Connexion | ✅ | ✅ | écran | ✅ | `WORKING_E2E` |
| Dashboard | ✅ | ✅ | écran | ✅ | `WORKING_E2E` |
| **Déposer un fichier audio** | ✅ | ❌→✅ | aucun→écran | ✅ | `NOT_IMPLEMENTED` → **`WORKING_E2E`** |
| Stockage | ✅ | ✅ | écran | ✅ | `WORKING_E2E` |
| Analyse (MED-001) | ✅ | ✅ | événement | ✅ | `WORKING_E2E` |
| Déduplication | ✅ | ✅ | écran | ✅ | `WORKING_E2E` |
| Transcription | ✅ | ❌→✅ | console→écran | ✅ | `BACKEND_ONLY` → **`WORKING_E2E`** |
| Enrichissement IA | ✅ | partiel→✅ | aucun 1er jet→écran | ✅ | `PARTIALLY_WIRED` → **`WORKING_E2E`** |
| **Création du morceau** | ✅ | ❌→✅ | aucun→écran | ✅ | `NOT_IMPLEMENTED` → **`WORKING_E2E`** |
| Artiste / crédits | ✅ | ✅ | écran | ✅ | `WORKING_E2E` |
| Métadonnées éditoriales | ✅ | ✅ | écran | ✅ | `WORKING_E2E` |
| **Identifiants (UPC/ISRC)** | ✅ | ❌→✅ | aucun→écran | ✅ | `BACKEND_ONLY` → **`WORKING_E2E`** |
| Artwork (dépôt) | ✅ | ❌→✅ | aucun→écran | ✅ | `NOT_IMPLEMENTED` → **`WORKING_E2E`** |
| Artwork (génération, ART-002) | ✅ | ❌ | aucun | ✅ | `BACKEND_ONLY` (inchangé) |
| Validation | ✅ | ✅ | écran | ✅ | `WORKING_E2E` |
| Release READY | ✅ | ✅ | écran | ✅ | `WORKING_E2E` |
| **Package de distribution** | ✅ | ❌→✅ | aucun→écran | ✅ | `BACKEND_ONLY` → **`WORKING_E2E`** |
| Livraison | ✅ | ✅ | écran | ✅ | `EXTERNALLY_BLOCKED` (DIST-002) |

**Le jalon est atteint et prouvé** :
`tests/Feature/Journey/FromAFileToAReadyReleaseTest.php` fait traverser un vrai
fichier audio depuis le navigateur jusqu'à une release READY dont le package
s'assemble sans rien qui bloque — uniquement par des routes HTTP sur lesquelles
un écran poste.

## D. Défauts trouvés

1. **Aucun `<input type="file">` dans tout le frontend.** L'application ne
   pouvait accepter aucun fichier depuis un navigateur. Le sélecteur de pochette
   d'une release était donc vide en permanence sur une installation neuve.

2. **Le piège `UploadedFile::fake()`.**
   `Illuminate\Http\Testing\File::getMimeType()` déduit le type du **nom de
   fichier** : `createWithContent('master.wav', 'ceci n'est pas de l'audio')`
   renvoie `audio/wav`. Un vrai `UploadedFile` sur le même contenu renvoie
   `text/plain`. Le test aurait donc passé contre une implémentation qui fait
   confiance à l'extension — exactement le bug qu'il existe pour attraper.

3. **W4 — une suggestion décidée comptait comme en attente.** Une suggestion
   acceptée, rejetée ou remplacée faisait dire à l'écran « une suggestion attend
   une revue » pour le reste de la vie de l'asset.

4. **Les exigences d'identifiants étaient invisibles sans distributeur.** Elles
   vivaient dans `ValidateDelivery`, qui n'est interrogé qu'à propos d'une
   destination. Sur une installation sans distributeur — le défaut livré — une
   sortie sans le moindre ISRC passait la validation, devenait READY, et ne
   disait rien. La première nouvelle serait arrivée d'une plateforme.

5. **`ExternalIdentifierException` ne portait aucun code lisible par machine**,
   seule à faire exception dans la plateforme.

6. **U3 — le garde « vérifié seulement » n'était pas testé.** Un master mis en
   quarantaine aurait produit une entrée de file d'apparence actionnable qui
   refuse définitivement.

7. **Un défaut dans mon propre harnais de mutation.** Une passe a rapporté trois
   survivantes qui ne l'étaient pas : le quoting shell mangeait les `$variables`,
   la mutation n'était jamais appliquée, et ce succès se lisait comme une
   survivante. Corrigé en assertant que le fichier a changé avant de lancer la
   suite. Même famille que la règle sur les codes de sortie : le signal vient du
   mécanisme, jamais d'une correspondance textuelle qui peut échouer en silence.

8. **Un fixture qui ne passait que sur SQLite.** Un ISRC assemblé en interpolant
   `$track->id` fait treize caractères dès que les ids atteignent deux chiffres.
   `RefreshDatabase` remet la séquence à zéro sur SQLite et pas sur les moteurs
   serveur ; trois jobs sur dix échouaient. Cause reproduite localement en
   poussant l'auto-increment au-delà de 9.

## E. Sécurité

- **Aucun identifiant émis par un tiers n'est saisissable à la main.**
  `DISTRIBUTOR_RELEASE_ID`, `DISTRIBUTOR_TRACK_ID`, `DSP_ID`, `DSP_URL` sont
  absents de la liste assignable : une saisie serait une référence de livraison
  pour une livraison qui n'a jamais eu lieu.
- **La provenance appartient au serveur.** Toute attribution est `MANUAL` ;
  `source` et `is_authoritative` ne sont jamais lus depuis la requête.
- **IDOR fermé.** Un identifiant est lié par uuid *puis* vérifié contre l'entité
  par laquelle il a été atteint. `404`, pas `403`.
- **Type MIME lu depuis les octets**, jamais depuis l'extension ni depuis ce que
  déclare le client.
- **Taille bornée** par le minimum entre le réglage SaniTube et les limites PHP
  de l'hôte, et le facteur contraignant est nommé à l'écran.
- **Aucun chemin, disque, bucket ni URL** ne franchit la frontière : le package
  est asserté en le parcourant entier, pas champ par champ.
- **Le package est derrière `can.role:catalogue`** parce qu'il porte les noms
  légaux des contributeurs, qu'aucun autre écran de release n'affiche.
- **Les refus voyagent en codes**, jamais en phrases : un message du domaine
  nomme une classe pleinement qualifiée.
- Chaque « bouton caché » a un test qui poste au-delà et se fait refuser.

## F. Blocages externes

| Id | Cause | Preuve | Ce qui manque | Action humaine exacte |
|---|---|---|---|---|
| `DIST-002` | Aucun distributeur ne publie de contrat accessible | sondes directes, table dans `docs/research/distribution-2026-08.md` | un contrat publié + des identifiants | ouvrir un compte distributeur et fournir la documentation d'API |
| `GEN-002` | Suno ne publie aucun contrat d'API (août 2026) | `docs/research/suno-2026-08.md` | un contrat publié | attendre l'API partenaires ; ADR-0018 exclut définitivement les wrappers rétro-conçus |
| `AI-002` | Aucune clé OpenAI/Anthropic disponible | aucune requête sortante émise | une clé | fournir une clé dans la configuration |
| `STO-002` | Aucun endpoint S3 réel | contrat prouvé par deux implémentations | un bucket | fournir un endpoint et des identifiants |
| `ENVIRONMENT_EGRESS` | La politique réseau de cet environnement refuse les hôtes distributeurs et DDEX (403 au proxy) | table de sondes avec `raw.githubusercontent.com` en témoin | — | exécuter depuis un environnement sans cette restriction |
| `EXTERNAL_ADMIN_ACTION_REQUIRED` | La branche par défaut GitHub est encore `claude/verifier-repertoire-git-k29ft2` | aucun outil ici ne modifie les réglages du dépôt | droits admin | **Settings → General → Default branch → `main`** |

## G. Configuration

Rien n'a été codé en dur. Ajouté cette session :

- `config/assets.php` → `accepted_upload_types` : les types MIME acceptés par
  genre d'asset. Modifiable sans toucher au code.
- Les plafonds de taille lisent `upload_max_filesize` et `post_max_size` de
  l'hôte et affichent le plus contraignant des deux.

## H. État d'installation

- **cPanel / hébergement mutualisé** : le chemin relayé fonctionne — c'est le
  défaut livré, et c'est pour lui que la limite PHP effective est calculée et
  affichée. Aucun stockage objet requis.
- **VPS Ubuntu / CentOS** : identique, plus le chemin direct si un stockage
  objet est configuré.
- Ni l'un ni l'autre n'a été certifié sur un hôte réel — voir
  `CPANEL_CERTIFICATION` et `VPS_CERTIFICATION`, qui restent ouverts.

## I. Trois mesures, séparées

**Ne pas les confondre, et aucune n'est dérivée d'un décompte de fichiers.**

- **`INTERNAL_COMPLETION`** — la barrière complète est verte : 1739 tests,
  15212 assertions, PHPStan niveau 6 sans baseline, Pint, vue-tsc, Vitest, build.
  10/10 sur la matrice CI (3 versions de PHP × 4 moteurs de base).

- **`PRODUCT_USABILITY`** — **le parcours minimal est complet et prouvé de bout
  en bout par un test qui n'utilise que des routes d'écran.** Ce qui reste
  `BACKEND_ONLY` : la génération d'artwork (ART-002), qui n'a toujours aucun
  appelant de production.

- **`PRODUCTION_READINESS`** — **aucune livraison réelle n'a jamais eu lieu et
  aucune n'est possible depuis cet environnement.** Le moteur de livraison est
  exercé de bout en bout contre un distributeur factice en sandbox, qui le dit.
  Aucun appel sortant vers un fournisseur IA, un stockage objet ou un
  distributeur n'a été effectué ni validé. La plateforme est prête à recevoir
  ces intégrations ; elle n'en a validé aucune.

## J. Ce qui n'a pas été fait, et pourquoi

- **Accessibilité au-delà de jsdom** : parcours clavier sur technologie
  d'assistance réelle, et comportement responsive à quatre points de rupture sur
  six langues. Demande un navigateur rendu.
- **Certification sur hôte réel** (cPanel, VPS).
- **AUDIT-002** — une identité d'acteur honnête pour `src/Api`.
- **ART-002 câblé à un écran** — la génération d'artwork reste sans appelant.

## K. Chemin critique suivant

1. Élargir le parcours plutôt que l'ouvrir : plusieurs pistes par release depuis
   l'écran, et l'artwork généré (ART-002) relié à un écran.
2. Certification sur hôte réel.
3. Accessibilité en navigateur rendu.
4. Tout le reste attend une action humaine listée en **F**.
