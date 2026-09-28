<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/** @var array $_ */
/** @var \OCP\IL10N $l */

$urlGenerator = \OCP\Server::get(\OCP\IURLGenerator::class);
?>
<div class="body-login-container update">
	<div class="icon-big icon-checkmark icon-white"></div>
	<h2><?php p($l->t('Your Social account has been deleted')); ?></h2>
	<p class="infogroup"><?php p($l->t('The Social account and the Nextcloud account created for it have been deleted. You are now signed out.')); ?></p>
	<p class="infogroup"><?php p($l->t('Social sent requests to remove your posts to the servers that received them. Those servers control copies they may already have stored.')); ?></p>
	<p>
		<a class="button primary" href="<?php p($urlGenerator->linkToRoute('social.Navigation.navigate')); ?>">
			<?php p($l->t('Return to Social')); ?>
		</a>
		<a class="button" href="<?php p($urlGenerator->linkToRoute('core.login.showLoginForm')); ?>">
			<?php p($l->t('Go to the sign-in page')); ?>
		</a>
	</p>
</div>
