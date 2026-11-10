<?php
// SPDX-FileCopyrightText: 2026 Flavien Auvray
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\TemoniaPilotage\Db;

use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * Une tâche (table `tp_taches`). Peut être une sous-tâche via `parentId`
 * (arbre de profondeur illimitée). `projetId` est dénormalisé (recopié sur
 * chaque niveau) pour interroger tout un projet en une requête. `statut`
 * vaut a_faire | en_cours | fait ; `heuresPrevues` est l'estimation.
 *
 * @method int getProjetId()
 * @method void setProjetId(int $projetId)
 * @method int|null getActiviteId()
 * @method void setActiviteId(?int $activiteId)
 * @method int|null getParentId()
 * @method void setParentId(?int $parentId)
 * @method string getTitre()
 * @method void setTitre(string $titre)
 * @method string|null getDescription()
 * @method void setDescription(?string $description)
 * @method float|null getHeuresPrevues()
 * @method void setHeuresPrevues(?float $heuresPrevues)
 * @method string|null getAssignee()
 * @method void setAssignee(?string $assignee)
 * @method string getStatut()
 * @method void setStatut(string $statut)
 * @method int|null getDeckCardId()
 * @method void setDeckCardId(?int $deckCardId)
 * @method string|null getDateDebut()
 * @method void setDateDebut(?string $dateDebut)
 * @method string|null getEcheance()
 * @method void setEcheance(?string $echeance)
 * @method int getOrdre()
 * @method void setOrdre(int $ordre)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $createdAt)
 * @method bool getValide()
 * @method void setValide(bool $valide)
 */
// Même patron d'entité que Projet (voir Projet.php pour le détail Entity / addType / jsonSerialize).
// Spécificités ici : parentId permet l'arbre (une tâche peut être sous-tâche d'une autre), et
// projetId est « dénormalisé » = recopié sur chaque sous-tâche, pour charger tout un projet d'un coup.
class Tache extends Entity implements JsonSerializable {
    protected $projetId;
    protected $activiteId;
    protected $parentId;
    protected $titre;
    protected $description;
    protected $heuresPrevues;
    protected $assignee;
    protected $statut;
    protected $deckCardId;
    protected $dateDebut; // date de début (AAAA-MM-JJ), pour la Vue Planneur ; null = non planifiée
    protected $echeance;
    protected $ordre;
    protected $createdAt;
    protected $valide; // false = tâche proposée par un membre, en attente de validation par la direction
    protected $liens;  // liste JSON de liens attachés (adresse web / fichier / dossier du cloud), null = aucun

    public function __construct() {
        $this->addType('id', 'integer');
        $this->addType('projetId', 'integer');
        $this->addType('activiteId', 'integer');
        $this->addType('parentId', 'integer');
        $this->addType('heuresPrevues', 'float');
        $this->addType('deckCardId', 'integer');
        $this->addType('ordre', 'integer');
        $this->addType('createdAt', 'integer');
        $this->addType('valide', 'boolean');
        $this->addType('liens', 'json'); // texte JSON en base ↔ tableau PHP (conversion automatique)
    }

    public function jsonSerialize(): array {
        return [
            'id' => $this->id,
            'projetId' => $this->projetId,
            'activiteId' => $this->activiteId,
            'parentId' => $this->parentId,
            'titre' => $this->titre,
            'description' => $this->description,
            'heuresPrevues' => $this->heuresPrevues,
            'assignee' => $this->assignee,
            'statut' => $this->statut,
            'deckCardId' => $this->deckCardId,
            'dateDebut' => $this->dateDebut,
            'echeance' => $this->echeance,
            'ordre' => $this->ordre,
            'createdAt' => $this->createdAt,
            // (bool) car la base peut renvoyer 0/1 ; null (ancienne ligne) → considéré comme validé.
            'valide' => $this->valide === null ? true : (bool)$this->valide,
            'liens' => is_array($this->liens) ? $this->liens : [],
        ];
    }
}
