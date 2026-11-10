<?php
// SPDX-FileCopyrightText: 2026 Flavien Auvray
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\TemoniaPilotage\Service;

use OCA\TemoniaPilotage\AppInfo\Application;
use OCP\Files\AppData\IAppDataFactory;
use OCP\Files\IAppData;
use OCP\Files\NotFoundException;
use OCP\Files\SimpleFS\ISimpleFile;
use OCP\Files\SimpleFS\ISimpleFolder;
use OCP\Security\ISecureRandom;

/**
 * Stockage des JUSTIFICATIFS de frais (photo du ticket ou PDF) dans l'espace PRIVÉ
 * de l'app (« appdata »). Ce n'est PAS le dossier Fichiers de l'utilisateur :
 *  - invisible dans l'interface Fichiers (pas de suppression accidentelle) ;
 *  - lisible uniquement via notre contrôleur, qui vérifie les droits (auteur ou admin).
 *
 * Organisation (deux dossiers « plats », appdata ne gère qu'un niveau) :
 *  - frais_tmp/ : fichiers en ATTENTE, envoyés avant que le frais n'existe (le temps que
 *                 l'utilisateur remplisse le formulaire). Nom = <empreinte uid>-<aléa>.<ext>.
 *                 L'empreinte permet de vérifier que celui qui relit le fichier est bien
 *                 celui qui l'a envoyé. Purgés après 24 h.
 *  - frais/     : fichiers DÉFINITIFS, un par frais, nommés <id du frais>.<ext>.
 */
class JustificatifService {
    // Taille max acceptée (8 Mo). Le navigateur réduit déjà les photos (~1600 px) avant l'envoi.
    public const MAX_BYTES = 8 * 1024 * 1024;
    // Types acceptés → extension de fichier. On se fie au CONTENU (finfo), jamais au type annoncé
    // par le navigateur (facile à falsifier).
    public const MIMES = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'application/pdf' => 'pdf',
    ];
    private const TMP_TTL = 24 * 3600; // durée de vie d'un fichier temporaire (secondes)

    private IAppDataFactory $factory;
    private ISecureRandom $random;

    public function __construct(IAppDataFactory $factory, ISecureRandom $random) {
        $this->factory = $factory;
        $this->random = $random;
    }

    // L'espace appdata de NOTRE app (créé à la volée par Nextcloud si besoin).
    private function root(): IAppData {
        return $this->factory->get(Application::APP_ID);
    }

    // Récupère un sous-dossier, en le créant s'il n'existe pas encore.
    private function folder(string $name): ISimpleFolder {
        try {
            return $this->root()->getFolder($name);
        } catch (NotFoundException $e) {
            return $this->root()->newFolder($name);
        }
    }

    /**
     * Détermine le type MIME RÉEL d'un fichier envoyé (en lisant ses premiers octets) et vérifie
     * qu'il fait partie des types acceptés. Renvoie le MIME, ou null si refusé.
     */
    public static function sniffMime(string $path): ?string {
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($path);
        if ($mime === false) {
            return null;
        }
        return array_key_exists($mime, self::MIMES) ? $mime : null;
    }

    // Empreinte courte de l'uid (12 caractères hexadécimaux) : sert de préfixe aux fichiers
    // temporaires. Un uid peut contenir espaces/accents ; l'empreinte, non.
    private function ownerTag(string $uid): string {
        return substr(sha1($uid), 0, 12);
    }

    /**
     * Enregistre un fichier TEMPORAIRE (avant création du frais). Renvoie son identifiant
     * temporaire (« tempId »), que le navigateur renverra ensuite avec le formulaire.
     */
    public function storeTemp(string $uid, string $bytes, string $mime): string {
        $dir = $this->folder('frais_tmp');
        $this->purgeTemp($dir);
        $tempId = $this->ownerTag($uid) . '-' . $this->random->generate(32, ISecureRandom::CHAR_LOWER . ISecureRandom::CHAR_DIGITS);
        $dir->newFile($tempId . '.' . self::MIMES[$mime], $bytes);
        return $tempId;
    }

    // Supprime les fichiers temporaires de plus de 24 h (nettoyage « paresseux », sans tâche cron).
    private function purgeTemp(ISimpleFolder $dir): void {
        $limit = time() - self::TMP_TTL;
        foreach ($dir->getDirectoryListing() as $f) {
            if ($f->getMTime() < $limit) {
                try {
                    $f->delete();
                } catch (\Throwable $e) {
                    // un échec de nettoyage n'est jamais bloquant
                }
            }
        }
    }

    // Un tempId valide = « 12 hexa - 32 alphanum ». Tout autre format est refusé d'emblée
    // (évite qu'on nous fasse lire un autre fichier via un nom bricolé).
    private function tempIdValide(string $tempId): bool {
        return (bool)preg_match('/^[a-f0-9]{12}-[a-z0-9]{32}$/', $tempId);
    }

    // Retrouve le fichier temporaire d'un utilisateur (null si absent, format invalide,
    // ou si le fichier appartient à quelqu'un d'autre).
    private function findTemp(string $uid, string $tempId): ?ISimpleFile {
        if (!$this->tempIdValide($tempId) || strpos($tempId, $this->ownerTag($uid) . '-') !== 0) {
            return null;
        }
        $dir = $this->folder('frais_tmp');
        foreach (self::MIMES as $ext) {
            try {
                return $dir->getFile($tempId . '.' . $ext);
            } catch (NotFoundException $e) {
                // on essaie l'extension suivante
            }
        }
        return null;
    }

    /**
     * Lit un fichier temporaire de l'utilisateur. Renvoie ['bytes' => …, 'mime' => …] ou null.
     */
    public function readTemp(string $uid, string $tempId): ?array {
        $f = $this->findTemp($uid, $tempId);
        if ($f === null) {
            return null;
        }
        return ['bytes' => $f->getContent(), 'mime' => $this->mimeFromName($f->getName())];
    }

    // Retrouve le MIME à partir de l'extension du nom de fichier (inverse de la table MIMES).
    private function mimeFromName(string $name): string {
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $mime = array_search($ext, self::MIMES, true);
        return $mime === false ? 'application/octet-stream' : $mime;
    }

    /**
     * Rend DÉFINITIF un fichier temporaire : copié sous frais/<id>.<ext>, puis le temporaire est
     * supprimé. Renvoie ['name' => …, 'mime' => …] à enregistrer sur le frais, ou null si le
     * temporaire est introuvable.
     */
    public function promote(string $uid, string $tempId, int $fraisId): ?array {
        $f = $this->findTemp($uid, $tempId);
        if ($f === null) {
            return null;
        }
        $mime = $this->mimeFromName($f->getName());
        $res = $this->store($fraisId, $f->getContent(), $mime);
        try {
            $f->delete();
        } catch (\Throwable $e) {
        }
        return $res;
    }

    /**
     * Écrit (ou remplace) le justificatif définitif d'un frais. Un seul fichier par frais :
     * on supprime d'abord l'ancien, quelle que soit son extension.
     */
    public function store(int $fraisId, string $bytes, string $mime): array {
        $this->delete($fraisId);
        $name = $fraisId . '.' . self::MIMES[$mime];
        $this->folder('frais')->newFile($name, $bytes);
        return ['name' => $name, 'mime' => $mime];
    }

    /** Le fichier justificatif d'un frais, ou null s'il n'y en a pas. */
    public function get(int $fraisId, ?string $name): ?ISimpleFile {
        if ($name === null || $name === '') {
            return null;
        }
        try {
            return $this->folder('frais')->getFile($name);
        } catch (NotFoundException $e) {
            return null;
        }
    }

    /** Supprime le justificatif d'un frais (toutes extensions possibles). Silencieux s'il n'y en a pas. */
    public function delete(int $fraisId): void {
        $dir = $this->folder('frais');
        foreach (self::MIMES as $ext) {
            try {
                $dir->getFile($fraisId . '.' . $ext)->delete();
            } catch (NotFoundException $e) {
            }
        }
    }
}
