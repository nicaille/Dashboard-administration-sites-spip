/**
 * Service worker des alertes du parc.
 *
 * Il ne sert qu'à recevoir : aucun gestionnaire `fetch`, donc aucune requête de
 * la tour ne passe par lui. C'est délibéré — un service worker qui intercepte
 * le réseau devient un cache qu'il faut ensuite savoir vider, et il n'a rien à
 * faire entre l'espace privé et son serveur.
 *
 * Il est servi par une **adresse stable** (`spip.php?action=dashboard_sw`) et
 * non par son chemin de plugin : celui-ci porte le numéro de version, et
 * chaque mise à jour aurait donc fabriqué un enregistrement de plus, laissant
 * les précédents en place avec leurs abonnements.
 */

/* global self, clients */

self.addEventListener('push', function (evenement) {
	var donnees = {
		titre: 'Parc de sites SPIP',
		texte: '',
		url: '/',
		icone: ''
	};

	if (evenement.data) {
		// La charge est du JSON, mais elle vient du réseau : un service worker
		// qui lève une exception dans son gestionnaire `push` perd la
		// notification sans rien afficher. On retombe sur le texte brut.
		try {
			var recu = evenement.data.json();
			for (var clef in recu) {
				if (Object.prototype.hasOwnProperty.call(recu, clef)) { donnees[clef] = recu[clef]; }
			}
		} catch (e) {
			donnees.texte = evenement.data.text();
		}
	}

	var options = {
		body: donnees.texte,
		// Une seule notification de parc à la fois : la nouvelle remplace
		// l'ancienne au lieu de s'empiler. Un parc qui va mal en produirait
		// sinon une par jour, toutes périmées sauf la dernière.
		tag: 'dashboard-parc',
		renotify: true,
		data: { url: donnees.url }
	};
	if (donnees.icone) {
		options.icon = donnees.icone;
		options.badge = donnees.icone;
	}

	// `waitUntil` tient le service worker en vie le temps de l'affichage. Sans
	// lui, le navigateur peut l'arrêter avant, et certains affichent alors une
	// notification générique « Ce site a été mis à jour en arrière-plan ».
	evenement.waitUntil(self.registration.showNotification(donnees.titre, options));
});

self.addEventListener('notificationclick', function (evenement) {
	evenement.notification.close();

	var cible = (evenement.notification.data && evenement.notification.data.url) || '/';

	evenement.waitUntil(
		clients.matchAll({ type: 'window', includeUncontrolled: true }).then(function (fenetres) {
			// Un onglet déjà ouvert sur le parc : on le ramène au premier plan
			// plutôt que d'en ouvrir un deuxième.
			for (var i = 0; i < fenetres.length; i++) {
				if (fenetres[i].url === cible && 'focus' in fenetres[i]) {
					return fenetres[i].focus();
				}
			}
			if (clients.openWindow) {
				return clients.openWindow(cible);
			}
			return null;
		})
	);
});
