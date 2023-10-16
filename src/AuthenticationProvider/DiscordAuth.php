<?php

namespace DiscordAuth\AuthenticationProvider;

use Wohali\OAuth2\Client\Provider\Discord;
use MediaWiki\User\UserIdentity;
use WSOAuth\AuthenticationProvider\AuthProvider;

class DiscordAuth extends AuthProvider {

	const SOURCE = 'source';
	const DISCORD = 'discord';

	/**
	 * @var Discord
	 */
	private $provider;

	/**
	 * @inheritDoc
	 */
	public function __construct( string $clientId, string $clientSecret, ?string $authUri, ?string $redirectUri, array $extensionData = [] ) {
		$this->provider = new Discord( [
			'clientId' => $clientId,
			'clientSecret' => $clientSecret,
			'redirectUri' => $redirectUri
		] );
	}

	/**
	 * @inheritDoc
	 */
	public function login( ?string &$key, ?string &$secret, ?string &$authUrl ): bool {
		$authUrl = $this->provider->getAuthorizationUrl([ 'scope' => [ 'identify', 'email' ] ]);

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
			return false;
		}

		if ( !isset( $_GET['state'] ) || empty( $_GET['state'] ) || ( $_GET['state'] !== $secret ) ) {
			return false;
		}

		try {
			$token = $this->provider->getAccessToken( 'authorization_code', [ 'code' => $_GET['code'] ] );
			$user = $this->provider->getResourceOwner( $token );

			return [
				'name' => $user->getUsername(),
				'discord_user_id' => $user->getId(),
				'realname' => $user->getUsername(),
				'email' => $user->getEmail(),
				self::SOURCE => self::DISCORD
			];
		} catch ( \Exception $e ) {
			return false;
		}
	}

	/**
	 * @inheritDoc
	 */
	public function saveExtraAttributes( int $id ): void {
	}
}
