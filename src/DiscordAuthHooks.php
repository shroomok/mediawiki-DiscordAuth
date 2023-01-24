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

class DiscordAuthHooks {

	protected $discordClient;
	protected $guildId;
	protected $config;

	public function __construct() {
		/** @var DiscordClient $discordClient */
		$this->discordClient = MediaWikiServices::getInstance()->get('DiscordClient');
		$this->config = MediaWikiServices::getInstance()->getMainConfig();
		$this->guildId = (int) MediaWikiServices::getInstance()->getMainConfig()->get('DiscordGuildId');
	}

	/**
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

		if ( !$ns = $this->getDiscordNS() ) {
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
		$form->setSubmitCallback( [$this, 'submitFormHandler'] );
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
	 * @param $services
	 */
	public function onMediaWikiServices( &$services ) {
		global $wgAvailableRights, $wgNamespaceProtection, $wgNamespacesWithSubpages,
			   $wgContentNamespaces, $wgGroupPermissions, $wgNamespacesToBeSearchedDefault,
			   $wgOAuthAutoPopulateGroups, $wgExtraNamespaces;

		if ( $this->config->get('DiscordToRegisterNS') !== true ) {
			return;
		}

		if (!$ns = $this->getDiscordNS()) {
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
		$wgGroupPermissions[$lowerAlias][$right] = true;
		$wgNamespacesToBeSearchedDefault[$ns['id']] = 1;
		$wgOAuthAutoPopulateGroups[] = $lowerAlias;
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
			return false;
		}
		if ( $user_info[DiscordAuth::SOURCE] !== DiscordAuth::DISCORD ) {
			return false;
		}

		try {
			$this->checkDiscordUser( $user_info['discord_user_id' ]);
		} catch ( \Exception $e ) {
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
	 * @param integer $discordUserId
	 * @throws \Psr\Container\ContainerExceptionInterface
	 * @throws \Psr\Container\NotFoundExceptionInterface
	 */
	protected function checkDiscordUser( $discordUserId ) {
		$member = $this->discordClient->guild->getGuildMember(
			['guild.id' => $this->guildId, 'user.id' => (int) $discordUserId]
		);

		$roleIds = $this->getApprovedDiscordRolesIdsByNames(
			MediaWikiServices::getInstance()->getMainConfig()->get('DiscordApprovedRoles')
		);

		if ( !$roleIds ) {
			return false;
		}

		foreach ( $roleIds as $roleId ) {
			if ( in_array( $roleId, $member->roles ) ) {
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
	protected function getDiscordNS() {
		if ( !is_array( $this->config->get('DiscordNS') ) ) {
			return [];
		}

		if ( !count( $this->config->get('DiscordNS') ) ) {
			return [];
		}

		if ( !array_key_exists( 'id', $this->config->get('DiscordNS') ) ) {
			return [];
		}

		if ( !array_key_exists( 'alias', $this->config->get('DiscordNS') ) ) {
			return [];
		}

		return $this->config->get('DiscordNS');
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

	/**
	 * @param $formData
	 * @return Status|void
	 */
	protected function submitFormHandler( $formData ) {
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
	}
}
