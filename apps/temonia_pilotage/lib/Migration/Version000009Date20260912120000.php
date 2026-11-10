<?php
// SPDX-FileCopyrightText: 2026 Flavien Auvray
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\TemoniaPilotage\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Crée les 3 tables du module FRAIS (notes de frais) :
 *
 *  - `tp_frais_types`  : les TYPES de frais (km, hébergement/restauration, carburant…).
 *                         Liste gérée par l'admin ; le type « km » est spécial (montant = km × barème).
 *  - `tp_frais_themes` : les THÉMATIQUES internes hors projet (Assemblée générale, Conseil
 *                         d'administration, Vie interne…). Un frais se rattache soit à un PROJET
 *                         (table tp_projets existante), soit à une THÉMATIQUE — jamais les deux.
 *  - `tp_frais`        : les notes de frais elles-mêmes (une ligne = un ticket / un déplacement).
 *
 * Cycle de vie d'un frais (colonne `statut`) :
 *   soumis → valide → rembourse   (chemin normal)
 *   soumis → refuse               (avec un motif dans `commentaire_admin`)
 * Seul un admin Nextcloud change le statut ; l'auteur ne peut modifier/supprimer QUE tant
 * que le frais est « soumis ».
 */
class Version000009Date20260912120000 extends SimpleMigrationStep {
    private IDBConnection $db;

    // La connexion BD est injectée pour pouvoir insérer les valeurs de départ (« seeds ») après
    // la création des tables (voir postSchemaChange). On ne peut pas le faire dans changeSchema,
    // qui ne décrit que la STRUCTURE.
    public function __construct(IDBConnection $db) {
        $this->db = $db;
    }

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        // ---- Types de frais ----
        if (!$schema->hasTable('tp_frais_types')) {
            $t = $schema->createTable('tp_frais_types');
            $t->addColumn('id', 'integer', ['autoincrement' => true, 'notnull' => true]);
            // code = identifiant technique STABLE (ex. "hebergement_restauration") : c'est lui qui est
            // stocké sur chaque frais. Le label, lui, peut être renommé librement par l'admin.
            $t->addColumn('code', 'string', ['notnull' => true, 'length' => 32]);
            $t->addColumn('label', 'string', ['notnull' => true, 'length' => 128]);
            // est_km = vrai pour le type « kilométrique » : le formulaire demande des km, pas un montant.
            $t->addColumn('est_km', 'boolean', ['notnull' => false, 'default' => false]);
            // actif = faux → le type n'est plus proposé pour un NOUVEAU frais (les anciens le gardent).
            $t->addColumn('actif', 'boolean', ['notnull' => false, 'default' => true]);
            $t->addColumn('ordre', 'integer', ['notnull' => true, 'default' => 0]);
            $t->setPrimaryKey(['id']);
            // Index UNIQUE : deux types ne peuvent pas partager le même code.
            $t->addUniqueIndex(['code'], 'tp_frt_code_idx');
        }

        // ---- Thématiques internes (hors projet) ----
        if (!$schema->hasTable('tp_frais_themes')) {
            $t = $schema->createTable('tp_frais_themes');
            $t->addColumn('id', 'integer', ['autoincrement' => true, 'notnull' => true]);
            $t->addColumn('label', 'string', ['notnull' => true, 'length' => 128]);
            $t->addColumn('actif', 'boolean', ['notnull' => false, 'default' => true]);
            $t->addColumn('ordre', 'integer', ['notnull' => true, 'default' => 0]);
            $t->setPrimaryKey(['id']);
        }

        // ---- Notes de frais ----
        if (!$schema->hasTable('tp_frais')) {
            $t = $schema->createTable('tp_frais');
            $t->addColumn('id', 'integer', ['autoincrement' => true, 'notnull' => true]);
            // numero = "AAAA-NNN" (ex. 2026-001), unique, calculé côté serveur à la création.
            $t->addColumn('numero', 'string', ['notnull' => true, 'length' => 16]);
            $t->addColumn('user_id', 'string', ['notnull' => true, 'length' => 64]);   // auteur (uid NC)
            $t->addColumn('date_frais', 'date', ['notnull' => true]);                   // date de la dépense
            // Rattachement : UN des deux est rempli (projet OU thématique), l'autre reste NULL.
            $t->addColumn('projet_id', 'integer', ['notnull' => false]);
            $t->addColumn('theme_id', 'integer', ['notnull' => false]);
            $t->addColumn('type_code', 'string', ['notnull' => true, 'length' => 32]); // → tp_frais_types.code
            // Montants. TTC = ce qui a été payé (c'est ce qu'on rembourse). HT et TVA sont informatifs.
            $t->addColumn('montant_ttc', 'float', ['notnull' => true, 'default' => 0]);
            $t->addColumn('montant_ht', 'float', ['notnull' => false]);
            $t->addColumn('tva', 'float', ['notnull' => false]);
            // Frais kilométriques : distance + barème APPLIQUÉ (copié au moment de la saisie, pour que
            // changer le barème plus tard ne modifie pas les anciens frais).
            $t->addColumn('km', 'float', ['notnull' => false]);
            $t->addColumn('bareme_km', 'float', ['notnull' => false]);
            $t->addColumn('lieu', 'string', ['notnull' => false, 'length' => 255]);
            $t->addColumn('fournisseur', 'string', ['notnull' => false, 'length' => 255]);
            // objet = le « pourquoi » de la dépense, OBLIGATOIRE et jamais rempli par l'IA (choix humain).
            $t->addColumn('objet', 'string', ['notnull' => true, 'length' => 500]);
            $t->addColumn('commentaire', 'text', ['notnull' => false]);
            // Justificatif (photo/PDF) : stocké dans l'espace privé de l'app (appdata), pas en base.
            // On ne garde ici que le nom du fichier et son type MIME.
            $t->addColumn('photo_name', 'string', ['notnull' => false, 'length' => 255]);
            $t->addColumn('photo_mime', 'string', ['notnull' => false, 'length' => 64]);
            // Workflow.
            $t->addColumn('statut', 'string', ['notnull' => true, 'length' => 16, 'default' => 'soumis']);
            $t->addColumn('commentaire_admin', 'string', ['notnull' => false, 'length' => 500]);
            $t->addColumn('validated_at', 'bigint', ['notnull' => false]);
            $t->addColumn('validated_by', 'string', ['notnull' => false, 'length' => 64]);
            $t->addColumn('date_remboursement', 'date', ['notnull' => false]);
            $t->addColumn('remboursement_mode', 'string', ['notnull' => false, 'length' => 32]);
            // Réponse BRUTE de l'IA (JSON) : utile pour comprendre un pré-remplissage bizarre.
            $t->addColumn('scan_json', 'text', ['notnull' => false]);
            $t->addColumn('created_at', 'bigint', ['notnull' => true, 'default' => 0]);
            $t->addColumn('updated_at', 'bigint', ['notnull' => true, 'default' => 0]);
            $t->setPrimaryKey(['id']);
            // L'index unique sur numero est la VRAIE garantie contre les doublons de numéro
            // (deux personnes qui enregistrent au même instant) : la BD refuse le second insert.
            $t->addUniqueIndex(['numero'], 'tp_fr_numero_idx');
            $t->addIndex(['user_id'], 'tp_fr_user_idx');       // « mes frais »
            $t->addIndex(['statut'], 'tp_fr_statut_idx');      // « à valider », « à rembourser »
            $t->addIndex(['projet_id'], 'tp_fr_projet_idx');   // frais d'un projet
            $t->addIndex(['date_frais'], 'tp_fr_date_idx');    // filtres par période
        }

        return $schema;
    }

    // postSchemaChange s'exécute APRÈS que les tables existent : on y insère les valeurs de départ.
    // Idempotent : on n'insère que si la table est VIDE (rejouer la migration ne duplique rien).
    public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
        if ($this->isEmpty('tp_frais_types')) {
            // [ordre, code, label, est_km]. Les codes carburant/transport correspondent aux types que
            // l'IA sait reconnaître sur un ticket (voir VisionService) : on les propose donc d'emblée.
            $types = [
                [10, 'km', 'Frais kilométriques', true],
                [20, 'hebergement_restauration', 'Hébergement / Restauration', false],
                [30, 'carburant', 'Carburant', false],
                [40, 'transport', 'Transport (train, péage, parking…)', false],
                [50, 'frais_divers', 'Frais divers', false],
                [60, 'autre', 'Autre', false],
            ];
            foreach ($types as $row) {
                $qb = $this->db->getQueryBuilder();
                $qb->insert('tp_frais_types')->values([
                    'ordre' => $qb->createNamedParameter($row[0], \PDO::PARAM_INT),
                    'code' => $qb->createNamedParameter($row[1]),
                    'label' => $qb->createNamedParameter($row[2]),
                    'est_km' => $qb->createNamedParameter($row[3], \PDO::PARAM_BOOL),
                    'actif' => $qb->createNamedParameter(true, \PDO::PARAM_BOOL),
                ])->executeStatement();
            }
            $output->info('Types de frais initialisés.');
        }
        if ($this->isEmpty('tp_frais_themes')) {
            $themes = [[10, 'Assemblée générale'], [20, 'Conseil d’administration'], [30, 'Vie interne'], [40, 'Déplacement interne']];
            foreach ($themes as $row) {
                $qb = $this->db->getQueryBuilder();
                $qb->insert('tp_frais_themes')->values([
                    'ordre' => $qb->createNamedParameter($row[0], \PDO::PARAM_INT),
                    'label' => $qb->createNamedParameter($row[1]),
                    'actif' => $qb->createNamedParameter(true, \PDO::PARAM_BOOL),
                ])->executeStatement();
            }
            $output->info('Thématiques de frais initialisées.');
        }
    }

    // Vrai si la table ne contient aucune ligne. (Le query builder ajoute lui-même le préfixe « oc_ ».)
    private function isEmpty(string $table): bool {
        $qb = $this->db->getQueryBuilder();
        $qb->select($qb->func()->count('id', 'n'))->from($table);
        $n = (int)$qb->executeQuery()->fetchOne();
        return $n === 0;
    }
}
