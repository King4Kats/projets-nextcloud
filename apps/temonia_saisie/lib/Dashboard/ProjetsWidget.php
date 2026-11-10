<?php
// SPDX-FileCopyrightText: 2026 Flavien Auvray
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\TemoniaSaisie\Dashboard;

use OCA\TemoniaSaisie\AppInfo\Application;
use OCP\Dashboard\IWidget;
use OCP\IURLGenerator;
use OCP\Util;

/**
 * Tuile affichée sur le TABLEAU DE BORD D'ACCUEIL de Nextcloud (l'icône ⚡).
 *
 * Le rendu réel (barres de progression par projet + alertes de blocage) est fait
 * en JavaScript : `load()` charge le script `dashboard-widget.js` qui s'enregistre
 * auprès de `OCA.Dashboard` et va chercher les données via l'API de l'app.
 * La tuile est cliquable et renvoie vers l'application « Projets ».
 */
class ProjetsWidget implements IWidget {
    private IURLGenerator $url;

    public function __construct(IURLGenerator $url) {
        $this->url = $url;
    }

    public function getId(): string {
        return 'temonia_projets';
    }

    public function getTitle(): string {
        return 'Projets';
    }

    /** Ordre d'affichage parmi les widgets du dashboard (petit = plus haut). */
    public function getOrder(): int {
        return 10;
    }

    /** Icône de l'en-tête : une classe d'icône Nextcloud existante (thémée par NC). */
    public function getIconClass(): string {
        return 'icon-category-organization';
    }

    /** Lien « Voir plus » de la tuile → la page de l'app. */
    public function getUrl(): ?string {
        return $this->url->linkToRouteAbsolute('temonia_saisie.page.index');
    }

    /** Charge le JS/CSS qui dessine le contenu de la tuile. */
    public function load(): void {
        Util::addScript(Application::APP_ID, 'dashboard-widget');
        Util::addStyle(Application::APP_ID, 'dashboard-widget');
    }
}
