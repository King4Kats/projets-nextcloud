<?php
// SPDX-FileCopyrightText: 2026 Flavien Auvray
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\TemoniaPilotage\Activity;

use OCA\TemoniaPilotage\AppInfo\Application;
use OCP\Activity\Exceptions\UnknownActivityException;
use OCP\Activity\IEvent;
use OCP\Activity\IProvider;
use OCP\IURLGenerator;
use OCP\IUserManager;

/**
 * Transforme un événement brut de l'app en phrase lisible dans l'onglet
 * « Activité » de Nextcloud (ex. « Jean a changé le statut de « Nom et visuel » :
 * À faire → En cours (Projet Partenaires) »).
 */
class Provider implements IProvider {
    private IUserManager $userManager;
    private IURLGenerator $url;

    public function __construct(IUserManager $userManager, IURLGenerator $url) {
        $this->userManager = $userManager;
        $this->url = $url;
    }

    // ⚠️ Signature à respecter EXACTEMENT : dans OCP\Activity\IProvider, $language n'a PAS
    // de type déclaré. Y ajouter « string » provoque un fatal « must be compatible » qui casse
    // toute l'app Activity de Nextcloud.
    public function parse($language, IEvent $event, ?IEvent $previousEvent = null): IEvent {
        // On ne traite que NOS événements ; sinon on laisse la main aux autres providers
        // (chaque app a son provider ; lever l'exception dit « ce n'est pas pour moi »).
        if ($event->getApp() !== Application::APP_ID) {
            throw new UnknownActivityException();
        }
        // On relit les paramètres posés par ActivityService (titre, projet, avant, apres…).
        $p = $event->getSubjectParameters();
        $actor = $this->name($event->getAuthor()); // nom lisible de l'auteur
        $titre = (string)($p['titre'] ?? '');
        $projet = (string)($p['projet'] ?? '');
        // Petits fragments réutilisés : le titre entre guillemets, et le projet entre parenthèses.
        $suffix = $titre !== '' ? ' « ' . $titre . ' »' : '';
        $ctx = $projet !== '' ? ' (' . $projet . ')' : '';

        // Selon le type d'action (le « sujet »), on compose une phrase différente.
        switch ($event->getSubject()) {
            case 'statut':
                $text = $actor . ' a changé le statut de' . $suffix . ' : ' . ($p['avant'] ?: '?') . ' → ' . ($p['apres'] ?: '?') . $ctx;
                break;
            case 'blocage':
                $text = '⛔ ' . $actor . ' a signalé un blocage sur' . $suffix . $ctx;
                break;
            case 'deblocage':
                $text = $actor . ' a levé le blocage sur' . $suffix . $ctx;
                break;
            case 'creation':
                $text = $actor . ' a créé' . $suffix . $ctx;
                break;
            case 'suppression':
                $text = $actor . ' a supprimé' . $suffix . $ctx;
                break;
            case 'assignation':
                $text = $actor . ' a (ré)assigné' . $suffix . ' à ' . ($p['apres'] ?: '—') . $ctx;
                break;
            default:
                $text = $actor . ' a modifié' . $suffix . $ctx;
        }

        // On pose la phrase finale + l'icône + le lien de clic sur l'événement, puis on le renvoie.
        $event->setParsedSubject($text);
        $event->setIcon($this->url->getAbsoluteURL($this->url->imagePath('temonia_pilotage', 'app.svg')));
        // Clic → l'app « Projets ».
        $event->setLink($this->url->linkToRouteAbsolute('temonia_saisie.page.index'));
        return $event;
    }

    private function name(string $uid): string {
        $u = $uid !== '' ? $this->userManager->get($uid) : null;
        return $u ? $u->getDisplayName() : ($uid !== '' ? $uid : 'Quelqu’un');
    }
}
