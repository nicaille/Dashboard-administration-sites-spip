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
| `inc/dashagent_base.php` | migration du schéma de base du core, par tranches reprenables |
| `inc/dashagent_serveur.php` | consultation en lecture seule : phpinfo, tables, fichiers de réglage, avec masquage |
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
| `inc/dashboard_chantiers.php` | mises à jour menées par étapes : sauvegarde d'abord, un aller-retour par avancement |
| `action/dashboard_chantier.php` | avancement d'une étape, appelé en boucle par la fiche du site |
| `genie/dashboard_chantiers.php` | reprise des chantiers laissés en plan |
| `action/dashboard_serveur.php` | relais vers les consultations de l'agent, liste d'opérations fermée |

La table `spip_dashboard_sites` est déclarée **deux fois** : dans
`declarer_tables_objets_sql`, qui lui donne la machinerie d'objet éditorial
(statut, titre, formulaire d'édition), et dans `declarer_tables_principales`,
qui est le registre consulté par `maj_tables()` au moment de créer la base.
Sans la seconde, la table n'est simplement jamais créée. Le schéma vit dans
`dashboard_schema_sites()` pour que les deux déclarations ne puissent pas
diverger.

Le plugin ne définit **aucune** fonction `dashboard_site_inserer()`,
`dashboard_site_modifier()` ni équivalent : ce sont les noms que
`objet_inserer()` et `objet_modifier()` cherchent par convention pour ce type
d'objet. En définir une et y appeler l'API générique fait s'appeler les deux
sans fin. Le plugin n'ayant pas de logique d'insertion propre, il s'en tient à
l'API générique.

Aucune colonne ne peut porter un nom préfixé `spip_` : la couche SQL de SPIP
réécrit ces identifiants en noms de tables, et le `CREATE TABLE` devient
invalide. D'où `version_spip` plutôt que `spip_version`.

Quatre tables :

- `spip_dashboard_sites` — l'objet éditorial « site géré », avec le dernier état connu ;
- `spip_dashboard_plugins` — l'inventaire des plugins, remplacé à chaque synchronisation ;
- `spip_dashboard_journal` — ce qui a été fait, par qui, quand, avec quel résultat ;
- `spip_dashboard_sauvegardes` — le catalogue des sauvegardes, distantes ou rapatriées.

## Pourquoi les mises à jour sont des chantiers

Une purge de cache tient dans une requête ; le remplacement d'un noyau ou la
migration d'un schéma, non. Aucune des deux extrémités ne peut garder une
connexion ouverte pendant plusieurs minutes, et un `set_time_limit()` généreux
ne fait que déplacer la coupure — sans jamais permettre de dire à quelle étape
elle est survenue.

Un chantier est donc une ligne de `spip_dashboard_chantiers` portant l'opération,
son étape courante et ce qu'il reste à faire. Un *avancement* exécute une étape
et une seule, avec au plus un aller-retour vers l'agent, puis rend la main. Deux
moteurs poussent ces avancements : le navigateur de celui qui a lancé
l'opération, ce qui donne une progression visible, et une tâche de fond qui
reprend les chantiers abandonnés depuis plus de trois minutes, ce qui garantit
l'aboutissement même si l'onglet est fermé.

Deux étapes savent demander à rester en place plutôt qu'à avancer : la migration
de schéma, que SPIP mène par paliers et interrompt d'elle-même, et la mise à jour
de plusieurs plugins, traités un par un. C'est le même mécanisme — l'étape rend
un drapeau `rester` — et il suffit à couvrir les opérations longues sans qu'aucun
appel ne dépasse quelques dizaines de secondes.

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
