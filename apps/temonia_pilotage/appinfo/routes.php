<?php
// SPDX-FileCopyrightText: 2026 Flavien Auvray
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

// Pilotage n'expose plus AUCUNE route HTTP : c'est un pur socle de
// données et de services (entités/mappers, AccessService/HistoService/NotifyService,
// Notifier) réutilisé par DI cross-app par l'app « Projets » (temonia_saisie),
// qui possède, elle, tous ses contrôleurs et ses routes.
return [
    'routes' => [],
];
