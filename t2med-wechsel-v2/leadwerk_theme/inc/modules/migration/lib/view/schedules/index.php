<?php
/**
 * Backup automation settings.
 *
 * Copyright (C) 2026 Leadwerk.
 * Licensed under GPLv3 or later.
 */

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Direct access is not allowed.' );
}

$notice_key = isset( $_GET['leadwerk_notice'] ) ? sanitize_key( wp_unslash( $_GET['leadwerk_notice'] ) ) : '';
$notices    = array(
	'settings_saved' => array( 'success', __( 'Automation settings saved and the schedule was synchronized.', 'leadwerk-migration' ) ),
	'settings_failed'=> array( 'error', __( 'Automation settings could not be saved or scheduled. Review WP-Cron and try again.', 'leadwerk-migration' ) ),
	'backup_queued'  => array( 'success', __( 'The backup job was queued. WP-Cron will start it shortly.', 'leadwerk-migration' ) ),
	'queue_failed'   => array( 'error', __( 'The backup could not be queued. Another job may already be running.', 'leadwerk-migration' ) ),
);

$frequency_labels = array(
	'hourly'  => __( 'Hourly', 'leadwerk-migration' ),
	'daily'   => __( 'Daily', 'leadwerk-migration' ),
	'weekly'  => __( 'Weekly', 'leadwerk-migration' ),
	'monthly' => __( 'Monthly', 'leadwerk-migration' ),
);
$exclusion_labels = array(
	'no_spam_comments' => __( 'Spam comments', 'leadwerk-migration' ),
	'no_post_revisions' => __( 'Post revisions', 'leadwerk-migration' ),
	'no_media'         => __( 'Media library', 'leadwerk-migration' ),
	'no_themes'        => __( 'Themes', 'leadwerk-migration' ),
	'no_muplugins'     => __( 'Must-use plugins', 'leadwerk-migration' ),
	'no_plugins'       => __( 'Plugins', 'leadwerk-migration' ),
);
$enabled = ! empty( $settings['enabled'] );
?>

<div class="wrap leadwerk-dashboard leadwerk-automation">
	<?php if ( isset( $notices[ $notice_key ] ) ) : ?>
		<div class="notice notice-<?php echo esc_attr( $notices[ $notice_key ][0] ); ?> is-dismissible"><p><?php echo esc_html( $notices[ $notice_key ][1] ); ?></p></div>
	<?php endif; ?>

	<header class="leadwerk-dashboard__hero leadwerk-dashboard__hero--compact">
		<div>
			<p class="leadwerk-dashboard__eyebrow"><?php esc_html_e( 'LOCAL AUTOMATION', 'leadwerk-migration' ); ?></p>
			<h1><?php esc_html_e( 'Backup automation', 'leadwerk-migration' ); ?></h1>
			<p><?php esc_html_e( 'Schedule verified local backups, enforce retention, and receive completion alerts without an external service.', 'leadwerk-migration' ); ?></p>
		</div>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="leadwerk_migration_run_backup" />
			<?php wp_nonce_field( 'leadwerk_migration_run_backup' ); ?>
			<button class="button button-primary" type="submit" <?php disabled( $is_running ); ?>><?php echo esc_html( $is_running ? __( 'Backup running…', 'leadwerk-migration' ) : __( 'Run now', 'leadwerk-migration' ) ); ?></button>
		</form>
	</header>

	<div class="leadwerk-dashboard__grid leadwerk-dashboard__grid--automation">
		<section class="leadwerk-panel">
			<div class="leadwerk-panel__header"><div><h2><?php esc_html_e( 'Schedule policy', 'leadwerk-migration' ); ?></h2><p><?php esc_html_e( 'Times use the WordPress site timezone.', 'leadwerk-migration' ); ?></p></div><span class="leadwerk-badge <?php echo $enabled ? 'leadwerk-badge--verified' : 'leadwerk-badge--untracked'; ?>"><?php echo esc_html( $enabled ? __( 'Enabled', 'leadwerk-migration' ) : __( 'Disabled', 'leadwerk-migration' ) ); ?></span></div>

			<form class="leadwerk-settings" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="leadwerk_migration_save_automation" />
				<?php wp_nonce_field( 'leadwerk_migration_save_automation' ); ?>

				<label class="leadwerk-toggle-row" for="leadwerk-automation-enabled">
					<span><strong><?php esc_html_e( 'Automated backups', 'leadwerk-migration' ); ?></strong><small><?php esc_html_e( 'Let WP-Cron run backups using this policy.', 'leadwerk-migration' ); ?></small></span>
					<input type="hidden" name="settings[enabled]" value="0" />
					<input type="checkbox" id="leadwerk-automation-enabled" name="settings[enabled]" value="1" <?php checked( $enabled ); ?> />
				</label>

				<div class="leadwerk-field-grid">
					<label><span><?php esc_html_e( 'Frequency', 'leadwerk-migration' ); ?></span><select name="settings[frequency]">
						<?php foreach ( $frequency_labels as $value => $label ) : ?><option value="<?php echo esc_attr( $value ); ?>" <?php selected( isset( $settings['frequency'] ) ? $settings['frequency'] : 'daily', $value ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?>
					</select></label>
					<label><span><?php esc_html_e( 'Start hour', 'leadwerk-migration' ); ?></span><input type="number" min="0" max="23" step="1" name="settings[start_hour]" value="<?php echo esc_attr( isset( $settings['start_hour'] ) ? $settings['start_hour'] : 2 ); ?>" /></label>
					<label><span><?php esc_html_e( 'Start minute', 'leadwerk-migration' ); ?></span><input type="number" min="0" max="59" step="1" name="settings[start_minute]" value="<?php echo esc_attr( isset( $settings['start_minute'] ) ? $settings['start_minute'] : 0 ); ?>" /></label>
					<label><span><?php esc_html_e( 'Backups to retain (0 disables cleanup)', 'leadwerk-migration' ); ?></span><input type="number" min="0" max="100" step="1" name="settings[retention_count]" value="<?php echo esc_attr( isset( $settings['retention_count'] ) ? $settings['retention_count'] : 5 ); ?>" /></label>
				</div>

				<fieldset class="leadwerk-fieldset">
					<legend><?php esc_html_e( 'Export exclusions', 'leadwerk-migration' ); ?></legend>
					<p><?php esc_html_e( 'Exclude expendable or large content from scheduled backups.', 'leadwerk-migration' ); ?></p>
					<div class="leadwerk-check-grid">
						<?php foreach ( $exclusion_labels as $key => $label ) : ?>
							<label><input type="checkbox" name="settings[exclusions][<?php echo esc_attr( $key ); ?>]" value="1" <?php checked( ! empty( $settings['exclusions'][ $key ] ) ); ?> /> <?php echo esc_html( $label ); ?></label>
						<?php endforeach; ?>
					</div>
				</fieldset>

				<fieldset class="leadwerk-fieldset">
					<legend><?php esc_html_e( 'Notifications', 'leadwerk-migration' ); ?></legend>
					<label class="leadwerk-wide-field"><span><?php esc_html_e( 'Email address', 'leadwerk-migration' ); ?></span><input type="email" class="regular-text" name="settings[notification_email]" value="<?php echo esc_attr( isset( $settings['notification_email'] ) ? $settings['notification_email'] : get_option( 'admin_email' ) ); ?>" /></label>
					<div class="leadwerk-check-grid">
						<label><input type="checkbox" name="settings[notify_success]" value="1" <?php checked( ! empty( $settings['notify_success'] ) ); ?> /> <?php esc_html_e( 'Email after successful backups', 'leadwerk-migration' ); ?></label>
						<label><input type="checkbox" name="settings[notify_error]" value="1" <?php checked( ! empty( $settings['notify_error'] ) ); ?> /> <?php esc_html_e( 'Email when a backup fails', 'leadwerk-migration' ); ?></label>
					</div>
				</fieldset>

				<div class="leadwerk-settings__footer"><button type="submit" class="button button-primary"><?php esc_html_e( 'Save automation', 'leadwerk-migration' ); ?></button></div>
			</form>
		</section>

		<aside class="leadwerk-panel leadwerk-automation-status">
			<div class="leadwerk-panel__header"><div><h2><?php esc_html_e( 'Runtime status', 'leadwerk-migration' ); ?></h2><p><?php esc_html_e( 'Automation uses WordPress cron and an atomic lock.', 'leadwerk-migration' ); ?></p></div></div>
			<dl class="leadwerk-status-list">
				<div><dt><?php esc_html_e( 'Worker', 'leadwerk-migration' ); ?></dt><dd><span class="leadwerk-state-dot <?php echo $is_running ? 'is-running' : ''; ?>"></span><?php echo esc_html( $is_running ? __( 'Running', 'leadwerk-migration' ) : __( 'Idle', 'leadwerk-migration' ) ); ?></dd></div>
				<div><dt><?php esc_html_e( 'Next run', 'leadwerk-migration' ); ?></dt><dd><?php echo $enabled && $next_run ? esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $next_run ) ) : esc_html__( 'Not scheduled', 'leadwerk-migration' ); ?></dd></div>
				<div><dt><?php esc_html_e( 'Retention', 'leadwerk-migration' ); ?></dt><dd><?php echo esc_html( sprintf( _n( '%d backup', '%d backups', (int) $settings['retention_count'], 'leadwerk-migration' ), (int) $settings['retention_count'] ) ); ?></dd></div>
				<div><dt><?php esc_html_e( 'Timezone', 'leadwerk-migration' ); ?></dt><dd><?php echo esc_html( wp_timezone_string() ); ?></dd></div>
			</dl>
			<div class="leadwerk-callout"><strong><?php esc_html_e( 'Low-traffic site?', 'leadwerk-migration' ); ?></strong><p><?php esc_html_e( 'WP-Cron runs when your site receives a request. For exact timing, configure your host to call wp-cron.php regularly.', 'leadwerk-migration' ); ?></p></div>
		</aside>
	</div>

	<section class="leadwerk-panel">
		<div class="leadwerk-panel__header"><div><h2><?php esc_html_e( 'Automation history', 'leadwerk-migration' ); ?></h2><p><?php esc_html_e( 'The newest 100 events are retained.', 'leadwerk-migration' ); ?></p></div></div>
		<?php if ( $activity ) : ?>
			<div class="leadwerk-table-wrap"><table class="widefat striped leadwerk-table"><thead><tr><th><?php esc_html_e( 'Time', 'leadwerk-migration' ); ?></th><th><?php esc_html_e( 'Status', 'leadwerk-migration' ); ?></th><th><?php esc_html_e( 'Message', 'leadwerk-migration' ); ?></th></tr></thead><tbody>
			<?php foreach ( $activity as $event ) : ?>
				<?php $event_timestamp = ! empty( $event['timestamp'] ) ? strtotime( $event['timestamp'] ) : false; ?>
				<tr><td><?php echo $event_timestamp ? esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $event_timestamp ) ) : '—'; ?></td><td><span class="leadwerk-badge leadwerk-badge--<?php echo esc_attr( sanitize_html_class( isset( $event['status'] ) ? $event['status'] : 'info' ) ); ?>"><?php echo esc_html( ucwords( str_replace( '_', ' ', isset( $event['status'] ) ? $event['status'] : 'info' ) ) ); ?></span></td><td><?php echo esc_html( isset( $event['message'] ) ? $event['message'] : '' ); ?></td></tr>
			<?php endforeach; ?>
			</tbody></table></div>
		<?php else : ?><div class="leadwerk-empty"><span class="dashicons dashicons-clock" aria-hidden="true"></span><h3><?php esc_html_e( 'No runs recorded', 'leadwerk-migration' ); ?></h3><p><?php esc_html_e( 'Automation events will appear here after the first scheduled or manual run.', 'leadwerk-migration' ); ?></p></div><?php endif; ?>
	</section>
</div>
