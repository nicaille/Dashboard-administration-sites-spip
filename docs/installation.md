# Installation et appairage

## Pré-requis

- SPIP 4.1 à 4.4 des deux côtés, PHP 7.4 ou plus récent ;
- **HTTPS avec un certificat valide** sur chaque site géré : la vérification du
  certificat n'est pas contournable, et c'est voulu ;
- l'extension PHP `zip` sur les sites gérés, pour les mises à jour ;
- l'extension `zlib` sur les sites gérés, pour les sauvegardes ;
- de préférence l'extension `sodium` des deux côtés, pour que les secrets soient
  chiffrés en base plutôt que stockés en clair.

Aucun accès SSH, FTP ou base distante n'est nécessaire.

## 1. Le site tour de contrôle

Copier `plugins/dashboard` dans le répertoire `plugins/` du site qui pilotera le
parc, puis l'activer dans *Configuration → Gestion des plugins*.

Ce site doit être **au moins aussi protégé que le plus sensible des sites qu'il
administre** : il détient tous les secrets du parc. Voir [Sécurité](securite.md).

Réglages dans *Configuration → Dashboard : configuration* :

| Réglage | Valeur conseillée |
|---|---|
| Délai d'attente courant | 30 s |
| Délai des opérations longues | 300 s |
| Synchroniser automatiquement | activé |
| Fréquence | 6 h |
| Sites par passage | 10 (à baisser si le cron est court) |
| Sauvegarder avant mise à jour du core | activé |

## 2. Chaque site géré

Copier `plugins/dashboard_agent` dans son répertoire `plugins/`, puis l'activer.

Tant qu'aucun secret n'est configuré, **l'agent refuse toutes les requêtes** :
installer le plugin n'ouvre rien par lui-même.

## 3. Appairage

L'appairage consiste à faire partager un secret aux deux extrémités. Deux sens
possibles, au choix ; le plus simple est de partir du site géré.

### Depuis le site géré (recommandé)

1. Sur le site géré, aller dans *Configuration → Dashboard : agent*.
2. Cocher **Générer un nouveau secret partagé**, cocher les opérations à
   autoriser, enregistrer.
3. Le secret s'affiche **une seule fois** dans le message de confirmation.
   Le copier immédiatement.
4. Noter aussi l'URL de l'agent affichée en haut de la page, de la forme
   `https://exemple.org/spip.php?action=dashagent`.
5. Sur le tour de contrôle : *Édition → Parc de sites SPIP → Ajouter un site*.
   Renseigner le nom, l'adresse publique, l'URL de l'agent, coller le secret,
   enregistrer.
6. Cliquer sur **Synchroniser**. La fiche doit se remplir immédiatement.

### Depuis le tour de contrôle

Créer d'abord la fiche du site en cochant **Générer un nouveau secret partagé**,
puis recopier le secret affiché dans le champ *Secret partagé* de la page de
configuration de l'agent, sur le site géré.

## 4. Choisir les opérations autorisées

Sur chaque site géré, la page de configuration de l'agent liste les opérations
que le dashboard aura le droit de déclencher. Par défaut :

| Opération | Défaut | Commentaire |
|---|---|---|
| Inventaire | activée | lecture seule |
| Vider les caches | activée | sans effet de bord durable |
| Sauvegarde de la base | activée | coûteuse en temps sur une grosse base |
| Mise à jour des plugins | **désactivée** | écrit du code sur le site |
| Mise à jour du core | **désactivée** | écrit du code sur le site |

Les deux dernières sont à n'activer qu'en connaissance de cause. Elles restent
révocables à tout instant depuis le site géré, sans rien changer côté dashboard.

## 5. Vérifier que tout va bien

Sur la fiche du site, après une synchronisation :

- la pastille d'état est verte ;
- la version de SPIP, celle de PHP et celle de la base sont renseignées ;
- la liste des plugins est peuplée ;
- le tableau des caches affiche des tailles.

En cas d'échec, le message d'erreur est affiché en haut de la fiche et
enregistré dans le journal des opérations. Voir la section dépannage de
[Exploitation](exploitation.md).

## 6. Le cron

La synchronisation automatique passe par les tâches périodiques de SPIP. Sur un
site peu visité, elles ne se déclenchent pas toutes seules : penser à appeler
`spip.php?action=cron` par un cron système sur le tour de contrôle.

```
*/15 * * * * curl -s https://tour-de-controle.org/spip.php?action=cron > /dev/null
```

Les sites gérés ont eux aussi une tâche d'entretien quotidienne, qui purge le
journal de l'agent, les nonces périmés, les vieilles sauvegardes locales et les
copies de sécurité laissées par les mises à jour.
