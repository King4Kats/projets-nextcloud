<?php
// SPDX-FileCopyrightText: 2026 Flavien Auvray
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\TemoniaPilotage\Service;

use OCA\TemoniaPilotage\Db\Affectation;
use OCA\TemoniaPilotage\Db\AffectationMapper;
use OCA\TemoniaPilotage\Db\HeureMapper;
use OCA\TemoniaPilotage\Db\TacheMapper;

/**
 * Calculs autour des AFFECTATIONS (personnes/groupes affectés à une tâche, avec leurs
 * heures prévues). Centralisé ici pour ne PAS dupliquer la même logique dans les trois
 * endroits qui en ont besoin : la fiche tâche (BoardController), la gestion des
 * affectations (ManageController) et le tableau d'administration (AdminController).
 *
 * RÈGLE IMPORTANTE (« rollup ») : le réel d'une affectation se cumule sur TOUT le
 * sous-arbre de la tâche (elle-même + toutes ses sous-tâches en profondeur). Ainsi, si
 * on affecte un budget d'heures sur une tâche PARENTE, son réel additionne les heures
 * pointées sur ses sous-tâches (les heures se pointent sur les feuilles) — sinon un
 * parent afficherait toujours 0 h.
 */
class AffectationService {
    private AffectationMapper $affectations;
    private HeureMapper $heures;
    private TacheMapper $taches;
    private AccessService $access;

    public function __construct(AffectationMapper $affectations, HeureMapper $heures, TacheMapper $taches, AccessService $access) {
        $this->affectations = $affectations;
        $this->heures = $heures;
        $this->taches = $taches;
        $this->access = $access;
    }

    /**
     * Ids de la tâche $tacheId ET de toute sa descendance (sous-arbre complet), dans $projetId.
     * On construit d'abord la table parentId → [ids enfants], puis on descend en pile (sans
     * récursion) depuis la tâche de départ. « ?: 0 » range les tâches racines sous la clé 0.
     * @return int[]
     */
    public function subtreeIds(int $tacheId, int $projetId): array {
        $childrenOf = [];
        foreach ($this->taches->findByProjet($projetId) as $t) {
            $childrenOf[$t->getParentId() ?: 0][] = $t->getId();
        }
        $ids = [];
        $pile = [$tacheId];                 // on part de la tâche visée
        while ($pile) {
            $cur = array_pop($pile);        // on dépile une tâche
            $ids[] = $cur;                  // on la garde
            foreach ($childrenOf[$cur] ?? [] as $enfant) { $pile[] = $enfant; } // et on empile ses enfants
        }
        return $ids;
    }

    /**
     * Réel d'un principal (personne/groupe) sur un lot d'heures : somme des heures des
     * personnes concernées. isAssignedTo gère les deux cas :
     *   - "claire"          → seules les heures de Claire comptent ;
     *   - "group:Partenaires" → toutes les heures des membres du groupe comptent.
     * @param \OCA\l'associationPilotage\Db\Heure[] $heuresRows
     */
    public function reelPrincipal(array $heuresRows, string $principal): float {
        $somme = 0.0;
        foreach ($heuresRows as $h) {
            if ($this->access->isAssignedTo($h->getUserId(), $principal)) {
                $somme += (float)$h->getHeures();
            }
        }
        return $somme;
    }

    /**
     * Somme des heures prévues des affectations, PAR tâche (map tacheId => heures). Seules les
     * tâches ayant AU MOINS une affectation figurent dans le résultat. C'est le « prévu effectif »
     * d'une tâche affectée : quand on affecte des personnes/groupes, le prévu de la tâche devient
     * le CUMUL de leurs heures (et non l'estimation manuelle saisie autrefois). Une tâche sans
     * affectation n'apparaît pas ici → on retombe alors sur son heuresPrevues manuel.
     * @param int[] $tacheIds
     * @return array<int,float>
     */
    public function budgetByTaches(array $tacheIds): array {
        $out = [];
        foreach ($this->affectations->findByTaches($tacheIds) as $a) {
            $out[$a->getTacheId()] = ($out[$a->getTacheId()] ?? 0) + (float)($a->getHeuresPrevues() ?? 0);
        }
        return $out;
    }

    /** Prévu effectif d'UNE tâche (feuille) : cumul de ses affectations si elle en a, sinon $manuel. */
    public function prevuEffectifFeuille(int $tacheId, ?float $manuel, array $budgetMap): float {
        return array_key_exists($tacheId, $budgetMap) ? (float)$budgetMap[$tacheId] : (float)($manuel ?? 0);
    }

    /** Vrai si $principal désigne une personne existante OU un groupe existant. */
    public function principalValide(string $principal): bool {
        // On délègue à AccessService la connaissance du préfixe "group:" (une seule source de vérité).
        return $this->access->principalExiste($principal);
    }

    /**
     * Cumul des heures prévues des SOUS-tâches, par principal (personne ou groupe).
     * Sert au « rollup » du prévu : sur une tâche PARENTE, le prévu affiché pour un principal
     * = somme des prévus EFFECTIFS de ses sous-tâches DIRECTES. La présence de la clé signifie
     * qu'au moins une sous-tâche porte un budget pour ce principal.
     *
     * Prévu EFFECTIF d'une tâche pour un principal = le cumul de SES propres sous-tâches s'il y
     * en a un, sinon son budget à elle. On remonte donc niveau par niveau.
     *
     * Pourquoi pas une simple somme de toute la descendance ? Parce qu'une sous-tâche
     * intermédiaire peut porter un budget ET avoir des sous-tâches budgétées : on compterait
     * deux fois les mêmes heures (bug corrigé le 2026-09-17 : 82 h au lieu de 39 h).
     *
     * @param int[] $subtreeIds ids de la tâche + toute sa descendance
     * @param int   $selfId     id de la tâche visée (racine du sous-arbre)
     * @param int   $projetId   projet de la tâche (pour retrouver qui est l'enfant de qui)
     * @return array<string,float>
     */
    private function descendantBudgets(array $subtreeIds, int $selfId, int $projetId): array {
        // 1) Qui est l'enfant de qui (table parentId → [ids enfants]).
        $childrenOf = [];
        foreach ($this->taches->findByProjet($projetId) as $t) {
            $childrenOf[$t->getParentId() ?: 0][] = $t->getId();
        }
        // 2) Budgets PROPRES de chaque tâche du sous-arbre : $own[tacheId][principal] = heures.
        $own = [];
        foreach ($this->affectations->findByTaches($subtreeIds) as $a) {
            if ($a->getHeuresPrevues() === null) { continue; }  // affectation sans budget : ne compte pas
            $tid = $a->getTacheId();
            $p = $a->getPrincipal();
            $own[$tid][$p] = ($own[$tid][$p] ?? 0) + (float)$a->getHeuresPrevues();
        }
        // 3) Prévu effectif d'une tâche, par principal (fonction récursive, mémorisée).
        $memo = [];
        $effectif = function (int $tid) use (&$effectif, &$memo, $childrenOf, $own): array {
            if (isset($memo[$tid])) { return $memo[$tid]; }
            // Cumul des sous-tâches directes (chacune avec SON prévu effectif).
            $cumulEnfants = [];
            foreach ($childrenOf[$tid] ?? [] as $enfant) {
                foreach ($effectif($enfant) as $p => $h) { $cumulEnfants[$p] = ($cumulEnfants[$p] ?? 0) + $h; }
            }
            // Pour un principal : le cumul des sous-tâches REMPLACE le budget propre ; sinon on garde le propre.
            $res = $own[$tid] ?? [];
            foreach ($cumulEnfants as $p => $h) { $res[$p] = $h; }
            return $memo[$tid] = $res;
        };
        // 4) Résultat : somme des prévus effectifs des sous-tâches DIRECTES de la tâche visée.
        $out = [];
        foreach ($childrenOf[$selfId] ?? [] as $enfant) {
            foreach ($effectif($enfant) as $p => $h) { $out[$p] = ($out[$p] ?? 0) + $h; }
        }
        return $out;
    }

    /**
     * MÊME RÈGLE que descendantBudgets, mais pour TOUTES les tâches d'un projet d'un coup :
     * renvoie [tacheId => [principal => heures]] — le cumul des sous-tâches DIRECTES de chaque tâche.
     * Une tâche absente du résultat (ou un principal absent de sa ligne) n'a pas de sous-tâche budgétée
     * pour ce principal : c'est alors son budget PROPRE qui compte.
     *
     * Pourquoi cette méthode en plus ? L'écran d'administration doit afficher les dépassements de
     * BEAUCOUP d'affectations à la fois. Appeler descendantBudgets pour chacune relirait tout le projet
     * autant de fois ; ici, on lit le projet UNE fois et on calcule tout l'arbre d'un coup.
     *
     * @return array<int,array<string,float>>
     */
    public function cumulParTache(int $projetId): array {
        // 1) Qui est l'enfant de qui, et la liste de toutes les tâches du projet.
        $childrenOf = [];
        $toutes = [];
        foreach ($this->taches->findByProjet($projetId) as $t) {
            $childrenOf[$t->getParentId() ?: 0][] = $t->getId();
            $toutes[] = $t->getId();
        }
        // 2) Budgets PROPRES de chaque tâche : $own[tacheId][principal] = heures.
        $own = [];
        foreach ($this->affectations->findByTaches($toutes) as $a) {
            if ($a->getHeuresPrevues() === null) { continue; }
            $own[$a->getTacheId()][$a->getPrincipal()] = ($own[$a->getTacheId()][$a->getPrincipal()] ?? 0) + (float)$a->getHeuresPrevues();
        }
        // 3) Prévu effectif d'une tâche (récursif, mémorisé) : le cumul des enfants REMPLACE le propre.
        $memo = [];
        $effectif = function (int $tid) use (&$effectif, &$memo, $childrenOf, $own): array {
            if (isset($memo[$tid])) { return $memo[$tid]; }
            $cumulEnfants = [];
            foreach ($childrenOf[$tid] ?? [] as $enfant) {
                foreach ($effectif($enfant) as $p => $h) { $cumulEnfants[$p] = ($cumulEnfants[$p] ?? 0) + $h; }
            }
            $res = $own[$tid] ?? [];
            foreach ($cumulEnfants as $p => $h) { $res[$p] = $h; }
            return $memo[$tid] = $res;
        };
        // 4) Pour chaque tâche : somme des prévus effectifs de ses sous-tâches DIRECTES (vide si feuille).
        $out = [];
        foreach ($toutes as $tid) {
            $cumul = [];
            foreach ($childrenOf[$tid] ?? [] as $enfant) {
                foreach ($effectif($enfant) as $p => $h) { $cumul[$p] = ($cumul[$p] ?? 0) + $h; }
            }
            if ($cumul) { $out[$tid] = $cumul; }
        }
        return $out;
    }

    /**
     * Toutes les affectations d'une tâche, mises en forme pour le front (nom, type, prévu,
     * réel cumulé sur le sous-arbre, dépassement, écart). Charge les heures du sous-arbre 1 fois,
     * et le cumul des budgets des sous-tâches (pour le rollup du prévu sur une tâche parente).
     */
    public function rows(int $tacheId, int $projetId): array {
        $subtree = $this->subtreeIds($tacheId, $projetId);
        $heuresSousArbre = $this->heures->findByTaches($subtree);
        $descBudget = $this->descendantBudgets($subtree, $tacheId, $projetId);
        $out = [];
        foreach ($this->affectations->findByTache($tacheId) as $a) {
            $out[] = $this->row($a, $heuresSousArbre, $descBudget);
        }
        return $out;
    }

    /** Met en forme UNE affectation (après une création/màj) : recharge heures + cumul du sous-arbre. */
    public function rowForAffectation(Affectation $a, int $projetId): array {
        $subtree = $this->subtreeIds($a->getTacheId(), $projetId);
        $heuresSousArbre = $this->heures->findByTaches($subtree);
        $descBudget = $this->descendantBudgets($subtree, $a->getTacheId(), $projetId);
        return $this->row($a, $heuresSousArbre, $descBudget);
    }

    /** Fabrique la ligne JSON d'une affectation à partir d'un lot d'heures déjà chargé. */
    public function row(Affectation $a, array $heuresSousArbre, array $descBudget = []): array {
        $principal = $a->getPrincipal();
        $own = $a->getHeuresPrevues();
        // « Rollup » du prévu : si des SOUS-tâches portent un budget pour ce principal, le prévu
        // affiché est LEUR CUMUL (valeur en lecture seule : on ajuste alors le budget sur les
        // sous-tâches). Sinon, on affiche/édite le budget PROPRE de cette affectation.
        $cumul = array_key_exists($principal, $descBudget);
        // Réel COHÉRENT avec le prévu affiché : en mode cumul (« ∑ sous-tâches »), on compare des
        // sous-tâches à des sous-tâches → on EXCLUT les heures pointées sur la tâche parente elle-même
        // (cas d'une tâche pointée avant d'avoir des sous-tâches). Sinon, tout le sous-arbre compte.
        $selfId = $a->getTacheId();
        $rows = $cumul ? array_filter($heuresSousArbre, static fn ($h) => $h->getTacheId() !== $selfId) : $heuresSousArbre;
        $reel = round($this->reelPrincipal($rows, $principal), 2);
        $effectif = $cumul ? (float)$descBudget[$principal] : ($own !== null ? (float)$own : null);
        return [
            'id' => $a->getId(),
            'tacheId' => $a->getTacheId(),
            'principal' => $principal,
            'type' => (strpos($principal, AccessService::GROUP_PREFIX) === 0 || $principal === AccessService::TEAM_TEMONIA) ? 'group' : 'user',
            'nom' => $this->access->principalName($principal),
            'heuresPrevues' => $own,                // budget PROPRE de l'affectation (base de l'édition)
            'heuresPrevuesEffectif' => $effectif,   // budget AFFICHÉ (propre, ou cumul des sous-tâches)
            'cumulSousTaches' => $cumul,            // vrai = valeur issue du cumul des sous-tâches (lecture seule)
            'reel' => $reel,
            // Dépassement = un budget effectif est fixé ET le réel le dépasse. ecart = heures au-dessus (négatif = marge).
            'depassement' => ($effectif !== null && $reel > $effectif),
            'ecart' => $effectif !== null ? round($reel - $effectif, 2) : null,
        ];
    }
}
