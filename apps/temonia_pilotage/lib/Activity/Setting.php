<?php
// SPDX-FileCopyrightText: 2026 Flavien Auvray
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\TemoniaPilotage\Activity;

use OCP\Activity\ISetting;

/**
 * Déclare le type d'activité « Projets l'association » dans les réglages d'Activité de
 * Nextcloud (chaque utilisateur peut l'activer/désactiver dans son flux).
 * L'identifiant doit correspondre au setType() utilisé par ActivityService.
 */
class Setting implements ISetting {
    // Identifiant technique de ce type d'activité. DOIT être identique au setType('temonia_projets')
    // d'ActivityService, sinon Nextcloud ne saurait pas relier les deux.
    public function getIdentifier(): string {
        return 'temonia_projets';
    }

    // Libellé affiché dans les réglages d'Activité de l'utilisateur.
    public function getName(): string {
        return 'Projets (tâches, statuts, blocages)';
    }

    // Ordre d'affichage parmi les réglages d'activité (petit = plus haut).
    public function getPriority(): int {
        return 50;
    }

    // L'utilisateur peut-il activer/désactiver l'affichage dans son FLUX d'activité ? Oui.
    public function canChangeStream(): bool {
        return true;
    }

    // Par défaut (avant tout choix), ce type est-il affiché dans le flux ? Oui.
    public function isDefaultEnabledStream(): bool {
        return true;
    }

    // Peut-on recevoir ces activités par E-MAIL ? Non (on ne gère que le flux, pas les mails).
    public function canChangeMail(): bool {
        return false;
    }

    // Par défaut, envoi par e-mail ? Non.
    public function isDefaultEnabledMail(): bool {
        return false;
    }
}
