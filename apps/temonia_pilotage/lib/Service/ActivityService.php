<?php
// SPDX-FileCopyrightText: 2026 Flavien Auvray
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\TemoniaPilotage\Service;

use OCA\TemoniaPilotage\AppInfo\Application;
use OCA\TemoniaPilotage\Db\Projet;
use OCA\TemoniaPilotage\Db\ProjetMapper;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\Server;

/**
 * Publie les événements de l'app dans le flux « Activité » de Nextcloud.
 *
 * Chaque événement est envoyé aux personnes concernées : les MEMBRES du projet
 * (personnes listées + membres des groupes listés) ainsi que la direction.
 *
 * Robustesse : le manager d'activité est récupéré à la demande (Server::get) et
 * tout est encapsulé dans try/catch — si l'app Activity est absente ou échoue,
 * l'action métier n'est JAMAIS interrompue.
 */
class ActivityService {
    private ProjetMapper $projets;
    private IGroupManager $groupManager;
    private IConfig $config;

    public function __construct(ProjetMapper $projets, IGroupManager $groupManager, IConfig $config) {
        $this->projets = $projets;
        $this->groupManager = $groupManager;
        $this->config = $config;
    }

    public function publish(int $projetId, string $action, ?string $titre, string $actorUid, string $elementType, int $elementId, ?string $avant, ?string $apres): void {
        // TOUT est dans un try/catch : si l'app Activity est absente/en panne, on n'interrompt jamais
        // l'action métier (voir aussi HistoService). \Throwable attrape toutes les erreurs possibles.
        try {
            // Server::get(...) récupère un service « à la demande » plutôt que par le constructeur :
            // ainsi, si l'app Activity n'est pas installée, on ne plante qu'ICI (attrapé), pas au démarrage.
            /** @var \OCP\Activity\IManager $manager */
            $manager = Server::get(\OCP\Activity\IManager::class);

            // À qui envoyer cet événement ? Aux membres du projet + la direction (voir recipients()).
            $projet = $this->projets->find($projetId);
            $recipients = $this->recipients($projet);
            if (empty($recipients)) {
                return; // personne à prévenir → rien à faire
            }
            // Paramètres passés à l'événement : ils seront relus par Activity\Provider pour composer
            // la phrase affichée (« X a changé le statut : À faire → En cours (Projet) »).
            $params = [
                'titre' => (string)($titre ?? ''),
                'elementType' => $elementType,
                'avant' => (string)($avant ?? ''),
                'apres' => (string)($apres ?? ''),
                'projet' => $projet->getNom(),
            ];
            // Un événement PAR destinataire (chaque personne voit l'activité dans son propre flux).
            foreach ($recipients as $uid) {
                $event = $manager->generateEvent();
                // Chaînage de setters (chaque set... renvoie l'événement, d'où le « -> » enchaîné) :
                $event->setApp(Application::APP_ID)          // notre app
                    ->setType('temonia_projets')             // catégorie (celle déclarée par Activity\Setting)
                    ->setAuthor($actorUid)                    // qui a fait l'action
                    ->setAffectedUser($uid)                   // qui reçoit cette ligne d'activité
                    ->setTimestamp(time())                    // quand
                    ->setSubject($action, $params)            // le « sujet » brut + ses paramètres
                    ->setObject($elementType, $elementId, (string)($titre ?? '')); // l'objet concerné
                $manager->publish($event); // envoi effectif
            }
        } catch (\Throwable $e) {
            // L'activité ne doit jamais casser l'action métier qu'elle journalise.
        }
    }

    /** @return string[] uids destinataires : membres du projet (personnes + groupes étendus) + direction. */
    private function recipients(Projet $projet): array {
        // On utilise un tableau associatif comme ENSEMBLE : la clé = l'uid, la valeur = true. Ça
        // dédoublonne tout seul (ajouter deux fois le même uid n'écrit qu'une seule clé).
        $set = [];
        foreach (($projet->getMembres() ?? []) as $m) {
            if (!is_array($m)) {
                continue;
            }
            $type = $m['type'] ?? '';
            $id = (string)($m['id'] ?? '');
            if ($type === 'user' && $id !== '') {
                $set[$id] = true;                       // membre « personne » : on l'ajoute directement
            } elseif ($type === 'group' && $id !== '') {
                $this->addGroupUsers($set, $id);        // membre « groupe » : on ajoute tous ses utilisateurs
            }
        }
        // La direction reçoit l'activité de tous les projets.
        $this->addGroupUsers($set, $this->config->getAppValue(Application::APP_ID, 'group_direction', 'direction'));
        // array_keys récupère les uids (les clés de l'ensemble) sous forme de liste simple.
        return array_keys($set);
    }

    // Ajoute dans l'ensemble tous les membres d'un groupe. « array &$set » = par référence :
    // on modifie le vrai tableau de l'appelant (pour cumuler les destinataires au fil des appels).
    private function addGroupUsers(array &$set, string $gid): void {
        $g = $gid !== '' ? $this->groupManager->get($gid) : null;
        if ($g === null) {
            return; // groupe inconnu → rien à ajouter
        }
        foreach ($g->getUsers() as $u) {
            $set[$u->getUID()] = true;
        }
    }
}
