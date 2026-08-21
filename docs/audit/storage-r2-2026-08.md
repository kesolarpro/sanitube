# Audit du chemin de production R2 — août 2026

**Baseline : `main` à `dd4f22e`, vérifiée et non reprise d'un rapport.** 1983
tests passés / 1 ignoré / 25 589 assertions, PHPStan level 6 sans baseline
`[OK]`, Pint `passed`, arbre propre, aucun PR ouvert au démarrage.

Cet audit ne relit pas la documentation. Il suit le chemin réel qu'empruntent
les octets d'un master sur une installation Cloudflare R2 : de l'écran qui
demande les identifiants, jusqu'à l'URL signée qui rend l'audio au navigateur.

---

## Ce qui ne peut pas être audité ici

L'exécution réelle contre R2 est **impossible depuis cet environnement**, et ce
n'est pas une panne réseau : le proxy répond `403` au `CONNECT`.

```
kind:   connect_rejected
detail: gateway answered 403 to CONNECT (policy denial or upstream failure)
host:   pub-…….r2.dev:443
```

Aucune affirmation de cet audit ne repose donc sur un appel à R2, et **aucune
certification R2 n'est revendiquée**. Ce qui suit est vérifié sur le code, la
configuration et les tests. Ce qui exige un bucket réel est listé en fin de
document comme travail de certification, pas comme constat.

---

## Méthode

Le chemin de production, dans l'ordre où un opérateur le rencontre :

1. **Configurer** — l'écran des réglages, les variables d'environnement, les
   disques Laravel.
2. **Déposer** — l'upload direct navigateur → R2, et ce que le serveur en croit.
3. **Conserver** — visibilité des objets, clés, nettoyage du staging.
4. **Rendre** — comment l'audio ressort, et par quelles URL.

Chaque constat cite ce qui l'établit.

---

## F1 — L'écran nommait des variables que rien ne lit  *(corrigé, STO-004, #111)*

Reproduit avant toute modification, sur une installation R2 :

```
config(['storage.default' => 'r2']) → secrets:
  AWS_ACCESS_KEY_ID      configured: false
  AWS_SECRET_ACCESS_KEY  configured: false
  AWS_BUCKET             configured: false
```

Le disque `r2` lit `R2_ACCESS_KEY_ID`, `R2_SECRET_ACCESS_KEY`, `R2_BUCKET`.

**Sévérité : haute, et pas cosmétique.** L'écran lisait la présence sur le bon
disque, donc l'état affiché était juste ; c'est l'instruction qui était fausse.
Un opérateur montant un bucket neuf ne pouvait pas obéir à l'écran, et SET-002
rendait le mauvais nom *inscriptible* — taper une vraie clé R2 la déposait dans
`AWS_ACCESS_KEY_ID`, où elle configurait le disque `s3` que cette installation
ne touche jamais, laissant un identifiant cloud vivant dans un `.env` pour rien.

Corrigé : les noms sont déclarés par fournisseur dans `config/storage.php` et
lus par `ProviderConfiguration`. Le vocabulaire est fermé (`StorageField`), donc
la configuration ne peut pas introduire de champ arbitraire.

---

## F2 — Une méthode qui ne pouvait servir qu'à violer la règle, et que rien n'appelait  *(corrigé, STO-005)*

`StorageProvider` déclarait `url(string $key): string`, documentée
« masters must never be exposed this way ».

- **Aucun appelant de production.** Recherche sur `src/` : la seule occurrence
  hors déclaration est l'implémentation elle-même.
- **Le double de test refusait ; le vrai fournisseur acceptait.**
  `InMemoryStorageProvider::url()` levait une exception, donc *tous* les tests
  voyaient l'implémentation sûre. `FilesystemStorageProvider::url()` renvoyait
  ce que `Storage::url()` lui donnait — et les disques `s3`, `r2` et `b2`
  lisaient `AWS_URL`, `R2_URL`, `B2_URL`. Une variable posée suffisait à obtenir
  une adresse publique, permanente et non expirante pour chaque objet du bucket.

C'est la forme la plus dangereuse de conformité apparente : la garantie était
prouvée sur le seul chemin qui ne s'exécute jamais en production.

Corrigé : la méthode est retirée du contrat et des deux implémentations, les
trois variables ne sont plus lues, et un test interroge le **contrat** — donc un
fournisseur ajouté plus tard hérite de la garantie au lieu d'avoir à s'en
souvenir.

---

## F3 — Les disques ne déclaraient pas leur visibilité  *(corrigé, STO-005)*

`config/filesystems.php` ne posait `visibility: private` que sur `sanitube`.
Les disques `s3`, `r2` et `b2` n'en déclaraient aucune. Le comportement était
correct — le défaut de Flysystem est `private` — mais un défaut non déclaré est
une décision prise par quelqu'un d'autre, révisable par une mise à jour de
dépendance. La documentation affirmait par ailleurs que `r2` et `b2` le
posaient : elle décrivait une intention, pas le fichier.

Corrigé : les trois disques le déclarent, et un test parcourt
`storage.providers` pour l'exiger de chacun.

---

## F4 — L'autorité de l'upload direct : conforme

`BeginDirectUpload` enregistre l'asset **avant** de signer, ce qui décide la clé
depuis l'uuid de l'asset ; l'URL signée ne peut écrire que là, pendant 900
secondes. Le signal de fin ne transporte ni taille, ni empreinte, ni type MIME :
`AssetStorageService::finalizeDirectUpload()` mesure tout depuis l'objet stocké,
par le même code que l'upload à travers PHP.

Le navigateur choisit les octets. Il ne choisit rien d'autre.

---

## F5 — Les clés d'objet ne sortent pas de la couche de lecture : conforme

Aucune requête d'écran ne publie `disk` ni `path`. `AssetIndexQuery` le dit et
le fait ; la vérification par recherche sur `src/Ui/Queries` ne trouve aucune
exposition. Les URL de prévisualisation sont frappées à la demande par
`MintAssetPreviewUrl`, seul point du code qui appelle `temporaryUrl()`.

---

## F6 — Le nettoyage du staging : conforme

`StagingJanitor` et `CleanUpStagingCommand` sont planifiés quotidiennement, avec
un plancher d'âge qui empêche « supprimer tout upload en cours » par faute de
frappe. Les règles de cycle de vie côté bucket restent nécessaires et sont
documentées, y compris le piège des uploads multipart incomplets qui occupent le
stockage sans apparaître dans un listing.

---

## Ce qui reste à certifier contre un vrai bucket

Aucun de ces points n'est un défaut constaté. Ce sont les questions qu'un
environnement sans accès réseau ne peut pas trancher, et elles appartiennent au
runbook de certification :

| # | À vérifier | Pourquoi ça ne se déduit pas |
|---|---|---|
| 1 | Adressage : `R2_USE_PATH_STYLE_ENDPOINT` à `false` ou `true` | R2 accepte les deux formes selon l'endpoint ; seul un `PUT` réel tranche. |
| 2 | CORS sur le bucket | Sans règle, l'upload direct échoue dans le navigateur avec une erreur opaque et **rien n'atteint les logs du serveur**. |
| 3 | Signature d'un `PUT` présigné avec en-têtes | Une signature d'en-tête manquante échoue après le transfert complet du fichier. |
| 4 | Seuil multipart pour un master de 2 Go | Le plafond d'un `PUT` unique est une limite du service, pas du code. |
| 5 | Règles de cycle de vie sur le préfixe de staging | Doit couvrir les uploads multipart incomplets, invisibles dans un listing. |
| 6 | `sanitube:storage:check` contre le vrai bucket | Écriture, lecture, suppression, et disponibilité réelle des URL signées. |

---

## Conclusion

Le chemin de production R2 est **structurellement sain** : l'autorité n'est
jamais déléguée au navigateur, les clés ne fuient pas, le staging est nettoyé,
et l'audio ne sort que par un lien signé qui expire.

Les deux défauts trouvés partageaient une forme : **du code correct sur le
chemin testé et faux sur le chemin réel.** L'écran lisait le bon disque et
nommait la mauvaise variable ; le contrat interdisait l'URL permanente dans sa
documentation et l'offrait dans sa signature, refusée seulement par le double de
test. Les deux sont désormais tenus par des tests qui interrogent la
configuration et le contrat plutôt que l'implémentation la plus commode.

Aucune certification R2 n'est revendiquée : rien ici n'a parlé à R2.
