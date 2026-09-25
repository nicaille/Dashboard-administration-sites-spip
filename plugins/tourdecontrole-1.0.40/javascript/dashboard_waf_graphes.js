/**
 * Tendance du WAF : deux graphiques empilés, et un sélecteur de fenêtre.
 *
 * Deux graphiques séparés plutôt qu'un seul à double axe — et ce n'est pas une
 * affaire de goût. « Requêtes bloquées » se compte par centaines quand
 * « adresses bloquées » se compte sur les doigts : sur un axe commun, la seconde
 * courbe resterait écrasée au ras de l'abscisse. Et deux axes sur un même cadre
 * feraient se croiser des courbes qui ne se comparent pas, ce qui donne à voir
 * des coïncidences qui n'existent pas.
 *
 * La page reçoit quatre-vingt-dix jours une seule fois ; le sélecteur y découpe
 * sept ou trente jours sans requête ni rechargement.
 *
 * Les couleurs viennent de variables CSS, jamais du JavaScript : le thème sombre
 * de l'espace privé les redéfinit, et le graphique suit sans le savoir.
 */
(function () {
	'use strict';

	if (typeof Chart === 'undefined') {
		return;
	}

	/** Lit une variable CSS sur un élément, avec une valeur de repli. */
	function couleur(element, nom, repli) {
		var v = getComputedStyle(element).getPropertyValue(nom);

		return v ? v.trim() : repli;
	}

	/** « 2026-09-18 » → « 18/09 », ce qui tient sous un axe de trente colonnes. */
	function jourCourt(iso) {
		var m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(iso);

		return m ? m[3] + '/' + m[2] : iso;
	}

	/**
	 * Construit la vue en tableau qui double chaque graphique.
	 *
	 * Un graphique seul laisse sur le bord de la route qui ne le voit pas, et
	 * qui veut le chiffre exact. Le tableau est replié par défaut : il double la
	 * courbe, il ne l'encombre pas.
	 */
	function tableau(hote, points, libelles) {
		var details = document.createElement('details');
		details.className = 'dashboard-graphe-tableau';
		var resume = document.createElement('summary');
		resume.textContent = libelles.tableau;
		details.appendChild(resume);

		var table = document.createElement('table');
		table.className = 'spip';
		var thead = document.createElement('thead');
		var ligne = document.createElement('tr');
		[libelles.jour, libelles.requetes, libelles.ips].forEach(function (titre) {
			var th = document.createElement('th');
			th.setAttribute('scope', 'col');
			th.textContent = titre;
			ligne.appendChild(th);
		});
		thead.appendChild(ligne);
		table.appendChild(thead);

		var tbody = document.createElement('tbody');
		points.forEach(function (p) {
			var tr = document.createElement('tr');
			[p.jour, String(p.requetes), String(p.ips)].forEach(function (valeur) {
				var td = document.createElement('td');
				td.textContent = valeur;
				tr.appendChild(td);
			});
			tbody.appendChild(tr);
		});
		table.appendChild(tbody);
		details.appendChild(table);
		hote.appendChild(details);

		return tbody;
	}

	/** Les réglages communs aux deux graphiques. */
	function options(bloc, titre, libelleY) {
		var encre  = couleur(bloc, '--dashboard-graphe-encre', '#52514e');
		var grille = couleur(bloc, '--dashboard-graphe-grille', '#e6e5e0');

		return {
			responsive: true,
			maintainAspectRatio: false,
			// Le survol accroche la colonne entière, pas le point : viser un
			// point de deux pixels à la souris n'est pas une interface.
			interaction: { mode: 'index', intersect: false },
			plugins: {
				legend: { display: false },
				title: {
					display: true,
					text: titre,
					color: encre,
					font: { size: 14, weight: '600' },
					padding: { bottom: 8 }
				},
				tooltip: {
					displayColors: false,
					callbacks: {
						label: function (ctx) {
							return ctx.parsed.y + ' ' + libelleY;
						}
					}
				}
			},
			scales: {
				x: {
					grid: { display: false },
					ticks: { color: encre, maxRotation: 0, autoSkipPadding: 12 }
				},
				y: {
					beginAtZero: true,
					grid: { color: grille },
					border: { display: false },
					// Des entiers : une demi-requête bloquée ne veut rien dire.
					ticks: { color: encre, precision: 0 }
				}
			}
		};
	}

	/** Un graphique, une série. */
	function tracer(canvas, points, clef, teinte, titre, unite) {
		return new Chart(canvas, {
			type: 'line',
			data: {
				labels: points.map(function (p) { return jourCourt(p.jour); }),
				datasets: [{
					data: points.map(function (p) { return p[clef]; }),
					borderColor: teinte,
					backgroundColor: teinte + '22',
					borderWidth: 2,
					fill: true,
					tension: 0.25,
					pointRadius: 0,
					pointHoverRadius: 5,
					// La cible du survol est plus large que la marque.
					pointHitRadius: 14
				}]
			},
			options: options(canvas.parentNode, titre, unite)
		});
	}

	document.querySelectorAll('[data-dashboard-waf-graphe]').forEach(function (bloc) {
		var source = bloc.querySelector('script[type="application/json"]');
		if (!source) {
			return;
		}

		var points;
		try {
			points = (JSON.parse(source.textContent) || {}).points || [];
		} catch (e) {
			return;
		}
		if (!points.length) {
			return;
		}

		var libelles = {
			requetes: bloc.getAttribute('data-libelle-requetes') || 'Requêtes bloquées',
			ips:      bloc.getAttribute('data-libelle-ips') || 'Adresses bloquées',
			jour:     bloc.getAttribute('data-libelle-jour') || 'Jour',
			tableau:  bloc.getAttribute('data-libelle-tableau') || 'Voir les chiffres'
		};

		var canvasRequetes = bloc.querySelector('[data-graphe="requetes"]');
		var canvasIps      = bloc.querySelector('[data-graphe="ips"]');
		if (!canvasRequetes || !canvasIps) {
			return;
		}

		var bleu   = couleur(bloc, '--dashboard-graphe-requetes', '#2a78d6');
		var orange = couleur(bloc, '--dashboard-graphe-ips', '#eb6834');

		var fenetre = parseInt(bloc.getAttribute('data-jours'), 10) || 30;
		var vus = points.slice(-fenetre);

		var graphes = [
			tracer(canvasRequetes, vus, 'requetes', bleu, libelles.requetes, libelles.requetes.toLowerCase()),
			tracer(canvasIps, vus, 'ips', orange, libelles.ips, libelles.ips.toLowerCase())
		];

		var corps = tableau(bloc, vus, libelles);

		/** Rejoue les deux graphiques et le tableau sur une nouvelle fenêtre. */
		function redessiner(jours) {
			var retenus = points.slice(-jours);
			var etiquettes = retenus.map(function (p) { return jourCourt(p.jour); });

			graphes.forEach(function (graphe, i) {
				graphe.data.labels = etiquettes;
				graphe.data.datasets[0].data = retenus.map(function (p) {
					return i === 0 ? p.requetes : p.ips;
				});
				graphe.update();
			});

			corps.textContent = '';
			retenus.forEach(function (p) {
				var tr = document.createElement('tr');
				[p.jour, String(p.requetes), String(p.ips)].forEach(function (valeur) {
					var td = document.createElement('td');
					td.textContent = valeur;
					tr.appendChild(td);
				});
				corps.appendChild(tr);
			});
		}

		bloc.querySelectorAll('[data-fenetre]').forEach(function (bouton) {
			bouton.addEventListener('click', function () {
				var jours = parseInt(bouton.getAttribute('data-fenetre'), 10) || 30;
				bloc.querySelectorAll('[data-fenetre]').forEach(function (autre) {
					autre.setAttribute('aria-pressed', autre === bouton ? 'true' : 'false');
				});
				redessiner(jours);
			});
		});
	});
})();
