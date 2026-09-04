# Sécurité

Cet outil donne à un site le pouvoir d'écrire du code sur d'autres sites. C'est
utile, et c'est dangereux. Cette page dit exactement ce qui est protégé, comment,
et ce qui ne l'est pas.

## Modèle de menace

Ce qui est pris au sérieux :

| Menace | Réponse |
|---|---|
| Un tiers découvre l'URL de l'agent | Sans le secret, toute requête est rejetée en 403 |
| Interception réseau | HTTPS obligatoire, vérification du certificat non contournable |
| Rejeu d'une requête capturée | Nonce à usage unique en base + fenêtre temporelle de 5 minutes |
| Falsification d'un argument | La signature couvre l'opération, les arguments, l'horodatage et le nonce |
| Attaque temporelle sur la comparaison de signature | `hash_equals()`, comparaison en temps constant |
| Archive de mise à jour substituée | https imposé, empreinte SHA-256 vérifiable, contenu de l'archive contrôlé |
| Archive piégée (« zip slip ») | Chaque entrée est validée avant extraction ; `..`, chemin absolu ou octet nul refusés |
| Mise à jour ratée | `rename()` réversible, restauration automatique, copies gardées 7 jours |
| Dashboard compromis qui abuserait d'un site | Chaque opération est révocable depuis le site géré |
| Sauvegarde accessible par le web | Stockage sous `tmp/`, hors espace web, plus `.htaccess` de refus |
| Secret lisible dans la base | Chiffré avec le chiffrement du core SPIP quand il est disponible |

Ce qui n'est **pas** couvert :

- **un tour de contrôle compromis**. Il détient les secrets de tout le parc ;
  quiconque en prend le contrôle prend le contrôle du parc. C'est intrinsèque
  au principe même de l'outil, et cela dicte la manière de l'héberger ;
- **un hébergeur hostile** sur le site géré : l'agent s'exécute chez lui ;
- **la qualité des mises à jour elles-mêmes**. L'outil déploie ce qu'on lui dit
  de déployer ; il ne juge pas du contenu.

## Signature des requêtes

Voir [le protocole](protocole-api.md#calcul-de-la-signature) pour la formule.

Points qui comptent :

- le secret fait 256 bits, tiré de `random_bytes()` ;
- il n'est **jamais** transmis, seulement utilisé comme clef HMAC ;
- il n'est affiché qu'une fois, à sa création ; ensuite seule une empreinte
  tronquée est visible, de part et d'autre, ce qui suffit à vérifier que les
  deux extrémités parlent bien du même secret ;
- chaque site a le sien : compromettre un site ne compromet pas les autres.

## Anti-rejeu

Le nonce est la clef primaire de `spip_dashagent_nonces`. C'est **l'échec de
l'insertion** qui détecte le rejeu, pas une lecture préalable : deux requêtes
concurrentes portant le même nonce ne peuvent pas passer toutes les deux, quel
que soit l'entrelacement. Les nonces sont purgés après 24 h.

La fenêtre temporelle borne la durée de vie d'une requête capturée. Elle est
réglable entre 30 et 3600 secondes ; en cas de dérive d'horloge, l'erreur
`horloge` renvoie l'heure de l'agent pour faciliter le diagnostic.

## Autorisations

Sur le **site géré**, configurer l'agent est réservé aux webmestres — pas aux
simples administrateurs. Le raisonnement : l'agent porte des droits d'écriture
sur le code du site, le confier à un statut plus large élargirait silencieusement
ses pouvoirs.

Sur le **tour de contrôle**, trois niveaux :

| Action | Qui |
|---|---|
| Voir le parc et les fiches | administrateurs non restreints |
| Synchroniser (lecture) | administrateurs non restreints |
| Purger, sauvegarder, mettre à jour | webmestres |
| Créer, modifier, supprimer un site | webmestres |

Lire l'état d'un parc et agir dessus ne relèvent volontairement pas du même
droit.

## Ce qu'une mise à jour du core ne touche jamais

`config/`, `IMG/`, `local/`, `tmp/`, `plugins/`, `squelettes/`, `lib/`,
`extensions/`, `sites/`. Cette liste est vérifiée par les tests
(`tests/test_protocole.php`) : aucun répertoire déclaré remplaçable ne peut s'y
trouver.

## Recommandations d'exploitation

1. **Isoler le tour de contrôle.** Idéalement sur un hébergement dédié, avec
   l'espace privé derrière une authentification supplémentaire (`.htpasswd`,
   filtrage par IP, VPN).
2. **Restreindre par IP** côté agents lorsque le tour de contrôle a une adresse
   fixe. La signature reste la protection principale, mais une liste blanche
   réduit la surface exposée.
3. **N'activer les mises à jour que sur les sites où on en a besoin**, et les
   désactiver le reste du temps.
4. **Relire les journaux.** Chaque agent conserve la trace de toutes les
   requêtes reçues, y compris les refus, avec leur origine. Une série de
   `signature_invalide` venant d'une adresse inconnue mérite un regard.
5. **Renouveler les secrets** lors d'un changement d'équipe : générer un nouveau
   secret sur l'agent, le reporter sur la fiche du site. L'ancien cesse
   immédiatement de fonctionner.
6. **Ne pas activer « http en clair »** ailleurs qu'en développement local :
   le secret ne circule pas, mais tout le reste, oui — et une réponse falsifiée
   pourrait faire installer n'importe quoi.

## Signaler un problème

Ouvrir une issue publique pour un bug ordinaire ; pour une faille, contacter
directement le mainteneur du dépôt avant toute divulgation.
