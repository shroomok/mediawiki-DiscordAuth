<?php

namespace DiscordAuth;

use MediaWiki\Permissions\Authority;
use MediaWiki\User\UserIdentity;
use Title;
use Status;
use HTMLForm;
use RequestContext;
use RestCord\DiscordClient;
use MalformedTitleException;
use MediaWiki\MediaWikiServices;
use MediaWiki\Revision\ContributionsLookup;
use MediaWiki\User\UserGroupManager;
use DiscordAuth\AuthenticationProvider\DiscordAuth;
use MediaWiki\Logger\LoggerFactory;

class DiscordAuthHooks {

	protected $discordClient;
	protected $guildId;
	protected $config;
	protected $logger;

	public function __construct() {
		/** @var DiscordClient $discordClient */
		$this->discordClient = MediaWikiServices::getInstance()->get('DiscordClient');
		$this->config = MediaWikiServices::getInstance()->getMainConfig();
		$this->guildId = $this->config->get('DiscordGuildId');
		// Accessing this in checkDiscordUser returns null for some reason?
        $this->logger = LoggerFactory::getInstance( 'DiscordAuth' );
	}

	/**
	 *
	 * This hook runs for users who've been logged through Discord
	 * it does:
	 * - remove default content from Main page
	 * - draws a form for creating new pages
	 * - shows current User recent contributions
	 *
	 * @param \OutputPage $out
	 * @param $text
	 * @throws \MWException
	 */
	public function onOutputPageBeforeHTML( \OutputPage $out, &$text ) {
		if ( $this->config->get('DiscordShowUserContributionsOnMainPage') !== true ) {
			return;
		}

		if ( $this->config->get('DiscordToRegisterNS') !== true ) {
			return;
		}

		if ( $out->getTitle()->getTitleValue()->getText() !== 'Main Page' ) {
			return;
		}

		if ( !$ns = self::getDiscordNS($this->config->get('DiscordNS')) ) {
			return;
		}

		/** @var UserGroupManager $um */
		$um = MediaWikiServices::getInstance()->get('UserGroupManager');
		if ( !in_array( strtolower($ns['alias']), $um->getUserGroups($out->getUser() ) ) ) {
			return;
		}
		$text = '';
		$form = HTMLForm::factory('ooui', [
			'page' => [
				'type' => 'text',
				'name' => 'page',
				'label-message' => 'mypage',
				'required' => true,
			],
		], $out->getContext());
		$form->setSubmitTextMsg('create');
		$form->setSubmitCallback( function ( $formData ) {
			if (strpos(strtolower($formData['page']), 'discord:') !== 0) {
				$formData['page'] = 'Discord:' . $formData['page'];
			}
			try {
				$page = Title::newFromTextThrow($formData['page']);
			} catch (MalformedTitleException $e) {
				return Status::newFatal($e->getMessageObject());
			}
			$query = ['action' => 'edit'];
			$url = $page->getFullUrlForRedirect($query);
			RequestContext::getMain()->getOutput()->redirect($url);
		} );
		$form->show();

		$linksToRecentEditsByCurrentAuthor = $this->getPagesLinksByUserContributions(
			$out->getUser(),
			$out->getAuthority()
		);

		$text .= \Html::element('h3', [], 'Your recent contributions:');
		foreach($linksToRecentEditsByCurrentAuthor as $link) {
			$text .= \Html::openElement( 'p' );
			$text .= \Html::element( 'a', ['href' => $link['url']], $link['anchor']);
			$text .= \Html::closeElement( 'p' );
		}
	}

	/**
	 * @param MediaWikiServices $services
	 */
	public static function onMediaWikiServices( &$services ) {
		global $wgAvailableRights, $wgNamespaceProtection, $wgNamespacesWithSubpages,
			   $wgContentNamespaces, $wgGroupPermissions, $wgNamespacesToBeSearchedDefault,
			   $wgOAuthAutoPopulateGroups, $wgExtraNamespaces;

		$config = $services->getMainConfig();
		if ( $config->get('DiscordToRegisterNS') !== true ) {
			return;
		}

		if (!$ns = self::getDiscordNS($config->get('DiscordNS'))) {
			return;
		}
		if ( array_key_exists( $ns['id'], $wgExtraNamespaces ) ) {
			return;
		}
		$wgExtraNamespaces[$ns['id']] = $ns['alias'];
		$wgExtraNamespaces[$ns['id'] + 1] = $ns['alias'] . '_talk';

		$lowerAlias = strtolower( $ns['alias'] );
		$right = 'edit' . $lowerAlias;

		$wgAvailableRights[] = $right;
		$wgContentNamespaces[] = $ns['id'];
		$wgNamespaceProtection[$ns['id']] = [$right];
		$wgNamespacesWithSubpages[$ns['id']] = true;
		$wgGroupPermissions['sysop'][$right] = true;
        $wgGroupPermissions[$lowerAlias]['upload'] = true;
		$wgGroupPermissions[$lowerAlias][$right] = true;
		$wgNamespacesToBeSearchedDefault[$ns['id']] = 1;
		$wgOAuthAutoPopulateGroups[] = $lowerAlias;
        $wgNamespaceProtection[NS_FILE] = $right;
	}

	/**
	 * @param $user_info
	 * @param $errorMessage
	 * @return bool
	 */
	public function onWSOAuthAfterGetUser( &$user_info, &$errorMessage ): bool {
		if ( !$user_info ) {
			return false;
		}
		if ( !isset( $user_info[DiscordAuth::SOURCE] )) {
		    $errorMessage = 'Authentication attempt missing source attribute';
			return false;
		}
		if ( $user_info[DiscordAuth::SOURCE] !== DiscordAuth::DISCORD ) {
		    $errorMessage = 'Authentication attempt source is not DiscordAuth';
			return false;
		}
		if ( !isset( $this->guildId )) {
		    $errorMessage = 'No guild configured';
		    return false;
		}
        if ( $this->config->get('DiscordApprovedRoles') === null ) {
            $errorMessage = 'No approved roles configured';
            return false;
        }

		$userApproved = false;
		try {
			$userApproved = $this->checkDiscordUser( $user_info['discord_user_id'], $this->guildId, $this->config->get('DiscordApprovedRoles') );
		} catch ( \Exception $e ) {
		    $errorMessage = $e->getMessage();
			return false;
		}
		if (!$userApproved) {
			return false;
		}

		$dbr = wfGetDB( DB_MASTER );
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
	 * @param string $discordUserId
	 * @param integer $discordGuildId
	 * @param array $approvedRoleNames
	 * @throws \Psr\Container\ContainerExceptionInterface
	 * @throws \Psr\Container\NotFoundExceptionInterface
	 */
	protected function checkDiscordUser(string $discordUserId, int $discordGuildId, array $approvedRoleNames ) {
		LoggerFactory::getInstance( 'DiscordAuth' )->debug("Checking User {$discordUserId}");
		$member = $this->discordClient->guild->getGuildMember(
			['guild.id' => $discordGuildId, 'user.id' => (int) $discordUserId]
		);

		$memberRolesJson = json_encode($member->roles);
		LoggerFactory::getInstance( 'DiscordAuth' )->debug("Member has roles {$memberRolesJson}");

		$roleIds = $this->getApprovedDiscordRolesIdsByNames( $discordGuildId, $approvedRoleNames );
		$roleIdsJson = json_encode($roleIds);
		LoggerFactory::getInstance( 'DiscordAuth' )->debug("Found approved role list: {$roleIdsJson}");

		if ( !$roleIds ) {
			LoggerFactory::getInstance( 'DiscordAuth' )->error('No approved Discord role IDs were found. Please check that role names are spelled correctly.');
			return false;
		}

		foreach ( $roleIds as $roleId ) {
			if ( in_array( $roleId, $member->roles ) ) {
				return true;
			}
		}

        LoggerFactory::getInstance( 'DiscordAuth' )->error('Login failed: member does not have any approved roles');
		return false;
	}

	/**
	 * @param $guildId
	 * @param $roleNames
	 * @return array
	 */
	protected function getApprovedDiscordRolesIdsByNames( $guildId, $roleNames ) {
		$roleObjects = $this->discordClient->guild->getGuildRoles( ['guild.id' => $guildId] );
		$roleIds = [];
		foreach( $roleObjects as $roleObject ) {
			foreach ( $roleNames as $roleName ) {
				if ( strtolower( $roleName ) === strtolower( $roleObject->name ) ) {
					$roleIds[] = $roleObject->id;
				}
			}
		}
		return $roleIds;
	}

	/**
	 * @return array
	 */
	public static function getDiscordNS( $discordNSConfig) {
		if ( !is_array( $discordNSConfig ) ) {
			return [];
		}

		if ( !count( $discordNSConfig ) ) {
			return [];
		}

		if ( !array_key_exists( 'id', $discordNSConfig ) ) {
			return [];
		}

		if ( !array_key_exists( 'alias', $discordNSConfig ) ) {
			return [];
		}

		return $discordNSConfig;
	}

	/**
	 * @param UserIdentity $user
	 * @param Authority $authority
	 * @param int $limit
	 * @return array
	 */
	protected function getPagesLinksByUserContributions( UserIdentity $user, Authority $authority, $limit = 200 )
	{
		/** @var ContributionsLookup $cl */
		$cl = MediaWikiServices::getInstance()->get('ContributionsLookup');
		$revisions = $cl->getContributions( $user, $limit, $authority )->getRevisions();
		$recentEditsByCurrentAuthor = [];
		foreach ($revisions as $revision) {
			$recentEditsByCurrentAuthor[$revision->getPageId()] = [
				'anchor' => $revision->getPageAsLinkTarget()->getText(),
				'url' => MediaWikiServices::getInstance()
					->getWikiPageFactory()
					->newFromID($revision->getPageId())
					->getSourceURL()
			];
		}
		return $recentEditsByCurrentAuthor;
	}
}
