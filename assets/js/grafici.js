/**
 * DB Stazione Meteo — grafici dello shortcode [dbmt_grafici].
 *
 * Tre grafici a linea con lo stesso asse dei tempi (temperatura, umidità,
 * pressione): grandezze con unità diverse non condividono mai un asse.
 * Una linea per nodo, con il colore del suo numero; fascia minima–massima
 * sui periodi lunghi; linea interrotta dove mancano dati; crosshair comune
 * con un solo tooltip; tabella dei dati come alternativa accessibile.
 *
 * SVG scritto a mano, senza librerie né CDN: niente richieste a server
 * esterni dal sito della scuola.
 */
(function () {
	'use strict';

	var SVG = 'http://www.w3.org/2000/svg';
	var ALTEZZA = 150;
	var M = { sx: 44, dx: 72, su: 10, giu: 8 };   // margini dell'area dati
	var ASSE_X = 22;                                // spazio per le etichette dei tempi
	var GRANDEZZE = [
		{ chiave: 'temperatura', i: 1, unita: '°C', dec: 1, ampiezza: 2 },
		{ chiave: 'umidita', i: 4, unita: '%', dec: 0, ampiezza: 10 },
		{ chiave: 'pressione', i: 7, unita: 'hPa', dec: 1, ampiezza: 4 }
	];

	function el(nome, attr, padre) {
		var e = document.createElementNS(SVG, nome);
		Object.keys(attr || {}).forEach(function (k) { e.setAttribute(k, attr[k]); });
		if (padre) { padre.appendChild(e); }
		return e;
	}

	function html(nome, classe, testo, padre) {
		var e = document.createElement(nome);
		if (classe) { e.className = classe; }
		if (testo !== undefined && testo !== null) { e.textContent = testo; }
		if (padre) { padre.appendChild(e); }
		return e;
	}

	// Colore del nodo: segue il numero, non la posizione, così non cambia
	// quando compaiono o spariscono altri nodi
	function colore(nodo) { return 'var(--dbmt-s' + (((nodo - 1) % 8) + 1) + ')'; }

	// Valori "puliti" per l'asse: passi di 1, 2, 2,5 o 5 per una potenza di 10
	function tacche(min, max, ampiezzaMin) {
		if (max - min < ampiezzaMin) {
			var c = (min + max) / 2;
			min = c - ampiezzaMin / 2;
			max = c + ampiezzaMin / 2;
		}
		var grezzo = (max - min) / 4;
		var p = Math.pow(10, Math.floor(Math.log10(grezzo)));
		var passo = [1, 2, 2.5, 5, 10].map(function (f) { return f * p; }).filter(function (s) { return s >= grezzo; })[0];
		var da = Math.floor(min / passo) * passo;
		var a = Math.ceil(max / passo) * passo;
		var t = [];
		for (var v = da; v <= a + passo / 2; v += passo) { t.push(Math.round(v * 1e6) / 1e6); }
		return t;
	}

	// Segmenti continui: si interrompe su un valore mancante o un intervallo saltato
	function segmenti(punti, i, passo) {
		var out = [], cur = [], prima = null;
		punti.forEach(function (p) {
			var spezza = p[i] === null || (prima !== null && p[0] - prima > passo * 1.5);
			if (spezza && cur.length) { out.push(cur); cur = []; }
			if (p[i] !== null) { cur.push(p); prima = p[0]; } else { prima = null; }
		});
		if (cur.length) { out.push(cur); }
		return out;
	}

	function sfondo(e) {
		for (; e && e.nodeType === 1; e = e.parentElement) {
			var c = getComputedStyle(e).backgroundColor;
			if (c && c !== 'transparent' && !/rgba\(.*,\s*0\)$/.test(c)) { return c; }
		}
		return '#ffffff';
	}

	function Grafici(radice) {
		this.r = radice;
		this.url = radice.dataset.url;
		this.testi = JSON.parse(radice.dataset.testi || '{}');
		this.locale = radice.dataset.locale || undefined;
		this.fuso = radice.dataset.fuso || undefined;
		this.periodo = radice.dataset.periodo || '24h';
		this.dati = null;
		this.pannelli = radice.querySelector('.dbmt-pannelli');
		this.legenda = radice.querySelector('.dbmt-legenda');
		this.tabella = radice.querySelector('.dbmt-tabella');
		this.btnTabella = radice.querySelector('.dbmt-tabella-btn');
		try {
			this.fmtOra = new Intl.DateTimeFormat(this.locale, { timeZone: this.fuso, hour: '2-digit', minute: '2-digit', hourCycle: 'h23' });
		} catch (e) {
			this.fuso = undefined; // fuso del sito non riconosciuto: quello del visitatore
			this.fmtOra = new Intl.DateTimeFormat(this.locale, { hour: '2-digit', minute: '2-digit', hourCycle: 'h23' });
		}
		this.fmtGiorno = new Intl.DateTimeFormat(this.locale, { timeZone: this.fuso, weekday: 'short', day: 'numeric' });
		this.fmtData = new Intl.DateTimeFormat(this.locale, { timeZone: this.fuso, day: 'numeric', month: 'short' });
		this.fmtCompleto = new Intl.DateTimeFormat(this.locale, { timeZone: this.fuso, weekday: 'short', day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit', hourCycle: 'h23' });
		this.fmtTabella = new Intl.DateTimeFormat(this.locale, { timeZone: this.fuso, weekday: 'short', hour: '2-digit', minute: '2-digit', hourCycle: 'h23' });
		this.fmtTabellaData = new Intl.DateTimeFormat(this.locale, { timeZone: this.fuso, day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit', hourCycle: 'h23' });
		this.fmtParti = new Intl.DateTimeFormat('en-GB', { timeZone: this.fuso, hour: '2-digit', hourCycle: 'h23', weekday: 'short' });
		this.collega();
		this.carica();
	}

	Grafici.prototype.num = function (v, dec) {
		return v === null ? '–' : new Intl.NumberFormat(this.locale, { minimumFractionDigits: dec, maximumFractionDigits: dec, useGrouping: false }).format(v);
	};

	Grafici.prototype.collega = function () {
		var self = this;
		this.r.querySelectorAll('[data-periodo-btn]').forEach(function (b) {
			b.addEventListener('click', function () {
				self.periodo = b.dataset.periodoBtn;
				self.r.querySelectorAll('[data-periodo-btn]').forEach(function (x) { x.setAttribute('aria-pressed', x === b ? 'true' : 'false'); });
				self.carica();
			});
		});
		this.btnTabella.addEventListener('click', function () {
			var apri = self.tabella.hidden;
			self.tabella.hidden = !apri;
			self.btnTabella.setAttribute('aria-expanded', apri ? 'true' : 'false');
			self.btnTabella.textContent = apri ? self.testi.nascondiTabella : self.testi.mostraTabella;
		});
		if (window.ResizeObserver) {
			var larghezza = 0, attesa = null;
			new ResizeObserver(function (v) {
				var w = Math.round(v[0].contentRect.width);
				if (w === larghezza || !self.dati) { larghezza = w; return; }
				larghezza = w;
				cancelAnimationFrame(attesa);
				attesa = requestAnimationFrame(function () { self.disegna(); });
			}).observe(this.pannelli);
		}
		// Aggiornamento automatico ogni 5 minuti, solo con la pagina visibile
		setInterval(function () { if (!document.hidden) { self.carica(); } }, 300000);
	};

	Grafici.prototype.carica = function () {
		var self = this;
		this.r.classList.add('dbmt-carica'); // il disegno precedente resta, attenuato
		fetch(this.url + (this.url.indexOf('?') < 0 ? '?' : '&') + 'periodo=' + encodeURIComponent(this.periodo))
			.then(function (r) { if (!r.ok) { throw new Error('HTTP ' + r.status); } return r.json(); })
			.then(function (d) { self.dati = d; self.disegna(); })
			.catch(function (e) {
				console.warn('DB Stazione Meteo: serie non caricata', e);
				if (!self.dati) { self.pannelli.textContent = self.testi.errore; }
			})
			.then(function () { self.r.classList.remove('dbmt-carica'); });
	};

	// Etichette dei tempi: candidati ogni ora, tenuti quelli "rotondi" nel fuso del sito
	Grafici.prototype.tempi = function (d) {
		var out = [], self = this;
		for (var t = Math.ceil(d.da / 3600) * 3600; t <= d.a; t += 3600) {
			var parti = {};
			this.fmtParti.formatToParts(new Date(t * 1000)).forEach(function (p) { parti[p.type] = p.value; });
			var h = parseInt(parti.hour, 10);
			if (d.periodo === '24h' && h % 6 === 0) {
				out.push([t, h === 0 ? self.fmtGiorno.format(new Date(t * 1000)) : self.fmtOra.format(new Date(t * 1000))]);
			} else if (d.periodo === '7g' && h === 0) {
				out.push([t, self.fmtGiorno.format(new Date(t * 1000))]);
			} else if (d.periodo === '30g' && h === 0 && parti.weekday === 'Mon') {
				out.push([t, self.fmtData.format(new Date(t * 1000))]);
			}
		}
		return out;
	};

	Grafici.prototype.disegna = function () {
		var d = this.dati, self = this;
		this.pannelli.textContent = '';
		this.legenda.textContent = '';
		this.tooltip = null;
		var nodi = d.nodi.filter(function (n) { return n.punti.length; })
			.sort(function (a, b) { return a.nodo - b.nodo; });
		if (!nodi.length) {
			html('p', 'dbmt-vuoto', this.testi.nessunDato, this.pannelli);
			this.disegnaTabella([]);
			return;
		}

		// Legenda solo con due o più nodi: con uno solo il titolo basta
		this.legenda.hidden = nodi.length < 2;
		if (nodi.length >= 2) {
			nodi.forEach(function (n) {
				var voce = html('span', 'dbmt-voce', null, self.legenda);
				var k = el('svg', { width: 18, height: 10, 'aria-hidden': 'true' }, voce);
				el('line', { x1: 1, y1: 5, x2: 17, y2: 5, 'stroke-width': 2, 'stroke-linecap': 'round' }, k).style.stroke = colore(n.nodo);
				voce.appendChild(document.createTextNode(self.testi.nodo.replace('%d', n.nodo)));
			});
		}

		var larghezza = Math.max(280, this.pannelli.clientWidth);
		var w = larghezza - M.sx - M.dx;
		var x = function (t) { return M.sx + (t - d.da) / (d.a - d.da) * w; };
		var fascia = d.periodo !== '24h';
		var anello = sfondo(this.r);
		var grandezze = GRANDEZZE.filter(function (g) {
			return nodi.some(function (n) { return n.punti.some(function (p) { return p[g.i] !== null; }); });
		});
		var tempi = this.tempi(d);
		this.crosshair = [];

		grandezze.forEach(function (g, gi) {
			var ultimo = gi === grandezze.length - 1;
			var altezza = ALTEZZA + M.su + M.giu + (ultimo ? ASSE_X : 0);
			var valori = [];
			nodi.forEach(function (n) {
				n.punti.forEach(function (p) {
					[p[g.i]].concat(fascia ? [p[g.i + 1], p[g.i + 2]] : []).forEach(function (v) { if (v !== null) { valori.push(v); } });
				});
			});
			var tk = tacche(Math.min.apply(null, valori), Math.max.apply(null, valori), g.ampiezza);
			var y = function (v) { return M.su + (tk[tk.length - 1] - v) / (tk[tk.length - 1] - tk[0]) * ALTEZZA; };

			var fig = html('figure', 'dbmt-pannello', null, self.pannelli);
			var cap = html('figcaption', null, self.testi[g.chiave] + ' (' + g.unita + ')', fig);
			if (g.chiave === 'pressione') { html('span', 'dbmt-nota', ' ' + self.testi.livelloMare, cap); }
			var svg = el('svg', { width: larghezza, height: altezza, viewBox: '0 0 ' + larghezza + ' ' + altezza, role: 'img', tabindex: 0 }, fig);
			svg.setAttribute('aria-label', self.sommario(g, nodi));

			// Griglia orizzontale e valori dell'asse, tutti con gli stessi decimali
			var decAsse = tk.some(function (v) { return v % 1; }) ? 1 : 0;
			tk.forEach(function (v) {
				el('line', { x1: M.sx, x2: M.sx + w, y1: y(v), y2: y(v), class: 'dbmt-griglia' }, svg);
				el('text', { x: M.sx - 6, y: y(v), class: 'dbmt-asse', 'text-anchor': 'end', 'dominant-baseline': 'middle' }, svg).textContent = self.num(v, decAsse);
			});
			if (ultimo) {
				var finoA = -Infinity;
				tempi.forEach(function (tt) {
					var px = x(tt[0]);
					if (px - finoA < 56 || px > M.sx + w - 16) { return; } // niente etichette sovrapposte
					finoA = px;
					el('line', { x1: px, x2: px, y1: M.su + ALTEZZA, y2: M.su + ALTEZZA + 4, class: 'dbmt-griglia' }, svg);
					el('text', { x: px, y: M.su + ALTEZZA + 16, class: 'dbmt-asse', 'text-anchor': 'middle' }, svg).textContent = tt[1];
				});
			}

			var fine = [];
			nodi.forEach(function (n) {
				var segs = segmenti(n.punti, g.i, d.passo);
				if (fascia) {
					segs.forEach(function (s) {
						var su = s.map(function (p) { return x(p[0]) + ',' + y(p[g.i + 2]); });
						var giu = s.slice().reverse().map(function (p) { return x(p[0]) + ',' + y(p[g.i + 1]); });
						el('polygon', { points: su.concat(giu).join(' '), class: 'dbmt-fascia' }, svg).style.fill = colore(n.nodo);
					});
				}
				segs.forEach(function (s) {
					var linea = el('polyline', { points: s.map(function (p) { return x(p[0]) + ',' + y(p[g.i]); }).join(' '), class: 'dbmt-linea' }, svg);
					linea.style.stroke = colore(n.nodo);
				});
				var u = segs.length ? segs[segs.length - 1] : [];
				if (u.length) { fine.push({ n: n, p: u[u.length - 1] }); }
			});

			// Valore all'estremità: solo se le etichette non si sovrappongono
			var vicine = fine.length > 1 && fine.some(function (a, i) {
				return fine.some(function (b, j) { return i < j && Math.abs(y(a.p[g.i]) - y(b.p[g.i])) < 14; });
			});
			fine.forEach(function (f) {
				var c = el('circle', { cx: x(f.p[0]), cy: y(f.p[g.i]), r: 4, 'stroke-width': 2 }, svg);
				c.style.fill = colore(f.n.nodo);
				c.style.stroke = anello;
				if (!vicine) {
					el('text', { x: x(f.p[0]) + 9, y: y(f.p[g.i]), class: 'dbmt-valore', 'dominant-baseline': 'middle' }, svg)
						.textContent = self.num(f.p[g.i], g.dec) + ' ' + g.unita;
				}
			});

			// Crosshair: linea e punti, aggiornati insieme su tutti i pannelli
			var gruppo = el('g', { class: 'dbmt-cross', visibility: 'hidden' }, svg);
			var linea = el('line', { y1: M.su, y2: M.su + ALTEZZA, class: 'dbmt-cross-linea' }, gruppo);
			var punti = nodi.map(function (n) {
				var c = el('circle', { r: 4, 'stroke-width': 2 }, gruppo);
				c.style.fill = colore(n.nodo);
				c.style.stroke = anello;
				return c;
			});
			self.crosshair.push({ g: g, y: y, gruppo: gruppo, linea: linea, punti: punti, svg: svg, fig: fig });

			var area = el('rect', { x: M.sx, y: M.su, width: w, height: ALTEZZA, fill: 'transparent' }, svg);
			area.addEventListener('pointermove', function (e) {
				var r = svg.getBoundingClientRect();
				var t = d.da + (e.clientX - r.left - M.sx) / w * (d.a - d.da);
				self.mostra(self.indice(t), fig, x);
			});
			area.addEventListener('pointerleave', function () { self.nascondi(); });
			svg.addEventListener('focus', function () { self.mostra(self.cursore === undefined ? self.istanti().length - 1 : self.cursore, fig, x); });
			svg.addEventListener('blur', function () { self.nascondi(); });
			svg.addEventListener('keydown', function (e) {
				var n = self.istanti().length;
				if (e.key === 'ArrowLeft' || e.key === 'ArrowRight') {
					e.preventDefault();
					self.mostra(Math.max(0, Math.min(n - 1, (self.cursore || 0) + (e.key === 'ArrowLeft' ? -1 : 1))), fig, x);
				} else if (e.key === 'Escape') {
					self.nascondi();
				}
			});
		});

		this.nodi = nodi;
		this._istanti = null;
		this.disegnaTabella(nodi);
	};

	Grafici.prototype.sommario = function (g, nodi) {
		var self = this, periodo = this.testi['periodo_' + this.dati.periodo];
		return this.testi[g.chiave] + ', ' + periodo + ': ' + nodi.map(function (n) {
			var v = n.punti.map(function (p) { return p[g.i]; }).filter(function (v) { return v !== null; });
			if (!v.length) { return ''; }
			return (nodi.length > 1 ? self.testi.nodo.replace('%d', n.nodo) + ' ' : '') +
				self.testi.daA.replace('%1$s', self.num(Math.min.apply(null, v), g.dec)).replace('%2$s', self.num(Math.max.apply(null, v), g.dec)) + ' ' + g.unita;
		}).filter(Boolean).join('; ');
	};

	// Tutti gli istanti con almeno una misura, in ordine
	Grafici.prototype.istanti = function () {
		if (!this._istanti) {
			var s = {};
			this.nodi.forEach(function (n) { n.punti.forEach(function (p) { s[p[0]] = true; }); });
			this._istanti = Object.keys(s).map(Number).sort(function (a, b) { return a - b; });
		}
		return this._istanti;
	};

	Grafici.prototype.indice = function (t) {
		var ist = this.istanti(), migliore = 0;
		ist.forEach(function (v, i) { if (Math.abs(v - t) < Math.abs(ist[migliore] - t)) { migliore = i; } });
		return migliore;
	};

	Grafici.prototype.mostra = function (i, fig, x) {
		var self = this, t = this.istanti()[i];
		if (t === undefined) { return; }
		this.cursore = i;
		var riga = this.nodi.map(function (n) {
			return n.punti.filter(function (p) { return p[0] === t; })[0] || null;
		});
		this.crosshair.forEach(function (c) {
			c.gruppo.setAttribute('visibility', 'visible');
			c.linea.setAttribute('x1', x(t));
			c.linea.setAttribute('x2', x(t));
			c.punti.forEach(function (pt, k) {
				var p = riga[k];
				var ok = p && p[c.g.i] !== null;
				pt.setAttribute('visibility', ok ? 'visible' : 'hidden');
				if (ok) { pt.setAttribute('cx', x(t)); pt.setAttribute('cy', c.y(p[c.g.i])); }
			});
		});

		// Un solo tooltip con tutti i valori di quell'istante: prima il valore, poi l'etichetta
		var tip = this.tooltip || html('div', 'dbmt-tooltip', null, this.pannelli);
		this.tooltip = tip;
		tip.textContent = '';
		tip.setAttribute('role', 'status');
		html('div', 'dbmt-tooltip-ora', this.fmtCompleto.format(new Date(t * 1000)), tip);
		this.nodi.forEach(function (n, k) {
			var p = riga[k];
			if (!p) { return; }
			if (self.nodi.length > 1) {
				var titolo = html('div', 'dbmt-tooltip-nodo', null, tip);
				var key = el('svg', { width: 14, height: 8, 'aria-hidden': 'true' }, titolo);
				el('line', { x1: 1, y1: 4, x2: 13, y2: 4, 'stroke-width': 2, 'stroke-linecap': 'round' }, key).style.stroke = colore(n.nodo);
				titolo.appendChild(document.createTextNode(self.testi.nodo.replace('%d', n.nodo)));
			}
			self.crosshair.forEach(function (c) {
				if (p[c.g.i] === null) { return; }
				var r = html('div', 'dbmt-tooltip-riga', null, tip);
				html('strong', null, self.num(p[c.g.i], c.g.dec) + ' ' + c.g.unita, r);
				html('span', null, ' ' + self.testi[c.g.chiave].toLowerCase(), r);
			});
		});
		var box = this.pannelli.getBoundingClientRect();
		var fb = fig.getBoundingClientRect();
		var px = x(t);
		tip.style.top = (fb.top - box.top + 24) + 'px';
		tip.style.left = '';
		tip.style.right = '';
		if (px > box.width / 2) { tip.style.right = (box.width - px + 12) + 'px'; } else { tip.style.left = (px + 12) + 'px'; }
		tip.hidden = false;
	};

	Grafici.prototype.nascondi = function () {
		this.crosshair.forEach(function (c) { c.gruppo.setAttribute('visibility', 'hidden'); });
		if (this.tooltip) { this.tooltip.hidden = true; }
	};

	// Tabella: stessi dati dei grafici (medie), le misure più recenti in alto
	Grafici.prototype.disegnaTabella = function (nodi) {
		var self = this;
		this.tabella.textContent = '';
		if (!nodi.length) { return; }
		var t = html('table', null, null, this.tabella);
		html('caption', null, this.testi.didascalia.replace('%s', this.testi['periodo_' + this.dati.periodo]), t);
		var colonne = [];
		nodi.forEach(function (n) {
			GRANDEZZE.forEach(function (g) {
				if (n.punti.some(function (p) { return p[g.i] !== null; })) { colonne.push({ n: n, g: g }); }
			});
		});
		var testa = html('thead', null, null, t);
		var tr = html('tr', null, null, testa);
		var thOra = html('th', null, this.testi.ora, tr);
		thOra.setAttribute('scope', 'col');
		// Con più nodi, una riga di intestazione per nodo sopra le sue grandezze
		if (nodi.length > 1) {
			thOra.setAttribute('rowspan', '2');
			nodi.forEach(function (n) {
				var span = colonne.filter(function (c) { return c.n === n; }).length;
				var th = html('th', null, self.testi.nodo.replace('%d', n.nodo), tr);
				th.setAttribute('scope', 'colgroup');
				th.setAttribute('colspan', span);
			});
			tr = html('tr', null, null, testa);
		}
		colonne.forEach(function (c) {
			html('th', null, self.testi[c.g.chiave] + ' (' + c.g.unita + ')', tr).setAttribute('scope', 'col');
		});
		var corpo = html('tbody', null, null, t);
		var fmt = this.dati.periodo === '30g' ? this.fmtTabellaData : this.fmtTabella;
		this.istanti().slice().reverse().forEach(function (ist) {
			var r = html('tr', null, null, corpo);
			html('th', null, fmt.format(new Date(ist * 1000)), r).setAttribute('scope', 'row');
			colonne.forEach(function (c) {
				var p = c.n.punti.filter(function (q) { return q[0] === ist; })[0];
				html('td', null, p ? self.num(p[c.g.i], c.g.dec) : '–', r);
			});
		});
	};

	function avvia() {
		document.querySelectorAll('.dbmt-grafici[data-url]').forEach(function (r) {
			if (!r.dbmtGrafici) { r.dbmtGrafici = new Grafici(r); }
		});
	}
	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', avvia);
	} else {
		avvia();
	}
}());
