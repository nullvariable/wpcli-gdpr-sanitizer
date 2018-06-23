<?php
/**
 * @package nullvariable\wpcli-gdpr-sanitizer
 */

 class GDPR_Sanitizer extends \WP_CLI_Command {
	/**
	 * User ids to skip.
	 *
	 * @var array
	 */
	protected $excluded_user_ids = [];
	/**
	 * If we can't find a user id for a name or email, should we bail?
	 *
	 * @var boolean
	 */
	protected $skip_not_found_users = false;

	/**
     * Performs personal information replacement.
     *
     * ## OPTIONS
     *
     * [--keep=<user id|user login|email>]
     * : user(s) to skip during replacment.
     *
     * ## EXAMPLES
     *
     *     wp gdpr-sanitizer
	 *     wp gdpr-sanitizer --keep=123
	 *     wp gdpr-sanitizer --keep="123,admin,test@example.com"
     */
	public function __invoke( $args, $assoc_args ) {
		if ( isset( $assoc_args['keep'] ) && ! empty( $assoc_args['keep'] ) ) {
			$this->set_excluded_user_ids( $assoc_args['keep'] );
		}
		if ( isset( $assoc_args['skip-not-found'] ) ) {
			$this->skip_not_found_users = true;
		}
		$this->obfuscate_users();
		$this->obfuscate_comments();
	}

	/**
	 * Rewrite the PII found in standard WordPress comments.
	 *
	 * @return void
	 */
	protected function obfuscate_comments() {
		$faker = Faker\Factory::create();

		$trash_comments = get_comments( [ 'status' => 'trash', ] );
		$spam_comments = get_comments( [ 'status' => 'spam' ] );
		$regular_comments = get_comments();
		$comments = array_merge($regular_comments, $trash_comments, $spam_comments);

		$count = count( $users );
		$progress = \WP_CLI\Utils\make_progress_bar( __( 'Obfuscating comments', 'gdpr' ), $count );

		foreach ($comments as $comment) {
			$commentarr = $comment->to_array();
			$commentarr['comment_author']       = $faker->name;
			$commentarr['comment_author_email'] = $faker->safeEmail;
			$commentarr['comment_author_url']   = $faker->url;
			$commentarr['comment_author_IP']    = $faker->ipv4;
			$commentarr['comment_agent']        = $faker->userAgent;
			/**
			 * Preupdate Comment.
			 *
			 * Triggered before a single comment is updated with fake information. Allows you to modify custom meta fields when the plugin is triggered.
			 *
			 * @since 1.0.0
			 *
			 * @param WP_Comment $comment original WP_Comment object.
			 * @param array $commentarr new data about to be written to the database.
			 * @param Faker $faker the faker object, made available for you to generate fake data for meta fields etc.
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
			 * @param Faker $faker the faker object, made available for you to generate fake data for meta fields etc.
			 */
			do_action( 'gdpr_sanitizer_post_update_comment', $comment, $commentarr, $faker );
			$progress->tick();
		}
		$progress->finish();
		WP_CLI::success( count( $comments ) . __( ' comments obfuscated.', 'gdpr-sanitizer' ) );
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
		$count = count( $users );
		$progress = \WP_CLI\Utils\make_progress_bar( __( 'Obfuscating users', 'gdpr' ), $count );
		foreach ( $users as $user ) {
			$this->obfuscate_user( $user );
			$progress->tick();
		}
		$progress->finish();
		WP_CLI::success( $count . __( ' users obfuscated.', 'gdpr-sanitizer' ) );
	}

	private function obfuscate_user( $user ) {
		$faker = Faker\Factory::create();
		$original_user = $user;
		$new_data = [
			'user_pass' => $faker->password,
			'user_nicename' => $faker->name,
			'user_email' => $faker->safeEmail,
			'user_url' => $faker->url,
			'display_name' => $faker->firstName,
			'user_login' => $this->generate_unused_user_login(),
		];
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
		 * @param Faker $faker the faker object, made available for you to generate fake data for meta fields etc.
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
		 * @param Faker $faker the faker object, made available for you to generate fake data for meta fields etc.
		 */
		do_action( 'gdpr_sanitizer_pre_update_user', $original_user, $user, $faker );	
	}

	private function generate_unused_user_login() {
		$faker = Faker\Factory::create();
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
		return $new_user_login;
	}

	private function update_user_login( $user_id, $new_login ) {
		global $wpdb;
		$wpdb->update( $wpdb->users, [ 'user_login' => $new_login, ], [ 'ID' => $user_id, ] );
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
