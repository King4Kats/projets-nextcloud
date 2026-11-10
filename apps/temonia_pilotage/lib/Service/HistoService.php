<?php
// SPDX-FileCopyrightText: 2026 Flavien Auvray
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\TemoniaPilotage\Service;

use OCA\TemoniaPilotage\Db\Historique;
use OCA\TemoniaPilotage\Db\HistoriqueMapper;
use OCP\AppFramework\Utility\ITimeFactory;

/**
 * Enregistre les événements d'historique (qui a fait quoi, quand) sur les
 * phases / activités / tâches / sous-tâches / projets.
 */
class HistoService {
    // Actions qui méritent d'apparaître dans le flux « Activité » de Nextcloud
    // (on n'y met pas les petites éditions ni les changements de membres).
    private const ACTIVITY_ACTIONS = ['statut', 'blocage', 'deblocage', 'creation', 'suppression', 'assignation'];

    private HistoriqueMapper $mapper;
    private ITimeFactory $time;
    private ActivityService $activity;

    public function __construct(HistoriqueMapper $mapper, ITimeFactory $time, ActivityService $activity) {
        $this->mapper = $mapper;
        $this->time = $time;
        $this->activity = $activity;
    }

    // Enregistre UN événement. Appelée par les contrôleurs après chaque action notable
    // (création, changement de statut, blocage…). $avant/$apres servent aux changements de valeur.
    public function record(int $projetId, string $type, int $elementId, ?string $titre, string $uid, string $action, ?string $avant = null, ?string $apres = null): void {
        // On construit la ligne d'historique et on remplit ses champs.
        $h = new Historique();
        $h->setProjetId($projetId);
        $h->setElementType($type);
        $h->setElementId($elementId);
        // mb_substr(..., 0, 255) : on tronque le titre à 255 caractères (taille de la colonne) ;
        // « mb_ » = version qui compte les caractères accentués correctement (UTF-8), pas les octets.
        $h->setElementTitre($titre !== null ? mb_substr($titre, 0, 255) : null);
        $h->setUserId($uid);
        $h->setAction($action);
        $h->setAvant($avant);
        $h->setApres($apres);
        $h->setCreatedAt($this->time->getTime());
        try {
            $this->mapper->insert($h);
        } catch (\Throwable $e) {
            // RÈGLE D'OR : l'historique ne doit JAMAIS casser une action métier. Si l'écriture du
            // journal échoue, on avale l'erreur (\Throwable attrape tout) — l'action principale (ex.
            // changer un statut) a déjà réussi et ne doit pas être annulée à cause d'un souci d'audit.
        }
        // En plus du journal interne, on alimente le flux « Activité » de Nextcloud
        // pour les actions marquantes (ActivityService encapsule ses propres erreurs).
        if (in_array($action, self::ACTIVITY_ACTIONS, true)) {
            $this->activity->publish($projetId, $action, $titre, $uid, $type, $elementId, $avant, $apres);
        }
    }
}
