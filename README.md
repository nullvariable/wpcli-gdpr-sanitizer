nullvariable/gdpr-sanitizer
===========================

Rewrites all personal data in a WordPress install with random fake information.





Quick links: [Using](#using) | [Installing](#installing) | [Contributing](#contributing) | [Support](#support)

## Using

`wp gdpr-sanitizer` will rewrite all personally identifying information in standard WordPress profile fields.
`wp gdpr-sanitizer --keep=123` will rewrite all PII, skipping the user id 123.
`wp gdpr-sanitizer --keep=nullvariable` will skip the user with this user name.
`wp gdpr-sanitizer --keep=test@test.com` will skip the user with this email address.
You can keep as many users as you'd like by adding a comma between each, like `--keep=123,124,125`

### Extending
Have other plugins or custom fields that you need to rewrite in addition to the standard profile fields? Hook a custom function into `gdpr_sanitizer_update_user` that accepts the following arguments: `user_id`, `email_address`, `Faker`
A second hook should be used if you have custom comment meta: `gdpr_sanitizer_update_comment` will fire before the comment is update with the `comment ID` and instance of `Faker` as the arguments.

Example:
```
function my_custom_gdpr_sanitizer( $user_id, $email_address, $faker ) {
    update_user_meta( $user_id, 'my_custom_meta_nickname', $faker->firstName );
    update_user_meta( $user_id, 'my_custom_meta_phone_number', $faker->phoneNumber );

    global $wpdb;
    $wpdb->get_records
    foreach( $results as $result ) {
        $wpdb->update( 
            $wpdb->base_prefix . 'my_custom_table', 
            array(
                'customer_company' => $faker->company,
            ),
            array(
                'customer_email_address' => $email_address,
            )
        );
    }
}
add_action( 'gdpr_sanitizer_update_user', 'my_custom_gdpr_sanitizer', 10, 3 );
function my_custom_gdpr_sanitizer_for_commentmeta( $comment_id, $faker ) {
    update_comment_meta( $comment_id, 'my_custom_latitude_field', $faker->latitude)
    update_comment_meta( $comment_id, 'my_custom_longitude_field', $faker->longitude)
}
add_action( 'gdpr_sanitizer_update_comment', 'my_custom_gdpr_sanitizer_for_commentmeta', 10, 2 );
```
Full documentation on the fake data can be found here: https://github.com/fzaninotto/Faker

## Installing

Installing this package requires WP-CLI v1.1.0 or greater. Update to the latest stable release with `wp cli update`.

Once you've done so, you can install this package with:

    wp package install git@github.com:nullvariable/gdpr-sanitizer.git


### Reporting a bug

Think you’ve found a bug? We’d love for you to help us get it fixed.

Before you create a new issue, you should [search existing issues](https://github.com/nullvariable/gdpr-sanitizer/issues?q=label%3Abug%20) to see if there’s an existing resolution to it, or if it’s already been fixed in a newer version.

Once you’ve done a bit of searching and discovered there isn’t an open or fixed issue for your bug, please [create a new issue](https://github.com/nullvariable/gdpr-sanitizer/issues/new). Include as much detail as you can, and clear steps to reproduce if possible. For more guidance, [review our bug report documentation](https://make.wordpress.org/cli/handbook/bug-reports/).

### Creating a pull request

Want to contribute a new feature? Please first [open a new issue](https://github.com/nullvariable/gdpr-sanitizer/issues/new) to discuss whether the feature is a good fit for the project.

