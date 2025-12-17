 plugin is a read only audit screen that tells me what is actually inside `wp_options`, with a focus on autoload bloat and oversized values.
## What it does
Adds:
Tools
Options Audit
It reports:
Top autoloaded options by size
Largest options overall
Likely orphaned options that look like they belong to plugins that are not installed anymore
Expired transients estimate and samples
JSON export so I can compare before and after or attach it to a ticket
## What it does not do
No deletion
No optimisation
No “clean now”
No hero buttons
It is a flashlight. I still have to do the cleanup responsibly.
## Notes that matter
Autoload options load on every request. If a plugin stores a 900 KB option and sets autoload to yes, it is basically charging rent.
Orphan detection is heuristic. Option names do not have ownership metadata. Treat the orphan list as “investigate”.
Expired transients are common on sites where cleanup is not running reliably. This is often tied to WP-Cron health.
## Filters
Tweak sample sizes:
```php
add_filter( 'wota_autoload_top_limit', function() { return 100; } );
add_filter( 'wota_largest_limit', function() { return 100; } );
add_filter( 'wota_orphan_limit', function() { return 150; } );
add_filter( 'wota_transient_limit', function() { return 150; } );
Tweak the “big autoload” threshold:

php
Copy code
add_filter( 'wota_big_option_threshold_bytes', function() {
    return 512 * 1024; // 512 KB
} );
