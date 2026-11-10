/* SPDX-FileCopyrightText: 2026 Flavien Auvray
 * SPDX-License-Identifier: AGPL-3.0-or-later */
/* Contenu de la tuile l'association sur le tableau de bord d'accueil de Nextcloud.
   S'enregistre auprès de OCA.Dashboard, va chercher les stats de l'app, puis
   dessine : les alertes de blocage + une barre de progression par projet.
   Tout est construit via l'API DOM (pas d'innerHTML avec des données) → pas d'injection. */
(function () {
    // 'use strict' : mode strict de JS = erreurs plus tôt, moins de pièges silencieux.
    'use strict'

    // Petit fabricant d'éléments : el('div', 'ma-classe', 'mon texte') → un <div class="ma-classe">mon texte</div>.
    // On utilise textContent (et non innerHTML) : le texte est inséré tel quel, jamais interprété comme du HTML.
    function el(tag, cls, text) {
        var e = document.createElement(tag)
        if (cls) { e.className = cls }
        if (text != null) { e.textContent = text }
        return e
    }

    // Dessine le contenu de la tuile à partir des stats reçues de l'API.
    function render(container, stats) {
        container.textContent = '' // on vide le « Chargement… » précédent
        // URL de l'app « Projets » : les éléments cliquables y renvoient.
        var appUrl = OC.generateUrl('/apps/temonia_saisie/')

        // 1) Alerte blocages : affichée seulement s'il y en a au moins un.
        // (stats.kpis && stats.kpis.bloquees) || 0 : lit le compteur, ou 0 si absent (évite une erreur).
        var nb = (stats.kpis && stats.kpis.bloquees) || 0
        if (nb > 0) {
            // Accord singulier/pluriel : « 1 tâche bloquée » vs « 3 tâches bloquées ».
            var alert = el('a', 'tp-dashw-bloc', '⛔ ' + nb + ' tâche' + (nb > 1 ? 's' : '') + ' bloquée' + (nb > 1 ? 's' : ''))
            alert.href = appUrl
            container.appendChild(alert)
        }

        // 2) Progression par projet : une barre remplie au % réel/prévu pour chaque projet.
        var rows = stats.avancementProjets || []
        if (!rows.length) {
            // Aucun projet : on affiche un message, sauf si l'alerte de blocage occupe déjà la tuile.
            if (nb === 0) { container.appendChild(el('p', 'tp-dashw-empty', 'Aucun projet à afficher.')) }
            return
        }
        rows.forEach(function (r) {
            var row = el('a', 'tp-dashw-row') // toute la ligne est un lien vers l'app
            row.href = appUrl
            var dot = el('span', 'tp-dashw-dot') // pastille de la couleur du projet
            dot.style.background = r.couleur || 'var(--color-text-maxcontrast)'
            var nom = el('span', 'tp-dashw-nom', r.nom)
            nom.title = r.nom // infobulle au survol (utile si le nom est tronqué)
            var track = el('span', 'tp-dashw-track') // le rail gris de la barre
            var fill = el('span', 'tp-dashw-fill')   // la portion remplie
            // On borne le pourcentage entre 0 et 100 avant de fixer la largeur.
            fill.style.width = Math.max(0, Math.min(100, r.pct)) + '%'
            track.appendChild(fill)
            var pct = el('span', 'tp-dashw-pct', r.pct + '%')
            row.appendChild(dot); row.appendChild(nom); row.appendChild(track); row.appendChild(pct)
            container.appendChild(row)
        })
    }

    // Appelée par Nextcloud pour remplir la tuile : affiche « Chargement… », va chercher les stats, dessine.
    function mount(container) {
        container.classList.add('tp-dashw')
        container.appendChild(el('p', 'tp-dashw-empty', 'Chargement…'))
        // Appel à l'API de l'app. requesttoken = jeton anti-CSRF de Nextcloud (obligatoire).
        fetch(OC.generateUrl('/apps/temonia_saisie') + '/api/dashboard/stats', {
            headers: { requesttoken: OC.requestToken, 'OCS-APIRequest': 'true' }
        }).then(function (r) { return r.json() })          // on transforme la réponse en objet JS
            .then(function (stats) { render(container, stats) }) // puis on dessine
            // En cas d'échec réseau, message d'erreur discret (la tuile ne doit pas « planter »).
            .catch(function () { container.textContent = ''; container.appendChild(el('p', 'tp-dashw-empty', 'Impossible de charger les projets.')) })
    }

    // Enregistre notre widget auprès du système de dashboard de Nextcloud (OCA.Dashboard).
    // Renvoie true si l'enregistrement a pu se faire, false si l'API n'est pas encore chargée.
    function registerWidget() {
        if (!window.OCA || !OCA.Dashboard) { return false }
        OCA.Dashboard.register('temonia_projets', function (container) { mount(container) })
        return true
    }

    // OCA.Dashboard peut ne pas être prêt immédiatement : si l'enregistrement échoue maintenant,
    // on réessaie une fois la page entièrement chargée (événement DOMContentLoaded).
    if (!registerWidget()) {
        document.addEventListener('DOMContentLoaded', registerWidget)
    }
})()
