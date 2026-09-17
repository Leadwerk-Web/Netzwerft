<?php
/**
 * Leadwerk Migration operations dashboard.
 *
 * Copyright (C) 2026 Leadwerk.
 * Licensed under GPLv3 or later.
 */

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Direct access is not allowed.' );
}

$notice_key = isset( $_GET['leadwerk_notice'] ) ? sanitize_key( wp_unslash( $_GET['leadwerk_notice'] ) ) : '';
$notices    = array(
	'health_refreshed' => array( 'success', __( 'The environment health report has been refreshed.', 'leadwerk-migration' ) ),
	'verify_ok'        => array( 'success', __( 'Backup integrity was verified successfully.', 'leadwerk-migration' ) ),
	'verify_failed'    => array( 'error', __( 'Backup integrity could not be verified. Review the activity log.', 'leadwerk-migration' ) ),
);

$score        = isset( $report['score'] ) ? (int) $report['score'] : 0;
$score_status = $score >= 85 ? 'good' : ( $score >= 60 ? 'warning' : 'critical' );
$enabled      = ! empty( $settings['enabled'] );
$frequency    = isset( $settings['frequency'] ) ? sanitize_key( $settings['frequency'] ) : 'daily';
$frequencies  = array(
	'hourly'  => __( 'Hourly', 'leadwerk-migration' ),
	'daily'   => __( 'Daily', 'leadwerk-migration' ),
	'weekly'  => __( 'Weekly', 'leadwerk-migration' ),
	'monthly' => __( 'Monthly', 'leadwerk-migration' ),
);
?>

<div class="wrap leadwerk-dashboard">
	<?php if ( isset( $notices[ $notice_key ] ) ) : ?>
		<div class="notice notice-<?php echo esc_attr( $notices[ $notice_key ][0] ); ?> is-dismissible"><p><?php echo esc_html( $notices[ $notice_key ][1] ); ?></p></div>
	<?php endif; ?>

	<header class="leadwerk-dashboard__hero">
		<div>
			<p class="leadwerk-dashboard__eyebrow"><?php esc_html_e( 'LEADWERK MIGRATION', 'leadwerk-migration' ); ?></p>
			<h1><?php esc_html_e( 'Migration control center', 'leadwerk-migration' ); ?></h1>
			<p><?php esc_html_e( 'Monitor site readiness, verified backups, and automated protection from one place.', 'leadwerk-migration' ); ?></p>
		</div>
		<div class="leadwerk-dashboard__hero-actions">
			<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=leadwerk_migration_export' ) ); ?>"><?php esc_html_e( 'New export', 'leadwerk-migration' ); ?></a>
			<a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=leadwerk_migration_import' ) ); ?>"><?php esc_html_e( 'Import site', 'leadwerk-migration' ); ?></a>
		</div>
	</header>

	<section class="leadwerk-dashboard__metrics" aria-label="<?php esc_attr_e( 'Migration summary', 'leadwerk-migration' ); ?>">
		<article class="leadwerk-metric">
			<span><?php esc_html_e( 'Environment health', 'leadwerk-migration' ); ?></span>
			<strong class="leadwerk-score leadwerk-score--<?php echo esc_attr( $score_status ); ?>"><?php echo esc_html( $score ); ?><small>/100</small></strong>
			<small><?php echo esc_html( sprintf( _n( '%d check needs attention', '%d checks need attention', (int) $report['counts']['warning'] + (int) $report['counts']['critical'], 'leadwerk-migration' ), (int) $report['counts']['warning'] + (int) $report['counts']['critical'] ) ); ?></small>
		</article>
		<article class="leadwerk-metric">
			<span><?php esc_html_e( 'Protected backups', 'leadwerk-migration' ); ?></span>
			<strong><?php echo esc_html( number_format_i18n( $backup_count ) ); ?></strong>
			<small><?php echo esc_html( leadwerk_migration_size_format( $total_size, 2 ) ); ?> <?php esc_html_e( 'stored privately', 'leadwerk-migration' ); ?></small>
		</article>
		<article class="leadwerk-metric">
			<span><?php esc_html_e( 'Automation', 'leadwerk-migration' ); ?></span>
			<strong class="leadwerk-state <?php echo $enabled ? 'is-active' : ''; ?>"><?php echo esc_html( $enabled ? __( 'Active', 'leadwerk-migration' ) : __( 'Off', 'leadwerk-migration' ) ); ?></strong>
			<small>
				<?php
				if ( $is_running ) {
					esc_html_e( 'Backup currently running', 'leadwerk-migration' );
				} elseif ( $enabled && $next_run ) {
					echo esc_html( sprintf( __( '%1$s · next %2$s', 'leadwerk-migration' ), isset( $frequencies[ $frequency ] ) ? $frequencies[ $frequency ] : ucfirst( $frequency ), wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $next_run ) ) );
				} else {
					esc_html_e( 'Configure a local backup schedule', 'leadwerk-migration' );
				}
				?>
			</small>
		</article>
	</section>

	<div class="leadwerk-dashboard__grid">
		<section class="leadwerk-panel leadwerk-panel--health">
			<div class="leadwerk-panel__header">
				<div><h2><?php esc_html_e( 'Site readiness', 'leadwerk-migration' ); ?></h2><p><?php esc_html_e( 'Pre-flight checks for reliable exports and restores.', 'leadwerk-migration' ); ?></p></div>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="leadwerk_migration_refresh_health" />
					<?php wp_nonce_field( 'leadwerk_migration_refresh_health' ); ?>
					<button class="button" type="submit"><?php esc_html_e( 'Run checks', 'leadwerk-migration' ); ?></button>
				</form>
			</div>
			<div class="leadwerk-health-list">
				<?php foreach ( $report['checks'] as $check ) : ?>
					<details class="leadwerk-health leadwerk-health--<?php echo esc_attr( $check['status'] ); ?>">
						<summary><span class="leadwerk-health__dot" aria-hidden="true"></span><strong><?php echo esc_html( $check['label'] ); ?></strong><span><?php echo esc_html( ucfirst( $check['status'] ) ); ?></span></summary>
						<p><?php echo esc_html( $check['message'] ); ?></p>
					</details>
				<?php endforeach; ?>
			</div>
		</section>

		<aside class="leadwerk-panel leadwerk-panel--quick">
			<div class="leadwerk-panel__header"><div><h2><?php esc_html_e( 'Quick protection', 'leadwerk-migration' ); ?></h2><p><?php esc_html_e( 'Run a private backup with the saved policy.', 'leadwerk-migration' ); ?></p></div></div>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="leadwerk_migration_run_backup" />
				<?php wp_nonce_field( 'leadwerk_migration_run_backup' ); ?>
				<button class="button button-primary button-hero" type="submit" <?php disabled( $is_running ); ?>><?php echo esc_html( $is_running ? __( 'Backup running…', 'leadwerk-migration' ) : __( 'Back up now', 'leadwerk-migration' ) ); ?></button>
			</form>
			<ul class="leadwerk-feature-list">
				<li><?php esc_html_e( 'SHA-256 integrity manifests', 'leadwerk-migration' ); ?></li>
				<li><?php esc_html_e( 'Private, deny-by-default storage', 'leadwerk-migration' ); ?></li>
				<li><?php esc_html_e( 'Automatic retention cleanup', 'leadwerk-migration' ); ?></li>
			</ul>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=leadwerk_migration_schedules' ) ); ?>"><?php esc_html_e( 'Configure automation →', 'leadwerk-migration' ); ?></a>
		</aside>
	</div>

	<section class="leadwerk-panel">
		<div class="leadwerk-panel__header">
			<div><h2><?php esc_html_e( 'Recent backups', 'leadwerk-migration' ); ?></h2><p><?php esc_html_e( 'Every new backup receives a tamper-evident SHA-256 baseline.', 'leadwerk-migration' ); ?></p></div>
			<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=leadwerk_migration_backups' ) ); ?>"><?php esc_html_e( 'View all', 'leadwerk-migration' ); ?></a>
		</div>
		<?php if ( $backups ) : ?>
			<div class="leadwerk-table-wrap"><table class="widefat striped leadwerk-table">
				<thead><tr><th><?php esc_html_e( 'Backup', 'leadwerk-migration' ); ?></th><th><?php esc_html_e( 'Created', 'leadwerk-migration' ); ?></th><th><?php esc_html_e( 'Size', 'leadwerk-migration' ); ?></th><th><?php esc_html_e( 'Integrity', 'leadwerk-migration' ); ?></th><th><span class="screen-reader-text"><?php esc_html_e( 'Actions', 'leadwerk-migration' ); ?></span></th></tr></thead>
				<tbody>
				<?php foreach ( $backups as $backup ) : ?>
					<?php $integrity = is_wp_error( $backup['integrity'] ) ? 'error' : $backup['integrity']; ?>
					<tr>
						<td><strong><?php echo esc_html( basename( $backup['filename'] ) ); ?></strong></td>
						<td><?php echo $backup['mtime'] ? esc_html( sprintf( __( '%s ago', 'leadwerk-migration' ), human_time_diff( (int) $backup['mtime'] ) ) ) : '—'; ?></td>
						<td><?php echo null !== $backup['size'] ? esc_html( leadwerk_migration_size_format( $backup['size'], 2 ) ) : esc_html__( 'Unknown', 'leadwerk-migration' ); ?></td>
						<td><span class="leadwerk-badge leadwerk-badge--<?php echo esc_attr( $integrity ); ?>"><?php echo esc_html( ucfirst( $integrity ) ); ?></span></td>
						<td class="leadwerk-table__actions">
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
								<input type="hidden" name="action" value="leadwerk_migration_verify_backup" /><input type="hidden" name="archive" value="<?php echo esc_attr( $backup['filename'] ); ?>" />
								<?php if ( 'untracked' === $integrity ) : ?><input type="hidden" name="establish" value="1" /><?php endif; ?>
								<?php wp_nonce_field( 'leadwerk_migration_verify_backup' ); ?>
								<button class="button-link" type="submit"><?php echo esc_html( 'untracked' === $integrity ? __( 'Create baseline', 'leadwerk-migration' ) : __( 'Verify', 'leadwerk-migration' ) ); ?></button>
							</form>
							<a href="<?php echo esc_url( Leadwerk_Migration_Dashboard_Controller::download_url( $backup['filename'] ) ); ?>"><?php esc_html_e( 'Download', 'leadwerk-migration' ); ?></a>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table></div>
		<?php else : ?>
			<div class="leadwerk-empty"><span class="dashicons dashicons-database-add" aria-hidden="true"></span><h3><?php esc_html_e( 'No backups yet', 'leadwerk-migration' ); ?></h3><p><?php esc_html_e( 'Create your first verified backup to establish a recovery point.', 'leadwerk-migration' ); ?></p></div>
		<?php endif; ?>
	</section>

	<section class="leadwerk-panel">
		<div class="leadwerk-panel__header"><div><h2><?php esc_html_e( 'Activity', 'leadwerk-migration' ); ?></h2><p><?php esc_html_e( 'Recent automation and integrity events.', 'leadwerk-migration' ); ?></p></div></div>
		<?php if ( $activity ) : ?><ol class="leadwerk-activity">
			<?php foreach ( $activity as $event ) : ?>
				<?php
				$event_type = isset( $event['status'] ) ? $event['status'] : ( isset( $event['type'] ) ? $event['type'] : ( isset( $event['event'] ) ? $event['event'] : 'info' ) );
				$event_time = isset( $event['timestamp'] ) ? $event['timestamp'] : ( isset( $event['time'] ) ? $event['time'] : ( isset( $event['created_at'] ) ? $event['created_at'] : '' ) );
				$timestamp  = is_numeric( $event_time ) ? (int) $event_time : strtotime( (string) $event_time );
				?>
				<li><span class="leadwerk-activity__icon" aria-hidden="true"></span><div><strong><?php echo esc_html( ucwords( str_replace( '_', ' ', $event_type ) ) ); ?></strong><p><?php echo esc_html( isset( $event['message'] ) ? $event['message'] : '' ); ?></p></div><time><?php echo $timestamp ? esc_html( human_time_diff( $timestamp ) . ' ' . __( 'ago', 'leadwerk-migration' ) ) : '—'; ?></time></li>
			<?php endforeach; ?>
		</ol><?php else : ?><p class="leadwerk-muted"><?php esc_html_e( 'No automation activity has been recorded yet.', 'leadwerk-migration' ); ?></p><?php endif; ?>
	</section>
</div>
