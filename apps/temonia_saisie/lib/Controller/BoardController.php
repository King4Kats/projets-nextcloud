<?php
// SPDX-FileCopyrightText: 2026 Flavien Auvray
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\TemoniaSaisie\Controller;

// Réutilise la couche données de Pilotage (app compagnon).
use OCA\TemoniaPilotage\Db\ActiviteMapper;
use OCA\TemoniaPilotage\Db\AffectationMapper;
use OCA\TemoniaPilotage\Db\CommentaireMapper;
use OCA\TemoniaPilotage\Db\HeureMapper;
use OCA\TemoniaPilotage\Db\HistoriqueMapper;
use OCA\TemoniaPilotage\Db\ProjetMapper;
use OCA\TemoniaPilotage\Db\TacheMapper;
use OCA\TemoniaPilotage\Service\AccessService;
use OCA\TemoniaPilotage\Service\AffectationService;
use OCA\TemoniaPilotage\Service\HistoService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;          // pour retrouver un fichier/dossier du cloud (liens de la fiche)
use OCP\Files\NotFoundException;
use OCP\IRequest;
use OCP\IURLGenerator;              // pour fabriquer l'adresse « ouvrir dans Fichiers »
use OCP\IUserManager;
use OCP\IUserSession;

/**
 * Le board d'équipe : les tâches ACTIONNABLES (feuilles = tâches sans
 * sous-tâches, et les sous-tâches) réparties par statut. Tout le monde peut
 * déplacer une tâche (changer son statut).
 */
class BoardController extends Controller {
    // Liste blanche des statuts autorisés. « const » = valeur fixe, non modifiable.
    // Sert de garde-fou : on n'acceptera jamais un statut hors de cette liste.
    private const STATUTS = ['a_faire', 'en_cours', 'fait'];

    private ProjetMapper $projets;
    private ActiviteMapper $activites;
    private TacheMapper $taches;
    private HeureMapper $heures;
    private AffectationMapper $affectations;
    private AffectationService $affService;
    private IUserManager $userManager;
    private AccessService $access;
    private IUserSession $userSession;
    private CommentaireMapper $comments;
    private HistoService $histo;
    private HistoriqueMapper $historique;

    public function __construct(string $appName, IRequest $request, ProjetMapper $projets, ActiviteMapper $activites, TacheMapper $taches, HeureMapper $heures, AffectationMapper $affectations, AffectationService $affService, IUserManager $userManager, AccessService $access, IUserSession $userSession, CommentaireMapper $comments, HistoService $histo, HistoriqueMapper $historique, IRootFolder $rootFolder, IURLGenerator $url) {
        parent::__construct($appName, $request);
        $this->rootFolder = $rootFolder;
        $this->url = $url;
        $this->projets = $projets;
        $this->activites = $activites;
        $this->taches = $taches;
        $this->heures = $heures;
        $this->affectations = $affectations;
        $this->affService = $affService;
        $this->userManager = $userManager;
        $this->access = $access;
        $this->userSession = $userSession;
        $this->comments = $comments;
        $this->histo = $histo;
        $this->historique = $historique;
    }
    private IRootFolder $rootFolder;
    private IURLGenerator $url;

    // Retrouve une tâche ET vérifie que l'utilisateur a accès à son projet. Renvoie l'entité,
    // ou directement la réponse d'erreur (404 / 403) à retourner telle quelle.
    private function tacheAccessible(int $id) {
        try {
            $t = $this->taches->find($id);
        } catch (DoesNotExistException $e) {
            return new DataResponse(['message' => 'Introuvable.'], Http::STATUS_NOT_FOUND);
        }
        $u = $this->userSession->getUser();
        $allowed = array_flip($u ? $this->access->accessibleProjetIds($u->getUID()) : []);
        if (!isset($allowed[$t->getProjetId()])) {
            return new DataResponse(['message' => 'Accès refusé à ce projet.'], Http::STATUS_FORBIDDEN);
        }
        return $t;
    }

    // POST /api/board/{id}/liens : attache un lien à la fiche — soit une ADRESSE WEB (type 'url'),
    // soit un FICHIER ou DOSSIER du cloud (chemin renvoyé par le sélecteur de fichiers Nextcloud).
    // Pour un fichier, on mémorise son identifiant et une adresse « ouvrir dans Fichiers » qui
    // marche pour toute personne ayant accès au fichier (partage).
    #[NoAdminRequired]
    public function lienAdd(int $id, ?string $type = null, ?string $url = null, ?string $path = null, ?string $label = null): DataResponse {
        $t = $this->tacheAccessible($id);
        if ($t instanceof DataResponse) {
            return $t;
        }
        $liens = is_array($t->getLiens()) ? $t->getLiens() : [];
        if (count($liens) >= 20) {
            return new DataResponse(['message' => '20 liens maximum par tâche.'], Http::STATUS_BAD_REQUEST);
        }
        $label = mb_substr(trim((string)$label), 0, 120);
        $uid = $this->userSession->getUser() ? $this->userSession->getUser()->getUID() : '';
        if ($type === 'url') {
            $url = trim((string)$url);
            if (!preg_match('#^https?://\S+$#i', $url)) {
                return new DataResponse(['message' => 'Adresse invalide : elle doit commencer par http:// ou https://.'], Http::STATUS_BAD_REQUEST);
            }
            $lien = ['type' => 'url', 'label' => $label !== '' ? $label : mb_substr(preg_replace('#^https?://#i', '', $url), 0, 80), 'url' => $url];
        } else {
            // Chemin relatif aux Fichiers de l'utilisateur (ex. "/Documents/Rapport.pdf").
            $path = '/' . ltrim(str_replace('\\', '/', (string)$path), '/');
            try {
                $node = $this->rootFolder->getUserFolder($uid)->get($path);
            } catch (NotFoundException $e) {
                return new DataResponse(['message' => 'Fichier ou dossier introuvable.'], Http::STATUS_NOT_FOUND);
            }
            $lien = [
                'type' => $node instanceof Folder ? 'dossier' : 'fichier',
                'label' => $label !== '' ? $label : $node->getName(),
                'url' => $this->url->linkToRoute('files.view.showFile', ['fileid' => $node->getId()]),
                'fileId' => $node->getId(),
            ];
        }
        $lien['by'] = $uid;
        $lien['at'] = time();
        $liens[] = $lien;
        $t->setLiens($liens);
        $this->taches->update($t);
        return new DataResponse($liens, Http::STATUS_CREATED);
    }

    // DELETE /api/board/{id}/liens/{idx} : retire le lien n° idx (position dans la liste).
    #[NoAdminRequired]
    public function lienDelete(int $id, int $idx): DataResponse {
        $t = $this->tacheAccessible($id);
        if ($t instanceof DataResponse) {
            return $t;
        }
        $liens = is_array($t->getLiens()) ? $t->getLiens() : [];
        if (!isset($liens[$idx])) {
            return new DataResponse(['message' => 'Lien introuvable.'], Http::STATUS_NOT_FOUND);
        }
        array_splice($liens, $idx, 1); // retire l'élément et renumérote la liste
        $t->setLiens($liens);
        $this->taches->update($t);
        return new DataResponse($liens);
    }

    // Traduction technique → humain des statuts (pour écrire l'historique en français).
    private const ST_LABEL = ['a_faire' => 'À faire', 'en_cours' => 'En cours', 'fait' => 'Fait'];

    // GET /api/board : renvoie toutes les tâches-feuilles accessibles, avec leur contexte
    // (projet, activité, assigné) et leurs heures réelles. C'est la donnée de base du front.
    #[NoAdminRequired]
    public function index(): DataResponse {
        // (1) On prépare une table id_projet → { nom, couleur } pour l'affichage.
        $projetInfo = [];
        $projets = $this->projets->findAll();
        foreach ($projets as $p) {
            $projetInfo[$p->getId()] = ['nom' => $p->getNom(), 'couleur' => $p->getCouleur()];
        }

        // (2) On ne garde que les tâches des projets ACCESSIBLES à l'utilisateur courant.
        $u = $this->userSession->getUser();
        $uid = $u ? $u->getUID() : '';
        // array_flip pour tester l'appartenance instantanément (voir RefController::allowedIds).
        $allowed = array_flip($uid !== '' ? $this->access->accessibleProjetIds($uid) : []);
        $all = [];
        foreach ($projets as $p) {
            if (!isset($allowed[$p->getId()])) {
                continue; // projet interdit → on saute au suivant
            }
            foreach ($this->taches->findByProjet($p->getId()) as $t) {
                $all[] = $t; // on empile toutes les tâches de ce projet
            }
        }

        // (3) On repère quelles tâches sont des PARENTS (elles ont au moins un enfant).
        // Pour ça : on note, pour chaque tâche ayant un parentId, que ce parent a un enfant.
        // $byId sert de dictionnaire id → tâche (pour retrouver une tâche par son id).
        $parentIds = [];
        $byId = [];
        foreach ($all as $t) {
            $byId[$t->getId()] = $t;
            if ($t->getParentId()) {
                $parentIds[$t->getParentId()] = true;
            }
        }

        // Caches locaux pour éviter de refaire les mêmes recherches BD dans la boucle.
        $actNom = [];   // id_activité → nom (mémorisé une fois)
        $nameOf = [];   // assigné → nom résolu (mémorisé une fois)
        $out = [];
        foreach ($all as $t) {
            // (4) On ne garde QUE les feuilles : une tâche qui est parent d'une autre n'est
            // pas « actionnable » directement, on affiche ses sous-tâches à la place.
            if (isset($parentIds[$t->getId()])) {
                continue; // pas une feuille (a des sous-tâches)
            }

            // Nom de l'activité (phase) de la tâche, avec cache.
            // array_key_exists teste la présence de la clé même si sa valeur est null.
            $an = null;
            $aid = $t->getActiviteId();
            if ($aid) {
                if (!array_key_exists($aid, $actNom)) {
                    try {
                        $actNom[$aid] = $this->activites->find($aid)->getNom();
                    } catch (DoesNotExistException $e) {
                        $actNom[$aid] = null; // activité disparue → on mémorise null (pas de re-recherche)
                    }
                }
                $an = $actNom[$aid];
            }

            // Titre de la tâche parente (si sous-tâche), pour afficher un fil de contexte.
            $parentTitre = ($t->getParentId() && isset($byId[$t->getParentId()])) ? $byId[$t->getParentId()]->getTitre() : null;

            // Assigné = une personne (uid) OU un groupe ("group:<gid>") — nom résolu par AccessService.
            $assignee = $t->getAssignee();
            $aname = null;
            if ($assignee) {
                if (!array_key_exists($assignee, $nameOf)) {
                    $nameOf[$assignee] = $this->access->principalName($assignee);
                }
                $aname = $nameOf[$assignee];
            }

            // Infos projet (nom/couleur), avec valeur de repli si le projet a disparu.
            $pi = $projetInfo[$t->getProjetId()] ?? ['nom' => null, 'couleur' => null];

            // (5) On assemble la ligne exposée au front (JSON).
            $out[] = [
                'id' => $t->getId(),
                'titre' => $t->getTitre(),
                'statut' => $t->getStatut(),
                'assignee' => $assignee,
                'assigneeName' => $aname,
                // « m'est-elle assignée ? » (à moi, OU à un groupe dont je fais partie) — pour « Mes tâches »
                'mine' => $assignee ? $this->access->isAssignedTo($uid, $assignee) : false,
                'heuresPrevues' => $t->getHeuresPrevues(),
                'echeance' => $t->getEcheance(),
                'projetId' => $t->getProjetId(),
                'projetNom' => $pi['nom'],
                'couleur' => $pi['couleur'],
                'activiteNom' => $an,
                'parentTitre' => $parentTitre,
                'valide' => $t->getValide(), // false = proposition d'un membre en attente de validation
                'reel' => 0.0, // rempli juste après (voir étape 6)
            ];
        }

        // (6) Heures réelles par tâche : on additionne toutes les saisies de chaque tâche.
        // array_map extrait juste les id ; findByTaches récupère toutes les heures en 1 requête.
        $ids = array_map(static fn ($r) => $r['id'], $out);
        $reelBy = [];
        foreach ($this->heures->findByTaches($ids) as $h) {
            // ($reelBy[$id] ?? 0) : valeur courante ou 0 si pas encore vue → on cumule.
            $reelBy[$h->getTacheId()] = ($reelBy[$h->getTacheId()] ?? 0) + (float)$h->getHeures();
        }

        // (7) Tâches bloquées : ensemble des id ayant un blocage ouvert, pour cocher « bloque ».
        $blocked = array_flip($this->comments->tachesBloquees($ids));

        // (8) On complète chaque ligne avec son réel arrondi et son drapeau « bloque ».
        // « &$r » = par référence : on modifie directement les lignes de $out (pas une copie).
        foreach ($out as &$r) {
            $r['reel'] = round($reelBy[$r['id']] ?? 0, 2);
            $r['bloque'] = isset($blocked[$r['id']]);
        }
        unset($r); // bonne pratique : casser la référence après la boucle (évite des bugs subtils)

        // (9) AFFECTATIONS : une tâche peut être affectée à plusieurs personnes/groupes (en plus
        // de l'assigné unique). On les charge en une requête, puis pour chaque tâche on complète :
        //   - « mine » : la tâche m'est aussi « mienne » si je suis (ou fais partie d'un groupe) affecté ;
        //   - « assigneeName » : si aucun assigné unique, on affiche à la place les noms des affectés.
        $affParTache = [];
        foreach ($this->affectations->findByTaches($ids) as $a) { $affParTache[$a->getTacheId()][] = $a; }
        foreach ($out as &$r) {
            $affs = $affParTache[$r['id']] ?? [];
            $noms = [];
            $budget = 0.0;
            foreach ($affs as $a) {
                // Je suis concerné par cette affectation (personne = moi, ou groupe dont je suis membre) ?
                if ($uid !== '' && $this->access->isAssignedTo($uid, $a->getPrincipal())) { $r['mine'] = true; }
                $nom = $this->access->principalName($a->getPrincipal());
                if ($nom !== null) { $noms[] = $nom; }
                $budget += (float)($a->getHeuresPrevues() ?? 0);
            }
            $r['affectes'] = count($affs); // nombre d'affectés (badge éventuel sur la carte)
            // Prévu EFFECTIF : une tâche affectée affiche le cumul des heures de ses affectations.
            if ($affs) { $r['heuresPrevues'] = round($budget, 2); }
            // Repli d'affichage : pas d'assigné unique mais des affectés → on montre leurs noms.
            if (!$r['assigneeName'] && $noms) { $r['assigneeName'] = implode(', ', $noms); }
        }
        unset($r);

        return new DataResponse($out);
    }

    /** Fiche détail d'une tâche : contexte, prévu/réel et heures PAR PERSONNE. */
    #[NoAdminRequired]
    public function detail(int $id): DataResponse {
        // La tâche existe-t-elle ?
        try {
            $t = $this->taches->find($id);
        } catch (DoesNotExistException $e) {
            return new DataResponse(['message' => 'Introuvable.'], Http::STATUS_NOT_FOUND);
        }
        // Accès : le projet de la tâche doit être accessible à l'utilisateur.
        $u = $this->userSession->getUser();
        $allowed = array_flip($u ? $this->access->accessibleProjetIds($u->getUID()) : []);
        if (!isset($allowed[$t->getProjetId()])) {
            return new DataResponse(['message' => 'Accès refusé.'], Http::STATUS_FORBIDDEN);
        }

        // Fil d'Ariane : Projet › Phase (activité) › Activité (tâche parente).
        // Chaque bloc try/catch protège contre un maillon supprimé (on laisse alors le nom à null).
        $projetNom = null;
        $couleur = null;
        try {
            $p = $this->projets->find($t->getProjetId());
            $projetNom = $p->getNom();
            $couleur = $p->getCouleur();
        } catch (DoesNotExistException $e) {
        }
        $phaseNom = null;
        if ($t->getActiviteId()) {
            try {
                $phaseNom = $this->activites->find($t->getActiviteId())->getNom();
            } catch (DoesNotExistException $e) {
            }
        }
        $activiteNom = null;
        if ($t->getParentId()) {
            try {
                $activiteNom = $this->taches->find($t->getParentId())->getTitre();
            } catch (DoesNotExistException $e) {
            }
        }

        // Assigné : personne (uid) ou groupe ("group:<gid>").
        $assignee = $t->getAssignee();
        $assigneeName = $this->access->principalName($assignee);

        // Prévu / réel « roulés » : une tâche PARENTE cumule ceux de sa descendance (comme dans l'arbre),
        // pour qu'ouvrir « Communication » n'affiche pas 0 h mais bien la somme de ses sous-tâches.
        // (Toujours SANS détail par personne : confidentialité, pas de comparaison entre collègues.)
        $taches = $this->taches->findByProjet($t->getProjetId());
        $byId = [];        // id → tâche
        $childrenOf = [];  // parentId → [enfants]
        foreach ($taches as $x) { $byId[$x->getId()] = $x; $childrenOf[$x->getParentId() ?: 0][] = $x; }
        $tIds = array_map(static fn ($x) => $x->getId(), $taches);
        $reelBy = []; // heures réelles par tâche
        foreach ($this->heures->findByTaches($tIds) as $h) { $reelBy[$h->getTacheId()] = ($reelBy[$h->getTacheId()] ?? 0) + (float)$h->getHeures(); }
        // Prévu EFFECTIF : une feuille affectée vaut le cumul de ses affectations (sinon son estimation manuelle).
        $budget = $this->affService->budgetByTaches($tIds);
        // Deux fonctions récursives : somme des enfants si parent, prévu effectif si feuille.
        $prevuOf = function ($tid) use (&$prevuOf, $byId, $childrenOf, $budget) {
            $ch = $childrenOf[$tid] ?? [];
            if ($ch) { $s = 0; foreach ($ch as $c) { $s += $prevuOf($c->getId()); } return $s; }
            return $this->affService->prevuEffectifFeuille($tid, $byId[$tid]->getHeuresPrevues(), $budget);
        };
        // Réel = heures pointées SUR la tâche elle-même + celles de toute sa descendance. Les heures
        // « propres » d'un parent existent (tâche pointée avant qu'on lui crée des sous-tâches) :
        // on les compte, sinon elles disparaîtraient de la fiche alors que les affectations les comptent.
        $reelOf = function ($tid) use (&$reelOf, $childrenOf, $reelBy) {
            $s = (float)($reelBy[$tid] ?? 0);
            foreach ($childrenOf[$tid] ?? [] as $c) { $s += $reelOf($c->getId()); }
            return $s;
        };
        $reel = $reelOf($id);
        $prevuRoule = $prevuOf($id);

        // AFFECTATIONS de la tâche : chaque personne/groupe affecté, avec SON prévu et SON réel,
        // pour repérer un dépassement (ex. Hélène a fait 8 h alors que 5 h lui étaient prévues).
        // Le réel est CUMULÉ sur le sous-arbre (la tâche + ses sous-tâches) — voir AffectationService.
        $affectations = $this->affService->rows($id, $t->getProjetId());

        // Commentaires & blocages de la tâche, enrichis (auteur lisible, « mine »).
        $commentaires = [];
        foreach ($this->comments->findByTache($id) as $c) {
            $cu = $this->userManager->get($c->getUserId());
            $commentaires[] = [
                'id' => $c->getId(),
                'auteur' => $cu ? $cu->getDisplayName() : $c->getUserId(),
                'texte' => $c->getTexte(),
                'type' => $c->getType(),
                'resolved' => $c->getResolved(),
                'createdAt' => $c->getCreatedAt(),
                'mine' => $c->getUserId() === ($u ? $u->getUID() : ''),
            ];
        }
        // La tâche a-t-elle un blocage ouvert ? (tachesBloquees renvoie les id bloqués ; non vide = oui)
        $bloque = !empty($this->comments->tachesBloquees([$id]));

        // Historique (audit) de la tâche : les 50 derniers événements, auteur résolu en nom.
        $historique = [];
        foreach ($this->historique->findByElement('tache', $id, 50) as $h) {
            $hu = $this->userManager->get($h->getUserId());
            $historique[] = [
                'action' => $h->getAction(),
                'avant' => $h->getAvant(),
                'apres' => $h->getApres(),
                'auteur' => $hu ? $hu->getDisplayName() : $h->getUserId(),
                'createdAt' => $h->getCreatedAt(),
            ];
        }

        // Réponse complète pour la fiche (panneau de droite dans l'UI).
        return new DataResponse([
            'id' => $t->getId(),
            'titre' => $t->getTitre(),
            'description' => $t->getDescription(),
            'statut' => $t->getStatut(),
            'assignee' => $assignee,
            'assigneeName' => $assigneeName,
            'dateDebut' => $t->getDateDebut(),
            'echeance' => $t->getEcheance(),
            'heuresPrevues' => $prevuRoule, // prévu cumulé (roulé sur la descendance)
            'reel' => round($reel, 2),
            // Heures pointées SUR la tâche elle-même quand elle a des sous-tâches (0 sinon) : la fiche
            // l'affiche en note, car ces heures ne sont dans aucune sous-tâche.
            'reelPropre' => !empty($childrenOf[$id]) ? round((float)($reelBy[$id] ?? 0), 2) : 0,
            'liens' => is_array($t->getLiens()) ? $t->getLiens() : [],
            'projetId' => $t->getProjetId(),
            'projetNom' => $projetNom,
            'couleur' => $couleur,
            'phaseNom' => $phaseNom,
            'activiteNom' => $activiteNom,
            // NB : pas de clé « heuresParPersonne » ici — le détail par personne n'est
            // volontairement PAS renvoyé (confidentialité entre collègues). Seul le
            // total « reel » ci-dessus est exposé.
            'commentaires' => $commentaires,
            'bloque' => $bloque,
            // « peutLever » : l'utilisateur a-t-il le droit de lever un blocage ? (direction)
            'peutLever' => $this->access->isDirection($u ? $u->getUID() : ''),
            // Proposition en attente ? Et l'utilisateur courant peut-il la valider (admin = la direction) ?
            'valide' => $t->getValide(),
            'peutValider' => $this->access->isAdmin($u ? $u->getUID() : ''),
            'peutEditer' => $this->access->isDirection($u ? $u->getUID() : ''),
            // Affectations (personnes/groupes + prévu/réel/dépassement) et droit d'y toucher (direction).
            'affectations' => $affectations,
            'peutAffecter' => $this->access->peutAssigner($u ? $u->getUID() : ''),
            'historique' => $historique,
        ]);
    }

    /** Déplacer une tâche = changer son statut. Autorisé à tout MEMBRE du projet concerné. */
    #[NoAdminRequired]
    public function setStatut(int $id, ?string $statut = null): DataResponse {
        // Le statut reçu doit faire partie de la liste blanche (sinon 400). Le 3e argument
        // « true » de in_array impose une comparaison STRICTE (type + valeur), plus sûre.
        if (!in_array($statut, self::STATUTS, true)) {
            return new DataResponse(['message' => 'Statut invalide.'], Http::STATUS_BAD_REQUEST);
        }
        try {
            $t = $this->taches->find($id);
        } catch (DoesNotExistException $e) {
            return new DataResponse(['message' => 'Introuvable.'], Http::STATUS_NOT_FOUND);
        }
        // Contrôle d'accès : on ne peut modifier que les tâches d'un projet auquel on a accès.
        $u = $this->userSession->getUser();
        $allowed = array_flip($u ? $this->access->accessibleProjetIds($u->getUID()) : []);
        if (!isset($allowed[$t->getProjetId()])) {
            return new DataResponse(['message' => 'Accès refusé à ce projet.'], Http::STATUS_FORBIDDEN);
        }
        // SÉCURITÉ : on ne peut cocher « fait » une tâche que si TOUTES ses sous-tâches directes le sont
        // déjà (donc, de proche en proche, toute sa descendance). Évite de clôturer un parent en avance.
        if ($statut === 'fait') {
            foreach ($this->taches->findByProjet($t->getProjetId()) as $c) {
                if ($c->getParentId() === $id && $c->getStatut() !== 'fait') {
                    return new DataResponse(['message' => 'Terminez d’abord toutes les sous-tâches.'], Http::STATUS_BAD_REQUEST);
                }
            }
        }
        // On mémorise l'ancien statut AVANT de changer, pour savoir s'il y a vraiment eu un changement.
        $old = $t->getStatut();
        $t->setStatut($statut);
        $res = $this->taches->update($t);
        // On ne journalise que si le statut a réellement changé (évite le bruit dans l'historique).
        if ($old !== $statut) {
            $u = $this->userSession->getUser();
            // On enregistre l'événement avec les libellés FR (À faire → En cours), pas les codes techniques.
            $this->histo->record($t->getProjetId(), 'tache', $t->getId(), $t->getTitre(), $u ? $u->getUID() : '', 'statut', self::ST_LABEL[$old] ?? $old, self::ST_LABEL[$statut] ?? $statut);
        }
        return new DataResponse($res);
    }

    /**
     * GET /api/planneur?projet=… : toutes les données de la VUE PLANNEUR (Gantt).
     * Renvoie, pour les projets accessibles (ou un seul si `projet` fourni) :
     *   - projets  : { id, nom, couleur }
     *   - phases   : { id, projetId, nom }  (les activités = phases)
     *   - taches   : { id, projetId, activiteId, parentId, titre, statut, dateDebut, echeance,
     *                  valide, assigneeName (référent), affectes:[noms], mine }
     * Le front construit l'arbre et positionne les barres (début → échéance) lui-même.
     */
    #[NoAdminRequired]
    public function planneur(?int $projet = null): DataResponse {
        $u = $this->userSession->getUser();
        $uid = $u ? $u->getUID() : '';
        // Projets visibles par l'utilisateur (tous si direction), éventuellement restreints à un seul.
        $allowed = array_flip($uid !== '' ? $this->access->accessibleProjetIds($uid) : []);
        $projById = [];
        $projetsOut = [];
        foreach ($this->projets->findAll() as $p) {
            if (!isset($allowed[$p->getId()])) { continue; }
            if ($projet !== null && $projet > 0 && $p->getId() !== $projet) { continue; }
            $projById[$p->getId()] = $p;
            $projetsOut[] = ['id' => $p->getId(), 'nom' => $p->getNom(), 'couleur' => $p->getCouleur()];
        }
        // Phases (activités) des projets retenus.
        $phasesOut = [];
        foreach ($projById as $pid => $p) {
            foreach ($this->activites->findByProjet($pid) as $a) {
                $phasesOut[] = ['id' => $a->getId(), 'projetId' => $pid, 'nom' => $a->getNom()];
            }
        }
        // Tâches (tous niveaux) + leurs affectations (une requête groupée).
        $taches = [];
        $allIds = [];
        foreach ($projById as $pid => $p) {
            foreach ($this->taches->findByProjet($pid) as $t) { $taches[] = $t; $allIds[] = $t->getId(); }
        }
        $affByTache = [];
        foreach ($this->affectations->findByTaches($allIds) as $a) { $affByTache[$a->getTacheId()][] = $a->getPrincipal(); }
        // Mise en forme des tâches : nom du référent, noms des affectés, et « mine » (référent OU affecté = moi).
        $tachesOut = [];
        foreach ($taches as $t) {
            $ref = $t->getAssignee();
            $mine = $ref ? $this->access->isAssignedTo($uid, $ref) : false;
            $affNoms = [];
            foreach ($affByTache[$t->getId()] ?? [] as $principal) {
                if ($this->access->isAssignedTo($uid, $principal)) { $mine = true; }
                $n = $this->access->principalName($principal);
                if ($n !== null) { $affNoms[] = $n; }
            }
            $tachesOut[] = [
                'id' => $t->getId(),
                'projetId' => $t->getProjetId(),
                'activiteId' => $t->getActiviteId(),
                'parentId' => $t->getParentId(),
                'titre' => $t->getTitre(),
                'statut' => $t->getStatut(),
                'dateDebut' => $t->getDateDebut(),
                'echeance' => $t->getEcheance(),
                'valide' => $t->getValide(),
                'assigneeName' => $this->access->principalName($ref),
                'affectes' => $affNoms,
                'mine' => $mine,
            ];
        }
        return new DataResponse(['projets' => $projetsOut, 'phases' => $phasesOut, 'taches' => $tachesOut]);
    }
}
