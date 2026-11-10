<?php
// SPDX-FileCopyrightText: 2026 Flavien Auvray
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\TemoniaSaisie\Controller;

// Entités (une ligne de table) et mappers (l'accès BD) de l'app compagnon Pilotage.
use OCA\TemoniaPilotage\Db\Activite;
use OCA\TemoniaPilotage\Db\ActiviteMapper;
use OCA\TemoniaPilotage\Db\Affectation;
use OCA\TemoniaPilotage\Db\AffectationMapper;
use OCA\TemoniaPilotage\Db\CommentaireMapper;
use OCA\TemoniaPilotage\Db\HeureMapper;
use OCA\TemoniaPilotage\Db\HistoriqueMapper;
use OCA\TemoniaPilotage\Db\Projet;
use OCA\TemoniaPilotage\Db\ProjetMapper;
use OCA\TemoniaPilotage\Db\Tache;
use OCA\TemoniaPilotage\Db\TacheMapper;
use OCA\TemoniaPilotage\Service\AccessService;
use OCA\TemoniaPilotage\Service\AffectationService;
use OCA\TemoniaPilotage\Service\HistoService;
use OCA\TemoniaPilotage\Service\NotifyService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserManager;
use OCP\IUserSession;

/**
 * Endpoints d'administration (direction) : gestion des projets, phases,
 * activités, tâches, membres, et tableaux de bord d'avancement.
 * Tout est protégé : seule la direction (admin NC ou groupe « direction ») passe.
 */
class ManageController extends Controller {
    private ProjetMapper $projets;
    private ActiviteMapper $activites;
    private TacheMapper $taches;
    private HeureMapper $heures;
    private CommentaireMapper $comments;
    private AffectationMapper $affectations;
    private AffectationService $affService;
    private AccessService $access;
    private IUserSession $userSession;
    private IUserManager $userManager;
    private IGroupManager $groupManager;
    private ITimeFactory $time;
    private HistoService $histo;
    private HistoriqueMapper $historique;
    private IConfig $config;
    private NotifyService $notify;

    // Nextcloud injecte automatiquement chaque dépendance d'après son type (autowiring).
    public function __construct(string $appName, IRequest $request, ProjetMapper $projets, ActiviteMapper $activites, TacheMapper $taches, HeureMapper $heures, CommentaireMapper $comments, AffectationMapper $affectations, AffectationService $affService, AccessService $access, IUserSession $userSession, IUserManager $userManager, IGroupManager $groupManager, ITimeFactory $time, HistoService $histo, HistoriqueMapper $historique, IConfig $config, NotifyService $notify) {
        parent::__construct($appName, $request);
        $this->notify = $notify;
        $this->historique = $historique;
        $this->projets = $projets;
        $this->activites = $activites;
        $this->taches = $taches;
        $this->heures = $heures;
        $this->comments = $comments;
        $this->affectations = $affectations;
        $this->affService = $affService;
        $this->access = $access;
        $this->userSession = $userSession;
        $this->userManager = $userManager;
        $this->groupManager = $groupManager;
        $this->time = $time;
        $this->histo = $histo;
        $this->config = $config;
    }

    // Libellés lisibles des statuts, pour écrire l'historique en français ("À faire" plutôt que "a_faire").
    // Sert AUSSI de liste blanche : seules ces 3 clés de statut de tâche sont acceptées.
    private const ST_LABEL = ['a_faire' => 'À faire', 'en_cours' => 'En cours', 'fait' => 'Fait'];
    // Liste blanche des statuts de projet (garde-fou contre une valeur arbitraire).
    private const PROJ_STATUTS = ['actif', 'pause', 'clos'];

    // --- Petits utilitaires internes (écrits en une ligne pour rester compacts) ---
    // uid → nom affichable (retombe sur l'uid, puis sur "—", si l'utilisateur n'existe plus).
    private function nameOf(string $uid): string { $u = $uid ? $this->userManager->get($uid) : null; return $u ? $u->getDisplayName() : ($uid ?: '—'); }
    // Identifiant de l'utilisateur connecté (chaîne vide si session absente).
    private function uid(): string { $u = $this->userSession->getUser(); return $u ? $u->getUID() : ''; }
    // Est-ce un membre de la direction (admin NC ou groupe « direction ») ?
    private function mgr(): bool { return $this->access->isDirection($this->uid()); }
    // L'utilisateur a-t-il accès à ce projet (direction, ou membre du projet) ?
    private function canProj(int $projetId): bool { return $this->access->canAccess($this->uid(), $projetId); }
    // Réponses d'erreur standard (403). forbid = action réservée direction ; denyAccess = projet non autorisé.
    private function forbid(): DataResponse { return new DataResponse(['message' => 'Réservé à la direction.'], Http::STATUS_FORBIDDEN); }
    private function denyAccess(): DataResponse { return new DataResponse(['message' => 'Accès refusé à ce projet.'], Http::STATUS_FORBIDDEN); }

    /**
     * Supprime les données rattachées à une tâche (saisies d'heures + commentaires/blocages)
     * avant de supprimer la tâche elle-même, pour ne pas laisser de lignes orphelines en base.
     * L'historique (tp_historique) n'est PAS purgé : c'est un journal d'audit qui doit survivre.
     */
    private function purgeTache(int $tacheId): void {
        foreach ($this->heures->findByTache($tacheId) as $h) { $this->heures->delete($h); }
        foreach ($this->comments->findByTache($tacheId) as $c) { $this->comments->delete($c); }
        $this->affectations->deleteByTache($tacheId); // affectations (personnes/groupes) de la tâche
    }

    // ---------- PROJETS ----------
    // GET /api/manage/projets : la liste complète (réservée direction).
    #[NoAdminRequired]
    public function projets(): DataResponse {
        if (!$this->mgr()) { return $this->forbid(); } // barrière : direction uniquement
        return new DataResponse($this->projets->findAll());
    }

    // POST /api/manage/projets : créer un projet.
    #[NoAdminRequired]
    public function projetCreate(?string $nom = null, ?string $couleur = null, ?string $statut = null, ?string $description = null): DataResponse {
        if (!$this->mgr()) { return $this->forbid(); }
        if (!trim((string)$nom)) { return new DataResponse(['message' => 'Nom requis.'], Http::STATUS_BAD_REQUEST); }
        // On construit l'entité et on remplit ses champs (avec des valeurs par défaut sûres).
        $p = new Projet();
        $p->setNom(trim($nom));
        $p->setCouleur($couleur ?: '#C4362A');   // couleur par défaut si non fournie (rouge l'association)
        // On n'accepte le statut que s'il est dans la liste blanche ; sinon 'actif' par défaut.
        $p->setStatut(in_array($statut, self::PROJ_STATUTS, true) ? $statut : 'actif');
        $p->setDescription($description);
        $p->setOrdre(0);
        $p->setCreatedAt($this->time->getTime());
        $p->setMembres([]);                        // aucun membre au départ (projet invisible tant qu'on n'en ajoute pas)
        $p = $this->projets->insert($p);           // écrit en base + récupère l'id
        // Journalise la création dans l'historique du projet.
        $this->histo->record($p->getId(), 'projet', $p->getId(), $p->getNom(), $this->uid(), 'creation');
        return new DataResponse($p);
    }

    // PUT /api/manage/projets/{id} : modifier un projet (chaque champ est optionnel).
    #[NoAdminRequired]
    public function projetUpdate(int $id, ?string $nom = null, ?string $couleur = null, ?string $statut = null, ?string $description = null, ?array $membres = null): DataResponse {
        if (!$this->mgr()) { return $this->forbid(); }
        try { $p = $this->projets->find($id); } catch (DoesNotExistException $e) { return new DataResponse(['message' => 'Introuvable.'], Http::STATUS_NOT_FOUND); }
        // On ne modifie QUE les champs réellement transmis (null = « non envoyé, ne pas toucher »).
        if ($nom !== null) { $p->setNom($nom); }
        if ($couleur !== null) { $p->setCouleur($couleur); }
        if ($statut !== null && in_array($statut, self::PROJ_STATUTS, true)) { $p->setStatut($statut); }
        if ($description !== null) { $p->setDescription($description); }
        if ($membres !== null) { $p->setMembres($membres); }
        $p = $this->projets->update($p);
        // Événement « membres » si on a touché aux accès, sinon « edition » (simple modification).
        $this->histo->record($p->getId(), 'projet', $p->getId(), $p->getNom(), $this->uid(), $membres !== null ? 'membres' : 'edition');
        return new DataResponse($p);
    }

    // DELETE /api/manage/projets/{id} : supprimer un projet ET tout son contenu.
    #[NoAdminRequired]
    public function projetDestroy(int $id): DataResponse {
        if (!$this->mgr()) { return $this->forbid(); }
        try { $p = $this->projets->find($id); } catch (DoesNotExistException $e) { return new DataResponse([], Http::STATUS_NOT_FOUND); }
        // On journalise AVANT de supprimer (après, on n'aurait plus le nom du projet).
        $this->histo->record($p->getId(), 'projet', $p->getId(), $p->getNom(), $this->uid(), 'suppression');
        // Suppression en cascade : tâches (avec leurs heures + commentaires), puis phases, puis le projet.
        foreach ($this->taches->findByProjet($id) as $t) { $this->purgeTache($t->getId()); $this->taches->delete($t); }
        foreach ($this->activites->findByProjet($id) as $a) { $this->activites->delete($a); }
        $this->projets->delete($p);
        return new DataResponse([]);
    }

    // ---------- PHASES (activités) ----------
    // POST /api/manage/activites : créer une phase dans un projet.
    #[NoAdminRequired]
    public function activiteCreate(int $projet, ?string $nom = null, ?float $seuilHeures = null): DataResponse {
        if (!$this->mgr()) { return $this->forbid(); }
        if (!trim((string)$nom)) { return new DataResponse(['message' => 'Nom requis.'], Http::STATUS_BAD_REQUEST); }
        $a = new Activite();
        $a->setProjetId($projet);
        $a->setNom(trim($nom));
        $a->setSeuilHeures($seuilHeures); // seuil purement informatif (repère visuel)
        $a->setOrdre(0);
        $a = $this->activites->insert($a);
        $this->histo->record($a->getProjetId(), 'phase', $a->getId(), $a->getNom(), $this->uid(), 'creation');
        return new DataResponse($a);
    }

    // PUT /api/manage/activites/{id} : modifier une phase.
    #[NoAdminRequired]
    public function activiteUpdate(int $id, ?string $nom = null, ?float $seuilHeures = null): DataResponse {
        if (!$this->mgr()) { return $this->forbid(); }
        try { $a = $this->activites->find($id); } catch (DoesNotExistException $e) { return new DataResponse([], Http::STATUS_NOT_FOUND); }
        if ($nom !== null) { $a->setNom($nom); }
        $a->setSeuilHeures($seuilHeures); // ici on écrit toujours le seuil (null = on l'efface)
        $a = $this->activites->update($a);
        $this->histo->record($a->getProjetId(), 'phase', $a->getId(), $a->getNom(), $this->uid(), 'edition');
        return new DataResponse($a);
    }

    // DELETE /api/manage/activites/{id} : supprimer une phase (sans supprimer ses tâches).
    #[NoAdminRequired]
    public function activiteDestroy(int $id): DataResponse {
        if (!$this->mgr()) { return $this->forbid(); }
        try { $a = $this->activites->find($id); } catch (DoesNotExistException $e) { return new DataResponse([], Http::STATUS_NOT_FOUND); }
        // Ses tâches racines deviennent « sans phase » (activiteId = null) au lieu d'être perdues.
        foreach ($this->taches->findByProjet($a->getProjetId()) as $t) {
            if ($t->getActiviteId() === $id && !$t->getParentId()) { $t->setActiviteId(null); $this->taches->update($t); }
        }
        $this->histo->record($a->getProjetId(), 'phase', $a->getId(), $a->getNom(), $this->uid(), 'suppression');
        $this->activites->delete($a);
        return new DataResponse([]);
    }

    // ---------- TÂCHES ----------
    // POST /api/manage/taches : créer une tâche (ou sous-tâche si « parent » fourni).
    #[NoAdminRequired]
    public function tacheCreate(int $projet, ?int $activite = null, ?int $parent = null, ?string $titre = null, ?string $assignee = null, ?float $heuresPrevues = null, ?string $echeance = null, ?string $dateDebut = null): DataResponse {
        // Ici on autorise tout MEMBRE du projet (pas seulement la direction) à créer une tâche.
        if (!$this->canProj($projet)) { return $this->denyAccess(); }
        if (!trim((string)$titre)) { return new DataResponse(['message' => 'Titre requis.'], Http::STATUS_BAD_REQUEST); }
        // Si c'est une sous-tâche, elle hérite de la phase (activiteId) de son parent.
        if ($parent) {
            try { $pt = $this->taches->find($parent); $activite = $pt->getActiviteId(); } catch (DoesNotExistException $e) {}
        }
        $t = new Tache();
        $t->setProjetId($projet);
        $t->setActiviteId($activite ?: null); // « ?: null » : 0/'' deviennent null
        $t->setParentId($parent ?: null);
        $t->setTitre(trim($titre));
        // Assigné (référent) : conservé pour TOUT LE MONDE à la création.
        //  - créé par la direction  → assignation officielle ;
        //  - créé par un MEMBRE      → simple RECOMMANDATION (la tâche naît « à valider »/grisée,
        //    donc rien n'est officiel tant que la direction n'a pas validé). Les membres ne peuvent
        //    toujours PAS réassigner une tâche existante (tacheUpdate reste réservé à la direction).
        $t->setAssignee($assignee ?: null);
        $t->setHeuresPrevues($heuresPrevues);
        $t->setDateDebut($dateDebut ?: null);  // date de début (planneur), facultative
        $t->setEcheance($echeance ?: null);
        $t->setStatut('a_faire');              // toute nouvelle tâche démarre « à faire »
        $t->setOrdre(0);
        $t->setCreatedAt($this->time->getTime());
        // VALIDATION : une tâche créée par la direction est validée d'office ; créée par un MEMBRE
        // (bénévole/salarié/partenaire), elle naît « à valider » (visible mais grisée) jusqu'à ce que
        // la direction la valide depuis l'onglet Administration › À valider.
        $t->setValide($this->access->isDirection($this->uid()));
        $t = $this->taches->insert($t);
        $this->histo->record($t->getProjetId(), 'tache', $t->getId(), $t->getTitre(), $this->uid(), 'creation');
        // Proposition d'un membre (non validée) → cloche à la direction, EN PLUS du badge « à valider ».
        if (!$t->getValide()) {
            $this->notify->proposition($t->getId(), $t->getTitre(), $this->uid(), $this->nameOf($this->uid()));
        }
        return new DataResponse($t);
    }

    // PUT /api/manage/taches/{id} : modifier une tâche (titre, assigné, statut, échéance…).
    #[NoAdminRequired]
    public function tacheUpdate(int $id, ?string $titre = null, ?string $assignee = null, ?float $heuresPrevues = null, ?string $echeance = null, ?string $statut = null, ?int $activite = null, ?string $dateDebut = null): DataResponse {
        try { $t = $this->taches->find($id); } catch (DoesNotExistException $e) { return new DataResponse([], Http::STATUS_NOT_FOUND); }
        // ÉDITION réservée à la direction : les membres ne modifient pas une tâche (titre/assigné/
        // échéance/estimation). Ils gardent le statut (via /api/board) + heures + commentaires.
        if (!$this->mgr()) { return $this->forbid(); }
        // Sécurité d'intégrité : on ne peut passer une tâche « fait » que si TOUTES ses sous-tâches
        // directes sont déjà « fait » (donc, par transitivité, toute la descendance).
        if ($statut === 'fait' && !$this->sousTachesToutesFaites($id, $t->getProjetId())) {
            return new DataResponse(['message' => 'Terminez d’abord toutes les sous-tâches.'], Http::STATUS_BAD_REQUEST);
        }
        // On mémorise l'ancien statut / assigné AVANT modification, pour savoir quoi journaliser ensuite.
        $oldStatut = $t->getStatut();
        $oldAssignee = $t->getAssignee();
        // Seul la direction peut assigner : on calcule le droit une fois et on s'en sert
        // pour ignorer toute assignation envoyée par un simple membre (sécurité côté serveur).
        $canAssign = $this->access->peutAssigner($uid = $this->uid());
        if ($titre !== null) { $t->setTitre($titre); }
        if ($assignee !== null && $canAssign) { $t->setAssignee($assignee ?: null); }
        // heuresPrevues : on distingue « champ absent de la requête » (on ne touche pas) de « champ envoyé à null »
        // (on efface l'estimation). getParam(..., 'x') renvoie la sentinelle 'x' seulement si la clé est absente ;
        // donc « === null » ci-dessous ne peut être vrai que si le client a explicitement envoyé heuresPrevues=null.
        if ($heuresPrevues !== null || $this->request->getParam('heuresPrevues', 'x') === null) { $t->setHeuresPrevues($heuresPrevues); }
        if ($echeance !== null) { $t->setEcheance($echeance ?: null); }
        if ($dateDebut !== null) { $t->setDateDebut($dateDebut ?: null); } // '' → efface la date de début
        if ($statut !== null && isset(self::ST_LABEL[$statut])) { $t->setStatut($statut); } // liste blanche des statuts
        // Idem pour l'activité : 'keep' = clé absente → on garde la valeur actuelle.
        if ($this->request->getParam('activite', 'keep') !== 'keep') { $t->setActiviteId($activite ?: null); }
        $t = $this->taches->update($t);
        $uid = $this->uid();
        // Un seul événement d'historique par requête, par ordre de priorité : statut > assignation > édition simple.
        if ($statut !== null && isset(self::ST_LABEL[$statut]) && $statut !== $oldStatut) {
            $this->histo->record($t->getProjetId(), 'tache', $t->getId(), $t->getTitre(), $uid, 'statut', self::ST_LABEL[$oldStatut] ?? $oldStatut, self::ST_LABEL[$statut] ?? $statut);
        } elseif ($assignee !== null && $canAssign && ($assignee ?: null) !== $oldAssignee) {
            // Assigné = personne OU groupe : principalName() résout les deux (— si vide).
            // On ne journalise l'assignation que si elle a réellement eu lieu (donc si $canAssign).
            $this->histo->record($t->getProjetId(), 'tache', $t->getId(), $t->getTitre(), $uid, 'assignation', $this->access->principalName($oldAssignee) ?? '—', $this->access->principalName($assignee) ?? '—');
        } elseif ($titre !== null || $echeance !== null || $this->request->getParam('heuresPrevues', 'x') !== 'x') {
            $this->histo->record($t->getProjetId(), 'tache', $t->getId(), $t->getTitre(), $uid, 'edition');
        }
        return new DataResponse($t);
    }

    // DELETE /api/manage/taches/{id} : supprimer une tâche et toute sa descendance.
    #[NoAdminRequired]
    public function tacheDestroy(int $id): DataResponse {
        try { $t = $this->taches->find($id); } catch (DoesNotExistException $e) { return new DataResponse([], Http::STATUS_NOT_FOUND); }
        // Suppression réservée à la direction (les membres ne suppriment pas de tâche).
        if (!$this->mgr()) { return $this->forbid(); }
        $this->histo->record($t->getProjetId(), 'tache', $t->getId(), $t->getTitre(), $this->uid(), 'suppression');
        $this->deleteRec($t->getId(), $t->getProjetId());
        return new DataResponse([]);
    }

    // Vrai si TOUTES les sous-tâches directes de $id sont au statut « fait » (ou s'il n'y en a pas).
    // Sert de garde-fou avant de marquer une tâche parente « fait ».
    private function sousTachesToutesFaites(int $id, int $projetId): bool {
        foreach ($this->taches->findByProjet($projetId) as $c) {
            if ($c->getParentId() === $id && $c->getStatut() !== 'fait') { return false; }
        }
        return true;
    }
    /** Supprime une tâche et TOUTE sa descendance (sous-tâches en profondeur), heures et commentaires compris. */
    private function deleteRec(int $id, int $projetId): void {
        // RÉCURSION : la fonction s'appelle elle-même sur chaque enfant. On descend d'abord dans
        // les enfants pour supprimer les feuilles AVANT les parents (sinon on laisserait des orphelins).
        foreach ($this->taches->findByProjet($projetId) as $t) { if ($t->getParentId() === $id) { $this->deleteRec($t->getId(), $projetId); } }
        $this->purgeTache($id); // supprime heures + commentaires de cette tâche
        try { $this->taches->delete($this->taches->find($id)); } catch (DoesNotExistException $e) {}
    }

    // ---------- VALIDATION DES PROPOSITIONS (la direction / admin) ----------
    // Ces trois routes alimentent le sous-onglet « À valider » de l'écran Administration.
    // Elles sont réservées à l'ADMIN Nextcloud (direction), pas seulement à la direction.
    private function adminOnly(): bool { return $this->access->isAdmin($this->uid()); }
    private function forbidAdmin(): DataResponse { return new DataResponse(['message' => 'Réservé à l’administration.'], Http::STATUS_FORBIDDEN); }

    // GET /api/manage/pending : toutes les tâches proposées par des membres, en attente de validation.
    #[NoAdminRequired]
    public function pending(): DataResponse {
        if (!$this->adminOnly()) { return $this->forbidAdmin(); }
        $out = [];
        foreach ($this->projets->findAll() as $p) {
            $tasks = $this->taches->findByProjet($p->getId());
            // Index id → tâche du projet, pour retrouver le titre de la tâche parente.
            $byId = [];
            foreach ($tasks as $t) { $byId[$t->getId()] = $t; }
            foreach ($tasks as $t) {
                if ($t->getValide()) { continue; } // on ne garde que les propositions non validées
                $parentTitre = ($t->getParentId() && isset($byId[$t->getParentId()])) ? $byId[$t->getParentId()]->getTitre() : null;
                $out[] = [
                    'id' => $t->getId(), 'titre' => $t->getTitre(),
                    'projetNom' => $p->getNom(), 'couleur' => $p->getCouleur(),
                    'parentTitre' => $parentTitre,
                    // Recommandations du membre (référent + dates), pour que la direction les voie avant de valider.
                    'assigneeName' => $this->access->principalName($t->getAssignee()),
                    'dateDebut' => $t->getDateDebut(),
                    'echeance' => $t->getEcheance(), 'heuresPrevues' => $t->getHeuresPrevues(),
                    'createdAt' => $t->getCreatedAt(),
                ];
            }
        }
        return new DataResponse($out);
    }

    // POST /api/manage/taches/{id}/valider : la direction valide une proposition → elle devient normale.
    #[NoAdminRequired]
    public function tacheValider(int $id): DataResponse {
        if (!$this->adminOnly()) { return $this->forbidAdmin(); }
        try { $t = $this->taches->find($id); } catch (DoesNotExistException $e) { return new DataResponse([], Http::STATUS_NOT_FOUND); }
        $t->setValide(true);
        $t = $this->taches->update($t);
        $this->histo->record($t->getProjetId(), 'tache', $t->getId(), $t->getTitre(), $this->uid(), 'validation');
        $this->notify->clearProposition($id); // la cloche « à valider » n'a plus lieu d'être
        return new DataResponse($t);
    }

    // POST /api/manage/taches/{id}/rejeter : la direction refuse une proposition → la tâche est supprimée
    // (en cascade avec ses éventuelles sous-tâches/heures/commentaires).
    #[NoAdminRequired]
    public function tacheRejeter(int $id): DataResponse {
        if (!$this->adminOnly()) { return $this->forbidAdmin(); }
        try { $t = $this->taches->find($id); } catch (DoesNotExistException $e) { return new DataResponse([], Http::STATUS_NOT_FOUND); }
        $this->histo->record($t->getProjetId(), 'tache', $t->getId(), $t->getTitre(), $this->uid(), 'rejet');
        $this->notify->clearProposition($id); // purge la cloche « à valider » avant suppression
        $this->deleteRec($t->getId(), $t->getProjetId());
        return new DataResponse([]);
    }

    // ---------- AFFECTATIONS (plusieurs personnes/groupes par tâche) ----------
    // Une tâche peut être affectée à PLUSIEURS personnes/groupes, chacun avec ses heures prévues.
    // Seule la direction (peutAssigner = direction) crée/modifie/retire une affectation.
    // Tous les calculs (réel cumulé sur le sous-arbre, dépassement…) sont dans AffectationService,
    // pour ne pas dupliquer cette logique ici, dans BoardController et dans AdminController.

    // GET /api/manage/taches/{id}/affectations : la liste des affectés d'une tâche, avec prévu/réel/dépassement.
    #[NoAdminRequired]
    public function affectations(int $id): DataResponse {
        if (!$this->mgr()) { return $this->forbid(); }
        try { $t = $this->taches->find($id); } catch (DoesNotExistException $e) { return new DataResponse([], Http::STATUS_NOT_FOUND); }
        return new DataResponse($this->affService->rows($id, $t->getProjetId()));
    }

    /**
     * POST /api/manage/taches/{id}/affectations : affecte (ou met à jour le budget d')
     * une personne/un groupe sur la tâche. « Upsert » : si le principal est déjà affecté,
     * on met simplement à jour ses heures prévues (pas de doublon).
     */
    #[NoAdminRequired]
    public function affectationSave(int $id, ?string $principal = null, ?float $heuresPrevues = null): DataResponse {
        // Assignation RÉSERVÉE à la direction. Un simple membre ne peut pas affecter.
        if (!$this->access->peutAssigner($this->uid())) { return $this->forbid(); }
        try { $t = $this->taches->find($id); } catch (DoesNotExistException $e) { return new DataResponse([], Http::STATUS_NOT_FOUND); }
        $principal = trim((string)$principal);
        if ($principal === '' || !$this->affService->principalValide($principal)) {
            return new DataResponse(['message' => 'Personne ou groupe invalide.'], Http::STATUS_BAD_REQUEST);
        }
        // Upsert : on cherche une affectation existante pour ce couple (tâche, principal).
        $a = $this->affectations->findByTacheEtPrincipal($id, $principal);
        $estNouveau = ($a === null);
        if ($estNouveau) {
            $a = new Affectation();
            $a->setTacheId($id);
            $a->setPrincipal($principal);
            $a->setCreatedAt($this->time->getTime());
        }
        $a->setHeuresPrevues($heuresPrevues); // null = affecté sans budget chiffré (pas de dépassement calculable)
        $a = $estNouveau ? $this->affectations->insert($a) : $this->affectations->update($a);
        // Audit : on note l'affectation (nom + budget) dans l'historique de la tâche.
        $libelle = $this->access->principalName($principal) . ($heuresPrevues !== null ? ' (' . $heuresPrevues . ' h)' : '');
        $this->histo->record($t->getProjetId(), 'tache', $t->getId(), $t->getTitre(), $this->uid(), 'affectation', null, $libelle);
        // On renvoie la ligne enrichie (réel cumulé/dépassement) pour rafraîchir l'UI sans recharger.
        return new DataResponse($this->affService->rowForAffectation($a, $t->getProjetId()));
    }

    // DELETE /api/manage/affectations/{id} : retire une personne/un groupe de la tâche.
    #[NoAdminRequired]
    public function affectationDelete(int $id): DataResponse {
        if (!$this->access->peutAssigner($this->uid())) { return $this->forbid(); }
        try { $a = $this->affectations->find($id); } catch (DoesNotExistException $e) { return new DataResponse([], Http::STATUS_NOT_FOUND); }
        // Historique : on journalise le retrait avant de supprimer (sinon on perdrait le nom).
        try {
            $t = $this->taches->find($a->getTacheId());
            $this->histo->record($t->getProjetId(), 'tache', $t->getId(), $t->getTitre(), $this->uid(), 'affectation', $this->access->principalName($a->getPrincipal()), '—');
        } catch (DoesNotExistException $e) { /* tâche déjà disparue : on supprime quand même l'affectation */ }
        $this->affectations->delete($a);
        return new DataResponse([]);
    }

    // ---------- MÉTA ----------
    // GET /api/manage/principals : la liste des personnes ET groupes assignables (pour les menus).
    #[NoAdminRequired]
    public function principals(): DataResponse {
        if (!$this->mgr()) { return $this->forbid(); }
        $users = [];
        $this->userManager->callForAllUsers(static function ($u) use (&$users) { $users[] = ['uid' => $u->getUID(), 'displayName' => $u->getDisplayName()]; });
        $groups = [];
        foreach ($this->groupManager->search('') as $g) { $groups[] = ['gid' => $g->getGID(), 'displayName' => $g->getDisplayName()]; }
        return new DataResponse(['users' => $users, 'groups' => $groups]);
    }

    // ---------- AVANCEMENT ----------
    // Utilitaire : réel (heures cumulées) par tâche, pour une liste d'ids donnée.
    private function reelByTache(array $tacheIds): array {
        $by = [];
        foreach ($this->heures->findByTaches($tacheIds) as $h) { $by[$h->getTacheId()] = ($by[$h->getTacheId()] ?? 0) + (float)$h->getHeures(); }
        return $by;
    }

    // GET /api/manage/avancement/projet : détail d'un projet, phase par phase et tâche par tâche.
    #[NoAdminRequired]
    public function avProjet(int $projet): DataResponse {
        if (!$this->canProj($projet)) { return $this->denyAccess(); }
        $taches = $this->taches->findByProjet($projet);
        // On prépare deux index : id → tâche, et parentId → [enfants]. « ?: 0 » range les racines sous la clé 0.
        $byId = []; $childrenOf = [];
        foreach ($taches as $t) { $byId[$t->getId()] = $t; $childrenOf[$t->getParentId() ?: 0][] = $t; }
        $ids = array_map(static fn ($t) => $t->getId(), $taches);
        $reelBy = $this->reelByTache($ids);
        // Prévu EFFECTIF par feuille : cumul des affectations si la tâche en a, sinon estimation manuelle.
        $budget = $this->affService->budgetByTaches($ids);
        // FONCTION RÉCURSIVE (closure) : prévu d'une tâche = somme des prévus de ses enfants, ou son
        // prévu effectif si c'est une feuille. « use (&$prevuOf…) » permet à la fonction de s'appeler elle-même.
        $prevuOf = function ($id) use (&$prevuOf, $byId, $childrenOf, $budget) {
            $ch = $childrenOf[$id] ?? [];
            if ($ch) { $s = 0; foreach ($ch as $c) { $s += $prevuOf($c->getId()); } return $s; }
            return $this->affService->prevuEffectifFeuille($id, $byId[$id]->getHeuresPrevues(), $budget);
        };
        // Même principe pour le réel (heures saisies), cumulé récursivement.
        $reelOf = function ($id) use (&$reelOf, $childrenOf, $reelBy) {
            $ch = $childrenOf[$id] ?? [];
            if ($ch) { $s = 0; foreach ($ch as $c) { $s += $reelOf($c->getId()); } return $s; }
            return (float)($reelBy[$id] ?? 0);
        };
        // Répartition des heures par équipe, à partir de l'appartenance de l'auteur aux
        // groupes Nextcloud. On passe par AccessService::teamOf, qui est AUSSI ce qu'utilise
        // l'écran d'administration : une seule règle, donc un seul résultat possible.
        // Les groupes sont configurables (voir AccessService::teamGroups) :
        //   group_equipe_benevole / group_equipe_salarie / group_equipe_partenaire
        // Avant, cet écran lisait deux clés plus anciennes (group_equipe_temonia / _fede) :
        // deux réglages pour la même question, donc deux répartitions différentes possibles.
        // $cacheEquipe mémorise la catégorie par personne : teamOf interroge les groupes NC,
        // inutile de le refaire pour chacune des lignes d'heures du même auteur.
        $cacheEquipe = [];
        $split = ['benevole' => 0, 'salarie' => 0, 'pompier' => 0, 'autre' => 0];
        foreach ($this->heures->findByTaches($ids) as $h) {
            $uid = $h->getUserId();
            if (!isset($cacheEquipe[$uid])) { $cacheEquipe[$uid] = $this->access->teamOf($uid); }
            $split[$cacheEquipe[$uid]] += (float)$h->getHeures();
        }

        // Pour chaque phase : ses tâches racines, avec prévu/réel calculés récursivement.
        $phases = [];
        foreach ($this->activites->findByProjet($projet) as $a) {
            $roots = array_filter($taches, static fn ($t) => $t->getActiviteId() === $a->getId() && !$t->getParentId());
            $pv = 0; $re = 0;
            $tRows = [];
            foreach ($roots as $t) { $pv += $prevuOf($t->getId()); $re += $reelOf($t->getId()); $tRows[] = ['titre' => $t->getTitre(), 'prevu' => round($prevuOf($t->getId()), 2), 'reel' => round($reelOf($t->getId()), 2)]; }
            $phases[] = ['nom' => $a->getNom(), 'seuil' => $a->getSeuilHeures(), 'prevu' => round($pv, 2), 'reel' => round($re, 2), 'taches' => $tRows];
        }
        // Totaux du projet = somme des totaux de phases.
        $totPrevu = array_sum(array_map(static fn ($p) => $p['prevu'], $phases));
        $totReel = array_sum(array_map(static fn ($p) => $p['reel'], $phases));
        return new DataResponse([
            // « Équipe » = bénévoles + salariés, exactement comme sur le tableau
            // de bord d'administration ; les partenaires sont comptés à part.
            'totals' => [
                'prevu' => round($totPrevu, 2), 'reel' => round($totReel, 2),
                'temonia' => round($split['benevole'] + $split['salarie'], 2),
                'benevole' => round($split['benevole'], 2),
                'salarie' => round($split['salarie'], 2),
                'pompier' => round($split['pompier'], 2),
                'autre' => round($split['autre'], 2),
            ],
            'activites' => $phases,
        ]);
    }

    // ---------- HISTORIQUE ----------
    // GET /api/manage/historique/projet : les 300 derniers événements d'audit d'un projet.
    #[NoAdminRequired]
    public function histoProjet(int $projet): DataResponse {
        if (!$this->canProj($projet)) { return $this->denyAccess(); }
        $out = [];
        foreach ($this->historique->findByProjet($projet, 300) as $h) {
            $out[] = [
                'elementType' => $h->getElementType(),
                'elementTitre' => $h->getElementTitre(),
                'action' => $h->getAction(),
                'avant' => $h->getAvant(),
                'apres' => $h->getApres(),
                'auteur' => $this->nameOf($h->getUserId()),
                'createdAt' => $h->getCreatedAt(),
            ];
        }
        return new DataResponse($out);
    }
}
