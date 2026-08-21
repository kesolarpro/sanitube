# BULK-003 — importer un catalogue plus grand qu'un lot

## Le problème

Un lot est plafonné, délibérément : c'est une unité de travail qu'une personne
peut inspecter et, quand elle tourne mal, sur laquelle elle peut raisonner. Huit
lots de cinq cents à moitié échoués sont diagnosticables ; un lot de quatre
mille est un mur de lignes. `config/ingestion.php` le dit depuis BULK-001, et
propose le remède : « deux lots de cinq cents ».

**Ce remède n'était atteignable depuis aucune interface.**

`--prefix` prend *tout* ce qu'il y a sous un dossier, et une sélection au-dessus
du plafond est refusée sèchement. Un catalogue de quatre mille fichiers pouvait
donc être listé, refusé, et importé nulle part — sauf à nommer trois mille cinq
cents clés d'objet à la main. Le second lot n'existait pas.

## Ce que fait ce changement

`sanitube:import --continue` prend le prochain lot de ce que cette sélection n'a
pas encore traité. La même commande, relancée, prend le lot suivant. Relancée
une fois le catalogue rentré, elle ne fait rien du tout.

**Ce qui est déjà traité est lu dans la table des items**, pas dans un curseur
que l'opérateur devrait conserver. La séquence survit donc à un terminal fermé,
à un worker tué et à un redémarrage — la seule forme de reprise qui vaille pour
un import qui dure des heures.

### Ce qui compte comme traité

Tout sauf deux états, et les deux exceptions sont le cœur du ticket.

**FAILED n'est pas traité.** Un item en échec est exactement celui qu'une
seconde passe doit reprendre : une coupure réseau, une panne de fournisseur, un
fichier pas encore déposé. Le compter comme fait signifierait que la seule façon
de réessayer un fichier sur quatre mille est de le retrouver à la main — à cette
échelle, c'est-à-dire jamais.

**SKIPPED non plus.** Skipped veut dire que le travail n'a *pas* été tenté,
parce qu'autre chose portait la même intention à ce moment-là. L'item qui a fait
le travail est une autre ligne, et c'est elle — si elle existe — qui rend la
référence traitée.

### Ce que cela ne fait pas

**Le refus reste le défaut.** Sans `--continue`, une sélection au-dessus du
plafond est toujours refusée. Importer silencieusement un sous-ensemble de ce
que quelqu'un a nommé serait le pire des défauts : un opérateur qui a demandé un
dossier et en a reçu cinq cents, sans erreur, croit son import terminé.

**L'ordre ne porte rien.** Le listing est trié pour que les lots soient
prévisibles — le même dossier donne les mêmes cinq cents premiers, ce qui rend
un import à moitié fait lisible. Mais la reprise n'en dépend pas : un service
S3 ne garantit l'ordre lexicographique que par page, jamais à travers un
re-listing où des objets ont été ajoutés, et un test le prouve en réordonnant le
magasin entre chaque passe. Un ordre dont on dépend est un ordre qui finit par
trahir.

**Le manifeste est réduit, pas remplacé.** Passer une simple liste de références
pour le second lot perdrait chaque titre, chaque artiste et chaque ISRC que
l'opérateur a écrits — c'est-à-dire toute la raison pour laquelle il a écrit un
manifeste.

## Hors périmètre, et pourquoi

**L'écran d'import ne continue pas.** Il refuse toujours une sélection
au-dessus du plafond, avec le message traduit qu'il affichait déjà. C'est
cohérent avec la répartition posée par ING-002 : la ligne de commande est
l'outil du catalogue initial — « neuf cents fichiers sont un manifeste et un
long après-midi, et un onglet de navigateur est le mauvais endroit pour les
deux » — et l'écran sert le cas ordinaire, vingt masters déposés dans un
dossier. Étendre la continuation au navigateur est un ticket avec son propre
travail d'interface et de traduction dans six langues.

## Vérification

9 tests, dont la marche complète : dix-huit objets, un plafond de cinq, quatre
passes, dix-huit items et dix-huit clés d'ingestion distinctes. Ni recouvrement
ni trou — un import reprenable qui se recouvre est un import qui paie deux fois
le même egress.

7 mutations, 7 tuées.
