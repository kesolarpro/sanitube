# DIST-005 — livrer à la main, depuis un navigateur

## Ce qui manquait

DIST-004 a construit le paquet et lui a donné une commande. C'est le bon outil
pour le catalogue initial et le mauvais pour le déploiement que cette plateforme
existe pour servir : **un label sur un compte cPanel mutualisé n'a pas de
shell.**

Une des trois installations supportées pouvait donc atteindre READY et n'avait
ensuite nulle part où aller.

## Ce que fait ce changement

Un écran, trois routes, toutes derrière `can.role:distribute` — choisir ce qu'un
distributeur reçoit est la même décision qu'une API ou une personne la porte.

- `GET  …/distribution/package` — l'écran : ce qui a été construit, quand, les
  avertissements, et la liste des masters tels que le paquet les nomme.
- `POST …/distribution/package` — construire. Écrit deux fichiers.
- `GET  …/distribution/package/metadata` — la feuille, en flux, jamais tenue en
  mémoire.

### Trois choses qu'il ne fait pas

**Il ne soumet rien**, et l'écran le dit deux fois — à côté du bouton et à la
fin. Il n'y a délibérément **aucun contrôle « marquer comme livré »** à côté du
téléchargement : un bouton là transformerait « j'ai les fichiers » en « ils les
ont », c'est-à-dire une affirmation sur le système de quelqu'un d'autre que
seule une personne peut faire.

**La page ne transporte aucun lien.** Une URL signée est un porteur qui expire ;
en frapper une par piste parce que quelqu'un a ouvert un écran pour lire un nom
de fichier en créerait onze dont personne ne voulait. Chaque lien est demandé
**un master à la fois**, par le point de frappe ordinaire — même politique, même
limitation de débit, même ligne d'audit que tout autre écran. Une seconde route
de téléchargement pour la distribution serait un second endroit où se souvenir
de la politique de prévisualisation.

**Elle ne transporte pas non plus de clé d'objet.** L'uuid public de l'asset
suffit à demander un lien ; où vit un master ne regarde pas un navigateur.

### Deux vocabulaires de refus, rien de dupliqué

Construire un paquet interroge le packager *et* la distribution, donc les deux
familles de codes peuvent atteindre cet écran. L'écran cherche d'abord dans
`ui.releases.package_refusal`, puis dans `ui.distribution.failure`. Copier
quatre phrases dans un troisième catalogue serait quatre phrases que six
traducteurs tiennent en accord à la main.

### Le nom du fichier téléchargé vient de l'uuid

Pas du titre. Un nom de fichier construit à partir de texte saisi est un nom qui
peut porter une apostrophe, un retour à la ligne et un second en-tête — et un
téléchargement est exactement l'endroit où cela serait cru.

## Une vérification retirée plutôt que gardée

Le contrôleur vérifiait `exists()` avant de lire. Une mutation a montré que
c'était sans effet : `StorageProvider::readStream()` garantit déjà une exception
quand il n'y a rien à lire — il vérifie la ressource elle-même plutôt que de se
fier au réglage `throw` du disque, qui diffère entre les disques livrés. Une
seconde vérification serait une seconde chose à tenir vraie, pour le même refus.

## Vérification

12 tests. 7 mutations, 7 tuées après retrait de l'équivalente.

Deux survivantes au premier tour, toutes deux de vraies lacunes : rien ne
testait qu'une panne de stockage se lise comme « aucun paquet » plutôt que comme
un écran cassé, et rien ne testait que les avertissements atteignent l'écran —
un avertissement que personne ne voit est un avertissement qui n'a pas été
donné.
