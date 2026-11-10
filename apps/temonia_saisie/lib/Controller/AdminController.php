<?php
// SPDX-FileCopyrightText: 2026 Flavien Auvray
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\TemoniaSaisie\Controller;

// Réutilise la couche données de Pilotage (app compagnon).
use OCA\TemoniaPilotage\Db\ActiviteMapper;
use OCA\TemoniaPilotage\Db\AffectationMapper;
use OCA\TemoniaPilotage\Db\HeureMapper;
use OCA\TemoniaPilotage\Db\ProjetMapper;
use OCA\TemoniaPilotage\Db\TacheMapper;
use OCA\TemoniaPilotage\Service\AccessService;
use OCA\TemoniaPilotage\Service\Libelles;
use OCA\TemoniaPilotage\Service\AffectationService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataDownloadResponse; // réponse = un fichier à télécharger
use OCP\AppFramework\Http\DataResponse;
use OCP\Files\IRootFolder;                       // pour écrire un fichier dans les Fichiers Nextcloud
use OCP\IRequest;
use OCP\IUserManager;
use OCP\IUserSession;

/**
 * Tableau de bord d'ADMINISTRATION, réservé à la direction (administrateur Nextcloud).
 *
 * Contrairement au tableau de bord d'équipe (où les heures par personne sont MASQUÉES
 * pour la confidentialité), la direction a ici le détail complet : heures par personne, par
 * équipe (bénévole / salarié / partenaire) et par projet, avec des filtres, plus un récap
 * des tâches qu'il a assignées. Aucun bénévole / salarié / partenaire n'y a accès.
 */
class AdminController extends Controller {
    // Les 4 catégories d'équipe possibles (les 3 vraies + « autre » pour les hors-groupe).
    private const EQUIPES = ['benevole', 'salarie', 'pompier', 'autre'];
    // Valeurs acceptées pour le FILTRE équipe : les 4 catégories + « temonia » = REGROUPEMENT
    // « Équipe » (bénévoles + salariés), les Partenaires étant à part.
    private const EQUIPE_FILTRES = ['benevole', 'salarie', 'pompier', 'autre', 'temonia'];
    // Une catégorie de personne correspond-elle au filtre équipe choisi ? « temonia » = bénévole OU salarié.
    private function equipeMatch(string $team, string $filtre): bool {
        if ($filtre === 'temonia') { return $team === 'benevole' || $team === 'salarie'; }
        return $team === $filtre;
    }

    private ProjetMapper $projets;
    private ActiviteMapper $activites;
    private TacheMapper $taches;
    private HeureMapper $heures;
    private AffectationMapper $affectations;
    private AffectationService $affService;
    private AccessService $access;
    private IUserManager $userManager;
    private IUserSession $userSession;
    private IRootFolder $rootFolder;
    private Libelles $libelles;   // mots propres à la structure (Pilotage › Service/Libelles)

    public function __construct(string $appName, IRequest $request, ProjetMapper $projets, ActiviteMapper $activites, TacheMapper $taches, HeureMapper $heures, AffectationMapper $affectations, AffectationService $affService, AccessService $access, IUserManager $userManager, IUserSession $userSession, IRootFolder $rootFolder, Libelles $libelles) {
        parent::__construct($appName, $request);
        $this->projets = $projets;
        $this->activites = $activites;
        $this->taches = $taches;
        $this->heures = $heures;
        $this->affectations = $affectations;
        $this->affService = $affService;
        $this->access = $access;
        $this->userManager = $userManager;
        $this->userSession = $userSession;
        $this->rootFolder = $rootFolder;
        $this->libelles = $libelles;
    }

    private function uid(): string {
        $u = $this->userSession->getUser();
        return $u ? $u->getUID() : '';
    }

    // Cache local uid → nom (évite de redemander le même nom en boucle).
    private array $nameCache = [];
    private function nameOf(string $uid): string {
        if ($uid === '') { return '—'; }
        if (!isset($this->nameCache[$uid])) {
            $u = $this->userManager->get($uid);
            $this->nameCache[$uid] = $u ? $u->getDisplayName() : $uid;
        }
        return $this->nameCache[$uid];
    }

    /**
     * Données du dashboard admin, avec filtres facultatifs :
     *   $personne = uid ciblé ; $projet = id de projet ; $equipe = catégorie ; $periode = jours.
     */
    #[NoAdminRequired] // (l'attribut évite juste l'exigence admin par défaut du framework ; le vrai
                       //  contrôle est la barrière isAdmin ci-dessous : SEUL la direction passe.)
    public function stats(?string $personne = null, ?int $projet = null, ?string $equipe = null, ?int $periode = null): DataResponse {
        // BARRIÈRE DE SÉCURITÉ : réservé à l'administrateur Nextcloud (direction). Sinon 403.
        if (!$this->access->isAdmin($this->uid())) {
            return new DataResponse(['message' => 'Réservé à l’administration.'], Http::STATUS_FORBIDDEN);
        }

        // On nettoie/valide les filtres reçus (ne jamais faire confiance au client).
        $fEquipe = in_array($equipe, self::EQUIPE_FILTRES, true) ? $equipe : null;   // filtre équipe valide (dont « temonia »), sinon aucun
        $fPersonne = ($personne !== null && $personne !== '') ? $personne : null;
        $fProjet = ($projet !== null && $projet > 0) ? $projet : null;
        // Filtre période (jours) → borne « jour minimum » au format AAAA-MM-JJ (86400 s = 1 jour).
        $cutoffJour = ($periode !== null && $periode > 0) ? date('Y-m-d', time() - $periode * 86400) : null;

        // 1) Projets (admin = tout voir) + tables id → nom / couleur.
        $projets = $this->projets->findAll();
        $projetNom = [];
        $projetCouleur = [];
        foreach ($projets as $p) {
            $projetNom[$p->getId()] = $p->getNom();
            $projetCouleur[$p->getId()] = $p->getCouleur();
        }

        // 2) Toutes les tâches + table tacheId → projetId, et repérage des feuilles (pour le récap).
        $allTaches = [];
        foreach ($projets as $p) {
            foreach ($this->taches->findByProjet($p->getId()) as $t) { $allTaches[] = $t; }
        }
        $tacheProjet = [];
        foreach ($allTaches as $t) {
            $tacheProjet[$t->getId()] = $t->getProjetId();
        }
        $allIds = array_map(static fn ($t) => $t->getId(), $allTaches);

        // 3) On parcourt toutes les saisies d'heures UNE fois et on agrège selon les filtres.
        $parEquipe = ['benevole' => 0, 'salarie' => 0, 'pompier' => 0, 'autre' => 0];
        $parPersonne = [];      // uid => heures (respecte TOUS les filtres)
        $parProjet = [];        // projetId => heures (respecte tous les filtres)
        $reelParTacheUser = []; // tacheId => [uid => heures] (période + projet) → pour les dépassements par affecté
        $teamCache = [];        // uid => catégorie d'équipe (évite de recalculer)
        $total = 0.0;

        $heuresRows = empty($allIds) ? [] : $this->heures->findByTaches($allIds);
        foreach ($heuresRows as $h) {
            $tid = $h->getTacheId();
            $pid = $tacheProjet[$tid] ?? null;
            $jour = (string)$h->getJour();
            // Filtres « globaux » (période + projet) : appliqués aussi au calcul des dépassements.
            if ($cutoffJour !== null && $jour < $cutoffJour) { continue; }
            if ($fProjet !== null && $pid !== $fProjet) { continue; }
            $v = (float)$h->getHeures();

            // Équipe de l'auteur des heures (avec cache).
            $auteur = $h->getUserId();
            // Réel par (tâche, personne), sans filtre personne/équipe : base des dépassements par affecté.
            $reelParTacheUser[$tid][$auteur] = ($reelParTacheUser[$tid][$auteur] ?? 0) + $v;
            if (!isset($teamCache[$auteur])) { $teamCache[$auteur] = $this->access->teamOf($auteur); }
            $team = $teamCache[$auteur];

            // Filtres « fins » (personne, équipe) : ne concernent QUE les cumuls par personne/équipe/projet.
            if ($fPersonne !== null && $auteur !== $fPersonne) { continue; }
            if ($fEquipe !== null && !$this->equipeMatch($team, $fEquipe)) { continue; }

            $parEquipe[$team] = ($parEquipe[$team] ?? 0) + $v;
            $parPersonne[$auteur] = ($parPersonne[$auteur] ?? 0) + $v;
            if ($pid !== null) { $parProjet[$pid] = ($parProjet[$pid] ?? 0) + $v; }
            $total += $v;
        }

        // 4) Mise en forme des cumuls par personne (nom + équipe), triés du plus au moins d'heures.
        $personnes = [];
        foreach ($parPersonne as $u => $sum) {
            $personnes[] = ['uid' => $u, 'nom' => $this->nameOf($u), 'equipe' => $teamCache[$u] ?? 'autre', 'heures' => round($sum, 2)];
        }
        usort($personnes, static fn ($a, $b) => $b['heures'] <=> $a['heures']);

        // 5) Cumuls par projet (nom + couleur), triés décroissant.
        $projetsOut = [];
        foreach ($parProjet as $id => $sum) {
            $projetsOut[] = ['id' => $id, 'nom' => $projetNom[$id] ?? ('#' . $id), 'couleur' => $projetCouleur[$id] ?? null, 'heures' => round($sum, 2)];
        }
        usort($projetsOut, static fn ($a, $b) => $b['heures'] <=> $a['heures']);

        // 6) DÉPASSEMENTS par affecté : pour chaque affectation (personne/groupe sur une tâche),
        //       on compare son réel à son budget prévu. Le réel est CUMULÉ sur le sous-arbre (la tâche
        //       + ses sous-tâches). Chaque ligne embarque en plus le DÉTAIL par personne et par
        //       (sous-)tâche → la direction voit « équipe salariée dépassée » puis, en dépliant, que c'est
        //       un salarié qui a fait +5 h sur telle sous-tâche. Respecte les filtres projet/personne/équipe.
        //
        // childrenOf : parentId → [ids enfants] (les racines sous la clé 0), pour reconstituer les sous-arbres.
        $childrenOf = [];
        $tacheTitre = [];
        foreach ($allTaches as $t) { $childrenOf[$t->getParentId() ?: 0][] = $t->getId(); $tacheTitre[$t->getId()] = $t->getTitre(); }
        // Sous-arbre d'une tâche (elle + toute sa descendance), mémoïsé pour ne pas le recalculer.
        $subtreeCache = [];
        $subtreeOf = function (int $tid) use (&$subtreeOf, &$subtreeCache, $childrenOf) {
            if (isset($subtreeCache[$tid])) { return $subtreeCache[$tid]; }
            $ids = [$tid];
            foreach ($childrenOf[$tid] ?? [] as $c) { $ids = array_merge($ids, $subtreeOf($c)); }
            return $subtreeCache[$tid] = $ids;
        };
        $depassements = [];
        $cumulParProjet = [];   // projetId => [tacheId => [principal => heures]], calculé une fois par projet
        foreach ($this->affectations->findByTaches($allIds) as $a) {
            $tid = $a->getTacheId();
            $pid = $tacheProjet[$tid] ?? null;
            if ($fProjet !== null && $pid !== $fProjet) { continue; }
            $principal = $a->getPrincipal();
            // MÊME RÈGLE QUE LA FICHE D'UNE TÂCHE (« rollup ») : si des SOUS-tâches portent un budget pour
            // ce principal, leur cumul REMPLACE le budget propre de cette affectation. Sans cela, cet écran
            // comparait le réel de tout le sous-arbre à un budget de tâche parente : deux écrans, deux chiffres.
            $cumul = null;
            if ($pid !== null) {
                if (!isset($cumulParProjet[$pid])) { $cumulParProjet[$pid] = $this->affService->cumulParTache($pid); }
                $cumul = $cumulParProjet[$pid][$tid][$principal] ?? null;
            }
            $prevu = $cumul ?? $a->getHeuresPrevues();
            if ($prevu === null) { continue; }                    // sans budget, pas de dépassement calculable
            // En mode cumul, on compare des sous-tâches à des sous-tâches : les heures pointées sur la
            // tâche PARENTE elle-même sortent du réel (comme dans AffectationService::row()).
            $exclureTache = $cumul !== null ? $tid : null;
            // Filtre personne : ne garder que les affectations qui concernent la personne filtrée.
            if ($fPersonne !== null && !$this->access->isAssignedTo($fPersonne, $principal)) { continue; }
            // Équipe de l'affecté : un groupe → 'groupe' ; une personne → sa catégorie d'équipe.
            $isGroup = strpos($principal, AccessService::GROUP_PREFIX) === 0;
            $affTeam = $isGroup ? 'groupe' : $this->access->teamOf($principal);
            if ($fEquipe !== null && !$this->equipeMatch($affTeam, $fEquipe)) { continue; }
            // Réel cumulé sur le sous-arbre + détail par personne (parUser) et par (sous-)tâche.
            $reel = 0.0;
            $parUser = []; // uid => [ heures => total, taches => [ titre => heures ] ]
            foreach ($subtreeOf($tid) as $stid) {
                if ($stid === $exclureTache) { continue; }        // mode cumul : pas les heures de la tâche parente
                foreach ($reelParTacheUser[$stid] ?? [] as $u => $sum) {
                    if (!$this->access->isAssignedTo($u, $principal)) { continue; } // seulement les personnes concernées
                    $reel += $sum;
                    if (!isset($parUser[$u])) { $parUser[$u] = ['heures' => 0.0, 'taches' => []]; }
                    $parUser[$u]['heures'] += $sum;
                    $tt = $tacheTitre[$stid] ?? ('#' . $stid);
                    $parUser[$u]['taches'][$tt] = ($parUser[$u]['taches'][$tt] ?? 0) + $sum;
                }
            }
            $reel = round($reel, 2);
            if ($reel <= (float)$prevu) { continue; }              // pas de dépassement → on n'affiche pas
            // Mise en forme des contributeurs (qui a pointé combien, et sur quelles (sous-)tâches), triés décroissant.
            $contributeurs = [];
            foreach ($parUser as $u => $info) {
                $tRows = [];
                foreach ($info['taches'] as $tt => $h) { $tRows[] = ['tacheTitre' => $tt, 'heures' => round($h, 2)]; }
                usort($tRows, static fn ($x, $y) => $y['heures'] <=> $x['heures']);
                $contributeurs[] = ['uid' => $u, 'nom' => $this->nameOf($u), 'equipe' => $this->access->teamOf($u), 'heures' => round($info['heures'], 2), 'taches' => $tRows];
            }
            usort($contributeurs, static fn ($x, $y) => $y['heures'] <=> $x['heures']);
            $depassements[] = [
                'tacheId' => $tid,
                'tacheTitre' => $tacheTitre[$tid] ?? ('#' . $tid),
                'projetNom' => $pid !== null ? ($projetNom[$pid] ?? null) : null,
                'couleur' => $pid !== null ? ($projetCouleur[$pid] ?? null) : null,
                'nom' => $this->access->principalName($principal),
                'type' => $isGroup ? 'group' : 'user',
                'equipe' => $affTeam,
                'prevu' => round((float)$prevu, 2),
                'reel' => $reel,
                'ecart' => round($reel - (float)$prevu, 2),
                'contributeurs' => $contributeurs, // détail par personne + par (sous-)tâche
            ];
        }
        // Les plus gros dépassements d'abord (écart décroissant).
        usort($depassements, static fn ($a, $b) => $b['ecart'] <=> $a['ecart']);

        // 7) Listes de référence pour les menus de filtres (TOUJOURS complètes, non filtrées).
        //    Personnes = tous les utilisateurs NC, avec leur équipe ; projets = tous les projets.
        $refPersonnes = [];
        $access = $this->access; // variable locale : on appelle teamOf() dessus (méthode publique) sans toucher à une propriété privée depuis la closure
        $this->userManager->callForAllUsers(static function ($u) use (&$refPersonnes, $access) {
            $refPersonnes[] = ['uid' => $u->getUID(), 'nom' => $u->getDisplayName(), 'equipe' => $access->teamOf($u->getUID())];
        });
        $refProjets = array_map(static fn ($p) => ['id' => $p->getId(), 'nom' => $p->getNom()], $projets);

        // 8) DÉTAIL des saisies (chaque pointage), filtré comme le reste. On l'affiche sous forme de
        //    tableau et on le plafonne pour ne pas alourdir la réponse ; detailTotal donne le vrai total.
        $detail = $this->collectEntries($fPersonne, $fProjet, $fEquipe, $cutoffJour);
        $detailTotal = count($detail);

        // Réponse complète pour l'écran d'administration.
        return new DataResponse([
            'filtre' => ['personne' => $fPersonne, 'projet' => $fProjet, 'equipe' => $fEquipe, 'periode' => $periode],
            'refs' => ['personnes' => $refPersonnes, 'projets' => $refProjets],
            // Totaux par équipe + sous-total « l'association » (bénévole + salarié) et total général.
            'parEquipe' => [
                'benevole' => round($parEquipe['benevole'], 2),
                'salarie' => round($parEquipe['salarie'], 2),
                'pompier' => round($parEquipe['pompier'], 2),
                'autre' => round($parEquipe['autre'], 2),
                'temonia' => round($parEquipe['benevole'] + $parEquipe['salarie'], 2),
                'total' => round($total, 2),
            ],
            'parPersonne' => $personnes,
            'parProjet' => $projetsOut,
            'depassements' => $depassements, // affectations où le réel dépasse le budget prévu

            'detail' => array_slice($detail, 0, 800), // au plus 800 lignes affichées
            'detailTotal' => $detailTotal,
        ]);
    }

    // GET /api/admin/heures?personne=<uid> : toutes les saisies d'UNE personne (avec leur id, pour
    // pouvoir les corriger/supprimer). Réservé à l'admin — sert au sous-onglet « Pointer des heures ».
    #[NoAdminRequired]
    public function heures(?string $personne = null): DataResponse {
        if (!$this->access->isAdmin($this->uid())) {
            return new DataResponse(['message' => 'Réservé à l’administration.'], Http::STATUS_FORBIDDEN);
        }
        if ($personne === null || $personne === '') { return new DataResponse([]); }
        // Contexte (projet/phase/titre) de chaque tâche, pour afficher des lignes lisibles.
        $projetNom = [];
        $projets = $this->projets->findAll();
        foreach ($projets as $p) { $projetNom[$p->getId()] = $p->getNom(); }
        $tacheInfo = [];
        foreach ($projets as $p) {
            foreach ($this->taches->findByProjet($p->getId()) as $t) {
                $tacheInfo[$t->getId()] = ['projetId' => $t->getProjetId(), 'activiteId' => $t->getActiviteId(), 'titre' => $t->getTitre()];
            }
        }
        $actNom = [];
        $rows = [];
        foreach ($this->heures->findByUser($personne) as $h) {
            $info = $tacheInfo[$h->getTacheId()] ?? null;
            $aid = $info['activiteId'] ?? null;
            if ($aid && !array_key_exists($aid, $actNom)) {
                try { $actNom[$aid] = $this->activites->find($aid)->getNom(); } catch (\Throwable $e) { $actNom[$aid] = null; }
            }
            $rows[] = [
                'id' => $h->getId(),
                'jour' => (string)$h->getJour(),
                'heures' => round((float)$h->getHeures(), 2),
                'commentaire' => $h->getCommentaire(),
                'tacheId' => $h->getTacheId(),
                'tacheTitre' => $info['titre'] ?? null,
                'projetNom' => isset($info['projetId']) ? ($projetNom[$info['projetId']] ?? null) : null,
                'activiteNom' => $aid ? ($actNom[$aid] ?? null) : null,
            ];
        }
        return new DataResponse($rows);
    }

    // ---------- DÉTAIL DES SAISIES + EXPORT ----------

    /**
     * Construit la liste PLATE des saisies d'heures (une ligne = un pointage), filtrée.
     * Chaque ligne : jour, personne (+équipe), projet, phase, tâche, heures, commentaire.
     * Réutilisé par le tableau « Détail » et par les exports (Excel/Nextcloud) → une seule source.
     */
    private function collectEntries(?string $fPersonne, ?int $fProjet, ?string $fEquipe, ?string $cutoffJour): array {
        // Tables de référence : projet id → nom.
        $projetNom = [];
        $projets = $this->projets->findAll();
        foreach ($projets as $p) { $projetNom[$p->getId()] = $p->getNom(); }
        // Table tâche id → { projet, activité (phase), titre }.
        $tacheInfo = [];
        foreach ($projets as $p) {
            foreach ($this->taches->findByProjet($p->getId()) as $t) {
                $tacheInfo[$t->getId()] = ['projetId' => $t->getProjetId(), 'activiteId' => $t->getActiviteId(), 'titre' => $t->getTitre()];
            }
        }
        $ids = array_keys($tacheInfo);
        $actNom = [];     // cache id_activité → nom (chargé à la demande)
        $teamCache = [];  // cache uid → équipe
        $rows = [];
        // Une seule requête pour toutes les heures des tâches concernées.
        foreach ((empty($ids) ? [] : $this->heures->findByTaches($ids)) as $h) {
            $info = $tacheInfo[$h->getTacheId()] ?? null;
            $pid = $info['projetId'] ?? null;
            $jour = (string)$h->getJour();
            // Filtres : période, projet, personne, équipe (mêmes règles que l'agrégat).
            if ($cutoffJour !== null && $jour < $cutoffJour) { continue; }
            if ($fProjet !== null && $pid !== $fProjet) { continue; }
            $auteur = $h->getUserId();
            if (!isset($teamCache[$auteur])) { $teamCache[$auteur] = $this->access->teamOf($auteur); }
            if ($fPersonne !== null && $auteur !== $fPersonne) { continue; }
            if ($fEquipe !== null && !$this->equipeMatch($teamCache[$auteur], $fEquipe)) { continue; }
            // Nom de la phase (activité) de la tâche, mis en cache.
            $aid = $info['activiteId'] ?? null;
            if ($aid && !array_key_exists($aid, $actNom)) {
                try { $actNom[$aid] = $this->activites->find($aid)->getNom(); } catch (\Throwable $e) { $actNom[$aid] = null; }
            }
            $rows[] = [
                'jour' => $jour,
                'uid' => $auteur,
                'personne' => $this->nameOf($auteur),
                'equipe' => $teamCache[$auteur],
                'projetNom' => $pid !== null ? ($projetNom[$pid] ?? null) : null,
                'activiteNom' => $aid ? ($actNom[$aid] ?? null) : null,
                'tacheTitre' => $info['titre'] ?? null,
                'heures' => round((float)$h->getHeures(), 2),
                'commentaire' => $h->getCommentaire(),
            ];
        }
        // Tri : du jour le plus récent au plus ancien, puis par personne.
        usort($rows, static function ($a, $b) { $c = strcmp($b['jour'], $a['jour']); return $c !== 0 ? $c : strcmp($a['personne'], $b['personne']); });
        return $rows;
    }

    // Normalise les filtres bruts reçus (comme dans stats) → tableau [personne, projet, equipe, cutoffJour].
    private function normFilters(?string $personne, ?int $projet, ?string $equipe, ?int $periode): array {
        return [
            ($personne !== null && $personne !== '') ? $personne : null,
            ($projet !== null && $projet > 0) ? $projet : null,
            in_array($equipe, self::EQUIPE_FILTRES, true) ? $equipe : null,
            ($periode !== null && $periode > 0) ? date('Y-m-d', time() - $periode * 86400) : null,
        ];
    }

    // Traduit une catégorie d'équipe technique en libellé lisible (pour l'export), d'après les libellés réglables.
    private function equipeLabel(string $equipe): string {
        return in_array($equipe, ['benevole', 'salarie', 'pompier', 'autre'], true) ? $this->libelles->get($equipe) : $equipe;
    }

    /**
     * Fabrique le contenu d'un fichier CSV (ouvrable dans Excel) à partir des lignes de détail.
     * - BOM UTF-8 en tête : Excel affiche correctement les accents.
     * - Séparateur « ; » : le CSV français s'ouvre en colonnes dans Excel FR.
     */
    private function buildCsv(array $rows): string {
        $sep = ';';
        // Échappe un champ : guillemets doublés, et on entoure de guillemets s'il contient ; " ou saut de ligne.
        $esc = static function ($v) use ($sep) {
            $s = (string)($v ?? '');
            if (strpbrk($s, $sep . "\"\n\r") !== false) { $s = '"' . str_replace('"', '""', $s) . '"'; }
            return $s;
        };
        $lines = [];
        $lines[] = implode($sep, ['Date', 'Personne', 'Équipe', 'Projet', 'Phase', 'Tâche', 'Heures', 'Commentaire']);
        foreach ($rows as $r) {
            $lines[] = implode($sep, [
                $esc($r['jour']),
                $esc($r['personne']),
                $esc($this->equipeLabel((string)$r['equipe'])),
                $esc($r['projetNom']),
                $esc($r['activiteNom']),
                $esc($r['tacheTitre']),
                // Nombre à la française (virgule décimale) pour Excel FR.
                $esc(str_replace('.', ',', (string)$r['heures'])),
                $esc($r['commentaire']),
            ]);
        }
        // "\xEF\xBB\xBF" = BOM UTF-8 ; "\r\n" = fins de ligne attendues par Excel.
        return "\xEF\xBB\xBF" . implode("\r\n", $lines) . "\r\n";
    }

    // Nom de fichier d'export daté (ex. heures-2026-09-03_142530.xlsx).
    private function exportFilename(string $ext): string {
        return 'heures-' . date('Y-m-d_His') . '.' . $ext;
    }

    // Charge (au besoin) l'autoloader Composer de l'app et dit si PhpSpreadsheet est disponible.
    // Nextcloud inclut normalement <app>/vendor/autoload.php tout seul ; ce require est une ceinture
    // de sécurité au cas où. Si la lib manque, on retombera sur l'export CSV.
    private function xlsxAvailable(): bool {
        if (!class_exists(\PhpOffice\PhpSpreadsheet\Spreadsheet::class)) {
            $auto = __DIR__ . '/../../vendor/autoload.php'; // lib/Controller → racine de l'app → vendor/
            if (is_file($auto)) { require_once $auto; }
        }
        return class_exists(\PhpOffice\PhpSpreadsheet\Spreadsheet::class);
    }

    /**
     * Fabrique le contenu d'un vrai fichier Excel .xlsx à partir des lignes de détail (PhpSpreadsheet).
     * En-tête en gras + ligne figée, colonnes auto-ajustées, colonne Heures en nombre.
     */
    private function buildXlsx(array $rows): string {
        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Heures');
        // Ligne d'en-tête.
        $headers = ['Date', 'Personne', 'Équipe', 'Projet', 'Phase', 'Tâche', 'Heures', 'Commentaire'];
        $sheet->fromArray($headers, null, 'A1');
        $sheet->getStyle('A1:H1')->getFont()->setBold(true);
        $sheet->freezePane('A2'); // la 1re ligne reste visible en défilant
        // Lignes de données.
        $r = 2;
        foreach ($rows as $row) {
            $sheet->fromArray([
                $row['jour'],
                $row['personne'],
                $this->equipeLabel((string)$row['equipe']),
                $row['projetNom'],
                $row['activiteNom'],
                $row['tacheTitre'],
                (float)$row['heures'], // vrai nombre (pas de texte) → calculs possibles dans Excel
                $row['commentaire'],
            ], null, 'A' . $r);
            $r++;
        }
        // Largeur automatique des colonnes A..H.
        foreach (range('A', 'H') as $col) { $sheet->getColumnDimension($col)->setAutoSize(true); }
        // On écrit le classeur dans un tampon mémoire et on renvoie son contenu binaire.
        $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
        ob_start();
        $writer->save('php://output');
        return (string)ob_get_clean();
    }

    /**
     * GET /api/admin/export : TÉLÉCHARGE les saisies filtrées en CSV (Excel).
     * NoCSRFRequired car on l'ouvre par navigation directe (window.open) ; la session protège l'accès.
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function exportDownload(?string $personne = null, ?int $projet = null, ?string $equipe = null, ?int $periode = null): DataDownloadResponse {
        if (!$this->access->isAdmin($this->uid())) {
            // Réservé à l'admin : on renvoie un petit fichier d'erreur plutôt qu'un contenu sensible.
            return new DataDownloadResponse('Accès refusé.', 'erreur.txt', 'text/plain; charset=utf-8');
        }
        [$fP, $fPr, $fE, $cut] = $this->normFilters($personne, $projet, $equipe, $periode);
        $rows = $this->collectEntries($fP, $fPr, $fE, $cut);
        // Vrai .xlsx si PhpSpreadsheet est là, sinon CSV (ouvrable Excel aussi) en repli.
        if ($this->xlsxAvailable()) {
            return new DataDownloadResponse($this->buildXlsx($rows), $this->exportFilename('xlsx'), 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        }
        return new DataDownloadResponse($this->buildCsv($rows), $this->exportFilename('csv'), 'text/csv; charset=utf-8');
    }

    /**
     * POST /api/admin/export : ENREGISTRE les saisies filtrées en CSV dans les Fichiers Nextcloud
     * de la direction, dans le dossier « l'association - Exports ». Renvoie le chemin du fichier créé.
     */
    #[NoAdminRequired]
    public function exportNextcloud(?string $personne = null, ?int $projet = null, ?string $equipe = null, ?int $periode = null): DataResponse {
        if (!$this->access->isAdmin($this->uid())) {
            return new DataResponse(['message' => 'Réservé à l’administration.'], Http::STATUS_FORBIDDEN);
        }
        [$fP, $fPr, $fE, $cut] = $this->normFilters($personne, $projet, $equipe, $periode);
        $rows = $this->collectEntries($fP, $fPr, $fE, $cut);
        // Vrai .xlsx si disponible, sinon CSV en repli.
        if ($this->xlsxAvailable()) {
            $content = $this->buildXlsx($rows);
            $name = $this->exportFilename('xlsx');
        } else {
            $content = $this->buildCsv($rows);
            $name = $this->exportFilename('csv');
        }
        try {
            // Dossier personnel de l'utilisateur, puis sous-dossier « <Organisation> - Exports » (créé au besoin).
            $userFolder = $this->rootFolder->getUserFolder($this->uid());
            $dirName = $this->libelles->get('dossier_exports');
            $dir = $userFolder->nodeExists($dirName) ? $userFolder->get($dirName) : $userFolder->newFolder($dirName);
            // On (ré)écrit le fichier : newFile s'il n'existe pas, sinon putContent sur l'existant.
            $file = $dir->nodeExists($name) ? $dir->get($name) : $dir->newFile($name);
            $file->putContent($content);
            return new DataResponse(['path' => $dirName . '/' . $name]);
        } catch (\Throwable $e) {
            return new DataResponse(['message' => 'Échec de l’enregistrement dans Nextcloud.'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }
}
