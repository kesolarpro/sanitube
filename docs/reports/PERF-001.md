# PERF-001 — mesurer le comportement au volume, puis optimiser sur preuve

## La question

La plateforme est construite pour un catalogue d'environ 900 enregistrements.
La question qui décide si elle est utilisable n'est pas « cette page est-elle
rapide » mais **« cette page coûte-t-elle plus cher quand il y a plus dedans »**.

Un écran qui fait une requête pour dix pistes et neuf cents pour neuf cents va
bien dans tous les tests écrits contre une fixture de trois.

## Ce qui a été mesuré

Sondage direct, à 120 puis à 900 pistes, chaque piste créditée :

| Écran | Requêtes | Payload |
|---|---|---|
| `/catalog/tracks` | 3 | 96,6 Ko |
| `/catalog/artists` | 1 | 83,6 Ko |
| `/catalog/assets` | 1 | 78,8 Ko |
| `/releases` | 1 | 78,7 Ko |
| `/ingestion/candidates` | 1 | 78,8 Ko |
| `/ingestion/batches` | 1 | 78,7 Ko |
| `/` (tableau de bord) | 34 | 79,7 Ko |

**Identiques à 120 et à 900.** Les écrans d'index sont O(1) en taille de
catalogue : pagination par curseur et eager loading font leur travail. C'est le
résultat principal, et il est mesuré et non supposé.

## Ce qui a été corrigé

**Le tableau de bord demandait onze fois au moteur si une table existe**, pour
trois questions distinctes : chaque compte vérifie d'abord l'existence de sa
table. `SchemaPresence`, lié en `scoped`, répond une fois par requête. 34 → 30.

`scoped` et non `singleton` : une migration entre deux chargements de page doit
être vue. Un cache statique survivrait à la requête sous un worker persistant et
rapporterait une table comme absente jusqu'au redémarrage du processus — le
genre de péremption qu'on diagnostique comme une panne de base.

Les 30 restantes sont des agrégats distincts, un par chiffre affiché, aucun par
ligne — exactement ce que le docblock de la classe promet.

## Le défaut dans mon propre test

Le premier garde écrit mesurait chaque écran à deux tailles et asserait
l'égalité. Il passait.

**Puis j'ai retiré l'eager loading de la liste des pistes — un N+1 manuel — et
le garde est passé quand même.**

La raison mérite d'être écrite : la liste pagine par curseur, donc un N+1
s'exécute une fois par *ligne de la page*, et ce nombre est le même que le
catalogue contienne soixante pistes ou quatre cents. **L'égalité entre tailles
de catalogue prouve que la page est bornée ; elle ne dit rien de ce que la page
coûte par ligne à l'intérieur de cette borne.**

Un garde qui donne confiance sans détecter le défaut qu'il prétend détecter est
pire que pas de garde. Ajouté : un plafond absolu par écran d'index, délibérément
lâche (10). Re-vérifié contre la même mutation — **101 requêtes contre un
plafond de 10, échec net**.

Deux autres faux positifs de mon test, corrigés :

- Le tableau de bord affiche des *comptes*, et `400` fait deux caractères de
  plus que `60`. L'égalité stricte de payload était fausse ; une tolérance d'un
  kilooctet distingue « quelques chiffres de plus » de « 340 lignes sérialisées ».
- Ma fixture créait dix artistes de plus à chaque appel, ce qui allongeait
  légitimement la liste des artistes. Une seule chose peut différer entre deux
  mesures.

## Un défaut trouvé dans un test existant

`DashboardTest::the_snapshot_costs_the_same_whether_the_catalogue_is_small_ou_large`
mesurait deux fois dans le même processus. Avec `SchemaPresence` en `scoped`, la
seconde mesure héritait du cache chaud et devenait **moins chère** (30 → 17),
faisant échouer le test. Corrigé en oubliant les instances `scoped` avant chaque
mesure : un cache chaud rendrait la seconde lecture, plus grosse, artificiellement
moins chère — ce qui masquerait exactement la régression que le test surveille.

## Un constat mesuré et non corrigé

**`translations` pèse 75,7 Ko de props partagées sur *chaque* réponse Inertia** —
95 % du payload de chaque page. C'est plat : indépendant de la taille du
catalogue, donc **ce n'est pas un risque d'échelle**. Le corriger proprement
demande une négociation de locale entre client et serveur dont le mode d'échec
est « toute l'interface s'affiche en clés pointées ».

Enregistré comme mesuré, connu, non corrigé, avec le raisonnement — plutôt que
corrigé à la hâte en fin de session.

## Barrière complète

| | |
|---|---|
| PHPUnit | 1778 passés, 1 ignoré, 22671 assertions |
| PHPStan (niveau 6, sans baseline) | `[OK]` |
| Pint | `passed` |
| vue-tsc | exit 0 |
| Vitest | 23 passés |
| Build frontend | `✓ built` |
