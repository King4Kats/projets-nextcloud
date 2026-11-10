<?php
// SPDX-FileCopyrightText: 2026 Flavien Auvray
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\TemoniaPilotage\Service;

use OCA\TemoniaPilotage\AppInfo\Application;
use OCP\Http\Client\IClientService;
use OCP\IAppConfig;
use OCP\IConfig;
use Psr\Log\LoggerInterface;

/**
 * Analyse d'un justificatif (ticket de caisse OU facture) par un modèle d'IA « vision » (il lit l'image).
 * Une facture en PDF est d'abord convertie en image (1re page) par le conteneur « imaginary » de Nextcloud AIO.
 *
 * Le modèle (qwen2.5vl) tourne sur un serveur Ollama distant, derrière un tunnel
 * Cloudflare protégé par un « jeton de service » (deux en-têtes CF-Access-*).
 * Toute la configuration est en base (appconfig), JAMAIS dans le code :
 *   occ config:app:set temonia_pilotage vision_url        --value="https://…"
 *   occ config:app:set temonia_pilotage vision_model      --value="qwen2.5vl:7b"
 *   occ config:app:set temonia_pilotage vision_timeout    --value="40"
 *   occ config:app:set temonia_pilotage vision_cf_id      --value="…"
 *   occ config:app:set temonia_pilotage vision_cf_secret  --value="…" --sensitive
 *
 * Le résultat ne fait que PRÉ-REMPLIR le formulaire : l'utilisateur vérifie et corrige.
 * L'objet de la dépense n'est volontairement pas demandé à l'IA (c'est un choix humain).
 */
class VisionService {
    // Prompt éprouvé (repris tel quel du service OCR du Hub). « format: json » côté Ollama force
    // une réponse JSON, mais on la valide quand même strictement (voir normalize()).
    // Depuis le 18/10/2026 : valable pour un ticket de caisse ET pour une facture (photo ou PDF converti).
    private const PROMPT = 'Lis ce justificatif français : un ticket de caisse OU une facture (photo ou page de PDF). '
        . 'Réponds UNIQUEMENT par un JSON avec ces clés : '
        . 'montant_ttc (nombre = total payé TTC ; sur une facture : « Total TTC » ou « Net à payer »), '
        . 'montant_ht (nombre = total hors taxes HT, ou null), '
        . 'date (format AAAA-MM-JJ), '
        . 'fournisseur (nom de l\'enseigne, ou de la société qui a émis la facture), '
        . 'tva (nombre = total de la TVA, ou null), '
        . 'type (un seul parmi : hebergement_restauration, carburant, transport, frais_divers), '
        . 'ville (ville de l\'adresse du commerçant ou de l\'émetteur de la facture, ou null). '
        . 'Toute information absente ou illisible = null. N\'invente rien.';

    // Types que l'IA a le droit de proposer (doivent exister dans tp_frais_types).
    private const TYPES = ['hebergement_restauration', 'carburant', 'transport', 'frais_divers'];

    private IClientService $clients;
    private IAppConfig $appConfig;
    private LoggerInterface $logger;
    private IConfig $systemConfig; // config.php de Nextcloud : on y lit l'adresse du conteneur « imaginary »

    public function __construct(IClientService $clients, IAppConfig $appConfig, LoggerInterface $logger, IConfig $systemConfig) {
        $this->clients = $clients;
        $this->appConfig = $appConfig;
        $this->logger = $logger;
        $this->systemConfig = $systemConfig;
    }

    // Lecture d'une clé de configuration de l'app (chaîne vide si absente).
    private function cfg(string $key, string $default = ''): string {
        return $this->appConfig->getValueString(Application::APP_ID, $key, $default);
    }

    /** Vrai si l'URL et le jeton d'accès sont renseignés (sinon on ne tente même pas l'appel). */
    public function isConfigured(): bool {
        return $this->cfg('vision_url') !== '' && $this->cfg('vision_cf_id') !== '' && $this->cfg('vision_cf_secret') !== '';
    }

    /**
     * Envoie l'image au modèle et renvoie :
     *   ['ok' => true,  'fields' => [montant_ttc, montant_ht, tva, date, fournisseur, type, ville], 'raw' => '…']
     *   ['ok' => false, 'error' => 'non_configure' | 'pdf_non_supporte' (PDF non convertible) | 'indisponible' | 'json_invalide', 'raw' => ?]
     * Ne lève JAMAIS d'exception : un échec de l'IA ne doit pas empêcher la saisie manuelle.
     */
    public function scan(string $bytes, string $mime): array {
        if (!$this->isConfigured()) {
            return ['ok' => false, 'error' => 'non_configure'];
        }
        // Le modèle lit des IMAGES : une facture PDF est d'abord convertie (1re page → JPEG).
        if ($mime === 'application/pdf') {
            $image = $this->pdfVersImage($bytes);
            if ($image === null) {
                return ['ok' => false, 'error' => 'pdf_non_supporte'];
            }
            $bytes = $image;
        }
        $timeout = (int)$this->cfg('vision_timeout', '40');
        if ($timeout < 5 || $timeout > 90) {
            $timeout = 40;
        }
        $payload = [
            'model' => $this->cfg('vision_model', 'qwen2.5vl:7b'),
            'prompt' => self::PROMPT,
            'images' => [base64_encode($bytes)], // Ollama attend l'image en base64
            'stream' => false,
            'format' => 'json',
        ];
        try {
            $client = $this->clients->newClient();
            $resp = $client->post(rtrim($this->cfg('vision_url'), '/') . '/api/generate', [
                'body' => json_encode($payload),
                'headers' => [
                    'Content-Type' => 'application/json',
                    'CF-Access-Client-Id' => $this->cfg('vision_cf_id'),
                    'CF-Access-Client-Secret' => $this->cfg('vision_cf_secret'),
                ],
                'timeout' => $timeout,
                'connect_timeout' => 10,
            ]);
            $body = (string)$resp->getBody();
        } catch (\Throwable $e) {
            // Réseau, 403 (jeton), 5xx, délai dépassé… On journalise SANS le secret ni l'image.
            $this->logger->warning('Vision frais indisponible : ' . $e->getMessage(), ['app' => Application::APP_ID]);
            return ['ok' => false, 'error' => 'indisponible'];
        }
        // Réponse Ollama = { "response": "<texte JSON produit par le modèle>", … }.
        $outer = json_decode($body, true);
        $inner = is_array($outer) && isset($outer['response']) ? json_decode((string)$outer['response'], true) : null;
        if (!is_array($inner)) {
            $this->logger->warning('Vision frais : réponse JSON invalide', ['app' => Application::APP_ID]);
            return ['ok' => false, 'error' => 'json_invalide', 'raw' => mb_substr($body, 0, 2000)];
        }
        return ['ok' => true, 'fields' => $this->normalize($inner), 'raw' => (string)$outer['response']];
    }

    /**
     * 1re page d'un PDF → image JPEG, par le conteneur « imaginary » de Nextcloud AIO (celui qui
     * fabrique déjà les aperçus des PDF dans Fichiers). On lui envoie le PDF et une « pipeline »
     * d'opérations : convertir en JPEG, puis réduire à 1600 px max (assez pour lire un total).
     * Renvoie null si imaginary n'est pas configuré ou si la conversion échoue.
     */
    private function pdfVersImage(string $pdf): ?string {
        $url = (string)$this->systemConfig->getSystemValue('preview_imaginary_url', '');
        if ($url === '') {
            return null;
        }
        $ops = json_encode([
            ['operation' => 'convert', 'params' => ['type' => 'jpeg', 'quality' => 85]],
            ['operation' => 'fit', 'params' => ['width' => 1600, 'height' => 1600, 'stripmeta' => 'true', 'type' => 'jpeg']],
        ]);
        $query = 'operations=' . urlencode((string)$ops);
        $key = (string)$this->systemConfig->getSystemValue('preview_imaginary_key', '');
        if ($key !== '') {
            $query .= '&key=' . urlencode($key);
        }
        try {
            $resp = $this->clients->newClient()->post(rtrim($url, '/') . '/pipeline?' . $query, [
                'body' => $pdf,
                'headers' => ['Content-Type' => 'application/pdf'],
                'timeout' => 30,
                'nextcloud' => ['allow_local_address' => true], // imaginary est sur le réseau Docker interne
            ]);
            $img = (string)$resp->getBody();
            // Un JPEG commence toujours par les octets FF D8.
            return (strlen($img) > 100 && substr($img, 0, 2) === "\xFF\xD8") ? $img : null;
        } catch (\Throwable $e) {
            $this->logger->warning('Conversion PDF → image impossible : ' . $e->getMessage(), ['app' => Application::APP_ID]);
            return null;
        }
    }

    // Convertit une valeur du modèle en nombre (gère "12,50", "12.50 €"…), ou null.
    private static function toFloat($v): ?float {
        if ($v === null || $v === '') {
            return null;
        }
        $s = str_replace([',', '€', ' '], ['.', '', ''], (string)$v);
        if (!is_numeric($s)) {
            return null;
        }
        $f = round((float)$s, 2);
        return ($f < 0 || $f > 100000) ? null : $f;
    }

    // Nettoie et VALIDE ce que renvoie le modèle : on ne fait jamais confiance à l'IA telle quelle.
    private function normalize(array $raw): array {
        $ttc = self::toFloat($raw['montant_ttc'] ?? null);
        $tva = self::toFloat($raw['tva'] ?? null);
        $ht = self::toFloat($raw['montant_ht'] ?? null);
        // HT absent mais TTC et TVA connus → on le déduit.
        if ($ht === null && $ttc !== null && $tva !== null && $tva <= $ttc) {
            $ht = round($ttc - $tva, 2);
        }
        // Date : format AAAA-MM-JJ ET date réelle (checkdate refuse un 31/02).
        $date = null;
        $d = trim((string)($raw['date'] ?? ''));
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $d, $m) && checkdate((int)$m[2], (int)$m[3], (int)$m[1])) {
            $date = $d;
        }
        $type = strtolower(trim((string)($raw['type'] ?? '')));
        $texte = function ($v): ?string {
            $s = trim((string)($v ?? ''));
            return $s === '' || strtolower($s) === 'null' ? null : mb_substr($s, 0, 255);
        };
        return [
            'montant_ttc' => $ttc,
            'montant_ht' => $ht,
            'tva' => $tva,
            'date' => $date,
            'fournisseur' => $texte($raw['fournisseur'] ?? null),
            'type' => in_array($type, self::TYPES, true) ? $type : null,
            'ville' => $texte($raw['ville'] ?? null),
        ];
    }
}
