<?php
// SPDX-FileCopyrightText: 2026 Flavien Auvray
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\TemoniaPilotage\Db;

use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * Une NOTE DE FRAIS (table `tp_frais`) : un ticket, un trajet, une dépense…
 * saisie par une personne (`userId`), rattachée à un projet (`projetId`) OU à
 * une thématique interne (`themeId`), avec son montant et son statut de
 * traitement (soumis → valide → rembourse, ou refuse).
 *
 * Même patron d'entité que Heure (voir Heure.php) : propriétés camelCase ↔
 * colonnes snake_case, conversion automatique par Nextcloud.
 *
 * @method string getNumero()
 * @method void setNumero(string $numero)
 * @method string getUserId()
 * @method void setUserId(string $userId)
 * @method string getDateFrais()
 * @method void setDateFrais(string $dateFrais)
 * @method int|null getProjetId()
 * @method void setProjetId(?int $projetId)
 * @method int|null getThemeId()
 * @method void setThemeId(?int $themeId)
 * @method string getTypeCode()
 * @method void setTypeCode(string $typeCode)
 * @method float getMontantTtc()
 * @method void setMontantTtc(float $montantTtc)
 * @method float|null getMontantHt()
 * @method void setMontantHt(?float $montantHt)
 * @method float|null getTva()
 * @method void setTva(?float $tva)
 * @method float|null getKm()
 * @method void setKm(?float $km)
 * @method float|null getBaremeKm()
 * @method void setBaremeKm(?float $baremeKm)
 * @method string|null getLieu()
 * @method void setLieu(?string $lieu)
 * @method string|null getFournisseur()
 * @method void setFournisseur(?string $fournisseur)
 * @method string getObjet()
 * @method void setObjet(string $objet)
 * @method string|null getCommentaire()
 * @method void setCommentaire(?string $commentaire)
 * @method string|null getPhotoName()
 * @method void setPhotoName(?string $photoName)
 * @method string|null getPhotoMime()
 * @method void setPhotoMime(?string $photoMime)
 * @method string getStatut()
 * @method void setStatut(string $statut)
 * @method string|null getCommentaireAdmin()
 * @method void setCommentaireAdmin(?string $commentaireAdmin)
 * @method int|null getValidatedAt()
 * @method void setValidatedAt(?int $validatedAt)
 * @method string|null getValidatedBy()
 * @method void setValidatedBy(?string $validatedBy)
 * @method string|null getDateRemboursement()
 * @method void setDateRemboursement(?string $dateRemboursement)
 * @method string|null getRemboursementMode()
 * @method void setRemboursementMode(?string $remboursementMode)
 * @method string|null getScanJson()
 * @method void setScanJson(?string $scanJson)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $createdAt)
 * @method int getUpdatedAt()
 * @method void setUpdatedAt(int $updatedAt)
 */
class Frais extends Entity implements JsonSerializable {
    protected $numero;
    protected $userId;
    protected $dateFrais;
    protected $projetId;
    protected $themeId;
    protected $typeCode;
    protected $montantTtc;
    protected $montantHt;
    protected $tva;
    protected $km;
    protected $baremeKm;
    protected $lieu;
    protected $fournisseur;
    protected $objet;
    protected $commentaire;
    protected $photoName;
    protected $photoMime;
    protected $statut;
    protected $commentaireAdmin;
    protected $validatedAt;
    protected $validatedBy;
    protected $dateRemboursement;
    protected $remboursementMode;
    protected $scanJson;
    protected $createdAt;
    protected $updatedAt;

    public function __construct() {
        // addType() indique à Nextcloud comment convertir chaque colonne (la BD renvoie des chaînes).
        $this->addType('id', 'integer');
        $this->addType('projetId', 'integer');
        $this->addType('themeId', 'integer');
        $this->addType('montantTtc', 'float');
        $this->addType('montantHt', 'float');
        $this->addType('tva', 'float');
        $this->addType('km', 'float');
        $this->addType('baremeKm', 'float');
        $this->addType('validatedAt', 'integer');
        $this->addType('createdAt', 'integer');
        $this->addType('updatedAt', 'integer');
    }

    // Ce qui part vers le navigateur. Volontairement SANS scanJson (donnée technique, potentiellement
    // volumineuse) : le contrôleur l'ajoute seulement pour l'admin qui ouvre le détail.
    public function jsonSerialize(): array {
        return [
            'id' => $this->id,
            'numero' => $this->numero,
            'userId' => $this->userId,
            'dateFrais' => $this->dateFrais,
            'projetId' => $this->projetId,
            'themeId' => $this->themeId,
            'typeCode' => $this->typeCode,
            'montantTtc' => $this->montantTtc,
            'montantHt' => $this->montantHt,
            'tva' => $this->tva,
            'km' => $this->km,
            'baremeKm' => $this->baremeKm,
            'lieu' => $this->lieu,
            'fournisseur' => $this->fournisseur,
            'objet' => $this->objet,
            'commentaire' => $this->commentaire,
            'hasPhoto' => $this->photoName !== null && $this->photoName !== '',
            'photoMime' => $this->photoMime,
            'statut' => $this->statut,
            'commentaireAdmin' => $this->commentaireAdmin,
            'validatedAt' => $this->validatedAt,
            'validatedBy' => $this->validatedBy,
            'dateRemboursement' => $this->dateRemboursement,
            'remboursementMode' => $this->remboursementMode,
            'createdAt' => $this->createdAt,
            'updatedAt' => $this->updatedAt,
        ];
    }
}
