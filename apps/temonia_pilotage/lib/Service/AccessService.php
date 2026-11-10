<?php
// SPDX-FileCopyrightText: 2026 Flavien Auvray
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\TemoniaPilotage\Service;

use OCA\TemoniaPilotage\AppInfo\Application;
use OCA\TemoniaPilotage\Db\ProjetMapper;
use OCP\IConfig;
use OCP\IGroupManager; // service NC pour interroger les groupes (appartenance, admin…)
use OCP\IUserManager;  // service NC pour interroger les utilisateurs

/**
 * Qui a accès à quels projets côté équipe. La direction (admin NC ou membre du
 * groupe « direction ») voit tout ; sinon, un utilisateur voit les projets dont
 * il est membre (personne ou via un groupe Nextcloud membre).
 *
 * Le nom du groupe « direction » est CONFIGURABLE (il diffère d'une instance à
 * l'autre). Par défaut « direction » ; en prod on peut pointer vers un autre
 * groupe existant via :
 *   occ config:app:set temonia_pilotage group_direction --value="admin"
 */
class AccessService {
    // Préfixe qui distingue un assigné « groupe » d'un assigné « personne » dans le
    // champ `assignee` (une chaîne unique) : "group:<gid>" = groupe ; sinon = uid.
    // Astuce de conception : une seule colonne stocke les deux cas, le préfixe fait la différence.
    public const GROUP_PREFIX = 'group:';

    // Principal VIRTUEL « Équipe » = regroupement des bénévoles + salariés (les Partenaires/
    // partenaires restent à part). Ce n'est PAS un groupe Nextcloud : quand on affecte « Équipe »
    // à une tâche, on considère concerné TOUTE personne membre du groupe bénévoles OU du groupe salariés.
    // Côté public (fiche tâche), on affiche « Équipe » sans distinguer bénévole/salarié ; la
    // distinction ne sert qu'à la ventilation confidentielle de l'écran Administration.
    public const TEAM_TEMONIA = 'team:temonia';

    private ProjetMapper $projets;
    private IGroupManager $groupManager;
    private IUserManager $userManager;
    private IConfig $config;
    private Libelles $libelles;

    public function __construct(ProjetMapper $projets, IGroupManager $groupManager, IUserManager $userManager, IConfig $config, Libelles $libelles) {
        $this->libelles = $libelles;
        $this->projets = $projets;
        $this->groupManager = $groupManager;
        $this->userManager = $userManager;
        $this->config = $config;
    }

    /**
     * Nom affichable d'un assigné, qu'il s'agisse d'une personne (uid) ou d'un
     * groupe ("group:<gid>"). Retombe sur l'identifiant si l'entité n'existe plus.
     */
    public function principalName(?string $assignee): ?string {
        // Pas d'assigné → pas de nom.
        if ($assignee === null || $assignee === '') {
            return null;
        }
        // Équipe de la structure (composite bénévoles + salariés) : pas un vrai groupe ; son nom
        // (« Équipe », « Équipe Les Jardins »…) vient des libellés réglables (Service/Libelles).
        if ($assignee === self::TEAM_TEMONIA) {
            return $this->libelles->get('equipe');
        }
        // strpos(..., prefix) === 0 = « la chaîne COMMENCE par ce préfixe ». Si oui, c'est un groupe :
        // on retire le préfixe (substr à partir de sa longueur) pour récupérer le vrai gid.
        if (strpos($assignee, self::GROUP_PREFIX) === 0) {
            $gid = substr($assignee, strlen(self::GROUP_PREFIX));
            $g = $this->groupManager->get($gid);
            return $g ? $g->getDisplayName() : $gid; // nom du groupe, ou le gid s'il a disparu
        }
        // Sinon c'est une personne : on résout son nom affichable (ou l'uid en repli).
        $u = $this->userManager->get($assignee);
        return $u ? $u->getDisplayName() : $assignee;
    }

    /** Vrai si $principal (uid, "group:<gid>", ou l'équipe virtuelle l'association) est valide. */
    public function principalExiste(string $principal): bool {
        if ($principal === self::TEAM_TEMONIA) { return true; }
        if (strpos($principal, self::GROUP_PREFIX) === 0) {
            $gid = substr($principal, strlen(self::GROUP_PREFIX));
            return $gid !== '' && $this->groupManager->groupExists($gid);
        }
        return $this->userManager->userExists($principal);
    }

    /** Est-ce que $uid est concerné par cet assigné (lui-même, OU membre du groupe assigné) ? */
    public function isAssignedTo(string $uid, ?string $assignee): bool {
        if ($assignee === null || $assignee === '') {
            return false;
        }
        // Équipe (composite) : concerné si membre des bénévoles OU des salariés.
        if ($assignee === self::TEAM_TEMONIA) {
            $g = $this->teamGroups();
            return $this->groupManager->isInGroup($uid, $g['benevole']) || $this->groupManager->isInGroup($uid, $g['salarie']);
        }
        // Assigné à un GROUPE : vrai si l'utilisateur appartient à ce groupe.
        if (strpos($assignee, self::GROUP_PREFIX) === 0) {
            return $this->groupManager->isInGroup($uid, substr($assignee, strlen(self::GROUP_PREFIX)));
        }
        // Assigné à une PERSONNE : vrai seulement si c'est exactement cette personne.
        return $assignee === $uid;
    }

    /** Nom du groupe Nextcloud considéré comme « la direction » (paramétrable). */
    private function directionGroup(): string {
        // getAppValue(app, clé, défaut) : lit un réglage GLOBAL de l'app (pas par utilisateur).
        // Ici : le groupe « direction », avec 'direction' comme valeur par défaut si non configuré.
        return $this->config->getAppValue(Application::APP_ID, 'group_direction', 'direction');
    }

    public function isDirection(string $uid): bool {
        // Les administrateurs Nextcloud sont toujours considérés comme direction.
        // « || » = OU : admin NC, OU membre du groupe direction configuré → true.
        return $this->groupManager->isAdmin($uid) || $this->groupManager->isInGroup($uid, $this->directionGroup());
    }

    /**
     * Administrateur Nextcloud « pur » (= la direction). Réservé au tableau de bord
     * d'ADMINISTRATION (détail des heures par personne), plus restreint que isDirection.
     */
    public function isAdmin(string $uid): bool {
        return $uid !== '' && $this->groupManager->isAdmin($uid);
    }

    /**
     * Seul la direction peut ASSIGNER une tâche à quelqu'un. Les membres
     * peuvent créer des (sous-)tâches, changer les statuts et saisir leurs heures,
     * mais pas décider qui fait quoi. On réutilise isDirection (qui inclut l'admin).
     */
    public function peutAssigner(string $uid): bool {
        return $this->isDirection($uid);
    }

    /**
     * Noms des 3 groupes d'équipe Nextcloud (CONFIGURABLES). Structure l'association :
     *   Équipe = « bénévole » + « salarié »   |   « partenaire » à part.
     * Défauts = les groupes réels de la prod. Pour pointer vers d'autres groupes :
     *   occ config:app:set temonia_pilotage group_equipe_salarie  --value="équipe salariée"
     *   occ config:app:set temonia_pilotage group_equipe_benevole --value="bénévoles"
     *   occ config:app:set temonia_pilotage group_equipe_partenaire  --value="Partenaires"
     * @return array{benevole:string,salarie:string,partenaire:string}
     */
    public function teamGroups(): array {
        return [
            'benevole' => $this->config->getAppValue(Application::APP_ID, 'group_equipe_benevole', 'bénévoles'),
            'salarie' => $this->config->getAppValue(Application::APP_ID, 'group_equipe_salarie', 'équipe salariée'),
            'pompier' => $this->config->getAppValue(Application::APP_ID, 'group_equipe_pompier', 'Pompiers'),
        ];
    }

    /**
     * Catégorie d'équipe d'une PERSONNE : 'benevole' | 'salarie' | 'partenaire' | 'autre'.
     * Sert au dashboard admin à ventiler les heures par équipe. Si quelqu'un est dans
     * plusieurs groupes, l'ordre ci-dessous décide (salarié prioritaire).
     */
    public function teamOf(string $uid): string {
        $g = $this->teamGroups();
        if ($this->groupManager->isInGroup($uid, $g['salarie'])) { return 'salarie'; }
        if ($this->groupManager->isInGroup($uid, $g['benevole'])) { return 'benevole'; }
        if ($this->groupManager->isInGroup($uid, $g['pompier'])) { return 'pompier'; }
        return 'autre';
    }

    // Détermine si $uid fait partie des membres d'un projet. $membres est la liste JSON
    // stockée sur le projet, sous forme d'entrées { type: 'user'|'group', id: '...' }.
    private function isMember(string $uid, ?array $membres): bool {
        if (empty($membres)) {
            return false; // aucun membre défini → personne n'y a accès (hors direction)
        }
        foreach ($membres as $m) {
            if (!is_array($m)) {
                continue; // entrée mal formée → on ignore
            }
            $type = $m['type'] ?? '';
            $id = (string)($m['id'] ?? '');
            // Membre « personne » : accès si l'id correspond exactement à l'utilisateur.
            if ($type === 'user' && $id === $uid) {
                return true;
            }
            // Membre « groupe » : accès si l'utilisateur appartient à ce groupe.
            if ($type === 'group' && $id !== '' && $this->groupManager->isInGroup($uid, $id)) {
                return true;
            }
        }
        return false; // aucune entrée ne correspond
    }

    /** @return int[] ids des projets visibles par $uid (tous si direction) */
    public function accessibleProjetIds(string $uid): array {
        $ids = [];
        // On calcule UNE fois si l'utilisateur est direction (évite de le refaire à chaque projet).
        $direction = $this->isDirection($uid);
        foreach ($this->projets->findAll() as $p) {
            // Direction = accès à tout ; sinon, accès seulement si membre du projet.
            if ($direction || $this->isMember($uid, $p->getMembres())) {
                $ids[] = $p->getId();
            }
        }
        return $ids;
    }

    /** $uid a-t-il accès à ce projet (direction OU membre) ? */
    public function canAccess(string $uid, int $projetId): bool {
        // On réutilise la liste des projets accessibles et on vérifie que celui-ci y figure.
        // Le 3e argument « true » de in_array = comparaison stricte (type + valeur).
        return in_array($projetId, $this->accessibleProjetIds($uid), true);
    }
}
