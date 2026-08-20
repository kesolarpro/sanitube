# L'écosystème de génération musicale — recherche, août 2026

**Objectif du mandat : la plateforme reste neutre vis-à-vis des fournisseurs, et
Suno ne doit pas devenir la dépendance principale.**

Ce document enregistre ce qui a pu être **vérifié depuis cet environnement**, et
distingue partout les faits des `UNKNOWN`.

## Ce que cet environnement peut atteindre

Sondes directes, avec témoin, exécutées au début de la recherche :

| Hôte | Code |
|---|---|
| `raw.githubusercontent.com` (témoin) | **200** |
| `github.com` | 403 |
| `wavespeed.ai`, `docs.wavespeed.ai`, `api.wavespeed.ai` | échec de connexion |
| `huggingface.co` | échec de connexion |
| `suno.com`, `docs.suno.com` | échec de connexion |
| `api.openai.com` | échec de connexion |
| `docs.anthropic.com` | 403 |

La politique de sortie réseau de cet environnement laisse passer
`raw.githubusercontent.com` et pratiquement rien d'autre. **Cela détermine ce
qui suit** : un projet dont la documentation officielle vit dans son propre
dépôt Git est vérifiable ; un service dont la documentation vit sur son propre
domaine ne l'est pas.

Conformément à l'ADR-0018, un contrat non vérifiable ne produit pas d'adaptateur.

## Candidat 1 — ACE-Step (auto-hébergé) : **CONTRAT VÉRIFIÉ**

Source : dépôt officiel `ace-step/ACE-Step`, lu via `raw.githubusercontent.com`.

### Licence

**Apache 2.0** pour le code (`LICENSE`, lu intégralement).

⚠️ **La licence du *modèle* est distincte de celle du code** et vit sur
HuggingFace, inatteignable d'ici. Les droits commerciaux sur les **sorties**
sont donc `UNKNOWN` depuis cet environnement. Apache 2.0 couvre ce que fait le
programme, pas ce que le modèle produit.

### Capacités (README officiel)

| | |
|---|---|
| Texte → musique | oui |
| Paroles → musique | oui |
| Instrumental | oui |
| Audio → audio | oui |
| Repainting / édition localisée | oui |
| Variations | oui (optimisation à l'inférence, sans entraînement) |
| Clonage de voix, lyric2vocal, singing2accompaniment | oui |
| Langues | 19, dont 10 « performantes » : EN, ZH, RU, ES, JA, DE, FR, PT, IT, KO |
| Durée max | ~4 minutes citées, ~20 s de calcul sur A100 |
| Auto-hébergement | oui, 8 Go de VRAM minimum avec optimisations |
| Séparation de stems | non documentée comme telle |
| Compréhension audio | non documentée comme telle |

### Le contrat HTTP réel

`infer-api.py`, lu en entier. FastAPI, port 8000.

```
POST /generate  → { status, output_path, message }
GET  /health    → { status }
```

La requête porte plus de vingt paramètres, dont `actual_seeds`, `infer_step`,
`guidance_scale`, `scheduler_type`, `cfg_type`, `omega_scale`,
`guidance_interval`, `use_erg_*`, `oss_steps`.

→ **Contrôles déterministes : oui**, explicitement, par graine.

### Trois limites décisives, énoncées franchement

**1. C'est synchrone.** Pas d'identifiant de job, pas de sondage, pas de
webhook, pas d'annulation. `POST /generate` bloque jusqu'à la fin de la
génération.

**2. Il rend un chemin de fichier local**, `output_path`, pas des octets ni une
URL. **Un SaniTube distant ne peut donc pas récupérer l'audio via cette API.**
Toute intégration réelle exige un système de fichiers partagé, un stockage objet
ou un service enveloppant.

**3. C'est un serveur de référence, pas une infrastructure de production.**
`initialize_pipeline()` est appelé **à l'intérieur** du gestionnaire de requête :
le modèle est rechargé à chaque appel. Et `checkpoint_path` comme `device_id`
viennent du **client** — un chemin de système de fichiers contrôlé par
l'appelant, ce qui est un problème de sécurité dès que le port est exposé.

**Conclusion.** ACE-Step est un excellent *modèle* et le seul candidat dont le
contrat soit vérifiable d'ici. Son serveur livré est une démonstration. Un
adaptateur SaniTube auto-hébergé est réalisable, mais vise un service que
l'opérateur doit fournir — pas `infer-api.py` tel quel. C'est un fait sur
l'écosystème, pas une objection au choix.

## Candidat 2 — WaveSpeedAI hébergeant ACE-Step : **NON VÉRIFIABLE ICI**

`wavespeed.ai` et `docs.wavespeed.ai` n'aboutissent pas depuis cet
environnement. Aucun contrat, aucune authentification, aucune limite de débit,
aucun tarif, aucune condition commerciale n'a pu être lu.

Aucun adaptateur n'est écrit sur cette base. `BLOCKED_EXTERNAL_API_ACCESS`.

**C'est un blocage d'environnement, pas un jugement sur le fournisseur.** Le
mandat le désigne comme première implémentation réelle si la recherche le
confirme ; la recherche ne peut pas le confirmer d'ici. Depuis un réseau sans
cette restriction, c'est la première chose à relire.

## Candidat 3 — HeartMuLa : **NON VÉRIFIABLE ICI**

Aucune source officielle atteignable. `UNKNOWN` sur tous les axes.

## Candidat 4 — Suno : **INCHANGÉ, TOUJOURS BLOQUÉ**

`docs.suno.com` n'aboutit pas. La recherche de GEN-005 (`suno-2026-08.md`)
concluait qu'aucun contrat d'API n'était publié ; rien n'a pu être vérifié qui
change cela.

L'ADR-0018 exclut définitivement les enveloppes rétro-conçues, les cookies
récupérés et l'automatisation de navigateur. **Cela reste vrai indépendamment de
l'accès réseau** : c'est une décision d'architecture, pas une conséquence du
pare-feu.

## Ce que cette recherche change dans l'architecture

Un seul candidat a un contrat vérifiable, et il est **synchrone** alors que le
contrat `MusicGenerationProvider` existant suppose l'asynchrone
(`createGeneration` → sondage → `fetchResults`).

C'est le fait le plus utile de toute la recherche : **la plateforme doit
découvrir les capacités d'un fournisseur plutôt que de les supposer.** Un
fournisseur synchrone, un fournisseur sans annulation et un fournisseur sans
webhook sont tous légitimes, et l'orchestration doit pouvoir en choisir un selon
ce dont une intention a besoin.

D'où GEN-006 : un ensemble de capacités interrogeable, et une sélection de
fournisseur qui s'en sert. Pas un adaptateur : rien à quoi en brancher un
honnêtement pour l'instant.

## Tableau de synthèse

| | ACE-Step auto-hébergé | WaveSpeed | HeartMuLa | Suno |
|---|---|---|---|---|
| Contrat officiel lisible d'ici | **oui** | non | non | non |
| Licence du code | Apache 2.0 | UNKNOWN | UNKNOWN | UNKNOWN |
| Droits commerciaux sur les sorties | **UNKNOWN** | UNKNOWN | UNKNOWN | UNKNOWN |
| Async / job / webhook | **non** | UNKNOWN | UNKNOWN | UNKNOWN |
| Annulation | non | UNKNOWN | UNKNOWN | UNKNOWN |
| Déterminisme (graine) | **oui** | UNKNOWN | UNKNOWN | UNKNOWN |
| Auto-hébergeable | **oui** | non | UNKNOWN | non |
| Format de sortie | WAV | UNKNOWN | UNKNOWN | UNKNOWN |
| Coût | néant (matériel) | UNKNOWN | UNKNOWN | UNKNOWN |
| Adaptateur écrit | non — voir limites | non | non | non |

## Action humaine exacte pour débloquer

1. Relire `docs.wavespeed.ai` depuis un réseau sans cette restriction, et
   consigner : authentification, sémantique asynchrone, limites de débit,
   concurrence, formats, rétention des données, **droits commerciaux sur les
   sorties**, et conditions de distribution de musique générée par IA.
2. Confirmer la licence des **poids** d'ACE-Step sur HuggingFace — c'est ce qui
   décide si de la musique auto-hébergée est distribuable.
3. Si un service ACE-Step de production est auto-hébergé, en documenter le
   contrat réel : c'est lui que l'adaptateur vise, pas `infer-api.py`.
