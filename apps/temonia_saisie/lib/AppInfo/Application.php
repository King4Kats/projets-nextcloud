<?php
// SPDX-FileCopyrightText: 2026 Flavien Auvray
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\TemoniaSaisie\AppInfo;

use OCP\AppFramework\App;
use OCA\TemoniaSaisie\Dashboard\ProjetsWidget;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

/**
 * Point d'entrée de l'app « Projets » (temonia_saisie) : l'interface utilisée
 * par toute l'équipe. Elle s'appuie sur la couche données de Pilotage
 * (mappers/services du namespace OCA\l'associationPilotage), qui doit être installée.
 */
class Application extends App implements IBootstrap {
    public const APP_ID = 'temonia_saisie';

    public function __construct() {
        parent::__construct(self::APP_ID);
    }

    // register() : appelée TÔT, au chargement de l'app. On y DÉCLARE ce qu'on ajoute à
    // Nextcloud (widgets, écouteurs d'événements, services…). Ici : notre tuile de dashboard.
    public function register(IRegistrationContext $context): void {
        // Tuile sur le tableau de bord d'accueil de Nextcloud (voir Dashboard\ProjetsWidget).
        $context->registerDashboardWidget(ProjetsWidget::class);
    }

    // boot() : appelée APRÈS register(), quand tout est prêt. On y mettrait du code qui a
    // besoin que les services soient déjà branchés. Rien à faire ici pour le moment.
    public function boot(IBootContext $context): void {
    }
}
