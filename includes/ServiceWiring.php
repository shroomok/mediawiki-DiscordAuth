<?php

use MediaWiki\MediaWikiServices;
use RestCord\DiscordClient;

/** @phpcs-require-sorted-array */
return [
	'DiscordClient' => function ( MediaWikiServices $services ) {
		$botToken = $services->getMainConfig()->get('DiscordAuthBotToken');
		return new DiscordClient( ['token' => $botToken] );
	},
];
