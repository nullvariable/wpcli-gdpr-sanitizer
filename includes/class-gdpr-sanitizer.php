<?php
/**
 * @package nullvariable\gdpr-sanitizer
 */

 class GDPR_Sanitizer extends \WP_CLI_Command {
     /**
	 * Holds the command arguments.
	 *
	 * @var array
	 */
	protected $excluded_user_ids = [];
	protected $skip_not_found_users = false;
	public function __invoke( $args, $assoc_args ) {
		if ( isset( $assoc_args['keep'] ) && ! empty( $assoc_args['keep'] ) ) {
			$this->set_excluded_user_ids( $assoc_args['keep'] );
		}
		if ( isset( $assoc_args['skip-not-found'] ) ) {
			$this->skip_not_found_users = true;
		}
		// $this->obfuscate_users();
		$this->obfuscate_comments();
	}

	protected function obfuscate_comments() {
		$faker = Faker\Factory::create();

		$trash_comments = get_comments( [ 'status' => 'trash', ] );
		$spam_comments = get_comments( [ 'status' => 'spam' ] );
		$regular_comments = get_comments();
		$comments = array_merge($regular_comments, $trash_comments, $spam_comments);

		foreach ($comments as $comment) {
			do_action( 'gdpr_sanitizer_update_comment', $comment->comment_ID, $faker );
			$commentarr = $comment->to_array();
			$commentarr['comment_author']       = $faker->name;
			$commentarr['comment_author_email'] = $faker->safeEmail;
			$commentarr['comment_author_url']   = $faker->url;
			$commentarr['comment_author_IP']    = $faker->ipv4;
			$commentarr['comment_agent']        = $faker->userAgent;
			wp_update_comment( $commentarr );
		}
		WP_CLI::success( count( $comments ) . __( ' comments obfuscated.' ) );
	}
	
	protected function obfuscate_users() {
		$users = [];
		if ( is_multisite() ) { //@TODO check global --url param to allow for operating on a single site
			$site_ids = get_sites();
			foreach ( $site_ids as $site_id ) {
				$site_users = get_users( [
					'blog_id' => $site_id,
					'exclude' => $this->excluded_user_ids,
				] );
				
			}
		} else {
			$users = get_users( [
				'exclude' => $this->excluded_user_ids,
			] );
		}
		if ( count( $users ) <= 0 ) {
			WP_CLI::success( __( 'No users changed (did you exclude them all?)', 'gdpr-sanitizer' ) );
			return;
		}
		foreach ( $users as $user ) {
			$this->obfuscate_user( $user );
		}
		WP_CLI::success( count( $users ) . __( ' users obfuscated.', 'gdpr-sanitizer' ) );
	}

	private function obfuscate_user( $user ) {
		$faker = Faker\Factory::create();

		do_action( 'gdpr_sanitizer_update_user', $user->ID, $user->user_email, $faker );

		$user->data->user_pass = $faker->password;
		$user->data->user_nicename = $faker->name;
		$user->data->user_email = $faker->safeEmail;
		$user->data->user_url = $faker->url;
		$user->data->display_name = $faker->firstName;
		
		wp_update_user( $user );		
		$this->update_user_login( $user->ID, $faker );
		
	}

	private function update_user_login( $user_id, $faker ) {
		global $wpdb;

		// ensure the new fake username isn't already in use, keep generating them until we find one that isn't.
		$new_user_login = false;
		$sanity_check = 0;		
		while ( ! $new_user_login ) {
			$user_login_to_check = $faker->userName;
			$user = get_user_by( 'user_login', $user_login_to_check );
			if ( ! $user ) {
				$new_user_login = $user_login_to_check;
			} else if ( $sanity_check > 3 ) { // it would be crazy to get here, but lets try adding some random numbers.
				$user_login_to_check = $faker->numerify( $user_login_to_check . '#####' );
				$user = get_user_by( 'user_login', $user_login_to_check );
				if ( ! $user ) {
					$new_user_login = $user_login_to_check;
				}
			}
			$sanity_check++;
			// it should be impossible to get here.
			if ( $sanity_check > 30 ) {
				WP_CLI::error( __( 'Unable to find a fake username that was not already in use', 'gdpr-sanitizer' ) );
			}
		}
		$wpdb->update( $wpdb->users, [ 'user_login' => $new_user_login, ], [ 'ID' => $user_id, ] );
	}

	private function set_excluded_user_ids( $arg_string ) {
		$excluded_user_ids = [];
		if (stristr($arg_string, ',')) {
			$strings = explode( ',', $arg_string );
			foreach ( $strings as $string ) {
				$excluded_user_ids[] = $this->string_to_user( $string );
			}
		} else {
			$excluded_user_ids[] = $this->string_to_user( $arg_string );
		}
		$this->excluded_user_ids = $excluded_user_ids;
	}

	private function string_to_user( $string ) {
		if ( is_numeric ($string ) ) {
			return (int) $string;
		}
		if (stristr($string, '@')) {
			$user = get_user_by( 'email', $string );
			if ( $user ) {
				return $user->ID;
			} else {
				if ( $this->skip_not_found_users ) {
					WP_CLI::warning( __( 'user email not found', 'gdpr-sanitizer' ) );
				} else {
					WP_CLI::error( __( 'user email not found', 'gdpr-sanitizer' ) );
				}
			}
		}
		$user = get_user_by( 'username', $string );
		if ( $user ) {
			return $user->ID;
		}
		if ( $this->skip_not_found_users ) {
			WP_CLI::warning( __( 'username to keep not found, skipping', 'gdpr-sanitizer' ) );
		} else {
			WP_CLI::error( __( 'username to keep not found', 'gdpr-sanitizer' ) );
		}
	}
 }
