<?php
// SPDX-FileCopyrightText: 2026 Flavien Auvray
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\TemoniaSaisie\Controller;

// Réutilise la couche données de Pilotage (app compagnon).
use OCA\TemoniaPilotage\Db\CommentaireMapper;
use OCA\TemoniaPilotage\Db\HeureMapper;
use OCA\TemoniaPilotage\Db\HistoriqueMapper;
use OCA\TemoniaPilotage\Db\ProjetMapper;
use OCA\TemoniaPilotage\Db\TacheMapper;
use OCA\TemoniaPilotage\Service\AccessService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IConfig;   // lit/écrit des préférences (ici : la disposition des widgets par utilisateur)
use OCP\IRequest;
use OCP\IUserManager;
use OCP\IUserSession;

/**
 * Alimente le tableau de bord à widgets (style Salesforce).
 *
 * Deux rôles :
 *  1. `stats()` : toutes les données agrégées des widgets (KPI, graphes, listes),
 *     TOUJOURS filtrées sur les projets auxquels l'utilisateur courant a accès.
 *  2. `getLayout()` / `saveLayout()` : la disposition personnalisée des widgets,
 *     stockée PAR UTILISATEUR dans ses préférences Nextcloud (aucune table dédiée) —
 *     chacun a donc sa propre mise en page.
 */
class DashboardController extends Controller {
    private ProjetMapper $projets;
    private TacheMapper $taches;
    private HeureMapper $heures;
    private CommentaireMapper $comments;
    private HistoriqueMapper $historique;
    private AccessService $access;
    private IUserManager $userManager;
    private IUserSession $userSession;
    private IConfig $config;

    // Clé de préférence utilisateur où l'on range la disposition (JSON).
    private const LAYOUT_KEY = 'dashboard_layout';

    public function __construct(string $appName, IRequest $request, ProjetMapper $projets, TacheMapper $taches, HeureMapper $heures, CommentaireMapper $comments, HistoriqueMapper $historique, AccessService $access, IUserManager $userManager, IUserSession $userSession, IConfig $config) {
        parent::__construct($appName, $request);
        $this->projets = $projets;
        $this->taches = $taches;
        $this->heures = $heures;
        $this->comments = $comments;
        $this->historique = $historique;
        $this->access = $access;
        $this->userManager = $userManager;
        $this->userSession = $userSession;
        $this->config = $config;
    }

    // Identifiant de l'utilisateur connecté ('' si personne).
    private function uid(): string {
        $u = $this->userSession->getUser();
        return $u ? $u->getUID() : '';
    }

    /** uid → nom affichable, avec petit cache local pour éviter les allers-retours. */
    private array $nameCache = []; // mémoire tampon : uid déjà résolu → son nom
    private function nameOf(string $uid): string {
        if ($uid === '') { return '—'; }
        // Si on n'a pas encore résolu cet uid, on le fait UNE fois et on range le résultat.
        if (!isset($this->nameCache[$uid])) {
            $u = $this->userManager->get($uid);
            $this->nameCache[$uid] = $u ? $u->getDisplayName() : $uid;
        }
        return $this->nameCache[$uid];
    }

    /**
     * Toutes les données du tableau de bord, agrégées côté serveur et limitées
     * aux projets accessibles à l'utilisateur courant.
     */
    #[NoAdminRequired]
    public function stats(?int $projet = null, ?int $periode = null): DataResponse {
        $uid = $this->uid();
        // Les projets auxquels l'utilisateur a droit (liste d'ids).
        $allowed = $this->access->accessibleProjetIds($uid);
        // Filtre PROJET (optionnel) : on se restreint à ce seul projet s'il est accessible.
        if ($projet !== null && in_array($projet, $allowed, true)) { $allowed = [$projet]; }
        // Version « ensemble » pour tester l'appartenance en O(1) (voir array_flip ailleurs).
        $allowedSet = array_flip($allowed);
        // Filtre PÉRIODE (optionnel, en jours) : borne pour les données datées (heures, activité).
        // Les compteurs d'état (statuts, blocages) ne dépendent pas d'une fenêtre de temps.
        // 86400 = nombre de secondes dans une journée ; on recule de « periode » jours.
        $cutoffJour = ($periode !== null && $periode > 0) ? date('Y-m-d', time() - $periode * 86400) : null;
        $cutoffTs = ($periode !== null && $periode > 0) ? time() - $periode * 86400 : null;

        // 1) Projets accessibles + libellés/couleurs (on filtre la liste complète sur $allowedSet).
        $projets = array_values(array_filter($this->projets->findAll(), static fn ($p) => isset($allowedSet[$p->getId()])));
        $projetNom = [];
        $projetCouleur = [];
        foreach ($projets as $p) {
            $projetNom[$p->getId()] = $p->getNom();
            $projetCouleur[$p->getId()] = $p->getCouleur();
        }

        // 2) Toutes les tâches de ces projets + index parent/enfant.
        $allTaches = [];
        foreach ($projets as $p) {
            foreach ($this->taches->findByProjet($p->getId()) as $t) {
                $allTaches[] = $t;
            }
        }
        // On repère les tâches qui sont parentes (ont au moins un enfant).
        $parentIds = [];
        foreach ($allTaches as $t) {
            if ($t->getParentId()) { $parentIds[$t->getParentId()] = true; }
        }
        // Une « feuille » = tâche sans sous-tâche : c'est l'unité actionnable (statut, heures).
        $leaves = array_filter($allTaches, static fn ($t) => !isset($parentIds[$t->getId()]));
        // Deux listes d'ids : toutes les tâches, et seulement les feuilles.
        $allIds = array_map(static fn ($t) => $t->getId(), $allTaches);
        $leafIds = array_map(static fn ($t) => $t->getId(), $leaves);

        // 3) Heures réelles (toutes personnes) sur ces tâches, agrégées de plusieurs façons.
        $heuresRows = empty($allIds) ? [] : $this->heures->findByTaches($allIds);
        $reelParTache = [];   // tacheId => heures cumulées
        $reelParProjet = [];  // projetId => heures
        $heuresParPersonne = []; // uid => heures (calculé mais NON exposé, cf. confidentialité)
        $heuresParMois = [];  // 'AAAA-MM' => heures (pour la courbe dans le temps)
        // Table tacheId → projetId, pour rattacher chaque saisie à son projet.
        $tacheProjet = [];
        foreach ($allTaches as $t) { $tacheProjet[$t->getId()] = $t->getProjetId(); }
        $totalHeures = 0.0;
        foreach ($heuresRows as $h) {
            // Si un filtre période est actif, on ignore les saisies antérieures à la borne.
            if ($cutoffJour !== null && (string)$h->getJour() < $cutoffJour) { continue; } // hors période
            $v = (float)$h->getHeures();
            $totalHeures += $v;
            $tid = $h->getTacheId();
            // Cumuls (le motif « ($x[$k] ?? 0) + $v » = additionner en initialisant à 0 au besoin).
            $reelParTache[$tid] = ($reelParTache[$tid] ?? 0) + $v;
            if (isset($tacheProjet[$tid])) {
                $pid = $tacheProjet[$tid];
                $reelParProjet[$pid] = ($reelParProjet[$pid] ?? 0) + $v;
            }
            $heuresParPersonne[$h->getUserId()] = ($heuresParPersonne[$h->getUserId()] ?? 0) + $v;
            // substr(...,0,7) garde 'AAAA-MM' d'une date 'AAAA-MM-JJ' → regroupement par mois.
            $mois = substr((string)$h->getJour(), 0, 7);
            if ($mois !== '') { $heuresParMois[$mois] = ($heuresParMois[$mois] ?? 0) + $v; }
        }

        // 4) Prévu par projet (somme des feuilles) + comptes de statut.
        $prevuParProjet = [];
        $statut = ['a_faire' => 0, 'en_cours' => 0, 'fait' => 0];
        foreach ($leaves as $t) {
            $prevuParProjet[$t->getProjetId()] = ($prevuParProjet[$t->getProjetId()] ?? 0) + (float)($t->getHeuresPrevues() ?? 0);
            $s = $t->getStatut();
            if (isset($statut[$s])) { $statut[$s]++; } // on incrémente le compteur du statut correspondant
        }
        $totalPrevu = array_sum($prevuParProjet);

        // 5) Tâches bloquées (blocage non résolu) parmi les feuilles.
        $blockedSet = array_flip(empty($leafIds) ? [] : $this->comments->tachesBloquees($leafIds));

        // 6) Widget « Avancement par projet » : pour chaque projet, prévu/réel et % d'avancement.
        $avancementProjets = [];
        foreach ($projets as $p) {
            $pid = $p->getId();
            $prevu = round($prevuParProjet[$pid] ?? 0, 2);
            $reel = round($reelParProjet[$pid] ?? 0, 2);
            if ($prevu <= 0 && $reel <= 0) { continue; } // rien à montrer pour ce projet
            $avancementProjets[] = [
                'id' => $pid, 'nom' => $p->getNom(), 'couleur' => $p->getCouleur(),
                'prevu' => $prevu, 'reel' => $reel,
                // % = réel/prévu, plafonné à 100. Si pas de prévu : 100 s'il y a du réel, sinon 0.
                'pct' => $prevu > 0 ? min(100, round($reel / $prevu * 100)) : ($reel > 0 ? 100 : 0),
            ];
        }
        // Tri décroissant sur le réel : « <=> » (vaisseau spatial) renvoie -1/0/1 pour comparer.
        usort($avancementProjets, static fn ($a, $b) => $b['reel'] <=> $a['reel']);

        // (Le détail des heures PAR PERSONNE n'est volontairement PAS exposé — confidentialité.)

        // 8) Heures par mois (ordre chronologique). ksort trie par CLÉ (les 'AAAA-MM').
        ksort($heuresParMois);
        $mois = [];
        foreach ($heuresParMois as $m => $sum) { $mois[] = ['mois' => $m, 'heures' => round($sum, 2)]; }

        // 9) Listes : mes tâches / blocages (on parcourt les feuilles une fois).
        $mesTaches = [];
        $blocages = [];
        foreach ($leaves as $t) {
            $bloque = isset($blockedSet[$t->getId()]);
            $ligne = [
                'id' => $t->getId(),
                'titre' => $t->getTitre(),
                'statut' => $t->getStatut(),
                'projetNom' => $projetNom[$t->getProjetId()] ?? null,
                'couleur' => $projetCouleur[$t->getProjetId()] ?? null,
                'heuresPrevues' => $t->getHeuresPrevues(),
                'assigneeName' => $this->access->principalName($t->getAssignee()), // personne OU groupe
                'bloque' => $bloque,
            ];
            // « Mes tâches » = assignées à moi OU à un groupe dont je fais partie, et pas encore faites.
            if ($this->access->isAssignedTo($uid, $t->getAssignee()) && $t->getStatut() !== 'fait') { $mesTaches[] = $ligne; }
            if ($bloque) { $blocages[] = $ligne; }
        }

        // 10) Activité récente (journal d'historique multi-projets), limitée à 20 lignes affichées.
        $activite = [];
        foreach ($this->historique->findByProjets($allowed, 40) as $h) {
            if ($cutoffTs !== null && $h->getCreatedAt() < $cutoffTs) { continue; } // hors période
            if (count($activite) >= 20) { break; } // on s'arrête à 20 événements
            $activite[] = [
                'action' => $h->getAction(),
                'elementType' => $h->getElementType(),
                'elementTitre' => $h->getElementTitre(),
                'avant' => $h->getAvant(),
                'apres' => $h->getApres(),
                'auteur' => $this->nameOf($h->getUserId()),
                'projetNom' => $projetNom[$h->getProjetId()] ?? null,
                'createdAt' => $h->getCreatedAt(),
            ];
        }

        // 11) KPI (chiffres clés) affichés dans les tuiles du haut.
        $tachesTotal = count($leaves);
        $tachesFaites = $statut['fait'];
        $kpis = [
            // Projets non clos (array_filter garde ceux dont le statut n'est pas 'clos').
            'projets' => count(array_filter($projets, static fn ($p) => $p->getStatut() !== 'clos')),
            'tachesOuvertes' => $tachesTotal - $tachesFaites,
            'tachesTotal' => $tachesTotal,
            'tachesFaites' => $tachesFaites,
            'heures' => round($totalHeures, 1),
            // Avancement global = total heures réelles / total prévu, plafonné à 100 %.
            'avancement' => $totalPrevu > 0 ? min(100, round($totalHeures / $totalPrevu * 100)) : 0,
            'bloquees' => count($blockedSet),
        ];

        // Réponse : toutes les briques de données que le front distribue à ses widgets.
        return new DataResponse([
            'kpis' => $kpis,
            'avancementProjets' => $avancementProjets,
            'tachesParStatut' => $statut,
            'heuresParMois' => $mois,
            'mesTaches' => array_slice($mesTaches, 0, 50), // au plus 50 lignes
            'blocages' => $blocages,
            'activite' => $activite,
        ]);
    }

    /** Disposition personnalisée de l'utilisateur courant (null s'il n'en a jamais enregistré). */
    #[NoAdminRequired]
    public function getLayout(): DataResponse {
        // getUserValue(uid, app, clé, défaut) : lit la préférence de CET utilisateur ('' si absente).
        $raw = $this->config->getUserValue($this->uid(), $this->appName, self::LAYOUT_KEY, '');
        // On avait stocké du JSON ; json_decode(..., true) le retransforme en tableau PHP.
        $layout = $raw !== '' ? json_decode($raw, true) : null;
        return new DataResponse(['layout' => is_array($layout) ? $layout : null]);
    }

    /**
     * Enregistre la disposition de l'utilisateur courant. On ne garde que des
     * champs connus et bornés (i, x, y, w, h) pour ne rien stocker d'arbitraire.
     */
    #[NoAdminRequired]
    public function saveLayout(?array $layout = null): DataResponse {
        // On RECONSTRUIT une version propre ($clean) plutôt que de stocker tel quel : on ne fait
        // jamais confiance aux données du client (un widget pourrait envoyer n'importe quoi).
        $clean = [];
        foreach (($layout ?? []) as $item) {
            // On ignore les entrées mal formées (pas un tableau, ou sans type 'i').
            if (!is_array($item) || !isset($item['i'])) { continue; }
            $entry = [
                'i' => (string)$item['i'],               // type de widget (ex. 'kpi_heures')
                // On force des entiers positifs et des tailles minimales (max/() = garde-fous).
                'x' => max(0, (int)($item['x'] ?? 0)),   // colonne
                'y' => max(0, (int)($item['y'] ?? 0)),   // ligne
                'w' => max(1, (int)($item['w'] ?? 3)),   // largeur (en cellules)
                'h' => max(1, (int)($item['h'] ?? 4)),   // hauteur (en cellules)
            ];
            // Clé d'instance (permet plusieurs exemplaires d'un même type de widget). Bornée à 40 car.
            if (isset($item['key']) && $item['key'] !== '') { $entry['key'] = mb_substr((string)$item['key'], 0, 40); }
            // Filtre optionnel du widget : projet ciblé et/ou fenêtre de temps (jours).
            if (isset($item['cfg']) && is_array($item['cfg'])) {
                $p = (int)($item['cfg']['projet'] ?? 0);
                $per = (int)($item['cfg']['periode'] ?? 0);
                // On ne garde le filtre que s'il a un contenu utile (« ?: null » : 0 devient null).
                if ($p > 0 || $per > 0) { $entry['cfg'] = ['projet' => $p ?: null, 'periode' => $per ?: null]; }
            }
            $clean[] = $entry;
            if (count($clean) >= 60) { break; } // garde-fou anti-abus : au plus 60 widgets
        }
        // On re-sérialise en JSON et on écrit dans les préférences de l'utilisateur.
        $this->config->setUserValue($this->uid(), $this->appName, self::LAYOUT_KEY, json_encode($clean));
        return new DataResponse(['ok' => true]);
    }
}
