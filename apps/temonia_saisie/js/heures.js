/* SPDX-FileCopyrightText: 2026 Flavien Auvray
 * SPDX-License-Identifier: AGPL-3.0-or-later */
/* l'association — écran « Heures » : saisir ses heures et voir son récapitulatif.
   Écran à part de l'appli Projets (même app Nextcloud, même API, mêmes données).

   Deux parties :
     • SAISIR : Projet puis Tâche (deux menus, la tâche rangée par phase, comme dans Projets),
       Jour (Aujourd'hui / Hier / une date), Durée (+/− par demi-heure ou raccourcis),
       Précision facultative, puis « Enregistrer ». La dernière tâche pointée est déjà choisie.
     • MES HEURES : espace de consultation. Période Semaine | Mois | Année (‹ › pour naviguer),
       filtre par projet, total, répartition (par projet, ou par tâche si un projet est filtré),
       détail jour par jour (par mois en vue année) avec ✎ corriger / ✕ supprimer, export CSV.
   Sur ordinateur les deux parties sont côte à côte ; sur téléphone ce sont deux onglets
   et le bouton « Enregistrer » reste collé en bas de l'écran (voir css/heures.css).

   API utilisée (déjà existante, voir appinfo/routes.php ; l'API vérifie les droits) :
     GET    /api/heures       → MES saisies (jamais celles des autres)
     POST   /api/heures       → créer { tache, jour, heures, commentaire }
     PUT    /api/heures/{id}  → corriger une de mes saisies { heures, commentaire }
     DELETE /api/heures/{id}  → supprimer une de mes saisies
     GET    /api/board        → les tâches « feuilles » des projets auxquels j'ai accès
   Écrit sans framework (JS « vanilla »), comme main.js : pas de compilation. */
(function () {
    'use strict'
    var root = document.getElementById('th-app')
    if (!root) { return }
    var base = OC.generateUrl('/apps/temonia_saisie')

    // ---------- état de l'écran ----------
    // vue      : onglet affiché sur téléphone ('saisir' | 'recap') ; sur ordinateur, les deux sont visibles
    // jour     : jour de la saisie 'AAAA-MM-JJ'
    // projetId : projet choisi (le menu Tâche ne montre que ses tâches) ; tache : tâche choisie (objet du board)
    // heures   : durée réglée (pas de 0,5) ; comm : précision facultative
    // periode  : 'semaine' | 'mois' | 'annee' ; ref : une date dans la période affichée ; filtreProjet : projet filtré ('' = tous)
    // edition  : saisie en cours de correction { id, heures, comm } ou null
    // entries  : mes saisies (toutes) ; board : tâches accessibles ; envoi : enregistrement en cours
    var S = { vue: 'saisir', jour: todayISO(), projetId: null, tache: null, heures: 1, comm: '', periode: 'semaine', ref: todayISO(), filtreProjet: '', edition: null, entries: [], board: [], envoi: false }

    // ---------- petits outils ----------
    // Date locale au format 'AAAA-MM-JJ' (celui de l'API et des <input type="date">).
    function iso(d) { return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0') }
    function todayISO() { return iso(new Date()) }
    // Décale une date ISO de n jours (n négatif = dans le passé).
    function addDays(j, n) { var d = new Date(j + 'T00:00:00'); d.setDate(d.getDate() + n); return iso(d) }
    // Lundi de la semaine d'une date (getDay : dimanche = 0, donc « + 6 modulo 7 » met lundi à 0).
    function lundiDe(j) { var d = new Date(j + 'T00:00:00'); return addDays(j, -((d.getDay() + 6) % 7)) }
    // 1,5 → « 1,5 h » (virgule à la française).
    function fmtH(h) { return String(Math.round(h * 100) / 100).replace('.', ',') + ' h' }
    function fmtDate(j, opts) { return new Date(j + 'T00:00:00').toLocaleDateString('fr-FR', opts) }
    function jourLong(j) { return fmtDate(j, { weekday: 'long', day: 'numeric', month: 'long' }) }
    // Pour trier sans se soucier des accents ni des majuscules : « Éditions » ≈ « editions ».
    function plat(s) { return String(s || '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase() }

    // Appel à l'API de l'app (même recette que main.js). requesttoken = jeton anti-CSRF de Nextcloud.
    function api(method, path, body) {
        var opts = { method: method, headers: { requesttoken: OC.requestToken, 'OCS-APIRequest': 'true' } }
        if (body) { opts.headers['Content-Type'] = 'application/json'; opts.body = JSON.stringify(body) }
        return fetch(base + path, opts).then(function (r) {
            if (!r.ok) { return r.json().catch(function () { return {} }).then(function (j) { throw new Error(j.message || 'Erreur ' + r.status) }) }
            return r.status === 204 ? null : r.json()
        })
    }
    // Fabrique un élément HTML : el('button', { class, text, onclick… }, [enfants]).
    function el(tag, attrs, children) {
        var e = document.createElement(tag)
        Object.keys(attrs || {}).forEach(function (k) {
            var v = attrs[k]
            if (v == null || v === false) { return }
            if (k === 'class') { e.className = v }
            else if (k === 'text') { e.textContent = v }
            else if (k.indexOf('on') === 0) { e.addEventListener(k.slice(2), v) }
            else { e.setAttribute(k, v === true ? '' : v) }
        })
        ;(children || []).forEach(function (c) { if (c) { e.appendChild(c) } })
        return e
    }
    function bouton(classe, texte, action, extra) { return el('button', Object.assign({ type: 'button', class: classe, text: texte, onclick: action }, extra || {})) }
    // Message temporaire en bas de l'écran.
    function toast(msg, erreur) {
        var t = el('div', { class: 'th-toast' + (erreur ? ' th-toast--err' : ''), text: msg, role: 'status' })
        root.appendChild(t)
        setTimeout(function () { t.classList.add('th-toast--out') }, 2600)
        setTimeout(function () { if (t.parentNode) { t.parentNode.removeChild(t) } }, 3000)
    }

    // ---------- calculs ----------
    function somme(liste) { return liste.reduce(function (s, e) { return s + (e.heures || 0) }, 0) }
    function saisiesEntre(debut, fin) { return S.entries.filter(function (e) { return e.jour >= debut && e.jour <= fin }) }
    // Tâches pointables : on écarte les propositions pas encore validées par la direction.
    function pointables() { return S.board.filter(function (t) { return t.valide !== false }) }
    // Dernière tâche pointée (si elle existe encore et m'est accessible) : présélectionnée.
    function derniereTache() {
        var parId = {}; pointables().forEach(function (t) { parId[t.id] = t })
        var tri = S.entries.slice().sort(function (a, b) { return (b.jour + String(b.id).padStart(9, '0')).localeCompare(a.jour + String(a.id).padStart(9, '0')) })
        for (var i = 0; i < tri.length; i++) { if (parId[tri[i].tacheId]) { return parId[tri[i].tacheId] } }
        return null
    }
    function projetsDispo() {
        var vus = {}, out = []
        pointables().forEach(function (t) { if (t.projetId && !vus[t.projetId]) { vus[t.projetId] = true; out.push({ id: t.projetId, nom: t.projetNom || 'Projet', couleur: t.couleur }) } })
        return out.sort(function (a, b) { return plat(a.nom).localeCompare(plat(b.nom)) })
    }

    // ================= DESSIN =================
    function render() {
        // Les messages temporaires (toasts) survivent au redessin : on les met de côté puis on les remet.
        var toasts = Array.prototype.slice.call(root.querySelectorAll('.th-toast'))
        root.innerHTML = ''
        var page = el('div', { class: 'th-page th-page--' + S.vue })
        page.appendChild(entete())
        page.appendChild(onglets())
        page.appendChild(el('div', { class: 'th-grille' }, [panneauSaisie(), panneauRecap()]))
        root.appendChild(page)
        toasts.forEach(function (t) { root.appendChild(t) })
    }

    function entete() {
        var semaineEnCours = somme(saisiesEntre(lundiDe(todayISO()), todayISO()))
        return el('header', { class: 'th-head' }, [
            el('div', {}, [
                el('h1', { class: 'th-title', text: 'Heures' }),
                el('div', { class: 'th-sub', text: 'Cette semaine : ' + fmtH(semaineEnCours) })
            ]),
            // Lien vers l'appli complète (tableau de bord, projets, planneur…).
            el('a', { class: 'th-lien', href: base + '/', text: 'Projets' })
        ])
    }

    // Onglets « Saisir / Mes heures » : utiles sur téléphone seulement (masqués par la CSS sur ordinateur).
    function onglets() {
        function onglet(cle, texte) { return bouton('th-onglet' + (S.vue === cle ? ' th-onglet--on' : ''), texte, function () { S.vue = cle; render() }, { role: 'tab', 'aria-selected': S.vue === cle ? 'true' : 'false' }) }
        return el('div', { class: 'th-onglets', role: 'tablist' }, [onglet('saisir', 'Saisir'), onglet('recap', 'Mes heures')])
    }

    // ---------- SAISIR ----------
    function panneauSaisie() {
        return el('section', { class: 'th-panneau th-panneau--saisir', 'aria-label': 'Saisir des heures' }, [
            el('h2', { class: 'th-h2', text: 'Saisir' }),
            champProjet(), champTache(), champJour(), champDuree(), champPrecision(),
            barreEnregistrer()
        ])
    }
    function champ(libelle, contenu, aide) {
        return el('div', { class: 'th-champ' }, [el('div', { class: 'th-lbl' }, [el('span', { text: libelle }), aide ? el('span', { class: 'th-lbl__aide', text: aide }) : null]), contenu])
    }

    // Menu 1 : le projet. Menus déroulants NATIFS : sur téléphone, le système ouvre sa propre liste.
    function champProjet() {
        var sel = el('select', { class: 'th-select', id: 'th-projet-sel', 'aria-label': 'Projet' })
        sel.appendChild(el('option', { value: '', text: 'Choisir un projet' }))
        projetsDispo().forEach(function (p) { sel.appendChild(el('option', { value: String(p.id), text: p.nom })) })
        sel.value = S.projetId ? String(S.projetId) : ''
        sel.addEventListener('change', function () { S.projetId = sel.value ? parseInt(sel.value, 10) : null; S.tache = null; render(); ouvrirMenu() })
        return champ('Projet', sel)
    }
    // Menu 2 : la tâche du projet, un groupe par phase ; une sous-tâche est précédée de sa tâche parente.
    function champTache() {
        var sel = el('select', { class: 'th-select', id: 'th-tache-sel', 'aria-label': 'Tâche' })
        if (!S.projetId) {
            sel.disabled = true
            sel.appendChild(el('option', { value: '', text: 'Choisir d’abord un projet' }))
        } else {
            sel.appendChild(el('option', { value: '', text: 'Choisir une tâche' }))
            var parPhase = {}, ordre = []
            pointables().filter(function (t) { return t.projetId === S.projetId }).forEach(function (t) {
                var ph = t.activiteNom || 'Sans phase'
                if (!parPhase[ph]) { parPhase[ph] = []; ordre.push(ph) }
                parPhase[ph].push(t)
            })
            ordre.forEach(function (ph) {
                var og = document.createElement('optgroup'); og.label = ph
                parPhase[ph].forEach(function (t) { og.appendChild(el('option', { value: String(t.id), text: (t.parentTitre ? t.parentTitre + ' › ' : '') + t.titre + (t.mine ? ' ★' : '') })) })
                sel.appendChild(og)
            })
            sel.value = S.tache ? String(S.tache.id) : ''
        }
        sel.addEventListener('change', function () { var id = parseInt(sel.value, 10); S.tache = pointables().find(function (t) { return t.id === id }) || null; render() })
        return champ('Tâche', sel, '★ attribuée à moi')
    }
    // Amène au bon menu (Projet, ou Tâche si le projet est choisi) et l'ouvre quand le navigateur le permet.
    function ouvrirMenu() {
        var sel = document.getElementById(S.projetId ? 'th-tache-sel' : 'th-projet-sel'); if (!sel) { return }
        sel.focus()
        try { if (sel.showPicker) { sel.showPicker() } } catch (e) { /* refusé hors geste de l'utilisateur : le focus suffit */ }
    }

    // Jour : contrôle segmenté Aujourd'hui | Hier | date (calendrier natif).
    function champJour() {
        var auj = todayISO(), hier = addDays(auj, -1), autre = S.jour !== auj && S.jour !== hier
        function segment(j, texte) { return bouton('th-seg__btn' + (S.jour === j ? ' th-seg__btn--on' : ''), texte, function () { S.jour = j; render() }) }
        var date = el('input', { type: 'date', class: 'th-seg__btn th-seg__date' + (autre ? ' th-seg__btn--on' : ''), max: auj, value: S.jour, 'aria-label': 'Autre jour' })
        date.addEventListener('change', function () { if (date.value) { S.jour = date.value > auj ? auj : date.value; render() } })
        return champ('Jour', el('div', { class: 'th-seg' }, [segment(auj, 'Aujourd’hui'), segment(hier, 'Hier'), date]), jourLong(S.jour))
    }

    // Durée : − valeur + (pas de 0,5 h) et des raccourcis courants.
    function champDuree() {
        function regle(v) { S.heures = Math.max(0.5, Math.min(24, Math.round(v * 2) / 2)); render() }
        return champ('Durée', el('div', { class: 'th-duree' }, [
            el('div', { class: 'th-pas' }, [
                bouton('th-pas__btn', '−', function () { regle(S.heures - 0.5) }, { 'aria-label': 'Retirer une demi-heure' }),
                el('output', { class: 'th-pas__val', text: fmtH(S.heures), 'aria-live': 'polite' }),
                bouton('th-pas__btn', '+', function () { regle(S.heures + 0.5) }, { 'aria-label': 'Ajouter une demi-heure' })
            ]),
            el('div', { class: 'th-raccourcis' }, [0.5, 1, 2, 3.5, 7].map(function (v) {
                return bouton('th-puce' + (S.heures === v ? ' th-puce--on' : ''), fmtH(v), function () { regle(v) })
            }))
        ]))
    }

    function champPrecision() {
        var input = el('input', { type: 'text', class: 'th-input', placeholder: 'Ce qui a été fait (facultatif)', value: S.comm, enterkeyhint: 'done' })
        input.addEventListener('input', function () { S.comm = input.value }) // pas de redessin : le clavier reste ouvert
        return champ('Précision', input)
    }

    // Bouton principal. Sur téléphone il reste collé en bas de l'écran (CSS).
    // Sans tâche, il amène au menu à remplir au lieu d'être grisé : on ne bloque jamais la personne.
    function barreEnregistrer() {
        var b
        if (!S.tache) { b = bouton('th-btn', S.projetId ? 'Choisir une tâche' : 'Choisir un projet', ouvrirMenu) }
        else {
            var texte = S.envoi ? 'Enregistrement…' : 'Enregistrer ' + fmtH(S.heures) + (S.jour === todayISO() ? '' : ' · ' + fmtDate(S.jour, { weekday: 'short', day: 'numeric' }))
            b = bouton('th-btn', texte, enregistrer, { disabled: S.envoi })
        }
        return el('div', { class: 'th-barre' }, [b])
    }
    function enregistrer() {
        if (!S.tache || S.envoi) { return }
        S.envoi = true; render()
        api('POST', '/api/heures', { tache: S.tache.id, jour: S.jour, heures: S.heures, commentaire: S.comm })
            .then(function () {
                toast(fmtH(S.heures) + ' enregistrée' + (S.heures >= 2 ? 's' : '') + ' · ' + S.tache.titre)
                S.comm = ''
                S.ref = S.jour // le récap se place sur la période de la saisie
                return chargerSaisies()
            })
            .catch(function (e) { toast(e.message || 'Enregistrement impossible.', true) })
            .then(function () { S.envoi = false; render() })
    }

    // ---------- MES HEURES (espace de consultation) ----------
    // Période affichée : semaine, mois ou année contenant la date de référence S.ref.
    // Renvoie { debut, fin, libelle, courante } (debut/fin en 'AAAA-MM-JJ', bornes incluses).
    function periode() {
        var r = S.ref, auj = todayISO()
        if (S.periode === 'mois') {
            var debutM = r.slice(0, 8) + '01'
            var d = new Date(debutM + 'T00:00:00'); d.setMonth(d.getMonth() + 1); d.setDate(0) // dernier jour du mois
            var libM = fmtDate(debutM, { month: 'long', year: 'numeric' })
            return { debut: debutM, fin: iso(d), libelle: libM.charAt(0).toUpperCase() + libM.slice(1), courante: debutM === auj.slice(0, 8) + '01' }
        }
        if (S.periode === 'annee') {
            var an = r.slice(0, 4)
            return { debut: an + '-01-01', fin: an + '-12-31', libelle: an, courante: an === auj.slice(0, 4) }
        }
        var lundi = lundiDe(r), dimanche = addDays(lundi, 6)
        var memeMois = lundi.slice(5, 7) === dimanche.slice(5, 7)
        return { debut: lundi, fin: dimanche, courante: lundi === lundiDe(auj),
            libelle: fmtDate(lundi, memeMois ? { day: 'numeric' } : { day: 'numeric', month: 'short' }) + ' – ' + fmtDate(dimanche, { day: 'numeric', month: 'short' }) }
    }
    // Avance ou recule d'une période (sens = -1 ou +1).
    function decaler(sens) {
        if (S.periode === 'mois') { var d = new Date(S.ref.slice(0, 8) + '01T00:00:00'); d.setMonth(d.getMonth() + sens); S.ref = iso(d) }
        else if (S.periode === 'annee') { S.ref = (parseInt(S.ref.slice(0, 4), 10) + sens) + '-01-01' }
        else { S.ref = addDays(S.ref, 7 * sens) }
        S.edition = null; render()
    }
    // Saisies de la période, filtrées par projet si un projet est choisi.
    function saisiesPeriode(p) {
        return saisiesEntre(p.debut, p.fin).filter(function (e) { return !S.filtreProjet || (e.projetNom || 'Sans projet') === S.filtreProjet })
    }

    // Export de la période affichée en CSV, lisible par Excel / LibreOffice : séparateur « ; »,
    // virgule décimale, et le BOM (﻿) en tête pour que les accents s'affichent bien dans Excel.
    function exporterCSV(p, liste) {
        function cel(v) { v = String(v == null ? '' : v); return /[;"\n]/.test(v) ? '"' + v.replace(/"/g, '""') + '"' : v }
        var lignes = [['Date', 'Projet', 'Phase', 'Tâche', 'Heures', 'Précision'].join(';')]
        liste.slice().sort(function (a, b) { return a.jour.localeCompare(b.jour) }).forEach(function (e) {
            lignes.push([e.jour, e.projetNom, e.activiteNom, e.tacheTitre, String(e.heures || 0).replace('.', ','), e.commentaire].map(cel).join(';'))
        })
        lignes.push(['Total', '', '', '', String(somme(liste)).replace('.', ','), ''].map(cel).join(';'))
        var blob = new Blob(['﻿' + lignes.join('\r\n')], { type: 'text/csv;charset=utf-8' })
        var nom = 'mes-heures_' + p.debut + '_' + p.fin + (S.filtreProjet ? '_' + plat(S.filtreProjet).replace(/[^a-z0-9]+/g, '-').slice(0, 30) : '') + '.csv'
        var a = el('a', { href: URL.createObjectURL(blob), download: nom })
        document.body.appendChild(a); a.click(); setTimeout(function () { URL.revokeObjectURL(a.href); a.remove() }, 1000)
        window.dernierCSV = { nom: nom, lignes: lignes } // (lu par le banc d'essai)
    }

    function panneauRecap() {
        var p = periode(), liste = saisiesPeriode(p), total = somme(liste)

        // Choix de la période : Semaine | Mois | Année
        function segP(cle, texte) { return bouton('th-seg__btn' + (S.periode === cle ? ' th-seg__btn--on' : ''), texte, function () { S.periode = cle; S.edition = null; render() }) }
        // Filtre par projet : les projets présents dans TOUTES mes saisies (on peut changer de période sans perdre le filtre).
        var noms = {}; S.entries.forEach(function (e) { noms[e.projetNom || 'Sans projet'] = true })
        var filtre = el('select', { class: 'th-select th-select--petit', 'aria-label': 'Filtrer par projet' })
        filtre.appendChild(el('option', { value: '', text: 'Tous les projets' }))
        Object.keys(noms).sort(function (a, b) { return plat(a).localeCompare(plat(b)) }).forEach(function (n) { filtre.appendChild(el('option', { value: n, text: n })) })
        filtre.value = S.filtreProjet
        filtre.addEventListener('change', function () { S.filtreProjet = filtre.value; S.edition = null; render() })

        var panneau = el('section', { class: 'th-panneau th-panneau--recap', 'aria-label': 'Mes heures' }, [
            el('h2', { class: 'th-h2', text: 'Mes heures' }),
            el('div', { class: 'th-filtres' }, [
                el('div', { class: 'th-seg th-seg--periode' }, [segP('semaine', 'Semaine'), segP('mois', 'Mois'), segP('annee', 'Année')]),
                filtre
            ]),
            el('div', { class: 'th-semnav' }, [
                bouton('th-icone', '‹', function () { decaler(-1) }, { 'aria-label': 'Période précédente' }),
                el('span', { class: 'th-semnav__t', text: p.libelle }),
                // On ne va pas dans le futur : pas de période après la période en cours.
                bouton('th-icone', '›', function () { decaler(1) }, { 'aria-label': 'Période suivante', disabled: p.courante }),
                p.courante ? null : bouton('th-btn2 th-btn2--petit', 'Aujourd’hui', function () { S.ref = todayISO(); S.edition = null; render() })
            ]),
            el('div', { class: 'th-total' }, [
                el('span', { class: 'th-total__v', text: fmtH(total) }),
                el('span', { class: 'th-total__p', text: liste.length + ' saisie' + (liste.length > 1 ? 's' : '') }),
                liste.length ? bouton('th-btn2 th-btn2--petit th-export', 'Exporter (CSV)', function () { exporterCSV(p, liste) }, { title: 'Télécharger ces heures pour Excel ou LibreOffice' }) : null
            ])
        ])
        if (!liste.length) { panneau.appendChild(el('p', { class: 'th-vide', text: 'Aucune heure sur cette période.' })); return panneau }

        // Répartition : par projet (tous projets) ou par tâche (un projet filtré). Barres relatives au plus gros.
        var parCle = {}
        liste.forEach(function (e) { var k = S.filtreProjet ? (e.tacheTitre || 'Tâche n° ' + e.tacheId) : (e.projetNom || 'Sans projet'); parCle[k] = (parCle[k] || 0) + (e.heures || 0) })
        var cles = Object.keys(parCle).sort(function (a, b) { return parCle[b] - parCle[a] })
        var max = parCle[cles[0]] || 1
        panneau.appendChild(el('div', { class: 'th-projets' }, [el('div', { class: 'th-soustitre', text: S.filtreProjet ? 'Par tâche' : 'Par projet' })].concat(cles.map(function (k) {
            return el('div', { class: 'th-projet' }, [
                el('span', { class: 'th-projet__n', text: k, title: k }),
                el('span', { class: 'th-projet__barre' }, [el('span', { class: 'th-projet__rempli', style: 'width:' + Math.round(parCle[k] / max * 100) + '%' })]),
                el('span', { class: 'th-projet__h', text: fmtH(parCle[k]) })
            ])
        }))))

        if (S.periode === 'annee') {
            // Vue année : une ligne par mois (un clic ouvre le mois en détail).
            panneau.appendChild(el('div', { class: 'th-soustitre th-soustitre--detail', text: 'Par mois' }))
            for (var m = 12; m >= 1; m--) {
                (function (mm) {
                    var debut = p.debut.slice(0, 4) + '-' + String(mm).padStart(2, '0') + '-01'
                    var du = liste.filter(function (e) { return e.jour.slice(0, 7) === debut.slice(0, 7) })
                    if (!du.length) { return }
                    var nomMois = fmtDate(debut, { month: 'long' })
                    panneau.appendChild(el('button', { type: 'button', class: 'th-moisligne', onclick: function () { S.periode = 'mois'; S.ref = debut; render() } }, [
                        el('span', { class: 'th-moisligne__n', text: nomMois.charAt(0).toUpperCase() + nomMois.slice(1) }),
                        el('span', { class: 'th-moisligne__c', text: du.length + ' saisie' + (du.length > 1 ? 's' : '') }),
                        el('span', { class: 'th-moisligne__h', text: fmtH(somme(du)) }),
                        el('span', { class: 'th-moisligne__f', text: '›', 'aria-hidden': 'true' })
                    ]))
                })(m)
            }
            return panneau
        }

        // Vues semaine et mois : détail jour par jour, du plus récent au plus ancien.
        panneau.appendChild(el('div', { class: 'th-soustitre th-soustitre--detail', text: 'Détail' }))
        for (var j = p.fin; j >= p.debut; j = addDays(j, -1)) {
            var du = liste.filter(function (e) { return e.jour === j })
            if (!du.length) { continue }
            panneau.appendChild(el('div', { class: 'th-jourhead' }, [el('span', { class: 'th-jourhead__d', text: jourLong(j) }), el('span', { class: 'th-jourhead__h', text: fmtH(somme(du)) })]))
            du.forEach(function (e) { panneau.appendChild(S.edition && S.edition.id === e.id ? ligneCorrection(e) : ligneSaisie(e)) })
        }
        return panneau
    }

    function ligneSaisie(e) {
        var suppr = bouton('th-icone th-icone--suppr', '✕', null, { 'aria-label': 'Supprimer cette saisie', title: 'Supprimer' })
        var arme = false
        // 1re touche : « Supprimer ? » en rouge ; 2e touche dans les 3 s : on supprime (évite les erreurs de doigt).
        suppr.addEventListener('click', function () {
            if (!arme) { arme = true; suppr.textContent = 'Supprimer ?'; suppr.classList.add('th-icone--arme'); setTimeout(function () { arme = false; suppr.textContent = '✕'; suppr.classList.remove('th-icone--arme') }, 3000); return }
            api('DELETE', '/api/heures/' + e.id).then(chargerSaisies).then(function () { toast('Saisie supprimée'); render() }).catch(function (err) { toast(err.message, true) })
        })
        return el('div', { class: 'th-ligne' }, [
            el('div', { class: 'th-ligne__b' }, [
                el('div', { class: 'th-ligne__t', text: e.tacheTitre || ('Tâche n° ' + e.tacheId) }),
                el('div', { class: 'th-ligne__m', text: [e.projetNom, e.commentaire].filter(Boolean).join(' · ') })
            ]),
            el('span', { class: 'th-ligne__h', text: fmtH(e.heures || 0) }),
            bouton('th-icone', '✎', function () { S.edition = { id: e.id, heures: e.heures || 0.5, comm: e.commentaire || '' }; render() }, { 'aria-label': 'Corriger cette saisie', title: 'Corriger' }),
            suppr
        ])
    }

    // Correction : durée (+/−) et précision. La tâche et le jour ne changent pas (sinon : supprimer et ressaisir).
    function ligneCorrection(e) {
        var E = S.edition
        function regle(v) { E.heures = Math.max(0.5, Math.min(24, Math.round(v * 2) / 2)); render() }
        var prec = el('input', { type: 'text', class: 'th-input', placeholder: 'Ce qui a été fait (facultatif)', value: E.comm })
        prec.addEventListener('input', function () { E.comm = prec.value })
        function valider() {
            api('PUT', '/api/heures/' + e.id, { heures: E.heures, commentaire: E.comm })
                .then(chargerSaisies).then(function () { S.edition = null; toast('Saisie corrigée'); render() })
                .catch(function (err) { toast(err.message || 'Correction impossible.', true) })
        }
        return el('div', { class: 'th-ligne th-ligne--edit' }, [
            el('div', { class: 'th-ligne__t', text: e.tacheTitre || ('Tâche n° ' + e.tacheId) }),
            el('div', { class: 'th-pas th-pas--petit' }, [
                bouton('th-pas__btn', '−', function () { regle(E.heures - 0.5) }, { 'aria-label': 'Retirer une demi-heure' }),
                el('output', { class: 'th-pas__val', text: fmtH(E.heures) }),
                bouton('th-pas__btn', '+', function () { regle(E.heures + 0.5) }, { 'aria-label': 'Ajouter une demi-heure' })
            ]),
            prec,
            el('div', { class: 'th-editbtns' }, [
                bouton('th-btn2', 'Annuler', function () { S.edition = null; render() }),
                bouton('th-btn2 th-btn2--plein', 'Enregistrer', valider)
            ])
        ])
    }

    // ---------- chargement ----------
    function chargerSaisies() { return api('GET', '/api/heures').then(function (r) { S.entries = r || [] }) }
    Promise.all([chargerSaisies(), api('GET', '/api/board').then(function (b) { S.board = b || [] })])
        .then(function () {
            // Le cas le plus fréquent : on continue sur la même tâche que la dernière fois.
            var t = derniereTache(); if (t) { S.tache = t; S.projetId = t.projetId }
            render()
        })
        .catch(function (e) { root.innerHTML = ''; root.appendChild(el('p', { class: 'th-vide', text: e.message || 'Chargement impossible.' })) })
})()
