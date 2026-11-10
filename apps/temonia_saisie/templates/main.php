<?php
// SPDX-FileCopyrightText: 2026 Flavien Auvray
// SPDX-License-Identifier: AGPL-3.0-or-later
?>
<div id="tp-app" data-is-manager="<?php p(!empty($_['isManager']) ? '1' : '0'); ?>" data-is-admin="<?php p(!empty($_['isAdmin']) ? '1' : '0'); ?>" data-libelles="<?php p($_['libelles'] ?? '{}'); ?>">
    <div class="tp-loading icon-loading" style="margin-top:60px"></div>
</div>
