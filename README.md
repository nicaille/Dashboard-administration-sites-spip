# Dashboard d'administration de sites SPIP

Un site SPIP qui sert de **tour de contrôle** pour un parc d'autres sites SPIP,
hébergés n'importe où, chez des hébergeurs différents, sans accès SSH.

Depuis une seule interface :

- vue d'ensemble du parc : version du core, plugins installés et leurs versions ;
- signalement des mises à jour disponibles, côté plugins et côté core ;
- mise à jour à distance des plugins, unitairement ou en lot ;
- mise à jour à distance du core SPIP, avec retour arrière possible ;
- sauvegarde de la base de données, rapatriée et téléchargeable ;
- purge sélective des caches : pages, squelettes, images calculées, CSS/JS, sessions.

## Comment ça marche

L'outil est fait de **deux plugins** :

| Plugin | Où on l'installe | Rôle |
|---|---|---|
| `plugins/dashboard` | sur le site tour de contrôle, un seul | interface, inventaire, déclenchement des opérations |
| `plugins/dashboard_agent` | sur **chaque** site géré | expose un point d'entrée JSON signé et exécute les opérations |

Le dashboard n'a besoin ni de SSH, ni de FTP, ni d'accès à la base des sites
gérés : il dialogue en HTTPS avec l'agent, chaque requête étant signée en
HMAC-SHA256 avec un secret partagé propre à chaque site.

```
   ┌──────────────────────────┐            ┌──────────────────────────┐
   │  Site tour de contrôle   │  HTTPS +   │   Site géré n°1          │
   │  plugin « dashboard »    │  HMAC      │   plugin « dashagent »   │
   │                          │───────────►│   spip.php?action=…      │
   │  - parc                  │◄───────────│                          │
   │  - journal               │   JSON     └──────────────────────────┘
   │  - sauvegardes           │            ┌──────────────────────────┐
   │                          │───────────►│   Site géré n°2 …        │
   └──────────────────────────┘            └──────────────────────────┘
```

## Installation rapide

1. Copier `plugins/dashboard` dans le `plugins/` du site tour de contrôle, l'activer.
2. Copier `plugins/dashboard_agent` dans le `plugins/` de **chaque** site à gérer, l'activer.
3. Sur un site géré : *Configuration → Dashboard : agent*, générer un secret,
   cocher les opérations autorisées, noter l'URL de l'agent.
4. Sur le tour de contrôle : *Édition → Parc de sites SPIP → Ajouter un site*,
   coller l'URL de l'agent et le secret.
5. Cliquer sur **Synchroniser**.

Le détail est dans [docs/installation.md](docs/installation.md).

## Documentation

- [Architecture](docs/architecture.md) — pourquoi deux plugins, ce que fait chacun
- [Installation et appairage](docs/installation.md)
- [Protocole d'API](docs/protocole-api.md) — opérations, signature, codes d'erreur
- [Sécurité](docs/securite.md) — modèle de menace et garde-fous
- [Exploitation](docs/exploitation.md) — usage quotidien, sauvegardes, restauration, dépannage

## Tests

Les fonctions critiques (signature, filtrage IP, validation des archives,
protection contre les chemins traversants) sont vérifiables sans installation SPIP :

```bash
php tests/test_protocole.php
```

## Compatibilité

SPIP 4.1 à 4.4, PHP 7.4+. L'agent fonctionne en hébergement mutualisé : il ne
suppose ni `exec()`, ni `mysqldump`, ni accès au système de fichiers hors du site.

## Licence

GPL v3 — voir [LICENSE](LICENSE).
