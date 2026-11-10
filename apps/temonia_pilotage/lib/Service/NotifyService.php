<?php
// SPDX-FileCopyrightText: 2026 Flavien Auvray
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\TemoniaPilotage\Service;

use OCA\TemoniaPilotage\AppInfo\Application;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\Notification\IManager;

/**
 * Envoi de notifications Nextcloud (cloche) à la direction quand l'équipe
 * signale un blocage sur une tâche. Le groupe destinataire est le même que
 * celui d'AccessService (config `group_direction`, défaut « direction »).
 */
class NotifyService {
    private IManager $manager;
    private IGroupManager $groupManager;
    private IConfig $config;

    public function __construct(IManager $manager, IGroupManager $groupManager, IConfig $config) {
        $this->manager = $manager;
        $this->groupManager = $groupManager;
        $this->config = $config;
    }

    // Crée une notification (cloche) pour chaque membre de la direction quand un blocage est signalé.
    public function blocage(int $tacheId, string $tacheTitre, string $byUid, string $byName, string $texte): void {
        // Groupe direction (configurable), puis on le récupère.
        $groupName = $this->config->getAppValue(Application::APP_ID, 'group_direction', 'direction');
        $group = $this->groupManager->get($groupName);
        if ($group === null) {
            // Groupe direction absent → personne à prévenir (on n'échoue pas pour autant).
            return;
        }
        $now = new \DateTime();
        foreach ($group->getUsers() as $u) {
            // On ne se notifie pas soi-même : inutile de prévenir l'auteur du blocage.
            if ($u->getUID() === $byUid) {
                continue;
            }
            // Construction de la notification (chaînage de setters, comme pour l'activité).
            $n = $this->manager->createNotification();
            $n->setApp('temonia_pilotage')
                ->setUser($u->getUID())                    // destinataire
                ->setDateTime($now)
                ->setObject('tache', (string)$tacheId)     // objet concerné (permet de la retrouver/purger)
                ->setSubject('blocage', [                  // sujet + paramètres (mis en forme par le Notifier)
                    'tache' => mb_substr($tacheTitre, 0, 120),
                    'by' => $byName,
                    'texte' => mb_substr($texte, 0, 140),
                ]);
            $this->manager->notify($n);                    // envoi
        }
    }

    // Notifie la direction (direction) qu'un MEMBRE a proposé une nouvelle tâche à valider.
    // Même principe que blocage(), mais objet « tache_proposition » (flux distinct) et sujet « proposition ».
    public function proposition(int $tacheId, string $tacheTitre, string $byUid, string $byName): void {
        $groupName = $this->config->getAppValue(Application::APP_ID, 'group_direction', 'direction');
        $group = $this->groupManager->get($groupName);
        if ($group === null) {
            return; // groupe direction absent → personne à prévenir
        }
        $now = new \DateTime();
        foreach ($group->getUsers() as $u) {
            if ($u->getUID() === $byUid) {
                continue; // on ne se notifie pas soi-même
            }
            $n = $this->manager->createNotification();
            $n->setApp('temonia_pilotage')
                ->setUser($u->getUID())
                ->setDateTime($now)
                ->setObject('tache_proposition', (string)$tacheId) // objet dédié → purge indépendante des blocages
                ->setSubject('proposition', [
                    'tache' => mb_substr($tacheTitre, 0, 120),
                    'by' => $byName,
                ]);
            $this->manager->notify($n);
        }
    }

    /** Retire la notification « à valider » d'une tâche (quand la direction l'a validée ou refusée). */
    public function clearProposition(int $tacheId): void {
        $n = $this->manager->createNotification();
        $n->setApp('temonia_pilotage')->setObject('tache_proposition', (string)$tacheId);
        $this->manager->markProcessed($n);
    }

    // ---------- FRAIS ----------

    /**
     * Prévient les ADMINISTRATEURS Nextcloud (groupe « admin » = ceux qui valident les frais,
     * cf. AccessService::isAdmin) qu'une nouvelle note de frais est à traiter.
     */
    public function fraisNouveau(int $fraisId, string $numero, string $byUid, string $byName, float $montant): void {
        $group = $this->groupManager->get('admin');
        if ($group === null) {
            return;
        }
        $now = new \DateTime();
        foreach ($group->getUsers() as $u) {
            if ($u->getUID() === $byUid) {
                continue; // l'admin qui saisit son propre frais n'a pas besoin d'être prévenu
            }
            $n = $this->manager->createNotification();
            $n->setApp('temonia_pilotage')
                ->setUser($u->getUID())
                ->setDateTime($now)
                ->setObject('frais', (string)$fraisId)       // objet « frais » → retiré une fois traité
                ->setSubject('frais_nouveau', [
                    'numero' => $numero,
                    'by' => $byName,
                    'montant' => number_format($montant, 2, ',', ' '),
                ]);
            $this->manager->notify($n);
        }
    }

    /**
     * Prévient l'AUTEUR d'un frais de la décision de l'admin. $statut = 'valide' | 'refuse' | 'rembourse'.
     * $motif = motif du refus (affiché dans le message), sinon null.
     */
    public function fraisStatut(int $fraisId, string $numero, string $ownerUid, string $statut, ?string $motif): void {
        $n = $this->manager->createNotification();
        $n->setApp('temonia_pilotage')
            ->setUser($ownerUid)
            ->setDateTime(new \DateTime())
            ->setObject('frais_statut', (string)$fraisId)
            ->setSubject('frais_' . $statut, [
                'numero' => $numero,
                'motif' => $motif !== null ? mb_substr($motif, 0, 140) : '',
            ]);
        $this->manager->notify($n);
    }

    /** Retire la notification « nouvelle note à valider » d'un frais (traité ou supprimé). */
    public function clearFraisNouveau(int $fraisId): void {
        $n = $this->manager->createNotification();
        $n->setApp('temonia_pilotage')->setObject('frais', (string)$fraisId);
        $this->manager->markProcessed($n);
    }

    /** Retire les notifications de blocage d'une tâche (quand résolu). */
    public function clearBlocage(int $tacheId): void {
        // On décrit la MÊME cible (app + objet 'tache' + id) sans destinataire précis : markProcessed
        // supprime alors toutes les notifications correspondantes (chez tout le monde). Utile quand le
        // blocage est levé : la cloche rouge ne doit plus rester pour un problème déjà réglé.
        $n = $this->manager->createNotification();
        $n->setApp('temonia_pilotage')->setObject('tache', (string)$tacheId);
        $this->manager->markProcessed($n);
    }
}
