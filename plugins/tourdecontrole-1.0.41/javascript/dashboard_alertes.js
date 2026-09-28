/**
 * Abonnement du navigateur aux alertes du parc.
 *
 * Le Web Push demande trois choses, dans cet ordre, et aucune ne se saute :
 * l'accord de la personne, un service worker enregistré, un abonnement pris
 * auprès du service de distribution du navigateur. La dernière étape rend une
 * adresse et deux clefs, qu'on confie au serveur.
 *
 * Aucune adresse d'action n'est fabriquée ici : elles sont écrites signées dans
 * la page, comme partout ailleurs dans ce plugin.
 */
(function () {
	'use strict';

	var bloc = document.querySelector('[data-dashboard-alertes]');
	if (!bloc) { return; }

	var urlAbonner = bloc.getAttribute('data-abonner');
	var urlSw      = bloc.getAttribute('data-sw');
	var portee     = bloc.getAttribute('data-portee');
	var clefVapid  = bloc.getAttribute('data-clef');

	var bouton = bloc.querySelector('[data-alertes-bouton]');
	var etat   = bloc.querySelector('[data-alertes-etat]');

	function dire(texte) {
		if (etat) { etat.textContent = texte; }
	}

	/** Base64url vers Uint8Array, ce qu'attend applicationServerKey. */
	function enOctets(b64url) {
		var b64 = (b64url + '='.repeat((4 - b64url.length % 4) % 4))
			.replace(/-/g, '+').replace(/_/g, '/');
		var brut = window.atob(b64);
		var octets = new Uint8Array(brut.length);
		for (var i = 0; i < brut.length; i++) { octets[i] = brut.charCodeAt(i); }
		return octets;
	}

	/** Un ArrayBuffer d'abonnement vers du base64url. */
	function enB64(tampon) {
		var octets = new Uint8Array(tampon);
		var brut = '';
		for (var i = 0; i < octets.length; i++) { brut += String.fromCharCode(octets[i]); }
		return window.btoa(brut).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
	}

	var possible = ('serviceWorker' in navigator)
		&& ('PushManager' in window)
		&& ('Notification' in window);

	if (!possible) {
		// Le cas d'iOS avant la 16.4, et celui d'un site servi en http : le Web
		// Push exige une origine sûre. Le dire plutôt que de laisser un bouton
		// qui ne ferait rien.
		if (bouton) { bouton.disabled = true; }
		dire(bloc.getAttribute('data-sans-support') || '');
		return;
	}

	function envoyer(donnees) {
		var corps = new FormData();
		Object.keys(donnees).forEach(function (clef) { corps.append(clef, donnees[clef]); });

		return fetch(urlAbonner, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'X-Requested-With': 'XMLHttpRequest' },
			body: corps
		}).then(function (r) { return r.json(); });
	}

	/** Met le bouton au diapason de l'abonnement en cours. */
	function rafraichir(abonnement) {
		if (!bouton) { return; }
		bouton.disabled = false;
		bouton.setAttribute('data-abonne', abonnement ? 'oui' : 'non');
		bouton.textContent = bouton.getAttribute(abonnement ? 'data-libelle-off' : 'data-libelle-on');
	}

	navigator.serviceWorker.register(urlSw, { scope: portee })
		.then(function (enregistrement) {
			return enregistrement.pushManager.getSubscription();
		})
		.then(rafraichir)
		.catch(function (e) {
			if (bouton) { bouton.disabled = true; }
			dire(String(e && e.message ? e.message : e));
		});

	if (!bouton) { return; }

	bouton.addEventListener('click', function () {
		bouton.disabled = true;

		navigator.serviceWorker.ready.then(function (enregistrement) {
			return enregistrement.pushManager.getSubscription().then(function (abonnement) {

				// --- Se désabonner -------------------------------------------
				if (abonnement) {
					var adresse = abonnement.endpoint;
					return abonnement.unsubscribe()
						.then(function () { return envoyer({ endpoint: adresse, retirer: 'oui' }); })
						.then(function (r) { dire(r.message || ''); rafraichir(null); });
				}

				// --- S'abonner -----------------------------------------------
				// L'accord se demande ici, sur un clic, et non au chargement de
				// la page : les navigateurs refusent — ou pire, bloquent pour
				// toujours — une demande qui ne suit pas un geste.
				return Notification.requestPermission().then(function (accord) {
					if (accord !== 'granted') {
						dire(bloc.getAttribute('data-refus') || '');
						rafraichir(null);
						return null;
					}

					return enregistrement.pushManager.subscribe({
						// Obligatoire : le navigateur exige qu'une notification
						// soit montrée pour chaque message reçu. Un abonnement
						// silencieux serait refusé.
						userVisibleOnly: true,
						applicationServerKey: enOctets(clefVapid)
					}).then(function (nouveau) {
						var brut = nouveau.toJSON();
						return envoyer({
							endpoint: nouveau.endpoint,
							p256dh: brut.keys ? brut.keys.p256dh : enB64(nouveau.getKey('p256dh')),
							auth: brut.keys ? brut.keys.auth : enB64(nouveau.getKey('auth'))
						}).then(function (r) {
							dire(r.message || '');
							// Le serveur a refusé : on ne garde pas un abonnement
							// dont il n'a pas trace, sinon le navigateur se croit
							// abonné et n'entendra jamais rien.
							if (!r.ok) {
								return nouveau.unsubscribe().then(function () { rafraichir(null); });
							}
							rafraichir(nouveau);
							return null;
						});
					});
				});
			});
		}).catch(function (e) {
			dire(String(e && e.message ? e.message : e));
			bouton.disabled = false;
		});
	});
})();
