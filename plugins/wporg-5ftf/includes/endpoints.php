<?php
/**
 * Handle submissions to admin-ajax.php.
 */

namespace WordPressDotOrg\FiveForTheFuture\Endpoints;

use WordPressDotOrg\FiveForTheFuture\{ Auth, Contributor, Email, PledgeForm };

add_action( 'wp_ajax_manage-contributors', __NAMESPACE__ . '\manage_contributors_handler' );

/**
 * Handle the AJAX request for managing contributors on a pledge.
 * This responds to adding, removing, and resending emails to contributors.
 */
function manage_contributors_handler() {
	check_ajax_referer( 'manage-contributors', '_ajax_nonce' );

	$action         = filter_input( INPUT_POST, 'manage_action' );
	$pledge_id      = filter_input( INPUT_POST, 'pledge_id', FILTER_VALIDATE_INT );
	$contributor_id = filter_input( INPUT_POST, 'contributor_id', FILTER_VALIDATE_INT );
	$token          = filter_input( INPUT_POST, '_token' );
	$authenticated  = Auth\can_manage_pledge( $pledge_id, $token );

	if ( is_wp_error( $authenticated ) ) {
		wp_die( wp_json_encode( [
			'success' => false,
			'message' => $authenticated->get_error_message(),
		] ) );
	}

	switch ( $action ) {
		case 'resend-contributor-confirmation':
			$contribution = get_post( $contributor_id );
			Email\send_contributor_confirmation_emails( $pledge_id, $contributor_id );
			wp_die( wp_json_encode( [
				'success' => true,
				'message' => sprintf( __( 'Confirmation email sent to %s.', 'wporg-5ftf' ), $contribution->post_title ),
			] ) );
			break;

		case 'remove-contributor':
			// Trash contributor.
			Contributor\remove_contributor( $contributor_id );
			wp_die( wp_json_encode( [
				'success'      => true,
				'contributors' => Contributor\get_pledge_contributors_data( $pledge_id ),
			] ) );
			break;

		case 'add-contributor':
			$pledge = get_post( $pledge_id );
			$new_contributors = PledgeForm\parse_contributors( $_POST['contributors'] );
			if ( is_wp_error( $new_contributors ) ) {
				wp_die( wp_json_encode( [
					'success' => false,
					'message' => $new_contributors->get_error_message(),
				] ) );
			}
			$contributor_ids = Contributor\add_pledge_contributors( $pledge_id, $new_contributors );
			if ( 'publish' === $pledge->post_status ) {
				foreach ( $contributor_ids as $contributor_id ) {
					Email\send_contributor_confirmation_emails( $pledge_id, $contributor_id );
				}
			}

			// Fetch all contributors, now that the new ones have been added.
			$contributors = Contributor\get_pledge_contributors_data( $pledge_id );

			wp_die( wp_json_encode( [
				'success'      => true,
				'contributors' => $contributors,
			] ) );
			break;
	}

	// No matching action, we can just exit.
	wp_die();
}