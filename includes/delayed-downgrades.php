<?php

/**
 * Update confirmation message.
 */
function pmprorate_pmpro_confirmation_message( $message, $invoice ) {
	if ( ! empty( $invoice ) && ! empty( $invoice->user_id ) ) {
		$delayed_downgrades = pmproprorate_get_delayed_downgrades_for_user( $invoice->user_id, $invoice->membership_id );

		if ( ! empty( $delayed_downgrades ) ) {
			$downgrading = array_shift( $delayed_downgrades ); // We should only have one delayed downgrade per level.
			$dlevel = pmpro_getLevel( $downgrading['level'] );

			$message .= "<p>";
			$message .= esc_html(
				sprintf(
					__("You will be downgraded to %s on %s", "pmpro-proration"),
				    $dlevel->name,
				    date_i18n( get_option( "date_format" ), $downgrading['date'] )
				)
			);
			$message .= "</p>";
		}
	}

	return $message;
}

add_filter( "pmpro_confirmation_message", "pmprorate_pmpro_confirmation_message", 10, 2 );

/**
 * Update account page.
 */
function pmprorate_the_content( $content ) {
	global $current_user, $pmpro_pages;

	if ( is_user_logged_in() && is_page( $pmpro_pages['account'] ) ) {
		$delayed_downgrades = pmproprorate_get_delayed_downgrades_for_user( $current_user->ID );

		if ( ! empty( $delayed_downgrades ) ) {
			foreach ( $delayed_downgrades as $key => $downgrade ) {
				// Get the level being downgraded from.
				$downgrading_from = pmproprorate_get_level_to_switch_from_for_delayed_downgrade( $downgrade );
				if ( empty( $downgrading_from ) ) {
					continue;
				}
				$downgrade_from_level = pmpro_getLevel( $downgrading_from );

				// Get the level being downgraded to.
				$downgrade_to_level = pmpro_getLevel( $downgrade['level'] );

				$downgrade_message = "<p><strong>" . esc_html__( "Important Note:", "pmpro-proration" ) . "</strong>";
				$downgrade_message .= esc_html(
					sprintf(
						__( "You will be downgraded from %s to %s on %s.", "pmpro-proration" ),
						$downgrade_from_level->name,
						$downgrade_to_level->name,
						date_i18n( get_option( "date_format" ), $downgrade['date'] )
					)
				);

				$content = $downgrade_message . $content;
			}
		}
	}

	return $content;
}

add_filter( "the_content", "pmprorate_the_content" );

/**
 * Check for level changes daily.
 */
function pmproproate_daily_check_for_membership_changes() {
	global $wpdb;

	// Get all users with scheduled level changes
	$level_changes = $wpdb->get_col( "SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key = 'pmprorate_change_to_level'" );

	// Check legacy meta name.
	$level_changes = array_merge( $level_changes, $wpdb->get_col( "SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key = 'pmpro_change_to_level'" ) );

	if ( empty( $level_changes ) ) {
		return;
	}

	foreach ( $level_changes as $user_id ) {
		pmproprorate_process_delayed_downgrades_for_user( $user_id );
	}
}

//hook to run when pmpro_cron_expire_memberships does
add_action( 'pmpro_cron_expire_memberships', 'pmproproate_daily_check_for_membership_changes' );

/**
 * Process delayed downgrades for a user if needed.
 *
 * @since TBD
 *
 * @param int $user_id The user ID.
 */
function pmproprorate_process_delayed_downgrades_for_user( $user_id ) {
	global $wpdb;

	// Get upcoming delayed downgrades for the user.
	$delayed_downgrades = pmproprorate_get_delayed_downgrades_for_user( $user_id );

	// If there are no delayed downgrades, return.
	if ( empty( $delayed_downgrades ) ) {
		return;
	}

	// Loop through upcoming delayed downgrades.
	foreach ( $delayed_downgrades as $delayed_downgrade ) {
		// If the delayed downgrade is still in the future, we don't need to process it yet. Continue.
		if ( $delayed_downgrade['date'] > current_time( 'timestamp' ) ) {
			continue;
		}

		// Get the level that we want to swtich to.
		$switch_to = $delayed_downgrade['level'];

		// Get the level that we want to switch from.
		// For recent downgrades, this info will be stored in $delayed_downgrade. For older downgrades, we'll have to take a guess.
		$switch_from = pmproprorate_get_level_to_switch_from_for_delayed_downgrade( $delayed_downgrade );

		// If we have a level to switch from, switch the user to the new level.
		if ( ! empty( $switch_from ) ) {
			// Switch the user to the new level.
			$wpdb->update(
				$wpdb->pmpro_memberships_users,
				array( 'membership_id' => $switch_to ),
				array( 'membership_id' => $switch_from, 'user_id' => $user_id, 'status' => 'active' )
			);

			// If using PMPro v3.0+, we want to switch subscriptions over as well.
			if ( class_exists( 'PMPro_Subscription' ) ) {
				$wpdb->update(
					$wpdb->pmpro_subscriptions,
					array( 'membership_level_id' => $switch_to ),
					array( 'membership_level_id' => $switch_from, 'user_id' => $user_id, 'status' => 'active' )
				);
			}
		}

		// Delete the delayed downgrade meta.
		pmproprorate_delete_scheduled_downgrade_for_user( $user_id, $delayed_downgrade );
	}
}

/**
 * Set up a delayed downgrade at checkout. This includes giving the user their original level back for the time being.
 *
 * @since TBD
 *
 * @param int $user_id The user ID to be downgraded.
 * @param int $purchased_level The level ID that the user will be downgraded to.
 * @param int $original_level_id   The level that the user that the user had previously.
 * @param int $date The timestamp of when the downgrade should occur.
 */
function pmproprorate_set_up_delayed_downgrade( $user_id, $purchased_level, $original_level_id, $date ) {
	global $wpdb;

	// Type-check everything.
	$user_id = (int)$user_id;
	$purchased_level = (int)$purchased_level;
	$original_level_id = (int)$original_level_id;
	$date = (int)$date;

	// Give the users back their old membership level.
	$updated = $wpdb->update(
		$wpdb->pmpro_memberships_users,
		array( 'membership_id' => $original_level_id ),
		array( 'membership_id' => $purchased_level, 'user_id' => $user_id, 'status' => 'active' )
	);
	if ( false === $updated ) {
		pmpro_setMessage( esc_html__( 'Problem updating membership information. Please report this to the webmaster.', 'pmpro-proration' ), 'error' );
	};

	// If using PMPro v3.0+, change any subscriptions that were created to the old level.
	if ( class_exists( 'PMPro_Subscription' ) ) {
		$wpdb->update(
			$wpdb->pmpro_subscriptions,
			array( 'membership_level_id' => $original_level_id ),
			array( 'membership_level_id' => $purchased_level, 'user_id' => $user_id, 'status' => 'active' )
		);
	}

	// Add the delayed downgrade meta.
	add_user_meta(
		$user_id,
		'pmprorate_change_to_level',
		array(
			'level'     => $purchased_level,
			'old_level' => $original_level_id,
			'date'      => $date
		)
	);
}

/**
 * Get all scheduled downgrades for a user.
 *
 * @since TBD
 *
 * @param int $user_id The user ID.
 * @param int|null $from_level The level ID to filter by.
 * @return array An array of scheduled downgrades.
 */
function pmproprorate_get_delayed_downgrades_for_user( $user_id, $from_level = null ) {
	// Get all scheduled downgrades for the user.
	$downgrades = get_user_meta( $user_id, 'pmprorate_change_to_level' );

	// Check legacy meta name.
	$downgrades = array_merge( $downgrades, get_user_meta( $user_id, 'pmpro_change_to_level' ) );

	// If we are filtering by level, remove any downgrades that aren't for that level.
	if ( ! empty( $from_level ) ) {
		foreach ( $downgrades as $key => $downgrade ) {
			if ( (int)$downgrade['level'] != (int)$from_level ) {
				unset( $downgrades[ $key ] );
			}
		}
	}

	return $downgrades;
}

/**
 * Delete a delayed downgrade for a user.
 *
 * @since TBD
 *
 * @param int $user_id The user ID.
 * @param array $delayed_downgrade The downgrade to delete.
 */
function pmproprorate_delete_scheduled_downgrade_for_user( $user_id, $delayed_downgrade ) {
	// Delete the delayed downgrade meta.
	delete_user_meta( $user_id, 'pmpro_change_to_level', $delayed_downgrade );

	// Check legacy meta name.
	delete_user_meta( $user_id, 'pmprorate_change_to_level', $delayed_downgrade );
}

/**
 * Get the level ID to switch from for a particular delayed downgrade.
 *
 * @since TBD
 *
 * @param array $delayed_downgrade The delayed downgrade.
 * @return int|null The level ID to switch from or null if not found.
 */
function pmproprorate_get_level_to_switch_from_for_delayed_downgrade( $delayed_downgrade ) {
	// For recent downgrades, this info will be stored in $delayed_downgrade.
	if ( ! empty( $delayed_downgrade['old_level'] ) ) {
		return $delayed_downgrade['old_level'];
	}

	// For older downgrades, we'll have to take a guess.
	// If using PMPro v3.0+, check if the user has another level in the same group.
	if ( function_exists( 'pmpro_get_group_id_for_level' ) ) {
		// Get user's current levels.
		$user_levels     = pmpro_getMembershipLevelsForUser( $user_id );
		$user_level_ids  = array_map( 'intval', wp_list_pluck( $user_levels, 'id' ) );

		// Get other levels in the group of the level that we are switching to.
		$group_id        = pmpro_get_group_id_for_level( $switch_to );
		$group_level_ids = array_map( 'intval', pmpro_get_level_ids_for_group( $group_id ) );

		// Intersect the two arrays to see if the user has another level in the same group.
		$intersect = array_intersect( $user_level_ids, $group_level_ids );

		// If the user has another level in the same group, set that as the level that we want to switch from.
		return ! empty( $intersect ) ? array_shift( $intersect ) : null;
	}

	// If using PMPro v2.x, just choose a membership level that the user currently has. They should only have one.
	$user_levels = pmpro_getMembershipLevelsForUser( $user_id );
	return ! empty( $user_levels ) ? (int)array_shift( $user_levels )->id : null;
}
