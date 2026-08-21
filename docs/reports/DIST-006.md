# DIST-006 — une seule description de ce qui traverse

## Ce que DIST-004 a fait de travers

L'exportateur livré dans DIST-004 **parcourait l'agrégat Release lui-même** :
sa propre requête sur les pistes, sa propre lecture des identifiants, sa propre
vérification « cette piste a-t-elle un master ».

C'est exactement ce que `ReleasePackage` existe pour empêcher. Son propre
docblock le dit, et cela vaut pour un exportateur comme pour un adaptateur
d'API :

> Avant lui, un adaptateur aurait parcouru l'agrégat Release lui-même […] ce qui
> signifie que chaque adaptateur atteint différemment, que chaque adaptateur a
> sa propre chance de lire un identifiant révoqué comme courant, et que « qu'a-t-on
> réellement envoyé » n'est répondable qu'en rejouant ce que cet adaptateur-là
> a fait.

Deux descriptions de ce qu'un distributeur reçoit finiront par diverger, et
celle qui diverge est celle sur laquelle quelqu'un a agi.

## Ce que fait ce changement

`BuildDeliveryPackage` appelle `PackageRelease` et **rend** le `ReleasePackage`
obtenu. La validation, la pochette, les pistes et chaque identifiant sont
demandés une fois, au service qui répond pour tout canal sortant. Un refus porte
le même code qu'une livraison par API.

**Ce n'était pas un refactor à sortie constante.** Le type frontière portait
déjà des choses que la feuille n'avait pas, parce que l'exportateur n'avait
demandé au catalogue que ce qu'il avait pensé à demander :

- les **contributeurs**, avec leurs noms *légaux* — ceux sur lesquels les
  sociétés de gestion font correspondre ;
- le drapeau **explicite**, que chaque store demande et dont l'erreur fait
  retirer une sortie ;
- **instrumental**, les **genres**, et la ligne ℗ propre à l'enregistrement.

Huit colonnes de plus, disponibles parce qu'elles étaient déjà là.

La **durée** vient désormais de la piste et non du fichier. Ce sont deux
questions différentes — ce que la sortie déclare, et combien de temps dure
l'objet stocké — et c'est la première qu'un distributeur publie.

## Ce qui reste à l'exportateur

**Le fichier, pas l'enregistrement.** Une empreinte, un nombre d'octets et une
clé de stockage sont délibérément absents du type frontière — « aucun secret et
aucun emplacement n'est ici » — et un opérateur sur le point de téléverser deux
gigaoctets a besoin des trois. Ils sont lus sur l'asset que le type frontière
nomme.

**La contrainte READY.** REL-004 se sert du packager pour montrer à un label ce
qui *traverserait* pendant qu'une sortie est encore en cours d'assemblage —
raison pour laquelle il n'exige pas une sortie finie. La remettre, si. C'est
demandé ici, avant le packager, exactement comme `SubmitDelivery` le demande.

Un test l'a attrapé : la réécriture avait laissé tomber la contrainte, et un
brouillon valide serait devenu exportable.

## Un seul vocabulaire de refus

`ReleasePackagingException` et `DistributionException` implémentent désormais
`CarriesRefusalCode`. Les deux portaient déjà `reason` ; c'est la même valeur,
atteignable sans savoir quel module a refusé — ce qui permet à un seul `catch`
de traiter les deux au lieu qu'un appelant énumère les modules qu'il connaît.

## Vérification

25 tests. 7 mutations, 6 tuées.

**La survivante est enregistrée plutôt que contournée.** Le garde « le master
nommé par le type frontière n'existe plus » est inatteignable : `PackageRelease`
refuse déjà une piste sans master, donc rien ne peut arriver ici avec un master
absent. Il reste, parce qu'un garde inatteignable est ce à quoi ressemble la
défense en profondeur pendant que la couche extérieure fonctionne — et parce que
sans lui un changement du packager transformerait un refus nommé en TypeError.
C'est le précédent que REL-003 a posé pour ses propres gardes, et inventer un
test pour l'atteindre reviendrait à tester le double plutôt que le code.

Quatre des six tuées ne l'étaient pas au premier tour, et chacune était une
vraie lacune : l'ordre des artistes n'était pas prouvé (une seule par piste dans
les fixtures), les drapeaux booléens non plus (toujours faux), les contributeurs
n'étaient jamais peuplés, et rien ne conduisait la commande vers un refus venu
du *packager* plutôt que de la distribution.
