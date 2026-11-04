/**
 * Opérations de parc : une file de sites, un aller-retour à la fois.
 *
 * Le principe tient en une phrase : le serveur ne traite jamais qu'un site par
 * requête, et c'est cette page qui enchaîne. Faire le tour d'un parc en une
 * requête serait la coupure assurée — et l'impossibilité de dire où elle a eu
 * lieu.
 *
 * Le contrat de réponse est le même pour toutes les opérations : tant que
 * `termine` est faux, on rappelle **la même adresse**. Une opération en
 * plusieurs temps — relire six dépôts, mener un chantier de cinq étapes — tient
 * ainsi dans une boucle qui n'en sait rien.
 *
 * Aucune adresse n'est fabriquée ici : chacune est signée pour son site et pour
 * son opération, et arrive dans la page par une liste que le squelette écrit.
 * Un site que l'utilisateur n'a pas le droit de toucher n'y figure pas, et
 * aucune case à cocher ne saurait l'y mettre.
 *
 * Sans JavaScript, ces boutons ne font rien et rien n'est perdu : la
 * synchronisation de fond relit les catalogues trop anciens d'elle-même, et la
 * tâche de fond reprend les chantiers laissés en plan.
 */
(function () {
	'use strict';

	var racine = document.querySelector('[data-dashboard-parc]');
	if (!racine) {
		return;
	}

	/**
	 * Les sites d'une file, indexés par identifiant.
	 *
	 * @param {string} nom Nom de l'opération
	 * @return {Object} id → {url, titre}
	 */
	function file(nom) {
		var liste = racine.querySelector('[data-parc-file="' + nom + '"]');
		var sites = {};
		if (!liste) {
			return sites;
		}
		Array.prototype.forEach.call(liste.querySelectorAll('li[data-site]'), function (li) {
			var lien = li.querySelector('a');
			if (lien) {
				sites[li.getAttribute('data-site')] = {
					url: lien.getAttribute('href'),
					titre: lien.textContent.trim()
				};
			}
		});

		return sites;
	}

	/*
	 * Chaque bloc rend compte chez lui. Un seul compte rendu pour toute la page
	 * obligerait à chercher des yeux, à l'autre bout de l'écran, ce que le
	 * bouton qu'on vient de cliquer est en train de faire — et deux régions
	 * `aria-live` concurrentes se couperaient la parole.
	 */
	function sortieDe(bouton) {
		var bloc = bouton.closest('[data-parc-bloc]');

		return (bloc && bloc.querySelector('[data-parc-avancement]'))
			|| racine.querySelector('[data-parc-avancement]');
	}

	function direDans(sortie, texte) {
		if (sortie) {
			sortie.textContent = texte;
		}
	}

	var enCours = false;

	/** Tous les boutons d'opération, pour les figer pendant un tour. */
	var boutons = Array.prototype.slice.call(racine.querySelectorAll('[data-parc-action]'));

	function figer(oui) {
		enCours = oui;
		boutons.forEach(function (b) { b.disabled = oui; });
		if (!oui) {
			rafraichir();
		}
	}

	/**
	 * Déroule une file, un site après l'autre.
	 *
	 * @param {Array} etapes  Liste de {url, titre}
	 * @param {number} toursMax Rappels tolérés sur un même site
	 */
	function derouler(etapes, toursMax, sortie) {
		var tours = 0;

		function dire(texte) { direDans(sortie, texte); }

		function avancer(rang) {
			if (rang >= etapes.length) {
				dire('Terminé, actualisation…');
				window.location.reload();
				return;
			}

			// Un site ne peut pas retenir le tour indéfiniment. Le nombre de
			// rappels dépend de ce que le site géré répond ; si l'un d'eux reste
			// annoncé « pas terminé » sans avancer — un catalogue illisible, un
			// chantier qui rejoue sa propre étape —, la boucle tournerait sans
			// fin sur lui, et le parc ne serait jamais parcouru. Passé ce
			// nombre, on le laisse et on continue.
			if (tours > toursMax) {
				dire('(' + (rang + 1) + '/' + etapes.length + ') ' + etapes[rang].titre
					+ ' : trop long, on passe au site suivant');
				tours = 0;
				window.setTimeout(function () { avancer(rang + 1); }, 800);
				return;
			}
			tours++;
			dire('(' + (rang + 1) + '/' + etapes.length + ') ' + etapes[rang].titre + '…');

			fetch(etapes[rang].url, {
				credentials: 'same-origin',
				headers: { 'X-Requested-With': 'XMLHttpRequest' }
			})
				.then(function (r) {
					if (!r.ok) { throw new Error('HTTP ' + r.status); }
					return r.json();
				})
				.then(function (etat) {
					if (etat.message) {
						dire('(' + (rang + 1) + '/' + etapes.length + ') ' + etat.message);
					}
					if (etat.termine) { tours = 0; }
					avancer(etat.termine ? rang + 1 : rang);
				})
				.catch(function () {
					// Un site injoignable n'arrête pas le tour. Il n'est pas non
					// plus déclaré en échec : une mise à jour d'agent fait taire
					// le site par construction, et ce silence ne dit rien de son
					// issue. Le prochain inventaire tranchera.
					dire('(' + (rang + 1) + '/' + etapes.length + ') ' + etapes[rang].titre
						+ ' : sans réponse');
					tours = 0;
					window.setTimeout(function () { avancer(rang + 1); }, 800);
				});
		}

		figer(true);
		avancer(0);
	}

	/** Les identifiants cochés, dans l'ordre du tableau. */
	function coches() {
		return Array.prototype.filter.call(
			racine.querySelectorAll('[data-parc-site]'),
			function (c) { return c.checked; }
		).map(function (c) { return c.getAttribute('data-parc-site'); });
	}

	var compte = racine.querySelector('[data-parc-compte]');
	var tout = racine.querySelector('[data-parc-tout]');

	/** Met la barre d'actions au diapason de ce qui est coché. */
	function rafraichir() {
		var n = coches().length;
		if (compte) {
			compte.textContent = compte.getAttribute('data-gabarit').replace('@nb@', n);
		}
		boutons.forEach(function (b) {
			// Le bouton de l'agent porte sur tout le parc : la sélection ne le
			// concerne pas.
			if (b.getAttribute('data-parc-selection') === 'oui') {
				b.disabled = enCours || n === 0;
			}
		});
		if (tout) {
			var cases = racine.querySelectorAll('[data-parc-site]');
			tout.checked = (n > 0 && n === cases.length);
			tout.indeterminate = (n > 0 && n < cases.length);
		}
	}

	Array.prototype.forEach.call(racine.querySelectorAll('[data-parc-site]'), function (c) {
		c.addEventListener('change', rafraichir);
	});

	if (tout) {
		tout.addEventListener('change', function () {
			Array.prototype.forEach.call(racine.querySelectorAll('[data-parc-site]'), function (c) {
				c.checked = tout.checked;
			});
			rafraichir();
		});
	}

	boutons.forEach(function (bouton) {
		bouton.addEventListener('click', function () {
			if (enCours) { return; }

			var operation = bouton.getAttribute('data-parc-action');
			var sites = file(operation);
			var etapes = [];
			var ignores = 0;

			if (bouton.getAttribute('data-parc-selection') === 'oui') {
				coches().forEach(function (id) {
					// Un site coché sans adresse dans cette file est un site que
					// l'utilisateur ne peut pas opérer, ou qui est en pause. La
					// case ne crée aucun droit : elle ne fait que désigner.
					if (sites[id]) { etapes.push(sites[id]); } else { ignores++; }
				});
			} else {
				Object.keys(sites).forEach(function (id) { etapes.push(sites[id]); });
			}

			if (!etapes.length) {
				direDans(sortieDe(bouton),
					ignores ? 'Aucun de ces sites ne peut recevoir cette opération.' : 'Rien à faire.');
				return;
			}

			var confirmation = bouton.getAttribute('data-parc-confirmer');
			if (confirmation && !window.confirm(confirmation.replace('@nb@', etapes.length))) {
				return;
			}

			var sortie = sortieDe(bouton);

			// Dit avant de commencer : après, le premier site aura déjà pris la
			// parole et cet avertissement passerait inaperçu.
			if (ignores) {
				direDans(sortie, ignores + ' site(s) écarté(s), sans droit ou en pause.');
			}
			derouler(etapes, parseInt(bouton.getAttribute('data-parc-tours'), 10) || 20, sortie);
		});
	});

	rafraichir();
})();
