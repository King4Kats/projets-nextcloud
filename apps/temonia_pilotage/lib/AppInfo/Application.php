<?php
// SPDX-FileCopyrightText: 2026 Flavien Auvray
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\TemoniaPilotage\AppInfo;

use OCA\TemoniaPilotage\Notification\Notifier;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

/**
 * Point d'entrée de l'app « socle » Pilotage.
 * Elle n'a plus d'interface (voir routes.php) : elle fournit la base de données,
 * les services partagés et le Notifier de blocage à l'app « Projets » (temonia_saisie).
 */
class Application extends App implements IBootstrap {
    public const APP_ID = 'temonia_pilotage';

    public function __construct() {
        parent::__construct(self::APP_ID);
    }

    // register() : au chargement de l'app, on DÉCLARE notre Notifier de notifications à Nextcloud
    // (c'est lui qui met en forme la cloche « blocage signalé »). Le flux Activité et les settings
    // sont, eux, déclarés dans info.xml (bloc <activity>), pas ici.
    public function register(IRegistrationContext $context): void {
        $context->registerNotifierService(Notifier::class);
    }

    // boot() : rien à faire ici pour le moment.
    public function boot(IBootContext $context): void {
    }
}
