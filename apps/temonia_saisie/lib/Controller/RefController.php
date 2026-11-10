<?php
// SPDX-FileCopyrightText: 2026 Flavien Auvray
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\TemoniaSaisie\Controller;

// Réutilise la couche données de Pilotage (app compagnon).
// Un « Mapper » est l'objet qui parle à la base de données pour un type d'enregistrement
// (ProjetMapper → table des projets, etc.). On ne fait jamais de SQL à la main ici.
use OCA\TemoniaPilotage\Db\ActiviteMapper;
use OCA\TemoniaPilotage\Db\HeureMapper;    // heures pointées (réel « propre » de chaque tâche)
use OCA\TemoniaPilotage\Db\ProjetMapper;
use OCA\TemoniaPilotage\Db\TacheMapper;
use OCA\TemoniaPilotage\Service\AccessService;
use OCA\TemoniaPilotage\Service\AffectationService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse; // réponse = des données JSON (et non une page HTML)
use OCP\IRequest;
use OCP\IUserManager;   // pour parcourir les utilisateurs NC
use OCP\IUserSession;   // pour connaître l'utilisateur connecté

/**
 * Données de référence en LECTURE SEULE pour l'app « Projets » : liste des
 * projets accessibles à l'utilisateur, leurs activités et tâches, et la table
 * uid → nom. Chaque endpoint filtre sur les projets réellement autorisés.
 */
class RefController extends Controller {
    // Toutes les dépendances dont le contrôleur a besoin, mémorisées en propriétés.
    private ProjetMapper $projets;
    private ActiviteMapper $activites;
    private TacheMapper $taches;
    private AccessService $access;
    private AffectationService $affService;
    private HeureMapper $heures;
    private IUserSession $userSession;
    private IUserManager $userManager;
    private \OCP\IGroupManager $groupManager; // gère les GROUPES Nextcloud

    // Injection de dépendances : Nextcloud remplit chaque paramètre selon son type.
    public function __construct(string $appName, IRequest $request, ProjetMapper $projets, ActiviteMapper $activites, TacheMapper $taches, AccessService $access, AffectationService $affService, HeureMapper $heures, IUserSession $userSession, IUserManager $userManager, \OCP\IGroupManager $groupManager) {
        parent::__construct($appName, $request);
        $this->heures = $heures;
        $this->projets = $projets;
        $this->activites = $activites;
        $this->taches = $taches;
        $this->access = $access;
        $this->affService = $affService;
        $this->userSession = $userSession;
        $this->userManager = $userManager;
        $this->groupManager = $groupManager;
    }

    /** uid → displayName (pour afficher les assignés dans l'arbre). */
    #[NoAdminRequired]
    public function users(): DataResponse {
        // On construit un tableau $out qu'on remplira au fur et à mesure.
        $out = [];
        // callForAllUsers exécute la fonction fournie pour CHAQUE utilisateur NC.
        // « use (&$out) » : le & signifie « par référence » → la fonction modifie le vrai
        // $out (et pas une copie), pour qu'on récupère les lignes ajoutées.
        $this->userManager->callForAllUsers(static function ($u) use (&$out) {
            // Pour chaque utilisateur : son identifiant technique (uid) + son nom lisible.
            $out[] = ['uid' => $u->getUID(), 'displayName' => $u->getDisplayName()];
        });
        // On renvoie le tableau : Nextcloud le transforme automatiquement en JSON.
        return new DataResponse($out);
    }

    /** Groupes Nextcloud (gid → displayName) : pour pouvoir assigner une tâche à un groupe. */
    #[NoAdminRequired]
    public function groups(): DataResponse {
        $out = [];
        // search('') avec une chaîne vide = « tous les groupes » (aucun filtre de nom).
        foreach ($this->groupManager->search('') as $g) {
            // gid = identifiant technique du groupe ; displayName = son nom affichable.
            $out[] = ['gid' => $g->getGID(), 'displayName' => $g->getDisplayName()];
        }
        return new DataResponse($out);
    }

    // Méthode PRIVÉE (utilitaire interne, pas une route) : renvoie l'ensemble des IDs de
    // projets auxquels l'utilisateur courant a droit, sous une forme pratique à tester.
    private function allowedIds(): array {
        $u = $this->userSession->getUser();
        // accessibleProjetIds() renvoie une liste type [3, 7, 12]. array_flip la retourne
        // en [3=>0, 7=>1, 12=>2] : ainsi « isset($allowed[$id]) » teste l'appartenance en O(1)
        // (instantané), au lieu de parcourir toute la liste à chaque vérification.
        return array_flip($u ? $this->access->accessibleProjetIds($u->getUID()) : []);
    }

    #[NoAdminRequired]
    public function projets(): DataResponse {
        $allowed = $this->allowedIds();
        // findAll() = tous les projets en base ; array_filter garde seulement ceux dont l'id
        // est dans $allowed. array_values réindexe le tableau de 0, 1, 2… (JSON propre = un
        // tableau, pas un objet à clés trouées).
        $out = array_values(array_filter($this->projets->findAll(), static fn ($p) => isset($allowed[$p->getId()])));
        return new DataResponse($out);
    }

    #[NoAdminRequired]
    public function activites(int $projet): DataResponse {
        // Garde-fou : si le projet demandé n'est pas autorisé, on renvoie une liste vide
        // (on ne divulgue rien). « return » sort tout de suite de la méthode.
        if (!isset($this->allowedIds()[$projet])) {
            return new DataResponse([]);
        }
        // Sinon on renvoie les activités (= phases) de ce projet.
        return new DataResponse($this->activites->findByProjet($projet));
    }

    #[NoAdminRequired]
    public function taches(int $projet): DataResponse {
        // Même garde-fou que ci-dessus, appliqué aux tâches.
        if (!isset($this->allowedIds()[$projet])) {
            return new DataResponse([]);
        }
        $taches = $this->taches->findByProjet($projet);
        // Prévu EFFECTIF : quand une tâche est affectée, son prévu = le cumul des heures de ses
        // affectations (et non l'estimation manuelle). On remplace donc heuresPrevues par ce cumul
        // pour les tâches affectées ; l'arbre affiche alors le bon total, qui se cumule sur les parents.
        $ids = array_map(static fn ($t) => $t->getId(), $taches);
        $budget = $this->affService->budgetByTaches($ids);
        // Réel « PROPRE » de chaque tâche = heures pointées directement dessus (sans ses sous-tâches).
        // Sert à l'arbre pour ne pas perdre les heures d'une tâche parente pointée avant d'avoir des
        // sous-tâches (les feuilles, elles, ont déjà leur réel via /api/board).
        $reelDirect = [];
        foreach ($this->heures->findByTaches($ids) as $h) {
            $reelDirect[$h->getTacheId()] = ($reelDirect[$h->getTacheId()] ?? 0) + (float)$h->getHeures();
        }
        $out = [];
        foreach ($taches as $t) {
            $row = $t->jsonSerialize();
            if (array_key_exists($t->getId(), $budget)) { $row['heuresPrevues'] = round((float)$budget[$t->getId()], 2); }
            $row['reelDirect'] = round($reelDirect[$t->getId()] ?? 0, 2);
            $out[] = $row;
        }
        return new DataResponse($out);
    }
}
