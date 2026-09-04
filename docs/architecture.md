# Architecture

## Pourquoi deux plugins

Le besoin est d'administrer des sites répartis sur **des hébergements
différents**. Cela écarte d'emblée plusieurs approches :

- un accès direct aux bases distantes : presque jamais ouvert en mutualisé ;
- SSH / rsync : indisponible chez la plupart des hébergeurs partagés ;
- un montage de fichiers partagé : hors de question entre hébergeurs.

Le seul canal qui existe partout, c'est **HTTPS vers le site lui-même**. D'où le
découpage : un plugin *agent* sur chaque site géré expose ce qu'il faut par une
URL, un plugin *dashboard* sur le site tour de contrôle consomme cette URL.

## Le plugin `dashboard_agent` (préfixe `dashagent`)

Installé sur chaque site géré. Il expose un point d'entrée unique :

```
https://exemple.org/spip.php?action=dashagent
```

Toutes les requêtes y arrivent en POST, signées. Le routeur
(`action/dashagent.php`) authentifie, autorise, exécute et journalise ; le
travail réel est réparti :

| Fichier | Responsabilité |
|---|---|
| `inc/dashagent_securite.php` | signature HMAC, fenêtre temporelle, anti-rejeu, liste blanche d'IP |
| `inc/dashagent.php` | configuration, chiffrement du secret, journal, réponses JSON |
| `inc/dashagent_infos.php` | inventaire : core, PHP/SQL, plugins, caches, capacités |
| `inc/dashagent_cache.php` | purge des caches, cible par cible |
| `inc/dashagent_sauvegarde.php` | export SQL gzip streamé, rétention, diffusion |
| `inc/dashagent_maj.php` | mise à jour des plugins et du core, avec rollback |
| `inc/dashagent_fs.php` | mesure, copie, suppression, téléchargement, dézippage sûr |

Deux tables seulement : `spip_dashagent_journal` (piste d'audit) et
`spip_dashagent_nonces` (anti-rejeu). L'agent ne stocke rien d'autre.

## Le plugin `dashboard` (préfixe `dashboard`)

Installé une seule fois, sur la tour de contrôle.

| Fichier | Responsabilité |
|---|---|
| `inc/dashboard_client.php` | signature et transport HTTP ; **seul** endroit d'où partent des requêtes |
| `inc/dashboard_sync.php` | interrogation d'un site et persistance de son inventaire |
| `inc/dashboard_operations.php` | purge, sauvegarde, mises à jour ; format de résultat unique |
| `inc/dashboard_versions.php` | quelle version de SPIP est disponible pour quelle branche |
| `inc/dashboard_journal.php` | journal des opérations, côté tour de contrôle |
| `genie/dashboard_sync.php` | synchronisation périodique, par lots |

Quatre tables :

- `spip_dashboard_sites` — l'objet éditorial « site géré », avec le dernier état connu ;
- `spip_dashboard_plugins` — l'inventaire des plugins, remplacé à chaque synchronisation ;
- `spip_dashboard_journal` — ce qui a été fait, par qui, quand, avec quel résultat ;
- `spip_dashboard_sauvegardes` — le catalogue des sauvegardes, distantes ou rapatriées.

## Flux d'une opération

```
Espace privé                Tour de contrôle                    Site géré
    │                             │                                 │
    │ clic « Mettre à jour »      │                                 │
    ├────────────────────────────►│                                 │
    │                             │ dashboard_signer()              │
    │                             │ POST op=plugin_maj + sig        │
    │                             ├────────────────────────────────►│
    │                             │                                 │ vérif. signature
    │                             │                                 │ vérif. horloge
    │                             │                                 │ consommation nonce
    │                             │                                 │ opération autorisée ?
    │                             │                                 │ téléchargement + contrôle
    │                             │                                 │ rename() atomique
    │                             │◄────────────────────────────────┤ JSON
    │                             │ journalisation + resynchro      │
    │◄────────────────────────────┤                                 │
    │ message + fiche à jour      │                                 │
```

## Principes tenus dans tout le code

1. **Le site géré garde le dernier mot.** Chaque opération est activable ou
   désactivable localement. Le dashboard ne peut jamais s'octroyer un droit que
   l'administrateur du site n'a pas accordé.
2. **On ne détruit pas avant d'avoir validé.** Une mise à jour télécharge,
   vérifie l'empreinte, vérifie le contenu de l'archive, puis seulement échange
   les répertoires — par `rename()`, réversible.
3. **Une seule définition de la signature par extrémité.** `dashboard_signer()`
   et `dashagent_signer()` se répondent ; les tests vérifient qu'elles
   produisent le même résultat.
4. **Rien de sensible ne transite deux fois.** Un secret partagé n'est affiché
   qu'une seule fois, à sa création ; ensuite seule son empreinte est visible.
