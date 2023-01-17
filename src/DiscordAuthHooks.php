<?php

namespace DiscordAuth;

use DiscordAuth\AuthenticationProvider\DiscordAuth;
use MediaWiki\MediaWikiServices;
use RestCord\DiscordClient;
use Sanitizer;

class DiscordAuthHooks {

	protected $discordClient;
	protected $guildId;

	public function __construct() {
		$this->discordClient = MediaWikiServices::getInstance()->get('DiscordClient');
		$this->guildId = (int) MediaWikiServices::getInstance()->getMainConfig()->get('DiscordGuildId');
	}

	/**
	 * @param array|bool $user_info
	 * @param $errorMessage
	 */
	public function onWSOAuthAfterGetUser( &$user_info, &$errorMessage ): bool {
		if ( !$user_info ) {
			return false;
		}
		if ( !isset( $user_info[DiscordAuth::SOURCE] )) {
			return false;
		}
		if ( $user_info[DiscordAuth::SOURCE] !== DiscordAuth::DISCORD ) {
			return false;
		}

		if ( !isset( $user_info['email'] ) ) {
			return false;
		}

		if ( !Sanitizer::validateEmail( $user_info['email'] ) ) {
			return false;
		}

		try {
			$this->checkDiscordUser( $user_info['discord_user_id' ]);
		} catch ( \Exception $e ) {
			return false;
		}

		$dbr = wfGetDB(DB_MASTER);
		$user = $dbr->select(
			'user',
			['user_name'],
			['user_email' => $user_info['email']],
			__METHOD__
		)->fetchObject();

		if ( $user ) {
			$user_info['name'] = $user->user_name;
		}

		return true;
	}

	/**
	 * @param integer $discordUserId
	 * @throws \Psr\Container\ContainerExceptionInterface
	 * @throws \Psr\Container\NotFoundExceptionInterface
	 */
	protected function checkDiscordUser( $discordUserId ) {
		/** @var DiscordClient $discordClient */
		$member = $this->discordClient->guild->getGuildMember(
			['guild.id' => $this->guildId, 'user.id' => (int) $discordUserId]
		);
		if ( !$member ) {
			return false;
		}

		$roleIds = $this->getApprovedDiscordRolesIdsByNames(
			MediaWikiServices::getInstance()->getMainConfig()->get('ApprovedDiscordRoles')
		);

		if ( !$roleIds ) {
			return false;
		}

		foreach ( $roleIds as $roleId ) {
			if (in_array( $roleId, $member->roles )) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @param $roleNames
	 * @return array
	 */
	protected function getApprovedDiscordRolesIdsByNames( $roleNames ) {
		$roleObjects = $this->discordClient->guild->getGuildRoles( ['guild.id' => $this->guildId] );
		$roleIds = [];
		foreach( $roleObjects as $roleObject ) {
			foreach ( $roleNames as $roleName ) {
				if ( strtolower( $roleName ) === strtolower( $roleObject->name )) {
					$roleIds[] = $roleObject->id;
				}
			}
		}
		return $roleIds;
	}
}
