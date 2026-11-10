<?php
// SPDX-FileCopyrightText: 2026 Flavien Auvray
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\TemoniaPilotage\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Accès base de données aux notes de frais (table `tp_frais`).
 * Même patron que HeureMapper : toujours createNamedParameter (jamais une valeur
 * collée dans la requête → protège des injections SQL).
 * @extends QBMapper<Frais>
 */
class FraisMapper extends QBMapper {
    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'tp_frais', Frais::class);
    }

    public function find(int $id): Frais {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')->from($this->getTableName())
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
        return $this->findEntity($qb);
    }

    /**
     * MES frais, optionnellement limités à une année civile (annee = null ou 0 → tout).
     * Plus récent d'abord.
     * @return Frais[]
     */
    public function findByUser(string $uid, ?int $annee = null): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')->from($this->getTableName())
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($uid)));
        $this->filtreAnnee($qb, $annee);
        $qb->orderBy('date_frais', 'DESC')->addOrderBy('id', 'DESC');
        return $this->findEntities($qb);
    }

    /**
     * Recherche multi-critères pour l'ADMINISTRATION. Chaque critère est facultatif :
     * null/'' = « pas de filtre sur ce champ ». (L'équipe bénévole/salarié se filtre ensuite
     * en PHP, car elle dépend des groupes Nextcloud et non d'une colonne.)
     * @return Frais[]
     */
    public function findFiltered(?int $annee, ?string $statut, ?string $uid, ?int $projetId, ?int $themeId, int $limit = 2000): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')->from($this->getTableName());
        // Astuce : une condition « toujours vraie » pour pouvoir enchaîner des andWhere sans se soucier
        // de savoir laquelle est la première.
        $qb->where($qb->expr()->eq($qb->createNamedParameter(1, IQueryBuilder::PARAM_INT), $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT)));
        $this->filtreAnnee($qb, $annee);
        if ($statut !== null && $statut !== '') {
            $qb->andWhere($qb->expr()->eq('statut', $qb->createNamedParameter($statut)));
        }
        if ($uid !== null && $uid !== '') {
            $qb->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($uid)));
        }
        if ($projetId !== null && $projetId > 0) {
            $qb->andWhere($qb->expr()->eq('projet_id', $qb->createNamedParameter($projetId, IQueryBuilder::PARAM_INT)));
        }
        if ($themeId !== null && $themeId > 0) {
            $qb->andWhere($qb->expr()->eq('theme_id', $qb->createNamedParameter($themeId, IQueryBuilder::PARAM_INT)));
        }
        $qb->orderBy('date_frais', 'DESC')->addOrderBy('id', 'DESC')->setMaxResults($limit);
        return $this->findEntities($qb);
    }

    // Ajoute « date_frais entre le 1er janvier et le 31 décembre de l'année » si une année est donnée.
    // Les dates étant au format AAAA-MM-JJ, une comparaison de chaînes suffit (et marche partout).
    private function filtreAnnee(IQueryBuilder $qb, ?int $annee): void {
        if ($annee === null || $annee <= 0) {
            return;
        }
        $qb->andWhere($qb->expr()->gte('date_frais', $qb->createNamedParameter($annee . '-01-01')))
            ->andWhere($qb->expr()->lte('date_frais', $qb->createNamedParameter($annee . '-12-31')));
    }

    /**
     * Le plus grand numéro déjà attribué pour une année ("2026-017"), ou null s'il n'y en a aucun.
     * Sert à calculer le prochain numéro (voir FraisService::nextNumero).
     */
    public function maxNumeroForYear(int $annee): ?string {
        $qb = $this->db->getQueryBuilder();
        // LIKE '2026-%' : tous les numéros qui COMMENCENT par l'année. MAX() sur des chaînes de même
        // longueur ("AAAA-NNN") donne bien le plus grand numéro.
        $qb->select($qb->func()->max('numero'))->from($this->getTableName())
            ->where($qb->expr()->like('numero', $qb->createNamedParameter($annee . '-%')));
        $v = $qb->executeQuery()->fetchOne();
        return ($v === false || $v === null || $v === '') ? null : (string)$v;
    }

    /** Nombre de frais utilisant un type donné (pour refuser la suppression d'un type utilisé). */
    public function countByType(string $code): int {
        $qb = $this->db->getQueryBuilder();
        $qb->select($qb->func()->count('id', 'n'))->from($this->getTableName())
            ->where($qb->expr()->eq('type_code', $qb->createNamedParameter($code)));
        return (int)$qb->executeQuery()->fetchOne();
    }

    /** Nombre de frais rattachés à une thématique donnée (même usage). */
    public function countByTheme(int $themeId): int {
        $qb = $this->db->getQueryBuilder();
        $qb->select($qb->func()->count('id', 'n'))->from($this->getTableName())
            ->where($qb->expr()->eq('theme_id', $qb->createNamedParameter($themeId, IQueryBuilder::PARAM_INT)));
        return (int)$qb->executeQuery()->fetchOne();
    }

    /** Nombre de frais dans un statut donné (ex. « soumis » → pastille « à valider » de l'admin). */
    public function countByStatut(string $statut): int {
        $qb = $this->db->getQueryBuilder();
        $qb->select($qb->func()->count('id', 'n'))->from($this->getTableName())
            ->where($qb->expr()->eq('statut', $qb->createNamedParameter($statut)));
        return (int)$qb->executeQuery()->fetchOne();
    }
}
