<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Short Links → API & Webhooks (administrators).
 *
 * API keys are shown in full exactly once, right after they are made (handed
 * across the redirect in a 5-minute transient for the user who made it), then
 * only their prefix. Webhook secrets stay visible to administrators — the
 * receiving app needs them to check signatures, and an admin can already
 * change the webhook itself.
 */
class FW_Short_Links_Admin_API {

	const TRANSIENT_KEY = 'fw_ext_short_links_new_key_';

	/**
	 * @param string $action
	 *
	 * @return array|null [ type, message, url ] or null when not ours
	 */
	public static function handle( $action ) {
		$url = FW_Short_Links_Admin_Page::page_url( 'api' );

		if ( ! current_user_can( 'manage_options' ) ) {
			return null;
		}

		switch ( $action ) {
			case 'key_create':
				$scopes = isset( $_POST['scopes'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST['scopes'] ) ) : array();
				$name   = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
				$res    = FW_Short_Links_Keys::create( $name, $scopes, get_current_user_id() );

				if ( is_wp_error( $res ) ) {
					return array( 'error', $res->get_error_message(), $url );
				}

				set_transient( self::TRANSIENT_KEY . get_current_user_id(), $res['key'], 5 * MINUTE_IN_SECONDS );

				return array( 'success', __( 'API key created. Copy it now — it will not be shown again.', 'fw' ), $url );

			case 'key_revoke':
				FW_Short_Links_Keys::revoke( isset( $_GET['id'] ) ? (int) $_GET['id'] : 0 ); // phpcs:ignore WordPress.Security.NonceVerification -- checked by the caller

				return array( 'success', __( 'API key revoked. Apps using it are refused from now on.', 'fw' ), $url );

			case 'hook_create':
				$events = isset( $_POST['events'] ) ? array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['events'] ) ) : array();
				$res    = FW_Short_Links_Webhooks::create( isset( $_POST['url'] ) ? wp_unslash( $_POST['url'] ) : '', $events ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- validated in create()

				return is_wp_error( $res )
					? array( 'error', $res->get_error_message(), $url )
					: array( 'success', __( 'Webhook added. Use "Send test" to check the receiving end.', 'fw' ), $url );

			case 'hook_toggle':
				$id   = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0; // phpcs:ignore WordPress.Security.NonceVerification
				$hook = wp_list_filter( FW_Short_Links_Webhooks::all(), array( 'id' => (string) $id ) );
				$hook = $hook ? reset( $hook ) : null;

				if ( $hook ) {
					FW_Short_Links_Webhooks::set_active( $id, ! (int) $hook->active );
					FW_Short_Links_Webhooks::schedule();
				}

				return array( 'success', __( 'Webhook updated.', 'fw' ), $url );

			case 'hook_delete':
				FW_Short_Links_Webhooks::delete( isset( $_GET['id'] ) ? (int) $_GET['id'] : 0 ); // phpcs:ignore WordPress.Security.NonceVerification
				FW_Short_Links_Webhooks::schedule();

				return array( 'success', __( 'Webhook deleted.', 'fw' ), $url );

			case 'hook_ping':
				$msg = FW_Short_Links_Webhooks::ping( isset( $_GET['id'] ) ? (int) $_GET['id'] : 0 ); // phpcs:ignore WordPress.Security.NonceVerification

				return array( 0 === strpos( $msg, __( 'Delivered', 'fw' ) ) ? 'success' : 'error', $msg, $url );
		}

		return null;
	}

	public static function render() {
		$new_key = get_transient( self::TRANSIENT_KEY . get_current_user_id() );

		if ( $new_key ) {
			delete_transient( self::TRANSIENT_KEY . get_current_user_id() );
		}

		$keys  = FW_Short_Links_Keys::all();
		$hooks = FW_Short_Links_Webhooks::all();
		$base  = rest_url( FW_Short_Links_REST::NAMESPACE_ );
		?>
		<div class="fw-sl-api">
			<div class="fw-sl-panel">
				<h2><?php esc_html_e( 'REST API', 'fw' ); ?></h2>
				<p>
					<?php esc_html_e( 'Other apps can list, create, change and measure links through the API at:', 'fw' ); ?>
					<code><?php echo esc_html( $base ); ?></code>
				</p>
				<p class="description">
					<?php
					printf(
						/* translators: %s: link to the OpenAPI document */
						esc_html__( 'Send an API key as %1$s. The full description of every endpoint, in OpenAPI format, is at %2$s — most API tools and AI agents can import it directly.', 'fw' ),
						'<code>Authorization: Bearer fwsl_…</code>',
						'<a href="' . esc_url( $base . '/openapi.json' ) . '" target="_blank" rel="noopener">openapi.json</a>'
					);
					?>
				</p>
			</div>

			<div class="fw-sl-panel">
				<h2><?php esc_html_e( 'API keys', 'fw' ); ?></h2>

				<?php if ( $new_key ) : ?>
					<div class="fw-sl-secret">
						<p><strong><?php esc_html_e( 'Your new API key — copy it now, it will not be shown again:', 'fw' ); ?></strong></p>
						<code class="fw-sl-secret__value"><?php echo esc_html( $new_key ); ?></code>
						<button type="button" class="button fw-sl-copy" data-copy="<?php echo esc_attr( $new_key ); ?>"><?php esc_html_e( 'Copy', 'fw' ); ?></button>
					</div>
				<?php endif; ?>

				<p class="description"><?php esc_html_e( 'A key acts as you — with your permissions, narrowed to the ones ticked below — and only works on this extension\'s API, never the rest of the site. Make one key per app so you can revoke one without breaking the others.', 'fw' ); ?></p>

				<?php if ( $keys ) : ?>
					<table class="widefat striped fw-sl-keys">
						<thead><tr><th><?php esc_html_e( 'Name', 'fw' ); ?></th><th><?php esc_html_e( 'Key', 'fw' ); ?></th><th><?php esc_html_e( 'Permissions', 'fw' ); ?></th><th><?php esc_html_e( 'Acts as', 'fw' ); ?></th><th><?php esc_html_e( 'Last used', 'fw' ); ?></th><th></th></tr></thead>
						<tbody>
						<?php foreach ( $keys as $k ) : ?>
							<?php $user = get_userdata( (int) $k->user_id ); ?>
							<tr>
								<td><?php echo esc_html( $k->name ); ?></td>
								<td><code><?php echo esc_html( $k->prefix ); ?>…</code></td>
								<td><?php echo esc_html( str_replace( ',', ', ', $k->scopes ) ); ?></td>
								<td><?php echo esc_html( $user ? $user->display_name : '—' ); ?></td>
								<td><?php echo $k->last_used_at ? esc_html( sprintf( __( '%s ago', 'fw' ), human_time_diff( strtotime( $k->last_used_at . ' UTC' ) ) ) ) : esc_html__( 'Never', 'fw' ); ?></td>
								<td class="fw-sl-right"><a class="fw-sl-confirm submitdelete" data-confirm="<?php esc_attr_e( 'Revoke this key? Apps using it stop working immediately.', 'fw' ); ?>" href="<?php echo esc_url( FW_Short_Links_Admin_Page::action_url( 'key_revoke', (int) $k->id ) ); ?>"><?php esc_html_e( 'Revoke', 'fw' ); ?></a></td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>

				<form method="post" action="<?php echo esc_url( FW_Short_Links_Admin_Page::page_url( 'api' ) ); ?>" class="fw-sl-inline-form">
					<?php wp_nonce_field( FW_Short_Links_Admin_Page::NONCE ); ?>
					<input type="hidden" name="fw_sl_action" value="key_create" />
					<input type="text" name="name" class="regular-text" placeholder="<?php esc_attr_e( 'Name, e.g. the app that will use it', 'fw' ); ?>" required />
					<label><input type="checkbox" name="scopes[]" value="read" checked /> <?php esc_html_e( 'Read links', 'fw' ); ?></label>
					<label><input type="checkbox" name="scopes[]" value="write" /> <?php esc_html_e( 'Create & change links', 'fw' ); ?></label>
					<label><input type="checkbox" name="scopes[]" value="stats" /> <?php esc_html_e( 'Read stats', 'fw' ); ?></label>
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Create key', 'fw' ); ?></button>
				</form>
			</div>

			<div class="fw-sl-panel">
				<h2><?php esc_html_e( 'Webhooks', 'fw' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Tell another app when links change, and send it clicks (in batches, every five minutes). Deliveries run in the background and retry for about 15 hours if the other end is down. Each request is signed: check the X-Webhook-Signature header (t=…, v1=HMAC-SHA256 of "t.body" with the secret).', 'fw' ); ?></p>

				<?php if ( $hooks ) : ?>
					<table class="widefat striped fw-sl-hooks">
						<thead><tr><th><?php esc_html_e( 'URL', 'fw' ); ?></th><th><?php esc_html_e( 'Events', 'fw' ); ?></th><th><?php esc_html_e( 'Secret', 'fw' ); ?></th><th><?php esc_html_e( 'Last delivery', 'fw' ); ?></th><th></th></tr></thead>
						<tbody>
						<?php foreach ( $hooks as $h ) : ?>
							<tr class="<?php echo (int) $h->active ? '' : 'fw-sl-muted'; ?>">
								<td class="fw-sl-break"><code><?php echo esc_html( $h->url ); ?></code><?php echo (int) $h->active ? '' : ' <span class="fw-sl-pill fw-sl-pill--off">' . esc_html__( 'Off', 'fw' ) . '</span>'; ?></td>
								<td><?php echo esc_html( implode( ', ', $h->events ) ); ?></td>
								<td><button type="button" class="button-link fw-sl-copy" data-copy="<?php echo esc_attr( $h->secret ); ?>"><?php esc_html_e( 'Copy secret', 'fw' ); ?></button></td>
								<td>
									<?php
									if ( $h->last_sent_at ) {
										echo esc_html( sprintf( __( '%1$s, %2$s ago', 'fw' ), $h->last_status, human_time_diff( strtotime( $h->last_sent_at . ' UTC' ) ) ) );
										if ( (int) $h->failures ) {
											echo '<br><span class="fw-sl-bad">' . esc_html( sprintf( _n( '%d failure in a row', '%d failures in a row', (int) $h->failures, 'fw' ), (int) $h->failures ) ) . '</span>';
										}
									} else {
										esc_html_e( 'Not yet', 'fw' );
									}
									?>
								</td>
								<td class="fw-sl-right">
									<a href="<?php echo esc_url( FW_Short_Links_Admin_Page::action_url( 'hook_ping', (int) $h->id ) ); ?>"><?php esc_html_e( 'Send test', 'fw' ); ?></a> |
									<a href="<?php echo esc_url( FW_Short_Links_Admin_Page::action_url( 'hook_toggle', (int) $h->id ) ); ?>"><?php echo (int) $h->active ? esc_html__( 'Pause', 'fw' ) : esc_html__( 'Resume', 'fw' ); ?></a> |
									<a class="fw-sl-confirm submitdelete" data-confirm="<?php esc_attr_e( 'Delete this webhook and anything still waiting to be sent to it?', 'fw' ); ?>" href="<?php echo esc_url( FW_Short_Links_Admin_Page::action_url( 'hook_delete', (int) $h->id ) ); ?>"><?php esc_html_e( 'Delete', 'fw' ); ?></a>
								</td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>

				<form method="post" action="<?php echo esc_url( FW_Short_Links_Admin_Page::page_url( 'api' ) ); ?>">
					<?php wp_nonce_field( FW_Short_Links_Admin_Page::NONCE ); ?>
					<input type="hidden" name="fw_sl_action" value="hook_create" />
					<p><input type="url" name="url" class="large-text code" placeholder="https://" required /></p>
					<fieldset class="fw-sl-events">
						<?php
						$labels = array(
							'link.created'   => __( 'Link created', 'fw' ),
							'link.updated'   => __( 'Link changed', 'fw' ),
							'link.trashed'   => __( 'Link trashed', 'fw' ),
							'link.restored'  => __( 'Link restored', 'fw' ),
							'link.deleted'   => __( 'Link deleted', 'fw' ),
							'links.imported' => __( 'Import finished', 'fw' ),
							'clicks'         => __( 'Clicks (batched)', 'fw' ),
						);
						foreach ( $labels as $event => $label ) :
							?>
							<label><input type="checkbox" name="events[]" value="<?php echo esc_attr( $event ); ?>" <?php checked( 'clicks' !== $event ); ?> /> <?php echo esc_html( $label ); ?> <code><?php echo esc_html( $event ); ?></code></label>
						<?php endforeach; ?>
					</fieldset>
					<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Add webhook', 'fw' ); ?></button></p>
				</form>
			</div>
		</div>
		<?php
	}
}
