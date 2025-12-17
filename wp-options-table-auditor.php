<?php
/**
 * Plugin Name: WP Options Table Auditor
 * Plugin URI: https://github.com/TABARC-Code/wp-options-table-auditor
 * Description: Audits wp_options for autoload bloat, oversized values, orphaned plugin settings and transient debris. Read only, because I enjoy sleeping.
 * Version: 1.0.0.7
 * Author: TABARC-Code
 * Author URI: https://github.com/TABARC-Code
 * License: GPL-3.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 *
 * Why this exists:
 * wp_options is where plugins stash everything they do not want to think about.
 * Then half of those plugins get deleted and the options stay, still autoloading on every request, still slowing the site.
 * You end up with a homepage that waits for 4 MB of serialized sadness before it renders a paragraph.
 *
 * This plugin is read only. It audits, reports and exports. It does not delete. It does not "optimise".
 * I am not letting a UI button delete your site because you had a brave moment.
 *
 * TODO: add an optional CLI command for exports and scheduled baselines.
 * TODO: add a simple "diff against last export" view.
 * FIXME: orphan detection is heuristic. Plugin authors do not coordinate option name prefixes because that would be convenient.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'WP_Options_Table_Auditor' ) ) {

    class WP_Options_Table_Auditor {

        private $screen_slug   = 'wp-options-table-auditor';
        private $export_action = 'wota_export_json';

        public function __construct() {
            add_action( 'admin_menu', array( $this, 'add_tools_page' ) );
            add_action( 'admin_post_' . $this->export_action, array( $this, 'handle_export_json' ) );
            add_action( 'admin_head-plugins.php', array( $this, 'inject_plugin_list_icon_css' ) );
        }

        private function get_brand_icon_url() {
            return plugin_dir_url( __FILE__ ) . '.branding/tabarc-icon.svg';
        }

        public function add_tools_page() {
            add_management_page(
                __( 'Options Table Auditor', 'wp-options-table-auditor' ),
                __( 'Options Audit', 'wp-options-table-auditor' ),
                'manage_options',
                $this->screen_slug,
                array( $this, 'render_screen' )
            );
        }

        public function render_screen() {
            if ( ! current_user_can( 'manage_options' ) ) {
                wp_die( esc_html__( 'You do not have permission to access this page.', 'wp-options-table-auditor' ) );
            }

            $audit = $this->run_audit();

            $export_url = wp_nonce_url(
                admin_url( 'admin-post.php?action=' . $this->export_action ),
                'wota_export_json'
            );

            ?>
            <div class="wrap">
                <h1><?php esc_html_e( 'WP Options Table Auditor', 'wp-options-table-auditor' ); ?></h1>
                <p>
                    This is a report on the quiet performance killer: <code>wp_options</code>.
                    Specifically: what autoloads, what is huge, what is probably orphaned, and what looks like transient rot.
                </p>
                <p>
                    <a class="button button-secondary" href="<?php echo esc_url( $export_url ); ?>">
                        <?php esc_html_e( 'Export audit as JSON', 'wp-options-table-auditor' ); ?>
                    </a>
                </p>
                <h2><?php esc_html_e( 'Summary', 'wp-options-table-auditor' ); ?></h2>
                <?php $this->render_summary( $audit ); ?>
                <h2><?php esc_html_e( 'Top autoloaded options by size', 'wp-options-table-auditor' ); ?></h2>
                <p>
                    Autoloaded options are loaded on every request. Every request. Front end too.
                    If you see huge values here, that is your site doing unpaid labour.
                </p>
                <?php $this->render_autoload_top( $audit ); ?>
                <h2><?php esc_html_e( 'Largest options overall', 'wp-options-table-auditor' ); ?></h2>
                <p>
                    These options are big regardless of autoload. Not always bad, but often a sign of cached blobs, builder data, or a plugin storing a universe in a text field.
                </p>
                <?php $this->render_largest_overall( $audit ); ?>
                <h2><?php esc_html_e( 'Likely orphaned plugin options', 'wp-options-table-auditor' ); ?></h2>
                <p>
                    Options that look like they belong to plugins that are not installed anymore.
                    Heuristic. Still useful. Expect false positives.
                </p>
                <?php $this->render_orphans( $audit ); ?>
                <h2><?php esc_html_e( 'Transient debris', 'wp-options-table-auditor' ); ?></h2>
                <p>
                    Expired transients are supposed to disappear. On some sites they do. On others they just sit there, like a fossil record of past requests.
                </p>
                <?php $this->render_transients( $audit ); ?>
                <p style="font-size:12px;opacity:0.8;margin-top:2em;">
                    <?php esc_html_e( 'Read only audit. If you delete things, do it on staging, with backups, in small batches.', 'wp-options-table-auditor' ); ?>
                </p>
            </div>
            <?php
        }

        public function inject_plugin_list_icon_css() {
            $icon_url = esc_url( $this->get_brand_icon_url() );
            ?>
            <style>
                .wp-list-table.plugins tr[data-slug="wp-options-table-auditor"] .plugin-title strong::before {
                    content: '';
                    display: inline-block;
                    vertical-align: middle;
                    width: 18px;
                    height: 18px;
                    margin-right: 6px;
                    background-image: url('<?php echo $icon_url; ?>');
                    background-repeat: no-repeat;
                    background-size: contain;
                }
            </style>
            <?php
        }

        public function handle_export_json() {
            if ( ! current_user_can( 'manage_options' ) ) {
                wp_die( 'No.' );
            }

            check_admin_referer( 'wota_export_json' );

            $audit = $this->run_audit();

            $payload = array(
                'generated_at' => gmdate( 'c' ),
                'site_url'     => site_url(),
                'audit'        => $audit,
            );

            nocache_headers();
            header( 'Content-Type: application/json; charset=utf-8' );
            header( 'Content-Disposition: attachment; filename="wp-options-audit.json"' );

            echo wp_json_encode( $payload, JSON_PRETTY_PRINT );
            exit;
        }

        private function run_audit() {
            global $wpdb;

            $autoload_limit = (int) apply_filters( 'wota_autoload_top_limit', 50 );
            $largest_limit  = (int) apply_filters( 'wota_largest_limit', 50 );
            $orphan_limit   = (int) apply_filters( 'wota_orphan_limit', 80 );
            $transient_limit = (int) apply_filters( 'wota_transient_limit', 80 );

            if ( $autoload_limit <= 0 ) {
                $autoload_limit = 50;
            }
            if ( $largest_limit <= 0 ) {
                $largest_limit = 50;
            }
            if ( $orphan_limit <= 0 ) {
                $orphan_limit = 80;
            }
            if ( $transient_limit <= 0 ) {
                $transient_limit = 80;
            }

            $big_threshold_bytes = (int) apply_filters( 'wota_big_option_threshold_bytes', 256 * 1024 );
            if ( $big_threshold_bytes < 1024 ) {
                $big_threshold_bytes = 1024;
            }

            $autoload_rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT option_name, autoload, LENGTH(option_value) AS bytes
                     FROM {$wpdb->options}
                     WHERE autoload = 'yes'
                     ORDER BY bytes DESC
                     LIMIT %d",
                    $autoload_limit
                ),
                ARRAY_A
            );

            $largest_rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT option_name, autoload, LENGTH(option_value) AS bytes
                     FROM {$wpdb->options}
                     ORDER BY bytes DESC
                     LIMIT %d",
                    $largest_limit
                ),
                ARRAY_A
            );

            $autoload_total_bytes = (int) $wpdb->get_var(
                "SELECT SUM(LENGTH(option_value)) FROM {$wpdb->options} WHERE autoload = 'yes'"
            );

            $autoload_count = (int) $wpdb->get_var(
                "SELECT COUNT(*) FROM {$wpdb->options} WHERE autoload = 'yes'"
            );

            $total_count = (int) $wpdb->get_var(
                "SELECT COUNT(*) FROM {$wpdb->options}"
            );

            $transient_counts = $this->scan_transients( $transient_limit );

            $orphans = $this->scan_orphanish_options( $orphan_limit );

            $big_autoload = array();
            foreach ( (array) $autoload_rows as $row ) {
                $bytes = isset( $row['bytes'] ) ? (int) $row['bytes'] : 0;
                if ( $bytes >= $big_threshold_bytes ) {
                    $big_autoload[] = $row;
                }
            }

            return array(
                'thresholds' => array(
                    'big_option_bytes' => $big_threshold_bytes,
                ),
                'counts' => array(
                    'total_options'       => $total_count,
                    'autoload_options'    => $autoload_count,
                    'autoload_total_bytes'=> $autoload_total_bytes,
                ),
                'autoload_top'   => $this->normalise_rows( $autoload_rows ),
                'largest_overall'=> $this->normalise_rows( $largest_rows ),
                'big_autoload'   => $this->normalise_rows( $big_autoload ),
                'orphans'        => $orphans,
                'transients'     => $transient_counts,
            );
        }

        private function normalise_rows( $rows ) {
            $out = array();
            foreach ( (array) $rows as $row ) {
                $out[] = array(
                    'option_name' => isset( $row['option_name'] ) ? (string) $row['option_name'] : '',
                    'autoload'    => isset( $row['autoload'] ) ? (string) $row['autoload'] : '',
                    'bytes'       => isset( $row['bytes'] ) ? (int) $row['bytes'] : 0,
                );
            }
            return $out;
        }

        private function scan_transients( $limit ) {
            global $wpdb;

            // Transients are stored as:
            // _transient_{key}
            // _transient_timeout_{key}
            // same pattern for site transients: _site_transient_...
            //
            // Expired means timeout < now. WordPress should clean, but cleanup depends on traffic and luck.
            $now = time();

            $expired = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT o.option_name AS timeout_name, o.option_value AS timeout_value, LENGTH(v.option_value) AS bytes, v.option_name AS value_name
                     FROM {$wpdb->options} o
                     LEFT JOIN {$wpdb->options} v
                       ON v.option_name = REPLACE(o.option_name, '_transient_timeout_', '_transient_')
                     WHERE o.option_name LIKE %s
                       AND CAST(o.option_value AS UNSIGNED) > 0
                       AND CAST(o.option_value AS UNSIGNED) < %d
                     ORDER BY CAST(o.option_value AS UNSIGNED) ASC
                     LIMIT %d",
                    $wpdb->esc_like( '_transient_timeout_' ) . '%',
                    $now,
                    $limit
                ),
                ARRAY_A
            );

            $expired_site = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT o.option_name AS timeout_name, o.option_value AS timeout_value, LENGTH(v.option_value) AS bytes, v.option_name AS value_name
                     FROM {$wpdb->options} o
                     LEFT JOIN {$wpdb->options} v
                       ON v.option_name = REPLACE(o.option_name, '_site_transient_timeout_', '_site_transient_')
                     WHERE o.option_name LIKE %s
                       AND CAST(o.option_value AS UNSIGNED) > 0
                       AND CAST(o.option_value AS UNSIGNED) < %d
                     ORDER BY CAST(o.option_value AS UNSIGNED) ASC
                     LIMIT %d",
                    $wpdb->esc_like( '_site_transient_timeout_' ) . '%',
                    $now,
                    $limit
                ),
                ARRAY_A
            );

            $counts = array(
                'expired_transients_sample'      => $this->normalise_transient_rows( $expired ),
                'expired_site_transients_sample' => $this->normalise_transient_rows( $expired_site ),
                'expired_transients_count_estimate' => (int) $wpdb->get_var(
                    $wpdb->prepare(
                        "SELECT COUNT(*) FROM {$wpdb->options}
                         WHERE option_name LIKE %s
                           AND CAST(option_value AS UNSIGNED) > 0
                           AND CAST(option_value AS UNSIGNED) < %d",
                        $wpdb->esc_like( '_transient_timeout_' ) . '%',
                        $now
                    )
                ),
                'expired_site_transients_count_estimate' => (int) $wpdb->get_var(
                    $wpdb->prepare(
                        "SELECT COUNT(*) FROM {$wpdb->options}
                         WHERE option_name LIKE %s
                           AND CAST(option_value AS UNSIGNED) > 0
                           AND CAST(option_value AS UNSIGNED) < %d",
                        $wpdb->esc_like( '_site_transient_timeout_' ) . '%',
                        $now
                    )
                ),
            );

            return $counts;
        }

        private function normalise_transient_rows( $rows ) {
            $out = array();
            foreach ( (array) $rows as $row ) {
                $timeout = isset( $row['timeout_value'] ) ? (int) $row['timeout_value'] : 0;
                $out[] = array(
                    'timeout_name' => isset( $row['timeout_name'] ) ? (string) $row['timeout_name'] : '',
                    'value_name'   => isset( $row['value_name'] ) ? (string) $row['value_name'] : '',
                    'timeout'      => $timeout,
                    'timeout_human'=> $timeout ? date_i18n( 'Y-m-d H:i:s', $timeout ) : '',
                    'bytes'        => isset( $row['bytes'] ) ? (int) $row['bytes'] : 0,
                );
            }
            return $out;
        }

        private function scan_orphanish_options( $limit ) {
            global $wpdb;

            // This is crude, because WordPress does not track ownership of options.
            // I do two things:
            // 1) build a list of known plugin "slugs" from installed plugins
            // 2) look for options whose prefix looks like a plugin, and that plugin is not installed
            //
            // This will miss plenty. It will also accuse innocent options. So it is labelled "likely".

            $installed = $this->get_installed_plugin_markers();

            $candidates = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT option_name, autoload, LENGTH(option_value) AS bytes
                     FROM {$wpdb->options}
                     WHERE option_name NOT LIKE %s
                       AND option_name NOT LIKE %s
                       AND option_name NOT LIKE %s
                     ORDER BY bytes DESC
                     LIMIT %d",
                    $wpdb->esc_like( '_transient_' ) . '%',
                    $wpdb->esc_like( '_site_transient_' ) . '%',
                    $wpdb->esc_like( 'cron' ),
                    $limit * 6
                ),
                ARRAY_A
            );

            $orphans = array();

            foreach ( (array) $candidates as $row ) {
                if ( count( $orphans ) >= $limit ) {
                    break;
                }

                $name = isset( $row['option_name'] ) ? (string) $row['option_name'] : '';
                if ( $name === '' ) {
                    continue;
                }

                $prefix = $this->guess_option_prefix( $name );
                if ( $prefix === '' ) {
                    continue;
                }

                // If it matches core-ish prefixes, ignore.
                if ( $this->is_coreish_prefix( $prefix ) ) {
                    continue;
                }

                // If prefix looks installed, do not flag.
                if ( isset( $installed[ $prefix ] ) ) {
                    continue;
                }

                // Some plugin options are weird like "elementor_version", "woocommerce_version".
                // I also check against partial markers.
                if ( $this->looks_installed_by_fuzzy_match( $prefix, $installed ) ) {
                    continue;
                }

                $orphans[] = array(
                    'option_name' => $name,
                    'prefix_guess'=> $prefix,
                    'autoload'    => isset( $row['autoload'] ) ? (string) $row['autoload'] : '',
                    'bytes'       => isset( $row['bytes'] ) ? (int) $row['bytes'] : 0,
                );
            }

            return $orphans;
        }

        private function get_installed_plugin_markers() {
            if ( ! function_exists( 'get_plugins' ) ) {
                require_once ABSPATH . 'wp-admin/includes/plugin.php';
            }

            $plugins = get_plugins();
            $markers = array();

            foreach ( (array) $plugins as $path => $data ) {
                $path = (string) $path;

                // Common slug is the folder name.
                $folder = dirname( $path );
                if ( $folder && $folder !== '.' ) {
                    $markers[ sanitize_key( $folder ) ] = true;
                }

                // Also add the base filename without extension.
                $base = wp_basename( $path, '.php' );
                if ( $base ) {
                    $markers[ sanitize_key( $base ) ] = true;
                }

                // Some plugins use a text domain that maps to their option prefixes.
                if ( ! empty( $data['TextDomain'] ) ) {
                    $markers[ sanitize_key( $data['TextDomain'] ) ] = true;
                }

                // A last attempt: plugin name, stripped.
                if ( ! empty( $data['Name'] ) ) {
                    $markers[ sanitize_key( $data['Name'] ) ] = true;
                }
            }

            // Add a few known big ones, because they tend to be obvious prefixes.
            $markers['woocommerce'] = true;
            $markers['elementor'] = true;
            $markers['yoast'] = true;

            return $markers;
        }

        private function guess_option_prefix( $option_name ) {
            // Common patterns:
            // pluginname_option
            // pluginname-option
            // pluginname.option
            // pluginname:option
            //
            // I take the first chunk up to a delimiter, if any.
            $delims = array( '_', '-', '.', ':' );

            $best = '';
            $min_pos = null;

            foreach ( $delims as $d ) {
                $pos = strpos( $option_name, $d );
                if ( $pos !== false ) {
                    if ( $min_pos === null || $pos < $min_pos ) {
                        $min_pos = $pos;
                        $best = substr( $option_name, 0, $pos );
                    }
                }
            }

            $best = sanitize_key( (string) $best );

            // If there was no delimiter, give up.
            if ( $best === '' ) {
                return '';
            }

            // If prefix is too short, it is not useful.
            if ( strlen( $best ) < 3 ) {
                return '';
            }

            return $best;
        }

        private function is_coreish_prefix( $prefix ) {
            $core = array(
                'wp',
                'wpdb',
                'site',
                'blog',
                'rewrite',
                'widget',
                'theme',
                'users',
                'user',
                'admin',
                'dashboard',
                'mailserver',
                'uploads',
                'permalink',
                'gmt',
            );

            return in_array( $prefix, $core, true );
        }

        private function looks_installed_by_fuzzy_match( $prefix, $installed ) {
            foreach ( (array) $installed as $marker => $_true ) {
                if ( $marker === $prefix ) {
                    return true;
                }
                // Prefix contained within marker or marker contained within prefix.
                if ( strpos( $marker, $prefix ) !== false || strpos( $prefix, $marker ) !== false ) {
                    // This is loose, but it prevents silly false positives like "woo" against "woocommerce".
                    return true;
                }
            }
            return false;
        }

        private function human_bytes( $bytes ) {
            $bytes = (float) $bytes;
            if ( $bytes < 1024 ) {
                return $bytes . ' B';
            }
            $kb = $bytes / 1024;
            if ( $kb < 1024 ) {
                return number_format_i18n( $kb, 1 ) . ' KB';
            }
            $mb = $kb / 1024;
            if ( $mb < 1024 ) {
                return number_format_i18n( $mb, 2 ) . ' MB';
            }
            $gb = $mb / 1024;
            return number_format_i18n( $gb, 2 ) . ' GB';
        }

        private function render_summary( $audit ) {
            $counts = isset( $audit['counts'] ) ? (array) $audit['counts'] : array();

            $total = isset( $counts['total_options'] ) ? (int) $counts['total_options'] : 0;
            $autoload_count = isset( $counts['autoload_options'] ) ? (int) $counts['autoload_options'] : 0;
            $autoload_bytes = isset( $counts['autoload_total_bytes'] ) ? (int) $counts['autoload_total_bytes'] : 0;

            $big_autoload = isset( $audit['big_autoload'] ) && is_array( $audit['big_autoload'] ) ? count( $audit['big_autoload'] ) : 0;
            $orphans = isset( $audit['orphans'] ) && is_array( $audit['orphans'] ) ? count( $audit['orphans'] ) : 0;

            $trans = isset( $audit['transients'] ) ? (array) $audit['transients'] : array();
            $expired = isset( $trans['expired_transients_count_estimate'] ) ? (int) $trans['expired_transients_count_estimate'] : 0;
            $expired_site = isset( $trans['expired_site_transients_count_estimate'] ) ? (int) $trans['expired_site_transients_count_estimate'] : 0;

            $threshold = isset( $audit['thresholds']['big_option_bytes'] ) ? (int) $audit['thresholds']['big_option_bytes'] : 0;

            ?>
            <table class="widefat striped" style="max-width:980px;">
                <tbody>
                    <tr>
                        <th><?php esc_html_e( 'Total options', 'wp-options-table-auditor' ); ?></th>
                        <td><?php echo esc_html( $total ); ?></td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e( 'Autoload options', 'wp-options-table-auditor' ); ?></th>
                        <td>
                            <?php echo esc_html( $autoload_count ); ?>
                            <span style="opacity:0.75;font-size:12px;">
                                <?php
                                printf(
                                    esc_html__( 'Total autoload size: %s', 'wp-options-table-auditor' ),
                                    esc_html( $this->human_bytes( $autoload_bytes ) )
                                );
                                ?>
                            </span>
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e( 'Big autoload offenders (sample)', 'wp-options-table-auditor' ); ?></th>
                        <td>
                            <?php echo esc_html( $big_autoload ); ?>
                            <span style="opacity:0.75;font-size:12px;">
                                <?php
                                printf(
                                    esc_html__( 'Threshold: %s', 'wp-options-table-auditor' ),
                                    esc_html( $this->human_bytes( $threshold ) )
                                );
                                ?>
                            </span>
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e( 'Likely orphan options (sample)', 'wp-options-table-auditor' ); ?></th>
                        <td><?php echo esc_html( $orphans ); ?></td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e( 'Expired transients (estimate)', 'wp-options-table-auditor' ); ?></th>
                        <td>
                            <?php echo esc_html( $expired ); ?>
                            <span style="opacity:0.75;font-size:12px;">
                                <?php
                                printf(
                                    esc_html__( 'Expired site transients: %d', 'wp-options-table-auditor' ),
                                    (int) $expired_site
                                );
                                ?>
                            </span>
                        </td>
                    </tr>
                </tbody>
            </table>
            <?php
        }

        private function render_autoload_top( $audit ) {
            $rows = isset( $audit['autoload_top'] ) && is_array( $audit['autoload_top'] ) ? $audit['autoload_top'] : array();

            if ( empty( $rows ) ) {
                echo '<p>No autoloaded options found. That is unusual. Either a miracle or a very custom setup.</p>';
                return;
            }

            echo '<table class="widefat striped">';
            echo '<thead><tr><th>Option</th><th>Size</th><th>Autoload</th></tr></thead><tbody>';

            foreach ( $rows as $row ) {
                $name = $row['option_name'];
                $bytes = (int) $row['bytes'];
                $autoload = $row['autoload'];

                echo '<tr>';
                echo '<td><code>' . esc_html( $name ) . '</code></td>';
                echo '<td><strong>' . esc_html( $this->human_bytes( $bytes ) ) . '</strong> <span style="opacity:0.7;font-size:11px;">(' . esc_html( $bytes ) . ' bytes)</span></td>';
                echo '<td><code>' . esc_html( $autoload ) . '</code></td>';
                echo '</tr>';
            }

            echo '</tbody></table>';
        }

        private function render_largest_overall( $audit ) {
            $rows = isset( $audit['largest_overall'] ) && is_array( $audit['largest_overall'] ) ? $audit['largest_overall'] : array();

            if ( empty( $rows ) ) {
                echo '<p>No options found. Something is very off.</p>';
                return;
            }

            echo '<table class="widefat striped">';
            echo '<thead><tr><th>Option</th><th>Size</th><th>Autoload</th></tr></thead><tbody>';

            foreach ( $rows as $row ) {
                $name = $row['option_name'];
                $bytes = (int) $row['bytes'];
                $autoload = $row['autoload'];

                echo '<tr>';
                echo '<td><code>' . esc_html( $name ) . '</code></td>';
                echo '<td><strong>' . esc_html( $this->human_bytes( $bytes ) ) . '</strong> <span style="opacity:0.7;font-size:11px;">(' . esc_html( $bytes ) . ' bytes)</span></td>';
                echo '<td><code>' . esc_html( $autoload ) . '</code></td>';
                echo '</tr>';
            }

            echo '</tbody></table>';
        }

        private function render_orphans( $audit ) {
            $rows = isset( $audit['orphans'] ) && is_array( $audit['orphans'] ) ? $audit['orphans'] : array();

            if ( empty( $rows ) ) {
                echo '<p>No likely orphans detected in this sample. Either tidy, or the option names are too generic to guess ownership.</p>';
                return;
            }

            echo '<table class="widefat striped">';
            echo '<thead><tr><th>Option</th><th>Prefix guess</th><th>Size</th><th>Autoload</th></tr></thead><tbody>';

            foreach ( $rows as $row ) {
                $name = $row['option_name'];
                $prefix = $row['prefix_guess'];
                $bytes = (int) $row['bytes'];
                $autoload = $row['autoload'];

                echo '<tr>';
                echo '<td><code>' . esc_html( $name ) . '</code></td>';
                echo '<td><code style="opacity:0.85;">' . esc_html( $prefix ) . '</code></td>';
                echo '<td><strong>' . esc_html( $this->human_bytes( $bytes ) ) . '</strong></td>';
                echo '<td><code>' . esc_html( $autoload ) . '</code></td>';
                echo '</tr>';
            }

            echo '</tbody></table>';

            echo '<p style="font-size:12px;opacity:0.8;">';
            esc_html_e( 'If you delete options, delete only ones you can attribute with confidence. "Probably" is not a backup strategy.', 'wp-options-table-auditor' );
            echo '</p>';
        }

        private function render_transients( $audit ) {
            $trans = isset( $audit['transients'] ) ? (array) $audit['transients'] : array();

            $expired = isset( $trans['expired_transients_sample'] ) && is_array( $trans['expired_transients_sample'] ) ? $trans['expired_transients_sample'] : array();
            $expired_site = isset( $trans['expired_site_transients_sample'] ) && is_array( $trans['expired_site_transients_sample'] ) ? $trans['expired_site_transients_sample'] : array();

            $exp_count = isset( $trans['expired_transients_count_estimate'] ) ? (int) $trans['expired_transients_count_estimate'] : 0;
            $exp_site_count = isset( $trans['expired_site_transients_count_estimate'] ) ? (int) $trans['expired_site_transients_count_estimate'] : 0;

            echo '<p><strong>Expired transients estimate:</strong> ' . esc_html( $exp_count ) . '</p>';
            echo '<p><strong>Expired site transients estimate:</strong> ' . esc_html( $exp_site_count ) . '</p>';

            if ( empty( $expired ) && empty( $expired_site ) ) {
                echo '<p>No expired transients found in the sample. Either WP-Cron is actually working or you are blessed.</p>';
                return;
            }

            if ( ! empty( $expired ) ) {
                echo '<h3>Expired transients (sample)</h3>';
                echo '<table class="widefat striped">';
                echo '<thead><tr><th>Timeout option</th><th>Value option</th><th>Expired at</th><th>Size</th></tr></thead><tbody>';
                foreach ( $expired as $row ) {
                    $bytes = (int) $row['bytes'];
                    echo '<tr>';
                    echo '<td><code>' . esc_html( $row['timeout_name'] ) . '</code></td>';
                    echo '<td><code>' . esc_html( $row['value_name'] ) . '</code></td>';
                    echo '<td>' . esc_html( $row['timeout_human'] ) . '</td>';
                    echo '<td>' . esc_html( $this->human_bytes( $bytes ) ) . '</td>';
                    echo '</tr>';
                }
                echo '</tbody></table>';
            }

            if ( ! empty( $expired_site ) ) {
                echo '<h3>Expired site transients (sample)</h3>';
                echo '<table class="widefat striped">';
                echo '<thead><tr><th>Timeout option</th><th>Value option</th><th>Expired at</th><th>Size</th></tr></thead><tbody>';
                foreach ( $expired_site as $row ) {
                    $bytes = (int) $row['bytes'];
                    echo '<tr>';
                    echo '<td><code>' . esc_html( $row['timeout_name'] ) . '</code></td>';
                    echo '<td><code>' . esc_html( $row['value_name'] ) . '</code></td>';
                    echo '<td>' . esc_html( $row['timeout_human'] ) . '</td>';
                    echo '<td>' . esc_html( $this->human_bytes( $bytes ) ) . '</td>';
                    echo '</tr>';
                }
                echo '</tbody></table>';
            }
        }
    }

    new WP_Options_Table_Auditor();
}
