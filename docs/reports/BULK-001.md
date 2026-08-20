# BULK-001 — un catalogue entre depuis le navigateur

## Le problème

`ManualUploadReader` décrit depuis toujours des octets qui arrivent « through
the ordinary storage pipeline into a reserved inbox prefix, and the batch
refers to them by key ».

Tout ce qui se trouve **en aval** de cette phrase était construit : le reader,
le batch, le job par item, les retries, la déduplication, le candidat, les
écrans de batch, la finalisation. Et **rien ne pouvait déposer un octet dans
l'inbox depuis un navigateur.** La seule route qui démarre un batch à partir de
références n'avait elle non plus aucun appelant.

Une personne avec 900 masters sur son portable avait donc exactement deux
options : les envoyer un par un via un écran qui crée un asset par fichier et
aucun batch, ou obtenir un accès SSH.

## Ce que fait ce changement

**Deux chemins, un seul résultat.** *Direct* frappe une capacité éphémère par
fichier et les octets n'entrent jamais dans PHP — la seule façon dont 900
masters, couramment plusieurs centaines de Mo chacun, entrent sans que la limite
mémoire, le timeout d'exécution et la taille de corps de requête soient tous
faux en même temps. *Relayé* fait transiter un fichier par l'application, et
existe parce qu'un compte cPanel mutualisé ne peut pas frapper d'URL d'upload et
doit quand même pouvoir importer un catalogue.

**Le serveur choisit la clé. Toujours.** Un client qui nommerait la sienne
nommerait un chemin, et un chemin contrôlé par un client finit par contenir
`..`. Ce que le client fournit est un *nom de fichier*, qui compte seulement
parce que `ManualUploadReader::originalFilename()` en est le `basename()` et que
`SuggestTitle` le lit.

**L'inbox est l'état durable.** La liste d'attente est lue depuis le stockage à
chaque rendu, ce qui fait qu'un rechargement de page ne perd rien de ce qui a
atterri. Aucune abstraction n'a été inventée pour cela : les objets *sont* l'état.
Ce qui ne peut pas survivre — un fichier choisi et jamais envoyé, dont seul le
navigateur détient la poignée — est dit explicitement plutôt que sous-entendu.

**La requête qui démarre l'import porte des références, aucun octet.** Chaque
octet bouge dans un job de queue qui survit à la fermeture de l'onglet. C'est la
seule forme dans laquelle 900 fichiers s'importent.

Concurrence bornée et configurée : `deposit_concurrency` (3 par défaut), parce
que le bon nombre est une propriété de l'hôte et non de l'application.

## Preuves

**A. PREUVE COMPOSANT** — `tests/Feature/Ingestion/CatalogueImportFromTheBrowserTest.php`,
16 tests / 85 assertions, toutes à travers les routes HTTP.

**B. PREUVE CHEMIN DE PRODUCTION** — `resources/js/Pages/Ingestion/Import.vue`,
atteignable depuis la navigation (« Importer un catalogue »), premier élément du
groupe Bibliothèque.

**C. PARCOURS MULTI-FICHIERS** —
`tests/Feature/Journey/FromManyFilesToAReleaseTest.php` : trois fichiers →
dépôt → batch → **un vrai `queue:work`** → 3 candidats → promotion → 3 pistes →
crédits → ISRC → release → UPC → READY → package à 3 pistes, rien qui bloque.
Aucune écriture directe de statut ; le seul appel non-HTTP est le worker, ce que
fait une vraie installation.

## Mutations — 9 posées, 7 tuées, 2 survivantes honnêtes

| | Mutation | Résultat |
|---|---|---|
| B1 | le strip de séparateurs retiré de `safeName` | **survivante — garde inatteignable** |
| B2 | la clé perd son segment uuid (collisions) | tuée |
| B3 | le type déclaré n'est plus vérifié avant de frapper une capacité | tuée |
| B4 | le chemin relayé lit `getClientMimeType()` | tuée |
| B5 | la borne par requête retirée | tuée |
| B6 | une inbox illisible rapportée comme vide | tuée |
| B7 | la re-vérification du préfixe retirée du discard | **survivante — garde inatteignable** |
| B8 | la limite de taille retirée du chemin relayé | tuée |
| B9 | la route relais sort de `can.role:catalogue` | tuée |

**B1 et B7 sont enregistrées comme inatteignables plutôt que corrigées.**
`OriginalFilename::sanitise()` normalise les séparateurs Windows puis applique
`basename()` : aucun séparateur ne lui survit, et `..` revient en `unnamed`. Le
second strip est donc de la défense en profondeur pendant que la couche externe
fonctionne. Idem pour B7 : `files($prefix)` cadre déjà la liste. Les deux sont
documentées comme telles dans le code — inventer un test pour les atteindre
aurait été inventer un test.

## Deux défauts trouvés au passage

**1. Un prop nommé `import` est illisible depuis un template Vue.** Le parseur
d'expressions lit le mot nu comme la méta-propriété `import` et refuse le
fichier. **`vue-tsc` l'accepte et le build échoue** — le seul endroit où les
deux vérifications divergent, et la raison pour laquelle le build est une étape
de CI distincte. Renommé `capability` des deux côtés.

**2. Aucun garde de parité entre locales n'existait.** Le garde en place
vérifiait qu'un fichier de traduction *existe*, ce qui n'attrape qu'une locale
sans catalogue du tout : un fichier présent auquel il manque la moitié de ses
clés s'affiche en `ui.import.title` en production. Six locales × plusieurs
centaines de clés n'est pas vérifiable à l'œil.
`tests/Feature/Localization/TranslationParityTest.php` l'exige désormais dans
les deux sens, plus un garde anti-marqueur.

Sa première version cherchait `TODO` sans casse et **échouait immédiatement sur
l'espagnol `todos`**, dans une phrase parfaitement terminée. Un garde qui crie
au loup dans les langues mêmes qu'il protège est un garde que quelqu'un
supprime. Corrigé en sensible à la casse et borné aux mots.

## Barrière complète

| | |
|---|---|
| PHPUnit | 1759 passés, 1 ignoré, 22413 assertions |
| PHPStan (niveau 6, sans baseline) | `[OK]` |
| Pint | `passed` |
| vue-tsc | exit 0 |
| Vitest | 23 passés |
| Build frontend | `✓ built` |

## Ce que cela ne prouve pas

**900 fichiers n'ont pas été importés.** Ce qui est tenu est la *forme* — un
batch, un item par fichier, un job par item, une requête qui porte des
références et aucun octet — et c'est cette forme qui rend 900 possible.
Prétendre avoir testé 900 parce qu'une boucle a tourné 900 fois serait
prétendre quelque chose sur une machine, pas sur la plateforme.
