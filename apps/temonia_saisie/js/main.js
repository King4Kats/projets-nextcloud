/* SPDX-FileCopyrightText: 2026 Flavien Auvray
 * SPDX-License-Identifier: AGPL-3.0-or-later */
/* l'association — Espace équipe « Suivi de projet ». Design d'après Claude Design
   (Wireframes Nextcloud) : sidebar Projets/Vues + en-tête + table sobre.
   Couleurs mappées sur le thème Nextcloud (clair ET sombre). */
(function () {
    'use strict'
    var root = document.getElementById('tp-app')
    if (!root) { return }
    var base = OC.generateUrl('/apps/temonia_saisie')
    var currentUid = (typeof OC !== 'undefined' && OC.getCurrentUser) ? (OC.getCurrentUser().uid || '') : ''
    // isManager = direction (peut gérer projets/phases et ASSIGNER les tâches).
    // isAdmin   = administrateur Nextcloud : seul à voir l'écran « Administration ».
    var isManager = root.dataset.isManager === '1'
    var isAdmin = root.dataset.isAdmin === '1'
    // Mots propres à la structure (« Équipe », « Partenaires »…), réglés côté serveur
    // (temonia_pilotage › libelles). Valeurs de secours si l'attribut manque.
    var L = Object.assign({ organisation: 'Association', equipe: 'Équipe', salarie: 'Salarié', salaries: 'Salariés', benevole: 'Bénévole', benevoles: 'Bénévoles', pompier: 'Partenaire', pompiers: 'Partenaires', autre: 'Autre', dossier_exports: 'Exports' },
        (function () { try { return JSON.parse(root.dataset.libelles || '{}') } catch (e) { return {} } })())

    var STATUTS = [{ k: 'a_faire', l: 'À faire' }, { k: 'en_cours', l: 'En cours' }, { k: 'fait', l: 'Fait' }]
    var ST = { a_faire: 'À faire', en_cours: 'En cours', fait: 'Fait' }
    var DOT = { a_faire: '#b9bcc0', en_cours: '#5c7593', fait: '#7f9280' }

    // État global de l'appli (une seule page qui se redessine). Chaque champ :
    //  view      : écran affiché (projet | dashboard | mestaches | blocages | planneur | admin)
    //  projet    : projet couramment ouvert ; projets : liste des projets accessibles
    //  board     : toutes les tâches-feuilles accessibles (pour « Mes tâches » / « Blocages »)
    //  tree      : structure du projet ouvert { activites, taches, leafReel, leafBloque }
    //  usersMap  : uid → nom (résolution des noms d'assignés/affectés)
    //  open      : plis/déplis mémorisés par clé de ligne ; avDetail : id du projet en vue avancement
    // Page d'arrivée par défaut : l'admin (direction) tombe sur l'Administration ; tout le monde
    // d'autre sur le Tableau de bord. (On peut ensuite naviguer via le menu.)
    var state = { view: isAdmin ? 'admin' : 'dashboard', projet: null, projets: null, board: null, tree: null, usersMap: {}, groupsList: [], groupsMap: {}, open: {}, addProjet: '', principals: { users: [], groups: [] }, avDetail: null,
        // dash = tableau de bord à widgets : stats (données agrégées non filtrées, en cache),
        // layout (disposition perso), edit (mode personnalisation), cache (stats filtrées par
        // widget, indexées par clé de filtre), fetching (clés en cours de chargement).
        dash: { stats: null, layout: null, edit: false, cache: {}, fetching: {} },
        // admin = écran d'administration (direction). tab = sous-onglet ('heures' | 'notif') ;
        // data = stats heures ; filtre = sélection des filtres ; notifCount = nb de notifications (pastille).
        admin: { tab: 'heures', data: null, filtre: { personne: '', projet: '', equipe: '', periode: '' }, notifCount: 0 },
        // planneur = Vue Planneur (Gantt). projet = filtre projet ('' = tous) ; scope = 'all' | 'mine' ;
        // mode = 'hier' (projet→phase→tâche) | 'person' (par personne) ; zoom = clé d'échelle ;
        // open = plis/déplis des lignes ; data = réponse /api/planneur en cache.
        planneur: { projet: '', scope: 'all', mode: 'hier', zoom: 'M', open: {}, data: null, scroll: null } } // scroll = position horizontale mémorisée (null = cadrer sur aujourd'hui)

    // ---------- helpers ----------
    // Date du jour au format 'AAAA-MM-JJ' (le format attendu par l'API et les <input type=date>).
    function todayISO() { var d = new Date(); return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0') }
    // Appel à l'API de l'app. Renvoie une Promise résolue avec le JSON (ou null si 204).
    // requesttoken = jeton anti-CSRF de Nextcloud, obligatoire sur les requêtes qui écrivent.
    // En cas d'erreur HTTP, on lève une Error avec le message renvoyé par le serveur.
    function api(method, path, body) {
        var opts = { method: method, headers: { requesttoken: OC.requestToken, 'OCS-APIRequest': 'true' } }
        if (body) { opts.headers['Content-Type'] = 'application/json'; opts.body = JSON.stringify(body) }
        return fetch(base + path, opts).then(function (r) {
            if (!r.ok) { return r.json().then(function (j) { throw new Error(j.message || 'Erreur ' + r.status) }) }
            return r.status === 204 ? null : r.json()
        })
    }
    // Fabrique un élément DOM. attrs : { class, text, html, onclick..., ou attribut HTML }.
    // Écrit sans framework (vanilla JS) pour rester léger et sans dépendance de build.
    function el(tag, attrs, children) {
        var e = document.createElement(tag)
        attrs = attrs || {}
        Object.keys(attrs).forEach(function (k) {
            if (attrs[k] == null) { return }
            if (k === 'class') { e.className = attrs[k] }
            else if (k === 'text') { e.textContent = attrs[k] }
            else if (k === 'html') { e.innerHTML = attrs[k] }
            else if (k.indexOf('on') === 0) { e.addEventListener(k.slice(2), attrs[k]) }
            else { e.setAttribute(k, attrs[k]) }
        })
        ;(children || []).forEach(function (c) { if (c) { e.appendChild(c) } })
        return e
    }
    var fmtH = function (h) { return (h || h === 0) ? (String(Math.round(h * 100) / 100).replace('.', ',') + ' h') : '—' }
    function fmtDateFr(j) { try { return new Date(j + 'T00:00:00').toLocaleDateString('fr-FR', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' }) } catch (e) { return j } }
    function initiales(n) { if (!n) { return '?' } var p = n.trim().split(/\s+/); return ((p[0] || '')[0] + (p[1] ? p[1][0] : '')).toUpperCase() || '?' }
    // Un « assigné » est soit une personne (uid), soit un groupe ("group:<gid>").
    // Groupe OU équipe virtuelle « Équipe » (team:) → même style d'affichage (pastille de groupe).
    function isGroupAssignee(a) { return typeof a === 'string' && (a.indexOf('group:') === 0 || a.indexOf('team:') === 0) }
    function nameOf(a) { if (!a) { return '' } if (a === 'team:temonia') { return L.equipe } if (a.indexOf('group:') === 0) { var g = a.slice(6); return state.groupsMap[g] || g } return state.usersMap[a] || a }
    // Avatar d'un assigné : rond coloré (personne) ou pastille de groupe (initiale, style distinct), ou « — » si vide.
    function assigneeAva(a, extra) {
        if (!a) { return el('span', { class: 'sp-ava sp-ava--none' + (extra ? ' ' + extra : ''), text: '—' }) }
        // Groupe : même pastille qu'une personne mais couleur « groupe » (sp-ava--grp), avec l'initiale du nom.
        if (isGroupAssignee(a)) { var gn = nameOf(a); return el('span', { class: 'sp-ava sp-ava--grp' + (extra ? ' ' + extra : ''), title: gn, text: (gn.trim().charAt(0) || 'G').toUpperCase() }) }
        return spAva(nameOf(a), a, extra)
    }
    function alertErr(e) { spToast(e.message || 'Une erreur est survenue.', true) }

    // ---- AIDE : petit bouton « ? » + info-bulle légère (pour les non-initiés) ----
    // helpBtn(texte) → un bouton rond « ? » qui affiche/masque une bulle explicative au clic.
    // La bulle se ferme en cliquant ailleurs ou avec Échap. Les retours à la ligne (\n) sont respectés.
    function helpBtn(texte) {
        var btn = el('button', { class: 'sp-help', type: 'button', title: 'Aide', 'aria-label': 'Aide', text: '?' })
        var pop = null
        function close() { if (pop) { pop.remove(); pop = null } document.removeEventListener('click', onDoc, true); document.removeEventListener('keydown', onKey) }
        function onDoc(e) { if (pop && e.target !== btn && !pop.contains(e.target) && !btn.contains(e.target)) { close() } }
        function onKey(e) { if (e.key === 'Escape') { close() } }
        btn.onclick = function (e) {
            e.stopPropagation()
            if (pop) { close(); return }
            pop = el('div', { class: 'sp-help__pop' }, [el('div', { class: 'sp-help__txt', text: texte })])
            document.body.appendChild(pop)
            // Positionnement sous le bouton (fixe = coordonnées viewport de getBoundingClientRect).
            var r = btn.getBoundingClientRect(), w = 280
            pop.style.left = Math.max(8, Math.min(r.left, window.innerWidth - w - 8)) + 'px'
            pop.style.top = (r.bottom + 6) + 'px'
            setTimeout(function () { document.addEventListener('click', onDoc, true); document.addEventListener('keydown', onKey) }, 0)
        }
        return btn
    }
    // titleH(texte, aide) → un titre h1 avec, à côté, le bouton d'aide (si fourni).
    function titleH(texte, aide) { return el('div', { class: 'sp-tith' }, [el('h1', { class: 'sp-title', text: texte }), aide ? helpBtn(aide) : null]) }
    // Bandeau temporaire en bas de l'écran (remplace les alert() disgracieux du navigateur).
    function spToast(msg, isError) {
        var t = el('div', { class: 'sp-toast' + (isError ? ' sp-toast--err' : ''), text: msg })
        root.appendChild(t)
        requestAnimationFrame(function () { t.classList.add('sp-toast--in') })
        setTimeout(function () { t.classList.remove('sp-toast--in'); setTimeout(function () { if (t.parentNode) { t.parentNode.removeChild(t) } }, 300) }, 3600)
    }
    // Confirmation dans une modale à la charte de l'app (remplace confirm() du navigateur).
    // opts : { ok: libellé du bouton, danger: false pour un bouton non rouge, title }.
    function confirmModal(text, onOk, opts) {
        opts = opts || {}
        openFormModal(opts.title || 'Confirmation', el('div', { class: 'sp-confirm' }, [
            el('p', { class: 'sp-confirm__t', text: text }),
            el('div', { class: 'sp-modalbtns' }, [
                el('button', { class: 'sp-ghostbtn', text: 'Annuler', onclick: closePanel }),
                el('button', { class: opts.danger === false ? 'sp-addbtn' : 'sp-dangerbtn', text: opts.ok || 'Supprimer', onclick: function () { closePanel(); onOk() } })
            ])
        ]))
    }
    // Palette fixe de couleurs d'avatar (8 teintes lisibles sur fond clair comme sombre).
    var AVA_COLORS = ['#3b7dd8', '#7b6ef0', '#d8695f', '#2f9e78', '#c98a2a', '#b5578f', '#4a9fc4', '#6d8a4e']
    // Donne TOUJOURS la même couleur pour une même personne (couleur « stable »). Comment ?
    // On calcule un « hash » : un nombre dérivé des lettres de la clé (h = h*31 + code_lettre,
    // une recette classique). « >>> 0 » garde le nombre positif. Puis « h % longueur » choisit
    // un index dans la palette → même clé = même index = même couleur, à chaque fois.
    function avaColor(key) { if (!key) { return null } var h = 0; for (var i = 0; i < key.length; i++) { h = (h * 31 + key.charCodeAt(i)) >>> 0 } return AVA_COLORS[h % AVA_COLORS.length] }
    // Fabrique un avatar rond : les initiales sur un fond de la couleur stable calculée ci-dessus.
    function spAva(name, key, extra) { var c = avaColor(key || name); return el('span', { class: 'sp-ava' + (extra ? ' ' + extra : ''), title: name, text: initiales(name), style: c ? 'background:' + c + ';color:#fff' : null }) }

    // ---------- helpers d'arbre (Phase → Activité → Tâche → Sous-tâche) ----------
    // Les tâches forment un arbre via parentId. Une « feuille » = tâche sans enfant.
    function children(id) { return state.tree.taches.filter(function (t) { return t.parentId === id }) }
    // Tâches racines (1er niveau) d'une activité donnée (activiteId null = « Sans phase »).
    function rootTaches(activiteId) { return state.tree.taches.filter(function (t) { return (t.activiteId || null) === activiteId && !t.parentId }) }
    // Heures prévues d'une tâche : cumul récursif des enfants, ou valeur propre si c'est une feuille.
    function prevuOf(t) { var ch = children(t.id); return ch.length ? ch.reduce(function (a, c) { return a + prevuOf(c) }, 0) : (t.heuresPrevues || 0) }
    // Réel (heures pointées) cumulé : heures propres de la tâche + somme de sa descendance.
    // Feuille : réel fourni par l'API board (leafReel, toujours à jour). Parent : ses heures « propres »
    // (reelDirect, fourni par /api/ref/taches — cas d'une tâche pointée avant d'avoir des sous-tâches) + ses enfants.
    function reelOf(t) { var ch = children(t.id); if (!ch.length) { return state.tree.leafReel[t.id] || 0 } return (t.reelDirect || 0) + ch.reduce(function (a, c) { return a + reelOf(c) }, 0) }
    // Libellé du niveau selon la profondeur, pour les boutons « + » (Ajouter une activité / tâche / sous-tâche).
    function niveauNom(depth) { return depth <= 0 ? 'activité' : depth === 1 ? 'tâche' : 'sous-tâche' }
    function avecArticle(k) { return k === 'activité' ? 'l’activité' : 'la ' + k }

    // ============ SHELL : sidebar + main ============
    function render() {
        root.innerHTML = ''
        var main = el('div', { class: 'sp-main', id: 'sp-main' })
        var layout = el('div', { class: 'sp-layout' }, [sidebar(), main])
        // MOBILE : la barre latérale devient un TIROIR. On ajoute un voile (ferme au clic) et un
        // bouton « burger » (ouvre/ferme). Sur desktop, voile et burger sont masqués par la CSS.
        var backdrop = el('div', { class: 'sp-navbackdrop', onclick: function () { layout.classList.remove('sp-navopen') } })
        var burger = el('button', { class: 'sp-burger', title: 'Menu', 'aria-label': 'Ouvrir le menu', html: '&#9776;', onclick: function () { layout.classList.toggle('sp-navopen') } })
        layout.appendChild(backdrop)
        layout.appendChild(burger)
        // Choisir une entrée du menu referme le tiroir. Délégation sur le layout : survit au
        // remplacement de la sidebar par refreshSidebar() (qui ne recrée que .sp-side).
        layout.addEventListener('click', function (e) { if (e.target.closest && e.target.closest('.sp-navitem, .sp-navsub, .sp-navadd')) { layout.classList.remove('sp-navopen') } })
        root.appendChild(layout)
        routeMain()
    }
    // Recrée UNIQUEMENT la barre latérale (sans toucher au contenu principal ouvert) :
    // sert à rafraîchir le drapeau rouge « Blocages » après avoir signalé/levé un blocage.
    function refreshSidebar() {
        var layout = root.querySelector('.sp-layout'); if (!layout) { return }
        var old = layout.querySelector('.sp-side'); if (old) { layout.replaceChild(sidebar(), old) }
    }
    // Recalcule le nombre de notifications (direction) et rafraîchit la pastille du menu « Administration ».
    // Appelé au démarrage et après les actions qui changent l'état (validation, blocage levé, statut…).
    function refreshAdminBadge() { if (!isAdmin) { return } loadNotifs().then(refreshSidebar).catch(function () {}) }
    function sidebar() {
        var side = el('nav', { class: 'sp-side' })
        // (Pas de bandeau logo/texte dans la barre latérale : le nom de l'app est déjà
        //  affiché par Nextcloud dans la barre du haut.)
        // Groupe du haut : Tableau de bord (tout le monde) + Administration (la direction uniquement).
        var topItems = [
            el('div', { class: 'sp-navitem sp-navitem--vue' + (state.view === 'dashboard' ? ' sp-navitem--on' : ''), onclick: function () { state.view = 'dashboard'; state.avDetail = null; render() } }, [el('span', { class: 'sp-navtxt', text: 'Tableau de bord' })])
        ]
        if (isAdmin) {
            // Item « Administration » + pastille de notifications (propositions + blocages) si compteur > 0.
            var adminActive = state.view === 'admin'
            var adminKids = [el('span', { class: 'sp-navtxt', text: 'Administration' })]
            if (state.admin.notifCount) { adminKids.push(el('span', { class: 'sp-pastille', text: state.admin.notifCount > 99 ? '99+' : String(state.admin.notifCount) })) }
            topItems.push(el('div', { class: 'sp-navitem sp-navitem--vue' + (adminActive ? ' sp-navitem--on' : ''), onclick: function () { state.view = 'admin'; render() } }, adminKids))
            // Sous-menu déroulé SOUS « Administration » quand on est dessus : Heures · Saisie d'heures (admin) · Notifications.
            if (adminActive) {
                var curTab = state.admin.tab || 'heures'
                var subs = [['heures', 'Heures'], ['saisie', 'Saisie d’heures (admin)'], ['notif', 'Notifications']]
                subs.forEach(function (s) {
                    var on = curTab === s[0]
                    var kids = [el('span', { class: 'sp-navtxt', text: s[1] })]
                    if (s[0] === 'notif' && state.admin.notifCount) { kids.push(el('span', { class: 'sp-pastille', text: state.admin.notifCount > 99 ? '99+' : String(state.admin.notifCount) })) }
                    topItems.push(el('div', { class: 'sp-navsub' + (on ? ' sp-navsub--on' : ''), onclick: function () { state.admin.tab = s[0]; render() } }, kids))
                })
            }
        }
        side.appendChild(el('div', { class: 'sp-navgrp' }, topItems))
        // Projets
        var projs = (state.projets || []).filter(function (p) { return p.statut !== 'clos' })
        var pg = el('div', { class: 'sp-navgrp' }, [el('div', { class: 'sp-navlbl', text: 'Projets' })])
        projs.forEach(function (p) {
            var on = state.view === 'projet' && state.projet && state.projet.id === p.id
            pg.appendChild(el('div', { class: 'sp-navitem' + (on ? ' sp-navitem--on' : ''), onclick: function () { state.view = 'projet'; state.projet = p; state.open = {}; render(); openTree(p) } }, [
                el('span', { class: 'sp-navdot', style: 'background:' + (on ? 'var(--sp-acc)' : (p.couleur || 'var(--sp-chip)')) }),
                el('span', { class: 'sp-navtxt', text: p.nom })
            ]))
        })
        if (!projs.length) { pg.appendChild(el('div', { class: 'sp-navempty', text: 'Aucun projet accessible.' })) }
        if (isManager) { pg.appendChild(el('div', { class: 'sp-navadd', onclick: function () { openProjetModal(null) }, text: '+ Nouveau projet' })) }
        side.appendChild(pg)
        // Vues
        var blocked = (state.board || []).some(function (t) { return t.bloque })
        var vg = el('div', { class: 'sp-navgrp' }, [el('div', { class: 'sp-navlbl', text: 'Vues' })])
        function vue(id, label, dot) {
            return el('div', { class: 'sp-navitem sp-navitem--vue' + (state.view === id ? ' sp-navitem--on' : ''), onclick: function () { state.view = id; render() } }, [
                el('span', { class: 'sp-navtxt', text: label }),
                dot ? el('span', { class: 'sp-navflag' }) : null
            ])
        }
        vg.appendChild(vue('mestaches', 'Mes tâches', false))
        vg.appendChild(vue('blocages', 'Blocages', blocked))
        vg.appendChild(vue('planneur', 'Planneur', false))
        // Saisir ses heures : UN seul endroit, l'écran « Heures » (icône horloge, pensé pour le téléphone).
        // Plus d'onglet « Mes heures » ici (depuis la 0.24.3) : deux façons de saisir embrouillaient l'équipe.
        vg.appendChild(el('a', { class: 'sp-navitem sp-navitem--vue sp-navlink', href: base + '/heures' }, [el('span', { class: 'sp-navtxt', text: 'Saisir mes heures ›' })]))
        side.appendChild(vg)
        return side
    }
    function main() { return document.getElementById('sp-main') }
    // Aiguilleur : décide quel écran dessiner dans la zone principale selon state.view.
    function routeMain() {
        var M = main(); if (!M) { return }
        if (state.view === 'blocages') { renderBlocages() }
        else if (state.view === 'mestaches') { renderMesTaches() }
        else if (state.view === 'planneur') { renderPlanneur() }
        else if (state.view === 'dashboard') { renderDashboard() }
        else if (state.view === 'admin') { renderAdmin() }
        else {
            if (!state.projet) { renderPickProjet() }
            else if (state.tree) { renderProjet() }
            else { openTree(state.projet) }
        }
    }

    // Charge une seule fois les données globales (projets, board, noms d'utilisateurs,
    // et — pour la direction — la liste des personnes/groupes). Réutilise le cache si déjà chargé.
    function ensureData() {
        return Promise.all([
            state.projets ? Promise.resolve(state.projets) : api('GET', isManager ? '/api/manage/projets' : '/api/ref/projets'),
            state.board ? Promise.resolve(state.board) : api('GET', '/api/board').catch(function () { return [] }),
            Object.keys(state.usersMap).length ? Promise.resolve(null) : api('GET', '/api/ref/users').catch(function () { return [] }),
            (isManager && !state.principals.users.length) ? api('GET', '/api/manage/principals').catch(function () { return null }) : Promise.resolve(null),
            state.groupsList.length ? Promise.resolve(null) : api('GET', '/api/ref/groups').catch(function () { return [] })
        ]).then(function (res) {
            state.projets = res[0]
            state.board = res[1]
            if (res[2]) { res[2].forEach(function (u) { state.usersMap[u.uid] = u.displayName }) }
            if (res[3]) { state.principals = res[3]; (res[3].users || []).forEach(function (u) { state.usersMap[u.uid] = u.displayName }) }
            if (res[4]) { state.groupsList = res[4]; res[4].forEach(function (g) { state.groupsMap[g.gid] = g.displayName }) }
        })
    }

    // Charge la structure d'un projet (activités + tâches) et le réel/blocages par tâche-feuille,
    // range le tout dans state.tree, puis redessine.
    function openTree(p) {
        return Promise.all([api('GET', '/api/ref/activites?projet=' + p.id), api('GET', '/api/ref/taches?projet=' + p.id), api('GET', '/api/board').catch(function () { return [] })])
            .then(function (res) {
                state.board = res[2]
                var leafReel = {}, leafBloque = {}
                ;(res[2] || []).forEach(function (l) { if (String(l.projetId) === String(p.id)) { leafReel[l.id] = l.reel || 0; if (l.bloque) { leafBloque[l.id] = true } } })
                state.tree = { activites: res[0], taches: res[1], leafReel: leafReel, leafBloque: leafBloque }
                if (state.view === 'projet') { renderProjet() } else { render() }
            }).catch(function (e) { main().innerHTML = '<p class="sp-err">' + (e.message || 'Erreur') + '</p>' })
    }

    function renderPickProjet() {
        var M = main(); M.innerHTML = ''
        M.appendChild(el('div', { class: 'sp-empty', text: 'Sélectionnez un projet à gauche.' }))
        var first = (state.projets || []).filter(function (p) { return p.statut !== 'clos' })[0]
        if (first) { state.projet = first; openTree(first) }
    }

    // ============ VUE PROJET (table de gestion) ============
    // Toute l'équipe voit la même table (structure Phase → Activité → Tâche → Sous-tâche).
    // Les actions d'édition (+/✎/✕) n'apparaissent que pour la direction (voir renderManageProjet).
    function renderProjet() {
        return renderManageProjet()
    }

    function statutCell(k) {
        if (!k) { return el('div', { class: 'sp-c' }) }
        return el('div', { class: 'sp-c sp-stat' }, [el('span', { class: 'sp-sdot', style: 'background:' + DOT[k] }), el('span', { text: ST[k] })])
    }
    // Cellule numérique alignée à droite (heures prévues / réelles). '—' si vide.
    function numCell(v) { return el('div', { class: 'sp-c sp-num', text: v ? fmtH(v) : '—' }) }
    // Cellule « avancement » : (option) le « réel / prévu », une barre, et le % en texte.
    // Le % réel peut DÉPASSER 100 (ex. 105 % si on a pointé plus que prévu) ; la barre est plafonnée
    // à 100 % (sinon elle déborderait de sa piste). withText=true affiche « 2 h / 9 h » avant la barre.
    function avancementCell(reel, prevu, withText) {
        var pct = prevu > 0 ? Math.round(reel / prevu * 100) : (reel > 0 ? 100 : 0)
        var fill = Math.min(100, pct)                 // largeur de barre : jamais > 100 %
        var over = prevu > 0 && reel > prevu           // dépassement → couleur différente (sp-over)
        var kids = []
        if (withText) { kids.push(el('span', { class: 'sp-av__rp', text: fmtH(reel) + ' / ' + fmtH(prevu) })) }
        kids.push(el('div', { class: 'sp-av__bar' }, [el('div', { class: 'sp-av__fill' + (over ? ' sp-over' : ''), style: 'width:' + fill + '%' })]))
        kids.push(el('span', { class: 'sp-av__pct' + (over ? ' sp-over' : ''), text: pct + ' %' }))
        return el('div', { class: 'sp-c sp-av', title: fmtH(reel) + ' réel / ' + fmtH(prevu) + ' prévu' }, kids)
    }

    // Case à cocher d'une tâche-feuille : cochée = statut « fait ». Un clic bascule fait ⇄ à faire.
    function checkbox(t) {
        var done = t.statut === 'fait'
        var cb = el('span', { class: 'sp-cb' + (done ? ' sp-cb--on' : ''), text: done ? '✓' : '', title: 'Marquer fait / à faire', onclick: function (e) {
            e.stopPropagation() // le clic sur la case ne doit pas ouvrir la fiche de la tâche
            // On calcule le nouveau statut (l'inverse de l'actuel) et on l'envoie au serveur.
            var s = done ? 'a_faire' : 'fait'; t.statut = s
            api('PUT', '/api/board/' + t.id + '/statut', { statut: s }).then(function () { openTree(state.projet) }).catch(alertErr)
        } })
        return cb
    }

    // ============ ADMIN : GESTION (direction) ============
    function reloadStruct() { openTree(state.projet) }
    function iconBtn(txt, title, onclick, danger) { return el('button', { class: 'sp-iconbtn' + (danger ? ' sp-iconbtn--danger' : ''), text: txt, title: title, onclick: function (e) { e.stopPropagation(); onclick() } }) }
    // Un « champ de formulaire » = un libellé au-dessus d'un contrôle (input, select…).
    function mfield(label, node) { return el('div', { class: 'sp-mfield' }, [el('label', { text: label }), node]) }
    // Ouvre une MODALE générique (titre + contenu). Motif réutilisé partout (formulaires, confirmations…).
    // Structure : un voile plein écran (sp-pnl__wrap) contient le panneau (sp-pnl). Cliquer sur le voile
    // ferme la modale ; le stopPropagation du panneau empêche qu'un clic DANS le panneau la ferme aussi.
    function openFormModal(titleText, bodyNode, aide) {
        closePanel() // on ferme d'abord une éventuelle modale déjà ouverte
        var panel = el('div', { class: 'sp-pnl sp-pnl--form', onclick: function (e) { e.stopPropagation() } }, [
            el('div', { class: 'sp-pnl__head' }, [el('div', { class: 'sp-pnl__crumbrow' }, [el('div', { class: 'sp-tith' }, [el('h2', { class: 'sp-pnl__title', text: titleText }), aide ? helpBtn(aide) : null]), el('button', { class: 'sp-pnl__x', text: '✕', onclick: closePanel })])]),
            el('div', { class: 'sp-modalbody' }, [bodyNode])
        ])
        // On ajoute la modale à « root » (#tp-app) et non à document.body : ainsi elle hérite de nos
        // variables de couleur --sp-* définies sur #tp-app (sinon les couleurs de l'app seraient perdues).
        root.appendChild(el('div', { class: 'sp-pnl__wrap', id: 'sp-panel-wrap', onclick: closePanel }, [panel]))
        document.addEventListener('keydown', escClose) // la touche Échap ferme aussi la modale
    }
    function openHistoModal(p) {
        openFormModal('Historique — ' + p.nom, el('p', { class: 'sp-muted', text: 'Chargement…' }))
        api('GET', '/api/manage/historique/projet?projet=' + p.id).then(function (list) {
            var body = document.querySelector('#sp-panel-wrap .sp-modalbody'); if (!body) { return }
            body.innerHTML = ''
            if (!list.length) { body.appendChild(el('p', { class: 'sp-muted', text: 'Aucun événement enregistré.' })); return }
            var wrap = el('div', { class: 'sp-histlist' })
            list.forEach(function (h) {
                wrap.appendChild(el('div', { class: 'sp-hevt' }, [
                    spAva(h.auteur, h.auteur),
                    el('div', { class: 'sp-hevt__b' }, [
                        el('div', { class: 'sp-hevt__h' }, [el('span', { class: 'sp-pcm__a', text: h.auteur }), el('span', { class: 'sp-histbadge', text: h.elementType === 'phase' ? 'Phase' : (h.elementType === 'projet' ? 'Projet' : 'Tâche') }), el('span', { class: 'sp-pcm__ago', text: relTime(h.createdAt) })]),
                        el('div', { class: 'sp-hevt__x' }, [el('strong', { text: (h.elementTitre || '') + ' ' }), el('span', { text: '— ' + formatEvent(h) })])
                    ])
                ]))
            })
            body.appendChild(wrap)
        }).catch(alertErr)
    }

    function renderManageProjet() {
        var M = main(); M.innerHTML = ''
        var p = state.projet
        M.appendChild(el('div', { class: 'sp-head' }, [
            el('div', { class: 'sp-crumb' }, [el('span', { text: 'Projets' }), el('span', { class: 'sp-crumb__sep', text: '›' }), el('span', { class: 'sp-crumb__cur', text: p.nom })]),
            el('div', { class: 'sp-head__row' }, [
                el('div', {}, [el('h1', { class: 'sp-title', text: p.nom }), p.description ? el('div', { class: 'sp-sub', text: p.description }) : null]),
                el('div', { class: 'sp-ctrls' }, [
                    isManager ? el('button', { class: 'sp-ghostbtn', text: 'Modifier', onclick: function () { openProjetModal(p) } }) : null,
                    el('button', { class: 'sp-ghostbtn', text: 'Historique', onclick: function () { openHistoModal(p) } }),
                    el('button', { class: 'sp-ghostbtn', text: 'Avancement', onclick: function () { state.view = 'dashboard'; state.avDetail = p.id; render() } })
                ])
            ])
        ]))
        if (isManager) { M.appendChild(membresBlock(p)) }
        M.appendChild(el('div', { class: 'sp-secblock__lbl', style: 'margin-top:20px' }, [el('span', { text: 'Phases · activités · tâches' }), helpBtn('L’arborescence du projet : Phases › Tâches › Sous-tâches.\n\n• Cliquez le TITRE d’une (sous-)tâche pour l’ouvrir (statut, heures, commentaires, affectations).\n• Le « + » au bout d’une ligne permet de proposer une nouvelle sous-tâche ; le « ✎ » modifie, le « ✕ » supprime (réservés à la direction).\n• La flèche ▸ replie/déplie.\n• Sécurité : une tâche ne passe « Fait » que si toutes ses sous-tâches le sont déjà.\n• Une ligne grisée « à valider » est une proposition d’un membre, en attente de validation.'), isManager ? el('button', { class: 'sp-addbtn', text: '+ Phase', onclick: function () { openPhaseModal(null, p.id) } }) : null]))
        var table = el('div', { class: 'sp-table' })
        table.appendChild(el('div', { class: 'sp-hrow sp-hrow--mng' }, ['Sujet', 'Statut', 'Réf.', 'Avancement · réel / prévu', ''].map(function (h, i) { return el('div', { class: 'sp-h sp-h--' + (i === 0 ? 'sujet' : 'c'), text: h }) })))
        var body = el('div', {})
        state.tree.activites.forEach(function (a) { mngPhase(body, a) })
        if (rootTaches(null).length) { mngPhase(body, null) }
        table.appendChild(body)
        M.appendChild(table)
        if (!state.tree.activites.length && !rootTaches(null).length) { M.appendChild(el('div', { class: 'sp-empty', text: 'Aucune phase. Cliquez « + Phase » pour commencer.' })) }
    }
    function mngPhase(body, a) {
        var isSans = a === null
        var roots = rootTaches(isSans ? null : a.id)
        var key = 'p' + (isSans ? 'sans' : a.id), openS = state.open[key] !== false
        var prevu = roots.reduce(function (s, t) { return s + prevuOf(t) }, 0)
        var reel = roots.reduce(function (s, t) { return s + reelOf(t) }, 0)
        var suj = el('div', { class: 'sp-sujet' }, [
            el('span', { class: 'sp-caret', text: openS ? '▾' : '▸', onclick: function (e) { e.stopPropagation(); state.open[key] = !openS; renderProjet() } }),
            el('span', { class: 'sp-pname', text: isSans ? 'Sans phase' : a.nom }),
            isSans ? null : el('span', { class: 'sp-chip', text: 'prévu ' + fmtH(prevu) + (a.seuilHeures ? ' / seuil ' + fmtH(a.seuilHeures) : '') })
        ])
        var act = el('div', { class: 'sp-rowact' }, isSans ? [
            iconBtn('+', 'Ajouter une activité', function () { openTacheModal(null, { projetId: state.projet.id, activiteId: null, kind: 'activité' }) })
        ] : [
            iconBtn('+', 'Ajouter une activité', function () { openTacheModal(null, { projetId: state.projet.id, activiteId: a.id, kind: 'activité' }) }),
            isManager ? iconBtn('✎', 'Modifier la phase', function () { openPhaseModal(a, state.projet.id) }) : null,
            isManager ? iconBtn('✕', 'Supprimer la phase', function () { confirmModal('Supprimer la phase « ' + a.nom + ' » ? Ses activités passeront « sans phase ».', function () { api('DELETE', '/api/manage/activites/' + a.id).then(reloadStruct).catch(alertErr) }) }, true) : null
        ])
        body.appendChild(el('div', { class: 'sp-row sp-row--mng sp-row--phase' }, [suj, el('div', { class: 'sp-c' }), el('div', { class: 'sp-c' }), avancementCell(reel, prevu, true), act]))
        if (openS) { roots.forEach(function (t) { mngTache(body, t, 1) }) }
    }
    function mngTache(body, t, depth) {
        var ch = children(t.id), leaf = !ch.length
        var key = 't' + t.id, openS = state.open[key] !== false
        var childKind = niveauNom(depth + 1)
        var suj = el('div', { class: 'sp-sujet', style: 'padding-left:' + (depth * 22) + 'px' })
        if (!leaf) { suj.appendChild(el('span', { class: 'sp-caret', text: openS ? '▾' : '▸', onclick: function (e) { e.stopPropagation(); state.open[key] = !openS; renderProjet() } })) }
        else { suj.appendChild(checkbox(t)) }
        // Le titre ouvre la fiche pour TOUTE tâche (feuille OU parente) — avant, seules les feuilles
        // étaient cliquables, ce qui empêchait d'ouvrir « Communication », « Nom et visuel »… La flèche
        // (caret) sert toujours à déplier/replier ; c'est le titre qui ouvre le détail.
        suj.appendChild(el('span', { class: 'sp-tname sp-tname--link' + (t.statut === 'fait' ? ' sp-done' : ''), text: t.titre, title: 'Ouvrir la fiche', onclick: function (e) { e.stopPropagation(); openDetail(t.id) } }))
        // Badge « à valider » : proposition d'un membre en attente de la validation de la direction.
        if (t.valide === false) { suj.appendChild(el('span', { class: 'sp-tovalid', text: 'à valider' })) }
        // Le statut passe par /api/board (autorisé aux MEMBRES). Si le serveur refuse (ex. sous-tâches
        // non terminées), on affiche le message et on ré-affiche l'arbre pour remettre le bon statut.
        var sel = selectEl(STATUTS.map(function (c) { return { v: c.k, l: c.l } }), t.statut, function (v) { api('PUT', '/api/board/' + t.id + '/statut', { statut: v }).then(reloadStruct).catch(function (e) { alertErr(e); reloadStruct() }) })
        sel.className = 'sp-ministatut'
        var ava = assigneeAva(t.assignee)
        // « + » (proposer une sous-tâche) pour tout le monde ; « ✎ » modifier et « ✕ » supprimer
        // réservés à la direction (direction) — un membre ne modifie/supprime pas les tâches.
        var act = el('div', { class: 'sp-rowact' }, [
            iconBtn('+', 'Ajouter une ' + childKind, function () { openTacheModal(null, { projetId: state.projet.id, activiteId: t.activiteId, parent: t.id, kind: childKind }) }),
            isManager ? iconBtn('✎', 'Modifier', function () { openTacheModal(t, { projetId: state.projet.id, kind: niveauNom(depth) }) }) : null,
            isManager ? iconBtn('✕', 'Supprimer', function () { confirmModal('Supprimer « ' + t.titre + ' » et tout ce qu’elle contient (sous-tâches, heures, commentaires) ?', function () { api('DELETE', '/api/manage/taches/' + t.id).then(reloadStruct).catch(alertErr) }) }, true) : null
        ])
        body.appendChild(el('div', { class: 'sp-row sp-row--mng' + (t.valide === false ? ' sp-row--pending' : '') }, [suj, el('div', { class: 'sp-c' }, [sel]), el('div', { class: 'sp-c' }, [ava]), avancementCell(reelOf(t), prevuOf(t), true), act]))
        if (!leaf && openS) { ch.forEach(function (c) { mngTache(body, c, depth + 1) }) }
    }

    function labelOfMembre(m) { if (m.type === 'user') { var u = (state.principals.users || []).find(function (x) { return x.uid === m.id }); return u ? u.displayName : m.id } var g = (state.principals.groups || []).find(function (x) { return x.gid === m.id }); return g ? g.displayName : m.id }
    function saveMembres(p, membres) { api('PUT', '/api/manage/projets/' + p.id, { membres: membres }).then(function () { p.membres = membres; renderProjet() }).catch(alertErr) }
    function membresBlock(p) {
        var membres = p.membres || []
        var chips = el('div', { class: 'sp-memchips' })
        if (!membres.length) { chips.appendChild(el('span', { class: 'sp-muted', text: 'Aucun membre — ce projet n’est visible par personne dans l’espace équipe.' })) }
        membres.forEach(function (m) {
            chips.appendChild(el('span', { class: 'sp-memchip' }, [el('span', { text: labelOfMembre(m) + (m.type === 'group' ? ' (groupe)' : '') }), el('button', { class: 'sp-memchip__x', text: '×', onclick: function () { saveMembres(p, membres.filter(function (x) { return !(x.type === m.type && x.id === m.id) })) } })]))
        })
        function add(m) { if (!membres.some(function (x) { return x.type === m.type && x.id === m.id })) { saveMembres(p, membres.concat([m])) } }
        var addUser = selectEl([{ v: '', l: '+ personne…' }].concat((state.principals.users || []).map(function (u) { return { v: 'user:' + u.uid, l: u.displayName } })), '', function (v) { if (v) { add({ type: 'user', id: v.slice(5) }) } })
        var addGroup = selectEl([{ v: '', l: '+ groupe…' }].concat((state.principals.groups || []).map(function (g) { return { v: 'group:' + g.gid, l: g.displayName } })), '', function (v) { if (v) { add({ type: 'group', id: v.slice(6) }) } })
        return el('div', { class: 'sp-secblock' }, [el('div', { class: 'sp-lblrow' }, [el('div', { class: 'sp-secblock__lbl', text: 'Membres & accès' }), helpBtn('Qui a accès à CE projet. Ajoutez des personnes (+ personne…) ou des groupes entiers (+ groupe…).\n\nUn projet sans aucun membre n’est visible que par la direction. L’accès est propre à chaque projet.\n\nC’est indépendant de la « catégorie » (bénévole / salarié / pompier) qui, elle, est globale et sert aux statistiques de l’Administration.')]), chips, el('div', { class: 'sp-memadd' }, [addUser, addGroup])])
    }

    function openProjetModal(p) {
        var editing = !!p
        var nom = el('input', { type: 'text', placeholder: 'Nom du projet', value: editing ? p.nom : '' })
        var couleur = el('input', { type: 'color', value: editing ? (p.couleur || '#C4362A') : '#C4362A' })
        var statut = selectEl([{ v: 'actif', l: 'Actif' }, { v: 'pause', l: 'En pause' }, { v: 'clos', l: 'Clos' }], editing ? p.statut : 'actif', function () {})
        var desc = el('input', { type: 'text', placeholder: 'Description (facultatif)', value: editing ? (p.description || '') : '' })
        var err = el('p', { class: 'sp-err' })
        function afterSave(np) { closePanel(); state.projets = null; state.tree = null; ensureData().then(function () { state.view = 'projet'; state.projet = (state.projets || []).find(function (x) { return x.id === (np ? np.id : (p ? p.id : 0)) }) || np; if (state.projet) { openTree(state.projet) } else { render() } }) }
        function submit() { err.textContent = ''; if (!nom.value.trim()) { err.textContent = 'Nom requis.'; return } var body = { nom: nom.value, couleur: couleur.value, statut: statut.value, description: desc.value }; var req = editing ? api('PUT', '/api/manage/projets/' + p.id, body) : api('POST', '/api/manage/projets', body); req.then(afterSave).catch(function (e) { err.textContent = e.message }) }
        var btns = [el('button', { class: 'sp-ghostbtn', text: 'Annuler', onclick: closePanel }), el('button', { class: 'sp-addbtn', text: editing ? 'Enregistrer' : 'Créer le projet', onclick: submit })]
        if (editing) { btns.unshift(el('button', { class: 'sp-ghostbtn', style: 'margin-right:auto;color:var(--sp-red)', text: 'Supprimer', onclick: function () { confirmModal('Supprimer le projet « ' + p.nom + ' » et tout son contenu (phases, tâches, heures, commentaires) ?', function () { api('DELETE', '/api/manage/projets/' + p.id).then(function () { closePanel(); state.projets = null; state.projet = null; state.view = 'projet'; ensureData().then(render) }).catch(alertErr) }) } })) }
        openFormModal(editing ? 'Modifier le projet' : 'Nouveau projet', el('div', { class: 'sp-mform' }, [mfield('Nom', nom), el('div', { class: 'sp-mrow' }, [mfield('Couleur', couleur), mfield('Statut', statut)]), mfield('Description', desc), err, el('div', { class: 'sp-modalbtns' }, btns)]))
    }
    function openPhaseModal(a, projetId) {
        var editing = !!a
        var nom = el('input', { type: 'text', placeholder: 'Nom de la phase (ex. A — Ingénierie…)', value: editing ? a.nom : '' })
        var seuil = el('input', { type: 'number', step: '0.5', min: '0', placeholder: 'seuil h', value: (editing && a.seuilHeures != null) ? a.seuilHeures : '' })
        var err = el('p', { class: 'sp-err' })
        function submit() { err.textContent = ''; if (!nom.value.trim()) { err.textContent = 'Nom requis.'; return } var body = { nom: nom.value, seuilHeures: seuil.value === '' ? null : parseFloat(seuil.value) }; var req = editing ? api('PUT', '/api/manage/activites/' + a.id, body) : api('POST', '/api/manage/activites', Object.assign({ projet: projetId }, body)); req.then(function () { closePanel(); reloadStruct() }).catch(function (e) { err.textContent = e.message }) }
        openFormModal(editing ? 'Modifier la phase' : 'Nouvelle phase', el('div', { class: 'sp-mform' }, [mfield('Nom', nom), mfield('Seuil d’heures (informatif)', seuil), err, el('div', { class: 'sp-modalbtns' }, [el('button', { class: 'sp-ghostbtn', text: 'Annuler', onclick: closePanel }), el('button', { class: 'sp-addbtn', text: editing ? 'Enregistrer' : 'Créer la phase', onclick: submit })])]))
    }
    // Options du menu « personne ou groupe » (valeur = uid, ou "group:<gid>" pour un groupe) — partagé
    // par le formulaire de tâche et la section Affectations de la fiche.
    function principalSelectOptions() {
        return [{ v: '', l: '— personne ou groupe —' }, { v: 'team:temonia', l: L.equipe + ' (' + L.benevoles.toLowerCase() + ' + ' + L.salaries.toLowerCase() + ')' }]
            .concat((state.principals.users || []).map(function (u) { return { v: u.uid, l: u.displayName } }))
            .concat((state.principals.groups || []).map(function (g) { return { v: 'group:' + g.gid, l: g.displayName + ' (groupe)' } }))
    }
    // Nom lisible d'un principal (uid ou "group:<gid>") à partir des listes déjà chargées.
    function principalLabel(v) {
        if (v === 'team:temonia') { return L.equipe }
        if (v.indexOf('group:') === 0) { var g = (state.principals.groups || []).find(function (x) { return x.gid === v.slice(6) }); return (g ? g.displayName : v.slice(6)) + ' (groupe)' }
        var u = (state.principals.users || []).find(function (x) { return x.uid === v }); return u ? u.displayName : (state.usersMap[v] || v)
    }

    // Formulaire de création/édition d'une tâche (ou sous-tâche, ou « activité » = tâche parente).
    // TOUT est regroupé ici pour la direction : titre, référent optionnel, échéance, et surtout les
    // AFFECTATIONS (qui + heures prévues). Le prévu de la tâche = cumul des affectations (plus de
    // champ « Prévu » à saisir à la main). Les affectations sont gérées localement puis synchronisées
    // à l'enregistrement (indispensable en création : la tâche n'a pas encore d'id avant d'être créée).
    function openTacheModal(t, o) {
        var editing = !!t, kind = o.kind || 'tâche'
        var titre = el('input', { type: 'text', placeholder: 'Titre de ' + avecArticle(kind), value: editing ? t.titre : '' })
        // Dates début → échéance : servent au Planneur (barre du Gantt). Les deux sont facultatives.
        var dateDebut = el('input', { type: 'date', value: editing && t.dateDebut ? t.dateDebut : '' })
        var echeance = el('input', { type: 'date', value: editing && t.echeance ? t.echeance : '' })
        var err = el('p', { class: 'sp-err' })

        // RÉFÉRENT (optionnel) : une seule personne/groupe « responsable » de la tâche, SANS heures.
        //  - Direction : c'est le référent officiel de la tâche.
        //  - Membre : c'est une RECOMMANDATION (la tâche naît « à valider » ; la direction la voit dans ses
        //    notifications et l'accepte ou la refuse). D'où un libellé différent selon le rôle.
        // Les membres n'ont pas accès à /api/manage/principals (réservé direction) → on retombe sur
        // usersMap / groupsList (chargés pour tout le monde) pour peupler le menu.
        var refUsers = (state.principals.users && state.principals.users.length) ? state.principals.users : Object.keys(state.usersMap).map(function (uid) { return { uid: uid, displayName: state.usersMap[uid] } })
        var refGroups = (state.principals.groups && state.principals.groups.length) ? state.principals.groups : (state.groupsList || [])
        var refOpts = [{ v: '', l: '— aucun —' }]
            .concat(refUsers.map(function (u) { return { v: u.uid, l: u.displayName } }))
            .concat(refGroups.map(function (g) { return { v: 'group:' + g.gid, l: g.displayName + ' (groupe)' } }))
        var referent = selectEl(refOpts, editing ? (t.assignee || '') : '', function () {})
        var referentLabel = isManager ? 'Référent (optionnel)' : 'Recommander un référent (optionnel)'

        // AFFECTATIONS gérées localement : affs = [{ principal, nom, heures, id|null }] ; toDelete = [ids à retirer].
        var affs = [], toDelete = []
        var listWrap = el('div', { class: 'sp-affedit' })
        function renderList() {
            listWrap.innerHTML = ''
            if (!affs.length) { listWrap.appendChild(el('p', { class: 'sp-muted', text: 'Aucun affecté pour l’instant.' })) }
            affs.forEach(function (a, i) {
                listWrap.appendChild(el('div', { class: 'sp-affeditrow' }, [
                    assigneeAva(a.principal, 'sp-ava--24'),
                    el('span', { class: 'sp-affedit__nom', text: a.nom }),
                    el('span', { class: 'sp-affedit__h', text: a.heures != null ? fmtH(a.heures) : '— h' }),
                    el('button', { class: 'sp-iconbtn', title: 'Retirer', text: '✕', onclick: function () { if (a.id) { toDelete.push(a.id) } affs.splice(i, 1); renderList() } })
                ]))
            })
        }
        // Ligne d'ajout : personne/groupe + heures prévues + bouton. Un même principal ré-ajouté met à jour ses heures.
        var whoSel = selectEl(principalSelectOptions(), '', function () {})
        var hInput = el('input', { type: 'number', step: '0.5', min: '0', placeholder: 'heures prévues' })
        function addAff() {
            var v = whoSel.value; if (!v) { err.textContent = 'Choisissez une personne ou un groupe.'; return }
            err.textContent = ''
            var heures = hInput.value === '' ? null : parseFloat(hInput.value)
            var found = affs.find(function (x) { return x.principal === v })
            if (found) { found.heures = heures } else { affs.push({ principal: v, nom: principalLabel(v), heures: heures, id: null }) }
            whoSel.value = ''; hInput.value = ''; renderList()
        }
        var addRow = el('div', { class: 'sp-affadd' }, [whoSel, hInput, el('button', { class: 'sp-pbtn2', text: 'Affecter', onclick: addAff })])

        // Préchargement des affectations existantes en édition ; création → liste vide.
        if (editing) { api('GET', '/api/manage/taches/' + t.id + '/affectations').then(function (rows) { affs = (rows || []).map(function (r) { return { principal: r.principal, nom: r.nom, heures: r.heuresPrevues, id: r.id } }); renderList() }).catch(function () { renderList() }) }
        else { renderList() }

        function submit() {
            err.textContent = ''
            if (!titre.value.trim()) { err.textContent = 'Titre requis.'; return }
            // Plus de heuresPrevues manuel : le prévu se déduit des affectations, côté serveur.
            var body = { titre: titre.value, dateDebut: dateDebut.value, echeance: echeance.value, assignee: referent.value }
            // Rappel : à la CRÉATION, l'assigné est conservé pour tous (recommandation si c'est un membre).
            // À l'ÉDITION, seule la direction peut le changer (le serveur ignore l'assigné d'un non-direction).
            var save
            if (editing) { save = api('PUT', '/api/manage/taches/' + t.id, body) }
            else { body.projet = o.projetId; body.activite = o.activiteId || null; if (o.parent) { body.parent = o.parent } save = api('POST', '/api/manage/taches', body) }
            save.then(function (res) {
                var id = editing ? t.id : (res && res.id)
                if (!id) { closePanel(); reloadStruct(); return }
                // Synchronise les affectations : upsert de chaque affecté (POST) + retrait des supprimés (DELETE).
                var ops = affs.map(function (a) { return api('POST', '/api/manage/taches/' + id + '/affectations', { principal: a.principal, heuresPrevues: a.heures }) })
                    .concat(toDelete.map(function (aid) { return api('DELETE', '/api/manage/affectations/' + aid) }))
                Promise.all(ops).then(function () { closePanel(); reloadStruct() }).catch(function (e) { err.textContent = e.message })
            }).catch(function (e) { err.textContent = e.message })
        }

        // Assemblage du formulaire.
        var rows = [mfield('Titre', titre)]
        rows.push(mfield(referentLabel, referent))
        rows.push(el('div', { class: 'sp-mrow' }, [mfield('Début', dateDebut), mfield('Échéance', echeance)]))
        // Bloc affectations réservé à la direction — les membres ne fixent pas les heures des autres.
        if (isManager) { rows.push(el('div', { class: 'sp-mfield' }, [el('div', { class: 'sp-lblrow' }, [el('label', { text: 'Affectations & heures prévues' }), helpBtn('Qui travaille sur cette tâche, et combien d’heures sont prévues pour chacun.\n\nLe « prévu » de la tâche = la somme de ces heures (calculé automatiquement, pas besoin de le saisir ailleurs).\n\nLe « référent » ci-dessus est seulement le responsable (sans heures). Vous pouvez affecter plusieurs personnes ou un groupe entier.')]), listWrap, addRow])) }
        rows.push(err)
        rows.push(el('div', { class: 'sp-modalbtns' }, [el('button', { class: 'sp-ghostbtn', text: 'Annuler', onclick: closePanel }), el('button', { class: 'sp-addbtn', text: editing ? 'Enregistrer' : ('Créer ' + avecArticle(kind)), onclick: submit })]))
        // Aide contextuelle : différente pour un membre (proposition) et pour la direction.
        var aideTache = isManager
            ? 'Renseignez le titre, un référent (le responsable, optionnel, sans heures), les dates (début → échéance, utilisées par le Planneur), et les affectations : qui travaille dessus et combien d’heures sont prévues pour chacun.\n\nLe « prévu » de la tâche se calcule tout seul = la somme des heures affectées.'
            : 'Vous PROPOSEZ une tâche : elle apparaîtra « à valider » (grisée) jusqu’à ce que la direction la valide.\n\nVous pouvez recommander un référent (qui pourrait s’en occuper) et des dates. La direction décide au final ; vous ne fixez pas les heures ni l’assignation officielle.'
        openFormModal(editing ? ('Modifier — ' + kind) : ('Nouvelle ' + kind), el('div', { class: 'sp-mform' }, rows), aideTache)
    }

    // ============ TABLEAU DE BORD À WIDGETS (style Salesforce) ============
    // Grille libre : chaque widget a une position (x,y) et une taille (w,h) exprimées
    // en cellules de grille. 12 colonnes de large ; hauteur de ligne = DASH_ROWH px.
    // La disposition est propre à chaque utilisateur (sauvegardée côté serveur).
    var DASH_COLS = 12, DASH_ROWH = 30, DASH_GAP = 8
    var dashDragging = false, dashResizeBound = false

    // --- petits graphes maison (aucune lib externe : la CSP Nextcloud les bloque) ---
    var SVGNS = 'http://www.w3.org/2000/svg'
    function svgEl(name, attrs) { var e = document.createElementNS(SVGNS, name); Object.keys(attrs || {}).forEach(function (k) { e.setAttribute(k, attrs[k]) }); return e }
    function frMois(m) { if (!m) { return '' } var p = String(m).split('-'); var noms = ['', 'janv.', 'févr.', 'mars', 'avr.', 'mai', 'juin', 'juil.', 'août', 'sept.', 'oct.', 'nov.', 'déc.']; return (noms[parseInt(p[1], 10)] || p[1]) + ' ' + p[0] }

    // Barres horizontales (avancement par projet, heures par personne).
    function dashBars(rows, unit) {
        var wrap = el('div', { class: 'sp-wbars' })
        if (!rows || !rows.length) { wrap.appendChild(el('p', { class: 'sp-muted', text: 'Aucune donnée.' })); return wrap }
        var max = Math.max.apply(null, rows.map(function (r) { return r.value || 0 })) || 1
        rows.forEach(function (r) {
            var pct = r.pct != null ? Math.min(100, r.pct) : Math.round((r.value || 0) / max * 100)
            // Une barre peut être cliquable (r.onClick) — ex. cliquer une personne pour zoomer dessus.
            wrap.appendChild(el('div', { class: 'sp-wbar' + (r.onClick ? ' sp-wbar--clic' : ''), title: r.onClick ? 'Voir le détail' : null, onclick: r.onClick || null }, [
                el('div', { class: 'sp-wbar__l', title: r.label, text: r.label }),
                el('div', { class: 'sp-wbar__track' }, [el('div', { class: 'sp-wbar__fill', style: 'width:' + pct + '%;background:' + (r.color || 'var(--sp-acc)') })]),
                el('div', { class: 'sp-wbar__v', text: r.sub != null ? r.sub : (unit === 'h' ? fmtH(r.value) : String(r.value)) })
            ]))
        })
        return wrap
    }

    // Donut (tâches par statut).
    // Astuce : pas de « camembert » à dessiner à la main. On empile des CERCLES identiques
    // et, sur chacun, on n'affiche qu'un ARC grâce au pointillé SVG (stroke-dasharray) :
    //   - dasharray = "longueur_visible longueur_invisible" → on ne montre qu'un morceau du tour ;
    //   - dashoffset = décalage du départ de ce morceau → on met chaque segment bout à bout.
    function dashDonut(segs) {
        var wrap = el('div', { class: 'sp-donut' })
        var total = segs.reduce(function (a, s) { return a + (s.value || 0) }, 0)
        if (total <= 0) { wrap.appendChild(el('p', { class: 'sp-muted', text: 'Aucune tâche.' })); return wrap }
        // R = rayon ; C = circonférence (2·π·R) = longueur totale du tour, notre « 100 % ».
        var R = 52, C = 2 * Math.PI * R, off = 0
        var svg = svgEl('svg', { viewBox: '0 0 120 120', class: 'sp-donut__svg' })
        segs.forEach(function (s) {
            if (!s.value) { return }
            // Part de ce segment convertie en longueur d'arc (sa portion de la circonférence).
            var len = s.value / total * C
            // Le cercle est tourné de -90° pour que le 1er segment démarre en haut (midi) et non à 3 h.
            svg.appendChild(svgEl('circle', { cx: '60', cy: '60', r: String(R), fill: 'none', stroke: s.color, 'stroke-width': '16', 'stroke-dasharray': len + ' ' + (C - len), 'stroke-dashoffset': String(-off), transform: 'rotate(-90 60 60)' }))
            off += len  // on avance le point de départ du prochain segment de la longueur déjà dessinée
        })
        var t = svgEl('text', { x: '60', y: '65', 'text-anchor': 'middle', class: 'sp-donut__c' }); t.textContent = String(total); svg.appendChild(t)
        var leg = el('div', { class: 'sp-donut__leg' })
        segs.forEach(function (s) { leg.appendChild(el('div', { class: 'sp-donut__li' }, [el('span', { class: 'sp-donut__dot', style: 'background:' + s.color }), el('span', { class: 'sp-donut__ll', text: s.label }), el('span', { class: 'sp-donut__v', text: String(s.value) })])) })
        wrap.appendChild(svg); wrap.appendChild(leg)
        return wrap
    }

    // Courbe d'aire (heures pointées par mois).
    // On calcule les coordonnées (x, y) de chaque point dans un repère SVG de W×H, puis on
    // relie les points par un tracé « path ». Rappel SVG : l'axe Y est INVERSÉ (0 = en haut),
    // d'où le « H - … » pour que les grandes valeurs montent vers le haut.
    function dashLine(points) {
        var wrap = el('div', { class: 'sp-line' })
        if (!points || !points.length) { wrap.appendChild(el('p', { class: 'sp-muted', text: 'Aucune heure saisie.' })); return wrap }
        var n = points.length, W = 400, H = 140, pad = 8  // W/H = taille du dessin ; pad = marge intérieure
        var max = Math.max.apply(null, points.map(function (p) { return p.heures })) || 1  // sommet de l'échelle (|| 1 : évite /0)
        var xstep = n > 1 ? (W - 2 * pad) / (n - 1) : 0    // écart horizontal entre 2 points
        // Pour chaque point : x = marge + i·pas ; y = bas - (valeur/max)·hauteur_utile (proportion de l'échelle).
        var pts = points.map(function (p, i) { return [pad + i * xstep, H - pad - (p.heures / max) * (H - 2 * pad)] })
        // « d » = instructions du tracé : M = aller au 1er point (move), L = tracer une ligne vers les suivants.
        var d = 'M' + pts.map(function (p) { return p[0].toFixed(1) + ',' + p[1].toFixed(1) }).join(' L')
        var svg = svgEl('svg', { viewBox: '0 0 ' + W + ' ' + H, preserveAspectRatio: 'none', class: 'sp-line__svg' })
        // 1re passe : l'AIRE sous la courbe = même tracé, prolongé jusqu'au bas puis refermé (Z) → polygone rempli.
        svg.appendChild(svgEl('path', { d: d + ' L' + pts[n - 1][0].toFixed(1) + ',' + (H - pad) + ' L' + pts[0][0].toFixed(1) + ',' + (H - pad) + ' Z', class: 'sp-line__area' }))
        // 2e passe : la LIGNE elle-même, par-dessus l'aire (sans remplissage).
        svg.appendChild(svgEl('path', { d: d, class: 'sp-line__stroke', fill: 'none' }))
        wrap.appendChild(svg)
        wrap.appendChild(el('div', { class: 'sp-line__x' }, [el('span', { text: frMois(points[0].mois) }), el('span', { class: 'sp-line__max', text: 'max ' + fmtH(max) }), el('span', { text: frMois(points[n - 1].mois) })]))
        return wrap
    }

    // Listes (mes tâches, blocages) — lignes cliquables ouvrant la fiche.
    function dashTaskList(rows, empty) {
        var wrap = el('div', { class: 'sp-wlist' })
        if (!rows || !rows.length) { wrap.appendChild(el('p', { class: 'sp-muted', text: empty || 'Rien à afficher.' })); return wrap }
        rows.forEach(function (t) {
            wrap.appendChild(el('div', { class: 'sp-wli', onclick: function () { openDetail(t.id) } }, [
                el('span', { class: 'sp-sdot', style: 'background:' + (DOT[t.statut] || '#ccc'), title: ST[t.statut] || '' }),
                el('div', { class: 'sp-wli__b' }, [el('div', { class: 'sp-wli__t', text: t.titre }), el('div', { class: 'sp-wli__m', text: (t.projetNom || '') + (t.heuresPrevues ? ' · prévu ' + fmtH(t.heuresPrevues) : '') })]),
                t.bloque ? el('span', { class: 'sp-bdot' }) : null
            ]))
        })
        return wrap
    }

    // Liste d'activité récente (journal d'historique).
    function dashActivity(rows) {
        var wrap = el('div', { class: 'sp-wlist' })
        if (!rows || !rows.length) { wrap.appendChild(el('p', { class: 'sp-muted', text: 'Aucune activité.' })); return wrap }
        rows.forEach(function (h) {
            wrap.appendChild(el('div', { class: 'sp-wact' }, [
                spAva(h.auteur, h.auteur),
                el('div', { class: 'sp-wact__b' }, [
                    el('div', { class: 'sp-wact__h' }, [el('span', { class: 'sp-pcm__a', text: h.auteur }), el('span', { class: 'sp-pcm__ago', text: relTime(h.createdAt) })]),
                    el('div', { class: 'sp-wact__x' }, [el('strong', { text: (h.elementTitre || '') + ' ' }), el('span', { text: '— ' + formatEvent(h) + (h.projetNom ? ' (' + h.projetNom + ')' : '') })])
                ])
            ]))
        })
        return wrap
    }

    // Fabrique le rendu d'une tuile KPI (grand chiffre + sous-titre optionnel).
    function kpiRender(getVal, getSub) { return function (body, S) { body.appendChild(el('div', { class: 'sp-kpi__v', text: getVal(S) })); var s = getSub && getSub(S); if (s) { body.appendChild(el('div', { class: 'sp-kpi__s', text: s })) } } }

    // Catalogue des widgets disponibles. kpi = couleur de tuile pleine ; sinon carte blanche.
    var WIDGETS = {
        kpi_projets: { title: 'Projets actifs', w: 2, h: 3, kpi: '#6C5CE7', render: kpiRender(function (S) { return String(S.kpis.projets) }) },
        kpi_taches_ouvertes: { title: 'Tâches ouvertes', w: 2, h: 3, kpi: '#2D74DA', render: kpiRender(function (S) { return String(S.kpis.tachesOuvertes) }, function (S) { return 'sur ' + S.kpis.tachesTotal }) },
        kpi_heures: { title: 'Heures pointées', w: 2, h: 3, kpi: '#2F9E78', render: kpiRender(function (S) { return fmtH(S.kpis.heures) }) },
        kpi_avancement: { title: 'Avancement', w: 3, h: 3, kpi: '#17A2B8', render: kpiRender(function (S) { return S.kpis.avancement + ' %' }, function (S) { return S.kpis.tachesFaites + '/' + S.kpis.tachesTotal + ' faites' }) },
        kpi_bloquees: { title: 'Tâches bloquées', w: 3, h: 3, kpi: '#C4362A', render: kpiRender(function (S) { return String(S.kpis.bloquees) }) },
        chart_avancement: { title: 'Avancement par projet', w: 6, h: 8, render: function (b, S) { b.appendChild(dashBars(S.avancementProjets.map(function (p) { return { label: p.nom, pct: p.pct, color: p.couleur || 'var(--sp-acc)', sub: p.pct + '%' } }))) } },
        chart_taches_statut: { title: 'Tâches par statut', w: 4, h: 8, render: function (b, S) { b.appendChild(dashDonut([{ label: 'À faire', value: S.tachesParStatut.a_faire, color: DOT.a_faire }, { label: 'En cours', value: S.tachesParStatut.en_cours, color: DOT.en_cours }, { label: 'Fait', value: S.tachesParStatut.fait, color: DOT.fait }])) } },
        chart_heures_mois: { title: 'Heures pointées dans le temps', w: 8, h: 8, render: function (b, S) { b.appendChild(dashLine(S.heuresParMois)) } },
        list_mes_taches: { title: 'Mes tâches', w: 4, h: 9, render: function (b, S) { b.appendChild(dashTaskList(S.mesTaches, 'Aucune tâche assignée.')) } },
        list_blocages: { title: 'Blocages en cours', w: 4, h: 9, render: function (b, S) { b.appendChild(dashTaskList(S.blocages, 'Aucun blocage.')) } },
        list_activite: { title: 'Activité récente', w: 4, h: 9, render: function (b, S) { b.appendChild(dashActivity(S.activite)) } }
    }

    // Chaque widget de la grille a une CLÉ D'INSTANCE unique (it.key), distincte de son
    // TYPE (it.i). C'est ce qui permet d'avoir plusieurs fois le même widget (ex. « Heures
    // pointées » sur deux projets différents, chacun avec son propre filtre).
    var dashKeyCounter = 0
    function newWidgetKey() { dashKeyCounter++; return 'w' + Date.now().toString(36) + '-' + dashKeyCounter }

    // Disposition par défaut (utilisée tant que l'utilisateur n'a rien personnalisé).
    function defaultLayout() {
        return [
            { i: 'kpi_projets', x: 0, y: 0, w: 2, h: 3 }, { i: 'kpi_taches_ouvertes', x: 2, y: 0, w: 2, h: 3 }, { i: 'kpi_heures', x: 4, y: 0, w: 2, h: 3 }, { i: 'kpi_avancement', x: 6, y: 0, w: 3, h: 3 }, { i: 'kpi_bloquees', x: 9, y: 0, w: 3, h: 3 },
            { i: 'chart_avancement', x: 0, y: 3, w: 12, h: 8 },
            { i: 'chart_taches_statut', x: 0, y: 11, w: 4, h: 8 }, { i: 'chart_heures_mois', x: 4, y: 11, w: 8, h: 8 },
            { i: 'list_mes_taches', x: 0, y: 19, w: 4, h: 9 }, { i: 'list_blocages', x: 4, y: 19, w: 4, h: 9 }, { i: 'list_activite', x: 8, y: 19, w: 4, h: 9 }
        ].map(function (it) { it.key = newWidgetKey(); return it })
    }
    // Nettoie une disposition chargée : on retire les types inconnus et on garantit une clé d'instance.
    function normalizeLayout(list) {
        var out = (list || []).filter(function (it) { return WIDGETS[it.i] })
        out.forEach(function (it) { if (!it.key) { it.key = newWidgetKey() } })
        return out.length ? out : defaultLayout()
    }

    function renderDashboard() {
        var M = main(); M.innerHTML = ''
        if (state.avDetail) { return renderAvDetail(M, state.avDetail) }
        var edit = state.dash.edit
        var ctrls = []
        if (edit) {
            ctrls.push(el('button', { class: 'sp-addbtn', text: '+ Widget', onclick: openWidgetCatalog }))
            ctrls.push(el('button', { class: 'sp-ghostbtn', text: '↺ Réinitialiser', onclick: resetDash }))
        }
        ctrls.push(el('button', { class: 'sp-ghostbtn', title: 'Recharger les données', text: '↻', onclick: function () { state.dash.stats = null; renderDashboard() } }))
        ctrls.push(el('button', { class: 'sp-ghostbtn' + (edit ? ' sp-ghostbtn--on' : ''), text: edit ? '✓ Terminé' : 'Personnaliser', onclick: function () { state.dash.edit = !state.dash.edit; renderDashboard() } }))
        M.appendChild(el('div', { class: 'sp-head' }, [el('div', { class: 'sp-head__row' }, [
            el('div', {}, [titleH('Tableau de bord', 'Votre vue d’ensemble : quelques chiffres clés (projets, tâches, heures, avancement) et des graphiques.\n\nCliquez « Personnaliser » pour réorganiser ou masquer les cases ; chaque case a son propre filtre « filtrer ».'), el('div', { class: 'sp-sub', text: edit ? 'Glissez pour déplacer, tirez le coin pour redimensionner — votre disposition est enregistrée.' : 'Vue d’ensemble de vos projets' })]),
            el('div', { class: 'sp-ctrls' }, ctrls)
        ])]))
        var grid = el('div', { class: 'sp-dashgrid', id: 'sp-dashgrid' }); M.appendChild(grid)
        ensureDashData().then(paintDash).catch(function (e) { grid.innerHTML = '<p class="sp-err">' + (e.message || 'Erreur de chargement') + '</p>' })
        dashResizeHook()
    }

    // Charge (une fois) les stats et la disposition de l'utilisateur.
    function ensureDashData() {
        var jobs = []
        if (!state.dash.stats) { jobs.push(api('GET', '/api/dashboard/stats').then(function (s) { state.dash.stats = s })) }
        if (!state.dash.layout) { jobs.push(api('GET', '/api/dashboard/layout').then(function (r) { state.dash.layout = (r.layout && r.layout.length) ? normalizeLayout(r.layout) : defaultLayout() })) }
        return Promise.all(jobs)
    }

    // Repositionne un widget dans la grille (cellules → pixels, avec une gouttière).
    // La grille raisonne en CELLULES (colonne x, ligne y, largeur w, hauteur h). Ici on
    // convertit ces cellules en PIXELS pour placer réellement la carte à l'écran :
    //   colW = largeur d'UNE colonne en pixels ; DASH_ROWH = hauteur d'UNE ligne ; DASH_GAP = gouttière.
    function positionWidget(w, it, colW) {
        w.style.left = (it.x * colW + DASH_GAP) + 'px'          // position horizontale = n° de colonne × largeur colonne
        w.style.top = (it.y * DASH_ROWH + DASH_GAP) + 'px'      // position verticale = n° de ligne × hauteur ligne
        w.style.width = (it.w * colW - 2 * DASH_GAP) + 'px'     // largeur = nb colonnes × colW, moins 2 gouttières (une de chaque côté)
        w.style.height = (it.h * DASH_ROWH - 2 * DASH_GAP) + 'px' // hauteur = nb lignes × ROWH, moins 2 gouttières
    }

    // --- Anti-chevauchement (repoussage + gravité, façon Salesforce/gridstack) ---
    function dashOverlap(a, b) { return a.x < b.x + b.w && a.x + a.w > b.x && a.y < b.y + b.h && a.y + a.h > b.y }
    function dashFirstHit(list, item) { for (var k = 0; k < list.length; k++) { if (dashOverlap(item, list[k])) { return list[k] } } return null }
    // Placement LIBRE (« float ») : chaque widget garde la position où tu le déposes.
    // On ne fait que DESCENDRE les widgets qui se chevauchent (aucune remontée automatique),
    // pour éviter l'effet « tout remonte en haut » qui rendait le placement pénible.
    // Les trous sont autorisés : tu ranges les cartes comme tu veux.
    function dashRelayout(movedKey) {
        var sorted = state.dash.layout.slice().sort(function (a, b) {
            if (a.key === movedKey && b.key !== movedKey) { return -1 }  // le widget manipulé est prioritaire (garde sa place)
            if (b.key === movedKey && a.key !== movedKey) { return 1 }
            return (a.y - b.y) || (a.x - b.x)                             // sinon, priorité au plus haut / plus à gauche
        })
        var placed = []
        sorted.forEach(function (l) {
            var c, guard = 0
            while ((c = dashFirstHit(placed, l)) && guard++ < 500) { l.y = c.y + c.h }  // descendre juste sous ce qui gêne
            placed.push(l)
        })
    }

    // --- Filtres par widget (projet + période) ---
    var DASH_PERIODES = [{ v: '', l: 'Toute la période' }, { v: '30', l: '30 derniers jours' }, { v: '90', l: '90 derniers jours' }, { v: '365', l: '12 derniers mois' }]
    function dashCfgKey(cfg) { if (!cfg) { return '' } var p = []; if (cfg.projet) { p.push('projet=' + cfg.projet) } if (cfg.periode) { p.push('periode=' + cfg.periode) } return p.join('&') }
    function dashFilterLabel(cfg) {
        if (!cfg) { return '' }
        var out = []
        if (cfg.projet) { var p = (state.projets || []).find(function (x) { return String(x.id) === String(cfg.projet) }); out.push(p ? p.nom : 'Projet') }
        if (cfg.periode) { out.push(cfg.periode + ' j') }
        return out.join(' · ')
    }
    // Renvoie les stats pour un widget selon son filtre (cache + dédoublonnage des requêtes).
    // null = en cours de chargement (le widget affiche « Chargement… » puis se redessine).
    function dashDataFor(cfg) {
        var key = dashCfgKey(cfg)
        if (!key) { return state.dash.stats }
        if (state.dash.cache[key]) { return state.dash.cache[key] }
        if (!state.dash.fetching[key]) {
            state.dash.fetching[key] = true
            api('GET', '/api/dashboard/stats?' + key).then(function (s) { state.dash.cache[key] = s; delete state.dash.fetching[key]; if (state.view === 'dashboard' && !state.avDetail) { paintDash() } }).catch(function () { delete state.dash.fetching[key] })
        }
        return null
    }
    function openWidgetFilter(item) {
        var cfg = item.cfg || {}
        var projOpts = [{ v: '', l: 'Tous les projets' }].concat((state.projets || []).map(function (p) { return { v: String(p.id), l: p.nom } }))
        var projSel = selectEl(projOpts, cfg.projet ? String(cfg.projet) : '', function () {})
        var perSel = selectEl(DASH_PERIODES, cfg.periode ? String(cfg.periode) : '', function () {})
        function apply() {
            var pr = projSel.value ? parseInt(projSel.value, 10) : null
            var pe = perSel.value ? parseInt(perSel.value, 10) : null
            if (pr || pe) { item.cfg = { projet: pr, periode: pe } } else { delete item.cfg }
            saveDash(); paintDash(); closePanel()
        }
        openFormModal('Filtrer « ' + (WIDGETS[item.i] ? WIDGETS[item.i].title : '') + ' »', el('div', { class: 'sp-mform' }, [
            mfield('Projet', projSel), mfield('Période', perSel),
            el('div', { class: 'sp-modalbtns' }, [el('button', { class: 'sp-ghostbtn', text: 'Réinitialiser', onclick: function () { delete item.cfg; saveDash(); paintDash(); closePanel() } }), el('button', { class: 'sp-addbtn', text: 'Appliquer', onclick: apply })])
        ]))
    }

    // Construit un widget (cadre + en-tête + corps). Réutilisé par la grille libre ET la pile mobile.
    function dashWidgetEl(it, def, data) {
        var w = el('div', { class: 'sp-widget' + (def.kpi ? ' sp-widget--kpi' : '') }); if (def.kpi) { w.style.background = def.kpi }
        var flabel = dashFilterLabel(it.cfg)
        var head = el('div', { class: 'sp-widget__h' }, [el('span', { class: 'sp-widget__t', text: def.title })])
        if ((state.projets || []).length) { head.appendChild(el('button', { class: 'sp-widget__f' + (flabel ? ' sp-widget__f--on' : ''), title: 'Filtrer ce widget', text: flabel || 'filtrer', onclick: function (e) { e.stopPropagation(); openWidgetFilter(it) } })) }
        if (state.dash.edit) { head.appendChild(el('button', { class: 'sp-widget__x', text: '✕', title: 'Retirer ce widget', onclick: function (e) { e.stopPropagation(); removeWidget(it.key) } })) }
        var body = el('div', { class: 'sp-widget__b' })
        if (data) { try { def.render(body, data) } catch (e) { body.appendChild(el('p', { class: 'sp-err', text: 'Widget indisponible' })) } }
        else { body.appendChild(el('p', { class: 'sp-muted', text: 'Chargement…' })) }
        w.appendChild(head); w.appendChild(body)
        return w
    }

    // Dessine tous les widgets. En dessous de 760px de large → bascule en pile (1 colonne).
    function paintDash() {
        var grid = document.getElementById('sp-dashgrid'); if (!grid || !state.dash.stats || !state.dash.layout) { return }
        if (grid.clientWidth < 760) { return paintDashStacked(grid) }
        grid.className = 'sp-dashgrid' + (state.dash.edit ? ' sp-dashgrid--edit' : '')
        grid.innerHTML = ''
        var colW = grid.clientWidth / DASH_COLS, maxY = 0
        state.dash.layout.forEach(function (it) {
            var def = WIDGETS[it.i]; if (!def) { return }
            maxY = Math.max(maxY, it.y + it.h)
            var w = dashWidgetEl(it, def, dashDataFor(it.cfg))
            positionWidget(w, it, colW)
            if (state.dash.edit) { var rz = el('div', { class: 'sp-widget__rz', title: 'Redimensionner' }); w.appendChild(rz); dashDrag(w, w.firstChild, it); dashResize(w, rz, it, def) }
            grid.appendChild(w)
        })
        grid.style.height = (maxY * DASH_ROWH + DASH_GAP) + 'px'
    }

    // Vue empilée (mobile / fenêtre étroite) : widgets pleine largeur, dans l'ordre, non déplaçables.
    function paintDashStacked(grid) {
        grid.className = 'sp-dashgrid sp-dashgrid--stacked'; grid.style.height = ''; grid.innerHTML = ''
        state.dash.layout.slice().sort(function (a, b) { return (a.y - b.y) || (a.x - b.x) }).forEach(function (it) {
            var def = WIDGETS[it.i]; if (!def) { return }
            var w = dashWidgetEl(it, def, dashDataFor(it.cfg))
            w.style.height = Math.max(130, it.h * DASH_ROWH) + 'px'
            grid.appendChild(w)
        })
    }

    // Glisser-déplacer : suit la souris en pixels, puis « aimante » sur la grille au relâcher.
    // Principe d'un drag « à la souris » : au clic (mousedown) on note le point de départ,
    // pendant le mouvement (mousemove) on déplace la carte en pixels, au relâcher (mouseup)
    // on nettoie et on convertit la position pixel finale en cellules de grille.
    function dashDrag(widgetEl, handle, item) {
        handle.style.cursor = 'move'
        handle.addEventListener('mousedown', function (e) {
            if (e.target.closest('.sp-widget__x, .sp-widget__f')) { return } // clic sur ✕ / filtre ≠ déplacement
            e.preventDefault()
            dashDragging = true
            var grid = document.getElementById('sp-dashgrid'), colW = grid.clientWidth / DASH_COLS // largeur d'une colonne (px)
            // On mémorise le point de départ : position souris (sx, sy) ET position carte (ol, ot).
            var sx = e.clientX, sy = e.clientY, ol = parseFloat(widgetEl.style.left), ot = parseFloat(widgetEl.style.top)
            widgetEl.classList.add('sp-widget--drag'); document.body.style.userSelect = 'none' // empêche la sélection de texte pendant le glissé
            // mm = « mouse move » : nouvelle position = position initiale + déplacement de la souris (delta).
            function mm(ev) { widgetEl.style.left = (ol + ev.clientX - sx) + 'px'; widgetEl.style.top = Math.max(DASH_GAP, ot + ev.clientY - sy) + 'px' }
            // mu = « mouse up » : fin du glissé.
            function mu() {
                // On débranche les écouteurs (sinon la carte suivrait la souris pour toujours) et on nettoie.
                document.removeEventListener('mousemove', mm); document.removeEventListener('mouseup', mu); dashDragging = false; widgetEl.classList.remove('sp-widget--drag'); document.body.style.userSelect = ''
                // AIMANTAGE : on reconvertit les pixels en n° de colonne/ligne (division + arrondi),
                // en bornant pour rester dans la grille (jamais négatif, jamais au-delà du bord droit).
                item.x = Math.max(0, Math.min(DASH_COLS - item.w, Math.round((parseFloat(widgetEl.style.left) - DASH_GAP) / colW)))
                item.y = Math.max(0, Math.round((parseFloat(widgetEl.style.top) - DASH_GAP) / DASH_ROWH))
                // On repousse les cartes qui se chevauchent, on sauvegarde, on redessine proprement.
                dashRelayout(item.key); saveDash(); paintDash()
            }
            document.addEventListener('mousemove', mm); document.addEventListener('mouseup', mu)
        })
    }

    // Redimensionner via la poignée en bas à droite (aimantée sur la grille au relâcher).
    // Même schéma que le drag, mais on modifie la TAILLE (width/height) au lieu de la position.
    function dashResize(widgetEl, handle, item, def) {
        handle.addEventListener('mousedown', function (e) {
            e.preventDefault(); e.stopPropagation() // stopPropagation : évite de déclencher AUSSI le drag de la carte
            dashDragging = true
            var grid = document.getElementById('sp-dashgrid'), colW = grid.clientWidth / DASH_COLS
            // Point de départ : souris (sx, sy) + taille initiale de la carte (ow, oh).
            var sx = e.clientX, sy = e.clientY, ow = parseFloat(widgetEl.style.width), oh = parseFloat(widgetEl.style.height)
            widgetEl.classList.add('sp-widget--drag'); document.body.style.userSelect = 'none'
            // Pendant le glissé : nouvelle taille = taille initiale + déplacement souris, avec un minimum (60/50 px).
            function mm(ev) { widgetEl.style.width = Math.max(60, ow + ev.clientX - sx) + 'px'; widgetEl.style.height = Math.max(50, oh + ev.clientY - sy) + 'px' }
            function mu() {
                document.removeEventListener('mousemove', mm); document.removeEventListener('mouseup', mu); dashDragging = false; widgetEl.classList.remove('sp-widget--drag'); document.body.style.userSelect = ''
                // AIMANTAGE de la taille : pixels → nb de colonnes/lignes, borné entre un mini (minW/minH,
                // défaut 2) et le bord droit de la grille (DASH_COLS - item.x = colonnes restantes).
                item.w = Math.max(def.minW || 2, Math.min(DASH_COLS - item.x, Math.round((parseFloat(widgetEl.style.width) + 2 * DASH_GAP) / colW)))
                item.h = Math.max(def.minH || 2, Math.round((parseFloat(widgetEl.style.height) + 2 * DASH_GAP) / DASH_ROWH))
                dashRelayout(item.key); saveDash(); paintDash()
            }
            document.addEventListener('mousemove', mm); document.addEventListener('mouseup', mu)
        })
    }

    function saveDash() { api('PUT', '/api/dashboard/layout', { layout: state.dash.layout }).catch(function () {}) }
    function removeWidget(key) { state.dash.layout = state.dash.layout.filter(function (it) { return it.key !== key }); dashRelayout(null); saveDash(); paintDash() }
    // Ajoute une NOUVELLE instance du type de widget (clé unique) → on peut en mettre plusieurs.
    function addWidget(id) { var maxY = 0; state.dash.layout.forEach(function (it) { maxY = Math.max(maxY, it.y + it.h) }); var d = WIDGETS[id]; state.dash.layout.push({ i: id, key: newWidgetKey(), x: 0, y: maxY, w: d.w, h: d.h }); saveDash(); paintDash() }
    function resetDash() { confirmModal('Réinitialiser le tableau de bord à la disposition par défaut ?', function () { state.dash.layout = defaultLayout(); saveDash(); paintDash() }, { ok: 'Réinitialiser', danger: false }) }
    function openWidgetCatalog() {
        // On peut ajouter PLUSIEURS fois le même widget (ex. filtré sur deux projets).
        // On indique juste combien d'exemplaires sont déjà en place.
        var count = {}; state.dash.layout.forEach(function (it) { count[it.i] = (count[it.i] || 0) + 1 })
        var body = el('div', { class: 'sp-catalog' })
        body.appendChild(el('p', { class: 'sp-muted', text: 'Astuce : ajoutez un widget plusieurs fois et filtrez chaque copie sur un projet différent.' }))
        Object.keys(WIDGETS).forEach(function (id) {
            var suffix = count[id] ? ' (' + count[id] + ' déjà)' : ''
            body.appendChild(el('div', { class: 'sp-catitem' }, [el('span', { text: WIDGETS[id].title + suffix }), el('button', { class: 'sp-addbtn', text: 'Ajouter', onclick: function () { addWidget(id); closePanel() } })]))
        })
        openFormModal('Ajouter un widget', body)
    }
    // Recalcule les positions quand la fenêtre est redimensionnée (la largeur de colonne change).
    function dashResizeHook() { if (dashResizeBound) { return } dashResizeBound = true; window.addEventListener('resize', function () { if (state.view === 'dashboard' && !state.avDetail && !dashDragging) { paintDash() } }) }
    function renderAvDetail(M, projetId) {
        var proj = (state.projets || []).find(function (p) { return p.id === projetId })
        M.appendChild(el('div', { class: 'sp-head' }, [
            el('button', { class: 'sp-back', text: '‹ Tableau de bord', onclick: function () { state.avDetail = null; render() } }),
            el('h1', { class: 'sp-title', text: (proj ? proj.nom : 'Projet') + ' — avancement' })
        ]))
        api('GET', '/api/manage/avancement/projet?projet=' + projetId).then(function (d) {
            var tt = d.totals, tpct = tt.prevu > 0 ? Math.min(100, Math.round(tt.reel / tt.prevu * 100)) : 0
            M.appendChild(el('div', { class: 'sp-card', style: 'margin-bottom:16px' }, [
                el('div', { class: 'sp-av' }, [el('div', { class: 'sp-av__bar', style: 'height:5px' }, [el('div', { class: 'sp-av__fill', style: 'width:' + tpct + '%' })]), el('span', { class: 'sp-av__pct', text: tpct + ' %' })]),
                el('div', { class: 'sp-card__m', style: 'margin-top:8px', text: 'réel ' + fmtH(tt.reel) + ' / prévu ' + fmtH(tt.prevu) + ' · ' + L.organisation + ' ' + fmtH(tt.temonia) + ' · ' + L.pompiers + ' ' + fmtH(tt.pompier) + (tt.autre ? ' · ' + L.autre + ' ' + fmtH(tt.autre) : '') })
            ]))
            var table = el('div', { class: 'sp-table' })
            table.appendChild(el('div', { class: 'sp-hrow' }, ['Phase / activité', '', '', 'Prévu', 'Réel', 'Avancement'].map(function (h, i) { return el('div', { class: 'sp-h sp-h--' + (i === 3 || i === 4 ? 'num' : (i === 0 ? 'sujet' : 'c')), text: h }) })))
            var body = el('div', {})
            d.activites.forEach(function (a) {
                body.appendChild(el('div', { class: 'sp-row sp-row--phase' }, [el('div', { class: 'sp-sujet' }, [el('span', { class: 'sp-pname', text: a.nom })]), el('div', { class: 'sp-c' }), el('div', { class: 'sp-c' }), numCell(a.prevu), numCell(a.reel), avancementCell(a.reel, a.prevu)]))
                a.taches.forEach(function (t) { body.appendChild(el('div', { class: 'sp-row' }, [el('div', { class: 'sp-sujet', style: 'padding-left:22px' }, [el('span', { class: 'sp-tname', text: t.titre })]), el('div', { class: 'sp-c' }), el('div', { class: 'sp-c' }), numCell(t.prevu), numCell(t.reel), avancementCell(t.reel, t.prevu)])) })
            })
            table.appendChild(body)
            M.appendChild(table)
        }).catch(alertErr)
    }

    // ============ VUE BLOCAGES ============
    function renderBlocages() {
        var M = main(); M.innerHTML = ''
        M.appendChild(el('div', { class: 'sp-head' }, [titleH('Blocages', 'Les tâches signalées comme « bloquées » (en attente de quelque chose : une décision, une info, une autre tâche…).\n\nCliquez une tâche pour l’ouvrir, comprendre le blocage et le lever quand c’est débloqué.'), el('div', { class: 'sp-sub', text: 'Tâches signalées en blocage' })]))
        var rows = (state.board || []).filter(function (t) { return t.bloque })
        if (!rows.length) { M.appendChild(el('div', { class: 'sp-empty', text: 'Aucun blocage en cours.' })); return }
        var list = el('div', { class: 'sp-cards' })
        rows.forEach(function (t) {
            list.appendChild(el('div', { class: 'sp-card sp-card--bloc', onclick: function () { openDetail(t.id) } }, [
                el('div', { class: 'sp-card__top' }, [el('span', { class: 'sp-bdot' }), el('span', { class: 'sp-card__proj', text: t.projetNom || '' })]),
                el('div', { class: 'sp-card__t', text: t.titre }),
                el('div', { class: 'sp-card__m', text: (t.assigneeName ? t.assigneeName + ' · ' : '') + 'prévu ' + fmtH(t.heuresPrevues) })
            ]))
        })
        M.appendChild(list)
    }
    function renderMesTaches() {
        var M = main(); M.innerHTML = ''
        M.appendChild(el('div', { class: 'sp-head' }, [titleH('Mes tâches', 'Toutes les tâches qui vous sont attribuées, tous projets confondus.\n\nCliquez une tâche pour l’ouvrir : changer son statut, saisir vos heures, écrire un commentaire.'), el('div', { class: 'sp-sub', text: 'Toutes vos tâches, tous projets' })]))
        var rows = (state.board || []).filter(function (t) { return t.mine })
        if (!rows.length) { M.appendChild(el('div', { class: 'sp-empty', text: 'Aucune tâche ne vous est assignée.' })); return }
        var by = {}; rows.forEach(function (t) { (by[t.projetNom] = by[t.projetNom] || []).push(t) })
        Object.keys(by).forEach(function (proj) {
            M.appendChild(el('div', { class: 'sp-grouplbl', text: proj }))
            var list = el('div', { class: 'sp-cards' })
            by[proj].forEach(function (t) {
                list.appendChild(el('div', { class: 'sp-card', onclick: function () { openDetail(t.id) } }, [
                    el('div', { class: 'sp-card__t', text: t.titre }),
                    el('div', { class: 'sp-card__m' }, [statutCell(t.statut), el('span', { text: ' · prévu ' + fmtH(t.heuresPrevues) + (t.bloque ? ' · bloquée' : '') })])
                ]))
            })
            M.appendChild(list)
        })
    }

    // ============ VUE PLANNEUR (diagramme de Gantt) ============
    // NIVEAUX DE ZOOM, du plus large (3 ans) au plus fin (heure). Pour chacun :
    //   pxDay = largeur d'UN JOUR en pixels (plus grand = plus zoomé) ;
    //   bands = les 2 bandes de l'en-tête [haut, bas] (année / trimestre / mois / semaine / jour) ;
    //   grid  = l'unité des traits verticaux de la grille (un trait par mois, par semaine ou par jour) ;
    //   pad   = marge en jours ajoutée [avant, après] les tâches pour aérer ;
    //   snap  = caler la plage sur des mois entiers (plus lisible aux zooms larges).
    // Chaque bouton = ce qu'on voit à l'écran (≈ 1 100 px de frise) : Semaine ≈ 2 semaines, Mois ≈ 5 semaines,
    // Trimestre ≈ 3 mois, Année ≈ 1 an, 3 ans. (Les tâches sont datées au JOUR : inutile de zoomer plus fin.)
    var PZOOM = [
        { k: 'Y3', l: '3 ans', pxDay: 0.8, bands: ['year', 'quarter'], grid: 'month', pad: [60, 120], snap: true },
        { k: 'Y', l: 'Année', pxDay: 2.5, bands: ['year', 'month'], grid: 'month', pad: [30, 60], snap: true },
        { k: 'Q', l: 'Trimestre', pxDay: 11, bands: ['month', 'week'], grid: 'week', pad: [10, 20], snap: true },
        { k: 'M', l: 'Mois', pxDay: 30, bands: ['month', 'day'], grid: 'week', pad: [3, 6] },
        { k: 'W', l: 'Semaine', pxDay: 80, bands: ['month', 'day'], grid: 'day', pad: [2, 4] }
    ]
    function planZoom() { return PZOOM.find(function (x) { return x.k === state.planneur.zoom }) || PZOOM[3] }
    // Utilitaires de dates locales (on manipule des jours, sans fuseau).
    function planParse(s) { if (!s) { return null } var p = String(s).slice(0, 10).split('-'); return new Date(+p[0], +p[1] - 1, +p[2]) }
    function planISO(d) { return d.getFullYear() + '-' + ('0' + (d.getMonth() + 1)).slice(-2) + '-' + ('0' + d.getDate()).slice(-2) }
    function planDiff(a, b) { return Math.round((b - a) / 86400000) }        // nb de jours entre 2 dates
    function planAdd(d, n) { var x = new Date(d); x.setDate(x.getDate() + n); return x }
    function planProj(d, id) { var p = (d.projets || []).find(function (x) { return x.id === id }); return p || null }
    // Numéro de semaine ISO (celle du 1er jeudi de l'année = S1), comme sur les agendas.
    function planWeekNo(d) {
        var x = new Date(Date.UTC(d.getFullYear(), d.getMonth(), d.getDate()))
        var day = x.getUTCDay() || 7                       // lundi=1 … dimanche=7
        x.setUTCDate(x.getUTCDate() + 4 - day)             // on se place le jeudi de la même semaine
        var y0 = new Date(Date.UTC(x.getUTCFullYear(), 0, 1))
        return Math.ceil(((x - y0) / 86400000 + 1) / 7)
    }
    var PMOIS = ['janv.', 'févr.', 'mars', 'avr.', 'mai', 'juin', 'juil.', 'août', 'sept.', 'oct.', 'nov.', 'déc.']
    var PWD = ['D', 'L', 'M', 'M', 'J', 'V', 'S']
    // Découpe la plage affichée (minD, totalDays jours) en TRANCHES d'une unité (année, trimestre, mois,
    // semaine, jour). Sert à l'en-tête et à la grille. Retourne [{ i: index du 1er jour, n: nb de jours,
    // d: date du 1er jour, label, sub (2e ligne facultative), we (week-end), today }].
    function planSegments(unit, minD, totalDays, today) {
        function keyOf(d) {                                 // 2 jours de même clé = même tranche
            if (unit === 'year') { return d.getFullYear() }
            if (unit === 'quarter') { return d.getFullYear() * 10 + Math.floor(d.getMonth() / 3) }
            if (unit === 'month') { return d.getFullYear() * 100 + d.getMonth() }
            if (unit === 'week') { return planISO(planAdd(d, -((d.getDay() + 6) % 7))) } // lundi de la semaine
            return planISO(d)
        }
        var segs = [], i = 0
        while (i < totalDays) {
            var d0 = planAdd(minD, i), k = keyOf(d0), n = 1
            while (i + n < totalDays && keyOf(planAdd(minD, i + n)) === k) { n++ }
            var s = { i: i, n: n, d: d0 }
            if (unit === 'year') { s.label = String(d0.getFullYear()) }
            else if (unit === 'quarter') { s.label = 'T' + (Math.floor(d0.getMonth() / 3) + 1) }
            else if (unit === 'month') { s.label = PMOIS[d0.getMonth()]; s.year = d0.getFullYear() }
            else if (unit === 'week') { s.label = 'S' + planWeekNo(d0); s.sub = d0.getDate() + ' ' + PMOIS[d0.getMonth()] }
            else { s.label = String(d0.getDate()); s.sub = PWD[d0.getDay()]; s.we = (d0.getDay() === 0 || d0.getDay() === 6); s.today = (+d0 === +today) }
            segs.push(s); i += n
        }
        return segs
    }

    function renderPlanneur() {
        var M = main(); M.innerHTML = ''
        M.appendChild(el('div', { class: 'sp-head' }, [el('div', { class: 'sp-head__row' }, [
            el('div', {}, [titleH('Planneur', 'Un calendrier visuel (façon planning) des tâches. Chaque barre va de la date de début à l’échéance.\n\n• Filtrez par projet, ou n’affichez que « les miennes ».\n• Basculez entre vue par hiérarchie (projet → phase → tâche) et par personne.\n• Repliez/dépliez avec les flèches (ou tout d’un coup), zoomez de 3 ans à la semaine : la date au centre de l’écran est conservée.\n• « Aujourd’hui » recadre sur la ligne du jour.\n• Les tâches sans dates sont regroupées en bas dans « Non planifiées ».'), el('div', { class: 'sp-sub', text: 'Vue calendrier des tâches (début → échéance)' })]),
            el('div', { class: 'sp-ctrls' }, [el('button', { class: 'sp-ghostbtn', title: 'Recharger', text: '↻', onclick: function () { state.planneur.data = null; renderPlanneur() } })])
        ])]))
        M.appendChild(planControls())
        var body = el('div', { id: 'sp-plan-body' }); M.appendChild(body)
        body.appendChild(el('p', { class: 'sp-muted', text: 'Chargement…' }))
        ensurePlanData().then(function () { paintPlanneur(body) }).catch(function (e) { body.innerHTML = '<p class="sp-err">' + (e.message || 'Erreur') + '</p>' })
    }
    // Charge /api/planneur (mis en cache tant que le filtre projet ne change pas).
    function ensurePlanData() {
        var key = state.planneur.projet || 'all'
        if (state.planneur.data && state.planneur._key === key) { return Promise.resolve() }
        var q = state.planneur.projet ? ('?projet=' + state.planneur.projet) : ''
        return api('GET', '/api/planneur' + q).then(function (d) { state.planneur.data = d; state.planneur._key = key })
    }
    // Redessine la barre de contrôles (pour rafraîchir le bouton « actif ») ET le Gantt.
    function planRefresh() {
        var c = document.getElementById('sp-plan-ctrls'); if (c) { c.replaceWith(planControls()) }
        paintPlanneur(document.getElementById('sp-plan-body'))
    }
    // Un pli est ouvert s'il a été cliqué ouvert, sinon selon le défaut (« Tout déplier / replier »).
    function planIsOpen(key) { var P = state.planneur; return P.open[key] !== undefined ? P.open[key] : P.openDefault !== false }
    // Barre de contrôles : filtre projet · portée (toutes/miennes) · mode (hiérarchie/personne) · plis · aujourd'hui · zoom.
    function planControls() {
        var p = state.planneur
        var projOpts = [{ v: '', l: 'Tous les projets' }].concat((state.projets || []).map(function (x) { return { v: String(x.id), l: x.nom } }))
        var projSel = selectEl(projOpts, p.projet, function (v) { p.projet = v; p.data = null; renderPlanneur() }) // change projet → recharge
        function seg(val, label, key) { return el('button', { class: 'sp-seg__b' + (p[key] === val ? ' sp-seg__b--on' : ''), text: label, onclick: function () { p[key] = val; planRefresh() } }) }
        var scopeSeg = el('div', { class: 'sp-seg' }, [seg('all', 'Toutes', 'scope'), seg('mine', 'Les miennes', 'scope')])
        var modeSeg = el('div', { class: 'sp-seg' }, [seg('hier', 'Hiérarchie', 'mode'), seg('person', 'Par personne', 'mode')])
        var foldSeg = el('div', { class: 'sp-seg' }, [
            el('button', { class: 'sp-seg__b', text: '⊞', title: 'Tout déplier', onclick: function () { p.open = {}; p.openDefault = true; planRefresh() } }),
            el('button', { class: 'sp-seg__b', text: '⊟', title: 'Tout replier', onclick: function () { p.open = {}; p.openDefault = false; planRefresh() } })
        ])
        var todayBtn = el('button', { class: 'sp-ghostbtn', text: 'Aujourd’hui', title: 'Recadrer sur la ligne du jour', onclick: function () { p.scroll = null; p.anchor = null; planRefresh() } })
        // Changer de zoom : on mémorise la date au CENTRE de l'écran (anchor) pour la garder au centre après.
        var zoomSeg = el('div', { class: 'sp-seg' }, PZOOM.map(function (z) {
            return el('button', { class: 'sp-seg__b' + (p.zoom === z.k ? ' sp-seg__b--on' : ''), text: z.l, onclick: function () {
                var g = document.querySelector('.sp-gantt')
                if (g && p._minD) { var nameW = (g.querySelector('.sp-gantt__corner') || {}).offsetWidth || 250; var vis = Math.max(120, g.clientWidth - nameW); p.anchor = planAdd(p._minD, Math.round((g.scrollLeft + vis / 2) / p._pxDay)) }
                p.zoom = z.k; planRefresh()
            } })
        }))
        return el('div', { class: 'sp-planctrls', id: 'sp-plan-ctrls' }, [projSel, scopeSeg, modeSeg, foldSeg, todayBtn, el('span', { class: 'sp-planctrls__z' }, [el('span', { class: 'sp-muted', text: 'Zoom' }), zoomSeg])])
    }

    // Cœur : construit l'arbre/les lignes selon le mode, calcule la plage de dates, et dessine le Gantt.
    function paintPlanneur(body) {
        if (!body) { return }
        body.innerHTML = ''
        var d = state.planneur.data; var P = state.planneur
        if (!d) { body.appendChild(el('p', { class: 'sp-muted', text: 'Aucune donnée.' })); return }

        // Une COULEUR PAR PHASE : palette fixe, attribuée dans l'ordre des phases de chaque projet
        // (déterministe : la même phase garde la même couleur d'un affichage à l'autre).
        var PHASE_PAL = ['#2f6db3', '#2f8f4e', '#c98a2a', '#8e44ad', '#c0392b', '#16a085', '#d35400', '#5d6d7e']
        var phaseColor = {}, phaseIdx = {}
        d.phases.forEach(function (ph) { var k = ph.projetId; phaseIdx[k] = phaseIdx[k] || 0; phaseColor[ph.id] = PHASE_PAL[phaseIdx[k] % PHASE_PAL.length]; phaseIdx[k]++ })
        // Couleur d'une tâche : celle de sa phase, sinon celle du projet.
        function taskColor(t) { return phaseColor[t.activiteId] || (planProj(d, t.projetId) || {}).couleur }
        // Index tâches : par id, et par parent (clé 0 = racines).
        var childrenOf = {}
        d.taches.forEach(function (t) { (childrenOf[t.parentId || 0] = childrenOf[t.parentId || 0] || []).push(t) })
        // Intervalle propre d'une tâche (ses dates), ou null.
        function ownSpan(t) { var ds = planParse(t.dateDebut), de = planParse(t.echeance); if (!ds && !de) { return null } var s = ds || de, e = de || ds; if (e < s) { var x = s; s = e; e = x } return { s: s, e: e } }
        // Intervalle « roulé » d'une tâche = ses dates propres élargies par celles de sa descendance.
        var spanMemo = {}
        function spanOf(t) {
            if (spanMemo[t.id] !== undefined) { return spanMemo[t.id] }
            var sp = ownSpan(t)
            ;(childrenOf[t.id] || []).forEach(function (c) { var cs = spanOf(c); if (cs) { if (!sp) { sp = { s: cs.s, e: cs.e } } else { if (cs.s < sp.s) { sp.s = cs.s } if (cs.e > sp.e) { sp.e = cs.e } } } })
            return spanMemo[t.id] = sp || null
        }
        function branchMine(t) { return t.mine || (childrenOf[t.id] || []).some(branchMine) }
        function keep(t) { return P.scope === 'all' || branchMine(t) } // filtre « les miennes »

        // Tâches NON PLANIFIÉES = feuilles sans dates propres (listées à part, sans barre).
        var unplanned = d.taches.filter(function (t) { var leaf = !(childrenOf[t.id] && childrenOf[t.id].length); return leaf && !t.dateDebut && !t.echeance && (P.scope === 'all' || t.mine) })

        // Construction des LIGNES.
        var rows = [], minD = null, maxD = null
        function extend(sp) { if (!sp) { return } if (!minD || sp.s < minD) { minD = sp.s } if (!maxD || sp.e > maxD) { maxD = sp.e } }
        function mergeSpans(list) { var m = null; list.forEach(function (t) { var sp = spanOf(t); if (sp) { if (!m) { m = { s: sp.s, e: sp.e } } else { if (sp.s < m.s) { m.s = sp.s } if (sp.e > m.e) { m.e = sp.e } } } }); return m }

        if (P.mode === 'hier') {
            var phasesByProj = {}; d.phases.forEach(function (ph) { (phasesByProj[ph.projetId] = phasesByProj[ph.projetId] || []).push(ph) })
            var rootsByProj = {}; (childrenOf[0] || []).forEach(function (t) { (rootsByProj[t.projetId] = rootsByProj[t.projetId] || []).push(t) })
            // Ajoute récursivement une tâche et (si dépliée) ses sous-tâches.
            function addTask(t, level) {
                var sp = spanOf(t); if (!sp || !keep(t)) { return }
                var kids = (childrenOf[t.id] || []).filter(function (c) { return spanOf(c) && keep(c) })
                var opened = planIsOpen('t' + t.id)
                rows.push({ key: 't' + t.id, label: t.titre, level: level, span: ownSpan(t) || sp, own: !!ownSpan(t), color: taskColor(t), taskId: t.id, hasKids: kids.length > 0, count: kids.length, opened: opened, statut: t.statut, mine: t.mine })
                extend(rows[rows.length - 1].span)
                if (opened) { kids.forEach(function (c) { addTask(c, level + 1) }) }
            }
            d.projets.forEach(function (pr) {
                var roots = (rootsByProj[pr.id] || []).filter(function (t) { return spanOf(t) && keep(t) })
                if (!roots.length) { return } // projet sans tâche planifiée (selon le filtre) → masqué
                var pOpen = planIsOpen('p' + pr.id)
                rows.push({ key: 'p' + pr.id, label: pr.nom, level: 0, kind: 'projet', color: pr.couleur, span: mergeSpans(roots), hasKids: true, count: roots.length, opened: pOpen }); extend(rows[rows.length - 1].span)
                if (!pOpen) { return }
                ;(phasesByProj[pr.id] || []).forEach(function (ph) {
                    var pr2 = roots.filter(function (t) { return t.activiteId === ph.id })
                    if (!pr2.length) { return }
                    var phOpen = planIsOpen('ph' + ph.id)
                    rows.push({ key: 'ph' + ph.id, label: ph.nom, level: 1, kind: 'phase', color: phaseColor[ph.id] || pr.couleur, span: mergeSpans(pr2), hasKids: true, count: pr2.length, opened: phOpen })
                    if (phOpen) { pr2.forEach(function (t) { addTask(t, 2) }) }
                })
                var sansPhase = roots.filter(function (t) { return !t.activiteId })
                if (sansPhase.length) { var sOpen = planIsOpen('sp' + pr.id); rows.push({ key: 'sp' + pr.id, label: 'Sans phase', level: 1, kind: 'phase', color: pr.couleur, span: mergeSpans(sansPhase), hasKids: true, count: sansPhase.length, opened: sOpen }); if (sOpen) { sansPhase.forEach(function (t) { addTask(t, 2) }) } }
            })
        } else {
            // Mode « par personne » : chaque tâche datée apparaît sous chacun de ses affectés (ou son référent, ou « Non assigné »).
            var byPers = {}
            d.taches.forEach(function (t) {
                if (!(t.dateDebut || t.echeance)) { return }               // non datée → section à part
                if (!(P.scope === 'all' || t.mine)) { return }
                var noms = (t.affectes && t.affectes.length) ? t.affectes.slice() : (t.assigneeName ? [t.assigneeName] : ['Non assigné'])
                noms.forEach(function (n) { (byPers[n] = byPers[n] || []).push(t) })
            })
            Object.keys(byPers).sort(function (a, b) { return a.localeCompare(b) }).forEach(function (nom) {
                var pOpen = planIsOpen('pers:' + nom)
                rows.push({ key: 'pers:' + nom, label: nom, level: 0, kind: 'person', span: mergeSpans(byPers[nom]), hasKids: true, count: byPers[nom].length, opened: pOpen }); extend(rows[rows.length - 1].span)
                if (!pOpen) { return }
                byPers[nom].sort(function (a, b) { return String(a.dateDebut || a.echeance).localeCompare(String(b.dateDebut || b.echeance)) }).forEach(function (t) {
                    rows.push({ key: 'pers:' + nom + '/t' + t.id, label: t.titre, level: 1, span: ownSpan(t), own: true, color: taskColor(t), taskId: t.id, statut: t.statut, mine: t.mine })
                    extend(ownSpan(t))
                })
            })
        }

        // Plage de dates (avec repli si rien de planifié) + marges selon le zoom, calée sur des mois entiers aux zooms larges.
        var z = planZoom(), pxDay = z.pxDay
        var today = new Date(); today.setHours(0, 0, 0, 0)
        if (!minD || !maxD) { minD = planAdd(today, -3); maxD = planAdd(today, 21) }
        minD = planAdd(minD, -z.pad[0]); maxD = planAdd(maxD, z.pad[1])
        if (z.snap) { minD = new Date(minD.getFullYear(), minD.getMonth(), 1); maxD = new Date(maxD.getFullYear(), maxD.getMonth() + 1, 0) }
        var totalDays = planDiff(minD, maxD) + 1
        // Aux zooms larges, la frise peut être plus courte que l'écran : on prolonge la plage pour remplir la largeur visible.
        var manque = (body.clientWidth - 252) - totalDays * pxDay
        if (manque > 0) { maxD = planAdd(maxD, Math.ceil(manque / pxDay)); totalDays = planDiff(minD, maxD) + 1 }
        var timelineW = Math.round(totalDays * pxDay)
        P._minD = minD; P._pxDay = pxDay                                    // mémorisés pour le changement de zoom (ancre)

        var gantt = el('div', { class: 'sp-gantt' })
        gantt.appendChild(planHeader(z, minD, totalDays, pxDay, today, timelineW))
        // Corps = grille de fond (une seule couche, derrière toutes les lignes) + les lignes.
        var gbody = el('div', { class: 'sp-gantt__body' })
        gbody.appendChild(planGrid(z, minD, totalDays, pxDay, today, timelineW))
        if (!rows.length) { gbody.appendChild(el('div', { class: 'sp-gantt__empty', text: 'Aucune tâche planifiée pour ce filtre.' })) }
        rows.forEach(function (r) {
            var grp = r.kind === 'projet' || r.kind === 'person' || r.kind === 'phase'
            var name = el('div', { class: 'sp-gantt__name sp-gantt__name--l' + Math.min(r.level, 3) })
            if (r.hasKids) { name.appendChild(el('span', { class: 'sp-gantt__caret' + (r.opened ? ' sp-gantt__caret--open' : ''), text: '▸', title: r.opened ? 'Replier' : 'Déplier', onclick: function (e) { e.stopPropagation(); P.open[r.key] = !r.opened; paintPlanneur(body) } })) }
            else { name.appendChild(el('span', { class: 'sp-gantt__caret sp-gantt__caret--none' })) }
            if (r.kind === 'projet' || r.kind === 'phase') { name.appendChild(el('span', { class: 'sp-gantt__cdot', style: 'background:' + (r.color || 'var(--sp-chip)') })) }
            name.appendChild(el('span', { class: 'sp-gantt__lbl' + (r.taskId ? ' sp-gantt__lbl--link' : ''), text: r.label, title: r.label, onclick: r.taskId ? function () { openDetail(r.taskId) } : null }))
            if (r.hasKids && r.count) { name.appendChild(el('span', { class: 'sp-gantt__cnt', text: String(r.count), title: r.count + ' tâche(s)' })) } // nb de tâches du groupe
            var lane = el('div', { class: 'sp-gantt__lane', style: 'width:' + timelineW + 'px' })
            if (r.span) {
                var left = planDiff(minD, r.span.s) * pxDay
                var w = Math.max((planDiff(r.span.s, r.span.e) + 1) * pxDay, 6)
                var bg = grp
                    ? (r.color ? 'color-mix(in srgb, ' + r.color + ' 45%, transparent)' : 'color-mix(in srgb, var(--sp-tx2) 40%, transparent)')
                    : (r.color || 'var(--sp-acc)')
                var bar = el('div', {
                    class: 'sp-gantt__bar' + (grp ? ' sp-gantt__bar--grp' : '') + (r.statut === 'fait' ? ' sp-gantt__bar--done' : ''),
                    style: 'left:' + left + 'px;width:' + w + 'px;background:' + bg,
                    title: r.label + ' · ' + planISO(r.span.s) + ' → ' + planISO(r.span.e),
                    onclick: r.taskId ? function () { openDetail(r.taskId) } : null
                }, [(!grp && w > 46) ? el('span', { class: 'sp-gantt__barlbl', text: r.label }) : null])
                lane.appendChild(bar)
            }
            gbody.appendChild(el('div', { class: 'sp-gantt__row' + (grp ? ' sp-gantt__row--grp' : '') }, [name, lane]))
        })
        gantt.appendChild(gbody)
        body.appendChild(gantt)
        // Défilement horizontal : après un changement de zoom on recentre sur la date « ancre » ; au premier
        // affichage (ou « Aujourd'hui ») on cadre sur AUJOURD'HUI (ligne du jour à ~1/3 de la zone des barres) ;
        // sinon on garde la position choisie par l'utilisateur d'un redessin à l'autre (dépli/repli, mode…).
        var nameW = (gantt.querySelector('.sp-gantt__corner') || {}).offsetWidth || 250
        var visible = Math.max(120, gantt.clientWidth - nameW)               // largeur visible des barres (hors colonne des noms)
        if (P.anchor) { gantt.scrollLeft = Math.max(0, Math.round(planDiff(minD, P.anchor) * pxDay - visible / 2)); P.anchor = null }
        else if (P.scroll == null) { gantt.scrollLeft = Math.max(0, Math.round(planDiff(minD, today) * pxDay - visible / 3)) }
        else { gantt.scrollLeft = P.scroll }
        P.scroll = gantt.scrollLeft
        gantt.addEventListener('scroll', function () { P.scroll = gantt.scrollLeft })

        // Section « Non planifiées » (repliée par défaut).
        if (unplanned.length) {
            var npOpen = P.open['__np'] === true
            var wrap = el('div', { class: 'sp-planp' }, [
                el('div', { class: 'sp-planp__head', onclick: function () { P.open['__np'] = !npOpen; paintPlanneur(body) } }, [
                    el('span', { class: 'sp-gantt__caret' + (npOpen ? ' sp-gantt__caret--open' : ''), text: '▸' }),
                    el('span', { class: 'sp-seclbl', style: 'margin:0', text: 'Non planifiées — ' + unplanned.length })
                ])
            ])
            if (npOpen) {
                unplanned.forEach(function (t) {
                    wrap.appendChild(el('div', { class: 'sp-arow', onclick: function () { openDetail(t.id) } }, [
                        el('span', { class: 'sp-sdot', style: 'background:' + (DOT[t.statut] || '#ccc'), title: ST[t.statut] || '' }),
                        el('span', { class: 'sp-arow__t', text: t.titre }),
                        el('span', { class: 'sp-arow__c', text: (planProj(d, t.projetId) || {}).nom || '' }),
                        el('span', { class: 'sp-arow__n', text: 'à planifier' })
                    ]))
                })
            }
            body.appendChild(wrap)
        }
    }

    // En-tête du Gantt : coin figé + 2 bandes selon le zoom (ex. mois / jours, année / mois…).
    // Le libellé de la bande du haut est « collant » : il reste visible à gauche tant que sa tranche l'est.
    function planHeader(z, minD, totalDays, pxDay, today, timelineW) {
        function band(unit, top) {
            var b = el('div', { class: 'sp-gantt__band ' + (top ? 'sp-gantt__band--top' : 'sp-gantt__band--bot'), style: 'width:' + timelineW + 'px' })
            planSegments(unit, minD, totalDays, today).forEach(function (s) {
                var w = s.n * pxDay
                var cell = el('div', { class: 'sp-gantt__cell' + (s.we ? ' sp-gantt__cell--we' : '') + (s.today ? ' sp-gantt__cell--today' : ''), style: 'width:' + w + 'px' })
                if (top) {
                    var txt = unit === 'month' ? s.label + ' ' + s.year : s.label
                    if (w >= (unit === 'year' ? 44 : 78)) { cell.appendChild(el('span', { class: 'sp-gantt__ctop', text: txt })) } // tranche trop étroite → pas de libellé
                } else {
                    cell.appendChild(el('span', { class: 'sp-gantt__cnum', text: s.label }))
                    if (s.sub && w >= 34) { cell.appendChild(el('span', { class: 'sp-gantt__csub', text: s.sub })) } // 2e ligne si assez large
                }
                b.appendChild(cell)
            })
            return b
        }
        return el('div', { class: 'sp-gantt__header' }, [
            el('div', { class: 'sp-gantt__corner', text: 'Tâche' }),
            el('div', { class: 'sp-gantt__bands' }, [band(z.bands[0], true), band(z.bands[1], false)])
        ])
    }
    // Grille de fond (une seule couche derrière toutes les lignes) : week-ends grisés (zooms fins), un trait
    // par unité de grille (semaine / mois / jour), un trait plus marqué à chaque tranche de la bande du haut,
    // et la colonne / ligne « aujourd'hui ».
    function planGrid(z, minD, totalDays, pxDay, today, timelineW) {
        var g = el('div', { class: 'sp-gantt__grid', style: 'width:' + timelineW + 'px' })
        if (pxDay >= 12) {
            planSegments('day', minD, totalDays, today).forEach(function (s) {
                if (s.we) { g.appendChild(el('div', { class: 'sp-gantt__gwe', style: 'left:' + (s.i * pxDay) + 'px;width:' + pxDay + 'px' })) }
            })
        }
        planSegments(z.grid, minD, totalDays, today).forEach(function (s) { if (s.i > 0) { g.appendChild(el('div', { class: 'sp-gantt__gline', style: 'left:' + (s.i * pxDay) + 'px' })) } })
        planSegments(z.bands[0], minD, totalDays, today).forEach(function (s) { if (s.i > 0) { g.appendChild(el('div', { class: 'sp-gantt__gline sp-gantt__gline--strong', style: 'left:' + (s.i * pxDay) + 'px' })) } })
        var t = planDiff(minD, today)
        if (t >= 0 && t < totalDays) { g.appendChild(el('div', { class: 'sp-gantt__gtoday', style: 'left:' + (t * pxDay) + 'px;width:' + Math.max(pxDay, 2) + 'px' })) }
        return g
    }

    // ============ VUE ADMINISTRATION (la direction / admin NC seulement) ============
    // Détail CONFIDENTIEL des heures (l'inverse du board d'équipe où elles sont masquées) :
    // par équipe (bénévole/salarié/partenaire), par personne, par projet, + récap des assignations.
    // Tout est filtrable (personne, projet, équipe, période) ; les filtres sont envoyés à l'API.
    var EQUIPE_LABEL = { benevole: L.benevole, salarie: L.salarie, pompier: L.pompier, autre: L.autre, groupe: 'Groupe' }

    // Construit la chaîne « ?personne=…&projet=… » à partir des filtres actifs (vide si aucun).
    function adminQuery() {
        var f = state.admin.filtre, p = []
        if (f.personne) { p.push('personne=' + encodeURIComponent(f.personne)) }
        if (f.projet) { p.push('projet=' + f.projet) }
        if (f.equipe) { p.push('equipe=' + f.equipe) }
        if (f.periode) { p.push('periode=' + f.periode) }
        return p.length ? '?' + p.join('&') : ''
    }

    // Charge les NOTIFICATIONS de la direction = propositions de tâches à valider + blocages signalés.
    // Tout est déjà exposé par l'API existante : /api/manage/pending (propositions) et /api/board (blocages).
    function loadNotifs() {
        return Promise.all([
            api('GET', '/api/manage/pending').catch(function () { return [] }),
            api('GET', '/api/board').catch(function () { return state.board || [] })
        ]).then(function (res) {
            var proposals = res[0] || []
            if (res[1]) { state.board = res[1] }
            var blocked = (state.board || []).filter(function (t) { return t.bloque })
            state.admin.notifCount = proposals.length + blocked.length
            return { count: state.admin.notifCount, proposals: proposals, blocked: blocked }
        })
    }

    function renderAdmin() {
        var M = main(); M.innerHTML = ''
        var tab = state.admin.tab || 'heures'
        M.appendChild(el('div', { class: 'sp-head' }, [el('div', { class: 'sp-head__row' }, [
            el('div', {}, [titleH('Administration', 'Espace réservé à la direction.\n\n• Onglet Heures : le détail des heures par personne, par équipe (bénévoles / salariés / pompiers) et par projet, avec des filtres, les dépassements, et l’export Excel.\n• Onglet Saisie d’heures (admin) : déclarer ou corriger les heures d’une personne à sa place.\n• Onglet Notifications : les tâches proposées par les membres (à valider ou refuser) et les blocages signalés. La pastille indique le nombre en attente.'), el('div', { class: 'sp-sub', text: 'Espace réservé à l’administration' })]),
            tab === 'heures' ? el('div', { class: 'sp-ctrls' }, [el('button', { class: 'sp-ghostbtn', title: 'Recharger', text: '↻', onclick: function () { state.admin.data = null; renderAdmin() } })]) : null
        ])]))
        // (Les sous-onglets sont désormais dans le MENU DE GAUCHE, déroulés sous « Administration ».)
        var body = el('div', { id: 'sp-admin-body' }); M.appendChild(body)
        body.appendChild(el('p', { class: 'sp-muted', text: 'Chargement…' }))
        // Toujours rafraîchir le compteur (pastille), quel que soit l'onglet ; et peindre la liste si on est dessus.
        loadNotifs().then(function (n) {
            refreshSidebar() // met à jour la pastille du menu « Administration » et de son sous-item « Notifications »
            if ((state.admin.tab || 'heures') === 'notif') { paintAdminNotifs(body, n.proposals, n.blocked) }
        }).catch(function () {})
        if (tab === 'notif') { return } // la liste est peinte par loadNotifs ci-dessus
        if (tab === 'saisie') { paintAdminSaisie(body); return } // saisie déléguée (admin)
        // Onglet Heures : on charge les stats selon les filtres, puis on peint.
        api('GET', '/api/admin/stats' + adminQuery()).then(function (d) { state.admin.data = d; paintAdmin() }).catch(function (e) { body.innerHTML = '<p class="sp-err">' + (e.message || 'Erreur') + '</p>' })
    }

    // Sous-onglet « Saisie d'heures (admin) » : l'admin déclare des heures POUR une personne (ex. un salarié),
    // et peut corriger/supprimer ses saisies. state.admin.saisie = { personne, users, entries }.
    function paintAdminSaisie(body) {
        body.innerHTML = ''
        var S = state.admin.saisie || (state.admin.saisie = { personne: '', users: null, entries: null })
        // Liste des personnes (chargée une fois via /api/ref/users, accessible à l'admin).
        if (!S.users) {
            api('GET', '/api/ref/users').then(function (u) { S.users = u || []; paintAdminSaisie(body) }).catch(function () { S.users = []; paintAdminSaisie(body) })
            return
        }
        body.appendChild(el('div', { class: 'sp-lblrow', style: 'margin-bottom:12px' }, [
            el('div', { class: 'sp-seclbl', style: 'margin:0', text: 'Saisie d’heures (admin) : pour une autre personne' }),
            helpBtn('Réservé à l’administration : déclarez des heures À LA PLACE d’une personne (ex. pour un collègue sans accès à Nextcloud).\n\nChoisissez la personne, le projet puis la tâche, la date et le nombre d’heures. Les saisies déjà faites pour cette personne apparaissent en dessous : vous pouvez les corriger (✎) ou les supprimer (✕).')
        ]))
        // Sélecteur de personne.
        var persOpts = S.users.map(function (u) { return { v: u.uid, l: u.displayName } })
        body.appendChild(field('Personne', searchSelectEl(persOpts, S.personne, function (v) { S.personne = v; S.entries = null; paintAdminSaisie(body) }, 'Rechercher une personne…')))
        if (!S.personne) { body.appendChild(el('p', { class: 'sp-muted', text: 'Sélectionnez une personne pour saisir ses heures.' })); return }
        var nomP = (S.users.find(function (u) { return u.uid === S.personne }) || {}).displayName || S.personne

        // Formulaire d'ajout (projet → tâche → date/heures/commentaire).
        var projetSel = selectEl([{ v: '', l: '— projet —' }].concat((state.projets || []).map(function (p) { return { v: p.id, l: p.nom } })), '', function (v) { loadTaches(v) })
        var wrap = el('div', { id: 'sp-tache-wrap' }, [el('select', { disabled: 'disabled' }, [el('option', { text: '— choisir un projet —' })])])
        var date = el('input', { type: 'date', value: todayISO(), max: todayISO() }), heures = el('input', { type: 'number', step: '0.5', min: '0', placeholder: 'heures' }), comm = el('input', { type: 'text', placeholder: 'Précision : ce qui a été fait (facultatif)' }), err = el('p', { class: 'sp-err' })
        function submit() {
            err.textContent = ''
            var sel = document.getElementById('sp-tache-sel'), tacheId = sel ? sel.value : ''
            if (!tacheId) { err.textContent = 'Choisissez une tâche.'; return }
            if (!heures.value || parseFloat(heures.value) <= 0) { err.textContent = 'Indiquez les heures.'; return }
            api('POST', '/api/heures', { tache: parseInt(tacheId, 10), jour: date.value || todayISO(), heures: parseFloat(heures.value), commentaire: comm.value, pour: S.personne })
                .then(function () { spToast('Heures ajoutées pour ' + nomP + '.'); S.entries = null; paintAdminSaisie(body) })
                .catch(function (e) { err.textContent = e.message })
        }
        body.appendChild(el('div', { class: 'sp-add', style: 'margin-top:8px' }, [el('div', { class: 'sp-seclbl', text: 'Ajouter des heures pour ' + nomP }), projetSel, wrap, el('div', { class: 'sp-logh' }, [date, heures]), comm, err, el('button', { class: 'primary', text: 'Ajouter', onclick: submit })]))

        // Liste des saisies existantes de la personne (éditables/supprimables par l'admin).
        var listBox = el('div', { style: 'margin-top:18px' })
        body.appendChild(listBox)
        if (!S.entries) {
            listBox.appendChild(el('p', { class: 'sp-muted', text: 'Chargement des saisies…' }))
            api('GET', '/api/admin/heures?personne=' + encodeURIComponent(S.personne)).then(function (rows) { S.entries = rows || []; paintSaisieList(listBox, S, body) }).catch(function () { S.entries = []; paintSaisieList(listBox, S, body) })
        } else {
            paintSaisieList(listBox, S, body)
        }
    }
    // Rendu de la liste des saisies d'une personne (dans le sous-onglet admin), avec ✎ / ✕.
    function paintSaisieList(listBox, S, body) {
        listBox.innerHTML = ''
        listBox.appendChild(el('div', { class: 'sp-seclbl', text: 'Saisies existantes — ' + (S.entries.length) }))
        if (!S.entries.length) { listBox.appendChild(el('p', { class: 'sp-muted', text: 'Aucune saisie pour cette personne.' })); return }
        S.entries.slice().sort(function (a, b) { return String(b.jour).localeCompare(String(a.jour)) }).forEach(function (e) {
            var ctx = [e.projetNom, e.activiteNom, e.tacheTitre].filter(Boolean).join(' › ')
            var box = el('div', { class: 'sp-hrow2' })
            function read() {
                box.className = 'sp-hrow2'; box.innerHTML = ''
                var meta = ctx + (e.commentaire ? (ctx ? ' — ' : '') + e.commentaire : '')
                box.appendChild(el('div', { class: 'sp-hrow2__b' }, [el('div', { class: 'sp-hrow2__t', text: e.jour }), meta ? el('div', { class: 'sp-hrow2__m', text: meta }) : null]))
                box.appendChild(el('div', { class: 'sp-hrow2__hc' }, [el('span', { class: 'sp-hrow2__val', text: fmtH(e.heures) }), el('button', { class: 'sp-iconbtn', title: 'Modifier (heures + précision)', text: '✎', onclick: edit })]))
                box.appendChild(el('button', { class: 'sp-iconbtn', title: 'Supprimer', text: '✕', onclick: function () { confirmModal('Supprimer cette saisie ?', function () { api('DELETE', '/api/heures/' + e.id).then(function () { S.entries = null; paintAdminSaisie(body) }).catch(alertErr) }) } }))
            }
            function edit() {
                box.className = 'sp-hrow2 sp-hrow2--edit'; box.innerHTML = ''
                var prec = el('input', { type: 'text', class: 'sp-hprec', placeholder: 'Précision : ce qui a été fait', value: e.commentaire || '' })
                var h = el('input', { type: 'number', step: '0.5', min: '0', value: e.heures, class: 'sp-hedit' })
                function save() { var v = parseFloat(h.value); if (!(v > 0)) { spToast('Indiquez un nombre d’heures.', true); return } api('PUT', '/api/heures/' + e.id, { heures: v, commentaire: prec.value }).then(function () { e.heures = v; e.commentaire = prec.value; read() }).catch(alertErr) }
                function onKey(ev) { if (ev.key === 'Enter') { save() } else if (ev.key === 'Escape') { read() } }
                h.addEventListener('keydown', onKey); prec.addEventListener('keydown', onKey)
                box.appendChild(el('div', { class: 'sp-hrow2__b' }, [el('div', { class: 'sp-hrow2__t', text: e.jour }), prec]))
                box.appendChild(el('div', { class: 'sp-hrow2__hc' }, [h, el('button', { class: 'sp-iconbtn', title: 'Valider', text: '✓', onmousedown: function (ev) { ev.preventDefault(); save() } }), el('button', { class: 'sp-iconbtn', title: 'Annuler', text: '✕', onmousedown: function (ev) { ev.preventDefault(); read() } })]))
                setTimeout(function () { h.focus(); h.select() }, 0)
            }
            read()
            listBox.appendChild(box)
        })
    }

    // Sous-onglet « Notifications » : propositions de tâches à valider (Valider/Refuser) + blocages signalés.
    function paintAdminNotifs(body, proposals, blocked) {
        body.innerHTML = ''
        if (!proposals.length && !blocked.length) { body.appendChild(el('div', { class: 'sp-empty', text: 'Aucune notification. Tout est à jour.' })); return }
        // 1) Propositions de tâches créées par des membres, en attente de validation.
        if (proposals.length) {
            body.appendChild(el('div', { class: 'sp-seclbl', style: 'margin:0 0 8px', text: 'Propositions à valider — ' + proposals.length }))
            proposals.forEach(function (r) {
                var ctx = [r.projetNom, r.parentTitre].filter(Boolean).join(' › ')
                // Recommandations du membre (référent + dates) à afficher pour que la direction décide en connaissance de cause.
                var reco = []
                if (r.assigneeName) { reco.push('réf. recommandé : ' + r.assigneeName) }
                var dl = (r.dateDebut && r.echeance) ? (fmtDateFr(r.dateDebut) + ' → ' + fmtDateFr(r.echeance)) : (r.echeance ? 'échéance ' + fmtDateFr(r.echeance) : (r.dateDebut ? 'dès ' + fmtDateFr(r.dateDebut) : ''))
                if (dl) { reco.push(dl) }
                body.appendChild(el('div', { class: 'sp-arow sp-arow--prop' }, [
                    el('span', { class: 'sp-tovalid', text: 'à valider' }),
                    el('div', { class: 'sp-prop__b' }, [
                        el('div', { class: 'sp-prop__t', text: r.titre, title: 'Ouvrir la fiche', onclick: function () { openDetail(r.id) } }),
                        el('div', { class: 'sp-prop__m', text: [ctx].concat(reco).filter(Boolean).join('  ·  ') })
                    ]),
                    el('button', { class: 'sp-addbtn sp-arow__btn', text: 'Valider', onclick: function () { api('POST', '/api/manage/taches/' + r.id + '/valider', {}).then(function () { spToast('Tâche validée.'); state.board = null; renderAdmin() }).catch(alertErr) } }),
                    el('button', { class: 'sp-dangerbtn sp-arow__btn', text: 'Refuser', onclick: function () { confirmModal('Refuser et supprimer « ' + r.titre + ' » ?', function () { api('POST', '/api/manage/taches/' + r.id + '/rejeter', {}).then(function () { spToast('Proposition refusée.'); state.board = null; renderAdmin() }).catch(alertErr) }) } })
                ]))
            })
        }
        // 2) Blocages signalés sur des tâches (cliquer ouvre la fiche pour lever le blocage).
        if (blocked.length) {
            body.appendChild(el('div', { class: 'sp-seclbl', style: 'margin:16px 0 8px', text: 'Blocages signalés — ' + blocked.length }))
            blocked.forEach(function (t) {
                var ctx = [t.projetNom, t.activiteNom].filter(Boolean).join(' › ')
                body.appendChild(el('div', { class: 'sp-arow sp-arow--over', onclick: function () { openDetail(t.id) } }, [
                    el('span', { class: 'sp-bdot' }),
                    el('span', { class: 'sp-arow__t', text: t.titre }),
                    el('span', { class: 'sp-arow__c', text: ctx }),
                    el('span', { class: 'sp-arow__n', text: t.assigneeName || '' })
                ]))
            })
        }
    }

    // Dessine le contenu à partir des données déjà chargées (state.admin.data).
    function paintAdmin() {
        var d = state.admin.data, body = document.getElementById('sp-admin-body'); if (!d || !body) { return }
        body.innerHTML = ''
        // 1) Barre de filtres.
        body.appendChild(adminFilters(d.refs || { personnes: [], projets: [] }))
        // 2) Chiffres clés par équipe (+ sous-total l'association = bénévoles + salariés, + total général).
        var pe = d.parEquipe || {}
        body.appendChild(el('div', { class: 'sp-adminkpis' }, [
            adminKpi('Bénévoles', fmtH(pe.benevole), '#2F9E78'),
            adminKpi('Salariés', fmtH(pe.salarie), '#2D74DA'),
            adminKpi(L.pompiers, fmtH(pe.pompier), '#C4362A'),
            adminKpi('Total ' + L.organisation, fmtH(pe.temonia), '#6C5CE7'),
            adminKpi('Total général', fmtH(pe.total), '#17A2B8')
        ]))
        // 3) Deux colonnes : heures par personne / par projet (barres réutilisées du dashboard).
        //    Cliquer une PERSONNE applique le filtre « personne » → on zoome sur son détail.
        body.appendChild(el('div', { class: 'sp-admincols' }, [
            el('div', { class: 'sp-card' }, [el('div', { class: 'sp-seclbl', text: 'Heures par personne' }), dashBars((d.parPersonne || []).map(function (p) { return { label: p.nom + ' · ' + (EQUIPE_LABEL[p.equipe] || p.equipe), value: p.heures, onClick: function () { state.admin.filtre.personne = p.uid; state.admin.data = null; renderAdmin() } } }), 'h')]),
            el('div', { class: 'sp-card' }, [el('div', { class: 'sp-seclbl', text: 'Heures par projet' }), dashBars((d.parProjet || []).map(function (p) { return { label: p.nom, value: p.heures, color: p.couleur || 'var(--sp-acc)' } }), 'h')])
        ]))
        // Dépassements : affectés dont le réel dépasse le budget d'heures prévu.
        body.appendChild(adminDepassements(d.depassements || []))
        // 5) Détail des saisies (chaque pointage) + export.
        body.appendChild(adminDetail(d.detail || [], d.detailTotal || 0))
    }

    // Liste des DÉPASSEMENTS : chaque affecté (personne OU groupe) ayant fait plus d'heures que prévu.
    // Chaque ligne se DÉPLIE (clic) pour montrer QUI a pointé combien et sur quelle (sous-)tâche : ainsi,
    // sous l'enveloppe « équipe salariée dépassée », la direction voit que c'est un salarié (+5 h sur telle
    // sous-tâche). Le réel est cumulé sur la tâche et toutes ses sous-tâches.
    function adminDepassements(rows) {
        var wrap = el('div', { class: 'sp-card', style: 'margin-top:16px' }, [el('div', { class: 'sp-lblrow' }, [el('div', { class: 'sp-seclbl', style: 'margin:0', text: 'Dépassements d’heures — ' + rows.length }), helpBtn('Les personnes ou groupes qui ont pointé PLUS d’heures que ce qui leur était prévu sur une tâche.\n\nCliquez une ligne pour la déplier et voir qui exactement, et sur quelle (sous-)tâche. « +Xh » = nombre d’heures au-dessus du budget.')])])
        if (!rows.length) { wrap.appendChild(el('p', { class: 'sp-muted', text: 'Aucun dépassement : chacun est dans son budget d’heures prévu.' })); return wrap }
        rows.forEach(function (r) {
            var team = r.type === 'group' ? 'Groupe' : (EQUIPE_LABEL[r.equipe] || r.equipe || '—')
            // Sous-bloc détaillé (masqué au départ) : un bloc par contributeur, avec ses (sous-)tâches.
            var detail = el('div', { class: 'sp-depdetail' }); detail.hidden = true
            ;(r.contributeurs || []).forEach(function (c) {
                var tl = el('div', { class: 'sp-depc__taches' })
                ;(c.taches || []).forEach(function (t) {
                    tl.appendChild(el('div', { class: 'sp-depc__t' }, [el('span', { class: 'sp-depc__tt', text: t.tacheTitre }), el('span', { class: 'sp-depc__th', text: fmtH(t.heures) })]))
                })
                detail.appendChild(el('div', { class: 'sp-depc' }, [
                    el('div', { class: 'sp-depc__h' }, [
                        spAva(c.uid, c.nom, 'sp-ava--24'),
                        el('span', { class: 'sp-depc__nom', text: c.nom + ' · ' + (EQUIPE_LABEL[c.equipe] || c.equipe || '—') }),
                        el('span', { class: 'sp-depc__tot', text: fmtH(c.heures) })
                    ]),
                    tl
                ]))
            })
            // Lien pour ouvrir la fiche de la tâche (stopPropagation pour ne pas replier le bloc).
            detail.appendChild(el('button', { class: 'sp-pbtn2', style: 'margin-top:8px', text: 'Ouvrir la tâche', onclick: function (e) { e.stopPropagation(); openDetail(r.tacheId) } }))
            // Ligne principale : caret + affecté + contexte + réel/prévu + puce d'écart. Clic = déplier/replier.
            var caret = el('span', { class: 'sp-depcaret', text: '▸' })
            var line = el('div', { class: 'sp-arow sp-arow--over', onclick: function () { var wasHidden = detail.hidden; detail.hidden = !wasHidden; caret.textContent = wasHidden ? '▾' : '▸' } }, [
                caret,
                el('span', { class: 'sp-arow__t', text: r.nom + ' · ' + team }),
                el('span', { class: 'sp-arow__c', text: (r.projetNom || '—') + ' › ' + r.tacheTitre }),
                el('span', { class: 'sp-arow__n', text: 'réel ' + fmtH(r.reel) }),
                el('span', { class: 'sp-arow__n', text: 'prévu ' + fmtH(r.prevu) }),
                el('span', { class: 'sp-aff__over', text: '+' + fmtH(r.ecart) })
            ])
            wrap.appendChild(el('div', {}, [line, detail]))
        })
        return wrap
    }

    // Construit la « query string » des filtres courants (pour les URLs d'export).
    function adminFilterQuery() {
        var f = state.admin.filtre, p = []
        if (f.personne) { p.push('personne=' + encodeURIComponent(f.personne)) }
        if (f.projet) { p.push('projet=' + f.projet) }
        if (f.equipe) { p.push('equipe=' + f.equipe) }
        if (f.periode) { p.push('periode=' + f.periode) }
        return p.join('&')
    }

    // Tableau « Détail des saisies » : une ligne = un pointage (date, personne, équipe, projet, tâche,
    // heures, commentaire), avec les boutons d'export (Excel / Nextcloud).
    function adminDetail(rows, total) {
        var wrap = el('div', { class: 'sp-card', style: 'margin-top:16px' })
        // En-tête : titre + boutons d'export.
        var q = adminFilterQuery()
        var exportBtns = el('div', { class: 'sp-detail__actions' }, [
            el('button', { class: 'sp-ghostbtn', text: 'Exporter (Excel)', title: 'Télécharger un fichier CSV ouvrable dans Excel', onclick: function () {
                // Navigation directe vers l'endpoint GET → le navigateur télécharge le CSV.
                window.open(base + '/api/admin/export' + (q ? '?' + q : ''), '_blank')
            } }),
            el('button', { class: 'sp-ghostbtn', text: 'Enregistrer dans Nextcloud', title: 'Écrit le CSV dans « ' + L.dossier_exports + ' » de vos Fichiers', onclick: function () {
                api('POST', '/api/admin/export' + (q ? '?' + q : ''), {}).then(function (r) { spToast('Enregistré : ' + (r && r.path ? r.path : 'Fichiers Nextcloud')) }).catch(alertErr)
            } })
        ])
        wrap.appendChild(el('div', { class: 'sp-detail__head' }, [
            el('div', { class: 'sp-seclbl', style: 'margin:0', text: 'Détail des saisies' + (total ? ' — ' + total : '') }),
            exportBtns
        ]))
        if (!rows.length) { wrap.appendChild(el('p', { class: 'sp-muted', text: 'Aucune saisie pour ce filtre.' })); return wrap }
        // En-tête de colonnes.
        wrap.appendChild(el('div', { class: 'sp-drow sp-drow--h' }, [
            el('span', { class: 'sp-drow__d', text: 'Date' }),
            el('span', { class: 'sp-drow__p', text: 'Personne' }),
            el('span', { class: 'sp-drow__x', text: 'Projet · tâche' }),
            el('span', { class: 'sp-drow__n', text: 'Heures' })
        ]))
        rows.forEach(function (r) {
            var ctx = [r.projetNom, r.activiteNom, r.tacheTitre].filter(Boolean).join(' › ')
            wrap.appendChild(el('div', { class: 'sp-drow' }, [
                el('span', { class: 'sp-drow__d', text: r.jour }),
                el('span', { class: 'sp-drow__p', text: r.personne + ' · ' + (EQUIPE_LABEL[r.equipe] || r.equipe) }),
                el('span', { class: 'sp-drow__x', title: ctx, text: ctx }),
                el('span', { class: 'sp-drow__n', text: fmtH(r.heures) })
            ]))
            // La PRÉCISION (ce qui a été fait) sur sa propre ligne, en entier (non tronquée), sous la saisie.
            if (r.commentaire) {
                wrap.appendChild(el('div', { class: 'sp-drow__note' }, [el('span', { class: 'sp-drow__notelbl', text: 'Précision : ' }), el('span', { text: r.commentaire })]))
            }
        })
        // Si la liste est tronquée (plus de 800 lignes), on invite à filtrer / exporter.
        if (total > rows.length) { wrap.appendChild(el('p', { class: 'sp-muted', style: 'margin-top:8px', text: 'Affichage des ' + rows.length + ' plus récentes sur ' + total + '. Affinez les filtres ou exportez pour tout voir.' })) }
        return wrap
    }

    // Une tuile KPI (grand chiffre coloré + libellé).
    function adminKpi(label, val, color) {
        return el('div', { class: 'sp-adminkpi' }, [el('div', { class: 'sp-adminkpi__v', style: 'color:' + color, text: val }), el('div', { class: 'sp-adminkpi__l', text: label })])
    }

    // Barre de filtres : 4 menus (personne, projet, équipe, période) + bouton Réinitialiser.
    function adminFilters(refs) {
        var f = state.admin.filtre
        // À chaque changement : on mémorise le filtre, on jette le cache et on recharge.
        function onChange(key) { return function (v) { state.admin.filtre[key] = v; state.admin.data = null; renderAdmin() } }
        var persOpts = [{ v: '', l: 'Toutes les personnes' }].concat((refs.personnes || []).map(function (p) { return { v: p.uid, l: p.nom + ' (' + (EQUIPE_LABEL[p.equipe] || p.equipe) + ')' } }))
        var projOpts = [{ v: '', l: 'Tous les projets' }].concat((refs.projets || []).map(function (p) { return { v: String(p.id), l: p.nom } }))
        var equipeOpts = [{ v: '', l: 'Toutes les équipes' }, { v: 'temonia', l: L.equipe + ' (' + L.salaries.toLowerCase() + ' + ' + L.benevoles.toLowerCase() + ')' }, { v: 'benevole', l: '— dont ' + L.benevoles }, { v: 'salarie', l: '— dont ' + L.salaries }, { v: 'pompier', l: L.pompiers }, { v: 'autre', l: 'Autres' }]
        var perOpts = [{ v: '', l: 'Toute la période' }, { v: '30', l: '30 derniers jours' }, { v: '90', l: '90 derniers jours' }, { v: '365', l: '12 derniers mois' }]
        return el('div', { class: 'sp-adminfilters' }, [
            searchSelectEl(persOpts, f.personne, onChange('personne'), 'Toutes les personnes'),
            selectEl(projOpts, f.projet, onChange('projet')),
            selectEl(equipeOpts, f.equipe, onChange('equipe')),
            selectEl(perOpts, f.periode, onChange('periode')),
            el('button', { class: 'sp-ghostbtn', text: 'Réinitialiser', onclick: function () { state.admin.filtre = { personne: '', projet: '', equipe: '', periode: '' }; state.admin.data = null; renderAdmin() } })
        ])
    }

    // ============ PANNEAU DÉTAIL (droite) ============
    function closePanel() { var m = document.getElementById('sp-panel-wrap'); if (m) { m.remove() } document.removeEventListener('keydown', escClose) }
    function escClose(e) { if (e.key === 'Escape') { closePanel() } }
    function openDetail(id) { api('GET', '/api/board/' + id).then(renderDetail).catch(alertErr) }
    function refreshBehind() { if (state.view === 'projet' && state.projet) { openTree(state.projet).then(refreshSidebar) } else { api('GET', '/api/board').then(function (b) { state.board = b; routeMain(); refreshSidebar() }).catch(function () {}) } refreshAdminBadge() }
    function relTime(sec) { if (!sec) { return '' } var d = Math.floor((Date.now() / 1000 - sec) / 86400); if (d <= 0) { return 'aujourd’hui' } if (d === 1) { return 'hier' } if (d < 7) { return 'il y a ' + d + ' j' } return 'il y a ' + Math.floor(d / 7) + ' sem.' }
    function joursAvant(ds) { if (!ds) { return null } return Math.ceil((new Date(ds + 'T00:00:00') - new Date()) / 86400000) }
    function formatEvent(h) {
        switch (h.action) {
            case 'statut': return 'a changé le statut : ' + (h.avant || '?') + ' → ' + (h.apres || '?')
            case 'creation': return 'a créé cet élément'
            case 'edition': return 'a modifié les détails'
            case 'assignation': return 'a modifié l’assignation : ' + (h.avant || '—') + ' → ' + (h.apres || '—')
            case 'affectation':
                // Ajout : avant vide, apres = « Nom (X h) ». Retrait : avant = « Nom », apres = « — ».
                if (!h.avant && h.apres && h.apres !== '—') { return 'a affecté ' + h.apres }
                if (h.avant && (!h.apres || h.apres === '—')) { return 'a retiré ' + h.avant + ' de l’affectation' }
                return 'a modifié une affectation'
            case 'suppression': return 'a supprimé'
            case 'blocage': return 'a signalé un blocage' + (h.apres ? ' : ' + h.apres : '')
            case 'deblocage': return 'a levé le blocage'
            case 'validation': return 'a validé cette tâche'
            case 'rejet': return 'a refusé cette proposition'
            case 'membres': return 'a modifié les accès'
            default: return h.action
        }
    }
    // Petit conteneur libellé + valeur, réutilisé dans la fiche détail.
    function field(lbl, node) { return el('div', { class: 'sp-pfield' }, [el('div', { class: 'sp-plbl', text: lbl }), node]) }
    function checkboxDetail(d) {
        var done = d.statut === 'fait'
        return el('span', { class: 'sp-cb sp-cb--18' + (done ? ' sp-cb--on' : ''), text: done ? '✓' : '', title: 'Marquer fait / à faire', onclick: function () { var s = done ? 'a_faire' : 'fait'; api('PUT', '/api/board/' + d.id + '/statut', { statut: s }).then(function () { openDetail(d.id); refreshBehind() }).catch(alertErr) } })
    }

    // Dessine la FICHE DÉTAIL d'une tâche (le grand panneau qui glisse depuis la droite). « d » = les
    // données renvoyées par GET /api/board/{id}. La fiche est composée de sections construites plus bas :
    // en-tête (fil d'Ariane + titre + éventuelle bannière de blocage), attributs (statut/assigné/échéance/
    // avancement/description), formulaire de saisie d'heures, fil de commentaires, et historique.
    // Section « Affectations » du panneau détail : qui est affecté à la tâche, avec SON prévu,
    // SON réel et un repère de dépassement. Seule la direction (d.peutAffecter) peut ajouter/retirer.
    function affectationsSection(d) {
        var affs = d.affectations || []
        var wrap = el('div', { class: 'sp-psec' }, [el('div', { class: 'sp-lblrow' }, [el('div', { class: 'sp-plbl', text: 'Affectations & heures prévues' }), d.peutAffecter ? helpBtn('Les personnes/groupes affectés à cette tâche, avec leurs heures prévues et leurs heures réelles.\n\nUne pastille rouge « +Xh » signale un dépassement (plus d’heures faites que prévu). Le total prévu de la tâche = la somme de ces heures.') : null])])
        // Liste des affectés.
        if (!affs.length) {
            wrap.appendChild(el('p', { class: 'sp-muted', text: 'Personne n’est encore affecté à cette tâche.' }))
        }
        affs.forEach(function (a) {
            var rr = el('div', { class: 'sp-aff__r' })
            // Affichage : « réel / prévu » (ou « — pas d'heures affectées »), badge cumul, puce de
            // dépassement, ✎ modifier (sauf en cumul : le budget se règle sur les sous-tâches), ✕ retirer.
            function read() {
                rr.innerHTML = ''
                var prevu = a.heuresPrevuesEffectif
                var meta = prevu != null ? (fmtH(a.reel || 0) + ' / ' + fmtH(prevu)) : (fmtH(a.reel || 0) + ' — pas d\'heures affectées')
                rr.appendChild(el('span', { class: 'sp-aff__meta' + (a.depassement ? ' sp-over' : ''), text: meta }))
                // Prévu issu du cumul des sous-tâches : on le signale et on n'édite pas ici.
                if (a.cumulSousTaches) { rr.appendChild(el('span', { class: 'sp-aff__cumul', title: 'Somme des heures affectées sur les sous-tâches', text: '∑ sous-tâches' })) }
                if (a.depassement) { rr.appendChild(el('span', { class: 'sp-aff__over', text: '+' + fmtH(a.ecart) })) }
                if (d.peutAffecter) {
                    if (!a.cumulSousTaches) { rr.appendChild(el('button', { class: 'sp-iconbtn', title: 'Modifier les heures affectées', text: '✎', onclick: edit })) }
                    rr.appendChild(el('button', { class: 'sp-iconbtn', title: 'Retirer', text: '✕', onclick: function () { confirmModal('Retirer ' + (a.nom || 'cet affecté') + ' de la tâche ?', function () { api('DELETE', '/api/manage/affectations/' + a.id).then(function () { openDetail(d.id); refreshBehind() }).catch(alertErr) }) } }))
                }
            }
            // Édition des HEURES PRÉVUES de cette affectation (réutilise l'upsert par principal côté serveur).
            function edit() {
                rr.innerHTML = ''
                var inp = el('input', { type: 'number', step: '0.5', min: '0', class: 'sp-hedit', placeholder: 'h prévues', value: a.heuresPrevues != null ? a.heuresPrevues : '' })
                function save() {
                    var body = { principal: a.principal, heuresPrevues: (inp.value !== '' && parseFloat(inp.value) >= 0) ? parseFloat(inp.value) : null }
                    api('POST', '/api/manage/taches/' + d.id + '/affectations', body).then(function () { openDetail(d.id); refreshBehind() }).catch(alertErr)
                }
                inp.addEventListener('keydown', function (ev) { if (ev.key === 'Enter') { save() } else if (ev.key === 'Escape') { read() } })
                rr.appendChild(inp)
                rr.appendChild(el('button', { class: 'sp-iconbtn', title: 'Valider', text: '✓', onmousedown: function (ev) { ev.preventDefault(); save() } }))
                rr.appendChild(el('button', { class: 'sp-iconbtn', title: 'Annuler', text: '✕', onmousedown: function (ev) { ev.preventDefault(); read() } }))
                setTimeout(function () { inp.focus(); inp.select() }, 0)
            }
            read()
            wrap.appendChild(el('div', { class: 'sp-aff' + (a.depassement ? ' sp-aff--over' : '') }, [
                el('div', { class: 'sp-aff__who' }, [assigneeAva(a.principal, 'sp-ava--24'), el('span', { class: 'sp-aff__nom', text: a.nom || a.principal })]),
                rr
            ]))
        })
        // Formulaire d'ajout (direction seulement) : choisir une personne/un groupe + ses heures prévues.
        if (d.peutAffecter) {
            // Options = personnes (valeur = uid) puis groupes (valeur = "group:<gid>"), format attendu par l'API.
            var who = selectEl(principalSelectOptions(), '', function () {})
            var h = el('input', { type: 'number', step: '0.5', min: '0', placeholder: 'heures prévues' })
            var err = el('p', { class: 'sp-err' })
            function submit() {
                err.textContent = ''
                if (!who.value) { err.textContent = 'Choisissez une personne ou un groupe.'; return }
                // heuresPrevues facultatif : vide = affecté sans budget chiffré.
                var body = { principal: who.value }
                if (h.value !== '' && parseFloat(h.value) >= 0) { body.heuresPrevues = parseFloat(h.value) }
                api('POST', '/api/manage/taches/' + d.id + '/affectations', body).then(function () { openDetail(d.id); refreshBehind() }).catch(function (e) { err.textContent = e.message })
            }
            wrap.appendChild(el('div', { class: 'sp-affadd' }, [who, h, el('button', { class: 'sp-pbtn2', text: 'Affecter', onclick: submit })]))
            wrap.appendChild(err)
        }
        return wrap
    }

    // ============ LIENS & DOCUMENTS d'une fiche ============
    // Liste des liens attachés à la tâche (adresse web, fichier ou dossier du cloud) + ajout / retrait.
    // Tout membre ayant accès au projet peut en ajouter (comme pour les commentaires).
    var LIEN_TYPE = { url: 'Web', fichier: 'Fichier', dossier: 'Dossier' }
    function liensSection(d) {
        var wrap = el('div', { class: 'sp-psec' })
        wrap.appendChild(el('div', { class: 'sp-lblrow' }, [el('div', { class: 'sp-plbl', text: 'Liens & documents' }), helpBtn('Attachez à la tâche les documents utiles : un fichier ou un dossier de votre cloud Nextcloud (sélecteur), ou une adresse web.\n\nLe lien « Fichier / Dossier » ouvre l’élément dans Fichiers : il fonctionne pour toute personne avec qui il est partagé.')]))
        var list = d.liens || []
        if (!list.length) { wrap.appendChild(el('p', { class: 'sp-muted', style: 'margin:0', text: 'Aucun lien pour l’instant.' })) }
        list.forEach(function (l, i) {
            wrap.appendChild(el('div', { class: 'sp-lien' }, [
                el('span', { class: 'sp-lien__type', text: LIEN_TYPE[l.type] || 'Lien' }),
                el('a', { class: 'sp-lien__a', href: l.url, target: '_blank', rel: 'noopener', title: l.url, text: l.label || l.url }),
                el('button', { class: 'sp-iconbtn', title: 'Retirer ce lien', text: '✕', onclick: function () { confirmModal('Retirer le lien « ' + (l.label || l.url) + ' » de la tâche ?', function () { api('DELETE', '/api/board/' + d.id + '/liens/' + i).then(function () { openDetail(d.id) }).catch(alertErr) }, { ok: 'Retirer' }) } })
            ]))
        })
        // Ajout d'un fichier/dossier : sélecteur natif Nextcloud (tous types + dossiers autorisés).
        var btnFile = el('button', { class: 'sp-pbtn2', text: '+ Fichier ou dossier du cloud', onclick: function () {
            if (!(window.OC && OC.dialogs && OC.dialogs.filepicker)) { spToast('Sélecteur de fichiers indisponible.', true); return }
            OC.dialogs.filepicker('Choisir un fichier ou un dossier', function (path) {
                api('POST', '/api/board/' + d.id + '/liens', { type: 'fichier', path: path }).then(function () { openDetail(d.id) }).catch(alertErr)
            }, false, undefined, true, OC.dialogs.FILEPICKER_TYPE_CHOOSE, undefined, { allowDirectoryChooser: true })
        } })
        // Ajout d'une adresse web : petite modale (adresse + libellé facultatif).
        var btnUrl = el('button', { class: 'sp-pbtn2', text: '+ Adresse web', onclick: function () {
            var url = el('input', { type: 'url', placeholder: 'https://…' }), lbl = el('input', { type: 'text', placeholder: 'Libellé (facultatif)' }), err = el('p', { class: 'sp-err' })
            function submit() { err.textContent = ''; api('POST', '/api/board/' + d.id + '/liens', { type: 'url', url: url.value.trim(), label: lbl.value }).then(function () { openDetail(d.id) }).catch(function (e) { err.textContent = e.message }) }
            url.addEventListener('keydown', function (ev) { if (ev.key === 'Enter') { submit() } })
            openFormModal('Ajouter une adresse web', el('div', { class: 'sp-mform' }, [mfield('Adresse', url), mfield('Libellé', lbl), err, el('div', { class: 'sp-modalbtns' }, [el('button', { class: 'sp-ghostbtn', text: 'Annuler', onclick: function () { closePanel(); openDetail(d.id) } }), el('button', { class: 'sp-addbtn', text: 'Ajouter', onclick: submit })])]))
            setTimeout(function () { url.focus() }, 50)
        } })
        wrap.appendChild(el('div', { class: 'sp-lien__add' }, [btnFile, btnUrl]))
        return wrap
    }

    function renderDetail(d) {
        closePanel()
        // Y a-t-il un blocage OUVERT (type blocage et non résolu) ? Sert à afficher la bannière rouge.
        var openBloc = (d.commentaires || []).find(function (c) { return c.type === 'blocage' && !c.resolved })

        var crumbs = el('div', { class: 'sp-pcrumb' })
        var chain = [d.projetNom, d.phaseNom, d.activiteNom].filter(Boolean)
        chain.forEach(function (t) { crumbs.appendChild(el('span', { text: t })); crumbs.appendChild(el('span', { class: 'sp-pcrumb__s', text: '›' })) })
        crumbs.appendChild(el('span', { class: 'sp-pcrumb__cur', text: d.titre }))

        var head = el('div', { class: 'sp-pnl__head' }, [
            el('div', { class: 'sp-pnl__crumbrow' }, [crumbs, el('button', { class: 'sp-pnl__x', text: '✕', onclick: closePanel })]),
            el('div', { class: 'sp-pnl__titlerow' }, [checkboxDetail(d), el('h2', { class: 'sp-pnl__title', text: d.titre })])
        ])
        if (openBloc) {
            head.appendChild(el('div', { class: 'sp-blocbanner' }, [
                el('span', { class: 'sp-bdot' }),
                el('span', { class: 'sp-blocbanner__t', text: 'Bloqué ' + relTime(openBloc.createdAt) + (openBloc.texte ? ' — ' + openBloc.texte : '') }),
                (openBloc.mine || d.peutLever) ? el('button', { class: 'sp-blocbanner__b', text: 'Lever le blocage', onclick: function () { api('PUT', '/api/commentaires/' + openBloc.id + '/resolve', {}).then(function () { openDetail(d.id); refreshBehind() }).catch(alertErr) } }) : null
            ]))
        }
        // Bandeau « à valider » : tâche proposée par un membre, en attente. la direction (peutValider)
        // y trouve les boutons Valider / Refuser ; les autres voient juste l'information.
        if (d.valide === false) {
            var vbtns = []
            if (d.peutValider) {
                vbtns.push(el('button', { class: 'sp-addbtn', text: 'Valider', onclick: function () { api('POST', '/api/manage/taches/' + d.id + '/valider', {}).then(function () { spToast('Tâche validée.'); openDetail(d.id); refreshBehind() }).catch(alertErr) } }))
                vbtns.push(el('button', { class: 'sp-dangerbtn', text: 'Refuser', onclick: function () { confirmModal('Refuser et supprimer « ' + d.titre + ' » ?', function () { api('POST', '/api/manage/taches/' + d.id + '/rejeter', {}).then(function () { spToast('Proposition refusée.'); closePanel(); refreshBehind() }).catch(alertErr) }) } }))
            }
            head.appendChild(el('div', { class: 'sp-validbanner' }, [
                el('span', { class: 'sp-validbanner__t', text: 'Proposition en attente de validation' })
            ].concat(vbtns)))
        }

        var statut = el('div', { class: 'sp-pseg' })
        STATUTS.forEach(function (c) {
            statut.appendChild(el('button', { class: 'sp-pseg__b' + (d.statut === c.k ? ' sp-pseg__b--on' : ''), onclick: function () { api('PUT', '/api/board/' + d.id + '/statut', { statut: c.k }).then(function () { openDetail(d.id); refreshBehind() }).catch(alertErr) } }, [d.statut === c.k ? el('span', { class: 'sp-pseg__dot' }) : null, el('span', { text: c.l })]))
        })

        var prevu = d.heuresPrevues || 0, reel = d.reel || 0
        var pct = prevu > 0 ? Math.min(100, Math.round(reel / prevu * 100)) : (reel > 0 ? 100 : 0)
        var ech = joursAvant(d.echeance)

        var attrs = el('div', { class: 'sp-psec' }, [
            field('Statut', statut),
            el('div', { class: 'sp-pgrid2' }, [
                field('Référent', el('div', { class: 'sp-passignee' }, [assigneeAva(d.assignee, 'sp-ava--24'), el('span', { text: d.assigneeName || 'Aucun référent' })])),
                field('Échéance', d.echeance ? el('div', { class: 'sp-pech' }, [el('span', { text: d.echeance }), ech != null ? el('span', { class: 'sp-pech__r' + (ech < 3 ? ' sp-over' : ''), text: ech < 0 ? 'en retard' : 'dans ' + ech + ' j' }) : null]) : el('span', { class: 'sp-muted', text: '—' }))
            ]),
            el('div', { class: 'sp-pfield' }, [
                el('div', { class: 'sp-pbarhead' }, [el('span', { class: 'sp-plbl', text: 'Réel / prévu' }), el('span', { class: 'sp-muted', text: fmtH(reel) + ' sur ' + fmtH(prevu) + ' · ' + pct + ' %' })]),
                el('div', { class: 'sp-av__bar sp-pbar' }, [el('div', { class: 'sp-av__fill', style: 'width:' + pct + '%' })]),
                // Heures pointées directement sur cette tâche alors qu'elle a des sous-tâches : on le dit,
                // car elles comptent dans le total ci-dessus mais dans aucune ligne « ∑ sous-tâches ».
                d.reelPropre ? el('div', { class: 'sp-muted', style: 'font-size:11.5px', text: 'dont ' + fmtH(d.reelPropre) + ' pointées sur la tâche elle-même (hors sous-tâches)' }) : null
            ]),
            d.description ? field('Description', el('p', { class: 'sp-pdesc', text: d.description })) : null
        ])

        var logh = (function () {
            var date = el('input', { type: 'date', value: todayISO(), max: todayISO() }), h = el('input', { type: 'number', step: '0.5', min: '0', placeholder: '0,0 h' }), comm = el('input', { type: 'text', placeholder: 'Précision : ce qui a été fait (facultatif)' }), err = el('p', { class: 'sp-err' })
            function submit() { err.textContent = ''; if (!h.value || parseFloat(h.value) <= 0) { err.textContent = 'Indiquez les heures.'; return } api('POST', '/api/heures', { tache: d.id, jour: date.value || todayISO(), heures: parseFloat(h.value), commentaire: comm.value }).then(function () { openDetail(d.id); refreshBehind() }).catch(function (e) { err.textContent = e.message }) }
            return el('div', { class: 'sp-psec sp-psec--form' }, [el('div', { class: 'sp-plbl', text: 'Saisir mes heures' }), el('div', { class: 'sp-loghgrid' }, [date, h]), comm, err, el('button', { class: 'sp-pbtn', text: 'Enregistrer', onclick: submit })])
        })()

        var comm = (function () {
            var wrap = el('div', { class: 'sp-psec sp-psec--comm' }, [el('div', { class: 'sp-plbl', text: 'Activité & blocages' })])
            ;(d.commentaires || []).forEach(function (c) {
                var isB = c.type === 'blocage', redbox = isB && !c.resolved
                var htag = el('div', { class: 'sp-pcm__h' }, [el('span', { class: 'sp-pcm__a', text: c.auteur }), el('span', { class: 'sp-pcm__ago', text: relTime(c.createdAt) }), isB ? el('span', { class: 'sp-pcm__pill' + (c.resolved ? ' sp-ok' : ''), text: c.resolved ? '✓ levé' : 'Blocage' }) : null])
                var body = el('div', { class: 'sp-pcm__b' }, [htag, el('div', { class: 'sp-pcm__x', text: c.texte })])
                if (redbox && (c.mine || d.peutLever)) { body.appendChild(el('div', { class: 'sp-pcm__act' }, [el('span', { class: 'sp-pcm__lever', text: 'Lever le blocage', onclick: function () { api('PUT', '/api/commentaires/' + c.id + '/resolve', {}).then(function () { openDetail(d.id); refreshBehind() }).catch(alertErr) } })])) }
                wrap.appendChild(el('div', { class: 'sp-pcm' + (redbox ? ' sp-pcm--bloc' : '') }, [spAva(c.auteur, c.auteur, 'sp-ava--24'), body]))
            })
            if (!(d.commentaires || []).length) { wrap.appendChild(el('p', { class: 'sp-muted', text: 'Aucun message.' })) }
            var ta = el('input', { type: 'text', class: 'sp-cinput', placeholder: 'Ajouter un commentaire…' }), chk = el('input', { type: 'checkbox' }), err = el('p', { class: 'sp-err' })
            function submit() { err.textContent = ''; var t = (ta.value || '').trim(); if (!t) { err.textContent = 'Message vide.'; return } api('POST', '/api/commentaires', { tache: d.id, texte: t, type: chk.checked ? 'blocage' : 'comment' }).then(function () { openDetail(d.id); refreshBehind() }).catch(function (e) { err.textContent = e.message }) }
            wrap.appendChild(el('div', { class: 'sp-cform' }, [ta, err, el('div', { class: 'sp-cformrow' }, [el('label', { class: 'sp-chk' }, [chk, el('span', { text: 'Signaler comme blocage' })]), el('button', { class: 'sp-pbtn2', text: 'Publier', onclick: submit })])]))
            return wrap
        })()

        var histo = (function () {
            var wrap = el('div', { class: 'sp-psec sp-psec--comm' }, [el('div', { class: 'sp-plbl', text: 'Historique' })])
            var list = d.historique || []
            if (!list.length) { wrap.appendChild(el('p', { class: 'sp-muted', text: 'Aucun événement enregistré.' })); return wrap }
            list.forEach(function (h) {
                wrap.appendChild(el('div', { class: 'sp-hevt' }, [
                    spAva(h.auteur, h.auteur),
                    el('div', { class: 'sp-hevt__b' }, [
                        el('div', { class: 'sp-hevt__h' }, [el('span', { class: 'sp-pcm__a', text: h.auteur }), el('span', { class: 'sp-pcm__ago', text: relTime(h.createdAt) })]),
                        el('div', { class: 'sp-hevt__x', text: formatEvent(h) })
                    ])
                ]))
            })
            return wrap
        })()
        // Détail = PANNEAU LATÉRAL DROIT (fidèle à la maquette Claude Design), et non une modale
        // centrée : la classe --side ancre le panneau à droite, --right aligne le voile en conséquence.
        var panel = el('aside', { class: 'sp-pnl sp-pnl--side', onclick: function (e) { e.stopPropagation() } }, [head, el('div', { class: 'sp-pnl__body' }, [attrs, liensSection(d), affectationsSection(d), logh, comm, histo])])
        root.appendChild(el('div', { class: 'sp-pnl__wrap sp-pnl__wrap--right', id: 'sp-panel-wrap', onclick: closePanel }, [panel]))
        document.addEventListener('keydown', escClose)
    }

    // Fabrique un menu déroulant <select>. options = [{ v: valeur, l: libellé }], value = valeur
    // présélectionnée, onchange = fonction appelée avec la nouvelle valeur à chaque changement.
    function selectEl(options, value, onchange) { var s = el('select', { onchange: function (ev) { onchange(ev.target.value) } }); options.forEach(function (o) { s.appendChild(el('option', { value: o.v, text: o.l })) }); s.value = value != null ? String(value) : ''; return s }

    // Menu déroulant AVEC RECHERCHE (combobox) : utile quand il y a beaucoup d'options (ex. toutes
    // les personnes du Nextcloud). options = [{v,l}] ; on tape pour filtrer, on clique pour choisir.
    function searchSelectEl(options, value, onchange, placeholder) {
        var cur = value != null ? String(value) : ''
        function labelOf(v) { var o = options.find(function (x) { return String(x.v) === String(v) }); return o ? o.l : '' }
        var input = el('input', { type: 'text', class: 'sp-ssel__inp', placeholder: placeholder || 'Rechercher…', autocomplete: 'off', value: labelOf(cur) })
        var menu = el('div', { class: 'sp-ssel__menu' }); menu.hidden = true
        var wrap = el('div', { class: 'sp-ssel' }, [input, menu])
        function close() { menu.hidden = true; input.value = labelOf(cur); document.removeEventListener('click', onDoc, true) }
        function onDoc(e) { if (!wrap.contains(e.target)) { close() } }
        function pick(v) { cur = String(v); onchange(cur); close() }
        function render(q) {
            menu.innerHTML = ''; q = (q || '').toLowerCase()
            var shown = options.filter(function (o) { return !q || String(o.l).toLowerCase().indexOf(q) !== -1 })
            if (!shown.length) { menu.appendChild(el('div', { class: 'sp-ssel__empty', text: 'Aucun résultat' })); return }
            shown.slice(0, 60).forEach(function (o) {
                menu.appendChild(el('div', { class: 'sp-ssel__opt' + (String(o.v) === cur ? ' sp-ssel__opt--on' : ''), text: o.l, onmousedown: function (e) { e.preventDefault(); pick(o.v) } }))
            })
        }
        input.addEventListener('focus', function () { menu.hidden = false; render(''); input.select(); setTimeout(function () { document.addEventListener('click', onDoc, true) }, 0) })
        input.addEventListener('input', function () { menu.hidden = false; render(input.value) })
        input.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') { close() }
            else if (e.key === 'Enter') { e.preventDefault(); var q = input.value.toLowerCase(); var m = options.filter(function (o) { return String(o.l).toLowerCase().indexOf(q) !== -1 }); if (m.length) { pick(m[0].v) } }
        })
        return wrap
    }

    // Remplit le menu « tâche » de la saisie d'heures, en fonction du projet choisi.
    function loadTaches(projetId) {
        var wrap = document.getElementById('sp-tache-wrap'); if (!wrap) { return }
        wrap.innerHTML = '' // on vide le contenu précédent
        // Aucun projet choisi → menu désactivé avec un texte d'invite.
        if (!projetId) { wrap.appendChild(el('select', { disabled: 'disabled' }, [el('option', { text: '— choisir un projet —' })])); return }
        // Menu temporaire « chargement… » le temps des 2 appels réseau.
        wrap.appendChild(el('select', { disabled: 'disabled' }, [el('option', { text: 'chargement…' })]))
        // On charge EN PARALLÈLE les activités (phases) et les tâches du projet (Promise.all attend les 2).
        Promise.all([api('GET', '/api/ref/activites?projet=' + projetId), api('GET', '/api/ref/taches?projet=' + projetId)]).then(function (res) {
            var acts = res[0], taches = res[1], sel = el('select', { id: 'sp-tache-sel' })
            sel.appendChild(el('option', { value: '', text: '— tâche —' }))
            // fill : ajoute dans un groupe (og) les tâches racines. Si une racine a des sous-tâches,
            // on n'affiche que les sous-tâches (préfixées « ↳ ») car ce sont elles qu'on pointe ; sinon
            // on affiche la racine elle-même (c'est une feuille).
            function fill(og, racines) { racines.forEach(function (t) { var kids = taches.filter(function (x) { return x.parentId === t.id }); if (kids.length) { kids.forEach(function (k) { og.appendChild(el('option', { value: k.id, text: '  ↳ ' + k.titre })) }) } else { og.appendChild(el('option', { value: t.id, text: t.titre })) } }) }
            // Un <optgroup> par phase : on y met ses tâches racines. On ne l'ajoute que s'il contient quelque chose.
            acts.forEach(function (a) { var og = document.createElement('optgroup'); og.label = a.nom; fill(og, taches.filter(function (t) { return t.activiteId === a.id && !t.parentId })); if (og.children.length) { sel.appendChild(og) } })
            // Groupe « Sans phase » : les tâches racines qui n'appartiennent à aucune phase.
            var orphan = taches.filter(function (t) { return !t.activiteId && !t.parentId }); if (orphan.length) { var og = document.createElement('optgroup'); og.label = 'Sans phase'; fill(og, orphan); sel.appendChild(og) }
            wrap.innerHTML = ''; wrap.appendChild(sel) // on remplace le menu « chargement… » par le vrai menu
        }).catch(function () { wrap.innerHTML = ''; wrap.appendChild(el('p', { class: 'sp-err', text: 'Erreur chargement des tâches.' })) })
    }
    // ---------- init ----------
    // Démarrage : on charge les données puis on dessine la 1re fois. En cas d'échec, message d'erreur.
    ensureData().then(function () { render(); refreshAdminBadge() }).catch(function (e) { root.innerHTML = '<p class="sp-err">' + (e.message || 'Erreur de chargement') + '</p>' })
})()
