# DIST-004 — livrer sans API

## Le problème

C'était le plus grand manque de la plateforme.

Un catalogue pouvait être importé, relu, crédité, validé, marqué READY — et
s'arrêtait là. Les seuls distributeurs qui existaient étaient `none` et un
faux, donc un label réel utilisant SaniTube n'avait **aucun moyen de faire
sortir l'audio et les métadonnées**, sous quelque forme que ce soit.

Presque aucun distributeur ne publie d'API. ADR-0018 interdit d'en inventer une
à partir d'un wrapper rétro-ingénieré. Et tous, sans exception, ont un portail
web. Le chemin de livraison qui fonctionne partout est donc une personne, une
feuille de calcul et un jeu de fichiers.

## Ce que fait ce changement

`sanitube:distribution:export {release}` écrit deux fichiers sous le préfixe que
la plateforme possède déjà pour ses sorties de distribution :

- **`metadata.csv`** — la feuille d'intake, dans les colonnes configurées ;
- **`manifest.json`** — pour chaque piste : l'uuid, l'uuid du master, la clé de
  stockage, le nom de fichier proposé, le SHA-256 et la taille.

### Ce que ce n'est pas

**Ce n'est pas une soumission.** Rien n'est envoyé, rien ne devient
irréversible, et aucune ligne `DistributionDelivery` n'est écrite. Construire un
paquet est répétable ; confondre cela avec le seul acte de cette plateforme qui
ne peut pas être défait affaiblirait les trois gardes qui le protègent.

Enregistrer *qu'un humain l'a livré* est une affirmation distincte à propos du
monde extérieur. Elle mérite son propre ticket plutôt que d'être déduite du fait
qu'un fichier a été écrit.

### Aucun master n'est copié

Le paquet est une feuille et une liste vérifiée d'objets déjà stockés. Copier un
jeu de masters doublerait le stockage de chaque release et, sur du stockage
objet, ferait descendre deux gigaoctets à travers PHP pour les remonter à côté
d'eux-mêmes. L'audio est récupéré quand l'opérateur le récupère, par le même
lien signé expirant que toute autre lecture.

`--links` frappe un lien par master, à la demande. Rien n'est imprimé sans qu'on
le demande : la signature *est* la permission de lire un master, et un terminal
est un historique de défilement, un partage d'écran et un fil de support.

### Rien n'est inventé pour remplir une cellule

Un ISRC non attribué est une cellule vide **et un avertissement**, jamais un code
plausible qu'un distributeur enregistrerait ensuite comme réel. Un avertissement
plutôt qu'un refus : certains distributeurs attribuent les ISRC à la réception,
et refuser d'exporter tant que chaque code n'existe pas rendrait la plateforme
inutilisable pour les labels qui comptent là-dessus.

Un identifiant **révoqué** n'est jamais exporté. C'est le seul endroit où
présenter un code retiré le remettrait en circulation : un portail lit la
feuille et enregistre ce qu'il y trouve.

### Les colonnes sont des données

Chaque formulaire d'intake diffère — les intitulés, leur ordre, lesquels sont
obligatoires. Le mappage vit dans `config/distribution.php` et **aucun
distributeur n'est nommé dans le code d'export**. L'intitulé à gauche est ce que
le formulaire appelle la colonne ; la valeur à droite est un champ que SaniTube
sait répondre.

Ce vocabulaire est **fermé** (`ExportField`). Un intitulé pointant sur autre
chose est ignoré plutôt que rendu : une colonne de blancs se lit, pour qui
téléverse la feuille, comme une donnée que le catalogue ne détient pas.

### Le préfixe n'est pas configurable

C'est celui que la plateforme possède déjà pour ses sorties de distribution —
donc celui que l'importeur refuse déjà de relire. Un préfixe réglable serait un
moyen de poser la sortie de la plateforme là où un import ultérieur la lirait
comme du matériel neuf, et chaque passe produirait une génération de doublons de
plus. Un test le prouve en demandant au lecteur d'ingestion d'accepter le
préfixe du paquet, et en constatant qu'il refuse.

### Un nom de fichier est un rendu, jamais une identité

`01-01 Night Bus.wav` est ce qu'un opérateur voit dans son gestionnaire de
fichiers. Le manifeste porte à côté l'uuid de la piste et l'empreinte du master,
parce que deux pistes intitulées « Intro » sur le même album se réduisent à la
même chaîne et restent deux enregistrements différents.

## Vérification

20 tests. L'ordre de passage est l'un d'eux : la séquence *est* le livrable, un
portail lit la feuille de haut en bas, et un paquet dont les lignes sortiraient
dans l'ordre d'insertion livrerait un album mélangé sans que rien en aval ne
s'en aperçoive, puisque chaque ligne serait individuellement correcte.

8 mutations, 8 tuées. Deux survivantes au premier tour, toutes deux de vraies
lacunes : rien ne testait une release à plusieurs pistes — donc l'ordre n'était
pas prouvé — et rien ne testait une piste dont le master a disparu après le
passage en READY.
