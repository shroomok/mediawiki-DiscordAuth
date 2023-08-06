<?php

namespace DiscordAuth\AuthenticationProvider;

use Wohali\OAuth2\Client\Provider\Discord;
use MediaWiki\MediaWikiServices;
use MediaWiki\User\UserIdentity;
use WSOAuth\AuthenticationProvider\AuthProvider;

class DiscordAuth extends AuthProvider {

	const SOURCE = 'source';
	const DISCORD = 'discord';

	/**
	 * @var Discord
	 */
	private $provider;
	private $collectEmail;

	/**
	 * @inheritDoc
	 */
	public function __construct( string $clientId, string $clientSecret, ?string $authUri, ?string $redirectUri, array $extensionData = [] ) {
		$this->provider = new Discord( [
			'clientId' => $clientId,
			'clientSecret' => $clientSecret,
			'redirectUri' => $redirectUri
		] );
		$config = MediaWikiServices::getInstance()->getMainConfig();
		$this->collectEmail = (bool)$config->get('DiscordCollectEmail');
	}

	/**
	 * @inheritDoc
	 */
	public function login( ?string &$key, ?string &$secret, ?string &$authUrl ): bool {
		$scopes = ['identify'];

		if ($this->collectEmail) {
			$scopes[] = 'email';
		}

		$authUrl = $this->provider->getAuthorizationUrl([ 'scope' => $scopes]);

		$secret = $this->provider->getState();

		return true;
	}

	/**
	 * @inheritDoc
	 */
	public function logout( UserIdentity &$user ): void {
	}

	/**
	 * @inheritDoc
	 */
	public function getUser( string $key, string $secret, &$errorMessage ) {
		if ( !isset( $_GET['code'] ) ) {
			$errorMessage = 'Discord did not return authorization code';
			return false;
		}

		if (empty( $_GET['state'] ) || ( $_GET['state'] !== $secret) ) {
			$errorMessage = 'Discord did not return authorization state';
			return false;
		}

		try {
			$token = $this->provider->getAccessToken('authorization_code', ['code' => $_GET['code']]);
			$user = $this->provider->getResourceOwner($token);
			return [
				'name' => self::DISCORD . '-' . $user->getId(),
				'discord_user_id' => $user->getId(),
				'realname' => $user->getUsername(),
				'email' => $this->collectEmail ? $user->getEmail() : '',
				self::SOURCE => self::DISCORD
			];
		} catch ( \Exception $e ) {
			$errorMessage = $e->getMessage();
			return false;
		}
	}

	/**
	 * @inheritDoc
	 */
	public function saveExtraAttributes( int $id ): void {
	}
}
