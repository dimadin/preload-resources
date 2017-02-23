# Preload Resources

Preload Resources is a WordPress plugin that adds preload link header for each enqueued resource (CSS or JavaScript file) until conditions are met.

By default, it adds header for each enqueued resource before `wp_head` action with priority 777 (usually everything inside `<head>`) while keeping total header size less than 3072 bytes (3KB).

If you use server that supports HTTP/2 Push, resources that are on the same hostname as page will be pushed. This also applies if you use Cloudflare.

Everything can be customized with WordPress filters, it has no UI.

## Customizing

You can find out more how it works and what filters are available by looking into its code, but here are some example how you can change default behavior.

Changing what resources are processed can be achieved with `preload_resources_style_handles` and `preload_resources_script_handles` filters. In both cases an array of handles of resources that should be preloaded are passed as first argument.

For example, let's say that you are using Twenty Seventeen theme and you want to preload only styles with prefix `twentyseventeen` and no scripts:

```php
add_filter( 'preload_resources_style_handles', function( $handles ) {
	foreach ( $handles as $i => $handle ) {
		if ( 0 !== strpos( $handle, 'twentyseventeen' ) ) {
			unset( $handles[ $i ] );
		}
	}

	return $handles;
} );
add_filter( 'preload_resources_script_handles', '__return_empty_array' );
```

Or, you can manually set resources that should preloaded but that are already registered and disable output buffering:

```php
add_filter( 'preload_resources_style_handles', function( $handles ) {
	return [
		'my-custom-already-registered-style',
	];
} );
add_filter( 'preload_resources_script_handles', function( $handles ) {
	return [
		'jquery-core',
		'jquery-migrate',
	];
} );
add_filter( 'preload_resources_use_ob', '__return_false' );
```
Or, you can buffer output until later hook but only preload if current page is front page:

```php
add_filter( 'preload_resources_use_ob', function( $status ) {
    if ( is_front_page() ) {
        add_filter( 'preload_resources_ob_end_hook', function( $hook ) {
            return 'wp_footer';
        } );

        return true;
    } else {
        return false;
    }
} );
```
Or, you can decrease maximum header size to 2KB:

```php
add_filter( 'preload_resources_max_header_size', function( $size ) {
    return 2 * KB_IN_BYTES;
} );
```
Note that resources are usually registered at `wp_enqueue_script` action which is much later than `template_redirect` where `preload_resources_use_ob` is happening. This is important since resources can be preloaded only if they are previously registered, even if manually declared.
