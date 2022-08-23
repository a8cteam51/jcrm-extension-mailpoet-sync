<?php
/**
 * TODO: Convert to PHP Class
 */

add_action( 'wp_ajax_zbs_mailpoet_sync_export_segment', 'zeroBSCRM_AJAX_exportSegmentToMailPoet' );

function create_mailpoet_mailing_list( $name, $subscribers ) {

	if ( ! class_exists( \MailPoet\API\API::class ) ) {
		die("MailPoet API class doesn't exist");
	}

	$mailpoet_api = \MailPoet\API\API::MP('v1');

	$list = get_mailpoet_list_by_name( $name );

	if ( empty( $list ) ) {
		$list = $mailpoet_api->addList( array(
			'name' => $name,
			'description' => 'Created by Jetpack CRM' )
		);
	}

	if ( empty($list) || empty($list['id']) ) {
		//throw error
		return NULL;
	}

	$subscribers = array_slice($subscribers, 0, 5);
	$subscribers[] = array(
		'email' => 'john@newuser.com', // TODO: NOT WORKING <<<<<<<<<<<<<<<<<<<<<<<<<<<<<
		'fname' => 'John',
		'lname' => 'Doe',
	);

	foreach( $subscribers as $sc ) {
		
		try {
			$subscriber = $mailpoet_api->getSubscriber( $sc['email'] );
			// Found an existing subscriber
			if ( ! empty( $subscriber['id'] ) ) {
				$x = $mailpoet_api->subscribeToList(
					$subscriber['id'],
					$list['id'],
					array(
						'send_confirmation_email' => false,
						'schedule_welcome_email' => false,
						'skip_subscriber_notification' => true,
					)
				);
			}
		} catch (\Throwable $th) {
			//throw $th;
			// Subscriber not found. Create from scratch
			if ( empty( $subscriber['id'] ) ) {
				$mailpoet_api->addSubscriber(
					array(
						'email' => $sc['email'],
						'first_name' => $sc['fname'],
						'last_name' => $sc['lname'],
					),
					array( $list['id'] ),
					array(
						'send_confirmation_email' => false,
						'schedule_welcome_email' => false,
						'skip_subscriber_notification' => true,
					)
				);
			}
		}
	}
}

function get_mailpoet_list_by_name( $name ) {
	$mailpoet_api = \MailPoet\API\API::MP('v1');
	$lists = $mailpoet_api->getLists();
	$found = array_filter($lists, function ($i) use($name) {
		return ($i['name'] == $name);
	});

	return array_pop($found);
}

/**
 * Most of this logic was taken from Preview Segment in ZeroBSCRM.AJAX.php
 * It uses the same form and AJAX nonce
 */
function zeroBSCRM_AJAX_exportSegmentToMailPoet() {

	// } Check nonce
	check_ajax_referer( 'zbs-ajax-nonce', 'sec' );

	// either way
	header( 'Content-Type: application/json' );

	if ( current_user_can( 'admin_zerobs_customers' ) ) {

		global $zbs;

		// sanitize?
		$segmentID = -1;
		if ( isset( $_POST['sID'] ) ) {
			$segmentID = (int) sanitize_text_field( $_POST['sID'] );
		}
		$segmentTitle = __( 'Untitled Segment', 'zero-bs-crm' );
		if ( isset( $_POST['sTitle'] ) ) {
			$segmentTitle = sanitize_text_field( $_POST['sTitle'] );
		}
		$segmentMatchType = 'all';
		if ( isset( $_POST['sMatchType'] ) ) {
			$segmentMatchType = sanitize_text_field( $_POST['sMatchType'] );
		}
		$segmentConditions = array();
		if ( isset( $_POST['sConditions'] ) ) {
			$segmentConditions = zeroBSCRM_segments_filterConditions( $_POST['sConditions'], false );
		}

		// optional 2.90+ can just pass id and this'll fill the conditions from saved
		if ( $segmentID > 0 && count( $segmentConditions ) == 0 ) {

			$potentialSegment = $zbs->DAL->segments->getSegment( $segmentID, true );
			if ( is_array( $potentialSegment ) && isset( $potentialSegment['id'] ) ) {
				$segment           = $potentialSegment;
				$segmentConditions = $segment['conditions'];
				$segmentMatchType  = $segment['matchtype'];
				$segmentTitle      = $segment['name'];
			}
		}

		// attempt to build a top 5 customer list + total count for segment
		// $ret = $zbs->DAL->segments->previewSegment( $segmentConditions, $segmentMatchType );

		// todo: $this->segmentConditionsToArgs, when converted to PHP Class
		try {
			$contactGetArgs                = segmentConditionsToArgs( $segmentConditions, $segmentMatchType );
			$contactGetArgs['sortByField'] = 'ID';
			$contactGetArgs['sortOrder']   = 'DESC';
			$contactGetArgs['perPage']     = 100000;
			$contactGetArgs['ignoreowner'] = zeroBSCRM_DAL2_ignoreOwnership( ZBS_TYPE_CONTACT );

			$segment_contacts = $zbs->DAL->contacts->getContacts( $contactGetArgs );

			if ( is_array( $segment_contacts ) ) {
				//echo json_encode( $segment_contacts );

				// TODO: wp_schedule <<<
				// Create MailPoet Mailing List
				create_mailpoet_mailing_list( $segmentTitle, $segment_contacts );

				echo json_encode(
					array(
						'segmentID' => $segmentID,
						'ok'     	=> true,
					)
				);
				exit();
			}
		} catch (\Throwable $th) {
			//throw $th;
			var_dump($th);
		}
	}

	// empty handed
	echo json_encode(
		array(
			'segmentID' => $segmentID,
			'ok'     	=> false,
		)
	);
	exit();
}

/**
 * used by previewSegment and getSegmentAudience to build condition args
 */
function segmentConditionsToArgs( $conditions = array(), $matchType = 'all' ) {

	if ( is_array( $conditions ) && count( $conditions ) > 0 ) {

		$contactGetArgs = array();
		$conditionIndx  = 0; // this allows multiple queries for SAME field (e.g. status = x or status = y)

		// cycle through & add to contact request arr
		foreach ( $conditions as $condition ) {
			// TODO: $this->segmentConditionArgs
			$newArgs         = segmentConditionArgs( $condition, $conditionIndx );
			$additionalWHERE = false;

			// legit? merge (must be recursive)
			if ( is_array( $newArgs ) ) {
				$contactGetArgs = array_merge_recursive( $contactGetArgs, $newArgs );
			}

			$conditionIndx++;

		}

		// match type ALL is default, this switches to ANY
		if ( $matchType == 'one' ) {
			$contactGetArgs['whereCase'] = 'OR';
		}

		return $contactGetArgs;

	}

	return array();

}

/**
 * Segment rules
 * takes a condition + returns a contact dal2 get arr param
 */
function segmentConditionArgs( $condition = array(), $conditionKeySuffix = '' ) {

	if ( is_array( $condition ) && isset( $condition['type'] ) && isset( $condition['operator'] ) ) {

		global $zbs,$wpdb,$ZBSCRM_t;

		switch ( $condition['type'] ) {

			case 'status':
				/*
						 while this is right, it doesn't allow for MULTIPLE status cond lines, so do via sql:
				if ($condition['operator'] == 'equal')
					return array('hasStatus'=>$condition['value']);
				else
					return array('otherStatus'=>$condition['value']);
				*/
				if ( $condition['operator'] == 'equal' ) {
					return array(
						'additionalWhereArr' => array( 'statusEqual' . $conditionKeySuffix => array( 'zbsc_status', '=', '%s', $condition['value'] ) ),
					);
				} else {
					return array(
						'additionalWhereArr' => array( 'statusEqual' . $conditionKeySuffix => array( 'zbsc_status', '<>', '%s', $condition['value'] ) ),
					);
				}

				break;

			case 'fullname':
				if ( $condition['operator'] == 'equal' ) {
					return array(
						'additionalWhereArr' =>
															array( 'fullnameEqual' . $conditionKeySuffix => array( "CONCAT(zbsc_fname,' ',zbsc_lname)", '=', '%s', $condition['value'] ) ),
					);
				} elseif ( $condition['operator'] == 'notequal' ) {
					return array(
						'additionalWhereArr' =>
															array( 'fullnameEqual' . $conditionKeySuffix => array( "CONCAT(zbsc_fname,' ',zbsc_lname)", '<>', '%s', $condition['value'] ) ),
					);
				} elseif ( $condition['operator'] == 'contains' ) {
					return array(
						'additionalWhereArr' =>
															array( 'fullnameEqual' . $conditionKeySuffix => array( "CONCAT(zbsc_fname,' ',zbsc_lname)", 'LIKE', '%s', '%' . $condition['value'] . '%' ) ),
					);
				}
				break;

			case 'email':
				if ( $condition['operator'] == 'equal' ) {
					// while this is right, it doesn't allow for MULTIPLE status cond lines, so do via sql:
					// return array('hasEmail'=>$condition['value']);
					/*
								 // this was good, but was effectively AND
					return array('additionalWhereArr'=>
								array(
									'email'.$conditionKeySuffix=>array('zbsc_email','=','%s',$condition['value']),
									'emailAKA'.$conditionKeySuffix=>array('ID','IN',"(SELECT aka_id FROM ".$ZBSCRM_t['aka']." WHERE aka_type = ".ZBS_TYPE_CONTACT." AND aka_alias = %s)",$condition['value'])
									)
							);
					*/
					// This was required to work with OR (e.g. postcode 1 = x or postcode 2 = x)
					// -----------------------
					// This generates a query like 'zbsc_fname LIKE %s OR zbsc_lname LIKE %s',
					// which we then need to include as direct subquery
					/*
								 THIS WORKS: but refactored below
					$conditionQArr = $this->buildWheres(array(
														'email'.$conditionKeySuffix=>array('zbsc_email','=','%s',$condition['value']),
														'emailAKA'.$conditionKeySuffix=>array('ID','IN',"(SELECT aka_id FROM ".$ZBSCRM_t['aka']." WHERE aka_type = ".ZBS_TYPE_CONTACT." AND aka_alias = %s)",$condition['value'])
														),'',array(),'OR',false);
					if (is_array($conditionQArr) && isset($conditionQArr['where']) && !empty($conditionQArr['where'])){
						return array('additionalWhereArr'=>array('direct'=>array(array('('.$conditionQArr['where'].')',$conditionQArr['params']))));
					}
					return array();
					*/
					// this way for OR situations
					return $this->segmentBuildDirectOrClause(
						array(
							'email' . $conditionKeySuffix => array( 'zbsc_email', '=', '%s', $condition['value'] ),
							'emailAKA' . $conditionKeySuffix => array( 'ID', 'IN', '(SELECT aka_id FROM ' . $ZBSCRM_t['aka'] . ' WHERE aka_type = ' . ZBS_TYPE_CONTACT . ' AND aka_alias = %s)', $condition['value'] ),
						),
						'OR'
					);
					// -----------------------
				} elseif ( $condition['operator'] == 'notequal' ) {
					return array(
						'additionalWhereArr' =>
															array(
																'notEmail' . $conditionKeySuffix => array( 'zbsc_email', '<>', '%s', $condition['value'] ),
																'notEmailAka' . $conditionKeySuffix => array( 'ID', 'NOT IN', '(SELECT aka_id FROM ' . $ZBSCRM_t['aka'] . ' WHERE aka_type = ' . ZBS_TYPE_CONTACT . ' AND aka_alias = %s)', $condition['value'] ),
															),
					);
				} elseif ( $condition['operator'] == 'contains' ) {
					return array(
						'additionalWhereArr' =>
															array( 'emailContains' . $conditionKeySuffix => array( 'zbsc_email', 'LIKE', '%s', '%' . $condition['value'] . '%' ) ),
					);
				}
				break;

			// TBA (When DAL2 trans etc.)
			case 'totalval':
				break;

			case 'dateadded':
				// contactedAfter
				if ( $condition['operator'] == 'before' ) {
					// while this is right, it doesn't allow for MULTIPLE status cond lines, so do via sql:
					// return array('olderThan'=>$condition['value']);
					return array(
						'additionalWhereArr' =>
															array( 'olderThan' . $conditionKeySuffix => array( 'zbsc_created', '<=', '%d', $condition['value'] ) ),
					);
				} elseif ( $condition['operator'] == 'after' ) {
					// while this is right, it doesn't allow for MULTIPLE status cond lines, so do via sql:
					// return array('newerThan'=>$condition['value']);
					return array(
						'additionalWhereArr' =>
															array( 'newerThan' . $conditionKeySuffix => array( 'zbsc_created', '>=', '%d', $condition['value'] ) ),
					);
				} elseif ( $condition['operator'] == 'daterange' ) {

					$before = false;
					$after  = false;
					// split out the value
					if ( isset( $condition['value'] ) && ! empty( $condition['value'] ) ) {
						$after = (int) $condition['value'];
					}
					if ( isset( $condition['value2'] ) && ! empty( $condition['value2'] ) ) {
						$before = (int) $condition['value2'];
					}

					// while this is right, it doesn't allow for MULTIPLE status cond lines, so do via sql:
					// return array('newerThan'=>$after,'olderThan'=>$before);
					return array(
						'additionalWhereArr' =>
															array(
																'newerThan' . $conditionKeySuffix => array( 'zbsc_created', '>=', '%d', $condition['value'] ),
																'olderThan' . $conditionKeySuffix => array( 'zbsc_created', '<=', '%d', $condition['value2'] ),
															),
					);

				}

				break;

			case 'datelastcontacted':
				// contactedAfter
				if ( $condition['operator'] == 'before' ) {
					// while this is right, it doesn't allow for MULTIPLE status cond lines, so do via sql:
					// return array('contactedBefore'=>$condition['value']);
					return array(
						'additionalWhereArr' =>
															array( 'contactedBefore' . $conditionKeySuffix => array( 'zbsc_lastcontacted', '<=', '%d', $condition['value'] ) ),
					);
				} elseif ( $condition['operator'] == 'after' ) {
					// while this is right, it doesn't allow for MULTIPLE status cond lines, so do via sql:
					// return array('contactedAfter'=>$condition['value']);
					return array(
						'additionalWhereArr' =>
															array( 'contactedAfter' . $conditionKeySuffix => array( 'zbsc_lastcontacted', '>=', '%d', $condition['value'] ) ),
					);
				} elseif ( $condition['operator'] == 'daterange' ) {

					$before = false;
					$after  = false;
					// split out the value
					if ( isset( $condition['value'] ) && ! empty( $condition['value'] ) ) {
						$after = (int) $condition['value'];
					}
					if ( isset( $condition['value2'] ) && ! empty( $condition['value2'] ) ) {
						$before = (int) $condition['value2'];
					}

					// while this is right, it doesn't allow for MULTIPLE status cond lines, so do via sql:
					// return array('contactedAfter'=>$after,'contactedBefore'=>$before);
					return array(
						'additionalWhereArr' =>
															array(
																'contactedAfter' . $conditionKeySuffix => array( 'zbsc_lastcontacted', '>=', '%d', $after ),
																'contactedBefore' . $conditionKeySuffix => array( 'zbsc_lastcontacted', '<=', '%d', $before ),
															),
					);
				}

				break;

			case 'tagged':
				// while this is right, it doesn't allow for MULTIPLE status cond lines, so do via sql:
				// return array('isTagged'=>$condition['value']);
				// NOTE
				// ... this is a DIRECT query, so format for adding here is a little diff
				// ... and only works (not overriding existing ['direct']) because the calling func of this func has to especially copy separately
				return array(
					'additionalWhereArr' =>
										array(
											'direct' => array(
												array( '(SELECT COUNT(ID) FROM ' . $ZBSCRM_t['taglinks'] . ' WHERE zbstl_objtype = %d AND zbstl_objid = contact.ID AND zbstl_tagid = %d) > 0', array( ZBS_TYPE_CONTACT, $condition['value'] ) ),
											),
										),
				);

				break;

			case 'nottagged':
				// while this is right, it doesn't allow for MULTIPLE status cond lines, so do via sql:
				// return array('isNotTagged'=>$condition['value']);

				// NOTE
				// ... this is a DIRECT query, so format for adding here is a little diff
				// ... and only works (not overriding existing ['direct']) because the calling func of this func has to especially copy separately
				return array(
					'additionalWhereArr' =>
										array(
											'direct' => array(
												array( '(SELECT COUNT(ID) FROM ' . $ZBSCRM_t['taglinks'] . ' WHERE zbstl_objtype = %d AND zbstl_objid = contact.ID AND zbstl_tagid = %d) = 0', array( ZBS_TYPE_CONTACT, $condition['value'] ) ),
											),
										),
				);
				break;

			default:
				// Allow for custom segmentArgument builders
				if ( ! empty( $condition['type'] ) ) {

					$filterTag     = $this->makeSlug( $condition['type'] ) . '_zbsSegmentArgumentBuild';
					$potentialArgs = apply_filters( $filterTag, false, $condition, $conditionKeySuffix );

					// got anything back?
					if ( $potentialArgs !== false ) {
						return $potentialArgs;
					}
				}

				break;

		}
	}

	// if we get here we've failed to create any arguments for this condiition
	// ... to avoid scenarios such as mail campaigns going out to 'less filtered than intended' audiences
	// ... we throw an error
	$this->error_condition_exception(
		'segment_condition_produces_no_args',
		__( 'Segment Condition produces no filtering arguments', 'zero-bs-crm' ),
		array( 'condition' => $condition )
	);

	return false;

}
