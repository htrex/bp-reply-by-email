<?php
/**
 * BP Reply By Email Classes
 *
 * @package BP_Reply_By_Email
 * @subpackage Classes
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Handles checking an IMAP inbox and posting items to BuddyPress.
 *
 * @package BP_Reply_By_Email
 * @subpackage Classes
 */
class BP_Reply_By_Email_IMAP {

	/**
	 * Holds the single-running RBE IMAP object.
	 *
	 * @var BP_Reply_By_Email_IMAP
	 */
	private static $instance = false;

	/**
	 * Holds the current IMAP connection.
	 */
	protected $connection = false;

	/**
	 * Creates a singleton instance of the BP_Reply_By_Email_IMAP class
	 *
	 * @return BP_Reply_By_Email_IMAP object
	 * @static
	 */
	public static function &init() {
		if ( self::$instance === false ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Private constructor. Intentionally left empty.
	 *
	 * Instantiate this class by using {@link BP_Reply_By_Email_IMAP::init()}.
	 */
	private function __construct() {}

	/**
	 * The main method we use to parse an IMAP inbox.
	 */
	public function run() {
		global $bp, $wpdb;

		// $instance must be initialized before we go on!
		if ( self::$instance === false )
			return false;

		// If safe mode isn't on, then let's set the execution time to unlimited
		if ( ! ini_get( 'safe_mode' ) )
			set_time_limit(0);

		// Try to connect
		$connect = $this->connect();

		if ( ! $connect ) {
			return false;
		}

		// Total duration we should keep the IMAP stream alive for in seconds
		$duration = bp_rbe_get_execution_time();
		bp_rbe_log( '--- Keep alive for ' . $duration / 60 . ' minutes ---' );

		// Mark the current timestamp, mark the future time when we should close the IMAP connection;
		// Do our parsing until $future > $now; re-mark the timestamp at end of loop... rinse and repeat!
		for ( $now = time(), $future = time() + $duration; $future > $now; $now = time() ) :

			// Get number of messages
			$message_count = imap_num_msg( $this->connection );

			// If there are messages in the inbox, let's start parsing!
			if( $message_count != 0 ) :

				// According to this:
				// http://www.php.net/manual/pl/function.imap-headerinfo.php#95012
				// This speeds up rendering the email headers... could be wrong
				imap_headers( $this->connection );

				bp_rbe_log( '- Checking inbox -' );

				// Loop through each email message
				for ( $i = 1; $i <= $message_count; ++$i ) :

					// Email header check ******************************************

					$headers = $this->header_parser( $this->connection, $i );

					if ( !$headers ) {
						do_action( 'bp_rbe_imap_no_match', $this->connection, $i, false, 'no_headers' );
						continue;
					}

					bp_rbe_log( 'Message #' . $i . ' of ' . $message_count . ': email headers successfully parsed' );

					// Querystring check *******************************************

					$qs = self::get_address_tag( self::address_parser( $headers, 'To' ) );

					if ( !$qs ) {
						do_action( 'bp_rbe_imap_no_match', $this->connection, $i, $headers, 'no_address_tag' );
						continue;
					}

					bp_rbe_log( 'Message #' . $i . ': address tag successfully parsed' );

					// User check **************************************************

					$email = self::address_parser( $headers, 'From' );
					bp_rbe_log( 'User email address is ' . $email );

					$user_id = email_exists( $email );

					if ( !$user_id ) {
						do_action( 'bp_rbe_imap_no_match', $this->connection, $i, $headers, 'no_user_id' );
						continue;
					}

					bp_rbe_log( 'Message #' . $i . ': user id successfully parsed - user id is - ' . $user_id );

					// Spammer check ***********************************************

					if ( bp_core_is_user_spammer( $user_id ) ) {
						do_action( 'bp_rbe_imap_no_match', $this->connection, $i, $headers, 'user_is_spammer' );
						continue;
					}

					// Parameters parser *******************************************

					// Check if we're posting a new item or not
					if ( $this->is_new_item( $qs ) )
						$params = $this->querystring_parser( $qs, $user_id );
					else
						$params = $this->querystring_parser( $qs );

					if ( !$params ) {
						do_action( 'bp_rbe_imap_no_match', $this->connection, $i, $headers, 'no_params' );
						continue;
					}

					bp_rbe_log( 'Message #' . $i . ': params = ' . print_r( $params, true ) );

					// Email body parser *******************************************

					$body = self::body_parser( $this->connection, $i );

					// If there's no email body and this is a reply, stop!
					if ( !$body && !$this->is_new_item( $qs ) ) {
						do_action( 'bp_rbe_imap_no_match', $this->connection, $i, $headers, 'no_reply_body' );
						continue;
					}

					// log the body for replied items
					if ( !$this->is_new_item( $qs ) )
						bp_rbe_log( 'Message #' . $i . ': body contents - ' . $body );

					// Extract params **********************************************

					extract( $params );

					// Posting time! ***********************************************

					// Activity reply
					if ( !empty( $a ) ) :
						bp_rbe_log( 'Message #' . $i . ': this is an activity reply, checking if parent activities still exist' );

						// Check to see if the root activity ID and the parent activity ID exist before posting
						$activity_count = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$bp->activity->table_name} WHERE id IN ( {$a}, {$p} );" ) );

						// If $a = $p, this means that we're replying to a top-level activity update
						// So check if activity count is 1
						if ( $a == $p && $activity_count != 1 ) {
							do_action( 'bp_rbe_imap_no_match', $this->connection, $i, $headers, 'root_activity_deleted' );
							continue;
						}
						// If we're here, this means we're replying to an activity comment
						// If count != 2, this means either the super admin or activity author has deleted one of the update(s)
						elseif ( $a != $p && $activity_count != 2 ) {
							do_action( 'bp_rbe_imap_no_match', $this->connection, $i, $headers, 'root_or_parent_activity_deleted' );
							continue;
						}

						/* Let's start posting! */
						// Add our filter to override the activity action in bp_activity_new_comment()
						bp_rbe_activity_comment_action_filter( $user_id );

						$comment_id = bp_activity_new_comment(
							 array(
								'content'	=> $body,
								'user_id'	=> $user_id,
								'activity_id'	=> $a, // ID of the root activity item
								'parent_id'	=> $p  // ID of the parent comment
							)
						);

						if ( ! $comment_id ) {
							do_action( 'bp_rbe_imap_no_match', $this->connection, $i, $headers, 'activity_comment_fail' );
							continue;
						}

						// special hook for RBE activity items
						// might want to do something like add some activity meta
						do_action( 'bp_rbe_new_activity', array(
							'activity_id'       => $comment_id,
							'type'              => 'activity_comment',
							'user_id'           => $user_id,
							'item_id'           => $a,
							'secondary_item_id' => $p,
							'content'           => $body
						) );

						bp_rbe_log( 'Message #' . $i . ': activity comment successfully posted!' );

						// remove the filter after posting
						remove_filter( 'bp_activity_comment_action', 'bp_rbe_activity_comment_action' );

						// unset some variables
						unset( $comment_id );
						unset( $activity_count );
						unset( $a );
						unset( $p );

					// Forum reply
					elseif ( !empty( $t ) ) :

						if ( bp_is_active( $bp->groups->id ) && bp_is_active( $bp->forums->id ) ) :
							bp_rbe_log( 'Message #' . $i . ': this is a forum reply' );

							// get all group member data for the user in one swoop!
							$group_member_data = bp_rbe_get_group_member_info( $user_id, $g );

							// user is not a member of the group anymore
							if ( empty( $group_member_data ) ) {
								do_action( 'bp_rbe_imap_no_match', $this->connection, $i, $headers, 'user_not_group_member' );
								continue;
							}

							// user is banned from group
							if ( (int) $group_member_data->is_banned == 1 ) {
								do_action( 'bp_rbe_imap_no_match', $this->connection, $i, $headers, 'user_banned_from_group' );
								continue;
							}

							/* okay, we should be good to post now! */

							$forum_post_id = bp_rbe_groups_new_group_forum_post( array(
								'post_text' => $body,
								'topic_id'  => $t,
								'user_id'   => $user_id,
								'group_id'  => $g
							) );

							if ( !$forum_post_id ) {
								do_action( 'bp_rbe_imap_no_match', $this->connection, $i, $headers, 'forum_reply_fail' );
								continue;
							}

							bp_rbe_log( 'Message #' . $i . ': forum reply successfully posted!' );

							// could potentially add attachments
							do_action( 'bp_rbe_new_forum_post', $this->connection, $forum_post_id, $user_id, $g, $headers );

							unset( $t );
							unset( $group_member_data );
							unset( $forum_post_id );
						endif;

					// Private message reply
					elseif ( !empty( $m ) ) :
						if ( bp_is_active( $bp->messages->id ) ) :
							bp_rbe_log( 'Message #' . $i . ': this is a private message reply' );

							// see if the PM thread still exists
							if ( messages_is_valid_thread( $m ) ) {

								// see if the user is in the PM conversation
								$has_access = messages_check_thread_access( $m, $user_id );

								if ( !$has_access ) {
									do_action( 'bp_rbe_imap_no_match', $this->connection, $i, $headers, 'private_message_not_in_thread' );
									continue;
								}

								// post the PM!
								$message_id = messages_new_message (
									array(
										'thread_id' => $m,
										'sender_id' => $user_id,
										'content'   => $body
									)
								);

								if ( ! $message_id ) {
									do_action( 'bp_rbe_imap_no_match', $this->connection, $i, $headers, 'private_message_fail' );
									continue;
								}

								// special hook for RBE parsed PMs
								do_action( 'bp_rbe_new_pm_reply', array(
									'thread_id' => $m,
									'sender_id' => $user_id,
									'content'   => $body
								) );

								bp_rbe_log( 'Message #' . $i . ': PM reply successfully posted!' );

								unset( $message_id );
								unset( $has_access );
							}

							// the PM thread doesn't exist anymore
							else {
								do_action( 'bp_rbe_imap_no_match', $this->connection, $i, $headers, 'private_message_thread_deleted' );
								continue;
							}

							unset( $m );
						endif;

					// New forum topic
					elseif ( !empty( $g ) ) :

						if ( bp_is_active( $bp->groups->id ) && bp_is_active( $bp->forums->id ) ) :
							bp_rbe_log( 'Message #' . $i . ': this is a new forum topic' );

							$body    = self::body_parser( $this->connection, $i, false );
							$subject = self::address_parser( $headers, 'Subject' );

							bp_rbe_log( 'Message #' . $i . ': body contents - ' . $body );
							bp_rbe_log( 'Subject - ' . $subject );

							if ( empty( $body ) || empty( $subject ) ) {
								do_action( 'bp_rbe_imap_no_match', $this->connection, $i, $headers, 'new_forum_topic_empty' );
								continue;
							}

							// get all group member data for the user in one swoop!
							$group_member_data = bp_rbe_get_group_member_info( $user_id, $g );

							// user is not a member of the group anymore
							if ( empty( $group_member_data ) ) {
								do_action( 'bp_rbe_imap_no_match', $this->connection, $i, $headers, 'user_not_group_member' );
								continue;
							}

							// user is banned from group
							if ( (int) $group_member_data->is_banned == 1 ) {
								do_action( 'bp_rbe_imap_no_match', $this->connection, $i, $headers, 'user_banned_from_group' );
								continue;
							}

							/* okay, we should be good to post now! */

							$topic = bp_rbe_groups_new_group_forum_topic( array(
								'topic_title' => $subject,
								'topic_text'  => $body,
								'user_id'     => $user_id,
								'group_id'    => $g
							) );

							if ( !$topic ) {
								do_action( 'bp_rbe_imap_no_match', $this->connection, $i, $headers, 'new_topic_fail' );
								continue;
							}

							bp_rbe_log( 'Message #' . $i . ': forum topic successfully posted!' );

							// could potentially add attachments
							do_action( 'bp_rbe_new_forum_topic', $this->connection, $topic, $user_id, $g, $headers );

							unset( $g );
							unset( $subject );
							unset( $group_member_data );
						endif;
					endif;

					// Do something at the end of the loop; useful for 3rd-party plugins
					do_action( 'bp_rbe_imap_loop', $this->connection, $i, $params, $body, $user_id );

					// Unset some variables to clear some memory
					unset( $headers );
					unset( $qs );
					unset( $email );
					unset( $user_id );
					unset( $params );
					unset( $body );
				endfor;

				// do something after the loop
				do_action( 'bp_rbe_imap_after_loop', $this->connection );

			endif;

			// stop the loop if necessary
			if ( bp_rbe_should_stop() ) {
				if ( $this->close() ) {
					bp_rbe_log( '--- Manual termination of connection confirmed! Kaching! ---' );
				}
				else {
					bp_rbe_log( '--- Error - invalid connection during manual termination ---' );
				}
				return;
			}

			// Give IMAP server a break
			sleep( 10 );

			// If the IMAP connection is down, reconnect
			if( ! imap_ping( $this->connection ) ) {
				bp_rbe_log( '-- IMAP connection is down, attempting to reconnect... --' );

				// attempt to reconnect
				$reopen = imap_reopen( $this->connection, $this->get_mailbox() );

				if ( $reopen ) {
					bp_rbe_log( '-- Reconnection successful! --' );
				}
				else {
					bp_rbe_log( '-- Reconnection failed! :( --' );
					bp_rbe_log( 'Cannot connect: ' . imap_last_error() );

					// cleanup RBE after failure
					bp_rbe_cleanup();
				}
			}

			// Unset some variables to clear some memory
			unset( $message_count );
		endfor;

		if ( $this->close() ) {
			bp_rbe_log( '--- Closing current connection automatically ---' );

			// clear our scheduled hook to prevent wryly cron irregularity
			// see https://github.com/r-a-y/bp-reply-by-email/issues/5
			wp_clear_scheduled_hook( 'bp_rbe_schedule' );

			// since we clear our scheduled hook, it would take two page hits:
			// the first to reschedule the task and the second to run our task

			// so let's create a marker so we can spawn cron manually on the first page hit
			// @see bp_rbe_cron()

			// note: this does add an extra DB query on each page load...
			//       perhaps make this a toggleable option via a define?
			//       this would be beneficial for very active sites looking
			//       to optimize their DB queries
			bp_update_option( 'bp_rbe_spawn_cron', 1 );
		}
		else {
			bp_rbe_log( '--- Invalid connection during close time ---' );
		}

		exit();
	}

	/**
	 * Connects to the IMAP inbox.
	 *
	 * @return bool
	 */
	private function connect() {
		global $bp_rbe;

		bp_rbe_log( '--- Attempting to start new connection... ---' );

		// if our DB marker says we're already connected, stop now!
		// this is an extra precaution
		if ( bp_rbe_is_connected() ) {
			bp_rbe_log( '--- RBE is already connected! ---' );
			return false;
		}

		// decode the password
		$password = bp_rbe_decode( array( 'string' => $bp_rbe->settings['password'], 'key' => wp_salt() ) );

		// Let's open the IMAP stream!
		$this->connection = @imap_open( $this->get_mailbox(), $bp_rbe->settings['username'], $password );

		if ( $this->connection === false ) {
			bp_rbe_log( 'Cannot connect: ' . imap_last_error() );
			return false;
		}

		// add an entry in the DB to say that we're connected so we can access this info on other pages
		bp_update_option( 'bp_rbe_is_connected', true );

		bp_rbe_log( '--- Connection successful! ---' );

		return true;
	}

	/**
	 * Closes the IMAP connection.
	 *
	 * @return bool
	 */
	private function close() {
		// Do something before closing
		do_action( 'bp_rbe_imap_before_close', $this->connection );

		if ( $this->is_connected()  ) {
			@imap_close( $this->connection );
			bp_update_option( 'bp_rbe_is_connected', 0 );
			return true;
		}

		return false;
	}

	/**
	 * Check to see if the IMAP connection is connected.
	 *
	 * @return bool
	 */
	private function is_connected() {
		if ( ! is_resource( $this->connection ) ) {
			bp_rbe_log( '-- There is no active IMAP connection --' );
			return false;
		}

		return true;
	}

	/**
	 * Get the mailbox we want to connect to.
	 *
	 * This, basically, returns the first parameter of {@link imap_open()}, which
	 * is also the second parameter of {@link imap_reopen()}.
	 *
	 * @return string
	 */
	public function get_mailbox() {
		global $bp_rbe;

		// This needs some testing...
		$ssl = bp_rbe_is_imap_ssl() ? '/ssl' : '';

		// Need to readjust this before public release
		// In the meantime, let's add a filter!
		$mailbox = '{' . $bp_rbe->settings['servername'] . ':' . $bp_rbe->settings['port'] . '/imap' . $ssl . '}INBOX';
		$mailbox = apply_filters( 'bp_rbe_mailbox', $mailbox );

		return $mailbox;
	}

	/**
	 * Grabs and parses an email message's header and returns an array with each header item.
	 *
	 * @uses imap_fetchheader() Grabs full, raw unmodified email header
	 * @param resource $imap The current IMAP connection
	 * @param int $i The current email message number
	 * @return mixed Array of email headers. False if no headers or if the email is junk.
	 */
	public function header_parser( $imap, $i ) {
		// Grab full, raw email header
		$header = imap_fetchheader( $imap, $i );

		// No header? Return false
		if ( empty( $header ) ) {
			bp_rbe_log( 'Message #' . $i . ': error - no IMAP header' );
			return false;
		}

		// Do a regex match
		$pattern = apply_filters( 'bp_rbe_header_regex', '/([^: ]+): (.+?(?:\r\n\s(?:.+?))*)\r\n/m' );
		preg_match_all( $pattern, $header, $matches );

		// Parse headers into an array with descriptive key
		$headers = array_combine( $matches[1], $matches[2] );

		// No headers? Return false
		if ( empty( $headers ) ) {
			bp_rbe_log( 'Message #' . $i . ': error - no headers found' );
			return false;
		}

		// 'X-AutoReply' header check
		if ( ! empty( $headers['X-Autoreply'] ) && $headers['X-Autoreply'] == 'yes' ) {
			bp_rbe_log( 'Message #' . $i . ': error - this is an autoreply message, so stop now!' );
			return false;
		}

		// 'Precedence' header check
		// Test to see if our email is an out of office automated reply or mailing list email
		// See http://en.wikipedia.org/wiki/Email#Header_fields
		if ( ! empty( $headers['Precedence'] ) ) :
			switch ( $headers['Precedence'] ) {
				case 'bulk' :
				case 'junk' :
				case 'list' :
					bp_rbe_log( 'Message #' . $i . ': error - this is some type of bulk / junk / mailing list email, so stop now!' );
					return false;
				break;
			}
		endif;

		// 'Auto-Submitted' header check
		// See https://tools.ietf.org/html/rfc3834#section-5
		if ( ! empty( $headers['Auto-Submitted'] ) ) :
			switch ( strtolower( $headers['Auto-Submitted'] ) ) {
				case 'auto-replied' :
				case 'auto-generated' :
					bp_rbe_log( 'Message #' . $i . ': error - this is an auto-reply using the "Auto-Submitted" header, so stop now!' );
					return false;
				break;
			}
		endif;

		// 'X-Auto-Response-Suppress' header check
		// used in MS Exchange mail servers
		// See http://msdn.microsoft.com/en-us/library/ee219609%28v=EXCHG.80%29.aspx
		if ( ! empty( $headers['X-Auto-Response-Suppress'] ) ) :
			switch ( $headers['X-Auto-Response-Suppress'] ) {
				// non-standard value, but seems to be in use
				case 'All' :

				// these are official values
				case 'OOF' :
				case 'AutoReply' :
					bp_rbe_log( 'Message #' . $i . ': error - this is auto-reply from MS Exchange, so stop now!' );
					return false;
				break;
			}
		endif;

		// 'X-FC-MachineGenerated' header check
		// used in FirstClass mail servers
		if ( ! empty( $headers['X-FC-MachineGenerated'] ) ) {
			bp_rbe_log( 'Message #' . $i . ': error - this is an auto-reply from FirstClass mail, so stop now!' );
			return false;
		}

		// @todo Perhaps implement more auto-reply checks from this:
		// http://wiki.exim.org/EximAutoReply#Router-1

		// Want to do more checks? Here's the filter!
		return apply_filters( 'bp_rbe_parse_email_headers', $headers, $header );
	}

	/**
	 * Parses the body of an email message.
	 *
	 * Tries to fetch the plain-text version when available first. Otherwise, will fallback to the HTML version.
	 *
	 * @uses imap_fetchstructure() Get the structure of an email
	 * @uses imap_fetchbody() Using the third parameter will return a portion of the email depending on the email structure.
	 * @param resource $imap The current IMAP connection
	 * @param int $i The current email message number
	 * @param bool $reply If we're parsing a reply or not. Default set to true.
	 * @return mixed Either the email body on success or false on failure
	 */
	public static function body_parser( $imap, $i, $reply = true ) {
		// get the email structure
		$structure = imap_fetchstructure( $imap, $i );

		// this is a multipart email
		if ( ! empty( $structure->parts ) ) {
			// check each sub-part of a multipart email
			for ( $j = 0, $k = count( $structure->parts ); $j < $k; ++$j ) {
				// get the plain-text message only
				if ( strtolower( $structure->parts[$j]->subtype ) == 'plain' ) {
					$body = imap_fetchbody( $imap, $i, $j+1 );
					continue;
				}
			}
		}

		// either a plain-text email or a HTML email
		else {
			$body = imap_body( $imap, $i );
		}

		// decode emails with the following encoding
		switch ( $structure->encoding ) {
			// quoted-printable
			case 4 :
				$body = quoted_printable_decode( $body );
				break;

			// base64
			case 3 :
				$body = base64_decode( $body );
				break;
		}

		// do something special for emails that only contain HTML
		if ( strtolower( $structure->subtype ) == 'html' ) {
			$body = apply_filters( 'bp_rbe_parse_html_email', $body, $structure );
		}

		// Check to see if we're parsing a reply
		if ( $reply ) {

			// Find our pointer
			$pointer = strpos( $body, __( '--- Reply ABOVE THIS LINE to add a comment ---', 'bp-rbe' ) );

			// If our pointer isn't found, return false
			if ( $pointer === false )
				return false;

			// Return email body up to our pointer only
			$body = apply_filters( 'bp_rbe_parse_email_body_reply', trim( substr( $body, 0, $pointer ) ), $body, $structure );
		}

		// this means we're posting something new (eg. new forum topic)
		// do something special for this case
		else {
			$body = apply_filters( 'bp_rbe_parse_email_body_new', $body, $structure );
		}

		if ( empty( $body ) ) {
			bp_rbe_log( 'Message #' . $i . ': empty body' );
			return false;
		}

		return apply_filters( 'bp_rbe_parse_email_body', trim( $body ), $structure );
	}

	/**
	 * Parses an email header to return just the email address.
	 *
	 * eg. r-a-y <test@gmail.com> -> test@gmail.com
	 *
	 * @param array $headers The array of email headers
	 * @param string $key The key we want to check against the array.
	 * @return mixed Either the email address on success or false on failure
	 */
	public static function address_parser( $headers, $key ) {
		if ( empty( $headers[$key] ) ) {
			bp_rbe_log( $key . ' parser - empty key' );
			return false;
		}

		if ( $key == 'To' && strpos( $headers[$key], '@' ) === false ) {
			bp_rbe_log( $key . ' parser - missing email address' );
			return false;
		}

		// Sender is attempting to send to multiple recipients in the "To" header
		// A legit BP reply will not add multiple recipients, so let's return false
		if ( $key == 'To' && strpos( $headers['To'], ',' ) !== false ) {
			bp_rbe_log( $key . ' parser - multiple recipients - so stop!' );
			return false;
		}

		// grab email address in between triangular brackets if they exist
		// strip the rest
		$lbracket = strpos( $headers[$key], '<' );

		if ( $lbracket !== false ) {
			$rbracket = strpos( $headers[$key], '>' );

			$headers[$key] = substr( $headers[$key], ++$lbracket, $rbracket - $lbracket );
		}

		//bp_rbe_log( $key . ' parser - ' . $headers[$key] );

		return $headers[$key];
	}

	/**
	 * Returns the address tag from an email address.
	 *
	 * eg. test+tag@gmail.com> -> tag
	 * In BP Reply By Email IMAP, this is an encoded querystring.
	 *
	 * @param string $address The email address containing the address tag
	 * @return mixed Either the address tag on success or false on failure
	 */
	public static function get_address_tag( $address ) {
		global $bp_rbe;

		// $address might already be false, so let's return false right away
		if ( !$address )
			return false;

		$at  = strpos( $address, '@' );
		$tag = strpos( $address, $bp_rbe->settings['tag'] );

		if ( $at === false || $tag === false )
			return false;

		return substr( $address, ++$tag, $at - $tag );
	}

	/**
	 * Decodes the encoded querystring from {@link BP_Reply_By_Email_IMAP::get_address_tag()}.
	 * Then, extracts the params into an array.
	 *
	 * @uses bp_rbe_decode() To decode the encoded querystring
	 * @uses wp_parse_str() WP's version of parse_str() to parse the querystring
	 * @param string $qs The encoded address tag we want to decode
	 * @param int $user_id  The user ID. New posted items will pass this parameter for decoding. See inline doc of function for more details.
	 * @return mixed Either an array of params on success or false on failure
	 */
	private function querystring_parser( $qs, $user_id = false ) {

		// New posted items will pass $user_id along with $qs for decoding
		// This is done as an additional security measure because the "From" header
		// can be spoofed and is similar to how Basecamp handles posting new items
		if ( $user_id ) {
			// check to see if $user_id is numeric, if not, return false
			if ( !is_numeric( $user_id ) )
				return false;

			// new items will always have "-new" appended to the querystring
			$new = strrpos( $qs, '-new' );

			if ( $new !== false ) {
				// get rid of "-new" from the querystring
				$qs = substr( $qs, 0, $new );

				// pass $user_id to bp_rbe_decode()
				$qs = apply_filters( 'bp_rbe_decode_qs', bp_rbe_decode( array( 'string' => $qs, 'param' => $user_id ) ), $qs, $user_id );
			}
			else
				return false;
		}

		// Replied items will use the regular $qs for decoding
		else {
			$qs = apply_filters( 'bp_rbe_decode_qs', bp_rbe_decode( array( 'string' => $qs ) ), $qs, $user_id );
		}

		// These are the default params we want to check for
		$defaults = array(
			'a' => false, // root activity id
			'p' => false, // direct parent activity id
			't' => false, // topic id
			'm' => false, // message thread id
			'g' => false  // group id
		);

		// Let 3rd-party plugins whitelist additional params
		$defaults = apply_filters( 'bp_rbe_allowed_params', $defaults );

		// Parse querystring into an array
		wp_parse_str( $qs, $params );

		// Only allow parameters set from $defaults through
		$params = array_intersect_key( $params, $defaults );

		// If no params, return false
		if ( empty( $params ) )
			return false;

		return $params;
	}

	/**
	 * Check to see if we're parsing a new item (like a new forum topic).
	 *
	 * New items will always have "-new" appended to the address tag. This is what we're checking for.
	 * eg. djlkjkdjfkd-new = true
	 *     jkljd8fujkdjkdf = false
	 *
	 * @param string $qs The address tag we're checking for.
	 * @return bool
	 */
	private function is_new_item( $qs ) {
		$new = '-new';

		if ( substr( $qs, -strlen( $new ) ) == $new )
			return true;

		return false;
	}
}

?>