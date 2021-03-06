<?php

namespace MediaWiki\Extension\Sanctions;

use DatabaseUpdater;
use EchoEvent;
use Flow\Exception\InvalidInputException;
use Flow\Model\UUID;
use Linker;
use MediaWiki\MediaWikiServices;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\User\UserIdentity;
use MWException;
use OutputPage;
use RequestContext;
use SanctionsCreateTemplates;
use Skin;
use SpecialPage;
use Title;
use User;

class Hooks {
	/**
	 * Create tables in the database
	 *
	 * @param DatabaseUpdater|null $updater
	 * @throws MWException
	 * @return bool
	 */
	public static function onLoadExtensionSchemaUpdates( DatabaseUpdater $updater = null ) {
		$dir = __DIR__;

		if ( $updater->getDB()->getType() == 'mysql' ) {
			$updater->addExtensionUpdate(
				[ 'addTable', 'sanctions',
				"$dir/../sql/sanctions.tables.sql", true ]
			);
		}
		// @todo else

		require_once "$dir/../maintenance/SanctionsCreateTemplates.php";
		$updater->addPostDatabaseUpdateMaintenance( SanctionsCreateTemplates::class );

		return true;
	}

	/**
	 * Abort notifications regarding occupied pages coming from the RecentChange class.
	 * Flow has its own notifications through Echo.
	 *
	 * Also don't notify for actions made by Sanction bot.
	 *
	 * Copied from
	 * https://github.com/wikimedia/mediawiki-extensions-Flow/blob/de0b9ad/Hooks.php#L963-L996
	 *
	 * @param User $editor
	 * @param Title $title
	 * @return bool false to abort email notification
	 */
	public static function onAbortEmailNotification( User $editor, Title $title ) {
		if ( $title->getContentModel() === CONTENT_MODEL_FLOW_BOARD ) {
			// Since we are aborting the notification we need to manually update the watchlist
			$config = RequestContext::getMain()->getConfig();
			if ( $config->get( 'EnotifWatchlist' ) || $config->get( 'ShowUpdatedMarker' ) ) {
				MediaWikiServices::getInstance()->getWatchedItemStore()->updateNotificationTimestamp(
					$editor,
					$title,
					wfTimestampNow()
				);
			}
			return false;
		}

		if ( self::isSanctionBot( $editor ) ) {
			return false;
		}

		return true;
	}

	/**
	 * allow edit even when $wgEmailAuthentication is set to true
	 *
	 * @param User $user User being checked
	 * @param bool &$confirmed Whether or not the email address is confirmed
	 * @return bool|void True or no return value to continue or false to abort
	 */
	public static function onEmailConfirmed( User $user, bool &$confirmed ) {
		if ( !self::isSanctionBot( $user ) ) {
			return true;
		}

		$confirmed = true;
		return false;
	}

	/**
	 * Suppress all Echo notifications generated by Sanction bot
	 *
	 * Copied from
	 * https://github.com/wikimedia/mediawiki-extensions-Flow/blob/de0b9ad/Hooks.php#L1018-L1034
	 *
	 * @param EchoEvent $event
	 * @return bool
	 */
	public static function onBeforeEchoEventInsert( EchoEvent $event ) {
		$agent = $event->getAgent();

		if ( $agent === null ) {
			return true;
		}

		if ( self::isSanctionBot( $agent ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Defining the events for this extension
	 *
	 * @param array &$notifs
	 * @param array &$categories
	 * @param array &$icons
	 */
	public static function onBeforeCreateEchoEvent( &$notifs, &$categories, &$icons ) {
		$categories['sanctions-against-me'] = [
			'priority' => 1,
			'no-dismiss' => [ 'web' ],
			'tooltip' => 'sanctions-pref-tooltip-sanctions-against-me',
		];

		$notifs['sanctions-proposed'] = [
			'category' => 'sanctions-against-me',
			'group' => 'negative',
			'section' => 'alert',
			'presentation-model' => \MediaWiki\Extension\Sanctions\Notifications\ProposedPresentationModel::class,
			'user-locators' => [ [ 'EchoUserLocator::locateFromEventExtra', [ 'target-id' ] ] ],
		];
	}

	/**
	 * @param User $user
	 * @return bool
	 */
	private static function isSanctionBot( User $user ) {
		return $user->getName() === wfMessage( 'sanctions-bot-name' )->inContentLanguage()->text();
	}

	/**
	 * @param OutputPage $out
	 * @return bool
	 */
	public static function onFlowAddModules( OutputPage $out ) {
		$title = $out->getTitle();
		// Special:Sanctions
		$specialSanctionTitle = SpecialPage::getTitleFor( 'Sanctions' );
		// ProjectTalk:foobar
		$discussionPageName = wfMessage( 'sanctions-discussion-page-name' )
			->inContentLanguage()->text();

		if ( $title == null ) {
			return true;
		}

		// The Flow board for sanctions
		if ( $title->equals( Title::newFromText( $discussionPageName ) ) ) {
			// Flow does not support redirection, so implement it.
			// See https://phabricator.wikimedia.org/T102300
			$request = RequestContext::getMain()->getRequest();
			$redirect = $request->getVal( 'redirect' );
			if ( !$redirect || $redirect !== 'no' ) {
				$out->redirect( $specialSanctionTitle->getLocalURL() );
			}

			$out->addModules( 'ext.sanctions.flow-board' );

			return true;
		}

		// Each Flow topic
		$uuid = null;
		try {
			$uuid = UUID::create( strtolower( $title->getText() ) );
		} catch ( InvalidInputException $e ) {
			return true;
		}

		// Do nothing when UUID is invalid
		if ( !$uuid ) {
			return true;
		}

		// Do nothing when the topic is not about sanction
		$sanction = Sanction::newFromUUID( $uuid );
		if ( $sanction === false ) {
			return true;
		}

		$out->addModules( 'ext.sanctions.flow-topic' );

		if ( !$sanction->isExpired() ) {
			$sanction->checkNewVotes();
		}
		// else @todo mark as expired

		return true;
	}

	/**
	 * export static key and id to JavaScript
	 * @param array &$vars Array of variables to be added into the output of the startup module.
	 * @return true
	 */
	public static function onResourceLoaderGetConfigVars( &$vars ) {
		$vars['wgSanctionsAgreeTemplate'] = wfMessage( 'sanctions-agree-template-title' )
			->inContentLanguage()->text();
		$vars['wgSanctionsDisagreeTemplate'] = wfMessage( 'sanctions-disagree-template-title' )
			->inContentLanguage()->text();
		$vars['wgSanctionsInsultingNameTopicTitle'] = wfMessage( 'sanctions-type-insulting-name' )
			->inContentLanguage()->text();
		$vars['wgSanctionsMaxBlockPeriod'] = (int)wfMessage( 'sanctions-max-block-period' )
			->inContentLanguage()->text();

		return true;
	}

	/**
	 * (talk|contribs)
	 * @param int $userId User ID of the current user
	 * @param string $userText Username of the current user
	 * @param string[] &$items Array of user tool links as HTML fragments
	 * @return bool|void True or no return value to continue or false to abort
	 */
	public static function onUserToolLinksEdit( $userId, $userText, &$items ) {
		$user = RequestContext::getMain()->getUser();
		if ( $user == null || !Utils::hasVoteRight( $user ) ) {
			return true;
		}

		$items[] = Linker::link(
			SpecialPage::getTitleFor( 'Sanctions', $userText ),
			wfMessage( 'sanctions-link-on-user-tool' )->text()
		);
		return true;
	}

	/**
	 * @param RevisionRecord $newRevRecord New revision
	 * @param string[] &$links Array of HTML links
	 * @param RevisionRecord|null $oldRevRecord Old revision (may be null)
	 * @param UserIdentity $userIdentity Current user
	 * @return bool|void True or no return value to continue or false to abort
	 */
	public static function onDiffTools( RevisionRecord $newRevRecord, array &$links, ?RevisionRecord $oldRevRecord,
			UserIdentity $userIdentity ) {
		if ( !Utils::hasVoteRight( User::newFromIdentity( $userIdentity ) ) ) {
			return true;
		}

		$ids = '';
		if ( $oldRevRecord != null ) {
			$ids .= $oldRevRecord->getId() . '/';
		}
		$ids .= $newRevRecord->getId();

		$titleText = $newRevRecord->getUser()->getName() . '/' . $ids;
		$links[] = Linker::link(
			SpecialPage::getTitleFor( 'Sanctions', $titleText ),
			wfMessage( 'sanctions-link-on-diff' )->text()
		);

		return true;
	}

	/**
	 * @param RevisionRecord $revRecord
	 * @param string[] &$links Array of HTML links
	 * @param RevisionRecord|null $prevRevRecord RevisionRecord object, next in line
	 *   in page history, or null
	 * @param UserIdentity $userIdentity Current user
	 * @return bool|void True or no return value to continue or false to abort
	 */
	public static function onHistoryTools( $revRecord, &$links, $prevRevRecord, $userIdentity ) {
		if ( !Utils::hasVoteRight( User::newFromIdentity( $userIdentity ) ) ) {
			return true;
		}

		$titleText = $revRecord->getUser()->getName() . '/' . $revRecord->getId();
		$links[] = Linker::link(
			SpecialPage::getTitleFor( 'Sanctions', $titleText ),
			wfMessage( 'sanctions-link-on-history' )->text()
		);

		return true;
	}

	/**
	 * @param Skin $skin Skin object
	 * @param array[] &$sidebar An array of arrays of sidebar items.
	 */
	public static function onSidebarBeforeOutput( Skin $skin, array &$sidebar ) {
		$user = $skin->getRelevantUser();

		if ( !$user ) {
			return;
		}

		$rootUser = $user->getName();

		$sanctionsLink = [
			'sanctions' => [
				'text' => $skin->msg( 'sanctions-link-on-user-page' )->text(),
				'href' => $skin::makeSpecialUrlSubpage( 'Sanctions', $rootUser ),
				'id' => 't-sanctions'
			]
		];

		if ( !isset( $sidebar['TOOLBOX'] ) || !$sidebar['TOOLBOX'] ) {
			$sidebar['TOOLBOX'] = $sanctionsLink;
		} else {
			$toolbox = $sidebar['TOOLBOX'];

			$sidebar['TOOLBOX'] = wfArrayInsertAfter(
				$toolbox,
				$sanctionsLink,
				isset( $toolbox['blockip'] ) ? 'blockip' : 'log'
			);
		}
	}

	/**
	 * @param int $id - User identifier
	 * @param Title $title - User page title
	 * @param array &$tools - Array of tool links
	 * @param SpecialPage $sp - The SpecialPage object
	 */
	public static function onContributionsToolLinks( $id, $title, &$tools, $sp ) {
		$tools['sanctions'] = $sp->getLinkRenderer()->makeKnownLink(
				SpecialPage::getTitleFor( 'Sanctions', User::newFromId( $id ) ),
				wfMessage( 'sanctions-link-on-user-contributes' )->text()
			);
	}
}
