<?php
// SPDX-FileCopyrightText: 2026 Flavien Auvray
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\TemoniaPilotage\Notification;

use OCP\IURLGenerator;
use OCP\L10N\IFactory;
use OCP\Notification\INotification;
use OCP\Notification\INotifier;
use OCP\Notification\UnknownNotificationException;

/**
 * Rend les notifications de l'app (blocage signalé sur une tâche).
 */
class Notifier implements INotifier {
    private IFactory $l10nFactory;
    private IURLGenerator $url;

    public function __construct(IFactory $l10nFactory, IURLGenerator $url) {
        $this->l10nFactory = $l10nFactory;
        $this->url = $url;
    }

    public function getID(): string {
        return 'temonia_pilotage';
    }

    public function getName(): string {
        return 'Projets';
    }

    // prepare() transforme une notification BRUTE (juste un sujet + des paramètres, cf. NotifyService)
    // en notification LISIBLE (texte, icône, lien). Nextcloud l'appelle au moment d'afficher la cloche.
    public function prepare(INotification $notification, string $languageCode): INotification {
        // Ce Notifier ne s'occupe QUE de nos notifications. Pour les autres, on lève l'exception
        // « inconnue » → Nextcloud passera au notifier suivant (on ne doit pas les traiter).
        if ($notification->getApp() !== 'temonia_pilotage') {
            throw new UnknownNotificationException();
        }
        $l = $this->l10nFactory->get('temonia_pilotage', $languageCode); // outil de traduction (langue de l'utilisateur)
        if ($notification->getSubject() === 'blocage') {
            // On relit les paramètres passés à la création (tâche, auteur, texte).
            $p = $notification->getSubjectParameters();
            $tache = $p['tache'] ?? 'une tâche';
            $by = $p['by'] ?? '';
            $texte = $p['texte'] ?? '';
            // Titre + message affichés dans la cloche.
            $notification->setParsedSubject('🚧 Blocage signalé : ' . $tache);
            $notification->setParsedMessage(trim($by . ($by ? ' — ' : '') . $texte)); // « Auteur — texte » (ou juste l'un des deux)
            $notification->setIcon($this->url->getAbsoluteURL($this->url->imagePath('temonia_pilotage', 'app.svg')));
            // Clic → l'app « Projets » (temonia_saisie). NB : temonia_pilotage n'a plus de page/route,
            // donc on lie TOUJOURS vers temonia_saisie, sinon linkToRouteAbsolute lèverait une exception.
            $notification->setLink($this->url->linkToRouteAbsolute('temonia_saisie.page.index'));
            return $notification;
        }
        if ($notification->getSubject() === 'proposition') {
            // Notification « nouvelle tâche proposée par un membre, à valider ».
            $p = $notification->getSubjectParameters();
            $tache = $p['tache'] ?? 'une tâche';
            $by = $p['by'] ?? '';
            $notification->setParsedSubject('🆕 Nouvelle tâche à valider : ' . $tache);
            $notification->setParsedMessage($by !== '' ? ('Proposée par ' . $by) : '');
            $notification->setIcon($this->url->getAbsoluteURL($this->url->imagePath('temonia_pilotage', 'app.svg')));
            $notification->setLink($this->url->linkToRouteAbsolute('temonia_saisie.page.index'));
            return $notification;
        }
        // ---- Notifications FRAIS ----
        // Les liens pointent vers l'app « Frais » (temonia_frais) + un « #hash » qui ouvre directement
        // le bon écran : #admin pour l'administrateur, rien (Mes frais) pour l'auteur.
        $subject = $notification->getSubject();
        if ($subject === 'frais_nouveau') {
            $p = $notification->getSubjectParameters();
            $notification->setParsedSubject('🧾 Nouvelle note de frais ' . ($p['numero'] ?? '') . ' à valider');
            $notification->setParsedMessage(trim(($p['by'] ?? '') . ' · ' . ($p['montant'] ?? '') . ' €'));
            $notification->setIcon($this->url->getAbsoluteURL($this->url->imagePath('temonia_pilotage', 'app.svg')));
            $notification->setLink($this->fraisLink('#admin'));
            return $notification;
        }
        $fraisSujets = [
            'frais_valide' => '✅ Note de frais %s validée',
            'frais_refuse' => '❌ Note de frais %s refusée',
            'frais_rembourse' => '💶 Note de frais %s remboursée',
        ];
        if (isset($fraisSujets[$subject])) {
            $p = $notification->getSubjectParameters();
            $notification->setParsedSubject(sprintf($fraisSujets[$subject], $p['numero'] ?? ''));
            // Pour un refus, le motif de l'admin est affiché en message.
            $notification->setParsedMessage($subject === 'frais_refuse' && !empty($p['motif']) ? ('Motif : ' . $p['motif']) : '');
            $notification->setIcon($this->url->getAbsoluteURL($this->url->imagePath('temonia_pilotage', 'app.svg')));
            $notification->setLink($this->fraisLink(''));
            return $notification;
        }
        // Sujet non reconnu → on refuse de la préparer (sécurité : on ne gère que ce qu'on connaît).
        throw new UnknownNotificationException();
    }

    // Lien vers l'app Frais. Si elle n'est pas installée (route inconnue → exception), on retombe
    // sur l'app Projets pour que la notification reste affichable.
    private function fraisLink(string $hash): string {
        try {
            return $this->url->linkToRouteAbsolute('temonia_frais.page.index') . $hash;
        } catch (\Throwable $e) {
            return $this->url->linkToRouteAbsolute('temonia_saisie.page.index');
        }
    }
}
