<?php
/** Leadwerk Site Sync administration view. */
if ( ! defined( 'ABSPATH' ) ) {
	die( 'Direct access is not allowed.' );
}

$has_family = is_array( $family ) && Leadwerk_Migration_Site_Identity::valid_id( isset( $family['family_id'] ) ? $family['family_id'] : '' );
$is_hub_site = Leadwerk_Migration_Sync_Hub::is_hub_site();
$registration = isset( $environment['registration'] ) ? $environment['registration'] : 'unregistered';
$claimed_family = Leadwerk_Migration_Site_Identity::valid_id( isset( $environment['claimed_family_id'] ) ? $environment['claimed_family_id'] : '' ) ? $environment['claimed_family_id'] : '';
$has_active_commands = false;
foreach ( is_array( $commands ) ? $commands : array() as $command ) {
	if ( is_array( $command ) && isset( $command['status'] ) && in_array( $command['status'], array( 'queued', 'running', 'waiting_runtime' ), true ) ) {
		$has_active_commands = true;
		break;
	}
}
$source_environments = array_values(
	array_filter(
		is_array( $directory ) ? $directory : array(),
		static function ( $item ) {
			return is_array( $item ) && isset( $item['status'] ) && 'approved' === $item['status'] && Leadwerk_Migration_Site_Identity::valid_id( isset( $item['family_id'] ) ? $item['family_id'] : '' );
		}
	)
);
$target_environments = array_values(
	array_filter(
		is_array( $directory ) ? $directory : array(),
		static function ( $item ) use ( $is_hub_site ) {
			return is_array( $item ) && ( 'approved' === ( isset( $item['status'] ) ? $item['status'] : '' ) || ( $is_hub_site && 'available' === ( isset( $item['status'] ) ? $item['status'] : '' ) ) );
		}
	)
);
$format_seen = static function ( $row ) {
	if ( ! is_array( $row ) || empty( $row['last_seen_at'] ) ) {
		return __( 'Never', 'leadwerk-migration' );
	}
	$timestamp = strtotime( $row['last_seen_at'] . ' UTC' );
	return false === $timestamp ? __( 'Unknown', 'leadwerk-migration' ) : sprintf( __( '%s ago', 'leadwerk-migration' ), human_time_diff( $timestamp, time() ) );
};
$environment_labels = array( 'local' => __( 'Local', 'leadwerk-migration' ), 'staging' => __( 'Staging', 'leadwerk-migration' ), 'live' => __( 'Live', 'leadwerk-migration' ) );
$environment_index = array();
foreach ( is_array( $directory ) ? $directory : array() as $directory_environment ) {
	if ( is_array( $directory_environment ) && ! empty( $directory_environment['environment_id'] ) ) {
		$environment_index[ $directory_environment['environment_id'] ] = $directory_environment;
	}
}
$describe_environment = static function ( $environment_id ) use ( $environment_index, $environment_labels ) {
	if ( isset( $environment_index[ $environment_id ] ) ) {
		$row = $environment_index[ $environment_id ];
		$type = isset( $environment_labels[ $row['environment_type'] ] ) ? $environment_labels[ $row['environment_type'] ] : ucfirst( $row['environment_type'] );
		return $type . ' · ' . $row['site_url'];
	}
	return substr( (string) $environment_id, 0, 8 );
};
$registry_actions = static function ( $kind, $id, $status ) {
	$approve = ! in_array( $status, array( 'approved', 'available' ), true );
	$action = $approve ? 'leadwerk_migration_hub_approve' : 'leadwerk_migration_hub_revoke';
	ob_start();
	?>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="<?php echo esc_attr( $action ); ?>" /><input type="hidden" name="kind" value="<?php echo esc_attr( $kind ); ?>" /><input type="hidden" name="registry_id" value="<?php echo esc_attr( $id ); ?>" /><?php wp_nonce_field( $action ); ?><button class="button button-small" type="submit"><?php echo esc_html( $approve ? __( 'Reactivate', 'leadwerk-migration' ) : __( 'Emergency revoke', 'leadwerk-migration' ) ); ?></button></form>
	<?php
	return ob_get_clean();
};
?>

<div class="wrap leadwerk-dashboard leadwerk-sync">
	<?php if ( ! empty( $notice['message'] ) ) : ?>
		<div class="notice notice-<?php echo esc_attr( isset( $notice['type'] ) ? $notice['type'] : 'info' ); ?> is-dismissible"><p><?php echo esc_html( $notice['message'] ); ?></p></div>
	<?php endif; ?>
	<?php if ( $remote_error !== '' ) : ?>
		<div class="notice notice-warning"><p><strong><?php esc_html_e( 'Hub connection:', 'leadwerk-migration' ); ?></strong> <?php echo esc_html( $remote_error ); ?></p></div>
	<?php endif; ?>

	<header class="leadwerk-dashboard__hero leadwerk-dashboard__hero--compact">
		<div>
			<p class="leadwerk-dashboard__eyebrow"><?php esc_html_e( 'FULL WORDPRESS ENVIRONMENT TRANSFER', 'leadwerk-migration' ); ?></p>
			<h1><?php esc_html_e( 'Leadwerk Site Sync', 'leadwerk-migration' ); ?></h1>
				<p><?php esc_html_e( 'Plugin installations register as available targets. A Site Family is created only from the explicit button below; Hub administrators can then assign an available WordPress target when starting a transfer.', 'leadwerk-migration' ); ?></p>
		</div>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="leadwerk_migration_sync_process" />
			<?php wp_nonce_field( 'leadwerk_migration_sync_process' ); ?>
			<button class="button button-primary" type="submit" <?php disabled( ! Leadwerk_Migration_Sync_Hub::enabled() && ! $has_family && ! in_array( $registration, array( 'available', 'approved' ), true ) ); ?>><?php esc_html_e( 'Run worker now', 'leadwerk-migration' ); ?></button>
		</form>
	</header>

	<div class="leadwerk-dashboard__metrics leadwerk-dashboard__metrics--sync">
		<div class="leadwerk-metric"><span><?php esc_html_e( 'This environment', 'leadwerk-migration' ); ?></span><strong><?php echo esc_html( isset( $environment_labels[ $environment['type'] ] ) ? $environment_labels[ $environment['type'] ] : ucfirst( $environment['type'] ) ); ?></strong><small><?php echo esc_html( Leadwerk_Migration_Site_Identity::normalized_site_url() ); ?></small></div>
		<div class="leadwerk-metric"><span><?php esc_html_e( 'Runtime versions', 'leadwerk-migration' ); ?></span><strong><?php echo esc_html( get_bloginfo( 'version' ) ); ?></strong><small><?php echo esc_html( 'PHP ' . PHP_VERSION . ' · Plugin ' . LEADWERK_MIGRATION_VERSION ); ?></small></div>
			<div class="leadwerk-metric"><span><?php esc_html_e( 'Hub enrollment', 'leadwerk-migration' ); ?></span><strong class="leadwerk-state <?php echo in_array( $registration, array( 'approved', 'available' ), true ) ? 'is-active' : ''; ?>"><?php echo esc_html( 'approved' === $registration ? __( 'Active', 'leadwerk-migration' ) : ( 'available' === $registration ? __( 'Available target', 'leadwerk-migration' ) : ( $has_family ? __( 'Automatic retry', 'leadwerk-migration' ) : __( 'Registering', 'leadwerk-migration' ) ) ) ); ?></strong><small><?php echo esc_html( $settings['hub_url'] ); ?></small></div>
		<div class="leadwerk-metric leadwerk-metric--identity">
			<div class="leadwerk-metric__identity-header"><span><?php esc_html_e( 'Site Family identity', 'leadwerk-migration' ); ?></span><span class="leadwerk-badge <?php echo $has_family ? 'leadwerk-badge--verified' : 'leadwerk-badge--warning'; ?>"><?php echo esc_html( $has_family ? __( 'Zone Zero linked', 'leadwerk-migration' ) : __( 'Pending', 'leadwerk-migration' ) ); ?></span></div>
			<strong><?php echo esc_html( $has_family && isset( $family['project_name'] ) ? $family['project_name'] : get_bloginfo( 'name' ) ); ?></strong>
			<?php if ( $has_family ) : ?>
				<dl class="leadwerk-metric__identity-values"><div><dt><?php esc_html_e( 'Family ID', 'leadwerk-migration' ); ?></dt><dd><code><?php echo esc_html( $family['family_id'] ); ?></code></dd></div><div><dt><?php esc_html_e( 'Environment ID', 'leadwerk-migration' ); ?></dt><dd><code><?php echo esc_html( $environment['environment_id'] ); ?></code></dd></div></dl>
			<?php else : ?>
					<dl class="leadwerk-metric__identity-values"><div><dt><?php esc_html_e( 'Family ID', 'leadwerk-migration' ); ?></dt><dd><?php echo '' !== $claimed_family ? '<code>' . esc_html( $claimed_family ) . '</code>' : esc_html__( 'Not created', 'leadwerk-migration' ); ?></dd></div><div><dt><?php esc_html_e( 'Environment ID', 'leadwerk-migration' ); ?></dt><dd><code><?php echo esc_html( $environment['environment_id'] ); ?></code></dd></div></dl>
			<?php endif; ?>
		</div>
	</div>

		<?php if ( ! $has_family ) : ?>
			<section class="leadwerk-panel leadwerk-panel--zone-zero">
				<div class="leadwerk-panel__header"><div><h2><?php echo esc_html( '' !== $claimed_family ? __( 'Site Family assignment pending', 'leadwerk-migration' ) : __( 'Create a Site Family for this site', 'leadwerk-migration' ) ); ?></h2><p><?php echo esc_html( '' !== $claimed_family ? __( 'Hub assigned this environment to the selected source Family. The full-site synchronization will import the trusted Family proof while preserving this target’s environment identity.', 'leadwerk-migration' ) : __( 'Leave this site Family-less when it is only a destination. Create a Family ID only when this installation is the project origin you want to manage and synchronize.', 'leadwerk-migration' ) ); ?></p></div><span class="leadwerk-badge leadwerk-badge--warning"><?php echo esc_html( '' !== $claimed_family ? __( 'Assigned target', 'leadwerk-migration' ) : ( 'available' === $registration ? __( 'Available target', 'leadwerk-migration' ) : __( 'Registering', 'leadwerk-migration' ) ) ); ?></span></div>
				<?php if ( '' === $claimed_family ) : ?><form class="leadwerk-settings" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="leadwerk_migration_sync_initialize" /><?php wp_nonce_field( 'leadwerk_migration_sync_initialize' ); ?><label><span><?php esc_html_e( 'Project name', 'leadwerk-migration' ); ?></span><input type="text" name="project_name" maxlength="190" value="<?php echo esc_attr( get_bloginfo( 'name' ) ); ?>" required /></label><div class="leadwerk-settings__footer"><button class="button button-primary" type="submit"><?php esc_html_e( 'Create Family ID for this site', 'leadwerk-migration' ); ?></button></div></form><?php endif; ?>
			</section>
	<?php endif; ?>
	<?php if ( $has_family && isset( $environment['type'] ) && 'staging' === $environment['type'] ) : ?>
		<section class="leadwerk-panel leadwerk-panel--compact leadwerk-sync__promotion"><h3><?php esc_html_e( 'Promote this staging installation', 'leadwerk-migration' ); ?></h3><p><?php esc_html_e( 'Use this when the customer domain is now attached but automatic detection cannot identify the change. The environment ID and sync history are preserved.', 'leadwerk-migration' ); ?></p><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="leadwerk_migration_sync_promote_live" /><?php wp_nonce_field( 'leadwerk_migration_sync_promote_live' ); ?><label><span><?php esc_html_e( 'Enter PROMOTE LIVE', 'leadwerk-migration' ); ?></span><input type="text" name="promotion_confirmation" autocomplete="off" required /></label><button class="button" type="submit"><?php esc_html_e( 'Promote staging to live', 'leadwerk-migration' ); ?></button></form></section>
	<?php endif; ?>

	<div class="leadwerk-dashboard__grid leadwerk-dashboard__grid--sync leadwerk-dashboard__grid--sync-actions">
	<section class="leadwerk-panel leadwerk-panel--compact">
		<div class="leadwerk-panel__header"><div><h2><?php esc_html_e( 'Environments and heartbeat', 'leadwerk-migration' ); ?></h2><p><?php esc_html_e( 'All environments ping Hub every minute, while actionable jobs wake public workers immediately. Archive bytes move directly between compatible family environments; Hub is the authenticated control plane and fallback.', 'leadwerk-migration' ); ?></p></div><span class="leadwerk-badge" data-leadwerk-count="environments"><?php echo esc_html( sprintf( __( '%d environments', 'leadwerk-migration' ), count( $directory ) ) ); ?></span></div>
		<div data-leadwerk-dynamic="environments">
		<?php if ( $directory ) : ?>
			<div class="leadwerk-table-wrap"><table class="widefat striped leadwerk-table"><thead><tr><th><?php esc_html_e( 'Environment', 'leadwerk-migration' ); ?></th><th><?php esc_html_e( 'Status', 'leadwerk-migration' ); ?></th><th><?php esc_html_e( 'Last heartbeat', 'leadwerk-migration' ); ?></th><th><?php esc_html_e( 'WordPress', 'leadwerk-migration' ); ?></th><th><?php esc_html_e( 'PHP', 'leadwerk-migration' ); ?></th><th><?php esc_html_e( 'Plugin', 'leadwerk-migration' ); ?></th></tr></thead><tbody>
			<?php foreach ( $directory as $item ) : ?>
				<tr><td><strong><?php echo esc_html( isset( $environment_labels[ $item['environment_type'] ] ) ? $environment_labels[ $item['environment_type'] ] : ucfirst( $item['environment_type'] ) ); ?></strong><br /><small><?php echo esc_html( $item['site_url'] ); ?></small></td><td><span class="leadwerk-badge <?php echo ! empty( $item['is_online'] ) ? 'leadwerk-badge--verified' : 'leadwerk-badge--modified'; ?>"><span class="leadwerk-state-dot <?php echo empty( $item['is_online'] ) ? 'is-offline' : ''; ?>"></span><?php echo esc_html( ! empty( $item['is_online'] ) ? __( 'Online', 'leadwerk-migration' ) : __( 'Offline', 'leadwerk-migration' ) ); ?></span></td><td><?php echo esc_html( $format_seen( $item ) ); ?></td><td><?php echo esc_html( $item['wordpress_version'] ); ?></td><td><?php echo esc_html( $item['php_version'] ); ?></td><td><?php echo esc_html( $item['plugin_version'] ); ?></td></tr>
			<?php endforeach; ?>
			</tbody></table></div>
		<?php else : ?><div class="leadwerk-empty"><span class="dashicons dashicons-admin-site-alt3" aria-hidden="true"></span><h3><?php esc_html_e( 'Waiting for environment heartbeats', 'leadwerk-migration' ); ?></h3><p><?php esc_html_e( 'Sibling environments appear automatically after a cloned installation contacts Hub. No registration or approval action is needed.', 'leadwerk-migration' ); ?></p></div><?php endif; ?>
		</div>
	</section>

	<section class="leadwerk-panel leadwerk-panel--compact leadwerk-sync__transfer">
		<div class="leadwerk-panel__header"><div><h2><?php esc_html_e( 'Start full-site synchronization', 'leadwerk-migration' ); ?></h2><p><?php esc_html_e( 'Database, uploads/media, plugins, themes and MU plugins move from source to target. WordPress core, PHP, wp-config.php, DNS, SSL and hosting configuration are not transferred.', 'leadwerk-migration' ); ?></p></div><span class="leadwerk-badge leadwerk-badge--warning"><?php esc_html_e( 'Replaces target site data', 'leadwerk-migration' ); ?></span></div>
		<form class="leadwerk-settings" id="leadwerk-sync-transfer" data-leadwerk-sync-request method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="leadwerk_migration_sync_request" />
			<?php wp_nonce_field( 'leadwerk_migration_sync_request' ); ?>
			<div class="leadwerk-sync__direction">
				<label><span><?php esc_html_e( 'Source (will be backed up)', 'leadwerk-migration' ); ?></span><select name="source_environment_id" data-leadwerk-environment-select="source" required><option value=""><?php esc_html_e( 'Choose source', 'leadwerk-migration' ); ?></option><?php foreach ( $source_environments as $item ) : ?><option value="<?php echo esc_attr( $item['environment_id'] ); ?>" data-environment-type="<?php echo esc_attr( $item['environment_type'] ); ?>" data-family-id="<?php echo esc_attr( isset( $item['family_id'] ) ? $item['family_id'] : '' ); ?>"><?php echo esc_html( strtoupper( $item['environment_type'] ) . ' · ' . $item['site_url'] ); ?></option><?php endforeach; ?></select></label>
				<span class="dashicons dashicons-arrow-right-alt" aria-hidden="true"></span>
				<label><span><?php esc_html_e( 'Target (will be replaced)', 'leadwerk-migration' ); ?></span><select name="target_environment_id" data-leadwerk-environment-select="target" required><option value=""><?php esc_html_e( 'Choose target', 'leadwerk-migration' ); ?></option><?php foreach ( $target_environments as $item ) : $is_available_option = 'available' === ( isset( $item['status'] ) ? $item['status'] : '' ); ?><option value="<?php echo esc_attr( $item['environment_id'] ); ?>" data-environment-type="<?php echo esc_attr( $item['environment_type'] ); ?>" data-family-id="<?php echo esc_attr( isset( $item['family_id'] ) ? $item['family_id'] : '' ); ?>" data-unassigned="<?php echo $is_available_option ? '1' : '0'; ?>"><?php echo esc_html( ( $is_available_option ? __( 'AVAILABLE', 'leadwerk-migration' ) : strtoupper( $item['environment_type'] ) ) . ' · ' . $item['site_url'] ); ?></option><?php endforeach; ?></select></label>
			</div>
			<?php if ( $is_hub_site ) : ?><label class="leadwerk-sync__available-toggle"><input type="checkbox" id="leadwerk-show-unassigned" /> <?php esc_html_e( 'List WordPress environments without a Family ID', 'leadwerk-migration' ); ?></label><?php endif; ?>
			<div class="notice inline leadwerk-sync-request-feedback" data-leadwerk-sync-request-feedback role="status" aria-live="polite" hidden><p></p></div>
			<p><small><?php esc_html_e( 'When the target is Live, WordPress search-engine visibility is forced on after import even if the source was noindex.', 'leadwerk-migration' ); ?></small></p>
			<div class="leadwerk-settings__footer"><button class="button button-primary" type="submit" disabled><?php esc_html_e( 'Start full-site sync', 'leadwerk-migration' ); ?></button></div>
		</form>
	</section>
	</div>

	<section class="leadwerk-panel">
		<div class="leadwerk-panel__header"><div><h2><?php esc_html_e( 'Synchronization jobs', 'leadwerk-migration' ); ?></h2><p><?php esc_html_e( 'Archive bytes move only between the two authenticated family peers. Hub coordinates short-lived tickets and progress but never stores the WordPress backup.', 'leadwerk-migration' ); ?></p></div><span class="leadwerk-badge leadwerk-badge--verified" data-leadwerk-poll-status aria-live="polite"><?php esc_html_e( 'Live', 'leadwerk-migration' ); ?></span></div>
		<div class="leadwerk-sync__history-scroll" data-leadwerk-dynamic="jobs">
		<?php if ( $jobs ) : ?><div class="leadwerk-table-wrap"><table class="widefat striped leadwerk-table"><thead><tr><th><?php esc_html_e( 'Created', 'leadwerk-migration' ); ?></th><th><?php esc_html_e( 'Direction', 'leadwerk-migration' ); ?></th><th><?php esc_html_e( 'Status', 'leadwerk-migration' ); ?></th><th><?php esc_html_e( 'Progress', 'leadwerk-migration' ); ?></th><th><?php esc_html_e( 'Message', 'leadwerk-migration' ); ?></th><th><?php esc_html_e( 'Manage', 'leadwerk-migration' ); ?></th></tr></thead><tbody>
		<?php
		foreach ( $jobs as $job ) :
			$size = isset( $job['archive_size'] ) ? (int) $job['archive_size'] : 0;
			$is_direct = isset( $job['transport'] ) && in_array( $job['transport'], array( 'direct_push', 'direct_pull' ), true );
			$current = (int) ( isset( $job['bytes_delivered'] ) ? $job['bytes_delivered'] : 0 );
			if ( 'complete' === $job['status'] ) {
				$current = $size;
			}
			$percent = $size > 0 ? min( 100, (int) round( $current / $size * 100 ) ) : 0;
			$phase = $is_direct ? __( 'Peer-to-peer', 'leadwerk-migration' ) : __( 'Blocked legacy route', 'leadwerk-migration' );
			?>
			<tr><td><?php echo esc_html( isset( $job['created_at'] ) ? $job['created_at'] . ' UTC' : '' ); ?></td><td><?php echo esc_html( $describe_environment( $job['source_environment_id'] ) ); ?> → <?php echo esc_html( $describe_environment( $job['target_environment_id'] ) ); ?></td><td><span class="leadwerk-badge leadwerk-badge--<?php echo esc_attr( sanitize_html_class( $job['status'] ) ); ?>"><?php echo esc_html( ucwords( str_replace( '_', ' ', $job['status'] ) ) ); ?></span></td><td><?php if ( $size > 0 ) : ?><div class="leadwerk-sync-progress"><progress max="100" value="<?php echo esc_attr( $percent ); ?>" aria-label="<?php echo esc_attr( $phase . ' ' . $percent . '%' ); ?>"></progress><small><?php echo esc_html( $phase . ' ' . $percent . '% · ' . size_format( $current ) . ' / ' . size_format( $size ) ); ?></small></div><?php else : ?>—<?php endif; ?></td><td><?php echo esc_html( isset( $job['message'] ) ? $job['message'] : '' ); ?></td><td class="leadwerk-table__actions"><?php if ( in_array( $job['status'], array( 'preparing_target', 'approved', 'transferring', 'peer_ready', 'ready', 'receiving', 'failed' ), true ) ) : ?><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="leadwerk_migration_sync_cancel_job" /><input type="hidden" name="job_id" value="<?php echo esc_attr( $job['job_id'] ); ?>" /><?php wp_nonce_field( 'leadwerk_migration_sync_cancel_job' ); ?><button class="button-link" type="submit"><?php esc_html_e( 'Cancel', 'leadwerk-migration' ); ?></button></form><?php endif; ?><?php if ( in_array( $job['status'], array( 'failed', 'canceled' ), true ) ) : ?><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="leadwerk_migration_sync_retry_job" /><input type="hidden" name="job_id" value="<?php echo esc_attr( $job['job_id'] ); ?>" /><?php wp_nonce_field( 'leadwerk_migration_sync_retry_job' ); ?><button class="button-link" type="submit"><?php esc_html_e( 'Retry', 'leadwerk-migration' ); ?></button></form><?php endif; ?></td></tr>
		<?php endforeach; ?></tbody></table></div><?php else : ?><div class="leadwerk-empty"><span class="dashicons dashicons-randomize" aria-hidden="true"></span><h3><?php esc_html_e( 'No sync jobs', 'leadwerk-migration' ); ?></h3><p><?php esc_html_e( 'Jobs appear here after the first version-compatible transfer is requested.', 'leadwerk-migration' ); ?></p></div><?php endif; ?>
		</div>
	</section>

	<details class="leadwerk-panel leadwerk-sync__history" data-leadwerk-command-history<?php if ( $has_active_commands ) : ?> open<?php endif; ?>>
		<summary class="leadwerk-panel__header leadwerk-sync__history-summary"><div><h2><?php esc_html_e( 'Automatic family updates', 'leadwerk-migration' ); ?></h2><p><?php esc_html_e( 'Hub distributes the newest publisher-signed Migration release globally. Open this compact history only when you need its details.', 'leadwerk-migration' ); ?></p></div><span class="leadwerk-sync__history-meta"><span class="leadwerk-badge" data-leadwerk-command-count><?php echo esc_html( sprintf( __( '%d records', 'leadwerk-migration' ), count( $commands ) ) ); ?></span><span class="leadwerk-badge leadwerk-badge--verified" data-leadwerk-poll-status aria-live="polite"><?php esc_html_e( 'Live', 'leadwerk-migration' ); ?></span></span></summary>
		<div class="leadwerk-sync__history-body">
		<div class="leadwerk-sync__history-scroll" data-leadwerk-dynamic="commands">
		<?php if ( $commands ) : ?><div class="leadwerk-table-wrap"><table class="widefat striped leadwerk-table"><thead><tr><th><?php esc_html_e( 'Created', 'leadwerk-migration' ); ?></th><th><?php esc_html_e( 'Target', 'leadwerk-migration' ); ?></th><th><?php esc_html_e( 'Target version', 'leadwerk-migration' ); ?></th><th><?php esc_html_e( 'Stage', 'leadwerk-migration' ); ?></th><th><?php esc_html_e( 'Status', 'leadwerk-migration' ); ?></th><th><?php esc_html_e( 'Message', 'leadwerk-migration' ); ?></th><th><?php esc_html_e( 'Manage', 'leadwerk-migration' ); ?></th></tr></thead><tbody><?php foreach ( $commands as $command ) : $command_payload = isset( $command['payload'] ) && is_array( $command['payload'] ) ? $command['payload'] : array(); $target_version = ! empty( $command_payload['wordpress_version'] ) ? 'WordPress ' . $command_payload['wordpress_version'] : 'Plugin ' . ( isset( $command_payload['plugin_version'] ) ? $command_payload['plugin_version'] : '—' ); ?><tr><td><?php echo esc_html( $command['created_at'] . ' UTC' ); ?></td><td><?php echo esc_html( $describe_environment( $command['target_environment_id'] ) ); ?></td><td><?php echo esc_html( $target_version ); ?></td><td><?php echo esc_html( isset( $command_payload['stage'] ) ? ucfirst( $command_payload['stage'] ) : '—' ); ?></td><td><span class="leadwerk-badge leadwerk-badge--<?php echo esc_attr( sanitize_html_class( $command['status'] ) ); ?>"><?php echo esc_html( ucwords( str_replace( '_', ' ', $command['status'] ) ) ); ?></span></td><td><?php echo esc_html( $command['message'] ); ?></td><td class="leadwerk-table__actions"><?php if ( ! $is_hub_site && in_array( $command['status'], array( 'queued', 'running', 'waiting_runtime' ), true ) ) : ?><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="leadwerk_migration_runtime_cancel_command" /><input type="hidden" name="command_id" value="<?php echo esc_attr( $command['command_id'] ); ?>" /><?php wp_nonce_field( 'leadwerk_migration_runtime_cancel_command' ); ?><button class="button-link" type="submit"><?php esc_html_e( 'Cancel', 'leadwerk-migration' ); ?></button></form><?php endif; ?><?php if ( ! $is_hub_site && in_array( $command['status'], array( 'failed', 'canceled', 'manual_required' ), true ) ) : ?><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="leadwerk_migration_runtime_retry_command" /><input type="hidden" name="command_id" value="<?php echo esc_attr( $command['command_id'] ); ?>" /><?php wp_nonce_field( 'leadwerk_migration_runtime_retry_command' ); ?><button class="button-link" type="submit"><?php esc_html_e( 'Retry', 'leadwerk-migration' ); ?></button></form><?php endif; ?></td></tr><?php endforeach; ?></tbody></table></div><?php else : ?><div class="leadwerk-empty"><span class="dashicons dashicons-update" aria-hidden="true"></span><h3><?php esc_html_e( 'No automatic family updates yet', 'leadwerk-migration' ); ?></h3></div><?php endif; ?>
		</div>
		</div>
	</details>

	<?php if ( $is_hub_site ) : ?>
	<section class="leadwerk-panel">
		<div class="leadwerk-panel__header"><div><h2><?php esc_html_e( 'Site Sync settings', 'leadwerk-migration' ); ?></h2><p><?php esc_html_e( 'Automatic detection treats .local as local and myrdbx.io as staging. When the same installation receives a normal customer domain, its identity is preserved and its Hub type changes to live.', 'leadwerk-migration' ); ?></p></div></div>
		<form class="leadwerk-settings" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="leadwerk_migration_sync_save" /><?php wp_nonce_field( 'leadwerk_migration_sync_save' ); ?>
			<div class="leadwerk-field-grid">
				<label><span><?php esc_html_e( 'Project name', 'leadwerk-migration' ); ?></span><input type="text" name="project_name" maxlength="190" value="<?php echo esc_attr( isset( $family['project_name'] ) ? $family['project_name'] : get_bloginfo( 'name' ) ); ?>" /></label>
				<label><span><?php esc_html_e( 'Leadwerk Hub URL', 'leadwerk-migration' ); ?></span><input type="url" name="settings[hub_url]" value="<?php echo esc_attr( $settings['hub_url'] ); ?>" required /></label>
				<label><span><?php esc_html_e( 'Environment type', 'leadwerk-migration' ); ?></span><select name="settings[environment_type]"><?php foreach ( array( 'auto' => __( 'Automatic', 'leadwerk-migration' ), 'local' => __( 'Local', 'leadwerk-migration' ), 'staging' => __( 'Staging', 'leadwerk-migration' ), 'live' => __( 'Live', 'leadwerk-migration' ) ) as $value => $label ) : ?><option value="<?php echo esc_attr( $value ); ?>" <?php selected( $settings['environment_type'], $value ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select></label>
				<label><span><?php esc_html_e( 'Hosting PHP update webhook (optional)', 'leadwerk-migration' ); ?></span><input type="url" name="settings[php_update_webhook_url]" value="<?php echo esc_attr( isset( $settings['php_update_webhook_url'] ) ? $settings['php_update_webhook_url'] : '' ); ?>" placeholder="https://hosting.example/update-php" /></label>
				<label><span><?php esc_html_e( 'PHP webhook HMAC secret', 'leadwerk-migration' ); ?></span><input type="password" name="settings[php_update_webhook_secret]" value="" placeholder="<?php echo ! empty( $settings['php_update_webhook_secret'] ) ? esc_attr__( 'Saved — leave blank to keep', 'leadwerk-migration' ) : ''; ?>" autocomplete="new-password" /></label>
			</div>
			<fieldset class="leadwerk-fieldset"><legend><?php esc_html_e( 'Central Hub role', 'leadwerk-migration' ); ?></legend><label><input type="hidden" name="settings[hub_mode]" value="0" /><input type="checkbox" name="settings[hub_mode]" value="1" <?php checked( ! empty( $settings['hub_mode'] ) ); ?> /> <?php esc_html_e( 'Enable the registry and peer-to-peer control plane on this WordPress (intended for leadwerk.de)', 'leadwerk-migration' ); ?></label><p><?php esc_html_e( 'When enabling for the first time, enter ENABLE HUB. Archive bytes never pass through Hub storage. New Site Families additionally require the server-side enrollment key.', 'leadwerk-migration' ); ?></p><input type="text" name="hub_confirmation" placeholder="ENABLE HUB" autocomplete="off" /></fieldset>
			<p><strong><?php esc_html_e( 'New-family enrollment key:', 'leadwerk-migration' ); ?></strong> <?php $enrollment_configured = ! empty( $settings['hub_mode'] ) ? Leadwerk_Migration_Sync_Hub::enrollment_key_configured() : Leadwerk_Migration_Sync::enrollment_key_configured(); echo esc_html( $enrollment_configured ? __( 'Configured outside the plugin', 'leadwerk-migration' ) : __( 'Not configured — creation of an unknown Site Family will fail closed', 'leadwerk-migration' ) ); ?></p>
			<div class="leadwerk-settings__footer"><button class="button button-primary" type="submit"><?php esc_html_e( 'Save settings', 'leadwerk-migration' ); ?></button></div>
		</form>
	</section>
	<?php endif; ?>

	<?php if ( $is_hub_site && ! empty( $settings['hub_mode'] ) ) : ?>
		<section class="leadwerk-panel leadwerk-sync__hub-admin">
			<div class="leadwerk-panel__header"><div><h2><?php esc_html_e( 'Leadwerk Hub registry', 'leadwerk-migration' ); ?></h2><p><?php esc_html_e( 'Existing families add cloned environments with their family proof. Creating a new family also requires the out-of-package enrollment key. Controls below are for emergency revocation or reactivation.', 'leadwerk-migration' ); ?></p></div><span class="leadwerk-badge leadwerk-badge--verified"><?php esc_html_e( 'Peer-to-peer Hub mode', 'leadwerk-migration' ); ?></span></div>
			<h3><?php esc_html_e( 'Site Families', 'leadwerk-migration' ); ?></h3>
			<div data-leadwerk-dynamic="hub-families"><div class="leadwerk-table-wrap"><table class="widefat striped leadwerk-table"><thead><tr><th><?php esc_html_e( 'Project', 'leadwerk-migration' ); ?></th><th><?php esc_html_e( 'Family ID', 'leadwerk-migration' ); ?></th><th><?php esc_html_e( 'Status', 'leadwerk-migration' ); ?></th><th></th></tr></thead><tbody><?php foreach ( $hub_data['families'] as $item ) : ?><tr><td><?php echo esc_html( $item['project_name'] ); ?></td><td><code><?php echo esc_html( $item['family_id'] ); ?></code></td><td><?php echo esc_html( 'approved' === $item['status'] ? __( 'Active', 'leadwerk-migration' ) : ucfirst( $item['status'] ) ); ?></td><td><?php echo $registry_actions( 'family', $item['family_id'], $item['status'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td></tr><?php endforeach; ?></tbody></table></div></div>
			<h3><?php esc_html_e( 'Environments', 'leadwerk-migration' ); ?></h3>
			<div data-leadwerk-dynamic="hub-environments"><div class="leadwerk-table-wrap"><table class="widefat striped leadwerk-table"><thead><tr><th><?php esc_html_e( 'URL', 'leadwerk-migration' ); ?></th><th><?php esc_html_e( 'Type', 'leadwerk-migration' ); ?></th><th><?php esc_html_e( 'Family ID', 'leadwerk-migration' ); ?></th><th><?php esc_html_e( 'Heartbeat', 'leadwerk-migration' ); ?></th><th><?php esc_html_e( 'Versions', 'leadwerk-migration' ); ?></th><th><?php esc_html_e( 'Status', 'leadwerk-migration' ); ?></th><th></th></tr></thead><tbody><?php foreach ( $hub_data['environments'] as $item ) : ?><tr><td><?php echo esc_html( $item['site_url'] ); ?></td><td><?php echo esc_html( $item['environment_type'] ); ?></td><td><?php echo ! empty( $item['family_id'] ) ? '<code>' . esc_html( substr( $item['family_id'], 0, 8 ) ) . '…</code>' : esc_html__( 'No Family ID', 'leadwerk-migration' ); ?></td><td><span class="leadwerk-badge <?php echo ! empty( $item['is_online'] ) ? 'leadwerk-badge--verified' : 'leadwerk-badge--modified'; ?>"><?php echo esc_html( ! empty( $item['is_online'] ) ? __( 'Online', 'leadwerk-migration' ) : __( 'Offline', 'leadwerk-migration' ) ); ?></span> <?php echo esc_html( $format_seen( $item ) ); ?></td><td><?php echo esc_html( 'WP ' . $item['wordpress_version'] . ' · PHP ' . $item['php_version'] . ' · ' . $item['plugin_version'] ); ?></td><td><?php echo esc_html( 'approved' === $item['status'] ? __( 'Active', 'leadwerk-migration' ) : ( 'available' === $item['status'] ? __( 'Available target', 'leadwerk-migration' ) : ucfirst( $item['status'] ) ) ); ?></td><td><?php echo $registry_actions( 'environment', $item['environment_id'], $item['status'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td></tr><?php endforeach; ?></tbody></table></div></div>
		</section>
	<?php endif; ?>
</div>
