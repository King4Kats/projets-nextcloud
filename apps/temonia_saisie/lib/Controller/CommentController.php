<?php
// SPDX-FileCopyrightText: 2026 Flavien Auvray
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\TemoniaSaisie\Controller;

use OCA\TemoniaPilotage\Db\CommentaireMapper;
use OCA\TemoniaPilotage\Db\TacheMapper;
use OCA\TemoniaPilotage\Service\AccessService;
use OCA\TemoniaPilotage\Service\HistoService;   // journalise les événements (audit)
use OCA\TemoniaPilotage\Service\NotifyService;  // envoie les notifications (cloche NC)
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Utility\ITimeFactory;       // fournit l'heure courante (injectable = testable)
use OCP\IRequest;
use OCP\IUserManager;
use OCP\IUserSession;

/**
 * Commentaires & blocages sur une tâche, saisis par l'équipe depuis la fiche.
 * Un blocage notifie la direction (cloche Nextcloud).
 */
class CommentController extends Controller {
    private CommentaireMapper $comments;
    private TacheMapper $taches;
    private AccessService $access;
    private NotifyService $notify;
    private IUserManager $userManager;
    private IUserSession $userSession;
    private ITimeFactory $time;
    private HistoService $histo;

    public function __construct(string $appName, IRequest $request, CommentaireMapper $comments, TacheMapper $taches,
        AccessService $access, NotifyService $notify, IUserManager $userManager, IUserSession $userSession, ITimeFactory $time, HistoService $histo) {
        parent::__construct($appName, $request);
        $this->comments = $comments;
        $this->taches = $taches;
        $this->access = $access;
        $this->notify = $notify;
        $this->userManager = $userManager;
        $this->userSession = $userSession;
        $this->time = $time;
        $this->histo = $histo;
    }

    // Identifiant de l'utilisateur connecté ('' si personne).
    private function uid(): string {
        $u = $this->userSession->getUser();
        return $u ? $u->getUID() : '';
    }

    // Vérifie que l'utilisateur a le droit d'accéder à la tâche demandée.
    // Renvoie l'entité Tache si OK, ou null si la tâche n'existe pas / est interdite.
    // Le type de retour « ?\OCA\...\Tache » = « soit une Tache, soit null ».
    private function canAccessTache(int $tacheId): ?\OCA\TemoniaPilotage\Db\Tache {
        try {
            $t = $this->taches->find($tacheId);
        } catch (DoesNotExistException $e) {
            return null; // la tâche n'existe pas
        }
        // La tâche appartient-elle à un projet auquel j'ai accès ?
        $allowed = array_flip($this->access->accessibleProjetIds($this->uid()));
        return isset($allowed[$t->getProjetId()]) ? $t : null;
    }

    // uid → nom affichable (retombe sur l'uid si l'utilisateur n'existe plus).
    private function name(string $uid): string {
        $u = $this->userManager->get($uid);
        return $u ? $u->getDisplayName() : $uid;
    }

    // GET /api/commentaires?tache=… : liste les commentaires/blocages d'une tâche.
    #[NoAdminRequired]
    public function index(int $tache): DataResponse {
        // Pas d'accès → on renvoie une liste vide (on ne révèle rien).
        if (!$this->canAccessTache($tache)) {
            return new DataResponse([]);
        }
        $out = [];
        foreach ($this->comments->findByTache($tache) as $c) {
            $j = $c->jsonSerialize();
            $j['auteur'] = $this->name($c->getUserId());        // nom lisible de l'auteur
            $j['mine'] = $c->getUserId() === $this->uid();       // est-ce MON commentaire ? (pour l'UI)
            $out[] = $j;
        }
        return new DataResponse($out);
    }

    // POST /api/commentaires : publier un commentaire, ou signaler un blocage.
    #[NoAdminRequired]
    public function create(int $tache, ?string $texte = null, ?string $type = 'comment'): DataResponse {
        // Contrôle d'accès d'abord : $t vaut la tâche si autorisé, null sinon.
        $t = $this->canAccessTache($tache);
        if (!$t) {
            return new DataResponse(['message' => 'Accès refusé.'], Http::STATUS_FORBIDDEN);
        }
        // trim() enlève les espaces autour ; un message vide est refusé.
        $texte = trim((string)$texte);
        if ($texte === '') {
            return new DataResponse(['message' => 'Message vide.'], Http::STATUS_BAD_REQUEST);
        }
        // Liste blanche du type : soit 'blocage', soit 'comment' (toute autre valeur → 'comment').
        // On ne fait jamais confiance à une valeur brute venant du client.
        $type = ($type === 'blocage') ? 'blocage' : 'comment';

        // Construction de l'entité commentaire.
        $c = new \OCA\TemoniaPilotage\Db\Commentaire();
        $c->setTacheId($tache);
        $c->setUserId($this->uid());
        $c->setTexte($texte);
        $c->setType($type);
        $c->setResolved(false);                 // un blocage naît « non résolu »
        $c->setCreatedAt($this->time->getTime());
        $c = $this->comments->insert($c);        // écrit en base, récupère l'id

        // Effets de bord SPÉCIFIQUES au blocage : prévenir la direction + journaliser.
        if ($type === 'blocage') {
            $this->notify->blocage($tache, $t->getTitre(), $this->uid(), $this->name($this->uid()), $texte);
            $this->histo->record($t->getProjetId(), 'tache', $tache, $t->getTitre(), $this->uid(), 'blocage', null, $texte);
        }

        // On renvoie le commentaire créé, enrichi de l'auteur et du drapeau « mine ».
        $j = $c->jsonSerialize();
        $j['auteur'] = $this->name($this->uid());
        $j['mine'] = true;
        return new DataResponse($j);
    }

    /** Lever un blocage (direction, ou l'auteur du blocage). */
    #[NoAdminRequired]
    public function resolve(int $id): DataResponse {
        try {
            $c = $this->comments->find($id);
        } catch (DoesNotExistException $e) {
            return new DataResponse(['message' => 'Introuvable.'], Http::STATUS_NOT_FOUND);
        }
        // Il faut d'abord avoir accès à la tâche du commentaire.
        if (!$this->canAccessTache($c->getTacheId())) {
            return new DataResponse(['message' => 'Accès refusé.'], Http::STATUS_FORBIDDEN);
        }
        // Règle métier : seule la direction OU l'auteur du blocage peut le lever.
        // Le « && » = ET : il faut que les DEUX conditions soient vraies pour refuser.
        if (!$this->access->isDirection($this->uid()) && $c->getUserId() !== $this->uid()) {
            return new DataResponse(['message' => 'Seule la direction ou l’auteur peut lever le blocage.'], Http::STATUS_FORBIDDEN);
        }
        // On marque le blocage comme résolu et on enregistre.
        $c->setResolved(true);
        $c = $this->comments->update($c);
        // Journalise l'événement « déblocage ». Enveloppé dans try/catch : si la tâche a
        // disparu entre-temps, l'audit ne doit JAMAIS faire échouer l'action métier.
        try { $t = $this->taches->find($c->getTacheId()); $this->histo->record($t->getProjetId(), 'tache', $t->getId(), $t->getTitre(), $this->uid(), 'deblocage'); } catch (DoesNotExistException $e) {}
        // S'il n'y a plus AUCUN blocage ouvert sur la tâche, on purge les notifications
        // associées (inutile de laisser une cloche rouge pour un blocage déjà levé).
        if (empty($this->comments->tachesBloquees([$c->getTacheId()]))) {
            $this->notify->clearBlocage($c->getTacheId());
        }
        return new DataResponse($c);
    }
}
