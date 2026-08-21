# PERF-002 — mesurer des écrans qui ont quelque chose dedans

## Le défaut

PERF-001 mesure neuf écrans à deux tailles de catalogue et affirme que le nombre
de requêtes et la taille de la charge utile ne bougent pas. C'est la bonne
propriété : un écran qui fait une requête pour dix pistes et neuf cents pour
neuf cents va bien dans tous les tests écrits contre un fixture de trois.

**Le fixture ne semait que des pistes et des artistes.**

Vérifié en semant vingt pistes et en comptant :

```
tracks=20  assets=0  candidates=0  batches=0  duplicates=0
```

Donc cinq des neuf écrans mesurés — `/catalog/assets`,
`/catalog/contributors`, `/ingestion/candidates`, `/ingestion/batches` et
`/duplicates` — ne rendaient **rien** à soixante pistes et **rien** à quatre
cents.

« Le même nombre de requêtes aux deux tailles » est vrai d'un écran sans
lignes. Un N+1 sur une page de candidats ne pouvait pas y apparaître.

## Ce que fait ce changement

**Chaque écran déclare où vivent ses lignes** dans sa charge utile, et un test
exige que chacune soit non vide *avant* que quoi que ce soit ne soit mesuré. Un
écran ajouté à la liste sans le fixture pour le remplir échoue par son nom
plutôt que de rejoindre les cinq autres.

Le fixture sème désormais ce que les écrans lisent : un master par piste, un
item et un candidat d'ingestion, une relation de doublon par paire successive,
un contributeur crédité, une composition, une sortie, et une ligne d'audit —
cette dernière **par le service d'enregistrement** plutôt qu'en écrivant la
ligne, parce qu'il dérive le sujet de l'action et l'acteur de la requête.

Trois écrans de plus sont couverts au passage : `/catalog/compositions`,
`/releases` et `/system/audit`.

## Ce qui n'est pas couvert est écrit

Cinq écrans d'index restent hors de portée — enrichissement, distribution,
production, générations, projets — chacun avec ce qu'il faudrait pour
l'atteindre. Un écran non couvert que personne n'a nommé est indiscernable d'un
écran que quelqu'un a vérifié.

## Le résultat, une fois les écrans réellement remplis

**Aucun N+1 ne se cachait.** Chaque écran tient son nombre de requêtes et sa
charge utile avec de vraies lignes aux deux tailles. C'était déjà vrai ; ce n'en
était pas encore la preuve.

## Une erreur de fixture que le test a attrapée

La première version semait un doublon une piste sur deux — trente lignes à
soixante pistes, cinquante à quatre cents, c'est-à-dire une page de revue qui
grandit légitimement entre les deux lectures. Le test de charge utile a signalé
`/duplicates` comme non borné. Il ne l'est pas : la requête pagine à cinquante
par curseur. C'était le fixture qui produisait moins d'une page à la petite
taille.

C'est exactement le genre de faux positif qu'un seuil absolu n'aurait jamais
levé, et exactement pourquoi les deux lectures doivent toutes deux dépasser la
taille de page.

## Vérification

6 tests. 3 mutations, 3 tuées : le fixture revenu à « pistes et artistes
seulement », une raison vide dans la liste des non-mesurés, et un écran déclaré
à la fois mesuré et non mesuré.
