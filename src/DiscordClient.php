<?php

namespace DiscordAuth;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\MediaWikiServices;
use Psr\Log\LoggerInterface;

class DiscordClient {
	/** @var LoggerInterface */
	private $logger;
	/** @var Client */
	private $httpClient;

	/**
	 * Create a new DiscordClient with the given bot token
	 *
	 * @param string $botToken Discord bot token
	 */
	public function __construct( string $botToken ) {
		$this->logger = LoggerFactory::getInstance( 'DiscordAuth\DiscordClient' );
		$this->httpClient = MediaWikiServices::getInstance()->getHttpRequestFactory()->createGuzzleClient( [
			'base_uri' => 'https://discord.com/api/v10/',
			'headers' => [
				'Authorization' => "Bot $botToken"
			]
		] );
	}

	/**
	 * @param string $guildId ID of the guild to get the member for
	 * @param string $memberId ID of the member to get
	 * @return stdClass Discord member object
	 * @throws GuzzleException
	 */
	public function getGuildMember( string $guildId, string $memberId ): object {
		return json_decode( $this->httpClient->request(
			'GET', "guilds/$guildId/members/$memberId" )->getBody()->getContents() );
	}

	/**
	 * @param string $guildId ID of the guild to get the role list for
	 * @return array array of Discord role objects
	 * @throws GuzzleException
	 */
	public function getGuildRoles( string $guildId ): array {
		return json_decode( $this->httpClient->request( 'GET', "guilds/$guildId/roles" )->getBody()->getContents() );
	}
}
