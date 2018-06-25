<?php

use Faker\Factory;

/**
 * Rewrites personally identifying information (PII) in user profiles and comments.
 *
 * @package nullvariable\wpcli-gdpr-sanitizer
 */
class GDPR_Sanitizer extends \WP_CLI_Command {
	/**
	 * User ids to skip.
	 *
	 * @var array
	 */
	protected $excluded_user_ids = array();
	/**
	 * If we can't find a user id for a name or email, should we bail?
	 *
	 * @var boolean
	 */
	protected $skip_not_found_users = false;
	/**
	 * Site id to restrict rewrite to.
	 *
	 * @var integer
	 */
	protected $limit_to_site = null;

	/**
	 * Performs personal information replacement.
	 *
	 * ## OPTIONS
	 *
	 * [--keep=<user_id|user_login|email>]
	 * : user(s) to skip during replacement.
	 *
	 * [--skip-not-found]
	 * : skip users to keep if not found, fails otherwise.
	 *
	 * [--site=<site_id>]
	 * : site id to limit rewrites to.
	 *
	 * ## EXAMPLES
	 *
	 *     # Rewrite all user profiles and comments.
	 *     $ wp gdpr-sanitizer
	 *     Success: Rewrote all user data.
	 *
	 *     # Rewrite all user profiles except for user_id 123.
	 *     $ wp gdpr-sanitizer --keep=1
	 *     Success: All comments and users except: '1' rewritten.
	 *
	 *     # Rewrite all user profiles except ones matching user id 123, user login admin, and/or test@example.com and skip those if not found.
	 *     $ wp gdpr-sanitizer --keep="2,admin,test@example.com" --skip-not-found
	 *     Success: All comments and users except: '2,1,3' rewritten.
	 *
	 *     # Rewrite only comments and users for one site on a multi-site install.
	 *     $ wp gdpr-sanitizer --site=3
	 *     Success: All comments and users on site '3' rewritten.
	 */
	public function __invoke( $args, $assoc_args ) {
		if ( ! empty( $args ) ) {
			WP_CLI::warning( 'unknown argument' );
		}
		if ( isset( $assoc_args['keep'] ) && ! empty( $assoc_args['keep'] ) ) {
			$this->set_excluded_user_ids( $assoc_args['keep'] );
		}
		if ( isset( $assoc_args['skip-not-found'] ) ) {
			$this->skip_not_found_users = true;
		}
		if ( isset( $assoc_args['site'] ) ) {
			if ( ! is_multisite() ) {
				WP_CLI::error( 'site parameter only valid on multi-site installs.' );
			}
			if ( is_numeric( $assoc_args['site'] ) ) {
				$this->limit_to_site = (int) $assoc_args['site'];

			} else {
				WP_CLI::error( 'site must be a number' );
			}
		}

		WP_CLI::confirm( 'Rewrite all user data?', $assoc_args );
		$users_updated    = $this->obfuscate_users();
		$comments_updated = $this->obfuscate_comments();
		$items            = array(
			'Users'    => $users_updated,
			'Comments' => $comments_updated,
		);
		WP_CLI\Utils\format_items( 'table', $items, array( 'Updated', 'Count' ) );
		if ( count( $this->excluded_user_ids ) > 0 ) {
			$ids_string = implode( ',', $this->excluded_user_ids );
			if ( ! empty( $this->limit_to_site ) ) {
				WP_CLI::success( sprintf(
					'All comments and users except: \'%s\' on site \'%s\' rewritten.',
					$ids_string,
					$this->limit_to_site
				) );
			} else {
				WP_CLI::success( sprintf( 'All comments and users except: \'%s\' rewritten.', $ids_string ) );
			}
		} else {
			if ( ! empty( $this->limit_to_site ) ) {
				WP_CLI::success( sprintf( 'All comments and users on site \'%s\' rewritten.' ) );
			} else {
				WP_CLI::success( sprintf( 'All comments and users rewritten.' ) );
			}
		}
	}

	/**
	 * Rewrite the PII found in standard WordPress comments.
	 *
	 * @return integer Number of comments updated.
	 */
	protected function obfuscate_comments() {
		$faker        = Factory::create();
		$count        = 0;
		$all_comments = array();

		if ( ! is_multisite() ) {
			$data                        = $this->gather_comments( null );
			$count                       = count( $data );
			$all_comments['single_site'] = $data;
		} else {
			if ( ! empty( $this->limit_to_site ) ) {
				$site = get_site( $this->limit_to_site );
				if ( ! empty( $site ) ) {
					$data                           = $this->gather_comments( $site->blog_id );
					$count                          = $count + count( $data );
					$all_comments[ $site->blog_id ] = $data;
				} else {
					WP_CLI::error( 'Site not found.' );
				}
			} else {
				$sites = get_sites();
				foreach ( $sites as $site ) {
					$data                           = $this->gather_comments( $site->blog_id );
					$count                          = $count + count( $data );
					$all_comments[ $site->blog_id ] = $data;
				}
			}
		}

		$progress = \WP_CLI\Utils\make_progress_bar( 'Rewriting comments...', $count );
		foreach ( $all_comments as $blog_id => $comments ) {
			foreach ( $comments as $comment ) {
				if ( 'single_site' !== $blog_id ) {
					switch_to_blog( $blog_id );
				}
				$commentarr                         = $comment->to_array();
				$commentarr['comment_author']       = $faker->name;
				$commentarr['comment_author_email'] = $faker->safeEmail;
				$commentarr['comment_author_url']   = $faker->url;
				$commentarr['comment_author_IP']    = $faker->ipv4;
				$commentarr['comment_agent']        = $faker->userAgent;
				/**
				 * Pre-update Comment.
				 *
				 * Triggered before a single comment is updated with fake information. Allows you to modify custom meta fields when the plugin is triggered.
				 *
				 * @since 1.0.0
				 *
				 * @param WP_Comment $comment original WP_Comment object.
				 * @param array $commentarr new data about to be written to the database.
				 * @param Factory $faker the faker object, made available for you to generate fake data for meta fields etc.
				 */
				do_action( 'gdpr_sanitizer_pre_update_comment', $comment, $commentarr, $faker );
				wp_update_comment( $commentarr );
				/**
				 * Post update comment.
				 *
				 * Triggered after a single comment is updated with fake information.
				 *
				 * @since 1.0.0
				 *
				 * @param WP_Comment $comment original WP_Comment object.
				 * @param array $commentarr new data about to be written to the database.
				 * @param Factory $faker the faker object, made available for you to generate fake data for meta fields etc.
				 */
				do_action( 'gdpr_sanitizer_post_update_comment', $comment, $commentarr, $faker );
				$progress->tick();
				restore_current_blog();
			}
		}
		$progress->finish();
		return $count;
	}

	/**
	 * Gather comments for a single blog.
	 *
	 * @param integer $blog_id blog id.
	 *
	 * @return array
	 */
	protected function gather_comments( $blog_id ) {
		if ( is_int( $blog_id ) ) {
			switch_to_blog( $blog_id );
		}
		$trash_comments   = get_comments( array( 'status' => 'trash' ) );
		$spam_comments    = get_comments( array( 'status' => 'spam' ) );
		$regular_comments = get_comments();

		restore_current_blog();

		return array_merge( $regular_comments, $trash_comments, $spam_comments );
	}

	/**
	 * Loop over all the users found and replace their personal data.
	 *
	 * @return integer Number of users updated.
	 */
	protected function obfuscate_users() {
		$users = array();
		if ( is_multisite() ) {
			if ( ! empty( $this->limit_to_site ) ) {
				$sites[] = get_site( $this->limit_to_site );
			} else {
				$sites = get_sites();
			}
			foreach ( $sites as $site ) {
				$site_users = get_users(
					array(
						'blog_id' => $site->blog_id,
						'exclude' => $this->excluded_user_ids,
					)
				);
				$users      = array_merge( $users, $site_users );
			}
		} else {
			$users = get_users(
				array(
					'exclude' => $this->excluded_user_ids,
				)
			);
		}
		if ( count( $users ) <= 0 ) {
			WP_CLI::warning( 'No users changed (did you exclude them all?)' );
			return 0;
		}
		$count    = count( $users );
		$progress = \WP_CLI\Utils\make_progress_bar( 'Rewriting users...', $count );
		foreach ( $users as $user ) {
			$this->obfuscate_user( $user );
			$progress->tick();
		}
		$progress->finish();

		return $count;
	}

	/**
	 * Replace a single user's data.
	 *
	 * @param WP_User $user WordPress user object.
	 * @return void
	 */
	private function obfuscate_user( $user ) {
		$faker         = Factory::create();
		$original_user = $user;
		$new_data      = array(
			'user_pass'     => $faker->password,
			'user_nicename' => $faker->name,
			'user_email'    => $faker->safeEmail,
			'user_url'      => $faker->url,
			'display_name'  => $faker->firstName,
			'user_login'    => $this->generate_unused_user_login(),
		);
		foreach ( $new_data as $key => $value ) {
			$user->{$key} = $value;
		}

		/**
		 * Pre update user.
		 *
		 * Triggered after a single user is updated with fake information.
		 *
		 * @since 1.0.0
		 *
		 * @param WP_User $original_user original WP_User object.
		 * @param WP_User $user new data about to be written to the database.
		 * @param Factory $faker the faker object, made available for you to generate fake data for meta fields etc.
		 */
		do_action( 'gdpr_sanitizer_pre_update_user', $original_user, $user, $faker );
		wp_update_user( $user );
		$this->update_user_login( $user->ID, $new_data['user_login'] );
		/**
		 * Post update user.
		 *
		 * Triggered after a single user is updated with fake information.
		 *
		 * @since 1.0.0
		 *
		 * @param WP_User $original_user original WP_User object.
		 * @param WP_User $user new data about to be written to the database.
		 * @param Factory $faker the faker object, made available for you to generate fake data for meta fields etc.
		 */
		do_action( 'gdpr_sanitizer_pre_update_user', $original_user, $user, $faker );
	}

	/**
	 * Return a fake login name that doesn't exist yet.
	 *
	 * @return string
	 */
	private function generate_unused_user_login() {
		$faker          = Faker\Factory::create();
		$new_user_login = false;
		$sanity_check   = 0;
		while ( ! $new_user_login ) {
			$user_login_to_check = $faker->userName;
			$user                = get_user_by( 'user_login', $user_login_to_check );
			if ( ! $user ) {
				$new_user_login = $user_login_to_check;
			} elseif ( $sanity_check > 3 ) { // it would be crazy to get here, but lets try adding some random numbers.
				$user_login_to_check = $faker->numerify( $user_login_to_check . '#####' );
				$user                = get_user_by( 'user_login', $user_login_to_check );
				if ( ! $user ) {
					$new_user_login = $user_login_to_check;
				}
			}
			$sanity_check ++;
			// it should be impossible to get here.
			if ( $sanity_check > 30 ) {
				WP_CLI::error( 'Unable to find a fake username that was not already in use' );
			}
		}

		return $new_user_login;
	}

	/**
	 * WordPress does not update user names via the wp_update_user function, so we need to do that manually.
	 *
	 * @param int    $user_id WP user id.
	 * @param string $new_login New user login.
	 *
	 * @return void
	 */
	private function update_user_login( $user_id, $new_login ) {
		global $wpdb;
		$wpdb->update( $wpdb->users, array( 'user_login' => $new_login ), array( 'ID' => $user_id ) );
	}

	/**
	 * Process incoming --keep argument into excluded user ids array
	 *
	 * @param string $arg_string The --keep argument value.
	 *
	 * @return integer Number of user ids excluded.
	 */
	private function set_excluded_user_ids( $arg_string ) {
		$excluded_user_ids = array();
		if ( stristr( $arg_string, ',' ) ) {
			$strings = explode( ',', $arg_string );
			foreach ( $strings as $string ) {
				$excluded_user_ids[] = $this->string_to_user( $string );
			}
		} else {
			$excluded_user_ids[] = $this->string_to_user( $arg_string );
		}
		$this->excluded_user_ids = $excluded_user_ids;
		return count( $this->excluded_user_ids );
	}

	/**
	 * Returns a user id from an email, user login, or string id
	 *
	 * @param string $string A single segment of the --keep argument.
	 *
	 * @return integer|boolean User id or false if not found but skipping is ok.
	 */
	private function string_to_user( $string ) {
		if ( is_numeric( $string ) ) {
			return (int) $string;
		}
		if ( stristr( $string, '@' ) ) {
			$user = get_user_by( 'email', $string );
			if ( $user ) {
				return $user->ID;
			} else {
				if ( $this->skip_not_found_users ) {
					WP_CLI::warning( 'user email not found' );
				} else {
					WP_CLI::error( 'user email not found' );
				}
			}
		}
		$user = get_user_by( 'username', $string );
		if ( $user ) {
			return $user->ID;
		}
		if ( $this->skip_not_found_users ) {
			WP_CLI::warning( 'username to keep not found, skipping' );
		} else {
			WP_CLI::error( 'username to keep not found' );
		}

		return false;
	}
}
