# BULK-002 — décider en masse dans la file de revue

## Le problème

BULK-001 permet de faire entrer 900 fichiers. Ils produisent 900 candidats — et
la seule façon de les traverser était **900 chargements de page**.

## Ce que fait ce changement

Une barre d'actions apparaît dès qu'une sélection existe : ajouter au catalogue,
ou rejeter avec une raison. Les décisions elles-mêmes sont **inchangées** :
chacune met en file un job qui appelle le même service, exécute les mêmes
invariants et écrit la même entrée d'audit qu'une décision unitaire.

### Deux choses que cela ne fait délibérément pas

**Cela n'outrepasse jamais.** Outrepasser, c'est l'endroit où une personne
contredit la machine à propos d'**un** enregistrement qu'elle a regardé. Offrir
ça pour cent d'un coup est exactement ce que l'override existe pour empêcher.
Un candidat signalé par l'analyse est refusé par son nom (`NOT_PROMOTABLE`) et
reste dans la file, dans l'état qui explique pourquoi.

**Cela ne décide rien dans la requête.** Une boucle de mille transactions dans
une requête HTTP est une requête qui expire à mi-chemin, ayant déjà modifié le
catalogue, sans trace de l'endroit où elle s'est arrêtée. Un job par candidat
est interruptible, réessayable, et laisse chaque candidat non décidé exactement
où il était.

### Bornes et garde-fous

- La sélection est plafonnée (`ingestion.max_bulk_review`, 200 par défaut).
- La capacité est demandée à OPS-002 **avant** de mettre quoi que ce soit en
  file : refuser maintenant, pendant que quelqu'un regarde la réponse, vaut
  mieux qu'échouer deux cents jobs dans une file que personne ne surveille.
- Les uuid sont résolus contre ce qui existe, jamais crus.
- Un refus du domaine **n'échoue pas le job** : le retenter trois fois pour se
  faire répondre la même chose enterrerait une réponse ordinaire dans la table
  des jobs échoués.

## Un défaut trouvé au passage

**`ImportActionController` n'attrapait pas `WorkRefused`.**

`StartIngestionBatch` demande sa capacité à OPS-002 et lève `WorkRefused` ; le
contrôleur n'attrapait qu'`IngestionException`. Sur une installation dont le
travail de fond est en pause — un état supporté et délibéré — démarrer un import
depuis l'écran **répondait 500**.

L'exception portait déjà un code écrit pour être traduit en instruction. Rien ne
l'attrapait pour le faire. Corrigé, avec un test.

*(Trouvé parce que j'avais écrit les traductions en devinant les codes de refus.
Ils s'appellent `BACKGROUND_WORK_PAUSED` et `BACKLOG_SATURATED` ; en allant les
vérifier plutôt qu'en les supposant, le chemin non attrapé est apparu.)*

## Preuves

**A. PREUVE COMPOSANT** — `tests/Feature/Ingestion/BulkReviewTest.php`,
14 tests / 46 assertions.

**B. PREUVE CHEMIN DE PRODUCTION** — `resources/js/Pages/Ingestion/Candidates/Index.vue`,
cases à cocher par ligne et « tout sur cette page », barre d'actions →
`POST /ingestion/candidates/bulk`.

## Mutations — 8 posées, 7 tuées, 1 redondance supprimée

| | Mutation | Résultat |
|---|---|---|
| R1 | le job outrepasse ce que l'analyse a signalé | tuée |
| R2 | la capacité n'est plus demandée avant la mise en file | tuée |
| R3 | les uuid sont crus plutôt que résolus | tuée |
| R4 | la déduplication retirée de la requête | **survivante → ligne supprimée** |
| R5 | le plafond de sélection retiré | tuée |
| R6 | un rejet sans raison | tuée |
| R7 | un refus du domaine fait échouer le job | tuée |
| R8 | le contrôleur d'import cesse d'attraper `WorkRefused` | tuée |

**R4 n'a pas été corrigée par un test mais par une suppression.**
`whereIn(...)->pluck('uuid')` rend déjà chaque ligne une fois : l'`array_unique`
de la requête était une ligne qui ne pouvait changer aucun résultat. Une seconde
garantie au second endroit est une garantie qui finit par diverger. Le test qui
tient la propriété reste ; c'est la ligne redondante qui est partie.

## Barrière complète

| | |
|---|---|
| PHPUnit | 1774 passés, 1 ignoré, 22582 assertions |
| PHPStan (niveau 6, sans baseline) | `[OK]` |
| Pint | `passed` |
| vue-tsc | exit 0 |
| Vitest | 23 passés |
| Build frontend | `✓ built` |
