<?php
/**
 *
 *
 * Created on Oct 16, 2006
 *
 * Copyright © 2006 Yuri Astrakhan "<Firstname><Lastname>@gmail.com"
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA.
 * http://www.gnu.org/copyleft/gpl.html
 *
 * @file
 */

/**
 * This query action adds a list of a specified user's contributions to the output.
 *
 * @ingroup API
 */
class ApiQueryContributions extends ApiQueryBase {

	public function __construct( ApiQuery $query, $moduleName ) {
		parent::__construct( $query, $moduleName, 'uc' );
	}

	private $params, $prefixMode, $userprefix, $multiUserMode, $idMode, $usernames, $userids,
		$parentLens, $commentStore;
	private $fld_ids = false, $fld_title = false, $fld_timestamp = false,
		$fld_comment = false, $fld_parsedcomment = false, $fld_flags = false,
		$fld_patrolled = false, $fld_tags = false, $fld_size = false, $fld_sizediff = false;

	public function execute() {
		// Parse some parameters
		$this->params = $this->extractRequestParams();

		$this->commentStore = new CommentStore( 'rev_comment' );

		$prop = array_flip( $this->params['prop'] );
		$this->fld_ids = isset( $prop['ids'] );
		$this->fld_title = isset( $prop['title'] );
		$this->fld_comment = isset( $prop['comment'] );
		$this->fld_parsedcomment = isset( $prop['parsedcomment'] );
		$this->fld_size = isset( $prop['size'] );
		$this->fld_sizediff = isset( $prop['sizediff'] );
		$this->fld_flags = isset( $prop['flags'] );
		$this->fld_timestamp = isset( $prop['timestamp'] );
		$this->fld_patrolled = isset( $prop['patrolled'] );
		$this->fld_tags = isset( $prop['tags'] );

		// Most of this code will use the 'contributions' group DB, which can map to replica DBs
		// with extra user based indexes or partioning by user. The additional metadata
		// queries should use a regular replica DB since the lookup pattern is not all by user.
		$dbSecondary = $this->getDB(); // any random replica DB

		// TODO: if the query is going only against the revision table, should this be done?
		$this->selectNamedDB( 'contributions', DB_REPLICA, 'contributions' );

		$this->requireOnlyOneParameter( $this->params, 'userprefix', 'userids', 'user' );

		$this->idMode = false;
		if ( isset( $this->params['userprefix'] ) ) {
			$this->prefixMode = true;
			$this->multiUserMode = true;
			$this->userprefix = $this->params['userprefix'];
		} elseif ( isset( $this->params['userids'] ) ) {
			$this->userids = [];

			if ( !count( $this->params['userids'] ) ) {
				$encParamName = $this->encodeParamName( 'userids' );
				$this->dieWithError( [ 'apierror-paramempty', $encParamName ], "paramempty_$encParamName" );
			}

			foreach ( $this->params['userids'] as $uid ) {
				if ( $uid <= 0 ) {
					$this->dieWithError( [ 'apierror-invaliduserid', $uid ], 'invaliduserid' );
				}

				$this->userids[] = $uid;
			}

			$this->prefixMode = false;
			$this->multiUserMode = ( count( $this->params['userids'] ) > 1 );
			$this->idMode = true;
		} else {
			$anyIPs = false;
			$this->userids = [];
			$this->usernames = [];
			if ( !count( $this->params['user'] ) ) {
				$encParamName = $this->encodeParamName( 'user' );
				$this->dieWithError(
					[ 'apierror-paramempty', $encParamName ], "paramempty_$encParamName"
				);
			}
			foreach ( $this->params['user'] as $u ) {
				if ( $u === '' ) {
					$encParamName = $this->encodeParamName( 'user' );
					$this->dieWithError(
						[ 'apierror-paramempty', $encParamName ], "paramempty_$encParamName"
					);
				}

				if ( User::isIP( $u ) ) {
					$anyIPs = true;
					$this->usernames[] = $u;
				} else {
					$name = User::getCanonicalName( $u, 'valid' );
					if ( $name === false ) {
						$encParamName = $this->encodeParamName( 'user' );
						$this->dieWithError(
							[ 'apierror-baduser', $encParamName, wfEscapeWikiText( $u ) ], "baduser_$encParamName"
						);
					}
					$this->usernames[] = $name;
				}
			}
			$this->prefixMode = false;
			$this->multiUserMode = ( count( $this->params['user'] ) > 1 );

			if ( !$anyIPs ) {
				$dbr = $this->getDB();
				$res = $dbr->select( 'user', 'user_id', [ 'user_name' => $this->usernames ], __METHOD__ );
				foreach ( $res as $row ) {
					$this->userids[] = $row->user_id;
				}
				$this->idMode = count( $this->userids ) === count( $this->usernames );
			}
		}

		$this->prepareQuery();

		$hookData = [];
		// Do the actual query.
		$res = $this->select( __METHOD__, [], $hookData );

		if ( $this->fld_sizediff ) {
			$revIds = [];
			foreach ( $res as $row ) {
				if ( $row->rev_parent_id ) {
					$revIds[] = $row->rev_parent_id;
				}
			}
			$this->parentLens = Revision::getParentLengths( $dbSecondary, $revIds );
			$res->rewind(); // reset
		}

		// Initialise some variables
		$count = 0;
		$limit = $this->params['limit'];

		// Fetch each row
		foreach ( $res as $row ) {
			if ( ++$count > $limit ) {
				// We've reached the one extra which shows that there are
				// additional pages to be had. Stop here...
				$this->setContinueEnumParameter( 'continue', $this->continueStr( $row ) );
				break;
			}

			$vals = $this->extractRowInfo( $row );
			$fit = $this->processRow( $row, $vals, $hookData ) &&
				$this->getResult()->addValue( [ 'query', $this->getModuleName() ], null, $vals );
			if ( !$fit ) {
				$this->setContinueEnumParameter( 'continue', $this->continueStr( $row ) );
				break;
			}
		}

		$this->getResult()->addIndexedTagName(
			[ 'query', $this->getModuleName() ],
			'item'
		);
	}

	/**
	 * Prepares the query and returns the limit of rows requested
	 */
	private function prepareQuery() {
		// We're after the revision table, and the corresponding page
		// row for anything we retrieve. We may also need the
		// recentchanges row and/or tag summary row.
		$user = $this->getUser();
		$tables = [ 'page', 'revision' ]; // Order may change
		$this->addWhere( 'page_id=rev_page' );

		// Handle continue parameter
		if ( !is_null( $this->params['continue'] ) ) {
			$continue = explode( '|', $this->params['continue'] );
			$db = $this->getDB();
			if ( $this->multiUserMode ) {
				$this->dieContinueUsageIf( count( $continue ) != 4 );
				$modeFlag = array_shift( $continue );
				$this->dieContinueUsageIf( !in_array( $modeFlag, [ 'id', 'name' ] ) );
				if ( $this->idMode && $modeFlag === 'name' ) {
					// The users were created since this query started, but we
					// can't go back and change modes now. So just keep on with
					// name mode.
					$this->idMode = false;
				}
				$this->dieContinueUsageIf( ( $modeFlag === 'id' ) !== $this->idMode );
				$userField = $this->idMode ? 'rev_user' : 'rev_user_text';
				$encUser = $db->addQuotes( array_shift( $continue ) );
			} else {
				$this->dieContinueUsageIf( count( $continue ) != 2 );
			}
			$encTS = $db->addQuotes( $db->timestamp( $continue[0] ) );
			$encId = (int)$continue[1];
			$this->dieContinueUsageIf( $encId != $continue[1] );
			$op = ( $this->params['dir'] == 'older' ? '<' : '>' );
			if ( $this->multiUserMode ) {
				$this->addWhere(
					"$userField $op $encUser OR " .
					"($userField = $encUser AND " .
					"(rev_timestamp $op $encTS OR " .
					"(rev_timestamp = $encTS AND " .
					"rev_id $op= $encId)))"
				);
			} else {
				$this->addWhere(
					"rev_timestamp $op $encTS OR " .
					"(rev_timestamp = $encTS AND " .
					"rev_id $op= $encId)"
				);
			}
		}

		// Don't include any revisions where we're not supposed to be able to
		// see the username.
		if ( !$user->isAllowed( 'deletedhistory' ) ) {
			$bitmask = Revision::DELETED_USER;
		} elseif ( !$user->isAllowedAny( 'suppressrevision', 'viewsuppressed' ) ) {
			$bitmask = Revision::DELETED_USER | Revision::DELETED_RESTRICTED;
		} else {
			$bitmask = 0;
		}
		if ( $bitmask ) {
			$this->addWhere( $this->getDB()->bitAnd( 'rev_deleted', $bitmask ) . " != $bitmask" );
		}

		// We only want pages by the specified users.
		if ( $this->prefixMode ) {
			$this->addWhere( 'rev_user_text' .
				$this->getDB()->buildLike( $this->userprefix, $this->getDB()->anyString() ) );
		} elseif ( $this->idMode ) {
			$this->addWhereFld( 'rev_user', $this->userids );
		} else {
			$this->addWhereFld( 'rev_user_text', $this->usernames );
		}
		// ... and in the specified timeframe.
		// Ensure the same sort order for rev_user/rev_user_text and rev_timestamp
		// so our query is indexed
		if ( $this->multiUserMode ) {
			$this->addWhereRange( $this->idMode ? 'rev_user' : 'rev_user_text',
				$this->params['dir'], null, null );
		}
		$this->addTimestampWhereRange( 'rev_timestamp',
			$this->params['dir'], $this->params['start'], $this->params['end'] );
		// Include in ORDER BY for uniqueness
		$this->addWhereRange( 'rev_id', $this->params['dir'], null, null );

		$this->addWhereFld( 'page_namespace', $this->params['namespace'] );

		$show = $this->params['show'];
		if ( $this->params['toponly'] ) { // deprecated/old param
			$show[] = 'top';
		}
		if ( !is_null( $show ) ) {
			$show = array_flip( $show );

			if ( ( isset( $show['minor'] ) && isset( $show['!minor'] ) )
				|| ( isset( $show['patrolled'] ) && isset( $show['!patrolled'] ) )
				|| ( isset( $show['top'] ) && isset( $show['!top'] ) )
				|| ( isset( $show['new'] ) && isset( $show['!new'] ) )
			) {
				$this->dieWithError( 'apierror-show' );
			}

			$this->addWhereIf( 'rev_minor_edit = 0', isset( $show['!minor'] ) );
			$this->addWhereIf( 'rev_minor_edit != 0', isset( $show['minor'] ) );
			$this->addWhereIf( 'rc_patrolled = 0', isset( $show['!patrolled'] ) );
			$this->addWhereIf( 'rc_patrolled != 0', isset( $show['patrolled'] ) );
			$this->addWhereIf( 'rev_id != page_latest', isset( $show['!top'] ) );
			$this->addWhereIf( 'rev_id = page_latest', isset( $show['top'] ) );
			$this->addWhereIf( 'rev_parent_id != 0', isset( $show['!new'] ) );
			$this->addWhereIf( 'rev_parent_id = 0', isset( $show['new'] ) );
		}
		$this->addOption( 'LIMIT', $this->params['limit'] + 1 );

		// Mandatory fields: timestamp allows request continuation
		// ns+title checks if the user has access rights for this page
		// user_text is necessary if multiple users were specified
		$this->addFields( [
			'rev_id',
			'rev_timestamp',
			'page_namespace',
			'page_title',
			'rev_user',
			'rev_user_text',
			'rev_deleted'
		] );

		if ( isset( $show['patrolled'] ) || isset( $show['!patrolled'] ) ||
			$this->fld_patrolled
		) {
			if ( !$user->useRCPatrol() && !$user->useNPPatrol() ) {
				$this->dieWithError( 'apierror-permissiondenied-patrolflag', 'permissiondenied' );
			}

			// Use a redundant join condition on both
			// timestamp and ID so we can use the timestamp
			// index
			$index['recentchanges'] = 'rc_user_text';
			if ( isset( $show['patrolled'] ) || isset( $show['!patrolled'] ) ) {
				// Put the tables in the right order for
				// STRAIGHT_JOIN
				$tables = [ 'revision', 'recentchanges', 'page' ];
				$this->addOption( 'STRAIGHT_JOIN' );
				$this->addWhere( 'rc_user_text=rev_user_text' );
				$this->addWhere( 'rc_timestamp=rev_timestamp' );
				$this->addWhere( 'rc_this_oldid=rev_id' );
			} else {
				$tables[] = 'recentchanges';
				$this->addJoinConds( [ 'recentchanges' => [
					'LEFT JOIN', [
						'rc_user_text=rev_user_text',
						'rc_timestamp=rev_timestamp',
						'rc_this_oldid=rev_id' ] ] ] );
			}
		}

		$this->addTables( $tables );
		$this->addFieldsIf( 'rev_page', $this->fld_ids );
		$this->addFieldsIf( 'page_latest', $this->fld_flags );
		// $this->addFieldsIf( 'rev_text_id', $this->fld_ids ); // Should this field be exposed?
		$this->addFieldsIf( 'rev_len', $this->fld_size || $this->fld_sizediff );
		$this->addFieldsIf( 'rev_minor_edit', $this->fld_flags );
		$this->addFieldsIf( 'rev_parent_id', $this->fld_flags || $this->fld_sizediff || $this->fld_ids );
		$this->addFieldsIf( 'rc_patrolled', $this->fld_patrolled );

		if ( $this->fld_comment || $this->fld_parsedcomment ) {
			$commentQuery = $this->commentStore->getJoin();
			$this->addTables( $commentQuery['tables'] );
			$this->addFields( $commentQuery['fields'] );
			$this->addJoinConds( $commentQuery['joins'] );
		}

		if ( $this->fld_tags ) {
			$this->addTables( 'tag_summary' );
			$this->addJoinConds(
				[ 'tag_summary' => [ 'LEFT JOIN', [ 'rev_id=ts_rev_id' ] ] ]
			);
			$this->addFields( 'ts_tags' );
		}

		if ( isset( $this->params['tag'] ) ) {
			$this->addTables( 'change_tag' );
			$this->addJoinConds(
				[ 'change_tag' => [ 'INNER JOIN', [ 'rev_id=ct_rev_id' ] ] ]
			);
			$this->addWhereFld( 'ct_tag', $this->params['tag'] );
		}

		if ( isset( $index ) ) {
			$this->addOption( 'USE INDEX', $index );
		}
	}

	/**
	 * Extract fields from the database row and append them to a result array
	 *
	 * @param stdClass $row
	 * @return array
	 */
	private function extractRowInfo( $row ) {
		$vals = [];
		$anyHidden = false;

		if ( $row->rev_deleted & Revision::DELETED_TEXT ) {
			$vals['texthidden'] = true;
			$anyHidden = true;
		}

		// Any rows where we can't view the user were filtered out in the query.
		$vals['userid'] = (int)$row->rev_user;
		$vals['user'] = $row->rev_user_text;
		if ( $row->rev_deleted & Revision::DELETED_USER ) {
			$vals['userhidden'] = true;
			$anyHidden = true;
		}
		if ( $this->fld_ids ) {
			$vals['pageid'] = intval( $row->rev_page );
			$vals['revid'] = intval( $row->rev_id );
			// $vals['textid'] = intval( $row->rev_text_id ); // todo: Should this field be exposed?

			if ( !is_null( $row->rev_parent_id ) ) {
				$vals['parentid'] = intval( $row->rev_parent_id );
			}
		}

		$title = Title::makeTitle( $row->page_namespace, $row->page_title );

		if ( $this->fld_title ) {
			ApiQueryBase::addTitleInfo( $vals, $title );
		}

		if ( $this->fld_timestamp ) {
			$vals['timestamp'] = wfTimestamp( TS_ISO_8601, $row->rev_timestamp );
		}

		if ( $this->fld_flags ) {
			$vals['new'] = $row->rev_parent_id == 0 && !is_null( $row->rev_parent_id );
			$vals['minor'] = (bool)$row->rev_minor_edit;
			$vals['top'] = $row->page_latest == $row->rev_id;
		}

		if ( $this->fld_comment || $this->fld_parsedcomment ) {
			if ( $row->rev_deleted & Revision::DELETED_COMMENT ) {
				$vals['commenthidden'] = true;
				$anyHidden = true;
			}

			$userCanView = Revision::userCanBitfield(
				$row->rev_deleted,
				Revision::DELETED_COMMENT, $this->getUser()
			);

			if ( $userCanView ) {
				$comment = $this->commentStore->getComment( $row )->text;
				if ( $this->fld_comment ) {
					$vals['comment'] = $comment;
				}

				if ( $this->fld_parsedcomment ) {
					$vals['parsedcomment'] = Linker::formatComment( $comment, $title );
				}
			}
		}

		if ( $this->fld_patrolled ) {
			$vals['patrolled'] = (bool)$row->rc_patrolled;
		}

		if ( $this->fld_size && !is_null( $row->rev_len ) ) {
			$vals['size'] = intval( $row->rev_len );
		}

		if ( $this->fld_sizediff
			&& !is_null( $row->rev_len )
			&& !is_null( $row->rev_parent_id )
		) {
			$parentLen = isset( $this->parentLens[$row->rev_parent_id] )
				? $this->parentLens[$row->rev_parent_id]
				: 0;
			$vals['sizediff'] = intval( $row->rev_len - $parentLen );
		}

		if ( $this->fld_tags ) {
			if ( $row->ts_tags ) {
				$tags = explode( ',', $row->ts_tags );
				ApiResult::setIndexedTagName( $tags, 'tag' );
				$vals['tags'] = $tags;
			} else {
				$vals['tags'] = [];
			}
		}

		if ( $anyHidden && $row->rev_deleted & Revision::DELETED_RESTRICTED ) {
			$vals['suppressed'] = true;
		}

		return $vals;
	}

	private function continueStr( $row ) {
		if ( $this->multiUserMode ) {
			if ( $this->idMode ) {
				return "id|$row->rev_user|$row->rev_timestamp|$row->rev_id";
			} else {
				return "name|$row->rev_user_text|$row->rev_timestamp|$row->rev_id";
			}
		} else {
			return "$row->rev_timestamp|$row->rev_id";
		}
	}

	public function getCacheMode( $params ) {
		// This module provides access to deleted revisions and patrol flags if
		// the requester is logged in
		return 'anon-public-user-private';
	}

	public function getAllowedParams() {
		return [
			'limit' => [
				ApiBase::PARAM_DFLT => 10,
				ApiBase::PARAM_TYPE => 'limit',
				ApiBase::PARAM_MIN => 1,
				ApiBase::PARAM_MAX => ApiBase::LIMIT_BIG1,
				ApiBase::PARAM_MAX2 => ApiBase::LIMIT_BIG2
			],
			'start' => [
				ApiBase::PARAM_TYPE => 'timestamp'
			],
			'end' => [
				ApiBase::PARAM_TYPE => 'timestamp'
			],
			'continue' => [
				ApiBase::PARAM_HELP_MSG => 'api-help-param-continue',
			],
			'user' => [
				ApiBase::PARAM_TYPE => 'user',
				ApiBase::PARAM_ISMULTI => true
			],
			'userids' => [
				ApiBase::PARAM_TYPE => 'integer',
				ApiBase::PARAM_ISMULTI => true
			],
			'userprefix' => null,
			'dir' => [
				ApiBase::PARAM_DFLT => 'older',
				ApiBase::PARAM_TYPE => [
					'newer',
					'older'
				],
				ApiBase::PARAM_HELP_MSG => 'api-help-param-direction',
			],
			'namespace' => [
				ApiBase::PARAM_ISMULTI => true,
				ApiBase::PARAM_TYPE => 'namespace'
			],
			'prop' => [
				ApiBase::PARAM_ISMULTI => true,
				ApiBase::PARAM_DFLT => 'ids|title|timestamp|comment|size|flags',
				ApiBase::PARAM_TYPE => [
					'ids',
					'title',
					'timestamp',
					'comment',
					'parsedcomment',
					'size',
					'sizediff',
					'flags',
					'patrolled',
					'tags'
				],
				ApiBase::PARAM_HELP_MSG_PER_VALUE => [],
			],
			'show' => [
				ApiBase::PARAM_ISMULTI => true,
				ApiBase::PARAM_TYPE => [
					'minor',
					'!minor',
					'patrolled',
					'!patrolled',
					'top',
					'!top',
					'new',
					'!new',
				],
				ApiBase::PARAM_HELP_MSG => [
					'apihelp-query+usercontribs-param-show',
					$this->getConfig()->get( 'RCMaxAge' )
				],
			],
			'tag' => null,
			'toponly' => [
				ApiBase::PARAM_DFLT => false,
				ApiBase::PARAM_DEPRECATED => true,
			],
		];
	}

	protected function getExamplesMessages() {
		return [
			'action=query&list=usercontribs&ucuser=Example'
				=> 'apihelp-query+usercontribs-example-user',
			'action=query&list=usercontribs&ucuserprefix=192.0.2.'
				=> 'apihelp-query+usercontribs-example-ipprefix',
		];
	}

	public function getHelpUrls() {
		return 'https://www.mediawiki.org/wiki/Special:MyLanguage/API:Usercontribs';
	}
}
